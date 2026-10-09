<?php
// CRUISE DETAIL — expects $cruise (from _wgc_group), $filters, $selectedRef
@$SECURE or die('Access Denied!');

$cur = _wgc_currency($db);
$adults = $filters['adults'];
$children = $filters['children'];
$sailing = null;
foreach ($cruise['sailings'] as $s) { if ($s['date_ref'] === $selectedRef) { $sailing = $s; break; } }
$ship = $cruise['ship'];
$facts = $ship['facts'] ?? [];
$isRound = !$cruise['disembark'] || $cruise['disembark'] === $cruise['embark'];
$selfUrl = fn(array $q) => _wgc_url($cruise['key'], $cruise['name'], array_merge(['adults' => $adults, 'children' => $children, 'date' => $selectedRef], $q));

// Cabins of the selected sailing, priced for this party, grouped by room type
$roomLabels = ['inside' => 'Inside', 'outside' => 'Ocean View / Outside', 'balcony' => 'Balcony', 'suite' => 'Suite'];
$cabinGroups = [];
foreach ($sailing['cabins'] as $cab) {
    $q = _wgc_quote($db, $sailing, $cab['key'], $adults, $children, $cur);
    $type = $cab['room_type'] ?: 'other';
    $cabinGroups[$type][] = [
        'cabin' => $cab,
        'quote' => $q,
        'pp'    => $cab['double'] > 0 ? _wgc_price($db, $cab['double'], $sailing['currency'], $cur) : 0,
    ];
}
uksort($cabinGroups, fn($a, $b) => (array_search($a, array_keys($roomLabels)) === false ? 9 : array_search($a, array_keys($roomLabels)))
    <=> (array_search($b, array_keys($roomLabels)) === false ? 9 : array_search($b, array_keys($roomLabels))));
