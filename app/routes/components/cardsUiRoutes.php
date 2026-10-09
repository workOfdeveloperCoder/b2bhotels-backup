<?php
// FILE: app/routes/components/cards-ui.php
// Card and UI element component routes

@$SECURE or die('Access Denied!');

// ====================================
// CARDS COMPONENTS
// ====================================

$router->get('/components/cards', function () use ($SECURE, $db) {
    $title = "Card Components";
    $description = "Card layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/cards.php";
    require_once views."includes/footer.php";
});

// ====================================
// LINKS COMPONENTS
// ====================================

$router->get('/components/links', function () use ($SECURE, $db) {
    $title = "links Components";
    $description = "Links layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/links.php";
    require_once views."includes/footer.php";
});

// ====================================
// HEADER COMPONENTS
// ====================================

$router->get('/components/header', function () use ($SECURE, $db) {
    $title = "Header Components";
    $description = "Site header parts: links, chips, groups, menus and icons";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/header.php";
    require_once views."includes/footer.php";
});

// ====================================
// HERO COMPONENTS
// ====================================

$router->get('/components/hero', function () use ($SECURE, $db) {
    $title = "Hero Components";
    $description = "Home hero parts: tabs, search card, switch, fields and shortcuts";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/hero.php";
    require_once views."includes/footer.php";
});

// ====================================
// FEATURED COMPONENTS
// ====================================

$router->get('/components/featured', function () use ($SECURE, $db) {
    $title = "Featured Components";
    $description = "Featured section of the home page: heading, sliding row of cards, card parts";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/featured.php";
    require_once views."includes/footer.php";
});

// ====================================
// LANDING COMPONENTS
// ====================================

$router->get('/components/mobile-app', function () use ($SECURE, $db) {
    $title = "Mobile App Components";
    $description = "The get-the-app section: words, store badges, phones drawn in CSS, numbered reasons";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/mobile-app.php";
    require_once views."includes/footer.php";
});

$router->get('/components/tripadvisor', function () use ($SECURE, $db) {
    $title = "Tripadvisor Components";
    $description = "Tripadvisor ratings and reviews card of the hotel details page";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/tripadvisor.php";
    require_once views."includes/footer.php";
});

$router->get('/components/footer', function () use ($SECURE, $db) {
    $title = "Footer Components";
    $description = "The footer: brand and contact, the CMS menus, follow and the app, the bottom bar";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/footer.php";
    require_once views."includes/footer.php";
});

$router->get('/components/landing', function () use ($SECURE, $db) {
    $title = "Landing Components";
    $description = "Page of a service: top card, search box, catalog of destinations, steps, reasons";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/landing.php";
    require_once views."includes/footer.php";
});
