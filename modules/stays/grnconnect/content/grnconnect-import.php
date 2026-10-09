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
            GRNConnect availability search requires local hotel codes. Import
            <strong>countries</strong> and <strong>cities</strong> first, then import
            <strong>hotels</strong> for the cities/countries you sell. Hotels can be
            imported per city code (recommended) or per country code.
        </div>

        <div id="grn-stats" class="grid grid-cols-3 gap-3 text-center">
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
        </div>

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
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-gray-100">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Import Hotels by City Code</label>
                <div class="flex gap-2">
                    <input type="text" id="grn-city-code" class="input w-full" placeholder="e.g. 121449">
                    <button type="button" class="btn" onclick="grnImportHotels('city')">Import</button>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Import Hotels by Country Code</label>
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

    function status(msg, type) {
        const el = document.getElementById('grn-import-status');
        if (!el) return;
        el.classList.remove('hidden');
        el.textContent = msg;
        el.style.color = type === 'error' ? '#b91c1c' : (type === 'success' ? '#15803d' : '#374151');
    }

    async function call(action, extra) {
        const body = new FormData();
        body.append('action', action);
        if (extra) for (const k in extra) body.append(k, extra[k]);
        const res = await fetch(endpoint, { method: 'POST', body });
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { return { success: false, error: 'Invalid response: ' + text.slice(0, 200) }; }
    }

    async function refreshStats() {
        const r = await call('check');
        if (r && r.stats) {
            document.getElementById('grn-stat-countries').textContent = r.stats.countries ?? 0;
            document.getElementById('grn-stat-cities').textContent = r.stats.cities ?? 0;
            document.getElementById('grn-stat-hotels').textContent = r.stats.hotels ?? 0;
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
        status('Importing hotels for ' + mode + ' ' + code + '…');
        const r = await call('import_hotels', mode === 'city' ? { city: code } : { country: code });
        status(r.success ? (r.message || 'Done.') : ('Error: ' + (r.error || 'unknown')), r.success ? 'success' : 'error');
        refreshStats();
    };

    document.addEventListener('DOMContentLoaded', refreshStats);
    refreshStats();
})();
</script>
