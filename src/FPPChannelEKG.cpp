#include <fpp-pch.h>

#include <cstdlib>
#include <deque>
#include <mutex>
#include <string>
#include <utility>
#include <vector>

#include "common.h"
#include "log.h"
#include "Plugin.h"
#include "Plugins.h"
#include "settings.h"

// CEKG_HAVE_NOARG_REGISTER_APIS is defined by the Makefile when the FPP tree
// being built against has Plugin API 6 (first shipped in FPP 10.0-beta5): a
// no-arg APIProviderPlugin::registerApis()/unregisterApis(), routed through
// FPPPlugins::registerPluginApi()/unregisterPluginApi() in fpphttp.h, plus a
// shutdown()/FPP_PLUGIN_SUPPORTS_UNLOAD() hot-unload contract. Otherwise this
// targets the older 8.x/9.x libhttpserver API, where
// APIProviderPlugin::registerApis(httpserver::webserver*) hands the plugin
// the shared webserver directly and there is no hot-unload - an fppd restart
// is needed after install/uninstall/upgrade.
#ifdef CEKG_HAVE_NOARG_REGISTER_APIS
#include "fpphttp.h"
#else
#include <httpserver.hpp>
#endif

namespace {
    constexpr size_t MAX_MONITORED_CHANNELS = 16;
    constexpr long long HISTORY_MS = 30000;
    // Matches FPPD_MAX_CHANNELS (Sequence.h): FPP always allocates the output
    // channel buffer at this size, so any 1-based channel within this bound is
    // safe to index into seqData regardless of what is actually configured.
    constexpr long MAX_CHANNEL_INDEX = 8192 * 1024;
    constexpr char API_PATH[] = "/ChannelEKG";
    constexpr char CONFIG_PATH[] = "/ChannelEKG/config";
    constexpr char DATA_PATH[] = "/ChannelEKG/data";
}

struct MonitoredChannel {
    int channel = 0; // 1-based output channel number
    std::string label;
    std::deque<std::pair<long long, uint8_t>> samples; // {timestamp ms, value}
    uint8_t currentValue = 0;
};

class FPPChannelEKGPlugin : public FPPPlugins::Plugin, public FPPPlugins::ChannelDataPlugin, public FPPPlugins::APIProviderPlugin
#ifndef CEKG_HAVE_NOARG_REGISTER_APIS
    ,
                             public httpserver::http_resource
