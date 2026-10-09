<?php

// ============================================================================
// SEERU ISSUED TICKET CANCELLATION
// ============================================================================
// ENDPOINT: POST /flights/seeru/cancel
// INPUT:    { "invoice_id": "INV-12345" }
//
// FLOW:
// 1. Fetch booking from DB
// 2. Get access token (same as issue flow)
// 3. Retrieve ticket_id via /ticket/retrieve (using pnr + last_name)
// 4. Try VOID  → if success, done
// 5. Void fail → calculate REFUND fees → immediately execute with same fees
// ============================================================================

$router->post('flights/seeru/cancel', function() use ($db) {

    if (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    @mkdir(__DIR__ . "/../logs/", 0777, true);

    $invoice_id = '';
    $booking_id = null;

    try {

        if (!$db) throw new Exception('Database connection not available');

        // ─── Parse Request ───────────────────────────────────────────────────
        $raw_input    = file_get_contents('php://input');
        $json_data    = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_REQUEST.log",
            date('Y-m-d H:i:s') . "\nRAW: " . $raw_input . "\n\n", FILE_APPEND
        );

        // ─── STEP 1: Validate invoice_id ─────────────────────────────────────
        $invoice_id = trim($request_data['invoice_id'] ?? '');

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }

        // ─── STEP 2: Fetch Booking ───────────────────────────────────────────
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        $booking_id = $booking['id'];

        error_log("SEERU TICKET CANCEL: Invoice {$invoice_id} | Status: {$booking['booking_status']}");

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_BOOKING_FROM_DB.log",
            date('Y-m-d H:i:s') . "\n" . print_r($booking, true) . "\n\n", FILE_APPEND
        );

        // ─── STEP 3: Eligibility Check ───────────────────────────────────────
        if (in_array($booking['booking_status'], ['cancelled', 'voided', 'refunded'])) {
            ob_clean();
            echo json_encode([
                'status'         => false,
                'message'        => 'This booking has already been ' . $booking['booking_status'] . '.',
                'invoice_id'     => $invoice_id,
                'booking_status' => $booking['booking_status']
            ]);
            exit;
        }

        if (!in_array($booking['booking_status'], ['confirmed', 'issued', 'ticketed'])) {
            throw new Exception('Booking status "' . $booking['booking_status'] . '" is not eligible for cancellation.');
        }

        // ─── STEP 4: Load Seeru Credentials ──────────────────────────────────
        $module = $db->get('modules', ['c1', 'c2', 'dev_mode'], [
            'name' => 'seeru',
            'type' => 'flights'
        ]);

        if (!$module) throw new Exception('Seeru module not configured');

        $api_key       = $module['c1'] ?? '';
        $refresh_token = $module['c2'] ?? '';
        $dev_mode      = (int)($module['dev_mode'] ?? 1);

        if (empty($api_key) || empty($refresh_token)) {
            throw new Exception('Seeru API credentials missing');
        }

        $base_url = ($dev_mode === 1)
                  ? 'https://sandbox-api.seeru.travel/v1/'
                  : 'https://live-api.seeru.travel/v1/';

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_CREDENTIALS.log",
            date('Y-m-d H:i:s') .
            "\nMode: "     . ($dev_mode === 1 ? 'SANDBOX' : 'PRODUCTION') .
            "\nBase URL: " . $base_url .
            "\nAPI Key: "  . substr($api_key, 0, 20) . "... (len:" . strlen($api_key) . ")" .
            "\nToken: "    . substr($refresh_token, 0, 50) . "...\n\n",
            FILE_APPEND
        );

        // ─── Helper: cURL POST (matches issue flow — logs request + response) ─
        $seeru_post = function(string $url, array $body, array $headers, string $log_prefix) {

            @file_put_contents(__DIR__ . "/../logs/{$log_prefix}_REQUEST.log",
                date('Y-m-d H:i:s') .
                "\nURL: " . $url .
                "\nBODY: " . json_encode($body, JSON_PRETTY_PRINT) . "\n\n",
                FILE_APPEND
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_TIMEOUT        => 60,
            ]);

            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err  = curl_errno($ch) ? curl_error($ch) : null;

            @file_put_contents(__DIR__ . "/../logs/{$log_prefix}_RESPONSE.log",
                date('Y-m-d H:i:s') . " - HTTP {$http_code}\n" .
                ($curl_err ? "CURL ERROR: {$curl_err}\n" : '') .
                $response . "\n\n",
                FILE_APPEND
            );

            return [
                'response'  => $response,
                'http_code' => $http_code,
                'curl_err'  => $curl_err,
                'data'      => json_decode($response, true)
            ];
        };

        // ─── STEP 5: Get Access Token (same as issue flow) ────────────────────
        $auth_result = $seeru_post(
            $base_url . 'auth/token/refresh',
            ['refresh_token' => $refresh_token],
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'User-Agent: V10-Travel-System/1.0'
            ],
            '_CANCEL_AUTH'
        );

        $access_token = $auth_result['data']['access_token']
                     ?? $auth_result['data']['token']
                     ?? $auth_result['data']['data']['access_token']
                     ?? null;

        if (!$access_token) {
            @file_put_contents(__DIR__ . "/../logs/_CANCEL_AUTH_FALLBACK.log",
                date('Y-m-d H:i:s') . " - Token refresh failed, falling back to api_key\nAuth response: " .
                print_r($auth_result['data'], true) . "\n\n",
                FILE_APPEND
            );
            error_log("SEERU TICKET CANCEL: Token refresh failed, falling back to api_key");
            $access_token = $api_key;
        }

        // ─── Auth headers for all cancel operations ───────────────────────────
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token,
            'x-api-key: ' . $api_key,
            'User-Agent: V10-Travel-System/1.0'
        ];

        // ─── STEP 6: Retrieve ticket_id ───────────────────────────────────────
        $booking_response = json_decode($booking['booking_response'] ?? '{}', true);
        $order_id         = $booking_response['order_id'] ?? null;
        $airline_pnr      = $booking['pnr']               ?? null;
        $last_name        = $booking['last_name']          ?? null;

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_RETRIEVE_REQUEST.log",
            date('Y-m-d H:i:s') .
            "\nPNR: "       . $airline_pnr .
            "\nLast Name: " . $last_name .
            "\nOrder ID: "  . $order_id . "\n\n",
            FILE_APPEND
        );

        if (empty($airline_pnr)) {
            throw new Exception('airline_pnr (pnr) not found in booking. Cannot retrieve ticket details.');
        }

        if (empty($last_name)) {
            throw new Exception('last_name not found in booking. Cannot retrieve ticket details.');
        }

        error_log("SEERU TICKET CANCEL: Retrieving ticket for PNR={$airline_pnr}, last_name={$last_name}");

        $retrieve_result = $seeru_post(
            $base_url . 'ticket/retrieve',
            [
                'airline_pnr' => $airline_pnr,
                'last_name'   => $last_name
            ],
            $headers,
            '_CANCEL_RETRIEVE'
        );

        if ($retrieve_result['http_code'] !== 200) {
            throw new Exception(
                'Failed to retrieve ticket details: ' .
                ($retrieve_result['data']['message'] ?? 'HTTP ' . $retrieve_result['http_code'])
            );
        }

        $ticket_id = $retrieve_result['data']['ticket_id']
                  ?? $retrieve_result['data']['data']['ticket_id']
                  ?? null;

        if (empty($ticket_id)) {
            throw new Exception('ticket_id not found in /ticket/retrieve response. Check log: _CANCEL_RETRIEVE_RESPONSE.log');
        }

        error_log("SEERU TICKET CANCEL: Retrieved ticket_id={$ticket_id} for PNR={$airline_pnr}");

        // ─── STEP 7: Try VOID first ───────────────────────────────────────────
        $void_result = $seeru_post(
            $base_url . 'ticket/void',
            [
                'ticket_id'  => $ticket_id,
                'passengers' => []
            ],
            $headers,
            '_CANCEL_VOID'
        );

        // ── Void succeeded ────────────────────────────────────────────────────
        if ($void_result['http_code'] === 200 && ($void_result['data']['status'] ?? '') === 'success') {

            $db->update('bookings', [
                'booking_status'        => 'voided',
                'cancellation_request'  => 1,
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode([
                    'type'        => 'void',
                    'voided_at'   => date('Y-m-d H:i:s'),
                    'ticket_id'   => $ticket_id,
                    'order_id'    => $order_id,
                    'pnr'         => $airline_pnr,
                    'api_message' => $void_result['data']['message'] ?? 'Voided successfully',
                    'mode'        => $dev_mode === 1 ? 'sandbox' : 'production'
                ]),
                'error_response' => null
            ], ['id' => $booking_id]);

            @file_put_contents(__DIR__ . "/../logs/_CANCEL_SUCCESS.log",
                date('Y-m-d H:i:s') . " - VOIDED | PNR: {$airline_pnr} | ticket_id: {$ticket_id} | Mode: " .
                ($dev_mode === 1 ? 'SANDBOX' : 'PRODUCTION') . "\n\n",
                FILE_APPEND
            );

            ob_clean();
            echo json_encode([
                'status'              => true,
                'action'              => 'void',
                'message'             => $void_result['data']['message'] ?? 'Ticket voided successfully.',
                'invoice_id'          => $invoice_id,
                'ticket_id'           => $ticket_id,
                'cancellation_status' => 'voided',
                'mode'                => $dev_mode === 1 ? 'sandbox' : 'production',
                'response'            => $void_result['data']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ─── STEP 8: Void failed → Calculate Refund Fees ─────────────────────
        error_log("SEERU TICKET CANCEL: Void failed (HTTP {$void_result['http_code']}), trying refund...");

        $calc_result = $seeru_post(
            $base_url . 'ticket/refund',
            [
                'ticket_id'  => $ticket_id,
                'legs'       => [],
                'passengers' => [],
                'total_fees' => null
            ],
            $headers,
            '_CANCEL_REFUND_CALC'
        );

        if ($calc_result['http_code'] !== 200) {
            throw new Exception(
                'Void failed and refund calculation also failed: ' .
                ($calc_result['data']['message'] ?? 'HTTP ' . $calc_result['http_code'])
            );
        }

        $calculated_fees = $calc_result['data']['total_fees']
                        ?? $calc_result['data']['data']['total_fees']
                        ?? 0;

        error_log("SEERU TICKET CANCEL: Calculated refund fees = {$calculated_fees}");

        // ─── STEP 9: Execute Refund with Calculated Fees ──────────────────────
        $refund_result = $seeru_post(
            $base_url . 'ticket/refund',
            [
                'ticket_id'  => $ticket_id,
                'legs'       => [],
                'passengers' => [],
                'total_fees' => $calculated_fees
            ],
            $headers,
            '_CANCEL_REFUND_EXEC'
        );

        // ── Refund succeeded ──────────────────────────────────────────────────
        if ($refund_result['http_code'] === 200 && ($refund_result['data']['status'] ?? '') === 'success') {

            $db->update('bookings', [
                'booking_status'        => 'refunded',
                'cancellation_request'  => 1,
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode([
                    'type'        => 'refund',
                    'refunded_at' => date('Y-m-d H:i:s'),
                    'ticket_id'   => $ticket_id,
                    'order_id'    => $order_id,
                    'pnr'         => $airline_pnr,
                    'total_fees'  => $calculated_fees,
                    'api_message' => $refund_result['data']['message'] ?? 'Refunded successfully',
                    'mode'        => $dev_mode === 1 ? 'sandbox' : 'production'
                ]),
                'error_response' => null
            ], ['id' => $booking_id]);

            @file_put_contents(__DIR__ . "/../logs/_CANCEL_SUCCESS.log",
                date('Y-m-d H:i:s') . " - REFUNDED | PNR: {$airline_pnr} | ticket_id: {$ticket_id} | fees: {$calculated_fees} | Mode: " .
                ($dev_mode === 1 ? 'SANDBOX' : 'PRODUCTION') . "\n\n",
                FILE_APPEND
            );

            ob_clean();
            echo json_encode([
                'status'              => true,
                'action'              => 'refund',
                'message'             => $refund_result['data']['message'] ?? 'Ticket refunded successfully.',
                'invoice_id'          => $invoice_id,
                'ticket_id'           => $ticket_id,
                'total_fees_charged'  => $calculated_fees,
                'cancellation_status' => 'refunded',
                'mode'                => $dev_mode === 1 ? 'sandbox' : 'production',
                'response'            => $refund_result['data']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Both void and refund failed ───────────────────────────────────────
        $api_message = $refund_result['data']['message'] ?? 'Refund failed with HTTP ' . $refund_result['http_code'];

        error_log("SEERU TICKET CANCEL: Both void and refund failed for invoice {$invoice_id}");

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_FAILED.log",
            date('Y-m-d H:i:s') .
            "\nInvoice: "      . $invoice_id .
            "\nticket_id: "    . $ticket_id .
            "\nVoid HTTP: "    . $void_result['http_code'] .
            "\nVoid msg: "     . ($void_result['data']['message'] ?? 'n/a') .
            "\nRefund HTTP: "  . $refund_result['http_code'] .
            "\nRefund msg: "   . $api_message . "\n\n",
            FILE_APPEND
        );

        $db->update('bookings', [
            'error_response' => json_encode([
                'error'        => 'Both void and refund failed',
                'ticket_id'    => $ticket_id,
                'void_http'    => $void_result['http_code'],
                'void_message' => $void_result['data']['message'] ?? null,
                'refund_http'  => $refund_result['http_code'],
                'refund_msg'   => $api_message
            ])
        ], ['id' => $booking_id]);

        ob_clean();
        echo json_encode([
            'status'      => false,
            'message'     => $api_message,
            'invoice_id'  => $invoice_id,
            'ticket_id'   => $ticket_id,
            'void_result' => $void_result['data'],
            'http_code'   => $refund_result['http_code'],
            'response'    => $refund_result['data']
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        error_log("SEERU TICKET CANCEL ERROR: " . $e->getMessage());

        @file_put_contents(__DIR__ . "/../logs/_CANCEL_EXCEPTION.log",
            date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
            FILE_APPEND
        );

        if (!empty($booking_id) && isset($db)) {
            try {
                $db->update('bookings', [
                    'error_response' => json_encode([
                        'error'   => 'Exception during ticket cancellation',
                        'message' => $e->getMessage()
                    ])
                ], ['id' => $booking_id]);
            } catch (Exception $db_err) {
                @file_put_contents(__DIR__ . "/../logs/_CANCEL_DB_ERROR.log",
                    date('Y-m-d H:i:s') . " - Failed to update DB after exception: " . $db_err->getMessage() . "\n\n",
                    FILE_APPEND
                );
            }
        }

        ob_clean();
        echo json_encode([
            'status'     => false,
            'message'    => $e->getMessage(),
            'invoice_id' => $invoice_id ?? '',
            'debug'      => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});