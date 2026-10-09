<?php
/**
 * MYFATOORAH HELPER LIBRARY (HOSTED INVOICE LINK, RAW CURL — NO SDK).
 *
 * ALL MYFATOORAH-SPECIFIC LOGIC LIVES IN THIS ONE FILE: CONFIG, API CALLS,
 * CALLBACK VERIFICATION, WEBHOOK SIGNATURE CHECK AND BOOKING FINALIZATION.
 * IT IS REQUIRED ON DEMAND BY THE GATEWAY VIEW, verify_gateway_payment(),
 * THE ADMIN CREDENTIAL TEST ROUTE AND THE PUBLIC WEBHOOK ROUTE.
 *
 * CREDENTIAL MAPPING ON THE payment_gateways ROW:
 *   c1 = API TOKEN            (PORTAL > INTEGRATION SETTINGS > API KEY, "SK_...")
 *   c2 = WEBHOOK SECRET KEY   (PORTAL > INTEGRATION SETTINGS > WEBHOOK SETTINGS, OPTIONAL)
 *   c3 = COUNTRY ISO          (KWT, SAU, ARE, QAT, BHR, OMN, JOD, EGY — DEFAULT KWT)
 *   dev_mode: '1' = TEST (apitest.myfatoorah.com), '0' = LIVE (COUNTRY-SPECIFIC HOST)
 *
 * FLOW:
 *   1. VIEW CALLS POST /v2/SendPayment (NotificationOption=LNK) AND REDIRECTS THE
 *      BUYER TO Data.InvoiceURL.
 *   2. MYFATOORAH RETURNS THE BUYER TO CallBackUrl (?paymentId=...&Id=...).
 *      verify_gateway_payment('myfatoorah') CONFIRMS VIA POST /v2/GetPaymentStatus.
 *   3. OPTIONAL WEBHOOK (payment/myfatoorah/webhook) FINALIZES ASYNCHRONOUSLY;
 *      THE PAYLOAD IS SIGNATURE-CHECKED AND THEN RE-CONFIRMED VIA THE API.
 *
 * DOCS: https://docs.myfatoorah.com/docs/send-payment
 *       https://docs.myfatoorah.com/docs/get-payment-status
 *       https://docs.myfatoorah.com/docs/webhook-v2
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

/** LIVE API HOST PER COUNTRY ISO CODE (TEST IS ALWAYS apitest.myfatoorah.com). */
function myfatoorah_live_hosts(): array
{
    return [
        'KWT' => 'https://api.myfatoorah.com',
        'BHR' => 'https://api.myfatoorah.com',
        'JOD' => 'https://api.myfatoorah.com',
        'OMN' => 'https://api.myfatoorah.com',
        'ARE' => 'https://api-ae.myfatoorah.com',
        'SAU' => 'https://api-sa.myfatoorah.com',
        'QAT' => 'https://api-qa.myfatoorah.com',
        'EGY' => 'https://api-eg.myfatoorah.com',
    ];
}

/** RESOLVE MYFATOORAH CONFIGURATION + BASE URL FROM A payment_gateways ROW. */
function myfatoorah_config(array $gateway): array
{
    $devMode  = (string) ($gateway['dev_mode'] ?? '1') === '1';
    $apiToken = trim((string) ($gateway['c1'] ?? ''));
    $secret   = trim((string) ($gateway['c2'] ?? ''));
    $country  = strtoupper(trim((string) ($gateway['c3'] ?? ''))) ?: 'KWT';
    $hosts    = myfatoorah_live_hosts();

    if (!isset($hosts[$country])) {
        $country = 'KWT';
    }

    return [
        'api_token'      => $apiToken,
        'webhook_secret' => $secret,
        'country'        => $country,
        'currency'       => strtoupper(trim((string) ($gateway['currency'] ?? ''))),
        'dev_mode'       => $devMode,
        'base_url'       => $devMode ? 'https://apitest.myfatoorah.com' : $hosts[$country],
    ];
}

// ============================================================================
// HTTP
// ============================================================================

/**
 * RAW MYFATOORAH API REQUEST.
 * RETURNS ['http_code'=>int, 'json'=>array|null, 'raw'=>string, 'error'=>string].
 */
function myfatoorah_api_request(array $cfg, string $method, string $path, ?array $body = null): array
{
    $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $decoded;
        }
    }

    return ['http_code' => $code, 'json' => $json, 'raw' => (string) $raw, 'error' => $err];
}