#endif
{
public:
    FPPChannelEKGPlugin() : FPPPlugins::Plugin("fpp-ChannelEKG") {
        configLocation = FPP_DIR_CONFIG("/plugin.fpp-ChannelEKG.json");
        loadConfig();
    }
    virtual ~FPPChannelEKGPlugin() {}

#ifdef CEKG_HAVE_NOARG_REGISTER_APIS
    // Nothing async here: no threads, timers, commands, or event callbacks to
    // quiesce, so teardown is complete as soon as unregisterApis() returns.
    virtual std::function<bool()> shutdown() override {
        return nullptr;
    }
#endif

    void loadConfig() {
        std::vector<MonitoredChannel> loaded;
        if (FileExists(configLocation)) {
            Json::Value root;
            if (LoadJsonFromFile(configLocation, root) && root.isArray()) {
                for (const auto& entry : root) {
                    if (loaded.size() >= MAX_MONITORED_CHANNELS) {
                        break;
                    }
                    if (!entry.isMember("channel")) {
                        continue;
                    }
                    int ch = entry["channel"].asInt();
                    if (ch < 1 || ch > MAX_CHANNEL_INDEX) {
                        continue;
                    }
                    MonitoredChannel mc;
                    mc.channel = ch;
                    mc.label = entry.get("label", ("Channel " + std::to_string(ch))).asString();
                    loaded.push_back(mc);
                }
            }
        }
        std::lock_guard<std::mutex> lock(dataMutex);
        channels = std::move(loaded);
    }

    void saveConfig() {
        Json::Value root(Json::arrayValue);
        {
            std::lock_guard<std::mutex> lock(dataMutex);
            for (auto& mc : channels) {
                Json::Value entry;
                entry["channel"] = mc.channel;
                entry["label"] = mc.label;
                root.append(entry);
            }
        }
        SaveJsonToFile(root, configLocation);
    }

    // Called every output frame with the actual post-overlay data FPP is about
    // to send - this is the live "what is really being output" buffer.
    virtual void modifyChannelData(int ms, uint8_t* seqData) override {
        // Real time rather than sequence time, so channels still update while
        // testing/overlay data is being sent with no sequence running.
        long long now = GetTimeMS();
        std::lock_guard<std::mutex> lock(dataMutex);
        for (auto& mc : channels) {
            uint8_t v = seqData[mc.channel - 1];
            mc.currentValue = v;
            mc.samples.emplace_back(now, v);
            while (!mc.samples.empty() && (now - mc.samples.front().first) > HISTORY_MS) {
                mc.samples.pop_front();
            }
        }
    }

    Json::Value buildConfigJson() {
        Json::Value root(Json::arrayValue);
        std::lock_guard<std::mutex> lock(dataMutex);
        for (auto& mc : channels) {
            Json::Value entry;
            entry["channel"] = mc.channel;
            entry["label"] = mc.label;
            root.append(entry);
        }
        return root;
    }

    Json::Value buildDataJson(long long since) {
        long long now = GetTimeMS();
        Json::Value root;
        root["now"] = (Json::Int64)now;
        Json::Value channelsJson(Json::arrayValue);
        {
            std::lock_guard<std::mutex> lock(dataMutex);
            for (auto& mc : channels) {
                Json::Value cj;
                cj["channel"] = mc.channel;
                cj["label"] = mc.label;
                cj["value"] = mc.currentValue;
                Json::Value samplesJson(Json::arrayValue);
                for (auto& s : mc.samples) {
                    if (s.first <= since) {
                        continue;
                    }
                    Json::Value pair(Json::arrayValue);
                    pair.append((Json::Int64)s.first);
                    pair.append(s.second);
                    samplesJson.append(pair);
                }
                cj["samples"] = samplesJson;
                channelsJson.append(cj);
            }
        }
        root["channels"] = channelsJson;
        return root;
    }

    // Replaces `channels` from a config POST body. Returns an error message on
    // failure (empty string on success), matching neither transport so both
    // render_POST/registerPluginApi handlers can wrap it in their own response.
    std::string applyConfigJson(const std::string& body) {
        Json::Value root;
        if (!LoadJsonFromString(body, root) || !root.isArray()) {
            return "{\"error\":\"invalid JSON body, expected an array\"}";
        }
        if (root.size() > MAX_MONITORED_CHANNELS) {
            return "{\"error\":\"too many channels, max 16\"}";
        }
        std::vector<MonitoredChannel> newChannels;
        for (const auto& entry : root) {
            if (!entry.isMember("channel")) {
                continue;
            }
            int ch = entry["channel"].asInt();
            if (ch < 1 || ch > MAX_CHANNEL_INDEX) {
                return "{\"error\":\"channel number out of range\"}";
            }
            MonitoredChannel mc;
            mc.channel = ch;
            mc.label = entry.get("label", ("Channel " + std::to_string(ch))).asString();
            newChannels.push_back(std::move(mc));
        }
        {
            std::lock_guard<std::mutex> lock(dataMutex);
            channels = std::move(newChannels);
        }
        saveConfig();
        return "";
    }

#ifdef CEKG_HAVE_NOARG_REGISTER_APIS
    void handleGetConfig(HttpCallback&& callback) {
        callback(makeStringResponse(SaveJsonToString(buildConfigJson()), 200, "application/json"));
    }

    void handlePostConfig(const HttpRequestPtr& req, HttpCallback&& callback) {
        std::string err = applyConfigJson(getRequestContent(req));
        if (!err.empty()) {
            callback(makeStringResponse(err, 400, "application/json"));
            return;
        }
        handleGetConfig(std::move(callback));
    }

    void handleGetData(const HttpRequestPtr& req, HttpCallback&& callback) {
        long long since = 0;
        std::string sinceArg = getRequestArg(req, "since");
        if (!sinceArg.empty()) {
            since = std::atoll(sinceArg.c_str());
        }
        callback(makeStringResponse(SaveJsonToString(buildDataJson(since)), 200, "application/json"));
    }

    virtual void registerApis() override {
        FPPPlugins::registerPluginApi(
            CONFIG_PATH,
            [this](const HttpRequestPtr& req, HttpCallback&& callback) {
                if (req->method() == drogon::Get) {
                    handleGetConfig(std::move(callback));
                } else {
                    handlePostConfig(req, std::move(callback));
                }
            },
            { drogon::Get, drogon::Post }, false);

        FPPPlugins::registerPluginApi(
            DATA_PATH,
            [this](const HttpRequestPtr& req, HttpCallback&& callback) {
                handleGetData(req, std::move(callback));
            },
            { drogon::Get }, false);
    }

    // Both routes and their handlers are this plugin's code, so both have to
    // be gone before the .so can be unmapped.
    virtual void unregisterApis() override {
        FPPPlugins::unregisterPluginApi(CONFIG_PATH);
        FPPPlugins::unregisterPluginApi(DATA_PATH);
    }
#else
    std::shared_ptr<httpserver::http_response> handleGetConfig() {
        return std::shared_ptr<httpserver::http_response>(
            new httpserver::string_response(SaveJsonToString(buildConfigJson()), 200, "application/json"));
    }

    std::shared_ptr<httpserver::http_response> handlePostConfig(const httpserver::http_request& req) {
        std::string err = applyConfigJson(std::string(req.get_content()));
        if (!err.empty()) {
            return std::shared_ptr<httpserver::http_response>(new httpserver::string_response(err, 400, "application/json"));
        }
        return handleGetConfig();
    }

    std::shared_ptr<httpserver::http_response> handleGetData(const httpserver::http_request& req) {
        long long since = 0;
        std::string sinceArg(req.get_arg("since"));
        if (!sinceArg.empty()) {
            since = std::atoll(sinceArg.c_str());
        }
        return std::shared_ptr<httpserver::http_response>(
            new httpserver::string_response(SaveJsonToString(buildDataJson(since)), 200, "application/json"));
    }

    HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response> render_GET(const httpserver::http_request& req) override {
        const auto& pieces = req.get_path_pieces();
        if (pieces.size() > 1 && pieces[1] == "config") {
            return handleGetConfig();
        }
        if (pieces.size() > 1 && pieces[1] == "data") {
            return handleGetData(req);
        }
        return std::shared_ptr<httpserver::http_response>(new httpserver::string_response("Not Found", 404));
    }

    HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response> render_POST(const httpserver::http_request& req) override {
        const auto& pieces = req.get_path_pieces();
        if (pieces.size() > 1 && pieces[1] == "config") {
            return handlePostConfig(req);
        }
        return std::shared_ptr<httpserver::http_response>(new httpserver::string_response("Not Found", 404));
    }

    virtual void registerApis(httpserver::webserver* m_ws) override {
        m_ws->register_resource(API_PATH, this, true);
    }

    virtual void unregisterApis(httpserver::webserver* m_ws) override {
        m_ws->unregister_resource(API_PATH);
    }
#endif

    std::string configLocation;
    std::mutex dataMutex;
    std::vector<MonitoredChannel> channels;
};

#ifdef CEKG_HAVE_NOARG_REGISTER_APIS
// Safe to dlclose() on unload: no threads, no timers, no CurlManager requests,
// no epoll descriptors, no Commands, no Events callbacks - routes go through
// registerPluginApi()/unregisterPluginApi() only, so nothing outside this
// library can still be holding a pointer into it once unregisterApis() and
// shutdown() have returned.
FPP_PLUGIN_SUPPORTS_UNLOAD()
#endif

extern "C" {
FPPPlugins::Plugin* createPlugin() {
    return new FPPChannelEKGPlugin();
}
}
