<?php
// ============================================================================
// GRNCONNECT IN-APP DISPATCHER (live main-app fallback)
// ============================================================================
// Used when /modules/stays/grnconnect/{endpoint} is handled by
// app/routes/globalRoutes.php instead of modules/index.php.
//
// Why: requiring modules/index.php from the main app fatals on
// "Cannot redeclare MARKUP()" (modules/helpers.php vs app/lib/functions.php)
// and produces HTTP 500 with an almost-empty body (~3 bytes on live).
// ============================================================================

if (!function_exists('grnconnect_dispatch_in_app')) {
    /**
     * @param string $endpoint e.g. search | destination-meta | rooms | creds
     * @param mixed  $db       Main-app Medoo instance
     */
    function grnconnect_dispatch_in_app($endpoint, $db)
    {
        $endpoint = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$endpoint);
        if ($endpoint === '') {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Missing GRNConnect endpoint']);
            exit;
        }

        // Dedicated executables (avoid loading the full module router)
        if ($endpoint === 'import') {
            require __DIR__ . '/import.php';
            exit;
        }
        if ($endpoint === 'creds') {
            $wasBootstrapping = !empty($GLOBALS['__MODULES_BOOTSTRAP__']);
            $GLOBALS['__MODULES_BOOTSTRAP__'] = true;
            require_once __DIR__ . '/creds.php';
            if (!$wasBootstrapping) {
                $GLOBALS['__MODULES_BOOTSTRAP__'] = false;
            }
            if (!function_exists('grnconnect_handle_creds')) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'GRNConnect creds handler missing']);
                exit;
            }
            grnconnect_handle_creds();
            exit;
        }

        if (!class_exists('\\AppRouter\\Router', false)) {
            require_once __DIR__ . '/../../router.php';
        }

        // Do NOT require modules/helpers.php here — MARKUP/CURRENCY_CONVERT already
        // exist in the main app (app/lib/functions.php). Redeclare = fatal 500.

        if (!defined('SUPPLIER_CONNECT_TIMEOUT')) {
            define('SUPPLIER_CONNECT_TIMEOUT', 10);
        }
        if (!defined('SUPPLIER_REQUEST_TIMEOUT')) {
            define('SUPPLIER_REQUEST_TIMEOUT', 30);
        }

        $router = new \AppRouter\Router(function ($method, $path, $statusCode, $exception = null) {
            http_response_code((int)$statusCode ?: 500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => true,
                'code'    => $statusCode,
                'message' => ((int)$statusCode === 404)
                    ? 'GRNConnect endpoint not found'
                    : 'GRNConnect request failed',
                'path'    => $path,
                'detail'  => ($exception instanceof \Throwable) ? $exception->getMessage() : null,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        });

        $GLOBALS['router'] = $router;
        $GLOBALS['db'] = $db;
        $GLOBALS['__MODULES_BOOTSTRAP__'] = true;

        // Registers /stays/grnconnect/* routes onto $router (global $router).
        require_once __DIR__ . '/index.php';

        $GLOBALS['__MODULES_BOOTSTRAP__'] = false;

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));
        $path = '/stays/grnconnect/' . $endpoint;
        $router->dispatch($method, $path);
        exit;
    }
}
