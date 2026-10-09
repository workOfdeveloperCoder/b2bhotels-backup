<?php

// app/routes/_routes.php
@$SECURE or die('Access Denied!');

// ==================================================
// LAZY ROUTE LOADING
// Admin and API route files are only parsed when the request actually targets
// them. This avoids loading ~90 unrelated files on every public page (matters
// most where OPcache is unavailable, i.e. most shared hosting). Route grouping
// is safe: all admin files register `admin/...` paths (the only exception,
// `/sitemap.xml`, is handled below) and all api files register `/api/...`.
$__uri = $_SERVER['REQUEST_URI'] ?? '/';
if (($__qp = strpos($__uri, '?')) !== false) {
    $__uri = substr($__uri, 0, $__qp);
}
$__base = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME'] ?? ''), 0, -1)) . '/';
$__path = '/' . trim(substr($__uri, strlen($__base)), '/');

$__isAdminRequest   = ($__path === '/admin' || strpos($__path, '/admin/') === 0);
$__isApiRequest     = ($__path === '/api'   || strpos($__path, '/api/')   === 0);
$__isSitemapRequest = ($__path === '/sitemap.xml'); // public route defined in settingsRoutes

// ==================================================
// RESILIENT ROUTE LOADING
// Route files are loaded here at GLOBAL scope (this file is included at global
// scope by index.php). This is important: many route files declare top-level
// variables ($title/$header/$footer, handler closures, etc.) and rely on the
// globals $router/$db/$SECURE being in scope — exactly what a plain sequence of
// require_once at this scope provides. We iterate with a foreach (which does
// NOT create a new scope), so each require_once runs at global scope, identical
// to writing the requires out one by one. The only added behaviour is that a
// missing file is skipped and logged instead of triggering a fatal that would
// take the whole site down (as happens on a partial/failed update). We loop at
// GLOBAL scope (not via a function/closure) so route-file top-level variables
// and the framework globals behave exactly as with a plain require_once list.
// ==================================================

foreach (
    [
        'app/routes/mainRoutes.php',

        // COMPONENTS ROUTES
        'app/routes/components/dashboardRoutes.php',
        'app/routes/components/alertsNotificationsRoutes.php',
        'app/routes/components/badgesButtonsRoutes.php',
        'app/routes/components/formInputsRoutes.php',
        'app/routes/components/formSelectsRoutes.php',
        'app/routes/components/cardsUiRoutes.php',
        'app/routes/components/moduleComponentsRoutes.php',

        // CMS ROUTES
        'app/routes/cms/pageRoutes.php',

        // USERS ROUTES
        'app/routes/users/loginRoutes.php',
        'app/routes/users/signupRoutes.php',
        'app/routes/users/logoutRoutes.php',
        'app/routes/users/emailVerificationRoutes.php',
        'app/routes/users/passwordResetRoutes.php',
        'app/routes/users/userDashboardRoutes.php',
        'app/routes/users/profileRoutes.php',
        'app/routes/users/bookingsRoutes.php',
        'app/routes/users/favouritesRoutes.php',
        'app/routes/users/supportRoutes.php',

        // USERS AGENTS ROUTES
        'app/routes/users/depositRoutes.php',
        'app/routes/users/agencyRoutes.php',
        'app/routes/users/agencySignupRoutes.php',

        // FLIGHTS ROUTES
        'app/routes/flights/bookingRoutes.php',
        'app/routes/flights/invoiceRoutes.php',
        'app/routes/flights/listingRoutes.php',
        'app/routes/flights/homeRoutes.php',
        'app/routes/flights/checkoutRoutes.php',

        // STAYS ROUTES
        'app/routes/stays/bookingRoutes.php',
        'app/routes/stays/invoiceRoutes.php',
        'app/routes/stays/homeRoutes.php',
        'app/routes/stays/listingRoutes.php',
        'app/routes/stays/detailRoutes.php',
        'app/routes/stays/destinationSuggestionRoutes.php',

        // TOURS ROUTES
        'app/routes/tours/bookingRoutes.php',
        'app/routes/tours/invoiceRoutes.php',
        'app/routes/tours/homeRoutes.php',
        'app/routes/tours/listingRoutes.php',
        'app/routes/tours/detailRoutes.php',
        'app/routes/tours/destinationSuggestionRoutes.php',

        // CRUISES ROUTES
        'app/routes/cruises/bookingRoutes.php',
        'app/routes/cruises/invoiceRoutes.php',
        'app/routes/cruises/homeRoutes.php',
        'app/routes/cruises/detailRoutes.php',

        // UMRAH ROUTES
        'app/routes/umrah/homeRoutes.php',
        'app/routes/umrah/bookingRoutes.php',
        'app/routes/umrah/invoiceRoutes.php',
        'app/routes/umrah/detailRoutes.php',
        'app/routes/umrah/destinationSuggestionRoutes.php',
        'app/routes/umrah/listingRoutes.php', // Catch-all listing route should be last

        // CARS ROUTES
        'app/routes/cars/bookingRoutes.php',
        'app/routes/cars/invoiceRoutes.php',
        'app/routes/cars/mozioPaymentRoutes.php',
        'app/routes/cars/homeRoutes.php',
        'app/routes/cars/listingRoutes.php',
        'app/routes/cars/detailRoutes.php',
        'app/routes/cars/locationSuggestionRoutes.php',

        // VISA ROUTES
        'app/routes/visa/homeRoutes.php',
        'app/routes/visa/bookingRoutes.php',
        'app/routes/visa/invoiceRoutes.php',
        'app/routes/visa/uploadRoutes.php',

        // eSIM ROUTES
        'app/routes/esim/homeRoutes.php',
        'app/routes/esim/invoiceRoutes.php',

        // FERRIES ROUTES
        'app/routes/ferries/homeRoutes.php',
        'app/routes/ferries/listingRoutes.php',
        'app/routes/ferries/bookingRoutes.php',
        'app/routes/ferries/invoiceRoutes.php',

        // RAIL ROUTES
        'app/routes/rail/homeRoutes.php',
        'app/routes/rail/listingRoutes.php',
        'app/routes/rail/bookingRoutes.php',
        'app/routes/rail/invoiceRoutes.php',

        // BUS ROUTES
        'app/routes/bus/homeRoutes.php',
        'app/routes/bus/locationSuggestionRoutes.php',
        'app/routes/bus/bookingRoutes.php', // '/bus/booking/{hash}' + cache-draft + submit — BEFORE catch-all
        'app/routes/bus/invoiceRoutes.php', // '/invoice/bus/{id}'
        'app/routes/bus/listingRoutes.php', // CATCH-ALL '/bus/(.*)' — AFTER homeRoutes so '/bus/' matches first
    ] as $__rf
) {
    if (is_file($__rf)) { require_once $__rf; }
    else { error_log('ROUTES: missing route file skipped (update may be incomplete) — ' . $__rf); }
}

