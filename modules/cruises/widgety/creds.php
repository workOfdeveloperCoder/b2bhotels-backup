<?php
// ============================================================================
// WIDGETY — CREDENTIAL VALIDATION (Settings → Modules "Test" button)
// POST /modules/cruises/widgety/creds  { c1, c2, c3, c4 }
// Validates by calling a lightweight V3 endpoint (operators) with query auth.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('cruises/widgety/creds', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/api.php';

    // Admin only — this route SAVES credentials and the base URL. Left open, anyone
    // could overwrite them or point c3 at their own server to capture the token.
    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'status' => false, 'message' => 'Unauthorized']); exit;
    }

    try {
        // ALLOW VALIDATING VALUES TYPED IN THE FORM BEFORE THEY ARE SAVED.
        $appId = trim((string) ($_POST['c1'] ?? ''));
        $token = trim((string) ($_POST['c2'] ?? ''));
        $base  = trim((string) ($_POST['c3'] ?? ''));
        $market= trim((string) ($_POST['c4'] ?? ''));
        $update = array_filter([
            'c1' => $appId  !== '' ? $appId  : null,
            'c2' => $token  !== '' ? $token  : null,
            'c3' => $base   !== '' ? $base   : null,
            'c4' => $market !== '' ? $market : null,
        ], fn($v) => $v !== null);
        if ($update) {
            $db->update('modules', $update, ['name' => 'widgety', 'type' => 'cruises']);
        }

        $cfg = _widgety_cfg($db);
        if ($cfg['app_id'] === '' || $cfg['token'] === '') {
            throw new Exception('APP ID (c1) and Token (c2) are required.');
        }

        // LIGHTWEIGHT AUTHENTICATED CALL — ONE OPERATOR IS ENOUGH TO PROVE ACCESS.
        $res = _widgety_request($cfg, WIDGETY_EP_OPERATORS, ['per_page' => 1, 'page' => 1]);
        if ($res['http_code'] !== 200) {
            throw new Exception(_widgety_error_message($res, 'Widgety rejected the credentials'));
        }

        $operators = _widgety_collection($res['json'], 'operators');
        echo json_encode([
            'success' => true, 'status' => true,
            'message' => 'Widgety credentials validated successfully.',
            'data' => [
                'base_url'       => $cfg['base_url'],
                'market'         => $cfg['market'],
                'sample_operator'=> $operators[0]['name'] ?? ($operators[0]['title'] ?? null),
            ],
        ]);
        exit;
    } catch (\Throwable $e) {
        error_log('WIDGETY CREDS | ' . $e->getMessage());
        echo json_encode([
            'success' => false, 'status' => false,
            'message' => $e->getMessage(), 'response_error' => $e->getMessage(),
        ]);
        exit;
    }
});
