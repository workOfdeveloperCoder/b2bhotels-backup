<?php
// ============================================================================
// GRNCONNECT IMPORT TAB (admin settings > Import)
// Included by app/views/admin/settings/modules-settings.php
// ============================================================================
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">cloud_download</span>
            <h3 class="text-sm font-semibold text-gray-900">GRNConnect Static Content Import</h3>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-900">
            Import <strong>countries</strong> and <strong>cities</strong> first, then hotels.
            <strong>Import All Hotels</strong> walks every city and skips hotel codes already in the catalog. Use <strong>Stop</strong> to pause.
        </div>

        <div id="grn-stats" class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
            <div class="border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500">Countries</div>
                <div class="text-lg font-semibold text-gray-900" id="grn-stat-countries">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500">Cities</div>
                <div class="text-lg font-semibold text-gray-900" id="grn-stat-cities">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500">Hotels</div>
                <div class="text-lg font-semibold text-gray-900" id="grn-stat-hotels">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500">Cities left</div>
                <div class="text-lg font-semibold text-gray-900" id="grn-stat-pending">—</div>
            </div>
        </div>
        <div id="grn-stat-db" class="text-xs text-gray-500"></div>

        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" onclick="grnImport('create_tables')">
                <span class="material-symbols-outlined text-sm">database</span> Create Tables
            </button>
            <button type="button" class="btn" onclick="grnImport('import_countries')">
                <span class="material-symbols-outlined text-sm">public</span> Import Countries
            </button>
            <button type="button" class="btn" onclick="grnImport('import_cities')">
                <span class="material-symbols-outlined text-sm">location_city</span> Import Cities
            </button>
            <button type="button" class="btn" id="grn-import-all-btn" onclick="grnImportAllHotels()">
                <span class="material-symbols-outlined text-sm">hotel</span> Import All Hotels
            </button>
            <button type="button" class="btn hidden" id="grn-import-all-stop" onclick="grnStopImportAll()">
                Stop
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-gray-100">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Check / import hotels by code (fast)</label>
                <textarea id="grn-hotel-codes" class="input w-full h-28 font-mono text-xs" placeholder="Paste hotel codes, one per line or comma-separated"></textarea>
                <div class="flex flex-wrap gap-2 mt-2">
                    <button type="button" class="btn" onclick="grnCheckHotelCodes()">Check vs catalog</button>
                    <button type="button" class="btn" onclick="grnImportHotelCodes()">Import missing only</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Fetches real hotel records from GRN static <code>GET api/v3/hotels/?hcode=</code>. Existing catalog rows are skipped. If GRN has no record, the code is skipped — nothing dummy is inserted.</p>
                <pre id="grn-hotel-codes-result" class="hidden mt-2 text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 whitespace-pre-wrap max-h-48 overflow-auto"></pre>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Live availability by city code</label>
                <div class="flex gap-2">
                    <input type="text" id="grn-city-code" class="input w-full" placeholder="e.g. 121449">
                    <button type="button" class="btn" onclick="grnImportHotels('city')">Import</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Uses live <code>POST api/v3/hotels/availability</code>. Only hotels with a rate for the probe stay (about 3 weeks out, 1 night, 2 adults) are added. Existing catalog rows are skipped.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Import Hotels by Country Code (optional)</label>
                <div class="flex gap-2">
                    <input type="text" id="grn-country-code" class="input w-full" placeholder="e.g. AE">
                    <button type="button" class="btn" onclick="grnImportHotels('country')">Import</button>
                </div>
            </div>
        </div>

        <div id="grn-import-status" class="text-sm text-gray-600 hidden px-3 py-2 rounded-lg bg-gray-50 border border-gray-200"></div>
    </div>
</div>

