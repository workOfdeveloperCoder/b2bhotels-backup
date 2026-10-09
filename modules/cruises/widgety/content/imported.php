<?php @$SECURE or die('Access Denied!');
// WIDGETY — Contents tab: summary of imported catalogue (from the module content DB).
require_once dirname(__DIR__) . '/api.php';

$wg_error = '';
$wg_operators = $wg_ships = $wg_ports = $wg_cruises = $wg_departures = 0;
$wg_lastSync = null;
$wg_sample = [];
try {
    $mdb = _widgety_db($db);
    $wg_operators  = (int) $mdb->count('widgety_operators');
    $wg_ships      = (int) $mdb->count('widgety_ships');
    $wg_ports      = (int) $mdb->count('widgety_ports');
    $wg_cruises    = (int) $mdb->count('widgety_cruises');
    $wg_departures = (int) $mdb->count('widgety_departures');
    $wg_lastSync   = $mdb->get('sync_log', ['sync_type', 'items', 'created_at'], ['ORDER' => ['id' => 'DESC']]);
    $wg_sample     = $mdb->select('widgety_cruises',
        ['widgety_id', 'name', 'nights', 'lead_price', 'lead_currency', 'updated_at'],
        ['ORDER' => ['updated_at' => 'DESC'], 'LIMIT' => 15]) ?: [];
} catch (\Throwable $e) {
    $wg_error = $e->getMessage();
}
?>
<?php if ($wg_error): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-5 text-sm">
    <?= htmlspecialchars($wg_error) ?>
</div>
<?php endif; ?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-600 text-lg">inventory_2</span>
        <h3 class="text-sm font-semibold text-gray-900">Imported Content</h3>
    </div>
    <div class="p-4">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Operators</div><div class="text-lg font-bold text-gray-900"><?= $wg_operators ?></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Ships</div><div class="text-lg font-bold text-gray-900"><?= $wg_ships ?></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Ports</div><div class="text-lg font-bold text-gray-900"><?= $wg_ports ?></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Cruises</div><div class="text-lg font-bold text-gray-900"><?= $wg_cruises ?></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Departures</div><div class="text-lg font-bold text-gray-900"><?= $wg_departures ?></div></div>
        </div>
        <p class="text-xs text-gray-400 mb-4">Last sync: <?= $wg_lastSync ? htmlspecialchars($wg_lastSync['sync_type'] . ' · ' . $wg_lastSync['items'] . ' saved · ' . $wg_lastSync['created_at']) : '—' ?></p>

        <?php if ($wg_sample): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
                    <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Cruise</th>
                    <th class="py-2 pr-3">Nights</th><th class="py-2 pr-3">Lead price</th><th class="py-2">Updated</th>
                </tr></thead>
                <tbody>
                <?php foreach ($wg_sample as $r): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) $r['widgety_id']) ?></td>
                        <td class="py-2 pr-3"><?= htmlspecialchars(mb_strimwidth((string) $r['name'], 0, 60, '…')) ?></td>
                        <td class="py-2 pr-3"><?= (int) $r['nights'] ?></td>
                        <td class="py-2 pr-3"><?= $r['lead_price'] !== null ? htmlspecialchars(trim((string) $r['lead_currency'] . ' ' . $r['lead_price'])) : '—' ?></td>
                        <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars((string) $r['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-2">Showing latest <?= count($wg_sample) ?> of <?= $wg_cruises ?> cruises.</p>
        <?php else: ?>
        <div class="text-center py-8">
            <span class="material-symbols-outlined text-gray-300 text-4xl block mb-2">inventory_2</span>
            <p class="text-sm text-gray-500">No content imported yet. Run a Full Import from the Import tab.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