/** FLATTEN AN API ERROR RESPONSE INTO ONE HUMAN-READABLE MESSAGE. */
function myfatoorah_error_message(array $res, string $fallback = 'MyFatoorah request failed.'): string
{
    $json = $res['json'];
    if (!is_array($json)) {
        return $res['error'] !== '' ? $res['error'] : $fallback;
    }
    $parts = [];
    if (!empty($json['Message']) && is_string($json['Message'])) {
        $parts[] = $json['Message'];
    }
    if (!empty($json['ValidationErrors']) && is_array($json['ValidationErrors'])) {
        $parts = array_merge($parts, array_map(
            fn($v) => is_array($v) ? trim(($v['Name'] ?? '') . ': ' . ($v['Error'] ?? '')) : (string) $v,
            $json['ValidationErrors']
        ));
    }
    return $parts ? implode(' | ', array_filter($parts)) : $fallback;
}

// ============================================================================
// AMOUNT / CUSTOMER HELPERS
// ============================================================================

/** MYFATOORAH ACCEPTS UP TO 3 DECIMALS (KWD/BHD/OMR/JOD); ROUND ACCORDINGLY. */
function myfatoorah_amount($amount, string $currency): float
{
    $three    = ['KWD', 'BHD', 'OMR', 'JOD', 'IQD', 'LYD', 'TND'];
    $decimals = in_array(strtoupper($currency), $three, true) ? 3 : 2;
    return round((float) $amount, $decimals);
}

/**
 * CONVERT AN AMOUNT BETWEEN TWO SITE CURRENCIES USING THE `currencies` TABLE
 * (RATES ARE RELATIVE TO ONE BASE, SO amount * rate_to / rate_from).
 * RETURNS NULL WHEN EITHER CURRENCY IS UNKNOWN OR HAS NO USABLE RATE.
 */
function myfatoorah_convert_amount(float $amount, string $from, string $to): ?float
{
    global $db;
    $from = strtoupper(trim($from));
    $to   = strtoupper(trim($to));
    if ($from === $to) {
        return $amount;
    }
    if (!$db) {
        return null;
    }
    $rows = $db->select('currencies', ['name', 'rate'], ['name' => [$from, $to], 'LIMIT' => 2]);
    $rates = [];
    foreach ((array) $rows as $row) {
        $rates[strtoupper((string) $row['name'])] = (float) $row['rate'];
    }
    if (empty($rates[$from]) || empty($rates[$to]) || $rates[$from] <= 0 || $rates[$to] <= 0) {
        return null;
    }
    return $amount * $rates[$to] / $rates[$from];
}

/**
 * MYFATOORAH REJECTS BADLY FORMED MOBILE NUMBERS; RETURN A CLEAN LOCAL NUMBER
 * (DIGITS ONLY, 5–11 CHARS) OR '' SO THE FIELD CAN BE OMITTED.
 */
function myfatoorah_clean_mobile(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = ltrim($digits, '0');
    if (strlen($digits) > 11) {
        $digits = substr($digits, -11);
    }
    return (strlen($digits) >= 5) ? $digits : '';
}

// ============================================================================
// SEND PAYMENT (CREATE HOSTED INVOICE LINK)
// ============================================================================

/**
 * CREATE A MYFATOORAH INVOICE LINK FOR A BOOKING.
 * RETURNS ['success'=>bool, 'invoice_id'=>string, 'url'=>string, 'message'=>string, 'http_code'=>int].
 */
