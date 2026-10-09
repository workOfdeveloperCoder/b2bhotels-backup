<?php
// COMPONENT LIBRARY — HERO
// Live examples of the hero parts: tabs, search card, trip type switch, fields,
// popup, swap, search button and the shortcut pill with its moving icons.
// Classes are defined in app/views/includes/hero/styles.php and app/views/tailwind.php.
@$SECURE or die('Access Denied!');

require_once views . 'includes/hero/icons.php';
require views . 'includes/hero/styles.php';
require views . 'includes/hero/lottie.php';
require_once views . 'includes/hero/skeleton.php';

$hrStage = 'position:relative;padding:20px;border-radius:12px;background:linear-gradient(135deg,#1e3a8a,#0f172a)';
$hrPlain = 'padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa';

$hrTabs = [
    ['type' => 'flights', 'label' => T::flights, 'icon' => 'flight'],
    ['type' => 'stays',   'label' => T::stays,   'icon' => 'apartment'],
    ['type' => 'tours',   'label' => T::tours,   'icon' => 'explore'],
    ['type' => 'cars',    'label' => T::cars,    'icon' => 'directions_car'],
];

$hrFiles = [
    'data.php'       => 'Which modules are tabs and which are shortcuts. Change $heroShortcutPlan to move one.',
    'styles.php'     => 'All hero classes (.hr-*). Colours come from the theme.',
    'icons.php'      => 'heroIcon() and heroBadgeIcon(): our own drawings, each part has a name so it can move.',
    'lottie.php'     => 'Plays an icon from a Lottie file when one is placed in assets/lottie/hero/.',
    'script.php'     => 'homeHero(): active tab, loading of the search forms, AI trip planner.',
    'background.php' => 'Hero picture: drifts and zooms for 16s, then fades into itself; parallax on scroll; keeps its own height.',
    'skeleton.php'   => 'heroSkeleton(): grey blocks shown while a form is not ready.',
    'heading.php'    => 'Title, subtitle and the three badges.',
    'tabs.php'       => 'Module tabs, the sliding white tab and the AI Trip Planner button.',
    'panels.php'     => 'White card with one form per tab; forms cross-fade and the card follows their height.',
    'shortcuts.php'  => 'Pill under the card: Group Tours, Travel Visas, Travel Insurance, eSIM.',
    'ai-panel.php'   => 'AI Trip Planner form.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Hero Components</h1>
               <p class="text-gray-600 mb-6">The home hero is built from small parts in <code class="text-sm">app/views/includes/hero/</code>. The search forms of all modules share the same fields, switch and button, so a change here shows everywhere.</p>

               <div class="space-y-8">

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Tabs + card <code class="text-sm font-normal text-blue-700">.hr-tabs + .hr-tab + .hr-card</code></h3>
                     <p class="text-sm text-gray-600 mb-3">One white tab (<code>.hr-tab-ind</code>) slides to the tab that is clicked; its position is the variable <code>--i</code>. The forms cross-fade and the card moves to the height of the new form. Add <code>is-first</code> to the card when the first tab is active, so the corner under it stays square. Tab icons use a thin cut of the icon font, loaded by <code>heroTabFont()</code> for these icons only. When the tabs do not all fit, a scroll bar (<code>.hr-tabs-bar</code>) appears above them. The home page uses the low look, <code>.hr-tabs.is-compact</code> (icon and label on one line, tabs as wide as their label); the look is chosen in <code>hero/data.php</code> (<code>$heroTabStyle</code>). The example below shows the tall one.</p>
                     <div class="mb-3" style="<?= $hrStage ?>" x-data="{ activeTab: 'flights', tabs: ['flights', 'stays', 'tours', 'cars'] }">
                        <div class="hr-tabs-row">
                           <?= heroTabFont(array_column($hrTabs, 'icon')) ?>
                           <div class="hr-tabs" role="tablist">
                              <span class="hr-tab-ind no-start" style="--i:0" :style="{ '--i': tabs.indexOf(activeTab) }"
                                    :class="{ 'no-start': tabs.indexOf(activeTab) === 0, 'no-end': tabs.indexOf(activeTab) === tabs.length - 1 }"><i class="c-start"></i><i class="c-end"></i></span>
                              <?php foreach ($hrTabs as $tab): ?>
                              <button type="button" class="hr-tab" role="tab" :class="{ 'is-active': activeTab === '<?= $tab['type'] ?>' }" @click="activeTab = '<?= $tab['type'] ?>'">
                                 <span class="hr-tab-in">
                                    <span class="material-symbols-outlined hr-tab-icon"><?= $tab['icon'] ?></span>
                                    <span class="hr-tab-label"><?= htmlspecialchars($tab['label']) ?></span>
                                 </span>
                              </button>
                              <?php endforeach; ?>
                           </div>
                           <button type="button" class="hr-ai">
                              <span class="hr-ai-shine"></span>
                              <span class="hr-ai-icon"><span class="material-symbols-outlined">auto_awesome</span></span>
                              <span><?= htmlspecialchars(T::ai_trip_planner) ?></span>
                           </button>
                        </div>
                        <div class="hr-card" :class="{ 'is-first': activeTab === 'flights' }">
                           <?php foreach ($hrTabs as $n => $tab): ?>
                           <div class="hr-panel<?= $n === 0 ? ' is-active' : '' ?>" :class="{ 'is-active': activeTab === '<?= $tab['type'] ?>' }">
                              <p class="text-sm text-gray-600" style="padding: <?= 12 + $n * 14 ?>px 0">Search form of: <strong><?= htmlspecialchars($tab['label']) ?></strong></p>
                           </div>
                           <?php endforeach; ?>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"hr-tabs\" role=\"tablist\">\n  <span class=\"hr-tab-ind\" style=\"--i:0\"><i class=\"c-start\"></i><i class=\"c-end\"></i></span>\n  <button type=\"button\" class=\"hr-tab is-active\" role=\"tab\">\n    <span class=\"hr-tab-in\">\n      <span class=\"material-symbols-outlined hr-tab-icon\">flight</span>\n      <span class=\"hr-tab-label\">Flights</span>\n    </span>\n  </button>\n</div>\n<div class=\"hr-card is-first\">\n  <div class=\"hr-panel is-active\">...</div>\n  <div class=\"hr-panel\">...</div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Loading <code class="text-sm font-normal text-blue-700">.hr-card.is-loading + heroSkeleton()</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The card starts with <code>is-loading</code>: it shows a skeleton and keeps the form out of sight until styles and icons are ready, then the class is removed. A form that is fetched later shows the same skeleton while it loads. Pass <code>true</code> for a form that starts with a row of options.</p>
                     <div class="mb-3" style="<?= $hrStage ?>">
                        <div class="hr-card is-loading">
                           <div class="hr-card-skel"><?= heroSkeleton(true) ?></div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"hr-card is-loading\">\n  <div class=\"hr-card-skel\"><?= heroSkeleton(true) ?></div>\n  <div class=\"hr-panel is-active\">...</div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Switch <code class="text-sm font-normal text-blue-700">.segmented + .segmented-item</code></h3>
                     <p class="text-sm text-gray-600 mb-3">One choice out of a few: trip type, transfer type. Add <code>is-soon</code> with a <code>.soon-badge</code> for an option that is not ready yet.</p>
                     <div class="mb-3" style="<?= $hrPlain ?>" x-data="{ type: 'oneway' }">
                        <div class="segmented">
                           <button type="button" class="segmented-item" :class="type === 'oneway' ? 'is-active' : ''" @click="type = 'oneway'"><?= htmlspecialchars(T::one_way) ?></button>
                           <button type="button" class="segmented-item" :class="type === 'return' ? 'is-active' : ''" @click="type = 'return'"><?= htmlspecialchars(T::round_trip) ?></button>
                           <button type="button" class="segmented-item" :class="type === 'multi' ? 'is-active' : ''" @click="type = 'multi'"><?= htmlspecialchars(T::multi_city) ?></button>
                           <button type="button" class="segmented-item is-soon" disabled aria-disabled="true"><span><?= htmlspecialchars(T::open_return) ?></span><span class="soon-badge"><?= htmlspecialchars(T::soon) ?></span></button>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"segmented\">\n  <button type=\"button\" class=\"segmented-item is-active\">One Way</button>\n  <button type=\"button\" class=\"segmented-item\">Round Trip</button>\n  <button type=\"button\" class=\"segmented-item is-soon\" disabled>\n    <span>Open Return</span>\n    <span class=\"soon-badge\"><?= T::soon ?></span>\n  </button>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Field + search button <code class="text-sm font-normal text-blue-700">.field-box + .search-btn</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Fields are 67px tall. While a field is empty its label sits in the middle; it moves up (spring) when a value comes in. The search button uses the design-system <code>.btn</code> and takes the same height. Colours and springs are variables at the top of the search skin in <code>app/views/tailwind.php</code> (<code>--field-border</code>, <code>--spring-label</code> ...). The icon of a field is always 16px, in the colour of the label and on the line of the label, whether it comes from the icon font or is a drawing: give it <code>field-box-icon</code> and do not set a size on it.</p>
                     <div class="mb-3" style="<?= $hrPlain ?>">
                        <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_150px] gap-3">
                           <div class="field-box">
                              <span class="material-symbols-outlined field-box-icon">flight_takeoff</span>
                              <label class="field-box-label"><?= htmlspecialchars(T::depart_from) ?></label>
                              <input type="text" class="field-box-input" placeholder="Dubai (DXB)">
                           </div>
                           <div class="field-box">
                              <span class="material-symbols-outlined field-box-icon">calendar_month</span>
                              <label class="field-box-label"><?= htmlspecialchars(T::departure_date) ?></label>
                              <input type="text" class="field-box-input" value="Oct 12, 2026" placeholder="Date">
                           </div>
                           <button type="button" class="btn search-btn w-full h-[67px] rounded-lg flex items-center justify-center gap-2 p-0">
                              <span class="search-btn-label"><?= htmlspecialchars(T::search) ?></span>
                              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                           </button>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"field-box\">\n  <span class=\"material-symbols-outlined field-box-icon\">flight_takeoff</span>\n  <label class=\"field-box-label\">Departure From</label>\n  <input type=\"text\" class=\"field-box-input\">\n</div>\n\n<button type=\"submit\" class=\"btn search-btn w-full h-[67px] rounded-lg flex items-center justify-center gap-2 p-0\">\n  <span class=\"search-btn-label\"><?= T::search ?></span>\n  <svg>...</svg>\n</button>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Popup <code class="text-sm font-normal text-blue-700">x-transition:enter="pop-enter"</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Every dropdown of a search field: a floating card that flips in from its field, its rows follow one after another, and it fades out a little smaller. On phones the full-screen sheets slide in from the side. The calendar uses the same entrance. A popup that does not fit under its field opens above it when there is more room there (<code>data-side="top"</code>, set by <code>assets/js/app.js</code>).</p>
                     <div class="mb-3" style="<?= $hrPlain ?>; min-height: 270px" x-data="{ open: false }">
                        <div class="relative" style="max-width: 320px" @click.outside="open = false">
                           <div @click="open = !open" class="relative flex items-center h-[67px] pl-11 pr-4 cursor-pointer bg-white border" :class="open ? 'border-[#1570ef]' : 'border-[#d8dce3]'">
                              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined">flight_takeoff</span>
                              <div class="flex-1 min-w-0">
                                 <div><?= htmlspecialchars(T::depart_from) ?></div>
                                 <div class="text-[#344054] truncate">Dubai (DXB)</div>
                              </div>
                           </div>
                           <div x-show="open" x-transition:enter="pop-enter" x-transition:leave="pop-leave"
                                class="absolute left-0 right-0 z-50 bg-white p-2" style="top:100%; display:none">
                              <?php foreach (['Dubai' => 'DXB', 'London' => 'LHR', 'Istanbul' => 'IST', 'Jeddah' => 'JED'] as $city => $code): ?>
                              <div @click="open = false" class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer hover:bg-slate-50">
                                 <span class="material-symbols-outlined text-gray-400" style="font-size:18px">flight_takeoff</span>
                                 <span class="flex-1 text-sm font-semibold text-gray-900"><?= $city ?></span>
                                 <span class="text-xs text-gray-400"><?= $code ?></span>
                              </div>
                              <?php endforeach; ?>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div x-show=\"open\"\n     x-transition:enter=\"pop-enter\"\n     x-transition:leave=\"pop-leave\"\n     class=\"absolute left-0 right-0 z-50 bg-white\" style=\"top:100%; display:none\">\n  <div class=\"cursor-pointer hover:bg-slate-50 ...\">Row</div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Swap <code class="text-sm font-normal text-blue-700">.swap-wrap-end + .swap-wrap-start + .swap-btn</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Two fields that can change places. Each field curves around the round button between them (from 1024px).</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fff">
                        <div class="grid grid-cols-2 gap-3" style="max-width: 560px">
                           <div class="relative swap-wrap-end">
                              <div class="relative flex items-center h-[67px] pl-11 pr-4 cursor-pointer bg-white border border-[#d8dce3]">
                                 <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined">flight_takeoff</span>
                                 <div class="flex-1 min-w-0"><div><?= htmlspecialchars(T::from) ?></div><div class="text-[#344054] truncate">Dubai (DXB)</div></div>
                              </div>
                              <button type="button" class="swap-btn" aria-label="<?= htmlspecialchars(T::swap) ?>"><span class="material-symbols-outlined">swap_horiz</span></button>
                           </div>
                           <div class="relative swap-wrap-start">
                              <div class="relative flex items-center h-[67px] pl-11 pr-4 cursor-pointer bg-white border border-[#d8dce3]">
                                 <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined">flight_land</span>
                                 <div class="flex-1 min-w-0"><div><?= htmlspecialchars(T::to) ?></div><div class="text-[#344054] truncate">London (LHR)</div></div>
                              </div>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"relative swap-wrap-end\">\n  <div class=\"... h-[67px] pl-11 ...\">From</div>\n  <button type=\"button\" class=\"swap-btn\"><span class=\"material-symbols-outlined\">swap_horiz</span></button>\n</div>\n<div class=\"relative swap-wrap-start\">\n  <div class=\"... h-[67px] pl-11 ...\">To</div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Shortcuts <code class="text-sm font-normal text-blue-700">.hr-shortcuts + .hr-shortcut</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Services that open their own page. Point at one to see its icon move. To play an icon from a Lottie file, put the file in <code>assets/lottie/hero/</code> (<code>luggage.json</code>, <code>globe.json</code>, <code>verified.json</code>, <code>qr-scan.json</code>): it is used by itself and takes the colour of the text.</p>
                     <div class="mb-3" style="<?= $hrStage ?>">
                        <div class="hr-shortcuts-row" style="margin:0">
                           <div class="hr-shortcuts">
                              <button type="button" class="hr-shortcut"><?= heroIcon('bag') ?><span><?= htmlspecialchars(T::group_tours) ?></span></button>
                              <a href="#" class="hr-shortcut" onclick="return false"><?= heroIcon('globe') ?><span><?= htmlspecialchars(T::travel_visas) ?></span></a>
                              <a href="#" class="hr-shortcut" onclick="return false"><?= heroIcon('shield') ?><span><?= htmlspecialchars(T::travel_insurance) ?></span></a>
                              <a href="#" class="hr-shortcut" onclick="return false"><?= heroIcon('esim') ?><span>eSIM</span></a>
                           </div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"hr-shortcuts\">\n  <a href=\"<?= root ?>visa\" class=\"hr-shortcut\">\n    <?= heroIcon('globe') ?>\n    <span><?= T::travel_visas ?></span>\n  </a>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Badges <code class="text-sm font-normal text-blue-700">.hr-badges + .hr-badge</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Short promises under the hero title. Icons come from <code>heroBadgeIcon()</code>.</p>
                     <div class="mb-3" style="<?= $hrStage ?>">
                        <div class="hr-badges" style="margin:0">
                           <span class="hr-badge"><?= heroBadgeIcon('search') ?><span><?= htmlspecialchars(T::hero_badge_search) ?></span></span>
                           <span class="hr-badge"><?= heroBadgeIcon('bolt') ?><span><?= htmlspecialchars(T::hero_badge_instant) ?></span></span>
                           <span class="hr-badge"><?= heroBadgeIcon('tag') ?><span><?= htmlspecialchars(T::hero_badge_price) ?></span></span>
                        </div>
                     </div>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <p class="text-sm text-gray-600 mb-3">One file per part, in <code>app/views/includes/hero/</code>.</p>
                     <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                           <tbody>
                              <?php foreach ($hrFiles as $file => $note): ?>
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
