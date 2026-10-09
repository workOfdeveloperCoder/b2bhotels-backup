<?php
// COMPONENT LIBRARY — TRIPADVISOR
// The Tripadvisor ratings and reviews card of the hotel details page, live, and its parts.
// Classes are defined in app/views/includes/tripadvisor/styles.php.
@$SECURE or die('Access Denied!');

$taFiles = [
    'app/views/includes/tripadvisor/styles.php'  => 'All classes of the card (.ta-*).',
    'app/views/includes/tripadvisor/section.php' => 'The card and taReviews(). Needs $taLocationId, or $taHotel to find the hotel by name.',
    'modules/others/tripadvisor/location.php'    => 'POST hotel (summary + first reviews) and POST reviews (next pages) — what the card calls.',
    'app/views/modules/stays/details/stay.php'   => 'Shows the card under the guest reviews of the hotel page.',
    'robots.txt'                                 => 'Disallows /modules/others/tripadvisor/ — Tripadvisor review text must not be crawled.',
    'app/lang/*.json'                            => 'Words: ta_traveller_reviews, ta_n_reviews, ta_latest, ta_read_all, ta_write_review, ta_owner_response, ta_more_reviews, ta_stayed, ta_average, ta_poor, ta_terrible, ta_trip_*, ta_temporarily_closed, ta_closed, ta_from_tripadvisor.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Tripadvisor Components</h1>
               <p class="text-gray-600 mb-6">Ratings and reviews of one hotel from the Tripadvisor module. The card fills itself by AJAX when it comes near the screen, as Tripadvisor requires; it stays hidden when the module is off or the hotel is not on Tripadvisor. The rating bubbles and the brand mark use the Tripadvisor green; links and buttons use the theme.</p>

               <div class="space-y-8">

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Card <code class="text-sm font-normal text-blue-700">.ta</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Live, with location 302457 (Jumeirah Burj Al Arab). Each view of this page makes two billable Tripadvisor calls.</p>
                     <div class="mb-3" style="padding:20px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <?php $taLocationId = '302457'; include views . 'includes/tripadvisor/section.php'; ?>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<?php\n// a location id saved on the hotel\n\$taLocationId = \$hotel['tripadvisor_id'];\n// or let the module find the hotel by name (the match is stored)\n\$taHotel = ['name' => \$name, 'city' => \$city, 'lat' => \$lat, 'lng' => \$lng, 'supplier' => \$supplier, 'hotel_id' => \$id];\ninclude views . 'includes/tripadvisor/section.php';\n?>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Parts</h3>
                     <table class="w-full text-sm text-left mb-3">
                        <thead><tr class="border-b"><th class="py-2 pr-4">Class</th><th class="py-2">Purpose</th></tr></thead>
                        <tbody class="text-gray-700">
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-head</code> / <code>.ta-brand</code></td><td class="py-2">Heading and the Tripadvisor mark (a logo file in <code>assets/img/modules/tripadvisor.svg</code> replaces the drawn mark)</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-score</code></td><td class="py-2">Overall score out of 5, rating bubbles, review count, closed notice</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-bars</code> / <code>.ta-bar</code></td><td class="py-2">Excellent → Terrible with the share of reviews</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-subs</code> / <code>.ta-sub</code></td><td class="py-2">Sub-ratings (cleanliness, location, service …)</td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-review</code></td><td class="py-2">One review: <code>.ta-user</code>, <code>.ta-meta</code>, <code>.ta-review-title</code>, <code>.ta-text</code> (4 lines, <code>.is-open</code> shows all), <code>.ta-photos</code>, <code>.ta-owner</code></td></tr>
                           <tr class="border-b"><td class="py-2 pr-4"><code>.ta-bubbles</code> / <code>.ta-dots</code></td><td class="py-2">Tripadvisor's rating image; drawn bubbles when there is none</td></tr>
                           <tr><td class="py-2 pr-4"><code>.ta-foot</code> / <code>.ta-skel</code></td><td class="py-2">Attribution line; placeholders while loading</td></tr>
                        </tbody>
                     </table>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <table class="w-full text-sm text-left">
                        <tbody class="text-gray-700">
                           <?php foreach ($taFiles as $taPath => $taWhat): ?>
                           <tr class="border-b last:border-0"><td class="py-2 pr-4 align-top"><code><?= htmlspecialchars($taPath) ?></code></td><td class="py-2"><?= htmlspecialchars($taWhat) ?></td></tr>
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