function myfatoorah_send_payment(array $cfg, array $booking, string $callbackUrl, string $errorUrl): array
{
    $currency = strtoupper((string) ($booking['currency_markup'] ?? 'USD'));
    $amount   = myfatoorah_amount($booking['price_markup'] ?? 0, $currency);
    $name     = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')) ?: 'Customer';
    $email    = trim((string) ($booking['email'] ?? ''));
    $mobile   = myfatoorah_clean_mobile((string) ($booking['phone'] ?? ''));
    $lang     = (isset($_SESSION['lang']) && strtolower((string) $_SESSION['lang']) === 'ar') ? 'ar' : 'en';

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid payment amount.', 'http_code' => 0, 'invoice_id' => '', 'url' => ''];
    }

    $body = [
        'CustomerName'       => mb_substr($name, 0, 100),
        'NotificationOption' => 'LNK',
        'InvoiceValue'       => $amount,
        'DisplayCurrencyIso' => $currency,
        'CallBackUrl'        => $callbackUrl,
        'ErrorUrl'           => $errorUrl,
        'Language'           => $lang,
        'CustomerReference'  => (string) $booking['invoice_id'],
        'UserDefinedField'   => (string) $booking['invoice_id'],
    ];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body['CustomerEmail'] = $email;
    }
    if ($mobile !== '') {
        $body['CustomerMobile'] = $mobile;
    }

    $res     = myfatoorah_api_request($cfg, 'POST', 'v2/SendPayment', $body);
    $message = myfatoorah_error_message($res, 'Unable to create MyFatoorah invoice.');

    // THE BOOKING CURRENCY IS NOT ENABLED AS A DISPLAY CURRENCY ON THIS MYFATOORAH
    // ACCOUNT — RETRY IN THE GATEWAY'S CONFIGURED CURRENCY WITH A CONVERTED AMOUNT.
    $gatewayCurrency = (string) ($cfg['currency'] ?? '');
    if (!myfatoorah_send_ok($res) && stripos($message, 'displaycurrency') !== false
        && $gatewayCurrency !== '' && $gatewayCurrency !== $currency) {
        $converted = myfatoorah_convert_amount($amount, $currency, $gatewayCurrency);
        if ($converted !== null && $converted > 0) {
            $converted = myfatoorah_amount($converted, $gatewayCurrency);
            error_log('MYFATOORAH SENDPAYMENT | Invoice: ' . ($booking['invoice_id'] ?? '') . " | {$currency} rejected as display currency, retrying as {$gatewayCurrency} {$converted}");
            $body['DisplayCurrencyIso'] = $gatewayCurrency;
            $body['InvoiceValue']       = $converted;
            $res     = myfatoorah_api_request($cfg, 'POST', 'v2/SendPayment', $body);
            $message = myfatoorah_error_message($res, 'Unable to create MyFatoorah invoice.');
        } else {
            $message .= " (no exchange rate found to convert {$currency} to {$gatewayCurrency})";
        }
    }

    if (myfatoorah_send_ok($res)) {
        return [
            'success'    => true,
            'invoice_id' => (string) ($res['json']['Data']['InvoiceId'] ?? ''),
            'url'        => (string) $res['json']['Data']['InvoiceURL'],
            'message'    => 'OK',
            'http_code'  => 200,
        ];
    }

    error_log('MYFATOORAH SENDPAYMENT ERROR | Invoice: ' . ($booking['invoice_id'] ?? '') . ' | HTTP ' . $res['http_code'] . ' | ' . $message);
    return ['success' => false, 'message' => $message, 'http_code' => $res['http_code'], 'invoice_id' => '', 'url' => ''];
}

/** TRUE WHEN A SendPayment RESPONSE CARRIES A USABLE INVOICE URL. */
function myfatoorah_send_ok(array $res): bool
{
    $json = $res['json'];
    return $res['http_code'] === 200 && is_array($json) && !empty($json['IsSuccess']) && !empty($json['Data']['InvoiceURL']);
}

// ============================================================================
// PAYMENT STATUS (SERVER-SIDE TRUTH)
// ============================================================================

/**
 * QUERY POST /v2/GetPaymentStatus. $keyType IS 'PaymentId', 'InvoiceId' OR 'CustomerReference'.
 * RETURNS ['ok'=>bool, 'status'=>'Paid|Pending|Canceled|', 'data'=>array, 'message'=>string].
 */
function myfatoorah_get_payment_status(array $cfg, string $key, string $keyType): array
{
    if ($key === '') {
        return ['ok' => false, 'status' => '', 'data' => [], 'message' => 'Missing lookup key'];
    }
    $res  = myfatoorah_api_request($cfg, 'POST', 'v2/GetPaymentStatus', ['Key' => $key, 'KeyType' => $keyType]);
    $json = $res['json'];

    if ($res['http_code'] !== 200 || !is_array($json) || empty($json['IsSuccess']) || empty($json['Data'])) {
        return ['ok' => false, 'status' => '', 'data' => [], 'message' => myfatoorah_error_message($res, 'GetPaymentStatus failed (HTTP ' . $res['http_code'] . ')')];
    }

    return ['ok' => true, 'status' => (string) ($json['Data']['InvoiceStatus'] ?? ''), 'data' => $json['Data'], 'message' => 'OK'];
}

