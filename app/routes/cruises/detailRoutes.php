<?php
// ============================================================================
// FILE: app/routes/cruises/detailRoutes.php
// CRUISE DETAIL — /cruise/{group-key}/{slug}?date={date_ref}&adults=&children=
// One trip with all of its upcoming sailings and cabin prices.
// ============================================================================
@$SECURE or die('Access Denied!');

$__wgcCatalog = dirname(__DIR__, 3) . '/modules/cruises/widgety/catalog.php';
if (is_file($__wgcCatalog)) { require_once $__wgcCatalog; }

$router->get('/cruise/([a-f0-9]{12})(?:/[A-Za-z0-9\-]*)?', function ($key) use ($SECURE, $db) {
    $cruise = function_exists('_wgc_group') ? _wgc_group($db, $key) : null;
    if (!$cruise) {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'This cruise is no longer available.'];
        header('Location: ' . root . 'cruises');
        exit;
    }

    $filters = _wgc_filters($_GET + ($_SESSION['cruise_search'] ?? []));
    $selectedRef = (string) ($_GET['date'] ?? '');
    if (!in_array($selectedRef, array_column($cruise['sailings'], 'date_ref'), true)) {
        $selectedRef = $cruise['sailings'][0]['date_ref'];
    }

    $title = $cruise['name'] . ' - ' . $GLOBALS['app']['home_title'];
    $description = $cruise['nights'] . ' night cruise with ' . $cruise['operator_name'];
    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/details/cruise.php';
    require_once views . 'includes/footer.php';
});
