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
#ekgTabsNav {
	margin-top: 12px;
	margin-bottom: 0;
}
</style>

<div id="global" class="settings">
<div class="container-fluid settingsTable settingsGroupTable">

	<div class="row"><div class="col-md">
		Pick up to 128 raw output channels to monitor. Choose a configured model to add its
		channels directly, or enter a raw channel number manually. Changes apply immediately.
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
			<button class="btn btn-primary btn-sm" onclick="ekgAddModelChannels()">Add all <span id="ekgModelAddCount"></span> channels</button>
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
			Label <input type="text" id="ekgLabel" class="form-control form-control-sm d-inline-block" placeholder="optional label" style="width:180px;">
		</div>
		<div class="col-auto">
			<button class="btn btn-outline-primary btn-sm" onclick="ekgAddManualChannel()">Add</button>
		</div>
	</div>

	<div class="row"><div class="col-auto">
		<span id="ekgSaveStatus"></span>
	</div></div>

</div>

<hr>

<ul class="nav nav-pills pageContent-tabs" id="ekgTabsNav" role="tablist" style="display:none;"></ul>
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
var EKG_MAX_CHANNELS = 128;
// Matches DMX_CHANNELS_PER_TAB in FPP's own testing.php (Display Testing >
// Channel Fader), so the two pages group channels the same way.
var EKG_CHANNELS_PER_TAB = 16;
var ekgActiveTab = 0;
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

// Is this model a sparse, non-contiguous overlay rather than a plain
// contiguous block of channels? xLights submodels and model groups are
// exported to FPP as Pixel Overlay Models of Type "Sub" (see
// docs/PixelOverlaySubModels.md in the FPP source) - a subset of a parent's
// (or several models') channels scattered via a row/column "Grid" string with
// blank cells for the gaps. FPP recomputes such a model's own
// StartChannel/ChannelCount to describe its internal scratch buffer, not real
// output channels, so those two fields can't be used to find its channels.
function ekgIsGappedModel(m) {
	return m.Type === 'Sub' && (m.SubType === 'grid' || m.SubType === 'channelgrid');
}

// Best-effort inference for a "chained" convenience model - an xLights model
// whose strings each point at a different node of ANOTHER model via
// "@Model:Node" (e.g. a "Pan" model built from the pan channel of several
// separate moving-head fixtures). xLights currently exports these to FPP as a
// plain contiguous Channel model: model-overlays.json's StartChannel is only
// the FIRST string's resolved channel, and every other string is wrongly
// assumed to be sequential from there (xLights/src-core/controllers/FPP.cpp,
// FPP::CreateModelMemoryMap(), uses model->ModelStartChannel but never
// model->NodeStartChannel(i) per string - the per-node resolver it already
// uses correctly elsewhere). There's no field marking this, so it's inferred
// from a purely data-driven signal instead: the naive contiguous range
// collides with another model's real range, which a correctly contiguous
// model's channels never should. When that happens, look for other models
// sharing this model's exact "shape" (ChannelCount/StringCount/
// StrandsPerString/ChannelCountPerNode) - almost certainly repeated instances
// of the same kind of fixture - and assume each of this model's strings
// aliases the same relative offset into the Nth such fixture, in ascending
// channel order. Returns null when the signal isn't there (nothing to infer,
// or not enough same-shaped fixtures to resolve against).
function ekgInferChainedChannels(m, allModels) {
	var count = parseInt(m.ChannelCount, 10) || 0;
	var start = parseInt(m.StartChannel, 10) || 1;
	var end = start + count - 1;
	var stringCount = parseInt(m.StringCount, 10) || 1;
	if (count <= 0 || stringCount <= 1) return null;

	function rangeOf(mm) {
		if (ekgIsGappedModel(mm)) return null; // its Start/Count aren't real addresses
		var s = parseInt(mm.StartChannel, 10) || 1;
		var c = parseInt(mm.ChannelCount, 10) || 0;
		return c > 0 ? { start: s, end: s + c - 1 } : null;
	}

	// Host = another model whose range fully CONTAINS m's entire declared
	// range (not just overlaps it), picking the widest such match if more
	// than one qualifies. Containment, not overlap, is the signal: a real
	// fixture's own small per-channel alias models (e.g. a single-string
	// "MH_Pan-2" pointing at one of MH1's channels) are legitimately nested
	// inside it, and MH1 itself overlaps every one of them - but MH1's own
	// full range is never a strict subset of anything else, so it correctly
	// never becomes a "host" search target below and is left alone as a
	// normal contiguous model. A merely-overlapping (not fully containing)
	// model doesn't qualify, which is what keeps this from misfiring on it.
	var host = null, hostRange = null, hostCount = -1;
	for (var h = 0; h < allModels.length; h++) {
		if (allModels[h] === m) continue;
		var hr = rangeOf(allModels[h]);
		if (hr && hr.start <= start && end <= hr.end) {
			var hc = parseInt(allModels[h].ChannelCount, 10) || 0;
			if (hc > hostCount) { host = allModels[h]; hostRange = hr; hostCount = hc; }
		}
	}
	if (!host) return null; // no fully-containing model found - treat as genuinely contiguous

	var offset = start - hostRange.start;
	var perString = Math.round(count / stringCount) || 1;

	function shapeKey(mm) {
		return [mm.ChannelCount, mm.StringCount, mm.StrandsPerString, mm.ChannelCountPerNode]
			.map(function (v) { return parseInt(v, 10) || 0; }).join(',');
	}
	var hostShape = shapeKey(host);
	var family = [];
	for (var f = 0; f < allModels.length; f++) {
		if (!ekgIsGappedModel(allModels[f]) && shapeKey(allModels[f]) === hostShape) family.push(allModels[f]);
	}
	family.sort(function (a, b) { return (parseInt(a.StartChannel, 10) || 0) - (parseInt(b.StartChannel, 10) || 0); });

	var channels = [];
	for (var s = 0; s < stringCount && s < family.length; s++) {
		var base = (parseInt(family[s].StartChannel, 10) || 1) + offset;
		for (var k = 0; k < perString; k++) {
			channels.push(base + k);
		}
	}
	return channels.length ? channels : null;
}