/** PICK THE SUCCESSFUL TRANSACTION'S REFERENCE (PaymentId) FROM A STATUS PAYLOAD. */
function myfatoorah_paid_reference(array $data): string
{
    // MyFatoorah Benefit sandbox returns 'Succss' (typo with single 'e') as TransactionStatus.
    // Check for both 'success' and 'succss' so test transactions are properly matched without affecting live or other gateways.
    $paid  = array_values(array_filter($transactions, fn($t) => in_array(strtolower((string) ($t['TransactionStatus'] ?? '')), ['success', 'succss'], true)));
    $first = $paid[0] ?? ($transactions[0] ?? []);
    return (string) ($first['PaymentId'] ?? $first['TransactionId'] ?? $data['InvoiceId'] ?? '');
}

/**
 * CALLED FROM verify_gateway_payment('myfatoorah') WHEN THE BUYER RETURNS WITH
 * payment_status=success. RETURNS 'success', 'cancel' OR 'failure'.
 */
function myfatoorah_verify_callback($db, array $gatewayData, array $tokenData): string
{
    $gatewayId = $tokenData['gateway_id'] ?? null;
    $invoiceId = (string) ($tokenData['invoice_id'] ?? '');
    if (!$gatewayId || $invoiceId === '') {
        error_log('MYFATOORAH VERIFY | missing gateway id or invoice id in token');
        return 'failure';
    }

    $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
    if (!$gateway) {
        return 'failure';
    }
    $cfg = myfatoorah_config($gateway);
    if ($cfg['api_token'] === '') {
        error_log("MYFATOORAH VERIFY | API token not configured | Invoice: {$invoiceId}");
        return 'failure';
    }

    // PREFER THE paymentId MYFATOORAH APPENDS TO THE CALLBACK URL; FALL BACK TO
    // THE MYFATOORAH INVOICE ID WE STORED AT CHECKOUT, THEN OUR OWN REFERENCE.
    $paymentId = trim((string) ($gatewayData['paymentId'] ?? $gatewayData['PaymentId'] ?? ''));
    $mfInvoice = trim((string) ($tokenData['myfatoorah_invoice_id'] ?? $gatewayData['transaction_id'] ?? ''));

    if ($paymentId !== '') {
        $status = myfatoorah_get_payment_status($cfg, $paymentId, 'PaymentId');
    } elseif ($mfInvoice !== '') {
        $status = myfatoorah_get_payment_status($cfg, $mfInvoice, 'InvoiceId');
    } else {
        $status = myfatoorah_get_payment_status($cfg, $invoiceId, 'CustomerReference');
    }

    if (!$status['ok']) {
        error_log("MYFATOORAH VERIFY | Invoice: {$invoiceId} | " . $status['message']);
        return 'failure';
    }

    // THE PAID INVOICE MUST BELONG TO THIS BOOKING.
    $reference = (string) ($status['data']['CustomerReference'] ?? '');
    if ($reference !== '' && $reference !== $invoiceId) {
        error_log("MYFATOORAH VERIFY | reference mismatch | Invoice: {$invoiceId} | MyFatoorah ref: {$reference}");
        return 'failure';
    }

    $invoiceStatus = strtolower($status['status']);
    if ($invoiceStatus === 'paid') {
        return 'success';
    }
    if ($invoiceStatus === 'canceled' || $invoiceStatus === 'cancelled') {
        return 'cancel';
    }
    error_log("MYFATOORAH VERIFY | Invoice: {$invoiceId} | status '{$status['status']}' is not paid");
    return 'cancel';
}

// ============================================================================
// WEBHOOK
// ============================================================================

/**
 * VERIFY THE myfatoorah-signature HEADER (HMAC-SHA256, BASE64).
 * SUPPORTS THE V2 OBJECT PAYLOAD (Data.Invoice / Data.Transaction) AND THE V1
 * FLAT PAYLOAD (Data.InvoiceId ...).
 */
