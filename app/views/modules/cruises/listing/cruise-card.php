<?php
// ONE CRUISE RESULT CARD — expects $c (a group from _wgc_search) and $cardQuery (adults/children)
@$SECURE or die('Access Denied!');

$cardCur   = _wgc_currency($db);
$cardLead  = $c['lead'] ? _wgc_price($db, $c['lead'], $c['currency'], $cardCur) : 0;
$cardUrl   = _wgc_url($c['key'], $c['name'], $cardQuery ?? []);
$cardDates = array_slice($c['sailings'], 0, 5);
$cardMore  = count($c['sailings']) - count($cardDates);
$cardType  = str_contains((string) $c['holiday_type'], 'tour') ? 'Cruise & Tour' : (str_contains((string) $c['holiday_type'], 'river') ? 'River Cruise' : 'Cruise');
?>
<div class="card p-0 overflow-hidden flex flex-col md:flex-row hover:shadow-lg transition-shadow">
    <a href="<?= htmlspecialchars($cardUrl) ?>" class="relative md:w-72 h-48 md:h-auto flex-shrink-0 bg-slate-100 block">
        <img src="<?= htmlspecialchars($c['image']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" loading="lazy"
             class="absolute inset-0 w-full h-full object-cover" onerror="this.src='<?= root ?>uploads/no_img.jpg'">
        <span class="absolute top-3 left-3 bg-white/90 text-slate-800 text-[11px] font-semibold px-2 py-1 rounded-full"><?= $cardType ?></span>
    </a>

    <div class="flex-1 p-4 flex flex-col gap-2 min-w-0">
        <div class="text-xs text-slate-500 flex items-center gap-1 flex-wrap">
            <span class="material-symbols-outlined !text-sm">directions_boat</span>
            <span class="font-medium text-slate-700"><?= htmlspecialchars($c['operator_name']) ?></span>
            <?php if ($c['ships']): ?><span>·</span><span><?= htmlspecialchars(implode(', ', array_slice($c['ships'], 0, 2))) ?></span><?php endif; ?>
        </div>
        <a href="<?= htmlspecialchars($cardUrl) ?>" class="text-lg font-bold text-slate-900 dark:text-white leading-snug hover:text-blue-700 line-clamp-2"><?= htmlspecialchars($c['name']) ?></a>

        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
            <span class="flex items-center gap-1"><span class="material-symbols-outlined !text-base">nights_stay</span><?= (int) $c['nights'] ?> nights</span>
            <span class="flex items-center gap-1 min-w-0"><span class="material-symbols-outlined !text-base">route</span>
                <span class="truncate"><?= htmlspecialchars($c['embark']) ?><?= $c['disembark'] && $c['disembark'] !== $c['embark'] ? ' → ' . htmlspecialchars($c['disembark']) : ' (round trip)' ?></span>
            </span>
        </div>
        <?php if ($c['countries']): ?>
        <div class="text-xs text-slate-500 flex items-center gap-1"><span class="material-symbols-outlined !text-sm">public</span><span class="truncate"><?= htmlspecialchars($c['countries']) ?></span></div>
        <?php endif; ?>

        <div class="mt-auto pt-2">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-1"><?= count($c['sailings']) ?> sailing<?= count($c['sailings']) > 1 ? 's' : '' ?></div>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($cardDates as $s): ?>
                <a href="<?= htmlspecialchars(_wgc_url($c['key'], $c['name'], ($cardQuery ?? []) + ['date' => $s['date_ref']])) ?>"
                   class="text-xs px-2 py-1 rounded-md border border-slate-200 hover:border-blue-500 hover:text-blue-700 bg-white dark:bg-slate-800"><?= date('d M Y', strtotime($s['date'])) ?></a>
                <?php endforeach; ?>
                <?php if ($cardMore > 0): ?><a href="<?= htmlspecialchars($cardUrl) ?>" class="text-xs px-2 py-1 text-blue-700">+<?= $cardMore ?> more</a><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="md:w-52 p-4 border-t md:border-t-0 md:border-l border-slate-100 dark:border-slate-700 flex md:flex-col justify-between md:justify-center items-center md:items-end gap-2 text-right">
        <?php if ($cardLead > 0): ?>
        <div>
            <div class="text-xs text-slate-500">from</div>
            <div class="text-2xl font-bold text-slate-900 dark:text-white"><?= $cardCur ?> <?= number_format($cardLead, 0) ?></div>
            <div class="text-[11px] text-slate-500">per person</div>
        </div>
        <?php else: ?>
        <div class="text-sm font-semibold text-slate-600">Price on request</div>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($cardUrl) ?>" class="btn !px-4 whitespace-nowrap">View cruise</a>
    </div>
</div>
