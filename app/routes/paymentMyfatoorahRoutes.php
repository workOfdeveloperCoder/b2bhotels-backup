<?php
// ============================================================================
// MYFATOORAH WEBHOOK (PAYMENT_STATUS_CHANGED)
// ----------------------------------------------------------------------------
// PUBLIC, UNAUTHENTICATED ENDPOINT THAT RECEIVES MYFATOORAH'S ASYNCHRONOUS
// PAYMENT EVENTS. EVERY EVENT IS HMAC-VERIFIED (myfatoorah-signature HEADER)
// AND THEN RE-CONFIRMED VIA GetPaymentStatus BEFORE THE BOOKING IS FINALIZED.
// OPTIONAL: THE REDIRECT CALLBACK ALREADY VERIFIES SERVER-SIDE; THE WEBHOOK
// COVERS BUYERS WHO CLOSE THE BROWSER BEFORE RETURNING.
//
// REGISTER IN THE MYFATOORAH PORTAL (INTEGRATION SETTINGS > WEBHOOK SETTINGS):
//     ENDPOINT:  https://<your-domain>/payment/myfatoorah/webhook
//     SECRET:    PASTE THE GENERATED KEY INTO THE GATEWAY'S "WEBHOOK SECRET KEY" (c2)
//     EVENTS:    TRANSACTION STATUS CHANGED — SIGNING VERSION V2
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('payment/myfatoorah/webhook', function () use ($SECURE, $db) {

    require_once 'app/lib/payment-gateway.php';
    require_once 'app/lib/myfatoorah.php';

    $rawBody   = (string) file_get_contents('php://input');
    $signature = (string) ($_SERVER['HTTP_MYFATOORAH_SIGNATURE'] ?? '');

    [$httpCode, $body] = myfatoorah_handle_webhook($db, $rawBody, $signature);

    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
});