// How ekgModelChannels() resolved this model's channels - for the info text.
function ekgModelChannelsKind(m) {
	if (ekgIsGappedModel(m)) return 'sub';
	if (ekgInferChainedChannels(m, ekgModels)) return 'inferred';
	return 'contiguous';
}

// Returns a model's real, absolute 1-based output channels in on-screen
// (row-major Grid) order. For an ordinary contiguous model that's just
// StartChannel..StartChannel+ChannelCount-1; for a gapped overlay model (see
// ekgIsGappedModel) it walks the Grid instead, the same way FPP's own
// PixelOverlayModelSub::buildGrid()/buildChannelGrid() do, so gaps are
// skipped and the channels chosen are the model's actual ones. Failing that,
// it falls back to ekgInferChainedChannels()'s best-effort guess before
// finally assuming a plain contiguous range.
function ekgModelChannels(m) {
	if (ekgIsGappedModel(m)) {
		return ekgGappedModelChannels(m);
	}
	var inferred = ekgInferChainedChannels(m, ekgModels);
	if (inferred) {
		return inferred;
	}
	var startChannel = parseInt(m.StartChannel, 10) || 1;
	var channelCount = parseInt(m.ChannelCount, 10) || 0;
	var channels = [];
	for (var i = 0; i < channelCount; i++) {
		channels.push(startChannel + i);
	}
	return channels;
}

