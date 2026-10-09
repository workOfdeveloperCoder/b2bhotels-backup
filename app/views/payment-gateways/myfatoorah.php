<?php
// ============================================================================
// MYFATOORAH PAYMENT GATEWAY — HOSTED INVOICE LINK (REDIRECT CHECKOUT)
// ----------------------------------------------------------------------------
// CREATES A MYFATOORAH INVOICE (POST /v2/SendPayment) AND REDIRECTS THE BUYER
// TO MYFATOORAH'S HOSTED PAYMENT PAGE. THE BUYER RETURNS TO THE INVOICE WITH
// ?payment_status=success&gateway=myfatoorah&paymentId=...; THE SERVER-SIDE
// verify_gateway_payment('myfatoorah') CASE CONFIRMS VIA GetPaymentStatus AND
// THE OPTIONAL WEBHOOK (payment/myfatoorah/webhook) CONFIRMS ASYNCHRONOUSLY.
// ALL GATEWAY LOGIC LIVES IN app/lib/myfatoorah.php.
// ============================================================================

// EXIT IF NO PAYMENT REQUEST DETECTED
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

require_once __DIR__ . '/../../lib/myfatoorah.php';

// ============================================================================
// RESOLVE BOOKING / GATEWAY / URLS (NEW $paymentData FORMAT + LEGACY PAYLOAD)
// ============================================================================
$booking    = null;
$gateway    = null;
$token      = null;
$successUrl = null;
$cancelUrl  = null;

if (isset($paymentData) && is_array($paymentData)) {
    $booking    = $paymentData['booking'] ?? null;
    $gateway    = $paymentData['gateway'] ?? null;
    $token      = $paymentData['token'] ?? null;
    $successUrl = $paymentData['success_url'] ?? ($_POST['success_url'] ?? null);
    $cancelUrl  = $paymentData['cancel_url'] ?? ($_POST['cancel_url'] ?? null);
}

if ((!$booking || !$gateway) && isset($_POST['payload'])) {
    $payload = json_decode(base64_decode($_POST['payload']));
    $booking = $booking ?: [
        'invoice_id'      => $payload->booking_ref_no ?? $payload->invoice_id,
        'email'           => $payload->client_email ?? '',
        'price_markup'    => $payload->price ?? 0,
        'currency_markup' => $payload->currency ?? 'USD',
        'ref'             => $payload->booking_ref_no ?? $payload->invoice_id,
    ];
    $token      = $token ?: ($_POST['payment_token'] ?? '');
    $successUrl = $successUrl ?: ($_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=success'));
    $cancelUrl  = $cancelUrl ?: ($_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=cancel'));

    if (!$gateway) {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'MyFatoorah', 'status' => 1]);
    }
}

if (!$booking || !$gateway) {
    echo '<div class="text-center p-5 text-red-500"><p>Payment could not be initialized. Please contact support.</p></div>';
    return;
}

$cfg = myfatoorah_config($gateway);

// ============================================================================
// VALIDATE CONFIGURATION
// ============================================================================
if ($cfg['api_token'] === '') {
    echo '<div class="text-center p-5 text-red-500"><p><strong>Configuration Error</strong></p><p>MyFatoorah API token is not configured.</p></div>';
    return;
}

// ============================================================================
// CREATE THE INVOICE LINK
// RETURN URLS CARRY gateway=myfatoorah SO THE SERVER-SIDE VERIFY RUNS ON RETURN.
// MYFATOORAH APPENDS ?paymentId=...&Id=... TO CallBackUrl / ErrorUrl ITSELF.
// ============================================================================
$sep         = (strpos((string) $successUrl, '?') === false) ? '?' : '&';
$callbackUrl = $successUrl . $sep . 'gateway=myfatoorah';
$errorUrl    = $cancelUrl . ((strpos((string) $cancelUrl, '?') === false) ? '?' : '&') . 'gateway=myfatoorah';

$result = myfatoorah_send_payment($cfg, $booking, $callbackUrl, $errorUrl);

if (!empty($result['success'])) {
    // STASH THE MYFATOORAH INVOICE ID ON THE SESSION TOKEN SO THE VERIFY STEP CAN
    // LOOK THE PAYMENT UP EVEN IF paymentId IS MISSING FROM THE RETURN URL.
    if ($token && isset($_SESSION['payment_tokens'][$token])) {
        $_SESSION['payment_tokens'][$token]['myfatoorah_invoice_id'] = $result['invoice_id'];
    }
    ?>
    <div class="text-center p-5 text-slate-500">
        <p class="mb-3"><?= T::redirecting ?>&hellip;</p>
        <div class="mx-auto w-10 h-10 border-4 border-gray-200 border-t-blue-600 rounded-full animate-spin"></div>
    </div>
    <script>
        (function () {
            var url = <?= json_encode($result['url']) ?>;
            var delay = <?= json_encode($cfg['dev_mode'] ? 900 : 400) ?>;
            setTimeout(function () { window.location.href = url; }, delay);
        })();
    </script>
    <?php
    return;
}

// ============================================================================
// ERROR HANDLING
// ============================================================================
$apiMessage = (string) ($result['message'] ?? 'Unable to create MyFatoorah invoice.');

echo '<div class="max-w-md mx-auto p-5 bg-white rounded-xl border border-red-200">';
echo '<h4 class="text-red-600 font-semibold mb-2">MyFatoorah Payment Error</h4>';
echo '<p class="text-red-800">' . htmlspecialchars($apiMessage) . '</p>';
if ($cfg['dev_mode']) {
    echo '<details class="mt-3 text-xs text-gray-500"><summary class="cursor-pointer">Debug</summary>';
    echo '<pre class="bg-gray-100 p-3 mt-2 overflow-auto">HTTP: ' . htmlspecialchars((string) ($result['http_code'] ?? 0))
        . "\nAmount: " . htmlspecialchars((string) ($booking['price_markup'] ?? 0)) . ' ' . htmlspecialchars((string) ($booking['currency_markup'] ?? ''))
        . "\nEnvironment: " . ($cfg['dev_mode'] ? 'TEST' : 'LIVE') . ' (' . htmlspecialchars($cfg['country']) . ')</pre></details>';
}
echo '<p class="mt-4"><a href="' . htmlspecialchars(root . 'invoice/' . ($booking['invoice_id'] ?? '')) . '" class="text-blue-600">&larr; ' . T::back_to_invoice . '</a></p>';
echo '</div>';
