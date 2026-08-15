#include <fpp-pch.h>

#include <cstdlib>
#include <deque>
#include <mutex>
#include <string>
#include <utility>
#include <vector>

#include "common.h"
#include "fpp-json.h"
#include "fpphttp.h"
#include "log.h"
#include "Plugin.h"
#include "Plugins.h"
#include "settings.h"

namespace {
    constexpr size_t MAX_MONITORED_CHANNELS = 16;
    constexpr long long HISTORY_MS = 30000;
    // Matches FPPD_MAX_CHANNELS (Sequence.h): FPP always allocates the output
    // channel buffer at this size, so any 1-based channel within this bound is
    // safe to index into seqData regardless of what is actually configured.
    constexpr long MAX_CHANNEL_INDEX = 8192 * 1024;
}

struct MonitoredChannel {
    int channel = 0; // 1-based output channel number
    std::string label;
    std::deque<std::pair<long long, uint8_t>> samples; // {timestamp ms, value}
    uint8_t currentValue = 0;
};

class FPPChannelEKGPlugin : public FPPPlugins::Plugin, public FPPPlugins::ChannelDataPlugin, public FPPPlugins::APIProviderPlugin {
public:
    FPPChannelEKGPlugin() : FPPPlugins::Plugin("fpp-ChannelEKG") {
        configLocation = FPP_DIR_CONFIG("/plugin.fpp-ChannelEKG.json");
        loadConfig();
    }
    virtual ~FPPChannelEKGPlugin() {}

    // Nothing async here: no threads, timers, commands, or event callbacks to
    // quiesce, so teardown is complete as soon as unregisterApis() returns.
    virtual std::function<bool()> shutdown() override {
        return nullptr;
    }

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

    void handleGetConfig(HttpCallback&& callback) {
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
        callback(makeStringResponse(SaveJsonToString(root), 200, "application/json"));
    }

    void handlePostConfig(const HttpRequestPtr& req, HttpCallback&& callback) {
        Json::Value root;
        if (!LoadJsonFromString(getRequestContent(req), root) || !root.isArray()) {
            callback(makeStringResponse("{\"error\":\"invalid JSON body, expected an array\"}", 400, "application/json"));
            return;
        }
        if (root.size() > MAX_MONITORED_CHANNELS) {
            callback(makeStringResponse("{\"error\":\"too many channels, max 16\"}", 400, "application/json"));
            return;
        }
        std::vector<MonitoredChannel> newChannels;
        for (const auto& entry : root) {
            if (!entry.isMember("channel")) {
                continue;
            }
            int ch = entry["channel"].asInt();
            if (ch < 1 || ch > MAX_CHANNEL_INDEX) {
                callback(makeStringResponse("{\"error\":\"channel number out of range\"}", 400, "application/json"));
                return;
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
        handleGetConfig(std::move(callback));
    }

    void handleGetData(const HttpRequestPtr& req, HttpCallback&& callback) {
        long long since = 0;
        std::string sinceArg = getRequestArg(req, "since");
        if (!sinceArg.empty()) {
            since = std::atoll(sinceArg.c_str());
        }
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
        callback(makeStringResponse(SaveJsonToString(root), 200, "application/json"));
    }

    virtual void registerApis() override {
        FPPPlugins::registerPluginApi(
            "/ChannelEKG/config",
            [this](const HttpRequestPtr& req, HttpCallback&& callback) {
                if (req->method() == drogon::Get) {
                    handleGetConfig(std::move(callback));
                } else {
                    handlePostConfig(req, std::move(callback));
                }
            },
            { drogon::Get, drogon::Post }, false);

        FPPPlugins::registerPluginApi(
            "/ChannelEKG/data",
            [this](const HttpRequestPtr& req, HttpCallback&& callback) {
                handleGetData(req, std::move(callback));
            },
            { drogon::Get }, false);
    }

    // Both routes and their handlers are this plugin's code, so both have to
    // be gone before the .so can be unmapped.
    virtual void unregisterApis() override {
        FPPPlugins::unregisterPluginApi("/ChannelEKG/config");
        FPPPlugins::unregisterPluginApi("/ChannelEKG/data");
    }

    std::string configLocation;
    std::mutex dataMutex;
    std::vector<MonitoredChannel> channels;
};

// Safe to dlclose() on unload: no threads, no timers, no CurlManager requests,
// no epoll descriptors, no Commands, no Events callbacks - routes go through
// registerPluginApi()/unregisterPluginApi() only, so nothing outside this
// library can still be holding a pointer into it once unregisterApis() and
// shutdown() have returned.
FPP_PLUGIN_SUPPORTS_UNLOAD()

extern "C" {
FPPPlugins::Plugin* createPlugin() {
    return new FPPChannelEKGPlugin();
}
}
