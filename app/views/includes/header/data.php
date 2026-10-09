<?php
// ============================================================================
// HEADER / DATA
// Everything the header components need, prepared once. Components only print.
// Sets: $hd (array) plus the session currency keys the rest of the app reads.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('flagAssetPath')) {
  function flagAssetPath($flagCode) {
    static $availableFlags = null;

    if ($availableFlags === null) {
      $availableFlags = [];
      $flagsDir = __DIR__ . '/../../../../assets/img/flags';
      foreach (glob($flagsDir . '/*.svg') as $flagFile) {
        $availableFlags[basename($flagFile, '.svg')] = true;
      }
    }

    $code = strtolower(trim((string)$flagCode));
    if ($code !== '' && isset($availableFlags[$code])) {
      return root . "assets/img/flags/{$code}.svg";
    }

    return root . 'assets/img/flags/xx.svg';
  }
}

$currentLang  = $_SESSION['app_language'] ?? 'en';
$assetVersion = rawurlencode((string)($GLOBALS['app']['version'] ?? '1.0.0'));

// ADMIN SIDEBAR FIRST-PAINT STATE: THE SIDEBAR TOGGLE PERSISTS ITS STATE IN A
// COOKIE (SERVER-READABLE, UNLIKE localStorage) SO PHP RENDERS THE CORRECT BODY
// OFFSET AND RAIL WIDTH IN THE VERY FIRST PAINT — NO LAYOUT JUMP AT ANY POINT.
$isAdminSidebar   = (($_SESSION['user_role'] ?? '') === 'admin');
$sidebarExpanded  = $isAdminSidebar && (($_COOKIE['pt_sidebar'] ?? '') === 'expanded');
$sidebarBodyClass = $isAdminSidebar ? ($sidebarExpanded ? ' sidebar-open' : ' sidebar-closed') : '';
// ADMIN PAGE = AN ADMIN ON A URL UNDER /admin (THE SIDEBAR ALSO SHOWS ON THE WEBSITE FOR ADMINS)
$isAdminPage = $isAdminSidebar && (bool) preg_match('#/' . preg_quote(admin, '#') . '(/|$)#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

// ---------------------------------------------------------------- CMS PAGES
// Parents with children become dropdown menus, the rest are single links.
$headerPages = cms($db, "header", "headerfooter");
$header_menus = [];
$header_standalone = [];
$children_map = [];

foreach ($headerPages as $hdPage) {
    if ($hdPage['parent_id']) {
        $children_map[$hdPage['parent_id']][] = $hdPage;
    }
}
$hdPageHref = function (array $hdPage): array {
    $hdExternal = !empty($hdPage['external_url']);
    return [
        'href'     => $hdExternal ? (string) $hdPage['external_url'] : root . 'page/' . $hdPage['slug_url'],
        'external' => $hdExternal,
    ];
};
foreach ($headerPages as $hdPage) {
    if ($hdPage['parent_id']) {
        continue;
    }
    $hdKey  = strtolower(trim((string)(($hdPage['slug_url'] ?? '') ?: ($hdPage['page_name'] ?? ''))));
    $hdItem = [
        'label' => getTranslatedPageName($hdPage, $currentLang),
        'icon'  => headerIconFor($hdKey, !empty($children_map[$hdPage['id']])),
    ] + $hdPageHref($hdPage);

    if (!empty($children_map[$hdPage['id']])) {
        $hdItem['children'] = array_map(fn($hdChild) => [
            'label' => getTranslatedPageName($hdChild, $currentLang),
        ] + $hdPageHref($hdChild), $children_map[$hdPage['id']]);
        $header_menus[] = $hdItem;
    } else {
        $header_standalone[] = $hdItem;
    }
}

// ---------------------------------------------------------------- SERVICES (ACTIVE MODULES)
$processedServices = [];
if (!empty($GLOBALS['modules'])) {
    $hdGrouped = array_reduce($GLOBALS['modules'], function ($c, $m) {
        $c[$m['type']] = $c[$m['type']] ?? ['type' => $m['type'], 'order' => $m['order'], 'icon' => $m['icon'] ?? ''];
        return $c;
    }, []);
    $processedServices = array_values($hdGrouped);
    usort($processedServices, fn($a, $b) => $a['order'] <=> $b['order']);

    // MATERIAL SYMBOL FALLBACKS PER MODULE TYPE — same icons the hero search
    // tabs show when a module row has no icon of its own.
    $serviceIconMap = [
        'flights' => 'flight_takeoff', 'stays' => 'hotel', 'cars' => 'directions_car',
        'tours' => 'explore', 'visa' => 'approval', 'cruises' => 'directions_boat',
        'umrah' => 'mosque', 'rail' => 'train', 'bus' => 'directions_bus', 'esim' => 'sim_card',
    ];
    $serviceLabels = [
        'flights' => T::flights, 'stays' => T::stays, 'cars' => T::cars, 'visa' => T::visa,
        'tours' => T::tours, 'cruises' => T::cruises, 'umrah' => T::umrah, 'rail' => T::rail,
        'bus' => T::bus, 'ferries' => T::ferries, 'esim' => T::esim,
    ];

    foreach ($processedServices as &$hdSvc) {
        $hdType = $hdSvc['type'];
        // Supplier logo filenames are admin-only — fall back to the type map.
        $hdIcon = trim((string) $hdSvc['icon']);
        if ($hdIcon === '' || preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $hdIcon)) {
            $hdIcon = $serviceIconMap[$hdType] ?? 'confirmation_number';
        }
        $hdSvc['icon']  = $hdIcon;
        $hdSvc['label'] = isset($serviceLabels[$hdType]) ? (string) $serviceLabels[$hdType] : ucwords((string) $hdType);
        $hdSvc['href']  = root . $hdType;
    }
    unset($hdSvc);
}

