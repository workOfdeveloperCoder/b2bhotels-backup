<?php
// MYFATOORAH — ADMIN CREDENTIAL TEST ROUTE (AUTO-LOADED BY admin/gatewaysRoutes.php).
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/myfatoorah', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once 'app/lib/myfatoorah.php';

    $g     = gw_test_gateway($db, 'MyFatoorah');
    $cfg   = myfatoorah_config($g);
    $steps = [
        '[START] MyFatoorah credential validation',
        '[ENV] Environment: ' . ($cfg['dev_mode'] ? 'TEST' : 'LIVE') . ' — country ' . $cfg['country'] . ' — ' . $cfg['base_url'],
    ];

    if ($cfg['api_token'] === '') {
        $steps[] = '[ERROR] API Token (c1) is empty';
        gw_test_json(false, 'API token missing', $steps);
    }
    $steps[] = '[OK] API Token present (' . gw_test_mask($cfg['api_token'], 10) . ')';
    $steps[] = $cfg['webhook_secret'] !== ''
        ? '[OK] Webhook secret present (' . gw_test_mask($cfg['webhook_secret'], 6) . ')'
        : '[INFO] Webhook secret (c2) empty — webhook events will be ignored; redirect verification still works';

    // InitiatePayment IS A READ-ONLY CALL THAT PROVES THE TOKEN + HOST ARE VALID.
    $currency = strtoupper(trim((string) ($g['currency'] ?? ''))) ?: 'KWD';
    $steps[]  = '[CALL] POST ' . $cfg['base_url'] . '/v2/InitiatePayment (' . $currency . ') …';
    $r        = myfatoorah_api_request($cfg, 'POST', 'v2/InitiatePayment', ['InvoiceAmount' => 1, 'CurrencyIso' => $currency]);
    $steps[]  = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200 && !empty($r['json']['IsSuccess'])) {
        $methods = is_array($r['json']['Data']['PaymentMethods'] ?? null) ? $r['json']['Data']['PaymentMethods'] : [];
        $names   = array_map(fn($m) => (string) ($m['PaymentMethodEn'] ?? ''), $methods);
        $steps[] = '[OK] Authenticated — ' . count($methods) . ' payment method(s): ' . implode(', ', array_filter($names));
        $steps[] = '[INFO] Webhook URL: ' . root . 'payment/myfatoorah/webhook';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'MyFatoorah credentials valid', $steps, 200);
    }

    $msg     = myfatoorah_error_message($r, 'Unknown error');
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401 || $r['http_code'] === 403) {
        $steps[] = '[HINT] Tokens are environment-specific: a LIVE token (portal.myfatoorah.com) only works with Environment = Live; a TEST token (demo.myfatoorah.com) only works with Environment = Test.';
        $steps[] = '[HINT] Currently in ' . ($cfg['dev_mode'] ? 'TEST' : 'LIVE') . ' mode — switch the environment or paste the matching token. Also check Country (c3).';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