function myfatoorah_verify_signature(array $payload, string $secret, string $signature): bool
{
    if ($secret === '' || $signature === '') {
        return false;
    }
    $data = is_array($payload['Data'] ?? null) ? $payload['Data'] : [];

    if (isset($data['Invoice']) && is_array($data['Invoice'])) {
        // V2 — DOCUMENTED ORDER FOR PAYMENT_STATUS_CHANGED.
        $ordered = [
            'Invoice.Id'                 => $data['Invoice']['Id'] ?? '',
            'Invoice.Status'             => $data['Invoice']['Status'] ?? '',
            'Transaction.Status'         => $data['Transaction']['Status'] ?? '',
            'Transaction.PaymentId'      => $data['Transaction']['PaymentId'] ?? '',
            'Invoice.ExternalIdentifier' => $data['Invoice']['ExternalIdentifier'] ?? '',
        ];
    } else {
        // V1 — DOTTED KEYS SORTED CASE-INSENSITIVELY.
        $ordered = [
            'Invoice.Id'                => $data['InvoiceId'] ?? '',
            'Invoice.Reference'         => $data['InvoiceReference'] ?? '',
            'Invoice.CreatedDate'       => $data['CreatedDate'] ?? '',
            'Customer.Reference'        => $data['CustomerReference'] ?? '',
            'Customer.Name'             => $data['CustomerName'] ?? '',
            'Customer.Mobile'           => $data['CustomerMobile'] ?? '',
            'Customer.Email'            => $data['CustomerEmail'] ?? '',
            'Transaction.Status'        => $data['TransactionStatus'] ?? '',
            'Payment.Method'            => $data['PaymentMethod'] ?? '',
            'UserDefinedField'          => $data['UserDefinedField'] ?? '',
            'Transaction.ReferenceId'   => $data['ReferenceId'] ?? '',
            'Transaction.TrackId'       => $data['TrackId'] ?? '',
            'Payment.Id'                => $data['PaymentId'] ?? '',
            'Transaction.Authorization' => $data['Authorization'] ?? '',
        ];
        uksort($ordered, 'strcasecmp');
    }

    $pairs    = array_map(fn($k, $v) => $k . '=' . (is_scalar($v) ? (string) $v : ''), array_keys($ordered), $ordered);
    $expected = base64_encode(hash_hmac('sha256', implode(',', $pairs), $secret, true));

    return hash_equals($expected, trim($signature));
}

/**
 * EXTRACT (reference, mf_invoice, payment_id, tx_status, event_code) FROM
 * EITHER WEBHOOK PAYLOAD VERSION.
 */
function myfatoorah_webhook_fields(array $payload): array
{
    $data = is_array($payload['Data'] ?? null) ? $payload['Data'] : [];
    if (isset($data['Invoice']) && is_array($data['Invoice'])) {
        return [
            'reference'  => (string) ($data['Invoice']['UserDefinedField'] ?? $data['Invoice']['ExternalIdentifier'] ?? ''),
            'mf_invoice' => (string) ($data['Invoice']['Id'] ?? ''),
            'payment_id' => (string) ($data['Transaction']['PaymentId'] ?? ''),
            'tx_status'  => strtoupper((string) ($data['Transaction']['Status'] ?? '')),
            'event_code' => (int) ($payload['Event']['Code'] ?? 0),
        ];
    }
    return [
        'reference'  => (string) ($data['CustomerReference'] ?? $data['UserDefinedField'] ?? ''),
        'mf_invoice' => (string) ($data['InvoiceId'] ?? ''),
        'payment_id' => (string) ($data['PaymentId'] ?? ''),
        'tx_status'  => strtoupper((string) ($data['TransactionStatus'] ?? '')),
        'event_code' => (int) ($payload['EventType'] ?? 0),
    ];
}

/**
 * HANDLE ONE WEBHOOK REQUEST END-TO-END. RETURNS [http_code, body_array].
 * ONLY A SIGNATURE-VALID, API-CONFIRMED PAID INVOICE FINALIZES THE BOOKING.
 */
