<?php
// THEMES/DEFAULT/INCLUDES/HEADER.PHP
// The header is built from small components in includes/header/:
//   data.php      prepares everything once ($hd)
//   icons.php     line icons            helpers.php  shared menu rows
//   styles.php    component CSS         script.php   behaviour (Alpine)
//   logo.php  nav.php  switcher.php  account.php  mobile.php
// A page that starts with a hero image can set $headerOverlay = true before
// including this file: the bar then sits on the image until the page scrolls.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/header/icons.php';
require_once __DIR__ . '/header/helpers.php';
require __DIR__ . '/header/data.php';
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['app_language'] ?? 'en' ?>" dir="<?= $_SESSION['app_language_dir'] ?? 'ltr' ?>">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?=CSRF::getToken()?>">
    <link rel="shortcut icon" href="<?=versionedAssetUrl('uploads/global/favicon.png')?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://code.jquery.com">

    <script>document.documentElement.classList.add('js');</script>

    <!-- CRITICAL FIRST-PAINT CSS (OVERLAY-ONLY LOADER, NEVER HIDE BODY) -->
    <style>
      /* LOCK SCROLL ONLY WHILE THE LOADER OVERLAY IS VISIBLE (AUTO-RELEASES ON FADE) */
      html:has(.page-loader:not(.hide)) { overflow: hidden; }
      .page-loader { position: fixed; inset: 0; background: #fff; z-index: 999999; display: flex; align-items: center; justify-content: center; opacity: 1; transition: opacity .18s ease-out; will-change: opacity; }
      .page-loader.hide { opacity: 0; pointer-events: none; }
      /* SUBTLE, SMOOTH SCROLLBAR ONCE THE PAGE IS INTERACTIVE */
      html { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
      ::-webkit-scrollbar { width: 10px; height: 10px; }
      ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; border: 2px solid transparent; background-clip: content-box; transition: background .2s ease; }
      ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }
      ::-webkit-scrollbar-track { background: transparent; }
      /* HEADER NAV — THEME-AWARE HOVER WITHOUT INLINE JS */
      .nav-link { color: var(--header-link-color); transition: color .2s ease, background-color .2s ease; }
      .nav-link:hover { color: var(--header-link-hover-color); }
      .nav-link svg { stroke: currentColor; transition: stroke .2s ease; }
      <?php if ($isAdminSidebar): ?>
      /* ADMIN SIDEBAR LAYOUT — CRITICAL: THE BODY CLASS IS PRINTED SERVER-SIDE,
         SO THE CONTENT OFFSET IS CORRECT FROM THE VERY FIRST PAINT */
      @media (min-width: 1024px) {
        body.sidebar-open { padding-left: 260px; }
        body.sidebar-closed { padding-left: 64px; }
        body.sidebar-open header,
        body.sidebar-closed header { transition: none !important; }
      }
      @media (max-width: 1023px) {
        body.sidebar-open, body.sidebar-closed { padding-left: 0; }
      }
      /* MUST MATCH THE RAIL EXACTLY (transition-all duration-200 ease-linear = 0.2s linear) */
      body { transition: padding-left 0.2s linear; }
      <?php endif; ?>
    </style>

    <link rel="preload" href="<?= root ?>assets/fonts/ibm-plex-sans-arabic-400-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= root ?>assets/fonts/ibm-plex-sans-arabic-600-latin.woff2" as="font" type="font/woff2" crossorigin>

    <?php require_once "app/views/tailwind.php"; ?>
    <?php require __DIR__ . '/header/styles.php'; ?>
    <?php require __DIR__ . '/header/script.php'; ?>

    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <script>
      try { if (window.__APP_TAILWIND_CONFIG__) tailwind.config = window.__APP_TAILWIND_CONFIG__; } catch(e) {}
    </script>

    <script defer src="<?=root?>assets/js/tailwind.js?v=<?=$assetVersion?>"></script>
    <script defer src="<?=versionedAssetUrl('assets/js/app.js')?>"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0..1,0" rel="stylesheet">
    <!-- plugins load before Alpine itself -->
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://code.jquery.com/jquery-3.7.1.min.js" data-cfasync="false"></script>
    <link rel="stylesheet" href="<?=versionedAssetUrl('assets/css/app.css')?>">

    <!-- META -->
    <title><?= htmlspecialchars($title ?? '') ?></title>
    <meta name="description" content="<?= htmlspecialchars($description ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($keywords ?? '') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonical ?? ((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])) ?>">
    <meta name="author" content="<?=$GLOBALS['app']['business_name']?>">
    <meta property="og:title" content="<?= htmlspecialchars($title ?? '') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description ?? '') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical ?? ((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])) ?>">
    <meta property="og:image" content="<?=versionedAssetUrl('uploads/global/cover.png')?>">
    <meta property="og:site_name" content="<?=$GLOBALS['app']['business_name']?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title ?? '') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description ?? '') ?>">
    <meta name="twitter:image" content="<?=versionedAssetUrl('uploads/global/cover.png')?>">

  </head>
  <body class="bg-background text-foreground<?= $sidebarBodyClass ?><?= $isAdminPage ? ' ad-page' : '' ?>">

    <?php
    // Flash set by a route that had to correct the request (e.g. a hand-typed
    // stay URL longer than the portal's maximum stay length).
    if (!empty($_SESSION['stays_search_notice'])) {
        $staysSearchNotice = $_SESSION['stays_search_notice'];
        unset($_SESSION['stays_search_notice']);
        if ($staysSearchNotice === 'max_stay_nights') {
            $staysNoticeText = str_replace(
                '{nights}',
                (string) (function_exists('staysMaxStayNights') ? staysMaxStayNights() : 30),
                T::max_stay_nights_exceeded
            );
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.vt && typeof window.vt.warn === 'function') {
                        window.vt.warn(<?= json_encode($staysNoticeText) ?>);
                    }
                });
            </script>
            <?php
        }
    }
    ?>

    <!-- PAGE LOADER (RENDERED IMMEDIATELY, FADES OUT BY APP.JS ONCE FULLY LOADED) -->
    <!-- <div id="page-loader" class="page-loader">
      <?php // include __DIR__ . '/loader.php'; ?>
    </div> -->

    <?php if (($header ?? true) !== false) { ?>
    <header class="site-header<?= $hd['overlay'] ? ' is-overlay' : '' ?>" x-data="siteHeader('<?= root ?>')" :class="{ 'is-scrolled': scrolled, 'is-open': mobile }">
      <div class="hd-inner" x-ref="inner">

        <div class="hd-side">
          <?php include __DIR__ . '/header/logo.php'; ?>
          <?php include __DIR__ . '/header/nav.php'; ?>
        </div>

        <div class="hd-side">
          <div class="hd-desktop">
            <?php include __DIR__ . '/header/switcher.php'; ?>
            <?php if ($hd['showCurr'] || $hd['showLang']): ?><span class="hd-divider"></span><?php endif; ?>
            <?php include __DIR__ . '/header/account.php'; ?>
          </div>

          <button type="button" class="hd-icon-btn hd-mobile-only" @click="mobile = !mobile" :aria-expanded="mobile" aria-label="<?= htmlspecialchars(T::menu) ?>">
            <span x-show="!mobile"><?= headerIcon('menu', 20) ?></span>
            <span x-show="mobile" style="display:none"><?= headerIcon('close', 20) ?></span>
          </button>
        </div>

      </div>

      <?php include __DIR__ . '/header/mobile.php'; ?>
    </header>
    <?php } ?>

    <?php
    include "app/views/admin/sidebar.php";

    // ADMIN PAGES: THE ADMIN THEME (design system) — SCOPED BY THE BODY CLASS ad-page, NEVER
    // THE SITE HEADER, FOOTER OR SIDEBAR (admin/theme/styles.php).
    if ($isAdminPage) {
        require views . 'admin/theme/styles.php';
    }
    ?>