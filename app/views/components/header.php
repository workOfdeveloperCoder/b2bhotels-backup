<?php
// COMPONENT LIBRARY — HEADER
// Live examples of every header component, on a light bar and on a hero image.
// Classes are defined in app/views/includes/header/styles.php.
@$SECURE or die('Access Denied!');

$hdExamples = [
    'hd-link' => [
        'title' => 'Link',
        'note'  => 'Text link or menu trigger. Used for Services, CMS pages, Support and Login.',
        'html'  => '<a href="#" class="hd-link">' . headerIcon('headset', 17) . '<span>Support</span></a>'
                 . '<button type="button" class="hd-link">' . headerIcon('grid') . '<span>Services</span>' . headerIcon('chevron', 12, 'hd-chevron') . '</button>',
        'code'  => "<a href=\"...\" class=\"hd-link\">\n  <?= headerIcon('headset', 17) ?>\n  <span>Support</span>\n</a>",
    ],
    'hd-chip' => [
        'title' => 'Chip',
        'note'  => 'One small control with a border. Used for the account button.',
        'html'  => '<button type="button" class="hd-chip">' . headerIcon('coins') . '<span>USD</span>' . headerIcon('chevron', 12, 'hd-chevron') . '</button>'
                 . '<button type="button" class="hd-chip"><span class="hd-avatar">Q</span><span>Qasim</span>' . headerIcon('chevron', 12, 'hd-chevron') . '</button>',
        'code'  => "<button type=\"button\" class=\"hd-chip\">\n  <?= headerIcon('coins') ?>\n  <span>USD</span>\n  <?= headerIcon('chevron', 12, 'hd-chevron') ?>\n</button>",
    ],
    'hd-group' => [
        'title' => 'Group',
        'note'  => 'Several chips that share one pill. Used for currency + language.',
        'html'  => '<div class="hd-group"><button type="button" class="hd-chip">' . headerIcon('coins') . '<span>USD</span>' . headerIcon('chevron', 12, 'hd-chevron') . '</button>'
                 . '<button type="button" class="hd-chip">' . headerIcon('languages') . '<span>EN</span>' . headerIcon('chevron', 12, 'hd-chevron') . '</button></div>',
        'code'  => "<div class=\"hd-group\">\n  <button type=\"button\" class=\"hd-chip\">...</button>\n  <button type=\"button\" class=\"hd-chip\">...</button>\n</div>",
    ],
    'btn' => [
        'title' => 'Action button',
        'note'  => 'The header has no button of its own: it uses the design-system .btn, so height, colour and radius follow the theme. Links, chips and the pill take the same height.',
        'html'  => '<a href="#" class="btn">' . headerIcon('user-plus') . '<span>Signup</span></a>'
                 . '<a href="#" class="btn light">' . headerIcon('login') . '<span>Login</span></a>',
        'code'  => "<a href=\"...\" class=\"btn\">\n  <?= headerIcon('user-plus') ?>\n  <span>Signup</span>\n</a>\n\n<a href=\"...\" class=\"btn light\">...</a>",
    ],
    'hd-icon-btn' => [
        'title' => 'Icon button + divider',
        'note'  => 'Square button for a single icon (mobile menu). The divider separates groups of controls.',
        'html'  => '<button type="button" class="hd-icon-btn" aria-label="Menu">' . headerIcon('menu', 20) . '</button><span class="hd-divider"></span>'
                 . '<button type="button" class="hd-icon-btn" aria-label="Close">' . headerIcon('close', 20) . '</button>',
        'code'  => "<button type=\"button\" class=\"hd-icon-btn\" aria-label=\"Menu\">\n  <?= headerIcon('menu', 20) ?>\n</button>\n<span class=\"hd-divider\"></span>",
    ],
];

$hdMenuExample = '<div class="hd-menu-label">Currency</div>'
    . '<a href="#" class="hd-menu-item is-active"><img src="' . root . 'assets/img/flags/us.svg" alt=""><span class="hd-menu-text">United States</span><span class="hd-menu-meta">USD</span><span class="material-symbols-outlined hd-menu-check">check_circle</span></a>'
    . '<a href="#" class="hd-menu-item"><img src="' . root . 'assets/img/flags/sa.svg" alt=""><span class="hd-menu-text">Saudi Arabia</span><span class="hd-menu-meta">SAR</span></a>'
    . '<div class="hd-menu-sep"></div>'
    . headerMenuItem(['label' => 'Dashboard', 'href' => '#', 'icon' => 'dashboard'])
    . headerMenuItem(['label' => 'Flights Booking', 'href' => '#', 'symbol' => 'flight_takeoff'])
    . headerMenuItem(['label' => 'Logout', 'href' => '#', 'icon' => 'logout', 'danger' => true]);