// Grid is rows separated by ';', columns by ',', with an empty cell ("") as a
// hole/gap that contributes no channel. What a populated cell holds depends
// on SubType:
//   - "grid" (an xLights submodel): the cell is a PARENT node number, and
//     channel = (ParentStartChannel - 1) + (node - 1) * ChannelCountPerNode.
//   - "channelgrid" (an xLights model group): the cell is already one or more
//     '&'-separated absolute 1-based start channels (member pixels binned
//     into that cell).
// Either way, each resolved start channel expands to ChannelCountPerNode
// consecutive channels (R,G,B[,W]) and duplicates (a group can bin more than
// one member pixel into the same channel) are dropped.
function ekgGappedModelChannels(m) {
	var cpn = parseInt(m.ChannelCountPerNode, 10) || 3;
	var parentStart0 = (parseInt(m.ParentStartChannel, 10) || 1) - 1;
	var rows = String(m.Grid || '').split(';');
	var channels = [];
	var seen = {};
	function addNode(base0) {
		for (var k = 0; k < cpn; k++) {
			var ch = base0 + k + 1;
			if (!seen[ch]) {
				seen[ch] = true;
				channels.push(ch);
			}
		}
	}
	for (var y = 0; y < rows.length; y++) {
		var cells = rows[y].split(',');
		for (var x = 0; x < cells.length; x++) {
			var cell = cells[x];
			if (!cell) continue; // hole - no pixel at this grid position
			if (m.SubType === 'channelgrid') {
				var parts = cell.split('&');
				for (var p = 0; p < parts.length; p++) {
					var abs1 = parseInt(parts[p], 10);
					if (abs1 > 0) addNode(abs1 - 1);
				}
			} else {
				var node = parseInt(cell, 10);
				if (node > 0) addNode(parentStart0 + (node - 1) * cpn);
			}
		}
	}
	return channels;
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

	var channels = ekgModelChannels(m);
	var kind = ekgModelChannelsKind(m);
	if (kind === 'sub') {
		infoEl.textContent = channels.length + ' real channels (non-contiguous overlay model)';
	} else if (kind === 'inferred') {
		infoEl.textContent = channels.length + ' real channels, guessed from other matching fixtures ' +
			'(non-contiguous - declared as ' + (parseInt(m.StringCount, 10) || 1) + ' strings)';
	} else {
		var startChannel = parseInt(m.StartChannel, 10) || 1;
		infoEl.textContent = channels.length + ' raw channels (Ch ' + startChannel + '-' + (startChannel + channels.length - 1) + ')';
	}
	infoEl.style.display = '';

	var addCount = channels.length;
	document.getElementById('ekgModelAddCount').textContent = addCount;
	addRow.style.display = '';
}

// Bulk-adds every real output channel of the selected model (up to the
// EKG_MAX_CHANNELS overall cap) - honoring gaps for non-contiguous overlay
// models (see ekgModelChannels) instead of assuming StartChannel..
// StartChannel+N. A model with more channels than fit on one tab just spills
// onto however many tabs it needs (see EKG_CHANNELS_PER_TAB).
function ekgAddModelChannels() {
	if (!ekgSelectedModel) return;
	var channels = ekgModelChannels(ekgSelectedModel);
	var toAdd = channels.length;
	var added = 0;
	for (var i = 0; i < toAdd; i++) {
		if (ekgPicked.length >= EKG_MAX_CHANNELS) break;
		var channel = channels[i];
		var exists = false;
		for (var j = 0; j < ekgPicked.length; j++) {
			if (ekgPicked[j].channel === channel) { exists = true; break; }
		}
		if (exists) continue;
		ekgPicked.push({ channel: channel, label: ekgSelectedModel.Name + ' Ch' + (i + 1) });
		added++;
	}
	// Jump to the tab holding what was just added, so it's visible right away
	// instead of silently landing on a tab the user isn't looking at.
	if (added > 0) ekgActiveTab = Math.floor((ekgPicked.length - 1) / EKG_CHANNELS_PER_TAB);
	ekgSave();
	if (added < toAdd) {
		alert('Added ' + added + ' of ' + toAdd + ' channels - the ' + EKG_MAX_CHANNELS +
			' channel monitoring limit was reached or some were already in the list.');
	}
}

function ekgRemoveAllChannels() {
	ekgPicked = [];
	ekgActiveTab = 0;
	ekgSave();
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
	ekgActiveTab = Math.floor((ekgPicked.length - 1) / EKG_CHANNELS_PER_TAB);
	document.getElementById('ekgManualChannel').value = '';
	document.getElementById('ekgLabel').value = '';
	ekgSave();
}

