<?php
// COMPONENT LIBRARY — FEATURED
// Live examples of a featured section: heading, row of cards, buttons, card parts.
// Classes are defined in app/views/includes/featured/styles.php.
@$SECURE or die('Access Denied!');

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';
require views . 'includes/featured/script.php';

$fcArrow   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$fcChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';

$fcCards = [
    ['flag' => 'pk', 'name' => 'Pakistan',   'net' => '4G', 'level' => 3, 'chips' => ['1 GB – Unlimited', '3–30 days'], 'price' => '1.80'],
    ['flag' => 'tr', 'name' => 'Turkey',     'net' => '5G', 'level' => 4, 'chips' => ['1 GB – 20 GB', '7–30 days'],     'price' => '3.12'],
    ['flag' => 'ae', 'name' => 'United Arab Emirates', 'net' => '5G', 'level' => 4, 'chips' => ['1 GB – Unlimited', '3–30 days'], 'price' => '2.64'],
    ['flag' => 'my', 'name' => 'Malaysia',   'net' => '5G', 'level' => 4, 'chips' => ['1 GB – Unlimited', '3–30 days'], 'price' => '1.68'],
    ['flag' => 'sa', 'name' => 'Saudi Arabia', 'net' => '5G', 'level' => 4, 'chips' => ['1 GB – 10 GB', '7–30 days'],   'price' => '4.50'],
    ['flag' => 'aq', 'name' => 'Antarctica', 'net' => '',   'level' => 0, 'chips' => [],                                'price' => ''],
];