$hdIcons = ['grid', 'building', 'article', 'info', 'phone', 'headset', 'file', 'folder', 'coins', 'languages', 'login', 'logout',
            'user', 'user-plus', 'briefcase', 'dashboard', 'calendar', 'settings', 'key', 'menu', 'close', 'check', 'chevron'];
?>
<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-2">Header Components</h1>
               <p class="text-gray-600 mb-6">The site header is built from small parts in <code class="text-sm">app/views/includes/header/</code>. Each part below is shown on a normal bar and on a hero image, because the header uses both.</p>

               <div class="space-y-8">

                  <?php foreach ($hdExamples as $class => $ex): ?>
                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($ex['title']) ?> <code class="text-sm font-normal text-blue-700">.<?= $class ?></code></h3>
                     <p class="text-sm text-gray-600 mb-3"><?= htmlspecialchars($ex['note']) ?></p>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div class="site-header" style="position:static;height:auto;border:1px solid #e5e5e5;border-radius:12px;box-shadow:none">
                           <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:16px"><?= $ex['html'] ?></div>
                        </div>
                        <div class="site-header is-overlay" style="position:static;height:auto;margin:0;border-radius:12px;background:linear-gradient(135deg,#1e3a8a,#0f172a)">
                           <div style="position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:16px"><?= $ex['html'] ?></div>
                        </div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars($ex['code']) ?></code></pre>
                  </div>
                  <?php endforeach; ?>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Menu <code class="text-sm font-normal text-blue-700">.hd-drop + .hd-menu</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Dropdown panel. Rows come from <code>headerMenuItem()</code> so they look the same on desktop and mobile. Add <code>is-end</code> to open from the right edge.</p>
                     <div class="mb-3" style="padding:16px;border:1px solid #e5e5e5;border-radius:12px;background:#fafafa">
                        <div class="hd-drop is-open" style="display:block"><div class="hd-menu" style="position:static;max-width:260px"><?= $hdMenuExample ?></div></div>
                     </div>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("<div class=\"hd-drop\" x-data=\"{ open: false }\" @click.outside=\"open = false\">\n  <button type=\"button\" class=\"hd-chip\" @click=\"open = !open\" :aria-expanded=\"open\">...</button>\n  <div class=\"hd-menu is-end\" x-show=\"open\" style=\"display:none\">\n    <div class=\"hd-menu-label\">Currency</div>\n    <?= headerMenuItem(['label' => 'Dashboard', 'href' => root . 'dashboard', 'icon' => 'dashboard']) ?>\n    <?= headerMenuItem(['label' => 'Flights', 'href' => root . 'flights', 'symbol' => 'flight_takeoff']) ?>\n    <div class=\"hd-menu-sep\"></div>\n    <?= headerMenuItem(['label' => 'Logout', 'href' => root . 'logout', 'icon' => 'logout', 'danger' => true]) ?>\n  </div>\n</div>") ?></code></pre>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Icons <code class="text-sm font-normal text-blue-700">headerIcon('name', size)</code></h3>
                     <p class="text-sm text-gray-600 mb-3">Line icons that take the colour of the text around them.</p>
                     <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                        <?php foreach ($hdIcons as $name): ?>
                        <div class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-xs text-gray-700"><?= headerIcon($name, 18) ?><span><?= $name ?></span></div>
                        <?php endforeach; ?>
                     </div>
                  </div>

                  <div class="border-l-4 border-blue-500 pl-4">
                     <h3 class="text-lg font-semibold text-gray-900">Header on a hero image</h3>
                     <p class="text-sm text-gray-600 mb-3">A page that starts with a hero image sets one variable in its route. The bar is then clear on top of the image and turns solid when the page scrolls.</p>
                     <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto"><code><?= htmlspecialchars("\$headerOverlay = true;\nrequire_once views . \"includes/header.php\";") ?></code></pre>
                  </div>

               </div>
            </div>
         </div>
      </div>
   </div>
</div>
