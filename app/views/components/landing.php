<?php
// COMPONENT LIBRARY — LANDING
// Live examples of the parts of a service page (eSIM): top card, search box,
// destination card, row of choices, steps, reasons.
// Classes are defined in app/views/includes/landing/styles.php.
@$SECURE or die('Access Denied!');

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';
require views . 'includes/landing/styles.php';

$ldDests = [
    ['flag' => 'tr', 'name' => 'Turkey',   'net' => '5G', 'plans' => 12, 'price' => '4.50', 'top' => true,  'tag' => true],
    ['flag' => 'ae', 'name' => 'United Arab Emirates', 'net' => '5G', 'plans' => 9, 'price' => '5.00', 'top' => true, 'tag' => false],
    ['flag' => 'my', 'name' => 'Malaysia', 'net' => '4G', 'plans' => 8,  'price' => '4.50', 'top' => false, 'tag' => false],
];

$ldFiles = [
    'app/views/includes/landing/styles.php' => 'All classes of the page (.ld-*).',
    'app/views/includes/featured/modal.php' => 'Rows of choices (.fm-chips, .fm-pick) and the window with plans (.fm-*).',
    'app/views/modules/esim/index.php'      => 'The eSIM page, built from these parts.',
    'app/views/modules/esim/plans-script.php' => 'esimPlans(): words and numbers of a card, and the window with the plans of a country.',
    'app/views/modules/esim/plans-modal.php'  => 'The window with the plans of a country.',
    'app/routes/api/esim/featuredRoutes.php'  => '/api/esim/catalog: every destination with its starting price and region.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Landing Components</h1>
               <p class="text-gray-600 mb-6">The page of a service is made of white cards on a light page: a top card with a search box, a catalog, steps and reasons. The live page is <a class="text-blue-700" href="<?= root ?>esim"><?= root ?>esim</a>.</p>

               <div class="space-y-8">

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Top card <code class="text-sm font-normal text-blue-700">.ld-card.ld-hero</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Badge, heading, two numbers, and a search box whose button is the design-system <code>.btn</code>.</p>
                     <div class="mb-3 ld" style="padding:20px;border-radius:12px">
                        <div class="ld-card ld-hero">
                           <div class="ld-hero-top">
                              <div style="flex:1 1 auto;min-width:0">
                                 <span class="ld-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2.500" width="12" height="19" rx="2.500"/><path d="M11 18h2"/></svg><?= htmlspecialchars((string) T::esim_badge) ?></span>
                                 <h1 class="ld-h1"><?= htmlspecialchars((string) T::esim_h1) ?></h1>
                                 <p class="ld-lead"><?= htmlspecialchars(str_replace('{n}', '190', (string) T::esim_lead)) ?></p>
                              </div>
                              <div class="ld-stats">
                                 <div class="ld-stat"><b>190+</b><span><?= htmlspecialchars((string) T::countries) ?></span></div>
                                 <div class="ld-stat"><b><?= htmlspecialchars((string) T::esim_stat_instant) ?></b><span><?= htmlspecialchars((string) T::esim_stat_delivery) ?></span></div>
                              </div>
                           </div>
                           <div class="ld-rule"></div>
                           <p class="ld-label"><?= htmlspecialchars((string) T::esim_find) ?></p>
                           <form class="ld-search" onsubmit="return false">
                              <div class="ld-search-field">
                                 <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                 <input type="text" placeholder="<?= htmlspecialchars((string) T::esim_where) ?>">
                              </div>
                              <button type="submit" class="btn"><?= htmlspecialchars((string) T::search) ?></button>
                           </form>
                           <p class="ld-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.500"/><path d="M12 16v-4.500M12 8h.01"/></svg><?= htmlspecialchars(str_replace(['{a}', '{b}'], ['3', '30'], (string) T::esim_note)) ?></p>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"ld\">\n  <div class=\"container\">\n    <div class=\"ld-card ld-hero\">\n      <div class=\"ld-hero-top\">\n        <div>\n          <span class=\"ld-badge\">eSIM services</span>\n          <h1 class=\"ld-h1\">Heading</h1>\n          <p class=\"ld-lead\">Lines under the heading</p>\n        </div>\n        <div class=\"ld-stats\">\n          <div class=\"ld-stat\"><b>190+</b><span>Countries</span></div>\n        </div>\n      </div>\n      <div class=\"ld-rule\"></div>\n      <p class=\"ld-label\">Find your eSIM</p>\n      <form class=\"ld-search\">\n        <div class=\"ld-search-field\"><svg>...</svg><input type=\"text\"></div>\n        <button type=\"submit\" class=\"btn\">Search</button>\n      </form>\n      <p class=\"ld-note\">...</p>\n    </div>\n  </div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Catalog <code class="text-sm font-normal text-blue-700">.ld-head + .fm-chips + .ld-grid + .ld-dest</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Head with a row of choices, and a grid of destinations (4, 3, 2 or 1 in a row). On phones a destination becomes a row. The pictures of the supplier are small, so one copy fills the box blurred and a sharp copy lies on it.</p>
                     <div class="mb-3 ld" style="padding:20px;border-radius:12px" x-data="{ region: '' }">
                        <div class="ld-card is-clip">
                           <div class="ld-head">
                              <div><h2><?= htmlspecialchars((string) T::esim_destinations) ?></h2><p><?= htmlspecialchars(str_replace('{n}', '3', (string) T::esim_showing)) ?></p></div>
                              <div class="fm-chips">
                                 <button type="button" class="fm-pick" :class="region === '' ? 'is-active' : ''" @click="region = ''"><?= htmlspecialchars((string) T::all) ?><i>3</i></button>
                                 <button type="button" class="fm-pick" :class="region === 'me' ? 'is-active' : ''" @click="region = 'me'"><?= htmlspecialchars((string) T::region_middle_east) ?><i>1</i></button>
                                 <button type="button" class="fm-pick" :class="region === 'eu' ? 'is-active' : ''" @click="region = 'eu'"><?= htmlspecialchars((string) T::region_europe) ?><i>1</i></button>
                              </div>
                           </div>
                           <div class="ld-body">
                              <div class="ld-grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
                                 <?php foreach ($ldDests as $n => $dest): ?>
                                 <div class="ld-dest" role="button" tabindex="0" style="--n:<?= $n ?>">
                                    <div class="ld-dest-pic"><div>
                                       <img class="is-card" src="<?= root ?>assets/img/flags/<?= $dest['flag'] ?>.svg" alt="" style="border-radius:50%">
                                       <?php if ($dest['tag']): ?><span class="ld-dest-tag"><?= htmlspecialchars((string) T::popular) ?></span><?php endif; ?>
                                    </div></div>
                                    <div class="ld-dest-main">
                                       <div class="ld-dest-name"><div><img src="<?= root ?>assets/img/flags/<?= $dest['flag'] ?>.svg" alt=""><b><?= htmlspecialchars($dest['name']) ?></b></div><span class="ld-dest-line"><?= $dest['net'] ?> · <?= htmlspecialchars(str_replace('{n}', (string) $dest['plans'], (string) T::esim_plans_count)) ?></span></div>
                                       <div class="ld-dest-price"><span><?= htmlspecialchars((string) T::from) ?></span><span class="fc-price"><small>USD</small><b><?= $dest['price'] ?></b></span></div>
                                    </div>
                                    <div class="ld-dest-facts">
                                       <div><span><?= htmlspecialchars((string) T::network) ?></span><b><?= $dest['net'] ?></b></div>
                                       <div><span><?= htmlspecialchars((string) T::options) ?></span><b><?= htmlspecialchars(str_replace('{n}', (string) $dest['plans'], (string) T::esim_plans_count)) ?></b></div>
                                       <div><span><?= htmlspecialchars((string) T::esim_recharge) ?></span><b><?= htmlspecialchars((string) ($dest['top'] ? T::yes : T::no)) ?></b></div>
                                    </div>
                                 </div>
                                 <?php endforeach; ?>
                              </div>
                              <div class="ld-more"><button type="button" class="btn outline"><?= htmlspecialchars((string) T::show_more) ?></button></div>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"ld-card is-clip\">\n  <div class=\"ld-head\">\n    <div><h2>Title</h2><p>Line under it</p></div>\n    <div class=\"fm-chips\">\n      <button type=\"button\" class=\"fm-pick is-active\">All<i>195</i></button>\n      <button type=\"button\" class=\"fm-pick\">Europe<i>52</i></button>\n    </div>\n  </div>\n  <div class=\"ld-body\">\n    <div class=\"ld-grid\">\n      <div class=\"ld-dest\" role=\"button\" tabindex=\"0\">\n        <div class=\"ld-dest-pic\"><div>\n          <img class=\"is-pic\" src=\"...\" alt=\"\">\n          <img class=\"is-card\" src=\"...\" alt=\"\">\n          <span class=\"ld-dest-tag\">Popular</span>\n        </div></div>\n        <div class=\"ld-dest-main\">\n          <div class=\"ld-dest-name\"><div><img src=\"flag.svg\" alt=\"\"><b>Turkey</b></div></div>\n          <div class=\"ld-dest-price\"><span>From</span><span class=\"fc-price\"><small>USD</small><b>4.50</b></span></div>\n        </div>\n        <div class=\"ld-dest-facts\">\n          <div><span>Network</span><b>5G</b></div>\n          <div><span>Options</span><b>12 plans</b></div>\n          <div><span>Top-up</span><b>Yes</b></div>\n        </div>\n      </div>\n    </div>\n    <div class=\"ld-more\"><button type=\"button\" class=\"btn outline\">Show more</button></div>\n  </div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Steps and reasons <code class="text-sm font-normal text-blue-700">.ld-steps + .ld-why</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Cells share thin lines. A reason takes <code>is-blue</code>, <code>is-green</code> or <code>is-amber</code> for the colour of its icon; without one it has the theme colour.</p>
                     <div class="mb-3 ld" style="padding:20px;border-radius:12px">
                        <div class="ld-card is-clip" style="margin-bottom:16px">
                           <div class="ld-head is-large"><div><h2><?= htmlspecialchars((string) T::how_it_works) ?></h2><p><?= htmlspecialchars((string) T::esim_how_sub) ?></p></div></div>
                           <div class="ld-steps">
                              <div class="ld-step"><i>1</i><div><b><?= htmlspecialchars((string) T::esim_step1_t) ?></b><p><?= htmlspecialchars((string) T::esim_step1_d) ?></p></div></div>
                              <div class="ld-step"><i>2</i><div><b><?= htmlspecialchars((string) T::esim_step2_t) ?></b><p><?= htmlspecialchars((string) T::esim_step2_d) ?></p></div></div>
                              <div class="ld-step"><i>3</i><div><b><?= htmlspecialchars((string) T::esim_step3_t) ?></b><p><?= htmlspecialchars((string) T::esim_step3_d) ?></p></div></div>
                           </div>
                        </div>
                        <div class="ld-card is-clip">
                           <div class="ld-why" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                              <div class="ld-reason"><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4.500 13.500H11l-1 8.500 8.500-11.500H12l1-8.500Z"/></svg></i><div><b><?= htmlspecialchars((string) T::esim_why1_t) ?></b><p><?= htmlspecialchars((string) T::esim_why1_d) ?></p></div></div>
                              <div class="ld-reason is-green"><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.500 4.500 5.400v5.900c0 4.700 3.100 8.600 7.500 10.200 4.400-1.600 7.500-5.500 7.500-10.200V5.400L12 2.500Z"/><path d="m8.600 12.100 2.400 2.400 4.400-4.600"/></svg></i><div><b><?= htmlspecialchars((string) T::esim_why3_t) ?></b><p><?= htmlspecialchars((string) T::esim_why3_d) ?></p></div></div>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"ld-steps\">\n  <div class=\"ld-step\"><i>1</i><div><b>Title</b><p>Text</p></div></div>\n</div>\n\n<div class=\"ld-why\">\n  <div class=\"ld-reason is-green\"><i><svg>...</svg></i><div><b>Title</b><p>Text</p></div></div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                           <tbody>
                              <?php foreach ($ldFiles as $file => $note): ?>
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