// ADMIN ROUTES — only parsed on admin requests (or /sitemap.xml, which lives in settingsRoutes)
if ($__isAdminRequest || $__isSitemapRequest):
    foreach (
        [
            'app/routes/admin/globalRoutes.php',
            'app/routes/admin/dashboardRoutes.php',
            'app/routes/admin/settingsRoutes.php',
            'app/routes/admin/modulesRoutes.php',
            'app/routes/admin/usersRoutes.php',
            'app/routes/admin/newsletterRoutes.php',
            'app/routes/admin/updatesRoutes.php',
            'app/routes/admin/databaseRoutes.php',
            'app/routes/admin/cmsRoutes.php',
            'app/routes/admin/creditsRoutes.php',
            'app/routes/admin/transactionsRoutes.php',
            'app/routes/admin/notificationTemplatesRoutes.php',
            'app/routes/admin/menusRoutes.php',
            'app/routes/admin/staysRoutes.php',
            'app/routes/admin/staysSettingsRoutes.php',
            'app/routes/admin/flightsRoutes.php',
            'app/routes/admin/airlinesRoutes.php',
            'app/routes/admin/airportsRoutes.php',
            'app/routes/admin/locationsRoutes.php',
            'app/routes/admin/toursRoutes.php',
            'app/routes/admin/umrahRoutes.php',
            'app/routes/admin/carsRoutes.php',
            'app/routes/admin/busRoutes.php',
            'app/routes/admin/visaRoutes.php',
            'app/routes/admin/bookingsRoutes.php',
            'app/routes/admin/gatewaysRoutes.php',
            'app/routes/admin/blogsRoutes.php',
            'app/routes/admin/reportsRoutes.php',
            'app/routes/admin/depositRoutes.php',
            'app/routes/admin/supportticketRoutes.php',
            'app/routes/admin/promoCodesRoutes.php',
        ] as $__rf
    ) {
        if (is_file($__rf)) { require_once $__rf; }
        else { error_log('ROUTES: missing admin route file skipped — ' . $__rf); }
    }
endif; // admin routes

