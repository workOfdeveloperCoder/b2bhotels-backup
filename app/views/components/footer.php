<?php
// COMPONENT LIBRARY — FOOTER
// The footer of every page (it is under this page too) and its parts.
// Classes are defined in app/views/includes/footer/styles.php.
@$SECURE or die('Access Denied!');

$ftParts = [
    '.ft'                    => 'The footer: theme colours (--footer-*), a hairline above.',
    '.ft-top'                => 'The main row: brand and contact | the menus of the CMS | follow, the app, support. Two columns under 1024px, one under 768px.',
    '.ft-brand / .ft-tagline / .ft-contact' => 'Logo, the tagline (footer_trusted_partner), address, phone and email with small tinted icons.',
    '.ft-h'                  => 'Heading of a column: small capitals with a short bar in the theme colour.',
    '.ft-menu'               => 'Links of a CMS footer menu; the underline slides in on hover.',
    '.ft-social'             => 'Round buttons for the networks set in Settings (Facebook, X, Instagram, YouTube, LinkedIn, TikTok); only the ones with a link are shown.',
    '.ft-apps / .ft-app'     => 'App Store and Google Play badges, when the links are set and show_apps is on.',
    '.ft-support'            => 'The 24/7 support card.',
    '.ft-strip'              => 'The trust marks on their own line: SSL Secure, IATA (a link), Secure Payments, Best Price Guaranteed.',
    '.ft-bottom'             => 'The bottom bar in three parts: copyright with the business name | the .ft-legal links | the .ft-powered pill (unless the licence removes it).',
    '.ft-top-btn'            => 'The back-to-top button, shown after 300px of scroll (.is-on).',
];
$ftFiles = [
    'app/views/includes/footer/styles.php' => 'All classes (.ft-*).',
    'app/views/includes/footer.php'        => 'The footer: reads the CMS menus and the settings, prints the markup.',
    'app/views/tailwind.php'               => 'The theme variables --footer-background, --footer-text-color, --footer-heading-color, --footer-link-color, --footer-border-color, --footer-font-size.',
];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Footer Components</h1>
               <p class="text-gray-600 mb-6">The footer is at the bottom of this page. One main row in three parts — brand and contact, the menus of the CMS, the ways to follow and to get the app — and a bottom bar with the legal links and the trust marks. Colours come from the theme's footer settings; the theme colour marks the headings, the icons and the hover states.</p>

               <div class="space-y-8">
                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Powered by <code class="text-sm font-normal text-blue-700">.ft-powered</code></h3>
                     <p class="text-sm text-gray-600 mb-3">The linkback to phptravels.com (dofollow), a pill with a breathing dot and the PHPTRAVELS mark; it lifts on hover. It sits in the bottom bar unless the licence removes it (<code>plan_allows('remove_linkback')</code>) — this site's plan removes it, so here is the pill by itself, on a dark ground.</p>
                     <div class="mb-3" style="padding:20px;border-radius:12px;background:#0b0f19;--footer-border-color:#1e2636;--footer-heading-color:#fff;--footer-text-color:#9aa3b2">
                        <a class="ft-powered" href="https://phptravels.com" target="_blank" rel="noopener" style="margin:0"><i></i>Powered by <svg viewBox="0 0 700 700" fill="currentColor" aria-hidden="true"><g transform="translate(0,700) scale(0.1,-0.1)"><path d="M4435 5753 c-307 -19 -533 -75 -848 -209 l-156 -66 -78 45 c-146 86 -349 156 -563 193 -115 20 -360 23 -445 5 -141 -29 -285 -91 -330 -141 -42 -46 -7 -157 90 -288 l44 -60 68 34 c112 55 204 74 367 74 87 0 164 -6 202 -15 74 -17 174 -54 174 -64 0 -8 -171 -70 -275 -100 -320 -91 -556 -73 -658 50 -54 67 -97 165 -97 222 0 60 -12 54 -40 -21 -28 -73 -28 -158 -2 -278 43 -196 136 -378 224 -443 94 -69 244 -98 431 -81 317 28 432 73 1177 461 489 255 824 365 1145 376 157 6 237 -7 327 -53 72 -36 153 -116 185 -183 25 -49 28 -67 27 -146 0 -105 -22 -172 -89 -275 -65 -101 -174 -196 -415 -361 -188 -129 -664 -429 -682 -429 -4 0 -8 19 -8 43 0 107 -57 347 -126 536 -30 82 -175 390 -202 429 -6 10 -59 -13 -218 -94 -115 -59 -210 -108 -212 -110 -2 -1 14 -33 35 -71 134 -235 217 -551 248 -944 l6 -76 -138 -78 c-245 -139 -511 -317 -803 -538 -172 -131 -243 -191 -368 -312 -275 -265 -473 -617 -489 -865 -4 -75 -2 -94 22 -160 66 -182 187 -375 300 -478 54 -49 138 -103 182 -116 20 -7 20 -3 -13 61 -123 241 -21 584 288 969 189 236 545 536 869 732 789 479 1159 721 1514 987 267 200 412 385 494 630 107 320 19 641 -248 905 -148 146 -286 223 -484 270 -75 18 -310 43 -361 38 -12 -1 -43 -3 -71 -5z"/><path d="M3955 2994 c-154 -91 -285 -171 -291 -177 -7 -7 -24 -50 -39 -97 -85 -260 -216 -498 -365 -662 -116 -128 -345 -305 -490 -378 -30 -15 -91 -40 -135 -55 -44 -16 -85 -32 -91 -37 -19 -14 -36 -88 -35 -153 1 -114 63 -232 148 -282 37 -21 56 -26 109 -24 121 3 264 51 412 138 294 172 642 576 832 968 100 206 159 392 200 633 19 114 37 292 29 292 -2 -1 -130 -75 -284 -166z"/></g></svg><b>PHPTRAVELS</b></a>
                     </div>
                  </div>
                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Parts</h3>
                     <table class="w-full text-sm text-left mb-3">
                        <thead><tr class="border-b"><th class="py-2 pr-4">Class</th><th class="py-2">Purpose</th></tr></thead>
                        <tbody class="text-gray-700">
                           <?php foreach ($ftParts as $ftClass => $ftWhat): ?>
                           <tr class="border-b"><td class="py-2 pr-4 whitespace-nowrap"><code><?= htmlspecialchars($ftClass) ?></code></td><td class="py-2"><?= htmlspecialchars($ftWhat) ?></td></tr>
                           <?php endforeach; ?>
                        </tbody>
                     </table>
                  </div>
                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Files</h3>
                     <table class="w-full text-sm text-left">
                        <tbody class="text-gray-700">
                           <?php foreach ($ftFiles as $ftFile => $ftWhat): ?>
                           <tr class="border-b"><td class="py-2 pr-4 whitespace-nowrap"><code><?= htmlspecialchars($ftFile) ?></code></td><td class="py-2"><?= htmlspecialchars($ftWhat) ?></td></tr>
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
