<?php
// ============================================================================
// WIDGETY CRUISES SUPPLIER — MODULE ENTRY POINT
// Loaded by modules/index.php. Registers all Widgety endpoints on the module
// sub-app router. Endpoint files are guarded so a partial deploy never fatals.
// ============================================================================
require_once __DIR__ . '/api.php';                               // CLIENT HELPERS + CONTENT DB

if (file_exists(__DIR__ . '/creds.php'))            require_once __DIR__ . '/creds.php';            // POST cruises/widgety/creds
if (file_exists(__DIR__ . '/content/content.php'))  require_once __DIR__ . '/content/content.php';  // POST content_import + GET stats
if (file_exists(__DIR__ . '/search.php'))           require_once __DIR__ . '/search.php';           // POST cruises/widgety/search
if (file_exists(__DIR__ . '/details.php'))          require_once __DIR__ . '/details.php';          // POST cruises/widgety/details
