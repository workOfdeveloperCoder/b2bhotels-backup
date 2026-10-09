<?php @$SECURE or die('Access Denied!');
// WIDGETY — Import tab: run the content sync (operators -> ships -> ports -> cruises).
// Calls: POST modules/cruises/widgety/content_import  (action=all|operators|ships|ports|holidays)
//        GET  modules/cruises/widgety/stats
// Cruises are imported ONE PAGE (25) PER REQUEST; this screen loops the pages,
// shows progress, can be stopped, and resumes from the saved page next time.
$wgBase = rtrim(root, '/') . '/modules/cruises/widgety';
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-600 text-lg">upload_file</span>
        <h3 class="text-sm font-semibold text-gray-900">Content Import</h3>
    </div>
    <div class="p-4 space-y-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-900">
            Imports the Widgety catalogue into this module's content database: Operators → Ships → Ports → Cruises (with every sailing and cabin price).
            Cruises load 25 at a time; you can stop and it continues from the same page next time. Cruises synced in the last 20 hours are skipped unless you tick "Refresh all".
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3" id="wg-stats">
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Operators</div><div class="text-lg font-bold text-gray-900" data-stat="operators">—</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Ships</div><div class="text-lg font-bold text-gray-900" data-stat="ships">—</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Ports</div><div class="text-lg font-bold text-gray-900" data-stat="ports">—</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Cruises</div><div class="text-lg font-bold text-gray-900" data-stat="cruises">—</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Sailings</div><div class="text-lg font-bold text-gray-900" data-stat="departures">—</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn" data-wg-import="all">
                <span class="material-symbols-outlined text-sm">cloud_sync</span> Full Import
            </button>
            <button type="button" class="btn secondary" data-wg-import="operators">Operators</button>
            <button type="button" class="btn secondary" data-wg-import="ships">Ships</button>
            <button type="button" class="btn secondary" data-wg-import="ports">Ports</button>
            <button type="button" class="btn secondary" data-wg-import="holidays">Cruises</button>
            <button type="button" class="btn light hidden" id="wg-stop">
                <span class="material-symbols-outlined text-sm">stop_circle</span> Stop
            </button>
            <button type="button" class="btn light" id="wg-refresh">
                <span class="material-symbols-outlined text-sm">refresh</span> Refresh
            </button>
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 ml-1 cursor-pointer">
                <input type="checkbox" id="wg-force" class="rounded"> Refresh all (re-fetch recent cruises)
            </label>
        </div>

        <div id="wg-progress" class="hidden">
            <div class="flex justify-between text-xs text-gray-600 mb-1">
                <span id="wg-progress-label">Cruises</span><span id="wg-progress-pct">0%</span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div id="wg-progress-bar" class="h-full bg-blue-600 transition-all" style="width:0%"></div>
            </div>
        </div>

        <pre id="wg-log" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-3 h-48 overflow-auto font-mono whitespace-pre-wrap">Ready.</pre>
    </div>
</div>

<script>
(function () {
    const base = <?= json_encode($wgBase) ?>;
    const logEl = document.getElementById('wg-log');
    const statsWrap = document.getElementById('wg-stats');
    const stopBtn = document.getElementById('wg-stop');
    const prog = document.getElementById('wg-progress');
    let stopRequested = false;

    const now = () => new Date().toLocaleTimeString();
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    function log(line) {
        logEl.textContent += '\n' + line;
        logEl.scrollTop = logEl.scrollHeight;
    }
    function setBusy(busy) {
        document.querySelectorAll('[data-wg-import], #wg-refresh').forEach(b => b.disabled = busy);
        stopBtn.classList.toggle('hidden', !busy);
        stopRequested = false;
    }
    function setProgress(page, last) {
        prog.classList.remove('hidden');
        const pct = last ? Math.min(100, Math.round(page / last * 100)) : 0;
        document.getElementById('wg-progress-label').textContent = 'Cruises — page ' + page + ' of ' + last;
        document.getElementById('wg-progress-pct').textContent = pct + '%';
        document.getElementById('wg-progress-bar').style.width = pct + '%';
    }
    function paintStats(stats) {
        if (!stats) return;
        statsWrap.querySelectorAll('[data-stat]').forEach(el => {
            const k = el.getAttribute('data-stat');
            if (stats[k] !== undefined) el.textContent = stats[k];
        });
    }
    async function refreshStats() {
        try {
            const res = await fetch(base + '/stats', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (data.success) paintStats(data.stats);
        } catch (e) { /* non-fatal */ }
    }

    // POST one import request; returns parsed JSON or null (timeout / throttled).
    async function post(params) {
        const fd = new FormData();
        Object.keys(params).forEach(k => fd.append(k, params[k]));
        const res = await fetch(base + '/content_import', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    async function runSimple(action) {
        log('[' + now() + '] Import (' + action + ') started…');
        const data = await post({ action });
        if (!data) {
            log('[WARN] No valid response — the request likely timed out or was rate-limited by Widgety. Retry in a moment.');
            return false;
        }
        log((data.success ? '[OK] ' : '[ERROR] ') + (data.message || '') + (data.success ? ' — saved ' + (data.saved ?? 0) : ''));
        if (data.results) {
            Object.keys(data.results).forEach(k => {
                const r = data.results[k];
                log('   • ' + k + ': seen ' + r.seen + ', saved ' + r.saved + ', pages ' + r.pages + (r.error ? ' — ' + r.error : ''));
            });
        }
        return !!data.success;
    }

    // Loop cruise pages until done or stopped. startPage=0 resumes from the saved page.
    async function runCruises(startPage) {
        const force = document.getElementById('wg-force').checked ? '1' : '';
        let page = startPage;
        log('[' + now() + '] Cruises import ' + (page ? 'from page ' + page : '(resuming)') + '…');
        while (!stopRequested) {
            let data = null;
            for (let attempt = 1; attempt <= 3 && !data; attempt++) {
                const params = { action: 'holidays', force };
                if (page) params.page = page;
                data = await post(params);
                if (!data || !data.success) {
                    log('[WARN] ' + (data ? data.message : 'No response') + ' — retry ' + attempt + '/3 in 5s');
                    data = null;
                    await sleep(5000);
                }
            }
            if (!data) { log('[ERROR] Stopped after 3 failed attempts. Click Cruises to resume.'); return false; }

            setProgress(data.page, data.last_page);
            log('[OK] ' + data.message);
            (data.errors || []).forEach(e => log('   ! ' + e));
            await refreshStats();
            if (data.done) { log('[DONE] All ' + data.last_page + ' pages imported.'); return true; }
            page = data.next_page;
        }
        log('[STOPPED] Click Cruises to continue from page ' + page + '.');
        return false;
    }

    async function run(action) {
        setBusy(true);
        try {
            if (action === 'holidays') {
                await runCruises(0);
            } else if (action === 'all') {
                if (await runSimple('all')) await runCruises(1);
            } else {
                await runSimple(action);
            }
        } catch (e) {
            log('[ERROR] ' + e.message);
        } finally {
            await refreshStats();
            setBusy(false);
        }
    }

    document.querySelectorAll('[data-wg-import]').forEach(btn => {
        btn.addEventListener('click', () => run(btn.getAttribute('data-wg-import')));
    });
    stopBtn.addEventListener('click', () => { stopRequested = true; log('[' + now() + '] Stopping after this page…'); });
    document.getElementById('wg-refresh').addEventListener('click', refreshStats);

    refreshStats();
})();
</script>