// ---------------------------------------------------------------- CURRENCY
// Use the session currency when it is still valid, else the default one.
$currencies = $GLOBALS['currencies'] ?? [];
$sessionCurrencyCode = !empty($_SESSION['app_currency_changed'])
  ? strtoupper(trim((string)($_SESSION['app_currency'] ?? '')))
  : '';

$sessionCurrencyRow = null;
$defaultCurrencyRow = null;
foreach ($currencies as $hdCurr) {
    $hdCurrencyCode = strtoupper((string)($hdCurr['name'] ?? ''));
    if ($hdCurrencyCode === '') {
        continue;
    }
    if ($sessionCurrencyCode !== '' && $hdCurrencyCode === $sessionCurrencyCode) {
        $sessionCurrencyRow = $hdCurr;
    }
    if ($defaultCurrencyRow === null && (string)($hdCurr['default'] ?? '0') === '1') {
        $defaultCurrencyRow = $hdCurr;
    }
}

$activeCurrencyRow  = $sessionCurrencyRow ?? $defaultCurrencyRow ?? ($currencies[0] ?? null);
$activeCurrencyCode = strtoupper((string)($activeCurrencyRow['name'] ?? 'USD'));
$activeCurrencyFlag = !empty($activeCurrencyRow['country']) ? strtolower($activeCurrencyRow['country']) : 'xx';

if (!empty($activeCurrencyRow)) {
    $_SESSION['app_currency'] = $activeCurrencyCode;
    $_SESSION['app_currency_rate'] = $activeCurrencyRow['rate'] ?? ($_SESSION['app_currency_rate'] ?? 1);
    $_SESSION['app_currency_country'] = $activeCurrencyRow['country'] ?? ($_SESSION['app_currency_country'] ?? '');
    $_SESSION['app_currency_country_name'] = $activeCurrencyRow['country_name'] ?? ($_SESSION['app_currency_country_name'] ?? '');
}

// ---------------------------------------------------------------- LANGUAGE
$currentLangName = 'English';
$currentLangFlag = 'xx';
foreach ($GLOBALS['languages'] ?? [] as $hdLang) {
    if ($hdLang['lang_code'] === $currentLang) {
        $currentLangName = $hdLang['name'];
        $currentLangFlag = !empty($hdLang['country']) ? strtolower($hdLang['country']) : 'xx';
        break;
    }
}

// ---------------------------------------------------------------- ACCOUNT + SUPPORT
$isLoggedIn = !empty($_SESSION['user_id']);

$supportHref = '';
if ($isLoggedIn) {
    $supportHref = root . 'support/tickets';
} elseif ($db->has('cms', ['slug_url' => 'contact-us'])) {
    $supportHref = root . 'page/contact-us';
}

$hd = [
    'overlay'    => !empty($headerOverlay),          // page opts in: header sits on top of a hero image
    'menus'      => $header_menus,
    'links'      => $header_standalone,
    'services'   => $processedServices,
    'showLang'   => plan_allows('multi_language') && ($GLOBALS['app']['multi_language'] ?? '1') != '0',
    'showCurr'   => plan_allows('multi_currency') && ($GLOBALS['app']['multi_currency'] ?? '1') != '0',
    'currency'   => $activeCurrencyCode,
    'langCode'   => $currentLang,
    'langName'   => $currentLangName,
    'langFlag'   => $currentLangFlag,
    'loggedIn'   => $isLoggedIn,
    'isAdmin'    => ($_SESSION['user_role'] ?? '') === 'admin',
    'userName'   => (string) ($_SESSION['user_name'] ?? 'User'),
    'userRole'   => (string) ($_SESSION['user_role'] ?? 'Member'),
    'showSignup' => ($GLOBALS['app']['user_registration'] ?? '1') != '0',
    'agentReg'   => ($GLOBALS['app']['agent_registration'] ?? 0) != 0,
    'loginHref'  => root . 'login',
    'support'    => $supportHref,
    'brand'      => (string) ($GLOBALS['app']['business_name'] ?? ''),
];