function myfatoorah_handle_webhook($db, string $rawBody, string $signature): array
{
    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || empty($payload['Data'])) {
        error_log('MYFATOORAH WEBHOOK | empty or invalid payload');
        return [200, ['received' => true, 'ignored' => 'invalid payload']];
    }

    $gateway = $db->get('payment_gateways', '*', ['name' => 'MyFatoorah']) ?: $db->get('payment_gateways', '*', ['name[~]' => 'myfatoorah']);
    if (!$gateway) {
        error_log('MYFATOORAH WEBHOOK | gateway row not found');
        return [200, ['received' => true, 'ignored' => 'gateway not configured']];
    }
    $cfg = myfatoorah_config($gateway);

    if ($cfg['webhook_secret'] === '') {
        error_log('MYFATOORAH WEBHOOK | no webhook secret configured; event ignored');
        return [200, ['received' => true, 'ignored' => 'webhook secret not configured']];
    }
    if (!myfatoorah_verify_signature($payload, $cfg['webhook_secret'], $signature)) {
        error_log('MYFATOORAH WEBHOOK | SIGNATURE MISMATCH');
        return [401, ['received' => false, 'error' => 'invalid signature']];
    }

    $f = myfatoorah_webhook_fields($payload);
    error_log("MYFATOORAH WEBHOOK | event {$f['event_code']} | ref {$f['reference']} | mf {$f['mf_invoice']} | tx {$f['tx_status']}");

    if ($f['event_code'] !== 1 || $f['tx_status'] !== 'SUCCESS' || $f['reference'] === '') {
        return [200, ['received' => true, 'ignored' => 'not a successful payment event']];
    }

    // NEVER TRUST THE PAYLOAD ALONE — RE-CONFIRM WITH THE API BEFORE FINALIZING.
    $status = $f['mf_invoice'] !== ''
        ? myfatoorah_get_payment_status($cfg, $f['mf_invoice'], 'InvoiceId')
        : myfatoorah_get_payment_status($cfg, $f['reference'], 'CustomerReference');

    if (!$status['ok'] || strtolower($status['status']) !== 'paid') {
        error_log("MYFATOORAH WEBHOOK | API did not confirm paid | ref {$f['reference']} | " . ($status['message'] ?? $status['status']));
        return [200, ['received' => true, 'ignored' => 'not confirmed paid by API']];
    }
    $apiRef = (string) ($status['data']['CustomerReference'] ?? '');
    if ($apiRef !== '' && $apiRef !== $f['reference']) {
        error_log("MYFATOORAH WEBHOOK | reference mismatch | webhook {$f['reference']} | api {$apiRef}");
        return [200, ['received' => true, 'ignored' => 'reference mismatch']];
    }

    $paymentRef = $f['payment_id'] !== '' ? $f['payment_id'] : myfatoorah_paid_reference($status['data']);
    try {
        $result = myfatoorah_finalize_payment($db, $f['reference'], $paymentRef, $payload);
    } catch (\Throwable $e) {
        error_log("MYFATOORAH WEBHOOK | finalize exception | ref {$f['reference']} | " . $e->getMessage());
        return [500, ['received' => false, 'error' => 'finalize failed']];
    }
    return [200, ['received' => true, 'finalized' => (bool) ($result['success'] ?? false), 'already' => (bool) ($result['already'] ?? false)]];
}

// ============================================================================
// FINALIZATION (SESSION-FREE — USED BY THE WEBHOOK; MIRRORS handle_payment_callback)
// ============================================================================