<script>
(function () {
    const endpoint = '<?= root ?>modules/stays/grnconnect/import';

    function formatJobProgress(job, stats) {
        if (!job) return '';
        const total = parseInt(job.total_cities || 0, 10) || 0;
        const pendingJob = parseInt(job.pending_cities || 0, 10) || 0;
        const pendingStats = stats ? (parseInt(stats.pending_cities || 0, 10) || 0) : 0;
        const pending = Math.max(pendingJob, pendingStats);
        const processed = Math.max(parseInt(job.processed || 0, 10) || 0, total > 0 ? Math.max(0, total - pending) : 0);
        const neu = parseInt(job.imported || 0, 10) || 0;
        const skip = parseInt(job.skipped || 0, 10) || 0;
        const upd = parseInt(job.updated || 0, 10) || 0;
        const err = parseInt(job.error_count || 0, 10) || 0;
        const city = String(job.last_city || '').trim();
        const parts = [];
        if (total > 0) {
            parts.push('Cities imported ' + processed + '/' + total);
            parts.push(pending + ' remaining');
        } else if (processed > 0) {
            parts.push('Cities imported ' + processed);
        }
        parts.push(neu + ' hotels new');
        parts.push(skip + ' skipped (already in catalog)');
        if (upd > 0) parts.push(upd + ' updated');
        if (err > 0) parts.push(err + ' errors skipped');
        if (city) parts.push('current city ' + city);
        let line = parts.join(' · ');
        if (job.stop) {
            line = 'Stop requested. This city will finish, then import pauses. ' + line;
        } else if (job.status === 'completed' || (total > 0 && pending < 1)) {
            line = 'Done. ' + line;
        }
        return line;
    }

    function status(msg, type) {
        const el = document.getElementById('grn-import-status');
        if (!el) return;
        el.classList.remove('hidden');
        el.textContent = msg;
        el.style.color = type === 'error' ? '#b91c1c' : (type === 'success' ? '#15803d' : (type === 'warn' ? '#b45309' : '#374151'));
    }

    async function call(action, extra, timeoutMs) {
        const body = new FormData();
        body.append('action', action);
        if (extra) for (const k in extra) body.append(k, extra[k]);
        const ms = timeoutMs || (function () {
            if (action === 'check') return 8000;
            if (action === 'import_cities') return 180000;
            if (action === 'import_countries' || action === 'create_tables') return 90000;
            if (action === 'import_hotels_all' || action === 'import_hotels_refresh') return 20000;
            if (action === 'import_hotels' || action === 'import_hotel_codes') return 180000;
            if (action === 'check_hotel_codes') return 30000;
            return 12000;
        })();
        const ctrl = new AbortController();
        const timer = setTimeout(function () { ctrl.abort(); }, ms);
        let res;
        try {
            res = await fetch(endpoint, { method: 'POST', body, signal: ctrl.signal });
        } catch (e) {
            const aborted = e && (e.name === 'AbortError' || /abort/i.test(String(e.message || '')));
            return { success: false, error: aborted ? 'Import is still running on the server (browser wait timed out). Wait 30s then click Import Cities again — already-imported rows are skipped.' : ('Network error: ' + (e && e.message ? e.message : 'fetch failed')) };
        } finally {
            clearTimeout(timer);
        }
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { return { success: false, error: 'Invalid response: ' + text.slice(0, 200) }; }
    }

    let clientRunning = false;

    function setStopVisible(show) {
        const stopBtn = document.getElementById('grn-import-all-stop');
        if (stopBtn) stopBtn.classList.toggle('hidden', !show);
    }

    function applyJob(job, stats) {
        if (!job) return;
        const pendingJob = parseInt(job.pending_cities || 0, 10) || 0;
        const pendingStats = stats ? (parseInt(stats.pending_cities || 0, 10) || 0) : 0;
        const pending = Math.max(pendingJob, pendingStats);
        const stopped = !!job.stop;
        const serverRunning = !stopped && (job.status === 'running' || job.alive === true);
        const completed = job.status === 'completed' || (!serverRunning && pending < 1);
        const running = !completed && !stopped && (clientRunning || serverRunning);
        const startBtn = document.getElementById('grn-import-all-btn');
        if (completed || stopped) clientRunning = false;
        if (serverRunning) clientRunning = true;
        if (startBtn) startBtn.disabled = !!running;
        setStopVisible(!!running);
        if (running) {
            let msg = formatJobProgress(job, stats) || job.message || '';
            if (job.last_error) {
                msg += (msg ? ' ' : '') + 'Last error (continuing): ' + job.last_error;
            }
            if (msg) status(msg, job.last_error ? 'warn' : '');
            if (!pollTimer) pollTimer = setInterval(refreshStats, 3000);
        } else if (completed) {
            stopPolling();
        }
    }

    let pollTimer = null;
    let pollInFlight = false;
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }
    function startPolling() {
        if (!pollTimer) {
            pollTimer = setInterval(refreshStats, 3000);
        }
        refreshStats();
    }

    function showStopForBusyServer() {
        const stopBtn = document.getElementById('grn-import-all-stop');
        if (stopBtn) stopBtn.classList.remove('hidden');
        const startBtn = document.getElementById('grn-import-all-btn');
        if (startBtn) startBtn.disabled = false;
    }

    let lastStats = null;

    async function refreshStats() {
        if (pollInFlight) return;
        pollInFlight = true;
        try {
            const r = await call('check');
            if (!r.success && r.error) {
                if (/busy|timeout/i.test(r.error)) {
                    if (clientRunning) {
                        showStopForBusyServer();
                        return r;
                    }
                    status(r.error, 'error');
                    showStopForBusyServer();
                    return r;
                }
                status(r.error, 'error');
                return r;
            }
            if (r && r.stats) {
                lastStats = r.stats;
                document.getElementById('grn-stat-countries').textContent = r.stats.countries ?? 0;
                document.getElementById('grn-stat-cities').textContent = r.stats.cities ?? 0;
                document.getElementById('grn-stat-hotels').textContent = r.stats.hotels ?? 0;
                const pendingEl = document.getElementById('grn-stat-pending');
                if (pendingEl) pendingEl.textContent = r.stats.pending_cities ?? '—';
                const dbEl = document.getElementById('grn-stat-db');
                if (dbEl) {
                    const dbName = r.stats.database || r.database || '';
                    dbEl.textContent = dbName ? ('Content database: ' + dbName + ' (exact hotel count)') : '';
                }
            }
            if (r && r.job) {
                applyJob(r.job, r.stats);
            }
            return r;
        } finally {
            pollInFlight = false;
        }
    }

    window.grnImport = async function (action) {
        status('Working… (' + action + ')');
        const r = await call(action);
        status(r.success ? (r.message || 'Done.') : ('Error: ' + (r.error || 'unknown')), r.success ? 'success' : 'error');
        refreshStats();
    };

    window.grnImportHotels = async function (mode) {
        const code = (mode === 'city' ? document.getElementById('grn-city-code') : document.getElementById('grn-country-code')).value.trim();
        if (!code) { status('Please enter a ' + mode + ' code.', 'error'); return; }
        status((mode === 'city' ? 'Live availability for city ' : 'Importing hotels for country ') + code + '…');
        const r = await call('import_hotels', mode === 'city' ? { city: code } : { country: code });
        status(r.success ? (r.message || 'Done.') : ('Error: ' + (r.error || 'unknown')), r.success ? 'success' : 'error');
        refreshStats();
    };

    window.grnStopImportAll = async function () {
        clientRunning = false;
        status('Stopping background import…');
        const r = await call('import_hotels_all_stop');
        status(r.success ? (r.message || formatJobProgress(r.job, lastStats) || 'Stop requested.') : ('Error: ' + (r.error || 'unknown')), r.success ? 'success' : 'error');
        startPolling();
    };

    window.grnCheckHotelCodes = async function () {
        const codes = (document.getElementById('grn-hotel-codes') || {}).value || '';
        const out = document.getElementById('grn-hotel-codes-result');
        status('Checking codes against the catalog…');
        const r = await call('check_hotel_codes', { codes: codes });
        if (!r.success) {
            status('Error: ' + (r.error || 'unknown'), 'error');
            return;
        }
        const missing = Array.isArray(r.missing) ? r.missing : [];
        const present = Array.isArray(r.present) ? r.present : [];
        let text = (r.message || '') + '\n';
        text += 'Listed: ' + (r.listed || 0) + '  In catalog: ' + (r.present_count || 0) + '  Missing: ' + (r.missing_count || 0) + '\n';
        if (missing.length) {
            text += '\nMissing:\n' + missing.join('\n');
        }
        if (present.length && present.length <= 30) {
            text += '\n\nAlready in catalog:\n' + present.map(function (h) {
                return (h.hotel_code || '') + '  ' + (h.city_code || '') + '  ' + (h.name || '');
            }).join('\n');
        }
        if (out) {
            out.classList.remove('hidden');
            out.textContent = text.trim();
        }
        status(r.message || 'Done.', r.missing_count ? 'warn' : 'success');
        refreshStats();
    };

    window.grnImportHotelCodes = async function () {
        const codes = (document.getElementById('grn-hotel-codes') || {}).value || '';
        const out = document.getElementById('grn-hotel-codes-result');
        status('Importing missing codes from GRN (skip if no record)…');
        const r = await call('import_hotel_codes', { codes: codes });
        if (!r.success) {
            status('Error: ' + (r.error || 'unknown'), 'error');
            return;
        }
        let text = (r.message || '') + '\n';
        if (Array.isArray(r.fetch_failed) && r.fetch_failed.length) {
            text += 'GRN did not return: ' + r.fetch_failed.join(', ');
        }
        if (out) {
            out.classList.remove('hidden');
            out.textContent = text.trim();
        }
        status(r.message || 'Done.', r.failed ? 'warn' : 'success');
        refreshStats();
    };

    window.grnImportAllHotels = async function () {
        const extra = {};
        const startBtn = document.getElementById('grn-import-all-btn');
        if (startBtn) startBtn.disabled = true;
        clientRunning = true;
        setStopVisible(true);
        status('Queueing city jobs… you can leave this page.');
        const r = await call('import_hotels_all', extra);
        if (!r.success) {
            const timedOut = /busy|timeout/i.test(r.error || '');
            if (timedOut) {
                status('Waiting for the server to start the import…');
                startPolling();
                return;
            }
            clientRunning = false;
            setStopVisible(false);
            status('Could not start: ' + (r.error || 'unknown') + ' — click Import All Hotels to retry.', 'error');
            if (startBtn) startBtn.disabled = false;
            return;
        }
        if (r.job) {
            applyJob(r.job, r.stats);
        } else {
            setStopVisible(true);
        }
        status(r.message || 'Background import started. Errors will be skipped.', r.running ? '' : 'success');
        startPolling();
    };

    document.addEventListener('DOMContentLoaded', refreshStats);
})();
</script>
