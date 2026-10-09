<?php
// ============================================================================
// TRAIN BOOKING (RAIL) — CORE API HELPERS
// ============================================================================
// Low-level HTTP requests, dynamic credentials loading, and helper utilities.
// Single-responsibility: NO route registration here.
// ============================================================================

@$SECURE or die('Access Denied!');

// ----------------------------------------------------------------------------
// CONFIG RETRIEVER
// ----------------------------------------------------------------------------
if (!function_exists('_train_cfg')) {
    function _train_cfg($db)
    {
        try {
            $row = $db->get('modules', '*', ['type' => 'rail', 'name' => 'train']);
            if (!$row) {
                $row = $db->get('modules', '*', ['name' => 'train']);
            }
        } catch (Throwable $e) {
            return [];
        }
        return is_array($row) ? $row : [];
    }
}

// ----------------------------------------------------------------------------
// API CREDENTIALS
// ----------------------------------------------------------------------------
if (!function_exists('_train_base_url')) {
    function _train_base_url($cfg)
    {
        $url = trim((string)($cfg['c2'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        return 'http://121.43.107.128'; // fallback
    }
}

if (!function_exists('_train_api_key')) {
    function _train_api_key($cfg)
    {
        return trim((string)($cfg['c1'] ?? ''));
    }
}

if (!function_exists('_train_log_dir')) {
    function _train_log_dir(): string
    {
        return __DIR__ . '/logs';
    }
}

if (!function_exists('_train_ensure_log_dir')) {
    function _train_ensure_log_dir(): bool
    {
        $logDir = _train_log_dir();
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
                return false;
            }
        }

        return is_dir($logDir) && is_writable($logDir);
    }
}

if (!function_exists('_train_can_write_log')) {
    function _train_can_write_log(string $logFile): bool
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return false;
        }

        return !file_exists($logFile) || is_writable($logFile);
    }
}

if (!function_exists('_train_logging_enabled')) {
    function _train_logging_enabled($db): bool
    {
        if ($db === null || !function_exists('log_setting')) {
            return true;
        }
        try {
            $setting = log_setting($db, 'train');
            return $setting === '1' || $setting === 1;
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('_train_log_exchange')) {
    /**
     * Append full request/response exchange for every supplier API call.
     * Log file: modules/rail/train/logs/rail_api_YYYY-MM-DD.log
     */
    function _train_log_exchange(
        string $method,
        string $path,
        string $url,
        array $payload,
        $response,
        int $httpStatus = 0,
        ?bool $ok = null,
        ?string $error = null,
        int $attempt = 1
    ): void {
        if (!_train_ensure_log_dir()) {
            return;
        }

        $logDir  = _train_log_dir();
        $logFile = $logDir . '/rail_api_' . date('Y-m-d') . '.log';
        if (!_train_can_write_log($logFile)) {
            return;
        }

        $responseText = is_string($response)
            ? $response
            : json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entry = str_repeat('=', 80) . "\n"
            . date('Y-m-d H:i:s') . " — {$method} {$path}"
            . ($attempt > 1 ? " (attempt {$attempt})" : '') . "\n"
            . "URL: {$url}\n"
            . 'HTTP: ' . $httpStatus
            . ($ok === null ? '' : (' | OK: ' . ($ok ? 'true' : 'false')))
            . ($error ? (' | Error: ' . $error) : '') . "\n"
            . "REQUEST:\n"
            . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "RESPONSE:\n"
            . ($responseText !== false ? $responseText : '') . "\n";

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

// ----------------------------------------------------------------------------
// HTTP CLIENT WRAPPER (POST WITH JSON AND APIKEY HEADER)
// ----------------------------------------------------------------------------
if (!function_exists('_train_request')) {
    /**
     * @param  string $path     API endpoint path (e.g. '/ticket/trainQuery')
     * @param  array  $body     Payload array
     * @param  array  $opts     Keys: db (object), cfg (array), timeout (int), retries (int)
     * @return array  {ok, status, data, error, raw}
     */
    function _train_request($path, array $body = [], array $opts = [])
    {
        $cfg     = $opts['cfg']     ?? [];
        $db      = $opts['db']      ?? null;
        $timeout = (int)($opts['timeout'] ?? 30);
        // Retries are opt-in and should ONLY be used for idempotent, read-only calls
        // (e.g. trainQuery/trainWayQuery search). Order/cancel/refund/issue calls must
        // NOT retry, since a retried request could place/cancel/refund a duplicate order.
        $retries = max(0, (int)($opts['retries'] ?? 0));

        if (empty($cfg) && $db !== null) {
            $cfg = _train_cfg($db);
        }

        $apiKey  = _train_api_key($cfg);
        $baseUrl = _train_base_url($cfg);
        $url     = $baseUrl . $path;

        $headers = [
            'Content-Type: application/json',
            'apiKey: ' . $apiKey,
        ];

        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $attempts = $retries + 1;
        $result   = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL verification for ease of testing
            ]);

            $raw    = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            $logPayload = [
                'endpoint' => $path,
                'url'      => $url,
                'method'   => 'POST',
                'body'     => $body,
            ];

            if ($raw === false) {
                $result = ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err, 'raw' => ''];

                if (_train_logging_enabled($db)) {
                    _train_log_exchange('POST', $path, $url, $logPayload, [
                        'transport_error' => $err,
                    ], 0, false, $err, $attempt);
                }
            } else {
                $decoded = json_decode($raw, true);
                $ok      = ($status === 200) && isset($decoded['code']) && ($decoded['code'] == 200 || $decoded['code'] == '200');

                $result = [
                    'ok'     => $ok,
                    'status' => $status,
                    'data'   => $decoded,
                    'error'  => $ok ? null : ($decoded['msg'] ?? $err ?? 'API returned failure code'),
                    'raw'    => $raw,
                ];

                if (_train_logging_enabled($db)) {
                    _train_log_exchange(
                        'POST',
                        $path,
                        $url,
                        $logPayload,
                        $decoded ?? $raw,
                        $status,
                        $ok,
                        $result['error'],
                        $attempt
                    );
                }

                if ($status > 0 && $status < 500) {
                    break;
                }
            }

            if (_train_logging_enabled($db) && _train_ensure_log_dir() && function_exists('logApiCall')) {
                $logDir   = _train_log_dir();
                $date     = date('Y-m-d');
                $reqFile  = $logDir . '/train_requests_' . $date . '.json';
                $respFile = $logDir . '/train_responses_' . $date . '.json';
                if (_train_can_write_log($reqFile) && _train_can_write_log($respFile)) {
                    logApiCall(
                        'TRAIN_API:' . $path,
                        $logPayload,
                        $raw === false ? ['transport_error' => $err] : ($raw ?? ''),
                        $status,
                        $logDir,
                        'train'
                    );
                }
            }

            if ($attempt < $attempts) {
                usleep(300000); // 300ms backoff before retrying a transient failure
            }
        }

        return $result;
    }
}

