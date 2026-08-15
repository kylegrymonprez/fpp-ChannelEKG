<script type="text/javascript" src="js/d3.v7.min.js"></script>

<style>
#ekgLiveGrid {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 12px;
}
.ekgCard {
	position: relative;
	border: 1px solid var(--bs-border-color, #ccc);
	border-radius: 6px;
	padding: 8px 10px;
	width: 220px;
}
.ekgCard .ekgRemoveBtn {
	position: absolute;
	top: 2px;
	right: 4px;
	border: none;
	background: transparent;
	color: #c0392b;
	font-size: 1.2em;
	line-height: 1;
	padding: 2px 6px;
	cursor: pointer;
}
.ekgCard .ekgRemoveBtn:hover {
	color: #e74c3c;
}
.ekgCard .ekgLabel {
	font-weight: bold;
	font-size: 0.95em;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.ekgCard .ekgChannelNum {
	font-size: 0.8em;
	opacity: 0.7;
}
.ekgIndicator {
	display: inline-block;
	width: 9px;
	height: 9px;
	border-radius: 50%;
	border: 2px solid #888;
	background: transparent;
	margin-right: 5px;
	vertical-align: middle;
}
.ekgIndicator.ekgActive {
	border-color: #2ecc71;
	background: #2ecc71;
}
.ekgCard .ekgValue {
	font-size: 1.8em;
	font-weight: bold;
	margin: 2px 0 4px 0;
}
.ekgCard svg {
	width: 100%;
	height: 70px;
	display: block;
}
.ekgCard .ekgLine {
	fill: none;
	stroke: #2e86de;
	stroke-width: 1.5px;
}
.ekgCard .ekgAxis {
	stroke: var(--bs-border-color, #ccc);
	stroke-width: 1px;
}
</style>

<div id="global" class="settings">
<h2>Channel EKG</h2>
<div class="container-fluid settingsTable settingsGroupTable">

	<div class="row"><div class="col-md">
		Pick up to 16 raw output channels to monitor. Choose a configured model to add its first
		channels directly, or enter a raw channel number manually. Click "Save &amp; Apply" to
		start monitoring the selected channels below.
	</div></div>

	<div class="row align-items-center" style="margin-top:8px;">
		<div class="col-auto">Model:</div>
		<div class="col-auto">
			<select id="ekgModelSelect" class="form-select form-select-sm" onchange="ekgModelChanged()" style="width:220px;">
				<option value="">-- Manual channel entry --</option>
			</select>
		</div>
		<div class="col-auto" id="ekgModelInfo" style="display:none;"></div>
		<div class="col-auto" id="ekgModelAddRow" style="display:none;">
			<button class="btn btn-primary btn-sm" onclick="ekgAddModelChannels()">Add first <span id="ekgModelAddCount"></span> channels</button>
		</div>
		<div class="col-auto">
			<button class="btn btn-outline-danger btn-sm" onclick="ekgRemoveAllChannels()">Remove All</button>
		</div>
	</div>

	<div class="row align-items-center" style="margin-top:8px;">
		<div class="col-auto">Or add a specific channel:</div>
		<div class="col-auto">
			Channel # <input type="number" id="ekgManualChannel" class="form-control form-control-sm d-inline-block" min="1" style="width:100px;">
		</div>
		<div class="col-auto">
			Label <input type="text" id="ekgLabel" class="form-control form-control-sm" placeholder="optional label" style="width:180px;">
		</div>
		<div class="col-auto">
			<button class="btn btn-outline-primary btn-sm" onclick="ekgAddManualChannel()">Add</button>
		</div>
	</div>

	<div class="row"><div class="col-auto">
		<button class="btn btn-success" onclick="ekgSave()">Save &amp; Apply</button>
		<span id="ekgSaveStatus" style="margin-left:10px;"></span>
	</div></div>

</div>

<hr>

<div id="ekgLiveGrid"></div>

</div>

<script>
var ekgModels = [];
var ekgSelectedModel = null;
var ekgPicked = [];      // [{channel:int, label:string}]
var ekgHistory = {};     // channel -> [[t,v], ...]
var ekgLastSince = 0;
var ekgPollTimer = null;
var EKG_HISTORY_MS = 30000;
var EKG_MAX_CHANNELS = 16;
// "Active" means a controller is actually sending data to the channel right
// now - a locally playing sequence/playlist, or bridged E1.31/Artnet/DDP
// input from another controller - not just "the value happens to be
// changing" (a bridged fixture can be held on a steady value and still be
// getting fresh packets every frame). There's no per-channel plugin API for
// this in FPP today, so it's approximated from two real signals:
//   - api/fppd/e131stats: per-input packet counters with a startChannel
//     range. A rising packetsReceived count means that range is actively
//     receiving right now; this IS real per-channel (per-range) truth.
//   - api/system/status: status_name != "idle" means a sequence/playlist is
//     playing. This is global, not scoped to which channels that sequence
//     covers (FPP doesn't expose that to plugins), so it marks every
//     monitored channel active rather than just the ones truly in range.
var ekgUniverseStats = {};      // key -> {start, end, packetsReceived, lastActiveAt}
var ekgSequencePlaying = false;
var EKG_ACTIVITY_POLL_MS = 1000;
var EKG_ACTIVITY_GRACE_MS = 2500;

function ekgLoadModels() {
	$.getJSON('api/models', function (data) {
		ekgModels = data || [];
		var sel = document.getElementById('ekgModelSelect');
		for (var i = 0; i < ekgModels.length; i++) {
			var m = ekgModels[i];
			if (!m || !m.Name) continue;
			var opt = document.createElement('option');
			opt.value = m.Name;
			opt.text = m.Name;
			sel.appendChild(opt);
		}
	});
}

function ekgFindModel(name) {
	for (var i = 0; i < ekgModels.length; i++) {
		if (ekgModels[i].Name === name) return ekgModels[i];
	}
	return null;
}

function ekgModelChanged() {
	var name = document.getElementById('ekgModelSelect').value;
	var infoEl = document.getElementById('ekgModelInfo');
	var addRow = document.getElementById('ekgModelAddRow');
	if (!name) {
		ekgSelectedModel = null;
		infoEl.style.display = 'none';
		addRow.style.display = 'none';
		return;
	}
	var m = ekgFindModel(name);
	if (!m) return;
	ekgSelectedModel = m;

	var startChannel = parseInt(m.StartChannel, 10) || 1;
	var channelCount = parseInt(m.ChannelCount, 10) || 0;
	var endChannel = startChannel + channelCount - 1;
	infoEl.textContent = channelCount + ' raw channels (Ch ' + startChannel + '-' + endChannel + ')';
	infoEl.style.display = '';

	var addCount = Math.min(16, channelCount);
	document.getElementById('ekgModelAddCount').textContent = addCount;
	addRow.style.display = '';
}

// Bulk-adds the first min(16, model channel count) RAW output channels of the
// selected model - sequential from StartChannel, no pixel/color grouping.
function ekgAddModelChannels() {
	if (!ekgSelectedModel) return;
	var startChannel = parseInt(ekgSelectedModel.StartChannel, 10) || 1;
	var channelCount = parseInt(ekgSelectedModel.ChannelCount, 10) || 0;
	var toAdd = Math.min(16, channelCount);
	var added = 0;
	for (var i = 0; i < toAdd; i++) {
		if (ekgPicked.length >= EKG_MAX_CHANNELS) break;
		var channel = startChannel + i;
		var exists = false;
		for (var j = 0; j < ekgPicked.length; j++) {
			if (ekgPicked[j].channel === channel) { exists = true; break; }
		}
		if (exists) continue;
		ekgPicked.push({ channel: channel, label: ekgSelectedModel.Name + ' Ch' + (i + 1) });
		added++;
	}
	ekgRenderLiveGrid();
	if (added < toAdd) {
		alert('Added ' + added + ' of ' + toAdd + ' channels - the 16 channel monitoring limit was reached or some were already in the list.');
	}
}

function ekgRemoveAllChannels() {
	ekgPicked = [];
	ekgRenderLiveGrid();
}

function ekgAddManualChannel() {
	var raw = parseInt(document.getElementById('ekgManualChannel').value, 10);
	if (!raw || raw < 1) {
		alert('Enter a valid channel number.');
		return;
	}
	if (ekgPicked.length >= EKG_MAX_CHANNELS) {
		alert('Only ' + EKG_MAX_CHANNELS + ' channels can be monitored at once.');
		return;
	}
	for (var i = 0; i < ekgPicked.length; i++) {
		if (ekgPicked[i].channel === raw) {
			alert('Channel ' + raw + ' is already in the list.');
			return;
		}
	}
	var label = document.getElementById('ekgLabel').value || ('Channel ' + raw);
	ekgPicked.push({ channel: raw, label: label });
	document.getElementById('ekgManualChannel').value = '';
	document.getElementById('ekgLabel').value = '';
	ekgRenderLiveGrid();
}

// Removes a channel by its channel number (unique) from the live grid/staged list.
function ekgRemoveChannel(channel) {
	ekgPicked = ekgPicked.filter(function (c) { return c.channel !== channel; });
	ekgRenderLiveGrid();
}

function ekgSave() {
	$.ajax({
		url: 'api/plugin-apis/ChannelEKG/config',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify(ekgPicked),
		success: function (data) {
			ekgPicked = data || [];
			// The backend replaces its whole channel list on save, so every
			// channel's sample buffer - even an unchanged one - restarts empty.
			ekgHistory = {};
			ekgLastSince = 0;
			ekgRenderLiveGrid();
			var status = document.getElementById('ekgSaveStatus');
			status.textContent = 'Saved.';
			setTimeout(function () { status.textContent = ''; }, 2000);
		},
		error: function (xhr) {
			alert('Save failed: ' + xhr.responseText);
		}
	});
}

function ekgLoadCurrentConfig() {
	$.getJSON('api/plugin-apis/ChannelEKG/config', function (data) {
		ekgPicked = data || [];
		ekgRenderLiveGrid();
		ekgStartPolling();
	});
}

// Rebuilds the live grid from ekgPicked. History is kept for channels that
// are still present (so adding/removing one card doesn't interrupt the
// others' graphs) and dropped only for channels no longer in the list.
function ekgRenderLiveGrid() {
	var grid = document.getElementById('ekgLiveGrid');
	var stillPicked = {};
	for (var i = 0; i < ekgPicked.length; i++) {
		stillPicked[ekgPicked[i].channel] = true;
	}
	for (var ch in ekgHistory) {
		if (!stillPicked[ch]) delete ekgHistory[ch];
	}

	grid.innerHTML = '';
	for (var i = 0; i < ekgPicked.length; i++) {
		var c = ekgPicked[i];
		if (!ekgHistory[c.channel]) ekgHistory[c.channel] = [];
		var safeLabel = $('<div>').text(c.label).html();
		var card = document.createElement('div');
		card.className = 'ekgCard';
		card.id = 'ekgCard_' + c.channel;
		card.innerHTML =
			'<button type="button" class="ekgRemoveBtn" onclick="ekgRemoveChannel(' + c.channel + ')" title="Remove">&times;</button>' +
			'<div class="ekgLabel" title="' + safeLabel + '">' + safeLabel + '</div>' +
			'<div class="ekgChannelNum"><span class="ekgIndicator" id="ekgInd_' + c.channel + '" title="No live data"></span>Channel ' + c.channel + '</div>' +
			'<div class="ekgValue" id="ekgVal_' + c.channel + '">-</div>' +
			'<svg id="ekgSvg_' + c.channel + '" viewBox="0 0 200 70" preserveAspectRatio="none"></svg>';
		grid.appendChild(card);
	}
}

function ekgStartPolling() {
	if (ekgPollTimer) return;
	ekgPoll();
	ekgPollActivitySignals();
}

function ekgParseChannelRange(str) {
	var parts = String(str).split('-');
	var start = parseInt(parts[0], 10);
	var end = parts.length > 1 ? parseInt(parts[1], 10) : start;
	return { start: start, end: end };
}

function ekgIsChannelBridged(channel) {
	var now = Date.now();
	for (var key in ekgUniverseStats) {
		var u = ekgUniverseStats[key];
		if (channel >= u.start && channel <= u.end && (now - u.lastActiveAt) <= EKG_ACTIVITY_GRACE_MS) {
			return true;
		}
	}
	return false;
}

// Polled separately from channel data, and less often - these are coarser
// signals (per-input-range packet counters, and a global playback flag) that
// don't need 250ms granularity.
function ekgPollActivitySignals() {
	$.getJSON('api/fppd/e131stats', function (data) {
		var universes = (data && data.universes) || [];
		var now = Date.now();
		var seen = {};
		for (var i = 0; i < universes.length; i++) {
			var u = universes[i];
			var range = ekgParseChannelRange(u.startChannel);
			var key = u.id + '|' + u.startChannel;
			seen[key] = true;
			var packets = parseInt(u.packetsReceived, 10) || 0;
			var prev = ekgUniverseStats[key];
			var lastActiveAt = (prev && prev.lastActiveAt) || 0;
			if (!prev || packets > prev.packetsReceived) {
				lastActiveAt = now;
			}
			ekgUniverseStats[key] = { start: range.start, end: range.end, packetsReceived: packets, lastActiveAt: lastActiveAt };
		}
		for (var key2 in ekgUniverseStats) {
			if (!seen[key2]) delete ekgUniverseStats[key2];
		}
	});
	$.getJSON('api/system/status', function (status) {
		ekgSequencePlaying = !!(status && status.status_name && status.status_name !== 'idle');
	});
	setTimeout(ekgPollActivitySignals, EKG_ACTIVITY_POLL_MS);
}

function ekgPoll() {
	if (ekgPicked.length === 0) {
		ekgPollTimer = setTimeout(ekgPoll, 250);
		return;
	}
	$.getJSON('api/plugin-apis/ChannelEKG/data?since=' + ekgLastSince, function (data) {
		if (!data) return;
		var now = data.now;
		ekgLastSince = now;
		var cutoff = now - EKG_HISTORY_MS;
		var channels = data.channels || [];
		for (var i = 0; i < channels.length; i++) {
			var cd = channels[i];
			var hist = ekgHistory[cd.channel] || [];
			for (var j = 0; j < cd.samples.length; j++) {
				hist.push(cd.samples[j]);
			}
			while (hist.length && hist[0][0] < cutoff) {
				hist.shift();
			}
			ekgHistory[cd.channel] = hist;

			var valEl = document.getElementById('ekgVal_' + cd.channel);
			if (valEl) valEl.textContent = cd.value;

			var indEl = document.getElementById('ekgInd_' + cd.channel);
			if (indEl) {
				var active = ekgSequencePlaying || ekgIsChannelBridged(cd.channel);
				indEl.classList.toggle('ekgActive', active);
				indEl.title = active ?
					'A sequence, playlist, or bridged input is actively sending data to this channel' :
					'Nothing is currently sending data to this channel';
			}

			ekgDrawChart(cd.channel, hist, now);
		}
	}).always(function () {
		ekgPollTimer = setTimeout(ekgPoll, 250);
	});
}

function ekgDrawChart(channel, hist, now) {
	var svg = d3.select('#ekgSvg_' + channel);
	if (svg.empty()) return;
	var x = d3.scaleLinear().domain([now - EKG_HISTORY_MS, now]).range([0, 200]);
	var y = d3.scaleLinear().domain([0, 255]).range([65, 2]);
	var line = d3.line()
		.x(function (d) { return x(d[0]); })
		.y(function (d) { return y(d[1]); });

	var axis = svg.selectAll('line.ekgAxis').data([0, 128, 255]);
	axis.enter().append('line').attr('class', 'ekgAxis')
		.merge(axis)
		.attr('x1', 0).attr('x2', 200)
		.attr('y1', function (d) { return y(d); })
		.attr('y2', function (d) { return y(d); });
	axis.exit().remove();

	var path = svg.selectAll('path.ekgLine').data([hist]);
	path.enter().append('path').attr('class', 'ekgLine')
		.merge(path)
		.attr('d', line);
	path.exit().remove();
}

$(document).ready(function () {
	ekgLoadModels();
	ekgLoadCurrentConfig();
});
</script>
