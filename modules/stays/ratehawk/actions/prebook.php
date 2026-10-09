<?php
/**
 * RateHawk Prebook (h- -> p-)
 * Endpoint: POST /modules/stays/ratehawk/prebook
 *
 * ETG certification request: call /hotel/prebook/ as soon as the guest
 * selects a room, BEFORE the booking flow (guest details + payment) begins —
 * not after payment. This endpoint is called from the room list page right
 * when the guest clicks "Continue Booking", confirming the rate is still
 * available and locking in a p- hash while they fill in guest details.
 *
 * issue.php still has its own prebook step for the rare case a p- hash
 * expires before payment completes — it's a no-op there once the hash
 * already starts with p- (this endpoint's normal, successful result).
 *
 * Idempotent: if book_hash is already a p- hash, returns it unchanged with
 * no API call.
 */

$router->post('/stays/ratehawk/prebook', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $bookHash = trim((string) ($input['book_hash'] ?? ''));

        if ($bookHash === '') {
            throw new Exception('Missing book_hash');
        }

        // Already prebooked — nothing to do.
        if (strpos($bookHash, 'p-') === 0) {
            ob_end_clean();
            echo json_encode(['success' => true, 'data' => ['book_hash' => $bookHash, 'already_prebooked' => true]]);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'ratehawk', 'type' => 'stays']);
        if (!$module) {
            throw new Exception('RateHawk module not configured');
        }

        $credentials = json_decode($module['credentials'] ?? '{}', true) ?: [];
        $keyId  = trim($credentials['key_id'] ?? $module['c1'] ?? '');
        $apiKey = trim($credentials['api_key'] ?? $module['c3'] ?? '');
        if (empty($keyId) || empty($apiKey)) {
            throw new Exception('RateHawk API credentials not configured');
        }

        $apiBaseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        // Admin-configured tolerance for a price rise at prebook (ETG 3.1) —
        // same single source of truth issue.php uses.
        require_once __DIR__ . '/../content/hotel-schema.php';
        $priceThresholdPct = rh_default_settings()['price_change_threshold'];
        try {
            $settingsDb = new \Medoo\Medoo([
                'type'     => 'mysql',
                'host'     => $module['host'] ?: 'localhost',
                'database' => $module['database'],
                'username' => $module['username'],
                'password' => $module['password'],
                'charset'  => 'utf8mb4',
            ]);
            $row = $settingsDb->get('ratehawk_content_sync', ['price_change_threshold'], ['id' => 1]);
            if ($row && is_numeric($row['price_change_threshold'])) {
                $priceThresholdPct = max(0.0, (float) $row['price_change_threshold']);
            }
        } catch (\Throwable $e) {
            // keep default
        }

        $prebookReq = [
            'hash' => $bookHash,
            'price_increase_percent' => max(0, (int) round($priceThresholdPct)),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/prebook/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($prebookReq),
            // Integration Requirements: prebook recommended timeout 60s (min 30s)
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('prebook', 'prebook', $prebookReq, $res, '', [
                'url' => $apiBaseUrl . '/hotel/prebook/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => '',
            ]);
        }

        if ($res === false || $httpCode !== 200) {
            error_log("RateHawk Prebook (pre-checkout): API Error - HTTP {$httpCode}, Error: {$curlError}");
            throw new Exception('This rate is no longer available. Please select another room.');
        }

        $data = json_decode($res, true);
        $newHash = $data['data']['hotels'][0]['rates'][0]['book_hash'] ?? null;

        if (!isset($data['status']) || $data['status'] !== 'ok' || empty($newHash)) {
            $err = $data['error'] ?? 'unknown_error';
            $isPriceIssue = in_array($err, ['price_changed', 'rate_price_changed', 'no_rate', 'rate_not_found'], true);
            throw new Exception($isPriceIssue
                ? "This room's price changed by more than {$priceThresholdPct}%. Please select the room again to see the updated price."
                : 'This rate is no longer available. Please select another room.');
        }

        // ETG 3.5 / 3.7 name prebook as a valid (and, right before booking, the
        // most current) source for excluded taxes and cancellation policy — the
        // guest should never see stale terms locked in at search/hotelpage time.
        $newRate = $data['data']['hotels'][0]['rates'][0] ?? [];
        $newPaymentType = $newRate['payment_options']['payment_types'][0] ?? [];

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'data' => [
                'book_hash' => $newHash,
                'already_prebooked' => false,
                'excluded_taxes' => rh_extract_excluded_taxes($newPaymentType),
                'cancellation_policy' => rh_parse_cancellation_policy(
                    $newPaymentType['cancellation_penalties'] ?? [],
                    strtoupper((string) ($newPaymentType['show_currency_code'] ?? $newPaymentType['currency_code'] ?? ''))
                ),
            ],
        ]);
        exit;
    } catch (\Throwable $e) {
        ob_end_clean();
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
});
