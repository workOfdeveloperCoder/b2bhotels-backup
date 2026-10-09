<?php
// ============================================================================
// FILE: app/routes/cruises/homeRoutes.php
// CRUISES HOME + SEARCH RESULTS (Widgety content catalogue)
// ============================================================================
@$SECURE or die('Access Denied!');

$__wgcCatalog = dirname(__DIR__, 3) . '/modules/cruises/widgety/catalog.php';
if (is_file($__wgcCatalog)) { require_once $__wgcCatalog; }
else { error_log('MODULE FILE MISSING (deployment may be incomplete) — ' . $__wgcCatalog); }

// CRUISES HOME PAGE — HERO SEARCH + UPCOMING CRUISES
$router->get('/cruises/', function () use ($SECURE, $db) {
    $filters = function_exists('_wgc_filters') ? _wgc_filters($_GET) : [];
    $featured = function_exists('_wgc_search') ? array_slice(_wgc_search($db, _wgc_filters(['sort' => 'date'])), 0, 8) : [];

    $title = T::cruises . ' ' . $GLOBALS['app']['home_title'];
    $description = '';
    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/index.php';
    require_once views . 'includes/footer.php';
});

// CRUISE SEARCH RESULTS — /cruises/search?destination=&month=&line=&length=&adults=&children=
$router->get('/cruises/search', function () use ($SECURE, $db) {
    if (!function_exists('_wgc_search')) {
        header('Location: ' . root . 'cruises');
        exit;
    }
    $filters = _wgc_filters($_GET);
    $_SESSION['cruise_search'] = $filters;

    $results = _wgc_search($db, $filters);
    $perPage = 12;
    $total = count($results);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $results = array_slice($results, ($page - 1) * $perPage, $perPage);
    $options = _wgc_options($db);

    $title = T::cruises . ' - ' . $GLOBALS['app']['home_title'];
    $description = '';
    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/listing/cruises.php';
    require_once views . 'includes/footer.php';
});