// Removes a channel by its channel number (unique) from the live grid/staged list.
function ekgRemoveChannel(channel) {
	ekgPicked = ekgPicked.filter(function (c) { return c.channel !== channel; });
	ekgSave();
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

// Splits ekgPicked into pages of EKG_CHANNELS_PER_TAB, the same grouping
// FPP's own Display Testing > Channel Fader tab uses for its DMX sliders.
function ekgTabCount() {
	return Math.max(1, Math.ceil(ekgPicked.length / EKG_CHANNELS_PER_TAB));
}

// Rebuilds the tab nav from ekgPicked/ekgActiveTab. Hidden entirely when
// everything fits on one tab, since there's nothing to switch between.
function ekgRenderTabsNav() {
	var nav = document.getElementById('ekgTabsNav');
	var numTabs = ekgTabCount();
	if (ekgPicked.length <= EKG_CHANNELS_PER_TAB) {
		nav.style.display = 'none';
		nav.innerHTML = '';
		return;
	}
	if (ekgActiveTab >= numTabs) ekgActiveTab = numTabs - 1;
	if (ekgActiveTab < 0) ekgActiveTab = 0;

	var html = '';
	for (var t = 0; t < numTabs; t++) {
		var first = t * EKG_CHANNELS_PER_TAB + 1;
		var last = Math.min(first + EKG_CHANNELS_PER_TAB - 1, ekgPicked.length);
		var active = (t === ekgActiveTab) ? ' active' : '';
		html += '<li class="nav-item"><a href="#" class="nav-link' + active + '" role="tab" ' +
			'aria-selected="' + (t === ekgActiveTab ? 'true' : 'false') + '" ' +
			'onclick="ekgSelectTab(' + t + '); return false;">' + first + '-' + last + '</a></li>';
	}
	nav.innerHTML = html;
	nav.style.display = '';
}

// Switches the active tab. Rebuilding through ekgRenderLiveGrid() tears down
// the previous tab's cards/charts and builds the new tab's from scratch,
// rather than pre-building every tab and toggling visibility.
function ekgSelectTab(t) {
	ekgActiveTab = t;
	ekgRenderLiveGrid();
}

// Rebuilds the tab nav and the live grid for the active tab only, from
// ekgPicked. History is kept for every channel still in ekgPicked - including
// ones on other tabs, so switching back to a tab resumes its graphs rather
// than restarting them - and dropped only for channels no longer in the list
// at all.
function ekgRenderLiveGrid() {
	var grid = document.getElementById('ekgLiveGrid');
	var stillPicked = {};
	for (var i = 0; i < ekgPicked.length; i++) {
		stillPicked[ekgPicked[i].channel] = true;
	}
	for (var ch in ekgHistory) {
		if (!stillPicked[ch]) delete ekgHistory[ch];
	}

	ekgRenderTabsNav();

	var first = ekgActiveTab * EKG_CHANNELS_PER_TAB;
	var last = Math.min(first + EKG_CHANNELS_PER_TAB, ekgPicked.length);

	grid.innerHTML = '';
	for (var i = first; i < last; i++) {
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

var SVG_NS = 'http://www.w3.org/2000/svg';

// Maps v from [d0,d1] to [r0,r1] - the one piece of d3-scaleLinear this chart
// needs. Not pulling in d3 itself: FPP core doesn't bundle it (js/d3.v7.min.js
// 404s on a stock install), so relying on it silently broke every chart here.
function ekgLinearScale(domain, range) {
	var d0 = domain[0], d1 = domain[1], r0 = range[0], r1 = range[1];
	return function (v) {
		if (d1 === d0) return r0;
		return r0 + (v - d0) / (d1 - d0) * (r1 - r0);
	};
}

function ekgLinePath(xScale, yScale, hist) {
	var d = '';
	for (var i = 0; i < hist.length; i++) {
		d += (i === 0 ? 'M' : 'L') + xScale(hist[i][0]).toFixed(2) + ',' + yScale(hist[i][1]).toFixed(2) + ' ';
	}
	return d;
}

function ekgDrawChart(channel, hist, now) {
	var svg = document.getElementById('ekgSvg_' + channel);
	if (!svg) return;
	var x = ekgLinearScale([now - EKG_HISTORY_MS, now], [0, 200]);
	var y = ekgLinearScale([0, 255], [65, 2]);

	// The y-domain is fixed (0-255), so the three reference lines never move -
	// built once per card and reused rather than redrawn every poll.
	if (!svg.querySelector('.ekgAxisGroup')) {
		var axisGroup = document.createElementNS(SVG_NS, 'g');
		axisGroup.setAttribute('class', 'ekgAxisGroup');
		[0, 128, 255].forEach(function (v) {
			var line = document.createElementNS(SVG_NS, 'line');
			line.setAttribute('class', 'ekgAxis');
			line.setAttribute('x1', 0);
			line.setAttribute('x2', 200);
			line.setAttribute('y1', y(v));
			line.setAttribute('y2', y(v));
			axisGroup.appendChild(line);
		});
		svg.appendChild(axisGroup);
	}

	var path = svg.querySelector('path.ekgLine');
	if (!path) {
		path = document.createElementNS(SVG_NS, 'path');
		path.setAttribute('class', 'ekgLine');
		svg.appendChild(path);
	}
	path.setAttribute('d', ekgLinePath(x, y, hist));
}

$(document).ready(function () {
	ekgLoadModels();
	ekgLoadCurrentConfig();
});
</script>
