<?php
// ============================================================================
// HERO / DATA
// Turns the active modules (prepared at the top of home.php as $mods) into:
//   $hero['tabs']       modules that search from the hero  (Flights, Stays, ...)
//   $hero['shortcuts']  services that open their own page  (Visas, eSIM, ...)
// To move a module between the two, change HERO_SHORTCUT_TYPES below.
// ============================================================================
@$SECURE or die('Access Denied!');

// Modules shown under the card instead of as a tab, in this order.
// 'module' opens the page of a module; 'url' opens that address of the site, always;
// 'page' opens a CMS page (slug) when that page exists and is published. An item with
// none of them, or whose page is missing, is shown like the others and gets its link
// once there is something to open.
// 'soon' => true adds a small "Soon" label.
$heroShortcutPlan = [
    ['key' => 'group_tours',      'label' => T::group_tours,      'icon' => 'bag',    'url' => 'page/group-tours'],
    ['key' => 'visa',             'label' => T::travel_visas,     'icon' => 'globe',  'module' => 'visa'],
    ['key' => 'travel_insurance', 'label' => T::travel_insurance, 'icon' => 'shield', 'page' => 'travel-insurance'],
    ['key' => 'esim',             'label' => 'eSIM',              'icon' => 'esim',   'module' => 'esim'],
];
$heroShortcutTypes = array_values(array_filter(array_column($heroShortcutPlan, 'module')));

// Filled Material Symbol for each tab.
$heroTabIcons = [
    'flights' => 'flight', 'stays' => 'apartment', 'tours' => 'explore', 'cars' => 'directions_car',
    'cruises' => 'directions_boat', 'bus' => 'directions_bus', 'rail' => 'train', 'ferries' => 'sailing',
    'umrah' => 'mosque', 'visa' => 'approval', 'esim' => 'sim_card',
];
$heroTabLabels = [
    'flights' => T::flights, 'stays' => T::stays, 'tours' => T::tours, 'cars' => T::cars,
    'cruises' => T::cruises, 'bus' => T::bus, 'rail' => T::rail, 'ferries' => T::ferries,
    'umrah' => T::umrah, 'visa' => T::visa, 'esim' => 'eSIM',
];

$heroActiveTypes = array_column($mods ?? [], 'type');

$heroTabs = [];
foreach ($mods ?? [] as $module) {
    $type = $module['type'];
    if (in_array($type, $heroShortcutTypes, true)) {
        continue;
    }
    // A module may carry its own Material Symbol name; supplier logo files are admin-only.
    $icon = trim((string) ($module['icon'] ?? ''));
    if ($icon === '' || preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $icon) || !preg_match('/^[a-z0-9_]+$/', $icon)) {
        $icon = $heroTabIcons[$type] ?? 'confirmation_number';
    }
    $heroTabs[] = [
        'type'  => $type,
        'label' => isset($heroTabLabels[$type]) ? (string) $heroTabLabels[$type] : ucfirst($type),
        'icon'  => $icon,
    ];
}

// Only shortcut modules are active (e.g. a visa-only site): they become the tabs,
// otherwise the hero would have no search form at all.
if (empty($heroTabs)) {
    foreach ($mods ?? [] as $module) {
        $type = $module['type'];
        $heroTabs[] = [
            'type'  => $type,
            'label' => isset($heroTabLabels[$type]) ? (string) $heroTabLabels[$type] : ucfirst($type),
            'icon'  => $heroTabIcons[$type] ?? 'confirmation_number',
        ];
    }
    $heroShortcutTypes = [];
}

$heroShortcuts = [];
foreach ($heroShortcutPlan as $item) {
    if (empty($item['module'])) {
        $href = !empty($item['url']) ? root . ltrim((string) $item['url'], '/') : '';
        if ($href === '' && !empty($item['page']) && isset($db)) {
            $slug = $db->get('cms', 'slug_url', ['slug_url' => $item['page'], 'status' => 1]);
            $href = $slug ? root . 'page/' . $slug : '';
        }
        $heroShortcuts[] = $item + ['href' => $href, 'soon' => false];
        continue;
    }
    if (in_array($item['module'], $heroActiveTypes, true) && in_array($item['module'], $heroShortcutTypes, true)) {
        $heroShortcuts[] = $item + ['href' => root . $item['module'], 'soon' => false];
    }
}

// Flights and Stays lead, the rest keep the order they already had.
$heroTabOrder = array_flip(['flights', 'stays', 'tours', 'cars', 'cruises']);
foreach ($heroTabs as $i => &$heroTab) {
    $heroTab['sort'] = [$heroTabOrder[$heroTab['type']] ?? 99, $i];
}
unset($heroTab);
usort($heroTabs, fn($a, $b) => $a['sort'] <=> $b['sort']);

$heroTabTypes = array_column($heroTabs, 'type');

// Look of the tabs: 'compact' (icon and label on one line) or 'stacked' (icon above the label).
$heroTabStyle = 'compact';
$heroInitial  = $heroTabTypes[0] ?? '';
$heroFormFile = $heroInitial !== '' ? views . 'modules/' . $heroInitial . '/' . $heroInitial . '-search.php' : '';

$hero = [
    'tabs'      => $heroTabs,
    'types'     => $heroTabTypes,
    'initial'   => $heroInitial,
    // the first form is printed with the page, so it is there at first paint
    'formFile'  => ($heroFormFile !== '' && is_file($heroFormFile)) ? $heroFormFile : '',
    // the next ones are fetched quietly once the page is idle; the rest when a tab is pointed at
    'preload'   => array_slice($heroTabTypes, 1, 2),
    'shortcuts' => $heroShortcuts,
    'ai'        => !empty($aiSearchEnabled),
    // look of the tabs: 'compact' = icon and label on one line (low tabs),
    //                   'stacked' = icon above the label (tall tabs)
    'tabStyle'  => $heroTabStyle,
    // forms that start with a row of options (trip type ...): their skeleton has that row too
    'withOptions' => ['flights', 'bus', 'ferries', 'cars'],
    'title'     => T::hero_text1,
    'sub'       => T::hero_text2,
    'badges'    => [
        ['icon' => 'search', 'text' => T::hero_badge_search],
        ['icon' => 'bolt',   'text' => T::hero_badge_instant],
        ['icon' => 'tag',    'text' => T::hero_badge_price],
    ],
];