$fcFiles = [
    'app/views/includes/featured/styles.php' => 'All classes of the section and the card (.fs-*, .fc-*).',
    'app/views/includes/featured/modal.php'  => 'The window over the page (.fm-*): a list to choose from.',
    'app/views/includes/featured/script.php' => 'featuredTrack(): move the row, know its start and end.',
    'app/views/modules/esim/featured.php'    => 'Featured eSIM countries on the home page, built from these parts.',
    'app/routes/api/esim/featuredRoutes.php' => 'The facts of each card: network, data, days, lowest price.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Featured Components</h1>
               <p class="text-gray-600 mb-6">A featured section of the home page: a heading with an icon and a link, a row of cards, and two buttons to move it. Whole cards only: four are in view on a desktop, three on a tablet, two on a phone; the buttons bring the next ones.</p>

               <div class="space-y-8">

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Section <code class="text-sm font-normal text-blue-700">.fs + .fs-head + .fs-track + .fs-nav</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Point at a card: its border takes the theme colour, the button fills and the signal bars fill one after another.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa" x-data="{ ...featuredTrack() }" x-init="$nextTick(() => sync())">
                        <div class="fs-head">
                           <h2 class="fs-title">
                              <span class="fs-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7.200a2 2 0 0 1 1.400.600l3.800 3.800a2 2 0 0 1 .600 1.400V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><rect x="8.500" y="11.500" width="7" height="6" rx="1.200"/><path d="M12 11.500v6M8.500 14.500h7"/></svg></span>
                              <span><?= htmlspecialchars((string) T::esim_featured_title) ?></span>
                           </h2>
                           <a class="fs-more" href="#" onclick="return false"><span><?= htmlspecialchars(str_replace('{n}', '190', (string) T::esim_browse_destinations)) ?></span><?= $fcArrow ?></a>
                        </div>
                        <div class="fs-track" x-ref="track" @scroll.passive="sync()">
                           <?php foreach ($fcCards as $n => $card): ?>
                           <a class="fc" href="#" onclick="return false" style="--n:<?= $n ?>">
                              <div class="fc-top">
                                 <span class="fc-tile"><img src="<?= root ?>assets/img/flags/<?= $card['flag'] ?>.svg" alt=""></span>
                                 <?php if ($card['level']): ?>
                                 <span class="fc-signal" data-level="<?= $card['level'] ?>"><span><?= $card['net'] ?></span><span class="fc-bars"><i></i><i></i><i></i><i></i></span></span>
                                 <?php endif; ?>
                              </div>
                              <h3 class="fc-title"><?= htmlspecialchars($card['name']) ?></h3>
                              <div class="fc-chips">
                                 <?php foreach ($card['chips'] as $chip): ?><span class="fc-chip"><?= htmlspecialchars($chip) ?></span><?php endforeach; ?>
                              </div>
                              <div class="fc-foot">
                                 <div>
                                    <?php if ($card['price'] !== ''): ?>
                                    <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                                    <span class="fc-price"><small>USD</small><b><?= $card['price'] ?></b></span>
                                    <?php endif; ?>
                                 </div>
                                 <button type="button" class="btn" tabindex="-1"><?= htmlspecialchars((string) T::choose_plan) ?><?= $fcChevron ?></button>
                              </div>
                           </a>
                           <?php endforeach; ?>
                        </div>
                        <div class="fs-nav">
                           <button type="button" class="fs-nav-btn" @click="move(-1)" :disabled="atStart" aria-label="<?= htmlspecialchars((string) T::previous) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></button>
                           <button type="button" class="fs-nav-btn" @click="move(1)" :disabled="atEnd" aria-label="<?= htmlspecialchars((string) T::next) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<?php\nrequire views . 'includes/featured/styles.php';\nrequire views . 'includes/featured/script.php';\n?>\n<section class=\"fs\" x-data=\"{ ...featuredTrack() }\">\n  <div class=\"container\">\n    <div class=\"fs-head\">\n      <h2 class=\"fs-title\"><span class=\"fs-title-icon\"><svg>...</svg></span><span>Title</span></h2>\n      <a class=\"fs-more\" href=\"...\"><span>See all</span><svg>...</svg></a>\n    </div>\n    <div class=\"fs-track\" x-ref=\"track\" @scroll.passive=\"sync()\">\n      <a class=\"fc\" href=\"...\">...</a>\n    </div>\n    <div class=\"fs-nav\">\n      <button type=\"button\" class=\"fs-nav-btn\" @click=\"move(-1)\" :disabled=\"atStart\">...</button>\n      <button type=\"button\" class=\"fs-nav-btn\" @click=\"move(1)\" :disabled=\"atEnd\">...</button>\n    </div>\n  </div>\n</section>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Card <code class="text-sm font-normal text-blue-700">.fc</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Top row with a tinted tile, the name, small facts, and a bottom row with the price and the button. The button is the design-system <code>.btn</code>; the card only places it. Leave out what a card does not have: the rows keep their place, so all cards stay the same height.</p>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<a class=\"fc\" href=\"...\">\n  <div class=\"fc-top\">\n    <span class=\"fc-tile\"><img src=\"flag.svg\" alt=\"\"></span>\n    <span class=\"fc-signal\" data-level=\"4\">\n      <span>5G</span>\n      <span class=\"fc-bars\"><i></i><i></i><i></i><i></i></span>\n    </span>\n  </div>\n  <h3 class=\"fc-title\">Malaysia</h3>\n  <div class=\"fc-chips\">\n    <span class=\"fc-chip\">1 GB – Unlimited</span>\n    <span class=\"fc-chip\">3–30 days</span>\n  </div>\n  <div class=\"fc-foot\">\n    <div>\n      <span class=\"fc-from\">From</span>\n      <span class=\"fc-price\"><small>USD</small><b>1.68</b></span>\n    </div>\n    <button type=\"button\" class=\"btn\">Choose plan <svg class=\"fc-btn-icon\">...</svg></button>\n  </div>\n</a>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Modal <code class="text-sm font-normal text-blue-700">.fm + .fm-box + .fm-row</code></h3>
                     <p class="text-sm text-gray-600 mb-3">A window over the page with a list to choose from. It comes in with a spring; on phones it rises from the foot of the screen as a sheet. Add <code>is-open</code> to show it, and put it in <code>&lt;body&gt;</code> with <code>x-teleport</code> so nothing of the page can clip it.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa" x-data="{ open: false, kind: 'all' }" @keydown.escape.window="open = false">
                        <button type="button" class="btn" @click="open = true"><?= htmlspecialchars((string) T::choose_plan) ?></button>
                        <template x-teleport="body">
                           <div class="fm" :class="{ 'is-open': open }" @click.self="open = false" role="dialog" aria-modal="true">
                              <div class="fm-box">
                                 <div class="fm-head">
                                    <span class="fc-tile"><img src="<?= root ?>assets/img/flags/my.svg" alt=""></span>
                                    <div style="min-width:0">
                                       <h3 class="fm-title">Malaysia</h3>
                                       <div class="fm-sub"><span><?= htmlspecialchars(str_replace('{n}', '4', (string) T::esim_plans_count)) ?></span><span class="fc-signal" data-level="4"><span>5G</span><span class="fc-bars"><i></i><i></i><i></i><i></i></span></span></div>
                                    </div>
                                    <button type="button" class="fm-close" @click="open = false" aria-label="<?= htmlspecialchars((string) T::close) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                                 </div>
                                 <div class="fm-tools">
                                    <div class="segmented">
                                       <button type="button" class="segmented-item" :class="kind === 'all' ? 'is-active' : ''" @click="kind = 'all'"><?= htmlspecialchars((string) T::all) ?></button>
                                       <button type="button" class="segmented-item" :class="kind === 'local' ? 'is-active' : ''" @click="kind = 'local'"><?= htmlspecialchars((string) T::esim_local) ?></button>
                                       <button type="button" class="segmented-item" :class="kind === 'global' ? 'is-active' : ''" @click="kind = 'global'"><?= htmlspecialchars((string) T::esim_global) ?></button>
                                    </div>
                                 </div>
                                 <div class="fm-body">
                                    <?php foreach ([['1 GB', '7 days', '1.68'], ['3 GB', '15 days', '4.20'], ['5 GB', '30 days', '6.90'], ['Unlimited', '30 days', '24.00']] as $n => $row): ?>
                                    <a class="fm-row" href="#" onclick="return false" style="--n:<?= $n ?>">
                                       <div class="fm-row-main">
                                          <div class="fm-row-title"><b><?= $row[0] ?></b><span><?= $row[1] ?></span></div>
                                          <div class="fm-row-facts"><span class="fc-chip">Operator</span></div>
                                       </div>
                                       <div class="fm-row-price"><span class="fc-price"><small>USD</small><b><?= $row[2] ?></b></span></div>
                                       <span class="btn"><?= htmlspecialchars((string) T::select) ?><?= $fcChevron ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                 </div>
                                 <div class="fm-foot"><span></span><a href="#" onclick="return false"><?= htmlspecialchars((string) T::esim_open_country) ?></a></div>
                              </div>
                           </div>
                        </template>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<?php require views . 'includes/featured/modal.php'; ?>\n<template x-teleport=\"body\">\n  <div class=\"fm\" :class=\"{ 'is-open': open }\" @click.self=\"open = false\" role=\"dialog\" aria-modal=\"true\">\n    <div class=\"fm-box\">\n      <div class=\"fm-head\">\n        <span class=\"fc-tile\"><img src=\"flag.svg\" alt=\"\"></span>\n        <div><h3 class=\"fm-title\">Malaysia</h3><div class=\"fm-sub\">4 plans</div></div>\n        <button type=\"button\" class=\"fm-close\" @click=\"open = false\">...</button>\n      </div>\n      <div class=\"fm-tools\"><div class=\"segmented\">...</div></div>\n      <div class=\"fm-body\">\n        <a class=\"fm-row\" href=\"...\">\n          <div class=\"fm-row-main\">\n            <div class=\"fm-row-title\"><b>1 GB</b><span>7 days</span></div>\n            <div class=\"fm-row-facts\"><span class=\"fc-chip\">Operator</span></div>\n          </div>\n          <div class=\"fm-row-price\"><span class=\"fc-price\"><small>USD</small><b>1.68</b></span></div>\n          <span class=\"btn\">Select</span>\n        </a>\n      </div>\n      <div class=\"fm-foot\">...</div>\n    </div>\n  </div>\n</template>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Flight card <code class="text-sm font-normal text-blue-700">.fc.fc-flight</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The card of a featured flight: the airline with its logo in a round tile (a plane when there is no logo), the flight number and the cabin; the times and cities with the length of the flight on the line between them; the price and the <code>.btn</code>. Used by <code>app/views/modules/flights/featured.php</code>.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-track" style="overflow:hidden">
                        <a class="fc fc-flight" href="#" onclick="return false" style="width:calc((100% - 32px) / 3)">
                           <div class="fc-top" style="align-items:center">
                              <div class="fc-carrier">
                                 <span class="fc-tile"><img src="<?= root ?>uploads/flights/airlines/EK.png" alt=""></span>
                                 <div style="min-width:0"><p class="fc-airline">Emirates</p><p class="fc-flight-no">EK003</p></div>
                              </div>
                              <span class="fc-cabin">Economy</span>
                           </div>
                           <div class="fc-route">
                              <div><p class="fc-time">07:30</p><p class="fc-place">Dubai</p></div>
                              <div class="fc-leg">
                                 <span class="fc-leg-length">7h 35m</span>
                                 <span class="fc-leg-line" aria-hidden="true"><i></i><b></b><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg></span>
                                 <span class="fc-leg-stops">Direct</span>
                              </div>
                              <div><p class="fc-time">12:05</p><p class="fc-place">London</p></div>
                           </div>
                           <div class="fc-foot">
                              <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>669.50</b></span></div>
                              <span class="btn" tabindex="-1">Search Now<svg class="fc-btn-icon" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.48 15.51l2.83 2.82M17.49 10.62a6.93 6.93 0 1 1-13.85 0 6.93 6.93 0 0 1 13.85 0Z"/></svg></span>
                           </div>
                        </a>
                        <a class="fc fc-flight" href="#" onclick="return false" style="width:calc((100% - 32px) / 3)">
                           <div class="fc-top" style="align-items:center">
                              <div class="fc-carrier">
                                 <span class="fc-tile"><img src="<?= root ?>uploads/flights/airlines/TK.png" alt=""></span>
                                 <div style="min-width:0"><p class="fc-airline">Turkish Airlines</p><p class="fc-flight-no">TK1823</p></div>
                              </div>
                              <span class="fc-cabin">Business</span>
                           </div>
                           <div class="fc-route">
                              <div><p class="fc-time">08:25</p><p class="fc-place">Istanbul</p></div>
                              <div class="fc-leg">
                                 <span class="fc-leg-length">3h 40m</span>
                                 <span class="fc-leg-line" aria-hidden="true"><i></i><b></b><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg></span>
                                 <span class="fc-leg-stops">1 Stop</span>
                              </div>
                              <div><p class="fc-time">11:05</p><p class="fc-place">Paris</p></div>
                           </div>
                           <div class="fc-foot">
                              <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>1,240.00</b></span></div>
                              <span class="btn" tabindex="-1">Search Now<svg class="fc-btn-icon" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.48 15.51l2.83 2.82M17.49 10.62a6.93 6.93 0 1 1-13.85 0 6.93 6.93 0 0 1 13.85 0Z"/></svg></span>
                           </div>
                        </a>
                        <a class="fc fc-flight" href="#" onclick="return false" style="width:calc((100% - 32px) / 3)">
                           <div class="fc-top" style="align-items:center">
                              <div class="fc-carrier">
                                 <span class="fc-tile"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg></span>
                                 <div style="min-width:0"><p class="fc-airline">Air Blue</p><p class="fc-flight-no">PA200</p></div>
                              </div>
                              <span class="fc-cabin">Economy</span>
                           </div>
                           <div class="fc-route">
                              <div><p class="fc-time">21:00</p><p class="fc-place">Lahore</p></div>
                              <div class="fc-leg">
                                 <span class="fc-leg-length">4h</span>
                                 <span class="fc-leg-line" aria-hidden="true"><i></i><b></b><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg></span>
                                 <span class="fc-leg-stops">Direct</span>
                              </div>
                              <div><p class="fc-time">01:00</p><p class="fc-place">Dubai</p></div>
                           </div>
                           <div class="fc-foot">
                              <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>290.00</b></span></div>
                              <span class="btn" tabindex="-1">Search Now<svg class="fc-btn-icon" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.48 15.51l2.83 2.82M17.49 10.62a6.93 6.93 0 1 1-13.85 0 6.93 6.93 0 0 1 13.85 0Z"/></svg></span>
                           </div>
                        </a>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<a class=\"fc fc-flight\" href=\"...\">\n  <div class=\"fc-top\">\n    <div class=\"fc-carrier\">\n      <span class=\"fc-tile\"><img src=\"EK.png\" alt=\"\"></span>\n      <div><p class=\"fc-airline\">Emirates</p><p class=\"fc-flight-no\">EK003</p></div>\n    </div>\n    <span class=\"fc-cabin\">Economy</span>\n  </div>\n  <div class=\"fc-route\">\n    <div><p class=\"fc-time\">07:30</p><p class=\"fc-place\">Dubai</p></div>\n    <div class=\"fc-leg\">\n      <span class=\"fc-leg-length\">7h 35m</span>\n      <span class=\"fc-leg-line\"><i></i><b></b><svg>plane</svg></span>\n      <span class=\"fc-leg-stops\">Direct</span>\n    </div>\n    <div><p class=\"fc-time\">12:05</p><p class=\"fc-place\">London</p></div>\n  </div>\n  <div class=\"fc-foot\">\n    <div><span class=\"fc-from\">From</span><span class=\"fc-price\"><small>USD</small><b>669.50</b></span></div>\n    <span class=\"btn\">Search Now</span>\n  </div>\n</a>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Poster card <code class="text-sm font-normal text-blue-700">.fc.fc-poster</code></h3>
                     <p class="text-sm text-gray-600 mb-3">A tall card whose words sit on the picture: the place and the rating at the top, the name, the days and the type, the price and a round arrow at the foot. The picture grows a little on hover. Above the row, <code>.fs-filters</code> holds <code>.fm-pick</code> chips that narrow the row to one place. Used by <code>app/views/modules/tours/featured.php</code>.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-filters"><button type="button" class="fm-pick is-active"><span>All</span><i>30</i></button><button type="button" class="fm-pick"><span>Dubai</span><i>10</i></button><button type="button" class="fm-pick"><span>Istanbul</span><i>10</i></button><button type="button" class="fm-pick"><span>Bali</span><i>10</i></button></div>
                        <div class="fs-track" style="overflow:hidden">
                           <a class="fc fc-poster" href="#" onclick="return false" style="--n:0;width:calc((100% - 32px) / 3)">
                              <img src="<?= root ?>uploads/tours/gallery/tour_seed_100_0.jpg" alt="">
                              <div class="fc-poster-top"><span class="fc-poster-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Dubai</span></span><span class="fc-poster-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.4</span></span></div>
                              <span class="fc-poster-sale">-10% OFF</span>
                              <h3 class="fc-title">Dubai: Skip-The-Line Ticket to Dubai Miracle Garden</h3>
                              <div class="fc-poster-facts"><span>2 Days / 1 Night</span><i></i><span>Sightseeing</span></div>
                              <div class="fc-poster-foot">
                                 <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>144.20</b></span></div>
                                 <span class="fc-poster-go"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                              </div>
                           </a>
                           <a class="fc fc-poster" href="#" onclick="return false" style="--n:1;width:calc((100% - 32px) / 3)">
                              <img src="<?= root ?>uploads/tours/gallery/tour_seed_101_0.jpg" alt="">
                              <div class="fc-poster-top"><span class="fc-poster-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Dubai</span></span><span class="fc-poster-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.8</span></span></div>
                              
                              <h3 class="fc-title">Private Dubai Airport Transfer DXB to Dubai City</h3>
                              <div class="fc-poster-facts"><span>4 Days / 3 Nights</span></div>
                              <div class="fc-poster-foot">
                                 <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>270.60</b></span></div>
                                 <span class="fc-poster-go"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                              </div>
                           </a>
                           <a class="fc fc-poster" href="#" onclick="return false" style="--n:2;width:calc((100% - 32px) / 3)">
                              <img src="<?= root ?>uploads/tours/gallery/tour_seed_102_0.jpg" alt="">
                              <div class="fc-poster-top"><span class="fc-poster-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Dubai</span></span><span class="fc-poster-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.6</span></span></div>
                              
                              <h3 class="fc-title">Dubai Frame</h3>
                              <div class="fc-poster-facts"><span>2 Days / 1 Night</span><i></i><span>Landmark</span></div>
                              <div class="fc-poster-foot">
                                 <div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>432.60</b></span></div>
                                 <span class="fc-poster-go"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                              </div>
                           </a>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<a class=\"fc fc-poster\" href=\"...\">\n  <img src=\"picture.jpg\" alt=\"\">\n  <div class=\"fc-poster-top\">\n    <span class=\"fc-poster-chip\"><svg>pin</svg><span>Dubai</span></span>\n    <span class=\"fc-poster-rate\"><svg>star</svg><span>4.4</span></span>\n  </div>\n  <span class=\"fc-poster-sale\">-10% OFF</span>\n  <h3 class=\"fc-title\">Name</h3>\n  <div class=\"fc-poster-facts\"><span>2 Days / 1 Night</span><i></i><span>Sightseeing</span></div>\n  <div class=\"fc-poster-foot\">\n    <div><span class=\"fc-from\">From</span><span class=\"fc-price\"><small>USD</small><b>144.20</b></span></div>\n    <span class=\"fc-poster-go\"><svg>arrow</svg></span>\n  </div>\n</a>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Stay card <code class="text-sm font-normal text-blue-700">.fc.fc-stay</code></h3>
                     <p class="text-sm text-gray-600 mb-3">A framed picture with the discount, the guest score and the stars on it; under it the name, the place with a pin, up to three amenities; at the foot the price of a night and the <code>.btn</code>. Used by <code>app/views/modules/stays/featured.php</code>.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-track" style="overflow:hidden">
                           <a class="fc fc-stay" href="#" onclick="return false" style="--n:0;width:calc((100% - 32px) / 3)">
                              <div class="fc-stay-photo"><img src="<?= root ?>uploads/hotels/gallery/atlantis-the-palm-1.jpg" alt=""><span class="fc-stay-sale">-15% OFF</span><span class="fc-stay-score"><b>4.8</b><span>Excellent</span></span><span class="fc-stay-stars"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg></span></div>
                              <div class="fc-body"><h3 class="fc-title">Atlantis The Palm</h3><p class="fc-stay-place"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Palm Jumeirah, Dubai</span></p><div class="fc-chips"><span class="fc-chip">Free WiFi</span><span class="fc-chip">Pool</span><span class="fc-chip">Spa</span></div></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>1,545.00</b></span><span class="fc-night">per night</span></div><span class="btn">View Details<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </a>
                           <a class="fc fc-stay" href="#" onclick="return false" style="--n:1;width:calc((100% - 32px) / 3)">
                              <div class="fc-stay-photo"><img src="<?= root ?>uploads/hotels/gallery/address-downtown-dubai-1.jpg" alt=""><span class="fc-stay-score"><b>4.6</b><span>Excellent</span></span><span class="fc-stay-stars"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg></span></div>
                              <div class="fc-body"><h3 class="fc-title">Address Downtown Dubai</h3><p class="fc-stay-place"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Sheikh Mohammed Bin Rashed Boulevard</span></p><div class="fc-chips"><span class="fc-chip">Breakfast</span><span class="fc-chip">Gym</span></div></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>890.00</b></span><span class="fc-night">per night</span></div><span class="btn">View Details<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </a>
                           <a class="fc fc-stay" href="#" onclick="return false" style="--n:2;width:calc((100% - 32px) / 3)">
                              <div class="fc-stay-photo"><img src="<?= root ?>uploads/hotels/gallery/jw-marriott-marquis-dubai-1.jpg" alt=""><span class="fc-stay-sale">-10% OFF</span><span class="fc-stay-score"><b>4.1</b><span>Very good</span></span><span class="fc-stay-stars"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg></span></div>
                              <div class="fc-body"><h3 class="fc-title">JW Marriott Marquis Dubai</h3><p class="fc-stay-place"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Sheikh Zayed Road, Business Bay</span></p><div class="fc-chips"><span class="fc-chip">Free WiFi</span><span class="fc-chip">Parking</span><span class="fc-chip">Pool</span></div></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>420.00</b></span><span class="fc-night">per night</span></div><span class="btn">View Details<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </a>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<a class=\"fc fc-stay\" href=\"...\">
  <div class=\"fc-stay-photo\">
    <img src=\"picture.jpg\" alt=\"\">
    <span class=\"fc-stay-sale\">-15% OFF</span>
    <span class=\"fc-stay-score\"><b>4.8</b><span>Excellent</span></span>
    <span class=\"fc-stay-stars\"><svg>star</svg> x 5</span>
  </div>
  <div class=\"fc-body\">
    <h3 class=\"fc-title\">Name</h3>
    <p class=\"fc-stay-place\"><svg>pin</svg><span>Palm Jumeirah, Dubai</span></p>
    <div class=\"fc-chips\"><span class=\"fc-chip\">Free WiFi</span></div>
  </div>
  <div class=\"fc-foot\">
    <div><span class=\"fc-from\">From</span><span class=\"fc-price\"><small>USD</small><b>1,545.00</b></span><span class=\"fc-night\">per night</span></div>
    <span class=\"btn\">View Details</span>
  </div>
</a>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Car card <code class="text-sm font-normal text-blue-700">.fc.fc-car</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The car on a soft light with its type and rating; the name, make and place; four facts with icons (seats, gearbox, bags, fuel); the price a day and the <code>.btn</code>. The car slides forward a little on hover. Used by <code>app/views/modules/cars/featured.php</code>, where a card opens the booking window.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-track" style="overflow:hidden">
                           <article class="fc fc-car" role="button" tabindex="0" style="--n:0;width:calc((100% - 32px) / 3)">
                              <div class="fc-car-photo"><img src="<?= root ?>uploads/cars/car_ct_5.jpg" alt=""><span class="fc-car-kind">Compact</span><span class="fc-car-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.6</span></span></div>
                              <div class="fc-body"><h3 class="fc-title">Mazda 6 or similar</h3><p class="fc-car-sub">Mazda 6 · 2024 · Dubai</p><ul class="fc-car-specs"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span>5 Seats</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><path d="M6 8v8M6 12h12V8"/></svg><span>Automatic</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg><span>5 Bags</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 21h14M15 9h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0v-7l-3-3M8 7h4"/></svg><span>Petrol</span></li></ul></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>232.66</b><span class="fc-night">/ Day</span></span></div><span class="btn">Book Now<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </article>
                           <article class="fc fc-car" role="button" tabindex="0" style="--n:1;width:calc((100% - 32px) / 3)">
                              <div class="fc-car-photo"><img src="<?= root ?>uploads/cars/car_ct_2.jpg" alt=""><span class="fc-car-kind">SUV</span><span class="fc-car-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.8</span></span></div>
                              <div class="fc-body"><h3 class="fc-title">Toyota Land Cruiser</h3><p class="fc-car-sub">Toyota · 2024 · Dubai</p><ul class="fc-car-specs"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span>7 Seats</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><path d="M6 8v8M6 12h12V8"/></svg><span>Automatic</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg><span>4 Bags</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 21h14M15 9h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0v-7l-3-3M8 7h4"/></svg><span>Petrol</span></li></ul></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>410.00</b><span class="fc-night">/ Day</span></span></div><span class="btn">Book Now<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </article>
                           <article class="fc fc-car" role="button" tabindex="0" style="--n:2;width:calc((100% - 32px) / 3)">
                              <div class="fc-car-photo"><img src="<?= root ?>uploads/cars/car_ct_3.jpg" alt=""><span class="fc-car-kind">Transfer</span><span class="fc-car-rate"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg><span>4.9</span></span></div>
                              <div class="fc-body"><h3 class="fc-title">Airport Transfer · Sedan</h3><p class="fc-car-sub">Mercedes E-Class · Dubai</p><ul class="fc-car-specs"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span>3 Seats</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><path d="M6 8v8M6 12h12V8"/></svg><span>Automatic</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg><span>3 Bags</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 21h14M15 9h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0v-7l-3-3M8 7h4"/></svg><span>Hybrid</span></li></ul></div>
                              <div class="fc-foot"><div><span class="fc-from">From</span><span class="fc-price"><small>USD</small><b>85.00</b><span class="fc-night">Transfer</span></span></div><span class="btn">Book Now<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span></div>
                           </article>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<article class=\"fc fc-car\" role=\"button\">
  <div class=\"fc-car-photo\">
    <img src=\"car.png\" alt=\"\">
    <span class=\"fc-car-kind\">Compact</span>
    <span class=\"fc-car-rate\"><svg>star</svg><span>4.6</span></span>
  </div>
  <div class=\"fc-body\">
    <h3 class=\"fc-title\">Mazda 6 or similar</h3>
    <p class=\"fc-car-sub\">Mazda 6 · 2024 · Dubai</p>
    <ul class=\"fc-car-specs\"><li><svg>seat</svg><span>5 Seats</span></li>...</ul>
  </div>
  <div class=\"fc-foot\">
    <div><span class=\"fc-from\">From</span><span class=\"fc-price\"><small>USD</small><b>232.66</b></span><span class=\"fc-night\">/ Day</span></div>
    <button class=\"btn\">Book Now</button>
  </div>
</article>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Post card <code class="text-sm font-normal text-blue-700">.fc.fc-post + .fs-grid-2</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The picture on one side with the category on it; on the other the date and the reading time, the title, a line of the text and "Read more". Two to a row in <code>.fs-grid-2</code>, one on a phone with the picture on top. Used by <code>app/views/modules/blogs/featured.php</code>.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-grid-2">
                           <a class="fc fc-post" href="#" onclick="return false" style="--n:0">
                              <div class="fc-post-photo"><img src="<?= root ?>uploads/blog/sustainable-travel-reduce-carbon-footprint.jpg" alt=""><span class="fc-chip">Travel Tips</span></div>
                              <div class="fc-post-body"><div class="fc-post-kicker"><span>Feb 17, 2026</span><i></i><span>2 min read</span></div><h3 class="fc-title">Sustainable Travel: How to Reduce Your Carbon Footprint</h3><p class="fc-post-text">Travel broadens minds and connects cultures, but it also carries an environmental cost. The good news is that with thoughtful choices…</p><span class="fc-post-more">Read More<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span></div>
                           </a>
                           <a class="fc fc-post" href="#" onclick="return false" style="--n:1">
                              <div class="fc-post-photo"><img src="<?= root ?>uploads/blog/food-lovers-guide-istanbul.jpg" alt=""><span class="fc-chip">Food &amp; Dining</span></div>
                              <div class="fc-post-body"><div class="fc-post-kicker"><span>Feb 7, 2026</span><i></i><span>2 min read</span></div><h3 class="fc-title">A Food Lover Guide to Istanbul</h3><p class="fc-post-text">Istanbul is where East meets West — and nowhere is this more delicious than in its food. From dawn-to-dusk street snacks…</p><span class="fc-post-more">Read More<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span></div>
                           </a>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"fs-grid-2\">
  <a class=\"fc fc-post\" href=\"...\">
    <div class=\"fc-post-photo\"><img src=\"picture.jpg\" alt=\"\"><span class=\"fc-chip\">Travel Tips</span></div>
    <div class=\"fc-post-body\">
      <div class=\"fc-post-kicker\"><span>Feb 17, 2026</span><i></i><span>2 min read</span></div>
      <h3 class=\"fc-title\">Title</h3>
      <p class=\"fc-post-text\">A line of the text</p>
      <span class=\"fc-post-more\">Read More <svg>arrow</svg></span>
    </div>
  </a>
</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Spotlight <code class="text-sm font-normal text-blue-700">.fp + .fp-main + .fp-list</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The newest item large on one side: its picture with a dark foot, a chip, the name, a line of text and the facts. The next ones as a list on the other side: picture, chip, name, facts. One column under 1024px. Used by <code>app/views/modules/umrah/featured.php</code>.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fp">
                           <a class="fp-main" href="#" onclick="return false" style="min-height:380px">
                              <img src="<?= root ?>uploads/umrah/gallery/umrah_1780217199_c51f3b0e.jpg" alt="">
                              <span class="fp-chip">Standard</span>
                              <h3 class="fp-title">Spiritual Journey: Jeddah Umrah Experience</h3>
                              <p class="fp-text">Join us for an unforgettable 7-day Umrah pilgrimage based in the beautiful city of Jeddah, with guided visits and comfortable stays.</p>
                              <div class="fp-meta"><span>Jeddah</span><i></i><span>7 Days / 7 Nights</span><i></i><span>From <b>USD 1,200.00</b> / Person</span></div>
                           </a>
                           <div class="fp-list">
                              <div class="fp-list-head">Umrah Packages</div>
                           <a class="fp-row" href="#" onclick="return false" style="--n:1">
                              <span class="fp-thumb"><img src="<?= root ?>uploads/umrah/gallery/umrah_1780217166_5f7c3069.jpg" alt=""></span>
                              <div>
                                 <span class="fp-chip">Luxury</span>
                                 <h3 class="fp-title">Luxury Umrah Experience in Jeddah</h3>
                                 <div class="fp-meta"><span>Jeddah</span><i></i><span>7 Days / 7 Nights</span><i></i><span>From <b>USD 2,500.00</b> / Person</span></div>
                              </div>
                           </a>
                           <a class="fp-row" href="#" onclick="return false" style="--n:2">
                              <span class="fp-thumb"><img src="<?= root ?>uploads/umrah/gallery/umrah_1780217120_ddfc154e.jpg" alt=""></span>
                              <div>
                                 <span class="fp-chip">Standard</span>
                                 <h3 class="fp-title">Jeddah Spiritual Journey: 7 Days Umrah</h3>
                                 <div class="fp-meta"><span>Jeddah</span><i></i><span>7 Days / 7 Nights</span><i></i><span>From <b>USD 1,200.00</b> / Person</span></div>
                              </div>
                           </a>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"fp\">\n  <a class=\"fp-main\" href=\"...\">\n    <img src=\"picture.jpg\" alt=\"\">\n    <span class=\"fp-chip\">Standard</span>\n    <h3 class=\"fp-title\">Name</h3>\n    <p class=\"fp-text\">A line of the description</p>\n    <div class=\"fp-meta\"><span>Jeddah</span><i></i><span>7 Days</span><i></i><span>From <b>USD 1,200.00</b></span></div>\n  </a>\n  <div class=\"fp-list\">\n    <div class=\"fp-list-head\">Umrah Packages</div>\n    <a class=\"fp-row\" href=\"...\" style=\"--n:1\">\n      <span class=\"fp-thumb\"><img src=\"picture.jpg\" alt=\"\"></span>\n      <div>\n        <span class=\"fp-chip\">Luxury</span>\n        <h3 class=\"fp-title\">Name</h3>\n        <div class=\"fp-meta\">...</div>\n      </div>\n    </a>\n  </div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Signal <code class="text-sm font-normal text-blue-700">.fc-signal</code></h3>
                     <p class="text-sm text-gray-600 mb-3"><code>data-level</code> says how many of the four bars are lit: 5G = 4, 4G = 3, 3G = 2.</p>
                     <div class="mb-3 flex items-center gap-8" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fff">
                        <?php foreach (['3G' => 2, '4G' => 3, '5G' => 4] as $net => $level): ?>
                        <span class="fc-signal" data-level="<?= $level ?>"><span><?= $net ?></span><span class="fc-bars"><i></i><i></i><i></i><i></i></span></span>
                        <?php endforeach; ?>
                     </div>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Loading <code class="text-sm font-normal text-blue-700">.fc.fc-skel</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The same card as a skeleton, shown until the facts arrive.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="fs-track" style="overflow:hidden">
                           <?php for ($i = 0; $i < 5; $i++): ?>
                           <div class="fc fc-skel">
                              <div class="fc-top"><i style="width:54px;height:54px;border-radius:12px"></i><i style="width:24px;height:16px"></i></div>
                              <i style="width:66%;height:18px;margin-top:12px"></i>
                              <div class="fc-chips"><i style="width:96px;height:24px"></i><i style="width:64px;height:24px"></i></div>
                              <div class="fc-foot"><i style="width:80px;height:26px"></i><i style="width:104px;height:34px;border-radius:8px"></i></div>
                           </div>
                           <?php endfor; ?>
                        </div>
                     </div>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                           <tbody>
                              <?php foreach ($fcFiles as $file => $note): ?>
                              <tr class="border-b border-gray-100">
                                 <td class="py-2 pr-4 whitespace-nowrap"><code class="text-blue-700"><?= $file ?></code></td>
                                 <td class="py-2 text-gray-600"><?= htmlspecialchars($note) ?></td>
                              </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  </div>

               </div>
            </div>
         </div>
      </div>
   </div>
</div>
