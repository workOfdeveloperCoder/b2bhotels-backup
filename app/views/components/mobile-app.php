<?php
// COMPONENT LIBRARY — MOBILE APP
// The "get the app" section of the home page, live, and its parts.
// Classes are defined in app/views/includes/mobile-app/styles.php.
@$SECURE or die('Access Denied!');

$maFiles = [
    'app/views/includes/mobile-app/styles.php'  => 'All classes of the section (.ma-*).',
    'app/views/includes/mobile-app/section.php' => 'The section. Needs $maIos and $maAndroid (store links; an empty one hides its badge).',
    'app/views/home.php'                        => 'Shows it when Settings > show_apps is on and at least one store link is set.',
    'app/lang/*.json'                           => 'Words: app_title_1, app_title_2, app_lead, app_where_next, app_point_1..3_title / _text.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Mobile App Components</h1>
               <p class="text-gray-600 mb-6">One white card in three parts: the words with the store badges, two phones drawn in CSS with a glimpse of the app, and three numbered reasons. On a tablet the reasons go under the first two parts in a row; on a phone everything is one column. The heading's <code>&lt;em&gt;</code>, the eyebrow, the numbers and the phone screens take the theme colour.</p>

               <div class="space-y-8">

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Section <code class="text-sm font-normal text-blue-700">.ma</code></h3>
                     <p class="text-sm text-gray-600 mb-3">As on the home page. The phones lift a little when the card is pointed at.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <?php $maIos = 'https://apps.apple.com/'; $maAndroid = 'https://play.google.com/'; include views . 'includes/mobile-app/section.php'; ?>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<?php\n\$maIos = \$GLOBALS['app']['ios_store'] ?? '';\n\$maAndroid = \$GLOBALS['app']['android_store'] ?? '';\ninclude views . 'includes/mobile-app/section.php';\n?>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Parts</h3>
                     <table class="w-full text-sm text-left mb-3">
                        <thead><tr class="border-b"><th class="py-2 pr-4">Class</th><th class="py-2">Purpose</th></tr></thead>
                        <tbody class="text-gray-700">
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-eyebrow</code></td><td class="py-2">Small line above the heading, with a dash in the theme colour</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-title</code></td><td class="py-2">Heading; the words in <code>&lt;em&gt;</code> take the theme colour</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-lead</code></td><td class="py-2">Paragraph under the heading</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-stores</code> / <code>.ma-store</code></td><td class="py-2">The black App Store and Google Play badges</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-phones</code> / <code>.ma-phone</code></td><td class="py-2">Two phones; <code>.is-front</code> and <code>.is-back</code></td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ma-screen</code></td><td class="py-2">The screen: <code>.ma-bar</code>, <code>.ma-hello</code>, <code>.ma-search</code>, <code>.ma-tiles</code>, <code>.ma-deal</code>, <code>.ma-ticket</code>, <code>.ma-note</code></td></tr>
                           <tr><td class="py-2 pr-4"><code>.ma-points</code> / <code>.ma-point</code></td><td class="py-2">Numbered reasons: <code>&lt;b&gt;</code> the number, <code>&lt;h3&gt;</code> the title, <code>&lt;p&gt;</code> the text</td></tr>
                        </tbody>
                     </table>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <table class="w-full text-sm text-left">
                        <tbody class="text-gray-700">
                           <?php foreach ($maFiles as $maFile => $maWhat): ?>
                           <tr class="border-b"><td class="py-2 pr-4 whitespace-nowrap"><code><?= htmlspecialchars($maFile) ?></code></td><td class="py-2"><?= htmlspecialchars($maWhat) ?></td></tr>
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