/** MARK A BOOKING PAID, RECORD THE TRANSACTION AND AUTO-ISSUE WHEN ALLOWED. IDEMPOTENT. */
function myfatoorah_finalize_payment($db, string $invoiceId, ?string $paymentRef, array $rawEvent = []): array
{
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '') {
        return ['success' => false, 'message' => 'Missing invoice reference', 'already' => false];
    }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) {
        return ['success' => false, 'message' => "Booking not found for invoice {$invoiceId}", 'already' => false];
    }
    if (($booking['payment_status'] ?? '') === 'paid') {
        return ['success' => true, 'message' => 'Already paid', 'already' => true];
    }

    $module     = strtolower((string) ($booking['module'] ?? ''));
    $moduleType = (string) ($booking['module_type'] ?? '');
    $gatewayId  = $booking['payment_gateway'] ?? null;

    $tokenData = [
        'invoice_id'   => $invoiceId,
        'booking_id'   => $booking['id'] ?? null,
        'amount'       => $booking['price_markup'] ?? 0,
        'currency'     => $booking['currency_markup'] ?? 'USD',
        'gateway_id'   => $gatewayId,
        'gateway_name' => 'MyFatoorah',
        'client_email' => $booking['email'] ?? '',
        'user_id'      => $booking['user_id'] ?? null,
        'module'       => $module,
        'module_type'  => $moduleType,
    ];

    // DEV_MODE CONSISTENCY: COLLECT THE MONEY EITHER WAY, AUTO-ISSUE ONLY WHEN MODES MATCH.
    $devModeMismatch = false;
    if ($module && $gatewayId) {
        $moduleRecord  = $db->get('modules', ['dev_mode'], ['name' => $module]);
        $gatewayRecord = $db->get('payment_gateways', ['dev_mode'], ['id' => $gatewayId]);
        if ($moduleRecord && $gatewayRecord && (int) $moduleRecord['dev_mode'] !== (int) $gatewayRecord['dev_mode']) {
            $devModeMismatch = true;
            error_log("MYFATOORAH WEBHOOK DEV_MODE MISMATCH | Invoice: {$invoiceId} | module {$module}");
        }
    }

    $db->update('bookings', [
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'transaction_id' => $paymentRef,
        'paid_at'        => date('Y-m-d H:i:s'),
    ], ['invoice_id' => $invoiceId]);

    if (function_exists('record_transaction')) {
        record_transaction($tokenData, $paymentRef, 'success', $rawEvent);
    }

    $settingsRecord   = $db->get('settings', ['booking_payment_issue'], ['id' => 1]);
    $autoIssueEnabled = $settingsRecord && (int) ($settingsRecord['booking_payment_issue'] ?? 0) === 1;
    $issuable = $autoIssueEnabled && !$devModeMismatch && $module
        && !in_array($module, ['flight', 'visas'], true)
        && !in_array(strtolower($moduleType), ['tours', 'tour'], true);

    if ($issuable) {
        myfatoorah_auto_issue($db, $booking, $tokenData);
    }

    return ['success' => true, 'message' => 'Payment finalized', 'already' => false];
}

/** CALL THE MODULE ISSUE ENDPOINT AFTER A WEBHOOK-CONFIRMED PAYMENT. */
function myfatoorah_auto_issue($db, array $booking, array $tokenData): void
{
    $invoiceId  = (string) $tokenData['invoice_id'];
    $module     = (string) $tokenData['module'];
    $moduleType = (string) $tokenData['module_type'];
    $apiUrl     = root . 'modules/' . $moduleType . '/' . $module . '/issue';

    try {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['invoice_id' => $invoiceId]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp   = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $resp));
        $parsed = json_decode($resp, true);
        $ok     = is_array($parsed) && ((($parsed['status'] ?? null) === true) || (($parsed['success'] ?? null) === true));

        if ($ok) {
            $pnr = $parsed['Prn'] ?? $parsed['pnr'] ?? $parsed['confirmation_number'] ?? $parsed['booking_reference'] ?? $parsed['reference'] ?? null;
            $upd = ['booking_status' => $parsed['booking_status'] ?? 'confirmed', 'error_response' => null, 'booking_payment_issue' => null];
            if (!empty($pnr)) {
                $upd['pnr'] = $pnr;
            }
            if ($moduleType !== 'rail') {
                $upd['booking_response'] = json_encode($parsed);
            }
            $db->update('bookings', $upd, ['invoice_id' => $invoiceId]);

            if (function_exists('triggerNotification')) {
                triggerNotification('booking.issued', [
                    'invoice_id'     => $invoiceId,
                    'pnr'            => $pnr,
                    'module'         => $module,
                    'booking_id'     => $booking['id'] ?? null,
                    'user_id'        => $booking['user_id'] ?? null,
                    'customer_email' => $booking['email'] ?? '',
                    'amount'         => $tokenData['amount'],
                    'currency'       => $tokenData['currency'],
                ]);
            }
            return;
        }

        $err     = is_array($parsed) ? ($parsed['response_error'] ?? $parsed['error'] ?? $parsed['message'] ?? $resp) : ('HTTP ' . $code);
        $errText = is_string($err) ? $err : json_encode($err);
        $db->update('bookings', [
            'error_response'        => $errText,
            'booking_status'        => 'pending',
            'booking_payment_issue' => $errText,
        ], ['invoice_id' => $invoiceId]);
        error_log("MYFATOORAH WEBHOOK ISSUE FAILED | Invoice: {$invoiceId} | {$apiUrl} | {$errText}");
    } catch (\Throwable $e) {
        error_log("MYFATOORAH WEBHOOK ISSUE EXCEPTION | Invoice: {$invoiceId} | " . $e->getMessage());
    }
}