// API ROUTES — only parsed on /api requests
if ($__isApiRequest):
    // API Key verification middleware
    $__apiKeyExempt = (
        $__path === '/api/app-settings'
        || $__path === '/api/app/settings'
    );
    if (!$__apiKeyExempt) {
        require_once 'modules/helpers.php';
        verifyApiKey($db);
    }

    foreach (
        [
            // USER API ROUTES
            'app/routes/api/index.php',
            'app/routes/api/users/loginRoutes.php',
            'app/routes/api/users/signupRoutes.php',
            'app/routes/api/users/bookingRoutes.php',
            'app/routes/api/users/dashboardRoutes.php',
            'app/routes/api/users/logoutRoutes.php',
            'app/routes/api/users/profileRoutes.php',
            'app/routes/api/users/deleteAccountRoutes.php',
            'app/routes/api/users/emailVerificationRoutes.php',
            'app/routes/api/users/passwordResetRoutes.php',
            'app/routes/api/users/changePasswordRoutes.php',
            'app/routes/api/users/agencyRoutes.php',
            'app/routes/api/users/depositRoutes.php',
            'app/routes/api/users/supportRoutes.php',
            'app/routes/api/users/favouritesRoutes.php',

            // USER AUTH ROUTES
            'app/routes/api/auth/refreshRoutes.php',

            // PAYMENT API ROUTES
            'app/routes/api/paymentRoutes.php',

            // CRON API ROUTES
            'app/routes/api/crons/creditsRemindersRoutes.php',

            // FLIGHTS API ROUTES
            'app/routes/api/flights/featuredRoutes.php',
            'app/routes/api/flights/bookingRoutes.php',
            'app/routes/api/flights/homeRoutes.php',
            'app/routes/api/flights/invoiceRoutes.php',

            // STAYS API ROUTES
            'app/routes/api/stays/bookingRoutes.php',
            'app/routes/api/stays/homeRoutes.php',
            'app/routes/api/stays/invoiceRoutes.php',
            'app/routes/api/stays/destinationSuggestionRoutes.php',

            // CARS API ROUTES
            'app/routes/api/cars/bookingRoutes.php',
            'app/routes/api/cars/invoiceRoutes.php',
            'app/routes/api/cars/featuredRoutes.php',

            // BUS API ROUTES
            'app/routes/api/bus/listingRoutes.php',
            'app/routes/api/bus/invoiceRoutes.php',
            'app/routes/api/bus/bookingRoutes.php',

            // PROMO CODE API ROUTES
            'app/routes/api/promoCodeRoutes.php',

            // COUNTRY API ROUTES
            'app/routes/api/countries/homeRoutes.php',

            // TOUR API ROUTES
            'app/routes/api/tours/featuredRoutes.php',
            'app/routes/api/tours/homeRoutes.php',
            'app/routes/api/tours/bookingRoutes.php',
            'app/routes/api/tours/invoice.php',

            // UMRAH API ROUTES
            'app/routes/api/umrah/featuredRoutes.php',
            'app/routes/api/umrah/homeRoutes.php',
            'app/routes/api/umrah/bookingRoutes.php',

            // VISA API ROUTES
            'app/routes/api/visa/homeRoutes.php',
            'app/routes/api/visa/bookingRoutes.php',
            'app/routes/api/visa/uploadRoutes.php',

            // eSIM API ROUTES
            'app/routes/api/esim/featuredRoutes.php',
            'app/routes/api/esim/packagesRoutes.php',
            'app/routes/api/esim/bookingRoutes.php',

            // FERRIES API ROUTES
            'app/routes/api/ferries/searchRoutes.php',
            'app/routes/api/ferries/bookingRoutes.php',
            'app/routes/api/ferries/invoiceRoutes.php',

            // RAIL API ROUTES
            'app/routes/api/rail/homeRoutes.php',
            'app/routes/api/rail/searchRoutes.php',
            'app/routes/api/rail/bookingRoutes.php',
            'app/routes/api/rail/invoiceRoutes.php',

            // GLOBAL API ROUTES
            'app/routes/api/globalApiRoutes.php',
            'app/routes/api/themeRoutes.php',
            'app/routes/api/blogsRoutes.php',
            'app/routes/api/contactRoutes.php',

            // CMS ROUTES
            'app/routes/api/cms/pageRoutes.php',
        ] as $__rf
    ) {
        if (is_file($__rf)) { require_once $__rf; }
        else { error_log('ROUTES: missing api route file skipped — ' . $__rf); }
    }
endif; // api routes

foreach (
    [
        // AI TRIP PLANNER (web pages + web JSON APIs — same pattern as routes/flights/)
        'app/routes/ai/tripRoutes.php',
        'app/routes/ai/searchRoutes.php',
        'app/routes/ai/locationRoutes.php', // reverse geocode for departure context (CSRF)
        'app/routes/ai/tripBookingRoutes.php',
        'app/routes/ai/visaListingRoutes.php', // AI-only visa confirm card (CSRF)

        // GLOBAL ROUTES
        'app/routes/blogsRoutes.php',
        'app/routes/globalRoutes.php',
        'app/routes/ajaxRoutes.php',
        'app/routes/newsletterRoutes.php',
        'app/routes/demo-warning-route.php',

        // PAYMENT GATEWAY WEBHOOKS (public, unauthenticated, signature-verified)
        'app/routes/paymentAdyenRoutes.php',
        'app/routes/paymentMyfatoorahRoutes.php',

        // CRON ROUTES
        'app/routes/crons/creditsRemindersRoutes.php',
        'app/routes/crons/currencyRatesRoutes.php',
        'app/routes/crons/ratehawkContentSyncRoutes.php',
    ] as $__rf
) {
    if (is_file($__rf)) { require_once $__rf; }
    else { error_log('ROUTES: missing route file skipped (update may be incomplete) — ' . $__rf); }
}
