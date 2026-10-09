<?php
// ============================================================================
// GRNCONNECT CONTENTS TAB (admin settings > Contents)
// Included by app/views/admin/settings/modules-settings.php
// Shows a quick summary of imported static content.
// ============================================================================
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">inventory_2</span>
            <h3 class="text-sm font-semibold text-gray-900">Imported Content</h3>
        </div>
    </div>
    <div class="p-4">
        <div id="grn-contents-summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">Countries</div>
                <div class="text-2xl font-semibold text-gray-900" id="grn-c-countries">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">Cities</div>
                <div class="text-2xl font-semibold text-gray-900" id="grn-c-cities">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">Hotels</div>
                <div class="text-2xl font-semibold text-gray-900" id="grn-c-hotels">—</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">Images</div>
                <div class="text-2xl font-semibold text-gray-900" id="grn-c-images">—</div>
            </div>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            Content database: <span class="font-mono" id="grn-c-db">—</span>
        </p>
    </div>
</div>

<script>
(function () {
    const endpoint = '<?= root ?>modules/stays/grnconnect/import';
    async function load() {
        try {
            const body = new FormData();
            body.append('action', 'check');
            const res = await fetch(endpoint, { method: 'POST', body });
            const r = await res.json();
            if (r && r.stats) {
                document.getElementById('grn-c-countries').textContent = r.stats.countries ?? 0;
                document.getElementById('grn-c-cities').textContent = r.stats.cities ?? 0;
                document.getElementById('grn-c-hotels').textContent = r.stats.hotels ?? 0;
                document.getElementById('grn-c-images').textContent = r.stats.images ?? 0;
            }
            document.getElementById('grn-c-db').textContent = (r && r.database) ? r.database : '—';
        } catch (e) {}
    }
    document.addEventListener('DOMContentLoaded', load);
    load();
})();
</script>