if (!function_exists('_train_order_result_data')) {
    /** Extract the inner data object from an orderResultData API response. */
    function _train_order_result_data(array $res): ?array
    {
        $data = $res['data']['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        // Supplier may return code 0 with fail_msg only on the top-level msg field.
        if (trim((string)($data['fail_msg'] ?? '')) === '') {
            $topMsg = trim((string)($res['data']['msg'] ?? ''));
            if ($topMsg !== '') {
                $data['fail_msg'] = $topMsg;
            }
        }

        return $data;
    }
}

if (!function_exists('_train_order_has_seats')) {
    function _train_order_has_seats(array $data): bool
    {
        foreach (($data['journey'] ?? []) as $leg) {
            foreach (($leg['passengers'] ?? []) as $p) {
                if (!empty($p['train_seat_no']) || !empty($p['train_coach_no'])) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('_train_apply_order_result')) {
    /**
     * Persist orderResultData / offlinePush payload onto the local booking row.
     *
     * @return array{action:string,rows_matched:int}
     */
    function _train_apply_order_result($db, string $mainOrderId, array $data, $storedResponse = null): array
    {
        if ($mainOrderId === '') {
            return ['action' => 'skipped_empty_order_id', 'rows_matched' => 0];
        }

        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        $hasSeatAssignment = _train_order_has_seats($data);
        $encodedResponse = is_string($storedResponse)
            ? $storedResponse
            : json_encode($storedResponse ?? ['data' => $data]);

        if ($failMsg !== '') {
            // PNR exists — booking is confirmed; only seat numbers are still pending.
            $updated = $db->update('bookings', [
                'booking_status'   => 'confirmed',
                'booking_response' => $encodedResponse,
                'error_response'   => json_encode([
                    'fail_msg'    => $failMsg,
                    'fail_msg_en' => _train_human_fail_message($failMsg),
                    'source'      => 'orderResultData',
                ]),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);

            return [
                'action'        => 'seats_pending',
                'rows_matched'  => $updated ? $updated->rowCount() : 0,
            ];
        }

        if ($hasSeatAssignment) {
            $updated = $db->update('bookings', [
                'booking_status'   => 'confirmed',
                'booking_response' => $encodedResponse,
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);

            return [
                'action'        => 'confirmed',
                'rows_matched'  => $updated ? $updated->rowCount() : 0,
            ];
        }

        return ['action' => 'still_pending_no_update', 'rows_matched' => 0];
    }
}

if (!function_exists('_train_issue_booking')) {
    /**
     * Place a paid rail booking with the supplier (POST /ticket/order).
     * Used by admin Issue Booking, payment gateway, and payment webhooks.
     *
     * @return array{status:bool,message?:string,Prn?:string,pnr?:string,booking_status?:string,seats_assigned?:bool,fail_msg?:string,fail_msg_en?:string,response_error?:string}
     */
    function _train_issue_booking($db, string $invoiceId): array
    {
        if ($invoiceId === '') {
            return ['status' => false, 'message' => 'Invoice ID required'];
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            return ['status' => false, 'message' => 'Booking not found'];
        }

        if (!empty($booking['pnr'])) {
            return [
                'status'         => true,
                'Prn'            => $booking['pnr'],
                'pnr'            => $booking['pnr'],
                'booking_status' => $booking['booking_status'],
                'message'        => 'Booking already issued',
            ];
        }

        $orderInput = json_decode($booking['booking_data'] ?? '', true);
        if (!is_array($orderInput) || empty($orderInput['cus_main_order_id']) || empty($orderInput['journey']) || empty($orderInput['passengers'])) {
            return ['status' => false, 'message' => 'Original booking data missing, cannot issue'];
        }

        $callbackRoot = defined('root') ? root : '';
        if ($callbackRoot === '' && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $callbackRoot = $protocol . $_SERVER['HTTP_HOST'] . '/';
        }

        $orderPayload = [
            'cus_main_order_id' => $orderInput['cus_main_order_id'],
            'journey' => array_map(static function ($leg) {
                return [
                    'cus_order_id'      => $leg['cus_order_id']      ?? '',
                    'journey_type'      => $leg['journey_type']      ?? 1,
                    'traffic_no'        => $leg['traffic_no']        ?? '',
                    'from_station_code' => $leg['from_station_code'] ?? '',
                    'to_station_code'   => $leg['to_station_code']   ?? '',
                    'from_date_time'    => $leg['from_date_time']    ?? '',
                    'to_date_time'      => $leg['to_date_time']      ?? '',
                    'seat_class'        => $leg['seat_class']        ?? '',
                    'price_total_limit' => $leg['price_total_limit'] ?? 0,
                    'end_datetime'      => $leg['end_datetime']      ?? '',
                ];
            }, $orderInput['journey']),
            'passengers' => array_map(static function ($p) {
                return [
                    'passenger_first_name'    => $p['passenger_first_name']    ?? '',
                    'passenger_last_name'     => $p['passenger_last_name']     ?? '',
                    'passenger_type'          => $p['passenger_type']          ?? 1,
                    'passenger_card_type'     => $p['passenger_card_type']     ?? '',
                    'passenger_card_no'       => $p['passenger_card_no']       ?? '',
                    'passenger_sex_code'      => $p['passenger_sex_code']      ?? '',
                    'passenger_birth_date'    => $p['passenger_birth_date']    ?? '',
                    'passenger_country_code'  => $p['passenger_country_code']  ?? '',
                    'passenger_card_validity' => $p['passenger_card_validity'] ?? '',
                ];
            }, $orderInput['passengers']),
            'callBackUrl' => $orderInput['callBackUrl'] ?? (rtrim($callbackRoot, '/') . '/ticket/offlinePush'),
        ];

        $orderPayload = _train_normalize_order_payload($orderPayload, $db);
        $res = _train_request('/ticket/order', $orderPayload, ['db' => $db]);

        if (empty($res['ok']) || empty($res['data']['data']['main_order_id'])) {
            $errMsg = $res['data']['msg'] ?? $res['error'] ?? 'Train order request failed';
            $db->update('bookings', [
                'error_response' => json_encode($res['data'] ?? ['error' => $errMsg]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            return [
                'status'         => false,
                'message'        => $errMsg,
                'response_error' => $errMsg,
            ];
        }

        $mainOrderId   = (string)$res['data']['data']['main_order_id'];
        $orderResponse = json_encode($res['data']);

        $db->update('bookings', [
            'pnr'              => $mainOrderId,
            'booking_response' => $orderResponse,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $pollData = _train_poll_order_result($db, $mainOrderId, 3, 2000);

        if (is_array($pollData) && _train_order_ticketing_failed($pollData)) {
            $failMsg   = trim((string)($pollData['fail_msg'] ?? ''));
            $failMsgEn = _train_human_fail_message($failMsg);

            $db->update('bookings', [
                'booking_status'   => 'confirmed',
                'error_response'   => json_encode([
                    'fail_msg'     => $failMsg,
                    'fail_msg_en'  => $failMsgEn,
                    'order_status' => (int)($pollData['order_status'] ?? 0),
                    'source'       => 'orderResultData',
                ]),
                'booking_response' => json_encode(['order' => json_decode($orderResponse, true), 'poll' => $pollData]),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            // PNR issued — booking confirmed; fail_msg may only mean seats not assigned yet.
            return [
                'status'         => true,
                'Prn'            => $mainOrderId,
                'pnr'            => $mainOrderId,
                'booking_status' => 'confirmed',
                'seats_assigned' => false,
                'message'        => 'Train order placed with supplier. Seat assignment pending.',
                'fail_msg'       => $failMsg,
                'fail_msg_en'    => $failMsgEn,
            ];
        }

        $hasSeats = is_array($pollData) && _train_order_has_seats($pollData);
        $bookingResponse = $orderResponse;
        if (is_array($pollData)) {
            $bookingResponse = json_encode(['order' => json_decode($orderResponse, true), 'poll' => $pollData]);
        }

        $db->update('bookings', [
            'booking_status'   => 'confirmed',
            'booking_response' => $bookingResponse,
            'error_response'   => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        return [
            'status'            => true,
            'Prn'               => $mainOrderId,
            'pnr'               => $mainOrderId,
            'booking_reference' => $mainOrderId,
            'booking_status'    => 'confirmed',
            'seats_assigned'    => $hasSeats,
            'message'           => $hasSeats
                ? 'Train order ticketed successfully.'
                : 'Train order placed with supplier. Seat assignment pending.',
        ];
    }
}

if (!function_exists('_train_issue_rail_after_payment')) {
    /** Issue a paid rail booking if PNR is not set yet (used after payment). */
    function _train_issue_rail_after_payment($db, string $invoiceId): array
    {
        if ($invoiceId === '') {
            return ['status' => false, 'message' => 'Invoice ID required'];
        }

        $booking = $db->get('bookings', ['module_type', 'pnr'], ['invoice_id' => $invoiceId]);
        if (!$booking || ($booking['module_type'] ?? '') !== 'rail') {
            return ['status' => false, 'message' => 'Not a rail booking'];
        }

        if (!empty($booking['pnr'])) {
            return [
                'status'  => true,
                'pnr'     => $booking['pnr'],
                'message' => 'Already issued',
            ];
        }

        return _train_issue_booking($db, $invoiceId);
    }
}

if (!function_exists('_train_human_fail_message')) {
    function _train_human_fail_message(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        $known = [
            '未匹配到供应商，分单停止。' => 'No railway supplier could be matched for this route. Tickets could not be issued.',
            '未匹配到供应商，分单停止'  => 'No railway supplier could be matched for this route. Tickets could not be issued.',
            '第1个乘客的证件类型不正确'   => 'Invalid document type for passenger 1. Use Passport (B) for Jakarta–Bandung routes, or the correct ID type for China routes.',
        ];

        if (preg_match('/第(\d+)个乘客的证件类型不正确/u', $message, $m)) {
            return 'Invalid document type for passenger ' . $m[1] . '. Use Passport (B) for Jakarta–Bandung routes, or the correct ID type for China routes.';
        }

        return $known[$message] ?? $message;
    }
}

if (!function_exists('_train_seat_classes')) {
    /** @return array<string,string> code => English label */
    function _train_seat_classes(): array
    {
        return [
            'W' => 'No Seat',
            '1' => 'Hard Seat',
            '2' => 'Soft Seat',
            '3' => 'Hard Sleeper',
            '4' => 'Soft Sleeper',
            '5' => 'Hard Sleeper Compartment',
            '6' => 'Deluxe Soft Sleeper',
            '7' => 'First-Class Seat',
            '8' => 'Second-Class Seat',
            '9' => 'Business Class Seat',
            'A' => 'Deluxe Sleeper EMU',
            'B' => 'Mixed Hard Seat',
            'C' => 'Mixed Hard Sleeper',
            'D' => 'Preferred First-Class Seat',
            'E' => 'Premium Soft Seat',
            'F' => 'Sleeper EMU',
            'G' => 'Two-Person Soft Compartment',
            'H' => 'Single Soft Compartment',
            'I' => 'First-Class Sleeper',
            'J' => 'Second-Class Sleeper',
            'K' => 'Mixed Soft Seat',
            'L' => 'Mixed Soft Sleeper',
            'M' => 'First-Class Seat',
            'O' => 'Second-Class Seat',
            'P' => 'Premium Seat',
            'Q' => 'Multi-Function Seat',
            'S' => 'Second-Class Compartment Seat',
        ];
    }
}

if (!function_exists('_train_seat_class_label')) {
    function _train_seat_class_label(string $code, $db = null): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        if ($db !== null) {
            $row = _train_seat_class_row($db, $code);
            if ($row && !empty($row['label'])) {
                return (string)$row['label'];
            }
        }

        return _train_seat_classes()[$code] ?? $code;
    }
}

if (!function_exists('_train_seat_classes_for_api')) {
    /** @return list<array{code:string,label:string}> */
    function _train_seat_classes_for_api(): array
    {
        $out = [];
        foreach (_train_seat_classes() as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }
}

if (!function_exists('_train_ensure_seat_classes_table')) {
    function _train_ensure_seat_classes_table($db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `rail_seat_classes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(5) NOT NULL UNIQUE,
            `label` VARCHAR(120) NOT NULL,
            `tier` VARCHAR(20) NOT NULL DEFAULT 'second',
            `sort_order` INT NOT NULL DEFAULT 0,
            KEY `idx_tier` (`tier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        _train_seed_seat_classes($db);
    }
}

if (!function_exists('_train_seed_seat_classes')) {
    function _train_seed_seat_classes($db): void
    {
        $tiers = _train_seat_class_tiers();
        $order = 0;
        foreach (_train_seat_classes() as $code => $label) {
            $tier = _train_seat_class_tier($code);
            $existing = $db->get('rail_seat_classes', ['id', 'label', 'tier'], ['code' => $code]);
            if ($existing) {
                $db->update('rail_seat_classes', [
                    'label'      => $label,
                    'tier'       => $tier,
                    'sort_order' => $order,
                ], ['id' => $existing['id']]);
            } else {
                $db->insert('rail_seat_classes', [
                    'code'       => $code,
                    'label'      => $label,
                    'tier'       => $tier,
                    'sort_order' => $order,
                ]);
            }
            $order++;
        }
    }
}

if (!function_exists('_train_seat_class_catalog')) {
    /**
     * Seat class definitions from rail_seat_classes table.
     *
     * @return list<array{code:string,label:string,tier:string,tier_label:string}>
     */
    function _train_seat_class_catalog($db): array
    {
        _train_ensure_seat_classes_table($db);

        $tierLabels = [
            'second'   => 'Second Class',
            'first'    => 'First Class',
            'business' => 'Business Class',
        ];

        $rows = $db->select('rail_seat_classes', ['code', 'label', 'tier'], [
            'ORDER' => ['sort_order' => 'ASC', 'code' => 'ASC'],
        ]);

        $out = [];
        foreach ($rows as $row) {
            $tier = strtolower((string)($row['tier'] ?? 'second'));
            $out[] = [
                'code'       => strtoupper((string)$row['code']),
                'label'      => (string)$row['label'],
                'tier'       => $tier,
                'tier_label' => $tierLabels[$tier] ?? ucfirst($tier),
            ];
        }

        return $out;
    }
}

if (!function_exists('_train_seat_class_row')) {
    function _train_seat_class_row($db, string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        _train_ensure_seat_classes_table($db);
        $row = $db->get('rail_seat_classes', ['code', 'label', 'tier'], ['code' => $code]);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('_train_filter_train_seats')) {
    /**
     * Filter train seat rows by selected seat class codes (server-side).
     *
     * @param  list<string> $selectedCodes  Uppercase seat_class codes; empty = hide all
     */
    function _train_filter_train_seats(array $seats, array $selectedCodes, float $priceMin = 0, float $priceMax = PHP_FLOAT_MAX): array
    {
        if ($selectedCodes === []) {
            return [];
        }

        $allowed = array_flip(array_map(static fn($c) => strtoupper(trim((string)$c)), $selectedCodes));

        return array_values(array_filter($seats, static function ($seat) use ($allowed, $priceMin, $priceMax) {
            if (!is_array($seat)) {
                return false;
            }
            $code = strtoupper(trim((string)($seat['seat_class'] ?? $seat['seat_type'] ?? '')));
            if ($code === '' || !isset($allowed[$code])) {
                return false;
            }
            $price = (float)($seat['price'] ?? 0);
            return $price >= $priceMin && $price <= $priceMax;
        }));
    }
}

if (!function_exists('_train_seat_class_tiers')) {
    /**
     * Map supplier seat_class codes to listing filter tiers.
     *
     * @return array{business:string[],first:string[],second:string[]}
     */
    function _train_seat_class_tiers(): array
    {
        return [
            'business' => ['9', 'P', 'Q', 'E'],
            'first'    => ['M', '7', 'D', 'I', '6', '4', 'A', 'F', 'G', 'H'],
            'second'   => ['O', '8', 'S', '1', '2', '3', '5', 'B', 'C', 'J', 'K', 'L', 'W'],
        ];
    }
}

if (!function_exists('_train_seat_class_tier')) {
    /** @return 'business'|'first'|'second' */
    function _train_seat_class_tier(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 'second';
        }

        foreach (_train_seat_class_tiers() as $tier => $codes) {
            if (in_array($code, $codes, true)) {
                return $tier;
            }
        }

        $label = strtoupper(_train_seat_class_label($code));
        if (str_contains($label, 'BUSINESS') || str_contains($label, 'MULTI-FUNCTION')) {
            return 'business';
        }
        if (str_contains($label, 'FIRST') || str_contains($label, 'DELUXE')) {
            return 'first';
        }

        return 'second';
    }
}

if (!function_exists('_train_supplier_order_price')) {
    /** Supplier ceiling price for /ticket/order (minPrice/midPrice — not marked-up display price). */
    function _train_supplier_order_price(array $seat): float
    {
        foreach (['minPrice', 'midPrice', 'maxPrice', 'original_price'] as $key) {
            if (isset($seat[$key]) && (float)$seat[$key] > 0) {
                return round((float)$seat[$key], 2);
            }
        }

        return round((float)($seat['price'] ?? 0), 2);
    }
}

if (!function_exists('_train_apply_seat_price_markup')) {
    /** Apply display markup while keeping supplier_order_price for ticketing. */
    function _train_apply_seat_price_markup(array &$seat, $db, string $displayCurrency = 'USD'): void
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $supplierOrderPrice = _train_supplier_order_price($seat);
        $seat['supplier_order_price'] = $supplierOrderPrice;

        // Mark up supplier net (minPrice), not the supplier retail list price field.
        $displayBase = $supplierOrderPrice;
        if ($displayBase <= 0) {
            $displayBase = (float)($seat['price'] ?? 0);
        }

        $markupData = MARKUP($displayBase, 'rail', $db, 'USD', $displayCurrency);
        $seat['original_price'] = $supplierOrderPrice;
        $seat['price']          = (float)$markupData['price'];
        $seat['markup']         = (float)$markupData['markup'];
        $seat['currency']       = $displayCurrency;
    }
}

if (!function_exists('_train_target_currency')) {
    function _train_target_currency(array $input = []): string
    {
        $currency = strtoupper(trim((string)($input['currency'] ?? $_SESSION['app_currency'] ?? 'USD')));
        return $currency !== '' ? $currency : 'USD';
    }
}

if (!function_exists('_train_billable_passenger_count')) {
    /** Passengers charged a ticket (free infants / no-seat infants are excluded). */
    function _train_billable_passenger_count(int $journeyType, int $adults, int $children, int $infants): int
    {
        $adults = max(0, $adults);
        $children = max(0, $children);
        $infants = max(0, $infants);
        $mode = (string)(_train_region_policy($journeyType)['child_policy_mode'] ?? 'age');

        $billable = $adults + $children;

        if ($mode === 'infant_age') {
            $billable += max(0, $infants - min($infants, $adults));
        }

        return max(1, $billable);
    }
}

if (!function_exists('_train_free_infant_count')) {
    function _train_free_infant_count(int $journeyType, int $adults, int $infants): int
    {
        $infants = max(0, $infants);
        if ($infants <= 0) {
            return 0;
        }

        $mode = (string)(_train_region_policy($journeyType)['child_policy_mode'] ?? 'age');
        if ($mode === 'infant_age') {
            return min($infants, max(0, $adults));
        }

        return $infants;
    }
}

if (!function_exists('_train_billable_passengers_from_list')) {
    /** Count ticketed passengers from an order payload (type 3 = free infant / no seat). */
    function _train_billable_passengers_from_list(array $passengers): int
    {
        $billable = 0;
        foreach ($passengers as $p) {
            if (!is_array($p)) {
                continue;
            }
            if ((int)($p['passenger_type'] ?? 1) === 3) {
                continue;
            }
            $billable++;
        }
        return max(1, $billable);
    }
}

if (!function_exists('_train_calculate_display_price')) {
    /**
     * Apply rail markup and convert supplier USD pricing to the user's display currency.
     *
     * @return array{display_currency:string,display_total:float,display_per_seat:float,supplier_per_seat_usd:float,supplier_total_usd:float,markup:float}
     */
    function _train_calculate_display_price($db, float $supplierUsdPerSeat, int $passengers, array $input = []): array
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $passengers = max(1, $passengers);
        $supplierUsdPerSeat = max(0, $supplierUsdPerSeat);
        $displayCurrency = _train_target_currency($input);
        $supplierTotalUsd = round($supplierUsdPerSeat * $passengers, 2);

        // Per-seat markup then multiply — matches listing and fixed-markup modules.
        $markupData = MARKUP($supplierUsdPerSeat, 'rail', $db, 'USD', $displayCurrency);
        $displayPerSeat = (float)$markupData['price'];
        $displayTotal = round($displayPerSeat * $passengers, 2);
        $markupTotal = round((float)$markupData['markup'] * $passengers, 2);

        return [
            'display_currency'      => $displayCurrency,
            'display_total'         => $displayTotal,
            'display_per_seat'      => $displayPerSeat,
            'supplier_per_seat_usd' => round($supplierUsdPerSeat, 2),
            'supplier_total_usd'    => $supplierTotalUsd,
            'markup'                => $markupTotal,
        ];
    }
}

if (!function_exists('_train_apply_booking_markup')) {
    /** Markup + currency conversion for a supplier USD order total at checkout. */
    function _train_apply_booking_markup($db, float $supplierTotalUsd, array $input = []): array
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $displayCurrency = _train_target_currency($input);
        $supplierTotalUsd = max(0, $supplierTotalUsd);

        $passengers = max(1, (int)($input['passengers_count'] ?? 0));
        if (!empty($input['passengers']) && is_array($input['passengers'])) {
            $passengers = _train_billable_passengers_from_list($input['passengers']);
        } elseif ($passengers <= 1) {
            $journeyType = (int)($input['journey'][0]['journey_type'] ?? $input['journey_type'] ?? 1);
            $adults = max(0, (int)($input['adults'] ?? 0));
            $children = max(0, (int)($input['children'] ?? 0));
            $infants = max(0, (int)($input['infants'] ?? 0));
            $passengers = _train_billable_passenger_count($journeyType, $adults, $children, $infants);
        }

        $supplierPerSeat = $supplierTotalUsd / $passengers;
        $markupData = MARKUP($supplierPerSeat, 'rail', $db, 'USD', $displayCurrency);
        $finalPrice = round((float)$markupData['price'] * $passengers, 2);
        $commission = round((float)$markupData['markup'] * $passengers, 2);

        return [
            'display_currency'   => $displayCurrency,
            'final_price'        => $finalPrice,
            'commission'         => $commission,
            'supplier_total_usd' => round($supplierTotalUsd, 2),
        ];
    }
}

if (!function_exists('_train_poll_order_result')) {
    /** Poll orderResultData until seats, fail_msg, or attempts exhausted. */
    function _train_poll_order_result($db, string $mainOrderId, int $maxAttempts = 3, int $sleepMs = 2000): ?array
    {
        $mainOrderId = trim($mainOrderId);
        if ($mainOrderId === '') {
            return null;
        }

        $last = null;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0) {
                usleep(max(0, $sleepMs) * 1000);
            }
            $res = _train_request('/ticket/orderResultData', ['main_order_id' => $mainOrderId], ['db' => $db]);
            $data = _train_order_result_data($res);
            if (!is_array($data)) {
                continue;
            }
            $last = $data;
            $failMsg = trim((string)($data['fail_msg'] ?? ''));
            if ($failMsg !== '' || _train_order_has_seats($data)) {
                return $data;
            }
        }

        return $last;
    }
}

if (!function_exists('_train_order_ticketing_failed')) {
    function _train_order_ticketing_failed(array $data): bool
    {
        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        if ($failMsg === '') {
            return false;
        }
        if (_train_order_has_seats($data)) {
            return false;
        }
        $status = (int)($data['order_status'] ?? 0);
        return $status === 3 || $status === 0;
    }
}

if (!function_exists('_train_enrich_seat_labels')) {
    /** Add seat_class_label to each seat row (mutates array in place). */
    function _train_enrich_seat_labels(array &$seats): void
    {
        foreach ($seats as &$seat) {
            if (!is_array($seat)) {
                continue;
            }
            $code = strtoupper(trim((string)($seat['seat_class'] ?? $seat['seat_type'] ?? '')));
            $seat['seat_class_code']  = $code;
            $seat['seat_class_label'] = _train_seat_class_label($code);
            if (empty($seat['seat_name_english']) && empty($seat['seat_name'])) {
                $seat['seat_name_english'] = $seat['seat_class_label'];
            }
        }
        unset($seat);
    }
}

if (!function_exists('_train_normalize_passenger_country_code')) {
    /**
     * Resolve passenger_country_code from countries table iso3 column.
     * Booking form stores countries.iso (2-letter); supplier API expects iso3.
     */
    function _train_normalize_passenger_country_code(string $code, $db = null): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || $db === null) {
            return $code;
        }

        if (strlen($code) === 2) {
            $iso3 = $db->get('countries', 'iso3', ['iso' => $code]);
            return !empty($iso3) ? strtoupper((string)$iso3) : $code;
        }

        if (strlen($code) === 3) {
            $iso3 = $db->get('countries', 'iso3', ['iso3' => $code]);
            return !empty($iso3) ? strtoupper((string)$iso3) : $code;
        }

        return $code;
    }
}

if (!function_exists('_train_normalize_order_payload')) {
    /**
     * Normalize /ticket/order payload before sending to supplier.
     */
    function _train_normalize_order_payload(array $payload, $db = null): array
    {
        if (trim((string)($payload['callBackUrl'] ?? '')) === '') {
            $payload['callBackUrl'] = rtrim(root, '/') . '/ticket/offlinePush';
        }

        if (!empty($payload['journey']) && is_array($payload['journey'])) {
            foreach ($payload['journey'] as &$leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $leg['from_date_time'] = (int)($leg['from_date_time'] ?? 0);
                $leg['to_date_time']   = (int)($leg['to_date_time'] ?? 0);
                $leg['end_datetime']   = (int)($leg['end_datetime'] ?? 0);
                if ($leg['end_datetime'] <= 0 || ($leg['from_date_time'] > 0 && $leg['end_datetime'] < $leg['from_date_time'])) {
                    $leg['end_datetime'] = $leg['from_date_time'];
                }
                if (isset($leg['price_total_limit_original']) && (float)$leg['price_total_limit_original'] > 0) {
                    $leg['price_total_limit'] = round((float)$leg['price_total_limit_original'], 2);
                } else {
                    $leg['price_total_limit'] = round((float)($leg['price_total_limit'] ?? 0), 2);
                }
                unset($leg['price_total_limit_original']);
            }
            unset($leg);
        }

        if (!empty($payload['passengers']) && is_array($payload['passengers'])) {
            foreach ($payload['passengers'] as &$p) {
                if (!is_array($p)) {
                    continue;
                }
                $p['passenger_first_name'] = strtoupper(trim((string)($p['passenger_first_name'] ?? '')));
                $p['passenger_last_name']  = strtoupper(trim((string)($p['passenger_last_name'] ?? '')));
                $p['passenger_card_no']    = strtoupper(trim((string)($p['passenger_card_no'] ?? '')));
                $p['passenger_card_type']  = strtoupper(trim((string)($p['passenger_card_type'] ?? '')));
                // Legacy UI sent "A" for ID card; supplier API uses "1".
                if ($p['passenger_card_type'] === 'A') {
                    $p['passenger_card_type'] = '1';
                }
                $p['passenger_sex_code']   = strtoupper(trim((string)($p['passenger_sex_code'] ?? 'M')));

                $cc = strtoupper(trim((string)($p['passenger_country_code'] ?? '')));
                $p['passenger_country_code'] = _train_normalize_passenger_country_code($cc, $db);
            }
            unset($p);
        }

        return $payload;
    }
}

if (!function_exists('_train_region_policies')) {
    /**
     * Booking rules per journey_type (1=China, 2=Laos–China, 3=Jakarta–Bandung).
     * Child metric in search URLs: age (years) for types 1 & 3, height (cm) for type 2.
     */
    function _train_region_policies(): array
    {
        return [
            1 => [
                'journey_type'            => 1,
                'label'                   => 'China Railway Tickets',
                'child_policy_mode'       => 'age',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Age 14+',
                'child_label'             => 'Children',
                'child_hint'              => 'Age 6–14',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 6 (free, no seat)',
                'show_child_category'     => true,
                'child_metric_label'      => 'Age',
                'child_metric_unit'       => 'years',
                'infant_metric_label'     => 'Age',
                'infant_metric_unit'      => 'years',
                'infant_metric_min'       => 0,
                'infant_metric_max'       => 5,
                'infant_metric_default'   => 3,
                'child_metric_min'        => 6,
                'child_metric_max'        => 14,
                'child_metric_default'    => 8,
                'free_max_age'            => 5,
                'child_max_age'           => 14,
                'adult_min_age'           => 14,
                'inquiry_timezone'        => 'UTC+8',
                'inquiry_timezone_offset' => 8,
                'operating_hours'         => [
                    'start'     => '08:00',
                    'end'       => '23:00',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'Beijing Time (UTC+8)',
                ],
                'online_refund'           => true,
                'online_reschedule'       => true,
                'id_must_be_genuine'      => true,
                'rules'                   => [
                    'Operating hours: 08:00–23:00 (Beijing Time, UTC+8).',
                    'Children under 6 travel free without a seat; ages 6–14 purchase discounted child tickets; passengers aged 14 and above purchase full-fare tickets. A child must be accompanied by an adult.',
                    'Ticket issuance, refund, and rescheduling are supported online.',
                    'Passenger name and ID number must be genuine.',
                ],
                'note'                    => 'Children under 6 travel free (no seat). Ages 6–14 use child tickets. Age 14+ is full fare. Each child must travel with an adult.',
            ],
            2 => [
                'journey_type'            => 2,
                'label'                   => 'Laos–China Railway Tickets',
                'child_policy_mode'       => 'height',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Height 1.5 m and above',
                'child_label'             => 'Children',
                'child_hint'              => '1.2–1.5 m',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 1.2 m',
                'show_child_category'     => true,
                'child_metric_label'      => 'Height',
                'child_metric_unit'       => 'cm',
                'infant_metric_label'     => 'Height',
                'infant_metric_unit'      => 'cm',
                'infant_metric_min'       => 80,
                'infant_metric_max'       => 115,
                'infant_metric_default'   => 100,
                'child_metric_min'        => 120,
                'child_metric_max'        => 145,
                'child_metric_default'    => 130,
                'free_max_height_cm'      => 119,
                'child_max_height_cm'     => 149,
                'adult_min_height_cm'     => 150,
                'inquiry_timezone'        => 'UTC+7',
                'inquiry_timezone_offset' => 7,
                'operating_hours'         => [
                    'start'     => '07:30',
                    'end'       => '23:30',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'Beijing Time (UTC+8)',
                ],
                'online_refund'           => true,
                'online_reschedule'       => true,
                'id_must_be_genuine'      => false,
                'rules'                   => [
                    'Operating hours: 07:30–23:30 (Beijing Time, UTC+8). Booking inquiry timestamps use UTC+7.',
                    'Height under 1.2 m: free without a seat; 1.2–1.5 m: discounted child ticket (half price); 1.5 m and above: full-fare ticket. A child must be accompanied by an adult.',
                    'Ticket issuance, refund, and rescheduling are supported online.',
                ],
                'note'                    => 'Height under 1.2 m travels free (no seat). 1.2–1.5 m uses discounted child tickets. 1.5 m+ is full fare. Each child must travel with an adult.',
            ],
            3 => [
                'journey_type'            => 3,
                'label'                   => 'Jakarta–Bandung Railway Tickets',
                'child_policy_mode'       => 'infant_age',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Full fare ticket',
                'child_label'             => 'Children',
                'child_hint'              => '',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 3 years',
                'show_child_category'     => false,
                'infant_metric_label'     => 'Age',
                'infant_metric_unit'      => 'years',
                'infant_metric_min'       => 0,
                'infant_metric_max'       => 2,
                'infant_metric_default'   => 1,
                'child_metric_min'        => 0,
                'child_metric_max'        => 0,
                'child_metric_default'    => 0,
                'infant_max_age'          => 2,
                'infants_per_adult_free'  => 1,
                'inquiry_timezone'        => 'UTC+7',
                'inquiry_timezone_offset' => 7,
                'operating_hours'         => [
                    'start'     => '00:00',
                    'end'       => '24:00',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'China Time (UTC+8)',
                ],
                'online_refund'           => false,
                'online_reschedule'       => false,
                'id_must_be_genuine'      => false,
                'rules'                   => [
                    'Operating hours: 00:00–24:00 (China Time, UTC+8). Booking inquiry timestamps use UTC+7.',
                    'Only one infant per adult may travel without a separate ticket; any additional accompanying infant(s) must be issued a ticket at the full adult fare. Infants must be under 3 years and added as passengers.',
                    'Online refunds and rescheduling are not supported — passengers shall request refunds/rescheduling at the train station.',
                ],
                'note'                    => 'One infant under 3 per adult travels without a separate ticket. Additional infants require a full adult-fare ticket. All infants must be added as passengers.',
            ],
        ];
    }
}

if (!function_exists('_train_region_policy')) {
    function _train_region_policy(int $journeyType = 1): array
    {
        $policies = _train_region_policies();
        return $policies[$journeyType] ?? $policies[1];
    }
}

if (!function_exists('_train_region_policies_for_api')) {
    function _train_region_policies_for_api(): array
    {
        $out = [];
        foreach (_train_region_policies() as $id => $policy) {
            $out[] = array_merge(['id' => $id], $policy);
        }
        return $out;
    }
}

if (!function_exists('_train_is_within_operating_hours')) {
    function _train_is_within_operating_hours(int $journeyType = 1, ?DateTimeInterface $at = null): bool
    {
        $hours = _train_region_policy($journeyType)['operating_hours'] ?? [];
        $start = (string)($hours['start'] ?? '00:00');
        $end = (string)($hours['end'] ?? '24:00');
        if ($start === '00:00' && $end === '24:00') {
            return true;
        }

        $tzName = (string)($hours['timezone'] ?? 'Asia/Shanghai');
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Shanghai');
        }

        $now = $at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($at)->setTimezone($tz)
            : new DateTimeImmutable('now', $tz);

        $day = $now->format('Y-m-d');
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day . ' ' . $start, $tz);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day . ' ' . $end, $tz);
        if (!$startDt || !$endDt) {
            return true;
        }

        return $now >= $startDt && $now <= $endDt;
    }
}

if (!function_exists('_train_child_age_policy')) {
    /** Supplier child fare rules (trainQuery + /ticket/order) for the given journey_type. */
    function _train_child_age_policy(int $journeyType = 1): array
    {
        $policy = _train_region_policy($journeyType);
        $out = [
            'journey_type'       => (int)$policy['journey_type'],
            'child_policy_mode'  => (string)($policy['child_policy_mode'] ?? 'age'),
            'note'               => (string)($policy['note'] ?? ''),
            'rules'              => $policy['rules'] ?? [],
        ];

        if (($policy['child_policy_mode'] ?? '') === 'height') {
            $out['free_max_height_cm']  = (int)($policy['free_max_height_cm'] ?? 119);
            $out['child_max_height_cm'] = (int)($policy['child_max_height_cm'] ?? 149);
            $out['adult_min_height_cm'] = (int)($policy['adult_min_height_cm'] ?? 150);
            return $out;
        }

        if (($policy['child_policy_mode'] ?? '') === 'infant_age') {
            $out['infant_max_age']         = (int)($policy['infant_max_age'] ?? 2);
            $out['infants_per_adult_free'] = (int)($policy['infants_per_adult_free'] ?? 1);
            return $out;
        }

        $out['free_max_age']  = (int)($policy['free_max_age'] ?? 5);
        $out['child_max_age'] = (int)($policy['child_max_age'] ?? 13);
        $out['adult_min_age'] = (int)($policy['adult_min_age'] ?? 14);
        return $out;
    }
}

if (!function_exists('_train_parse_category_metrics')) {
    /**
     * Parse age/height values for a passenger category (child or infant).
     *
     * @return int[]
     */
    function _train_parse_category_metrics($raw, int $expectedCount, int $journeyType, string $category = 'child'): array
    {
        $policy = _train_region_policy($journeyType);
        $prefix = $category === 'infant' ? 'infant_metric_' : 'child_metric_';
        $min = (int)($policy[$prefix . 'min'] ?? 0);
        $max = (int)($policy[$prefix . 'max'] ?? 17);
        $default = (int)($policy[$prefix . 'default'] ?? 6);

        if (is_array($raw)) {
            $values = array_map('intval', $raw);
        } elseif ($raw === null || $raw === '' || $raw === '0') {
            $values = [];
        } elseif (is_string($raw) && str_contains($raw, ',')) {
            $values = array_map('intval', explode(',', $raw));
        } else {
            $values = array_map('intval', explode('-', (string)$raw));
        }

        $values = array_values(array_filter(
            $values,
            static fn($value) => $value >= $min && $value <= $max
        ));

        if ($expectedCount <= 0) {
            return [];
        }

        if (count($values) > $expectedCount) {
            $values = array_slice($values, 0, $expectedCount);
        }
        while (count($values) < $expectedCount) {
            $values[] = $default;
        }

        return $values;
    }
}

if (!function_exists('_train_parse_passenger_metrics_bundle')) {
    /**
     * Parse combined metrics string: "6-8~1-2" (child ages ~ infant ages).
     *
     * @return array{child_ages:int[],infant_ages:int[]}
     */
    function _train_parse_passenger_metrics_bundle($raw, int $childCount, int $infantCount, int $journeyType = 1): array
    {
        $raw = (string)($raw ?? '0');
        $childPart = $raw;
        $infantPart = '';

        if (str_contains($raw, '~')) {
            [$childPart, $infantPart] = explode('~', $raw, 2);
        } elseif ($infantCount <= 0 && $childCount > 0) {
            $childPart = $raw;
        } elseif ($infantCount > 0 && $childCount <= 0) {
            $infantPart = $raw;
            $childPart = '0';
        }

        return [
            'child_ages'  => _train_parse_category_metrics($childPart, $childCount, $journeyType, 'child'),
            'infant_ages' => _train_parse_category_metrics($infantPart, $infantCount, $journeyType, 'infant'),
        ];
    }
}

if (!function_exists('_train_encode_passenger_metrics')) {
    function _train_encode_passenger_metrics(array $childAges, array $infantAges): string
    {
        $childPart = _train_encode_child_ages($childAges);
        $infantPart = _train_encode_child_ages($infantAges);
        if ($childPart === '0' && $infantPart === '0') {
            return '0';
        }
        if ($infantPart === '0') {
            return $childPart;
        }
        if ($childPart === '0') {
            return '~' . $infantPart;
        }
        return $childPart . '~' . $infantPart;
    }
}

if (!function_exists('_train_parse_child_ages')) {
    /**
     * Parse child age/height values from URL, session, or API input.
     *
     * @param  mixed $raw  "4-8-10", comma-separated, array, or empty
     * @return int[]
     */
    function _train_parse_child_ages($raw, int $expectedCount = 0, int $journeyType = 1): array
    {
        return _train_parse_category_metrics($raw, $expectedCount, $journeyType, 'child');
    }
}

if (!function_exists('_train_encode_child_ages')) {
    function _train_encode_child_ages(array $ages): string
    {
        if ($ages === []) {
            return '0';
        }
        return implode('-', array_map('intval', $ages));
    }
}

if (!function_exists('_train_passenger_type_for_age')) {
    /** Map age or height (cm) to supplier passenger_type: 1=Adult, 2=Child, 3=Free/no seat or infant. */
    function _train_passenger_type_for_age(int $value, int $journeyType = 1): int
    {
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');

        if ($mode === 'height') {
            if ($value <= (int)($policy['free_max_height_cm'] ?? 119)) {
                return 3;
            }
            if ($value < (int)($policy['adult_min_height_cm'] ?? 150)) {
                return 2;
            }
            return 1;
        }

        if ($mode === 'infant_age') {
            if ($value <= (int)($policy['infant_max_age'] ?? 2)) {
                return 3;
            }
            return 1;
        }

        if ($value <= (int)($policy['free_max_age'] ?? 5)) {
            return 3;
        }
        if ($value < (int)($policy['adult_min_age'] ?? 14)) {
            return 2;
        }
        return 1;
    }
}

if (!function_exists('_train_build_train_query_passenger_list')) {
    /**
     * Build supplier trainQuery passenger_type_list from adults + children + infants.
     *
     * @return array<int,array{passenger_type:int,passenger_num:int,passenger_age?:int}>
     */
    function _train_build_train_query_passenger_list(int $adults, array $childAges, int $journeyType = 1, array $infantAges = []): array
    {
        $adults = max(0, $adults);
        $journeyType = in_array($journeyType, [1, 2, 3], true) ? $journeyType : 1;
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');

        $list = [];
        if ($adults > 0) {
            $list[] = ['passenger_type' => 1, 'passenger_num' => $adults];
        }

        if ($mode === 'infant_age') {
            $freeInfantSlots = max(0, $adults);
            foreach ($infantAges as $age) {
                $age = max(0, min((int)($policy['infant_max_age'] ?? 2), (int)$age));
                if ($freeInfantSlots > 0) {
                    $list[] = ['passenger_type' => 3, 'passenger_num' => 1, 'passenger_age' => $age];
                    $freeInfantSlots--;
                    continue;
                }
                $list[] = ['passenger_type' => 1, 'passenger_num' => 1, 'passenger_age' => $age];
            }
            return $list;
        }

        foreach ($infantAges as $metric) {
            $metric = max(0, (int)$metric);
            $list[] = ['passenger_type' => 3, 'passenger_num' => 1, 'passenger_age' => $metric];
        }

        foreach ($childAges as $metric) {
            $metric = max(0, (int)$metric);
            $list[] = ['passenger_type' => 2, 'passenger_num' => 1, 'passenger_age' => $metric];
        }

        return $list;
    }
}

if (!function_exists('_train_apply_train_query_passengers')) {
    /** Merge passenger_type_list into a trainQuery payload when passengers are specified. */
    function _train_apply_train_query_passengers(array &$payload, int $adults = 0, array $childAges = [], array $infantAges = []): void
    {
        if (!empty($payload['passenger_type_list']) && is_array($payload['passenger_type_list'])) {
            return;
        }

        $journeyType = (int)($payload['journey_type'] ?? 1);
        $adults = max(0, (int)($payload['adults'] ?? $adults));
        $childrenCount = max(0, (int)($payload['children'] ?? 0));
        $infantCount = max(0, (int)($payload['infants'] ?? 0));

        if ($childAges === [] && $infantAges === []) {
            $metricsRaw = $payload['passenger_metrics'] ?? $payload['child_ages'] ?? $payload['child_age'] ?? [];
            if ($infantCount > 0 || str_contains((string)$metricsRaw, '~')) {
                $bundle = _train_parse_passenger_metrics_bundle($metricsRaw, $childrenCount, $infantCount, $journeyType);
                $childAges = $bundle['child_ages'];
                $infantAges = $bundle['infant_ages'];
            } else {
                $childAges = $childrenCount > 0
                    ? _train_parse_category_metrics($metricsRaw, $childrenCount, $journeyType, 'child')
                    : [];
            }
        }

        if ($adults < 1 && $childAges === [] && $infantAges === []) {
            $adults = 1;
        }

        $list = _train_build_train_query_passenger_list($adults, $childAges, $journeyType, $infantAges);
        if ($list !== []) {
            $payload['passenger_type_list'] = $list;
        }
    }
}

if (!function_exists('_train_journey_types')) {
    /**
     * Supplier journey_type codes (trainQuery + /ticket/order).
     * 1 = China Railway, 2 = Laos–China, 3 = Jakarta–Bandung (Whoosh).
     */
    function _train_journey_types(): array
    {
        return [
            1 => [
                'label'   => 'China Railway Ticket',
                'short'   => 'China Railway',
                'country' => 'China',
            ],
            2 => [
                'label'   => 'Laos–China Railway Ticket',
                'short'   => 'Laos–China',
                'country' => 'Laos / China',
            ],
            3 => [
                'label'   => 'Jakarta–Bandung Railway Ticket',
                'short'   => 'Jakarta–Bandung',
                'country' => 'Indonesia',
            ],
        ];
    }
}

if (!function_exists('_train_journey_type_label')) {
    function _train_journey_type_label(int $journeyType, bool $short = false): string
    {
        $types = _train_journey_types();
        if (!isset($types[$journeyType])) {
            return $short ? 'Jakarta–Bandung' : 'Jakarta–Bandung Railway Ticket';
        }
        return $short ? $types[$journeyType]['short'] : $types[$journeyType]['label'];
    }
}

if (!function_exists('_train_journey_types_for_api')) {
    function _train_journey_types_for_api(): array
    {
        $out = [];
        foreach (_train_journey_types() as $id => $meta) {
            $out[] = [
                'id'      => $id,
                'label'   => $meta['label'],
                'short'   => $meta['short'],
                'country' => $meta['country'],
            ];
        }
        return $out;
    }
}

if (!function_exists('_train_passenger_card_types')) {
    /**
     * Supplier passenger_card_type codes (order/issue API).
     * @see ID Document Type Enumeration in supplier API docs.
     */
    function _train_passenger_card_types(): array
    {
        return [
            '1' => 'PRC Resident ID / HK-Macao-Taiwan Residence Permit',
            'B' => 'Passport',
            'H' => 'Foreigner Permanent Residence ID',
            'C' => 'Mainland Travel Permit — Hong Kong & Macao',
            'G' => 'Mainland Travel Permit — Taiwan',
        ];
    }
}

if (!function_exists('_train_passenger_card_type_meta')) {
    /** Digit-length hints from supplier ID Document Type Enumeration. */
    function _train_passenger_card_type_meta(): array
    {
        return [
            '1' => ['digits' => 18, 'note' => 'China: PRC ID. Laos–China: Laos ID — set passenger_country_code to LAO.'],
            'B' => ['digits' => 9,  'note' => 'Passport number.'],
            'H' => ['digits' => 18, 'note' => 'Foreigner permanent residence ID.'],
            'C' => ['digits' => 9,  'note' => 'Hong Kong / Macao travel permit.'],
            'G' => ['digits' => 8,  'note' => 'Taiwan travel permit.'],
        ];
    }
}

if (!function_exists('_train_passenger_card_types_for_journey')) {
    /** Document types allowed/recommended per journey_type (1=China, 2=Laos–China, 3=Jakarta–Bandung). */
    function _train_passenger_card_types_for_journey(int $journeyType): array
    {
        $all = _train_passenger_card_types();

        if ($journeyType === 3) {
            return ['B' => $all['B']];
        }

        if ($journeyType === 2) {
            return [
                '1' => 'Laos ID / PRC Resident ID',
                'B' => $all['B'],
                'C' => $all['C'],
                'G' => $all['G'],
                'H' => $all['H'],
            ];
        }

        return $all;
    }
}

if (!function_exists('_train_passenger_card_type_label')) {
    function _train_passenger_card_type_label(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        return _train_passenger_card_types()[$code] ?? $code;
    }
}

if (!function_exists('_train_passenger_card_types_for_api')) {
    function _train_passenger_card_types_for_api(?int $journeyType = null): array
    {
        $types = $journeyType !== null
            ? _train_passenger_card_types_for_journey($journeyType)
            : _train_passenger_card_types();
        $meta = _train_passenger_card_type_meta();
        $out = [];
        foreach ($types as $code => $label) {
            $m = $meta[$code] ?? [];
            $out[] = [
                'code'    => $code,
                'label'   => $label,
                'digits'  => $m['digits'] ?? null,
                'note'    => $m['note'] ?? '',
            ];
        }
        return $out;
    }
}

if (!function_exists('_train_enrich_supplier_passenger')) {
    /**
     * Map orderResultData passenger row to display-friendly seat/coach/class fields.
     *
     * @return array<string,mixed>
     */
    function _train_enrich_supplier_passenger(array $passenger, string $failMsg = ''): array
    {
        $seatCode = strtoupper(trim((string)($passenger['seat_class'] ?? '')));
        $coach    = trim((string)($passenger['train_coach_no'] ?? ''));
        $seatNo   = trim((string)($passenger['train_seat_no'] ?? ''));
        $hasSeat  = $coach !== '' || $seatNo !== '';

        $passenger['seat_class_code']  = $seatCode;
        $passenger['seat_class_label'] = _train_seat_class_label($seatCode);
        $passenger['passenger_card_type_label'] = _train_passenger_card_type_label((string)($passenger['passenger_card_type'] ?? ''));
        $passenger['has_assigned_seat'] = $hasSeat;

        $displayParts = [];
        if ($coach !== '') {
            $displayParts[] = 'Coach ' . $coach;
        }
        if ($seatNo !== '') {
            $displayParts[] = 'Seat ' . $seatNo;
        }
        if ($seatCode !== '') {
            $displayParts[] = $passenger['seat_class_label'];
        }
        $passenger['seat_display'] = implode(' · ', $displayParts);

        if ($hasSeat) {
            $passenger['seat_status'] = 'assigned';
            $passenger['seat_status_message'] = $passenger['seat_display'];
        } elseif ($failMsg !== '') {
            $passenger['seat_status'] = 'failed';
            $passenger['seat_status_message'] = _train_human_fail_message($failMsg);
        } else {
            $passenger['seat_status'] = 'pending';
            $passenger['seat_status_message'] = 'Seat allocation pending...';
        }

        return $passenger;
    }
}

if (!function_exists('_train_extract_rsp_passengers')) {
    /** Pull passenger rows from a stored booking_response JSON object. */
    function _train_extract_rsp_passengers(array $bookingResponse): array
    {
        $passengers = $bookingResponse['data']['journey'][0]['passengers']
            ?? $bookingResponse['journey'][0]['passengers']
            ?? [];
        return is_array($passengers) ? $passengers : [];
    }
}

if (!function_exists('_train_merge_passenger_seats')) {
    /**
     * Merge local travellers with orderResultData passengers (matched by passenger_card_no).
     *
     * @return list<array{traveller:array,supplier:array,name:string}>
     */
    function _train_merge_passenger_seats(array $travellers, array $rspPassengers, string $failMsg = ''): array
    {
        $byCard = [];
        foreach ($rspPassengers as $rp) {
            if (!is_array($rp)) {
                continue;
            }
            $cardNo = trim((string)($rp['passenger_card_no'] ?? ''));
            if ($cardNo !== '') {
                $byCard[$cardNo] = $rp;
            }
        }

        $rows = [];
        foreach ($travellers as $idx => $traveller) {
            if (!is_array($traveller)) {
                continue;
            }
            $cardNo = trim((string)($traveller['passenger_card_no'] ?? ''));
            $rawRsp = ($cardNo !== '' && isset($byCard[$cardNo]))
                ? $byCard[$cardNo]
                : (is_array($rspPassengers[$idx] ?? null) ? $rspPassengers[$idx] : []);

            $rows[] = [
                'traveller' => $traveller,
                'supplier'  => _train_enrich_supplier_passenger($rawRsp, $failMsg),
                'name'      => trim((string)($traveller['passenger_first_name'] ?? '') . ' ' . ($traveller['passenger_last_name'] ?? '')),
            ];
        }

        return $rows;
    }
}

if (!function_exists('_train_enrich_order_result_data')) {
    /** Enrich full orderResultData payload for API/mobile display. */
    function _train_enrich_order_result_data(array $data): array
    {
        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        $data['fail_msg_en'] = $failMsg !== '' ? _train_human_fail_message($failMsg) : '';
        $data['has_assigned_seats'] = _train_order_has_seats($data);
        $data['order_status_label'] = match ((int)($data['order_status'] ?? 0)) {
            2       => 'Ticketed',
            3       => 'Failed',
            default => 'Pending',
        };

        if (!empty($data['journey']) && is_array($data['journey'])) {
            foreach ($data['journey'] as &$leg) {
                if (empty($leg['passengers']) || !is_array($leg['passengers'])) {
                    continue;
                }
                foreach ($leg['passengers'] as &$p) {
                    if (is_array($p)) {
                        $p = _train_enrich_supplier_passenger($p, $failMsg);
                    }
                }
                unset($p);
            }
            unset($leg);
        }

        return $data;
    }
}
