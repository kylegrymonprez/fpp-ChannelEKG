# Channel EKG

An FPP (Falcon Player) plugin that monitors the live raw values being sent to
up to 16 output channels, showing the current value and a scrolling 30 second
line graph for each.

## How it works

A small C++ plugin (`src/FPPChannelEKG.cpp`) hooks FPP's
`ChannelDataPlugin::modifyChannelData()`, which runs every output frame with
the actual post-overlay data about to be sent out. It samples the configured
channels each frame into an in-memory 30 second ring buffer per channel, and
exposes that data over HTTP:

- `GET /api/plugin-apis/ChannelEKG/config` - current monitored channel list
- `POST /api/plugin-apis/ChannelEKG/config` - set the monitored channel list (max 16, `[{ "channel": 123, "label": "..." }]`)
- `GET /api/plugin-apis/ChannelEKG/data?since=<ms>` - current value per channel, plus samples newer than `since` (delta polling, not the full history each time)

The web UI (`status.php`, under the Status menu as "Channel EKG") lets
you pick channels either by choosing a configured model (adds its first raw
output channels sequentially from its start channel, reusing FPP's own
`/api/models` endpoint - no pixel/color grouping) or by entering a raw channel
number, then polls the `data` endpoint every 250ms and draws a live chart per
channel with a small hand-rolled SVG renderer (no external JS dependency).

## Installing

Clone this repo into FPP's plugins directory and run the install script (or
install it through FPP's Plugin Manager once published):

```
cd <fpp media dir>/plugins
git clone https://github.com/kylegrymonprez/fpp-ChannelEKG.git
cd fpp-ChannelEKG
scripts/fpp_install.sh
```

The Makefile detects which plugin HTTP API the FPP tree at `SRCDIR` provides
and builds against that one: FPP 10.0-beta5+'s `registerApis()`/
`FPPPlugins::registerPluginApi()` (supports live install/uninstall/upgrade
with no `fppd` restart), or the older 8.x/9.x
`registerApis(httpserver::webserver*)` (needs an `fppd` restart after
install, uninstall, or upgrade).

## Notes

- Channel numbers are 1-based absolute output channels, matching what FPP's
  Testing page shows for a model.