$leadPp = $sailing['lead'] ? _wgc_price($db, $sailing['lead'], $sailing['currency'], $cur) : 0;
?>
<div class="bg-gray-100 dark:bg-gray-900 min-h-screen pb-10">

    <!-- HERO -->
    <div class="relative h-64 md:h-80 bg-slate-800">
        <img src="<?= htmlspecialchars($cruise['image']) ?>" alt="<?= htmlspecialchars($cruise['name']) ?>" class="absolute inset-0 w-full h-full object-cover opacity-80" onerror="this.src='<?= root ?>uploads/no_img.jpg'">
        <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/20 to-transparent"></div>
        <div class="container relative h-full flex flex-col justify-end pb-6 text-white">
            <div class="flex items-center gap-1 text-xs mb-2 opacity-90">
                <a href="<?= root ?>cruises" class="hover:underline"><?= T::cruises ?></a>
                <span class="material-symbols-outlined !text-sm">chevron_right</span>
                <a href="<?= root ?>cruises/search" class="hover:underline">Search</a>
                <span class="material-symbols-outlined !text-sm">chevron_right</span>
                <span class="truncate"><?= htmlspecialchars($cruise['name']) ?></span>
            </div>
            <div class="flex items-center gap-3 mb-2">
                <?php if (!empty($cruise['operator']['logo'])): ?>
                <img src="<?= htmlspecialchars($cruise['operator']['logo']) ?>" alt="" class="h-9 bg-white rounded px-2 py-1 object-contain">
                <?php endif; ?>
                <span class="text-sm font-medium"><?= htmlspecialchars($cruise['operator_name']) ?></span>
            </div>
            <h1 class="text-2xl md:text-4xl font-bold leading-tight"><?= htmlspecialchars($cruise['name']) ?></h1>
            <div class="flex flex-wrap gap-2 mt-3 text-xs">
                <span class="bg-white/20 backdrop-blur px-3 py-1 rounded-full flex items-center gap-1"><span class="material-symbols-outlined !text-sm">nights_stay</span><?= $cruise['nights'] ?> nights</span>
                <span class="bg-white/20 backdrop-blur px-3 py-1 rounded-full flex items-center gap-1"><span class="material-symbols-outlined !text-sm">directions_boat</span><?= htmlspecialchars($sailing['ship']) ?></span>
                <span class="bg-white/20 backdrop-blur px-3 py-1 rounded-full flex items-center gap-1"><span class="material-symbols-outlined !text-sm">route</span><?= htmlspecialchars($cruise['embark']) ?><?= $isRound ? ' (round trip)' : ' → ' . htmlspecialchars($cruise['disembark']) ?></span>
            </div>
        </div>
    </div>

    <div class="container mt-6">
        <?php if (!empty($_SESSION['message'])): ?>
            <div class="alert alert-warning mb-4"><span class="material-symbols-outlined">info</span><div><?= htmlspecialchars($_SESSION['message']['text'] ?? '') ?></div></div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 space-y-5">

                <!-- SAILINGS -->
                <div class="card p-0">
                    <div class="card-header">
                        <div><span class="card-header-icon">calendar_month</span><h3>Choose your sailing</h3></div>
                        <span class="text-xs text-gray-500"><?= count($cruise['sailings']) ?> date<?= count($cruise['sailings']) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-72 overflow-y-auto pr-1">
                            <?php foreach ($cruise['sailings'] as $s):
                                $on = $s['date_ref'] === $selectedRef;
                                $sp = $s['lead'] ? _wgc_price($db, $s['lead'], $s['currency'], $cur) : 0; ?>
                            <a href="<?= htmlspecialchars($selfUrl(['date' => $s['date_ref']])) ?>#cabins"
                               class="rounded-lg border p-2 text-center transition <?= $on ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-200' : 'border-gray-200 bg-white hover:border-blue-400' ?>">
                                <div class="text-sm font-semibold text-gray-900"><?= date('D, d M Y', strtotime($s['date'])) ?></div>
                                <div class="text-[11px] text-gray-500 truncate"><?= htmlspecialchars($s['ship']) ?></div>
                                <div class="text-xs mt-1 <?= $sp ? 'text-blue-700 font-semibold' : 'text-gray-500' ?>"><?= $sp ? 'from ' . $cur . ' ' . number_format($sp, 0) : 'On request' ?></div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- CABINS -->
                <div class="card p-0" id="cabins">
                    <div class="card-header flex-wrap gap-2">
                        <div><span class="card-header-icon">bed</span><h3>Cabins · <?= date('d M Y', strtotime($sailing['date'])) ?></h3></div>
                        <form method="GET" action="<?= htmlspecialchars(_wgc_url($cruise['key'], $cruise['name'])) ?>#cabins" class="flex items-center gap-2 text-xs" x-data @change="$el.submit()">
                            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedRef) ?>">
                            <label class="flex items-center gap-1">Adults
                                <select name="adults" class="select !py-1 !h-8 !w-16"><?php for ($i = 1; $i <= 4; $i++): ?><option <?= $i === $adults ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
                            </label>
                            <label class="flex items-center gap-1">Children
                                <select name="children" class="select !py-1 !h-8 !w-16"><?php for ($i = 0; $i <= 3; $i++): ?><option <?= $i === $children ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
                            </label>
                        </form>
                    </div>
                    <div class="card-body space-y-5">
                        <?php if (!$sailing['cabins']): ?>
                            <div class="text-center py-6 text-gray-500">
                                <span class="material-symbols-outlined text-4xl">support_agent</span>
                                <p class="font-semibold mt-1">Price on request</p>
                                <p class="text-sm">Online prices are not published for this sailing yet. Please contact us for a quote.</p>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($cabinGroups as $type => $items): ?>
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2 flex items-center gap-1">
                                <span class="material-symbols-outlined !text-base text-blue-600"><?= ['inside' => 'door_front', 'outside' => 'window', 'balcony' => 'balcony', 'suite' => 'king_bed'][$type] ?? 'bed' ?></span>
                                <?= $roomLabels[$type] ?? ucfirst($type) ?>
                            </h4>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <?php foreach ($items as $it): $cab = $it['cabin']; $q = $it['quote']; ?>
                                <div class="p-3 flex flex-col sm:flex-row sm:items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-gray-900 dark:text-white text-sm"><?= htmlspecialchars($cab['name']) ?> <span class="text-xs text-gray-400 font-normal"><?= htmlspecialchars($cab['code']) ?></span></div>
                                        <?php if ($cab['fare']): ?><div class="text-xs text-gray-500"><?= htmlspecialchars($cab['fare']) ?></div><?php endif; ?>
                                    </div>
                                    <?php if ($q): ?>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500"><?= $cur ?> <?= number_format($it['pp'], 0) ?> pp</div>
                                        <div class="text-lg font-bold text-gray-900 dark:text-white"><?= $cur ?> <?= number_format($q['subtotal'], 2) ?></div>
                                        <div class="text-[11px] text-gray-500">total for <?= $adults + $children ?> guest<?= $adults + $children > 1 ? 's' : '' ?></div>
                                    </div>
                                    <a href="<?= root ?>cruises/booking/<?= rawurlencode($selectedRef) ?>?<?= http_build_query(['cabin' => $cab['key'], 'adults' => $adults, 'children' => $children]) ?>"
                                       class="btn !px-5 whitespace-nowrap">Select</a>
                                    <?php else: ?>
                                    <div class="text-sm font-medium text-gray-500"><?= $cab['double'] > 0 ? 'Sold out' : 'Price on request' ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($sailing['cabins']): ?>
                        <p class="text-[11px] text-gray-500">Prices are per cabin for the whole party, based on cruise-line fares at the time of import. A solo traveller pays the single rate. Your booking is confirmed with the cruise line after payment.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- OVERVIEW -->
                <?php if ($cruise['description'] || $cruise['highlights'] || $cruise['included']): ?>
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">info</span><h3>Overview</h3></div></div>
                    <div class="card-body prose prose-sm max-w-none dark:prose-invert text-gray-700 dark:text-gray-300 space-y-3 [&_ul]:list-disc [&_ul]:pl-5">
                        <?= $cruise['description'] ?>
                        <?php if ($cruise['highlights']): ?><h4 class="font-semibold text-gray-900 dark:text-white">Highlights</h4><?= $cruise['highlights'] ?><?php endif; ?>
                        <?php if ($cruise['included']): ?><h4 class="font-semibold text-gray-900 dark:text-white">What's included</h4><?= $cruise['included'] ?><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- DESTINATIONS -->
                <?php if ($cruise['countries'] || $cruise['regions']): ?>
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">public</span><h3>Destinations</h3></div></div>
                    <div class="card-body flex flex-wrap gap-2">
                        <?php foreach (array_unique(array_merge($cruise['regions'], $cruise['countries'])) as $place): ?>
                        <span class="text-xs px-3 py-1 rounded-full bg-blue-50 text-blue-800 border border-blue-100"><?= htmlspecialchars($place) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- SHIP -->
                <?php if ($ship): ?>
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">directions_boat</span><h3>About the ship · <?= htmlspecialchars($ship['name']) ?></h3></div></div>
                    <div class="card-body space-y-4">
                        <?php if ($ship['teaser']): ?><div class="text-sm text-gray-700 dark:text-gray-300"><?= $ship['teaser'] ?></div><?php endif; ?>
                        <?php
                        $factList = array_filter([
                            'Launched'    => $facts['launch_year'] ?? null,
                            'Refit'       => $facts['refit_year'] ?? null,
                            'Guests'      => isset($facts['capacity']) ? number_format((float) $facts['capacity']) : null,
                            'Crew'        => isset($facts['crew_count']) ? number_format((float) $facts['crew_count']) : null,
                            'Decks'       => $facts['deck_count'] ?? null,
                            'Cabins'      => isset($facts['cabin_count']) ? number_format((float) $facts['cabin_count']) : null,
                            'Tonnage'     => isset($facts['gross_tonnage']) ? number_format((float) $facts['gross_tonnage']) : null,
                            'Class'       => $ship['class'] ?: null,
                        ]);
                        if ($factList): ?>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <?php foreach ($factList as $k => $v): ?>
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 p-2 text-center">
                                <div class="text-[11px] uppercase text-gray-500"><?= $k ?></div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars((string) $v) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($ship['dining'])): ?>
                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-1">Dining</div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($ship['dining'] as $d): ?><span class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700"><?= htmlspecialchars((string) $d) ?></span><?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- OPERATOR -->
                <?php if (!empty($cruise['operator']['about'])): ?>
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">sailing</span><h3>About <?= htmlspecialchars($cruise['operator_name']) ?></h3></div></div>
                    <div class="card-body text-sm text-gray-700 dark:text-gray-300 space-y-2"><?= $cruise['operator']['about'] ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- SUMMARY -->
            <aside class="lg:col-span-1">
                <div class="card p-0 lg:sticky lg:top-4">
                    <div class="card-header"><div><span class="card-header-icon">sailing</span><h3>Your sailing</h3></div></div>
                    <div class="card-body space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Departs</span><span class="font-semibold"><?= date('D, d M Y', strtotime($sailing['date'])) ?></span></div>
                        <?php if ($sailing['return']): ?><div class="flex justify-between"><span class="text-gray-500">Returns</span><span class="font-semibold"><?= date('D, d M Y', strtotime($sailing['return'])) ?></span></div><?php endif; ?>
                        <div class="flex justify-between"><span class="text-gray-500">Ship</span><span class="font-semibold"><?= htmlspecialchars($sailing['ship']) ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">From</span><span class="font-semibold text-right"><?= htmlspecialchars($sailing['embark']) ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">To</span><span class="font-semibold text-right"><?= htmlspecialchars($sailing['disembark'] ?: $sailing['embark']) ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Guests</span><span class="font-semibold"><?= $adults ?> adult<?= $adults > 1 ? 's' : '' ?><?= $children ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?></span></div>
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <?php if ($leadPp > 0): ?>
                                <div class="text-xs text-gray-500">Cabins from</div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-white"><?= $cur ?> <?= number_format($leadPp, 0) ?> <span class="text-xs font-normal text-gray-500">pp</span></div>
                                <a href="#cabins" class="btn w-full mt-3 justify-center">Choose a cabin</a>
                            <?php else: ?>
                                <div class="font-semibold text-gray-700">Price on request</div>
                                <p class="text-xs text-gray-500 mt-1">Contact us and we will quote this sailing for you.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>
