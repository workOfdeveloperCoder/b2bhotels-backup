<?php
// ============================================================================
// GRNCONNECT IMPORT ENDPOINT (executable)
// ============================================================================
// Used when live hosts route /modules/stays/grnconnect/import through the main
// app (app/routes/globalRoutes.php), which includes this file as:
//   modules/stays/grnconnect/import.php
//
// Localhost normally hits modules/index.php instead (route registration in
// content/content.php). Both paths call grnconnect_handle_import().
// ============================================================================

while (ob_get_level()) {
    ob_end_clean();
}

@error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

try {
    // Bootstrap $db when this file is hit directly (not included from globalRoutes).
    if (!isset($db)) {
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (!is_file($envFile)) {
            throw new Exception('Application .env not found');
        }
        $env = parse_ini_file($envFile);
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

        $db = new \Medoo\Medoo([
            'type'      => $env['DB_TYPE'] ?? 'mysql',
            'host'      => $env['DB_HOST'] ?? 'localhost',
            'database'  => $env['DB_DATABASE'],
            'username'  => $env['DB_USERNAME'],
            'password'  => $env['DB_PASSWORD'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }

    require_once __DIR__ . '/api.php';
    require_once __DIR__ . '/content/content.php';

    if (!function_exists('grnconnect_handle_import')) {
        throw new Exception('GRNConnect import handler is not available');
    }

    grnconnect_handle_import($db);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
