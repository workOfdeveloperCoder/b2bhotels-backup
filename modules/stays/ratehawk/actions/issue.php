<?php
/**
 * RATEHAWK BOOKING ISSUE HANDLER
 * =====================================
 * ETG API v3 workflow (Integration Guide §4):
 * 1. Create booking process  → /hotel/order/booking/form/
 * 2. Start booking process   → /hotel/order/booking/finish/
 * 3. Check booking process   → /hotel/order/booking/finish/status/ (poll until ok/error)
 *
 * Docs: poll finish/status until status is ok, final error, or booking timeout.
 * Recommended interval: once every 5 seconds (plus a final check at cut-off).
 *
 * POST params:
 *   async=1        → Phase A only: prebook + form + finish, mark booking
 *                    'processing' and return immediately (skip the ~90s status
 *                    poll). The stays invoice page then keeps calling this
 *                    endpoint with action=poll until confirmed.
 *   action=poll    → one booking/finish/status check for an already-submitted
 *                    async booking; on ok it runs order/info and confirms.
 *   (default)      → full synchronous flow: poll to confirmation before returning.
 */

$router->post('/stays/ratehawk/issue', function() use ($db) {

    // =====================================
    // INITIALIZE RESPONSE
    // =====================================
    if (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    try {
        // =====================================
        // VALIDATE INPUT & GET BOOKING
        // =====================================
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId = $_POST['invoice_id'] ?? '';
        if (empty($invoiceId)) {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'ratehawk'
        ]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        $bookingData = json_decode($booking['booking_data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking data');
        }

        // =====================================
        // MODULE CREDENTIALS (needed by every branch below)
        // =====================================
        $rhModule = $db->get('modules', '*', ['name' => 'ratehawk']);
        $rhCreds  = json_decode($rhModule['credentials'] ?? '{}', true) ?: [];
        $keyId  = trim($rhCreds['key_id'] ?? $rhModule['c1'] ?? '');
        $apiKey = trim($rhCreds['api_key'] ?? $rhModule['c3'] ?? '');
        $apiBaseUrl = rtrim(trim($rhModule['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        // Module-DB admin settings (price-change threshold, booking cut-off) —
        // both are set by the admin in the Content Sync tab. The values below are
        // only the emergency fallback if the settings row can't be read; the
        // defaults live in one place: rh_default_settings() in hotel-schema.php.
        require_once __DIR__ . '/../content/hotel-schema.php';
        $rhSettings = rh_default_settings();
        try {
            $rhSettingsDb = new \Medoo\Medoo([
                'type'     => 'mysql',
                'host'     => $rhModule['host'] ?: 'localhost',
                'database' => $rhModule['database'],
                'username' => $rhModule['username'],
                'password' => $rhModule['password'],
                'charset'  => 'utf8mb4',
            ]);
            $rhRow = $rhSettingsDb->get('ratehawk_content_sync', ['price_change_threshold', 'booking_cutoff_minutes'], ['id' => 1]);
            if ($rhRow) {
                if (is_numeric($rhRow['price_change_threshold'])) {
                    $rhSettings['price_change_threshold'] = max(0.0, (float) $rhRow['price_change_threshold']);
                }
                if (is_numeric($rhRow['booking_cutoff_minutes'])) {
                    $rhSettings['booking_cutoff_minutes'] = max(1, (int) $rhRow['booking_cutoff_minutes']);
                }
            }
        } catch (\Throwable $e) {
            error_log('RateHawk issue: settings lookup failed, using defaults - ' . $e->getMessage());
        }
        $rhCutoffSec = $rhSettings['booking_cutoff_minutes'] * 60;

        // =====================================
        // FINALIZE HELPER — order/info + write row to 'confirmed'.
        // Used by both the async poll branch and the synchronous confirm path.
        // Returns the resolved PNR. Mutates $bookingData / $orderId by reference.
        // =====================================
        $orderId = $bookingData['ratehawk_order_id'] ?? null; // set/overwritten later on the sync path
        $rhFinalize = function ($lastPercent) use ($db, &$booking, &$bookingData, &$orderId, $apiBaseUrl, $keyId, $apiKey) {
            $partnerOrderId = $bookingData['ratehawk_partner_order_id'] ?? $booking['pnr'] ?? '';
            $itemId         = $bookingData['ratehawk_item_id'] ?? null;

            $actualPnr = (string) ($orderId ?: $partnerOrderId); // safe default: RateHawk order id

            $infoReq = [
                'ordering'   => ['ordering_type' => 'desc', 'ordering_by' => 'created_at'],
                'pagination' => ['page_size' => '1', 'page_number' => '1'],
                'search'     => ['partner_order_ids' => [(string) $partnerOrderId]],
                'language'   => 'en',
            ];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/info/', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true, CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($infoReq), CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'order_info', $infoReq, $raw, '', [
                    'url' => $apiBaseUrl . '/hotel/order/info/', 'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => '',
                ]);
            }

            $orderInfo = null;
            $infoError = null;
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                $infoError = 'order/info returned invalid JSON';
            } elseif ($code !== 200 || ($decoded['status'] ?? '') !== 'ok') {
                $infoError = 'order/info failed (Error: ' . ($decoded['error'] ?? 'unknown_error') . ')';
            } else {
                $orders = $decoded['data']['orders'] ?? [];
                if (is_array($orders) && !empty($orders) && is_array($orders[0] ?? null)) {
                    $orderInfo = $orders[0];
                } else {
                    $infoError = 'order/info not synced yet (ETG delay)';
                }
            }

            if ($orderInfo) {
                $bookingData['ratehawk_order_info']             = $orderInfo;
                $bookingData['ratehawk_order_info_verified_at'] = date('Y-m-d H:i:s');
                $bookingData['ratehawk_supplier_status']        = $orderInfo['status'] ?? null;

                // RateHawk's own numeric order id — NOT partner_data.order_id (our RH_… ref)
                $rhOrderId = $orderInfo['order_id'] ?? $orderId ?? null;
                if (!empty($rhOrderId)) {
                    $bookingData['ratehawk_order_id'] = $rhOrderId;
                    $orderId = $rhOrderId;
                }
                // Hotel-side confirmation code the guest quotes at the property
                $hcn = $orderInfo['hotel_data']['order_id']
                    ?? $orderInfo['hotel_order_id']
                    ?? ($orderInfo['supplier_data']['confirmation_id'] ?? null);
                if (!empty($hcn)) {
                    $bookingData['ratehawk_hotel_confirmation'] = $hcn;
                }
                if (!empty($hcn)) {
                    $actualPnr = (string) $hcn;
                } elseif (!empty($rhOrderId)) {
                    $actualPnr = (string) $rhOrderId;
                }
                unset($bookingData['ratehawk_order_info_pending'], $bookingData['ratehawk_order_info_last_error']);
            } else {
                if (!empty($orderId)) {
                    $actualPnr = (string) $orderId;
                }
                $bookingData['ratehawk_order_info_pending']    = true;
                $bookingData['ratehawk_order_info_last_error'] = $infoError ?? 'order/info not ready';
            }

            $bookingData['ratehawk_partner_order_id']    = $partnerOrderId;
            $bookingData['ratehawk_order_id']            = $orderId ?? ($bookingData['ratehawk_order_id'] ?? null);
            $bookingData['ratehawk_item_id']             = $itemId ?? ($bookingData['ratehawk_item_id'] ?? null);
            $bookingData['ratehawk_last_status_percent'] = (int) $lastPercent;
            $bookingData['ratehawk_last_status_check']   = date('Y-m-d H:i:s');
            $bookingData['ratehawk_confirmed_at']        = $bookingData['ratehawk_confirmed_at'] ?? date('Y-m-d H:i:s');

            $db->update('bookings', [
                'booking_status' => 'confirmed',
                'pnr'            => $actualPnr,
                'booking_data'   => json_encode($bookingData),
                'error_response' => null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            return $actualPnr;
        };

        // =====================================
        // ASYNC BRANCH: action=poll — one status check for a submitted booking.
        // Called repeatedly by the stays invoice page. Returns quickly.
        // =====================================
        if (($_POST['action'] ?? '') === 'poll') {
            $st = strtolower((string) ($booking['booking_status'] ?? ''));
            if ($st === 'confirmed') {
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'confirmed', 'percent' => 100, 'pnr' => $booking['pnr'] ?? '']);
                return;
            }
            if (in_array($st, ['failed', 'cancelled'], true)) {
                $er = json_decode($booking['error_response'] ?? '{}', true);
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'failed', 'message' => $er['message'] ?? 'Booking could not be confirmed.']);
                return;
            }

            $partnerOrderId = $bookingData['ratehawk_partner_order_id'] ?? $booking['pnr'] ?? '';
            if ($partnerOrderId === '' || empty($bookingData['ratehawk_finish_submitted_at'])) {
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'processing', 'percent' => 0]);
                return;
            }
            $orderId = $bookingData['ratehawk_order_id'] ?? null;

            $stReq = ['partner_order_id' => (string) $partnerOrderId];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/finish/status/', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true, CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($stReq), CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $stRaw = curl_exec($ch);
            curl_close($ch);
            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'booking_status', $stReq, $stRaw, 'poll', [
                    'url' => $apiBaseUrl . '/hotel/order/booking/finish/status/', 'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => '',
                ]);
            }
            $stData    = json_decode((string) $stRaw, true) ?: [];
            $stStatus  = $stData['status'] ?? 'processing';
            $stErr     = (string) ($stData['error'] ?? '');
            $stPercent = (int) ($stData['data']['percent'] ?? ($bookingData['ratehawk_last_status_percent'] ?? 0));

            if ($stStatus === 'ok') {
                $pnr = $rhFinalize(100);
                if (function_exists('triggerNotification')) {
                    triggerNotification('booking.issued', [
                        'invoice_id' => $invoiceId, 'pnr' => $pnr, 'module' => 'ratehawk',
                        'booking_id' => $booking['id'] ?? null, 'user_id' => $booking['user_id'] ?? null,
                        'customer_email' => $booking['email'] ?? '',
                        'customer_name' => trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')),
                        'amount' => $booking['amount'] ?? 0, 'currency' => $booking['currency'] ?? 'USD',
                    ]);
                }
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'confirmed', 'percent' => 100, 'pnr' => $pnr]);
                return;
            }

            $finalPollErrors = ['soldout', 'provider', 'book_limit', 'block', 'charge', 'not_allowed',
                'order_not_found', 'booking_finish_did_not_succeed', 'invalid_params', 'lock', 'overdue_debt'];
            if ($stStatus === '3ds' || in_array($stErr, $finalPollErrors, true)) {
                $reason = $stStatus === '3ds' ? '3ds_required' : $stErr;
                $bookingData['ratehawk_failed_at'] = date('Y-m-d H:i:s');
                $db->update('bookings', [
                    'booking_status' => 'failed',
                    'booking_data'   => json_encode($bookingData),
                    'error_response' => json_encode([
                        'response_error' => 'Booking failed: ' . $reason, 'error' => $reason,
                        'message' => 'The hotel could not confirm this booking (' . $reason . ').',
                        'partner_order_id' => $partnerOrderId, 'timestamp' => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'failed',
                    'message' => 'The hotel could not confirm this booking (' . $reason . ').']);
                return;
            }

            // Booking cut-off (ETG 5.2) — counted from the successful booking/finish
            // response (ratehawk_finish_submitted_at). Past it with no ok status →
            // treat as failed (ETG auto-fails the order at cut-off).
            $submittedAt = strtotime((string) ($bookingData['ratehawk_finish_submitted_at'] ?? ''));
            if ($submittedAt && (time() - $submittedAt) > $rhCutoffSec) {
                $bookingData['ratehawk_failed_at'] = date('Y-m-d H:i:s');
                $db->update('bookings', [
                    'booking_status' => 'failed',
                    'booking_data'   => json_encode($bookingData),
                    'error_response' => json_encode([
                        'response_error' => 'Booking cut-off reached without confirmation',
                        'error' => 'booking_cutoff',
                        'message' => 'The hotel did not confirm within the booking cut-off window.',
                        'partner_order_id' => $partnerOrderId,
                        'cutoff_minutes' => $rhSettings['booking_cutoff_minutes'],
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
                ob_end_clean();
                echo json_encode(['success' => true, 'status' => 'failed',
                    'message' => 'The hotel did not confirm within the booking cut-off window. Please contact support — your payment is safe.']);
                return;
            }

            // still working
            $bookingData['ratehawk_last_status_percent'] = $stPercent;
            $bookingData['ratehawk_last_status_check']   = date('Y-m-d H:i:s');
            $db->update('bookings', [
                'booking_data' => json_encode($bookingData), 'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            ob_end_clean();
            echo json_encode(['success' => true, 'status' => 'processing', 'percent' => $stPercent]);
            return;
        }

        // =====================================
        // EXTRACT BOOK_HASH FROM BOOKING DATA
        // =====================================
        $bookHash = null;

        // Check root level
        if (!empty($bookingData['book_hash'])) {
            $bookHash = $bookingData['book_hash'];
        }
        // Check selected rooms
        elseif (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
            $firstRoom = reset($bookingData['selected_rooms']);
            $bookHash = $firstRoom['book_hash'] ?? 
                       $firstRoom['option']['book_hash'] ?? 
                       ((!empty($firstRoom['option']['option_id']) && 
                         strpos($firstRoom['option']['option_id'], '_opt_') === false) 
                         ? $firstRoom['option']['option_id'] : null);
        }
        // Check rooms data
        elseif (!empty($bookingData['rooms_data']) && is_array($bookingData['rooms_data'])) {
            $firstRoom = reset($bookingData['rooms_data']);
            $bookHash = $firstRoom['book_hash'] ?? null;
        }

        // ------------------------------------------------------------------
        // ETG — one booking = ONE rate (one book_hash). RateHawk does not accept
        // different room types (or different rate options) in a single booking.
        // The room picker allows selecting several options, so guard here: if the
        // customer selected more than one distinct book_hash, stop before booking
        // instead of silently issuing only the first one.
        // ------------------------------------------------------------------
        if (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
            $distinctHashes = [];
            foreach ($bookingData['selected_rooms'] as $sr) {
                if (!is_array($sr)) {
                    continue;
                }
                $h = $sr['book_hash']
                    ?? $sr['option']['book_hash']
                    ?? $sr['option']['rate_key']
                    ?? null;
                if (!empty($h) && strpos((string) $h, '_opt_') === false) {
                    $distinctHashes[(string) $h] = true;
                }
            }
            if (count($distinctHashes) > 1) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'          => 'multiple_room_types',
                        'response_error' => 'RateHawk allows only one room type per booking. This booking selected '
                            . count($distinctHashes) . ' different rates and cannot be issued in a single reservation.',
                        'rate_count'     => count($distinctHashes),
                        'timestamp'      => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);

                throw new Exception(
                    'This booking has more than one room type or rate, which RateHawk cannot book in a single reservation. '
                    . 'Your payment is safe — please contact support to split it into separate bookings or to arrange a refund.'
                );
            }
        }

        // =====================================
        // GET MODULE CONFIGURATION
        // =====================================
        $moduleData = $db->get('modules', '*', ['name' => 'ratehawk']);
        $devMode = strtolower($moduleData['dev_mode'] ?? '0');
        $isTestEnvironment = in_array($devMode, ['1', 'test', 'on']);

        // =====================================
        // DEV MODE: FETCH TEST HOTEL BOOK_HASH
        // =====================================
        if ($isTestEnvironment && empty($bookHash)) {
            $credentials = json_decode($moduleData['credentials'] ?? '{}', true);
            $keyId = trim($credentials['key_id'] ?? $moduleData['c1'] ?? '');
            $apiKey = trim($credentials['api_key'] ?? $moduleData['c3'] ?? '');

            if (empty($keyId) || empty($apiKey)) {
                throw new Exception('Ratehawk API credentials not configured');
            }

            $apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
            if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
                $apiBaseUrl .= '/api/b2b/v3';
            }

            // Extract and format booking details
            $checkin = $bookingData['checkin'] ?? '';
            $checkout = $bookingData['checkout'] ?? '';
            $roomsData = $bookingData['rooms_data'] ?? [['adults' => 2, 'children' => 0]];
            $currency = $bookingData['currency'] ?? 'USD';
            $residency = strtolower($bookingData['nationality'] ?? 'us');

            // Convert date format (24-01-2026 -> 2026-01-24)
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkin, $m)) {
                $checkin = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkout, $m)) {
                $checkout = $m[3] . '-' . $m[2] . '-' . $m[1];
            }

            // Build guests array — children must be ages[], matching hotelpage occupancy
            $guests = array_map(function($room) {
                $roomAdults = max(1, (int)($room['adults'] ?? 2));
                $roomChildren = max(0, (int)($room['children'] ?? 0));
                $ages = $room['childAges'] ?? $room['children_ages'] ?? [];
                if (!is_array($ages)) {
                    $ages = [];
                }
                $ages = array_map('intval', array_values($ages));
                while (count($ages) < $roomChildren) {
                    $ages[] = 1;
                }
                if (count($ages) > $roomChildren) {
                    $ages = array_slice($ages, 0, $roomChildren);
                }
                $guestRoom = ['adults' => $roomAdults];
                if ($roomChildren > 0) {
                    $guestRoom['children'] = $ages;
                }
                return $guestRoom;
            }, $roomsData);

            // Call hotelpage API for test hotel
            $hotelpageRequest = [
                'checkin' => $checkin,
                'checkout' => $checkout,
                'residency' => $residency,
                'language' => 'en',
                'guests' => $guests,
                // NOTE: `hid` (ETG's non-deprecated /search/hp/ param) only accepts a
                // numeric hotel id and rejects this sandbox sentinel string with
                // invalid_json_format (verified live 2026-09-23) — `id` still accepts
                // it, so this one dev-mode-only call intentionally keeps `id`.
                'id' => 'test_hotel_do_not_book',
                'currency' => $currency
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/search/hp/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($hotelpageRequest),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $hotelpageResponse = curl_exec($ch);
            $hotelpageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'hotelpage', $hotelpageRequest, $hotelpageResponse, '', [
                    'url' => $apiBaseUrl . '/search/hp/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($hotelpageResponse === false || $hotelpageHttpCode !== 200) {
                error_log("RateHawk Dev Mode: Hotelpage API failed - HTTP {$hotelpageHttpCode}, Error: {$curlError}");
                throw new Exception('Failed to fetch test hotel rates. Please check API credentials.');
            }

            $hotelpageData = json_decode($hotelpageResponse, true);
            $firstRate = $hotelpageData['data']['hotels'][0]['rates'][0] ?? null;

            if (!$firstRate || empty($firstRate['book_hash'])) {
                error_log("RateHawk Dev Mode: Test hotel not available or missing book_hash");
                throw new Exception('Test hotel not available for selected dates.');
            }

            $bookHash = $firstRate['book_hash'];

            // Update booking with test hotel data
            $bookingData['ratehawk_dev_mode'] = true;
            $bookingData['ratehawk_test_hotel_id'] = 'test_hotel_do_not_book';
            $bookingData['ratehawk_test_hotel_hid'] = 8473727;
            $bookingData['book_hash'] = $bookHash;
            $bookingData['hotel_id'] = 'test_hotel_do_not_book';
            $bookingData['hotel_name'] = 'TEST HOTEL - DO NOT BOOK (Dev Mode)';

            $db->update('bookings', [
                'booking_data' => json_encode($bookingData),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);
        }

        // =====================================
        // VALIDATE BOOK_HASH
        // =====================================
        if (empty($bookHash)) {
            error_log("RateHawk Error: book_hash not found - Booking ID: {$booking['id']}, Invoice: {$invoiceId}");
            throw new Exception('Missing book_hash - room must be selected with live pricing. Please search and select room again.');
        }

        // =====================================
        // GET API CREDENTIALS
        // =====================================
        if (!isset($keyId) || !isset($apiKey)) {
            $credentials = json_decode($moduleData['credentials'] ?? '{}', true);
            $keyId = trim($credentials['key_id'] ?? $moduleData['c1'] ?? '');
            $apiKey = trim($credentials['api_key'] ?? $moduleData['c3'] ?? '');

            if (empty($keyId) || empty($apiKey)) {
                throw new Exception('Ratehawk API credentials not configured');
            }
        }

        if (!isset($apiBaseUrl)) {
            $apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
            if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
                $apiBaseUrl .= '/api/b2b/v3';
            }
        }

        // =====================================
        // CALL PREBOOK TO GET BOOKING HASH (p-...)
        // =====================================
        if (strpos($bookHash, 'p-') !== 0) {
            // ETG 3.1 — price_increase_percent is the movement in the supplier net
            // price we accept silently between room selection and booking. The admin
            // sets it in the Content Sync tab (default 2%). Anything above it means
            // prebook fails → we hold the booking and tell the customer.
            $priceThresholdPct = $rhSettings['price_change_threshold'];

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
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $prebookRes = curl_exec($ch);
            $prebookHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $prebookCurlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'prebook', $prebookReq, $prebookRes, '', [
                    'url' => $apiBaseUrl . '/hotel/prebook/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($prebookRes === false || $prebookHttpCode !== 200) {
                error_log("RateHawk Prebook: API Error - HTTP {$prebookHttpCode}, Error: {$prebookCurlError}");
                throw new Exception('Prebook failed. Selected rate is no longer available. Please search again.');
            }

            $prebookData = json_decode($prebookRes, true);
            if (!isset($prebookData['status']) || $prebookData['status'] !== 'ok' || empty($prebookData['data']['hotels'][0]['rates'][0]['book_hash'])) {
                $err = $prebookData['error'] ?? 'unknown_error';
                error_log("RateHawk Prebook: API Error status - " . json_encode($prebookData));

                // A price move beyond the admin threshold makes prebook reject the
                // rate. Flag it so the invoice page shows a clear "price changed"
                // notice instead of a generic failure (ETG 3.1).
                $isPriceIssue = in_array($err, ['price_changed', 'rate_price_changed', 'no_rate', 'rate_not_found'], true);
                $bookingData['ratehawk_price_review'] = $isPriceIssue;
                $db->update('bookings', [
                    'booking_status'  => 'pending',
                    'booking_data'    => json_encode($bookingData),
                    'error_response'  => json_encode([
                        'error'          => $isPriceIssue ? 'price_change' : $err,
                        'response_error' => $isPriceIssue
                            ? "The room's price changed by more than the allowed {$priceThresholdPct}% after checkout."
                            : "Prebook failed ({$err}).",
                        'threshold_pct'  => $priceThresholdPct,
                        'timestamp'      => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);

                if ($isPriceIssue) {
                    throw new Exception(
                        "The hotel's price changed by more than the {$priceThresholdPct}% we allow after checkout. "
                        . "Your booking is on hold and your payment is safe — our team will confirm the new price with you or refund you."
                    );
                }
                throw new Exception("Prebook failed (Error: {$err}). Please select another rate.");
            }

            // Record the confirmed net + any accepted (within-threshold) movement
            // so the voucher / admin can see it.
            $pbRate = $prebookData['data']['hotels'][0]['rates'][0] ?? [];
            $pbPay  = $pbRate['payment_options']['payment_types'][0] ?? [];
            $pbNet  = (float) ($pbPay['amount'] ?? $pbPay['show_amount'] ?? 0);
            $oldNet = (float) ($bookingData['ratehawk_prebook_net'] ?? $bookingData['actual_amount'] ?? 0);
            if ($pbNet > 0) {
                $bookingData['ratehawk_prebook_net'] = $pbNet;
                if ($oldNet > 0 && $pbNet > $oldNet + 0.01) {
                    $bookingData['ratehawk_price_changed'] = [
                        'old_net'          => round($oldNet, 2),
                        'new_net'          => round($pbNet, 2),
                        'currency'         => $pbPay['currency_code'] ?? $pbPay['show_currency_code'] ?? '',
                        'pct'              => round((($pbNet - $oldNet) / $oldNet) * 100, 2),
                        'threshold_pct'    => $priceThresholdPct,
                        'within_threshold' => true,
                        'at'               => date('Y-m-d H:i:s'),
                    ];
                }
            }

            // Update book_hash to the new p- hash returned by prebook
            $bookHash = $pbRate['book_hash'];
        }

        // =====================================
        // CREATE BOOKING FORM
        // =====================================
        // Resume: if finish already started, only poll Check booking process (don't create a new order)
        $existingPartnerOrderId = $bookingData['ratehawk_partner_order_id'] ?? '';
        $resumeStatusOnly = !empty($existingPartnerOrderId)
            && empty($bookingData['ratehawk_confirmed_at'])
            && (
                !empty($bookingData['ratehawk_finish_submitted_at'])
                || !empty($booking['pnr'])
            );

        if ($resumeStatusOnly) {
            $partnerOrderId = $existingPartnerOrderId;
            $orderId = $bookingData['ratehawk_order_id'] ?? null;
            $itemId = $bookingData['ratehawk_item_id'] ?? null;
        } else {
        // Prefer a public IPv4 — localhost ::1 can be rejected by some RateHawk validations
        $userIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($userIp, ',') !== false) {
            $userIp = trim(explode(',', $userIp)[0]);
        }
        if ($userIp === '::1' || $userIp === '127.0.0.1' || !filter_var($userIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $userIp = '8.8.8.8';
        }

        // =====================================
        // CREATE BOOKING FORM (retry ≤10 with new partner_order_id)
        // Docs: duplicate_reservation / double_booking_form / unknown / timeout / 5xx
        // =====================================
        $formRetryable = ['duplicate_reservation', 'double_booking_form', 'unknown', 'timeout'];
        $formOk = false;
        $apiResponse = null;
        $partnerOrderId = '';
        $maxFormAttempts = 10;

        for ($formAttempt = 1; $formAttempt <= $maxFormAttempts; $formAttempt++) {
            $partnerOrderId = 'RH_' . $booking['id'] . '_' . uniqid();

            $bookingFormRequest = [
                'partner_order_id' => $partnerOrderId,
                'book_hash' => $bookHash,
                'language' => 'en',
                'user_ip' => $userIp
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/form/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($bookingFormRequest),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'booking_form', $bookingFormRequest, $response, (string) $formAttempt, [
                    'url' => $apiBaseUrl . '/hotel/order/booking/form/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($response === false) {
                // Treat connectivity as retryable (similar to 5xx)
                error_log("RateHawk Booking Form: cURL Error attempt {$formAttempt} - {$curlError}");
                if ($formAttempt < $maxFormAttempts) {
                    usleep(300000);
                    continue;
                }
                throw new Exception('Failed to connect to booking API: ' . $curlError);
            }

            $apiResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("RateHawk Booking Form: Invalid JSON - HTTP {$httpCode}");
                if ($formAttempt < $maxFormAttempts && $httpCode >= 500) {
                    continue;
                }
                throw new Exception('Received invalid response from booking API');
            }

            if ($httpCode === 200 && ($apiResponse['status'] ?? '') === 'ok') {
                $formOk = true;
                break;
            }

            $errorCode = $apiResponse['error'] ?? 'unknown_error';
            $isRetryable = in_array($errorCode, $formRetryable, true) || $httpCode >= 500;

            error_log("RateHawk Booking Form: API Error attempt {$formAttempt} - {$errorCode}, HTTP {$httpCode}");

            if ($isRetryable && $formAttempt < $maxFormAttempts) {
                usleep(300000);
                continue;
            }

            $db->update('bookings', [
                'error_response' => json_encode($apiResponse),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            $errorMessages = [
                'contract_mismatch' => 'Booking contract mismatch. Please try searching again.',
                'double_booking_form' => 'Booking already in progress. Please wait or start a new booking.',
                'duplicate_reservation' => 'This booking has already been completed. Please start a new booking.',
                'hotel_not_found' => 'Hotel not found. Please search again.',
                'reservation_is_not_allowed' => 'Booking is not allowed for this account. Please contact support.',
                'rate_not_found' => 'Selected rate is no longer available. Please search again.',
                'sandbox_restriction' => 'Test bookings require test environment. Please contact support.',
                'invalid_params' => 'Invalid booking parameters. Please try again or contact support.',
            ];

            $errorMessage = $errorMessages[$errorCode] ?? 'Booking service temporarily unavailable. Please try again.';
            throw new Exception($errorMessage . ' (Error: ' . $errorCode . ')');
        }

        if (!$formOk || !is_array($apiResponse)) {
            throw new Exception('Create booking process failed after ' . $maxFormAttempts . ' attempts. Please search again.');
        }

        // =====================================
        // EXTRACT BOOKING FORM DATA
        // =====================================
        $bookingFormData = $apiResponse['data'] ?? [];
        $orderId = $bookingFormData['order_id'] ?? null;
        $itemId = $bookingFormData['item_id'] ?? null;
        $paymentTypes = $bookingFormData['payment_types'] ?? [];

        if (empty($orderId) || empty($itemId)) {
            error_log("RateHawk Booking Form: Missing order_id or item_id");
            throw new Exception('Invalid booking form response');
        }

        // Update booking with order details (partner ref only — real PNR after finish/status)
        $bookingData['ratehawk_order_id'] = $orderId;
        $bookingData['ratehawk_item_id'] = $itemId;
        $bookingData['ratehawk_partner_order_id'] = $partnerOrderId;
        $bookingData['ratehawk_payment_types'] = $paymentTypes;
        $bookingData['ratehawk_book_hash'] = $bookHash;
        $bookingData['ratehawk_booking_form_created'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status' => 'pending',
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        // =====================================
        // FINISH BOOKING
        // =====================================
        $travellers = json_decode($booking['travellers'], true) ?? [];
        $primaryGuest = $travellers['primary_guest'] ?? [];
        $travelers = $travellers['travelers'] ?? [];

        // Build phone as +{dial_code}{local_number} exactly from submitted booking fields.
        // Do NOT assume local number already contains the dial code (e.g. 34... + dial 34).
        $normalizePhone = function ($countryCode, $phone) use ($db) {
            $phoneRaw = trim((string)$phone);
            if ($phoneRaw === '') {
                return null;
            }

            $dial = '';
            if (function_exists('getPhoneCode')) {
                $dial = preg_replace('/\D+/', '', (string)getPhoneCode($countryCode, $db));
            } else {
                $dial = preg_replace('/\D+/', '', (string)$countryCode);
                if ($dial === '' && preg_match('/^[A-Za-z]{2}$/', trim((string)$countryCode))) {
                    $row = $db->get('countries', 'phonecode', ['iso' => strtoupper(trim((string)$countryCode))]);
                    $dial = preg_replace('/\D+/', '', (string)($row ?? ''));
                }
            }

            // Full international already provided
            if (strpos($phoneRaw, '+') === 0) {
                $digits = preg_replace('/\D+/', '', $phoneRaw);
                if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                    return '+' . $digits;
                }
            }

            $local = preg_replace('/\D+/', '', $phoneRaw);
            $local = ltrim($local, '0');
            if ($local === '') {
                return null;
            }

            $digits = ($dial !== '') ? ($dial . $local) : $local;
            if (strlen($digits) < 8 || strlen($digits) > 15) {
                return null;
            }
            return '+' . $digits;
        };

        $sanitizeName = function ($name, $fallback = 'Guest') {
            $name = preg_replace('/[^a-zA-Z\-\s]/', '', (string)$name);
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                return $fallback;
            }
            // RateHawk prefers simple latin name tokens
            $parts = preg_split('/\s+/', $name);
            return $parts[0] ?: $fallback;
        };

        // Prefer booking.phone_country_code (saved via getPhoneCode on submit),
        // then traveller country/phone country fields.
        $rawCountry = $booking['phone_country_code']
            ?? $primaryGuest['country_code']
            ?? $primaryGuest['phone_country_code']
            ?? '';
        $rawPhone = $primaryGuest['phone'] ?? $booking['phone'] ?? '';
        $normalizedPhone = $normalizePhone($rawCountry, $rawPhone);

        // Build rooms array with guests.
        // RateHawk counts anyone without is_child=true as an adult → must mark children.
        $roomsDataForAges = $bookingData['rooms_data'] ?? $_SESSION['hotel_rooms_data'] ?? [];
        if (!is_array($roomsDataForAges)) {
            $roomsDataForAges = [];
        }
        $roomsDataForAges = array_values($roomsDataForAges);

        $roomsForBooking = [];
        $roomIndex = 0;
        foreach ($travelers as $roomKey => $roomTravelers) {
            if (!is_array($roomTravelers)) {
                continue;
            }

            $adultGuests = [];
            $childGuests = [];
            $roomAges = [];
            if (isset($roomsDataForAges[$roomIndex]['childAges']) && is_array($roomsDataForAges[$roomIndex]['childAges'])) {
                $roomAges = array_map('intval', array_values($roomsDataForAges[$roomIndex]['childAges']));
            }
            $childOrdinal = 0;

            foreach ($roomTravelers as $guestKey => $traveler) {
                if (!is_array($traveler) || empty($traveler['first_name']) || empty($traveler['last_name'])) {
                    continue;
                }

                $guest = [
                    'first_name' => $sanitizeName($traveler['first_name'], 'Guest'),
                    'last_name' => $sanitizeName($traveler['last_name'], 'Traveler')
                ];

                $keyIsChild = is_string($guestKey) && strpos($guestKey, 'child_') === 0;
                $flagIsChild = !empty($traveler['is_child']) || (($traveler['type'] ?? '') === 'child');

                if ($keyIsChild || $flagIsChild) {
                    $age = isset($traveler['age']) ? (int)$traveler['age'] : null;
                    if ($age === null || $age < 0 || $age > 17) {
                        $age = $roomAges[$childOrdinal] ?? 1;
                    }
                    $guest['is_child'] = true;
                    $guest['age'] = max(0, min(17, (int)$age));
                    $childGuests[] = $guest;
                    $childOrdinal++;
                } else {
                    $adultGuests[] = $guest;
                }
            }

            $guests = array_merge($adultGuests, $childGuests);
            if (!empty($guests)) {
                $roomsForBooking[] = ['guests' => $guests];
            }
            $roomIndex++;
        }

        // Fallback if no guests found
        if (empty($roomsForBooking)) {
            $roomsForBooking[] = [
                'guests' => [[
                    'first_name' => $sanitizeName($primaryGuest['first_name'] ?? $booking['first_name'] ?? '', 'Guest'),
                    'last_name' => $sanitizeName($primaryGuest['last_name'] ?? $booking['last_name'] ?? '', 'Traveler')
                ]]
            ];
        }

        // ETG: a single rate covers at most 9 rooms. (The search widget already
        // caps at 5, so this only guards hand-crafted URLs / future changes.)
        if (count($roomsForBooking) > 9) {
            $db->update('bookings', [
                'booking_status' => 'pending',
                'error_response' => json_encode([
                    'error'          => 'too_many_rooms',
                    'response_error' => 'RateHawk allows a maximum of 9 rooms per booking; this reservation requested '
                        . count($roomsForBooking) . '.',
                    'timestamp'      => date('Y-m-d H:i:s'),
                ]),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
            throw new Exception(
                'RateHawk allows at most 9 rooms in one booking. Please reduce the number of rooms or make separate bookings. '
                . 'Your payment is safe — contact support if you need help.'
            );
        }

        $selectedPaymentType = $paymentTypes[0] ?? [
            'type' => 'deposit',
            'amount' => $bookingData['total_amount'] ?? '0.00',
            'currency_code' => $bookingData['currency'] ?? 'USD'
        ];

        // amount_sell_b2b2c must match payment currency/amount scale (not display EGP markup)
        $sellAmount = (string)($selectedPaymentType['amount'] ?? '0');

        // Customer's real phone (normalised) — goes in supplier_data, never in user.
        $phoneValue = $normalizedPhone;
        if (empty($phoneValue)) {
            $phoneDigits = preg_replace('/\D+/', '', (string)($rawPhone ?: '12124567899'));
            $phoneValue = '+' . (strlen($phoneDigits) >= 8 ? $phoneDigits : '12124567899');
        }

        // ------------------------------------------------------------------
        // ETG Integration Requirements 5.7 (B2B): user.email / user.phone MUST
        // be our own fixed corporate contact — the customer receives OUR voucher
        // (with our selling price) by email from our system, not ETG's net-price
        // one. The customer's real email + phone go in supplier_data only.
        // ------------------------------------------------------------------
        $corpSettings = $db->get('settings', ['contact_email', 'contact_phone', 'admin_email', 'email_sender_email']) ?: [];

        $corpEmail = '';
        foreach (['contact_email', 'admin_email', 'email_sender_email'] as $k) {
            $cand = trim((string)($corpSettings[$k] ?? ''));
            if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_EMAIL)) {
                $corpEmail = $cand;
                break;
            }
        }
        if ($corpEmail === '') {
            $corpEmail = $primaryGuest['email'] ?? $booking['email'] ?? 'booking@phptravels.org';
        }

        $corpPhoneDigits = ltrim(preg_replace('/\D+/', '', (string)($corpSettings['contact_phone'] ?? '')), '0');
        $corpPhone = (strlen($corpPhoneDigits) >= 8 && strlen($corpPhoneDigits) <= 15)
            ? '+' . $corpPhoneDigits
            : $phoneValue; // fall back to the customer's valid phone if corp phone is unusable

        $userPayload = [
            'email' => $corpEmail,
            'comment' => $booking['special_requests'] ?? '',
            'phone' => $corpPhone
        ];
        $supplierPayload = [
            'first_name_original' => $sanitizeName($primaryGuest['first_name'] ?? $booking['first_name'] ?? '', 'Guest'),
            'last_name_original' => $sanitizeName($primaryGuest['last_name'] ?? $booking['last_name'] ?? '', 'Traveler'),
            'email' => $primaryGuest['email'] ?? $booking['email'],
            'phone' => $phoneValue
        ];

        $paymentTypePayload = [
            'type' => $selectedPaymentType['type'] ?? 'deposit',
            'amount' => (string)($selectedPaymentType['amount'] ?? '0'),
            'currency_code' => $selectedPaymentType['currency_code'] ?? 'USD',
        ];

        // Pass init_uuid and pay_uuid if required (supported only outside Sandbox environment)
        if (!$isTestEnvironment) {
            if (!empty($selectedPaymentType['init_uuid'])) {
                $paymentTypePayload['init_uuid'] = (string)$selectedPaymentType['init_uuid'];
            }
            if (!empty($selectedPaymentType['pay_uuid'])) {
                $paymentTypePayload['pay_uuid'] = (string)$selectedPaymentType['pay_uuid'];
            }
        }

        $finishBookingRequest = [
            'user' => $userPayload,
            'supplier_data' => $supplierPayload,
            'partner' => [
                'partner_order_id' => $partnerOrderId,
                'comment' => 'Booking via PHP Travel System',
                'amount_sell_b2b2c' => $sellAmount
            ],
            'language' => 'en',
            'rooms' => $roomsForBooking,
            'payment_type' => $paymentTypePayload
        ];

        // Add return_path for 'now' payment type outside Sandbox if required
        if (!$isTestEnvironment) {
            $paymentType = strtolower((string)($selectedPaymentType['type'] ?? ''));
            if ($paymentType === 'now' || !empty($bookingData['return_path'])) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $finishBookingRequest['return_path'] = $bookingData['return_path'] ?? ($baseUrl . '/stays/invoice/' . $invoiceId);
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/finish/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($finishBookingRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $finishResponse = curl_exec($ch);
        $finishHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('issue', 'booking_finish', $finishBookingRequest, $finishResponse, '', [
                'url' => $apiBaseUrl . '/hotel/order/booking/finish/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => ''
            ]);
        }

        if ($finishResponse === false) {
            error_log("RateHawk Finish Booking: cURL failed");
            // Docs: timeout/unknown/5xx → still proceed to Check booking process
        } else {
            $finishApiResponse = json_decode($finishResponse, true) ?: [];
            $finishStatus = $finishApiResponse['status'] ?? '';
            $finishError = $finishApiResponse['error'] ?? '';
            $finishTransient = in_array($finishError, ['timeout', 'unknown'], true)
                || $finishHttpCode >= 500
                || $finishHttpCode === 0;

            if ($finishHttpCode === 200 && $finishStatus === 'ok') {
                // proceed to poll
            } elseif ($finishTransient || $finishStatus === 'ok') {
                // Docs: ok OR timeout/unknown/5xx → Proceed to Check Booking Process
                error_log("RateHawk Finish Booking: transient/ok proceed to status poll - HTTP {$finishHttpCode}, error={$finishError}");
            } else {
                $validationError = $finishApiResponse['debug']['validation_error'] ?? '';
                $errorDetail = $validationError !== '' ? "{$finishError}: {$validationError}" : ($finishError ?: 'unknown');
                error_log("RateHawk Finish Booking: Error - {$errorDetail}");

                $finishErrorMessages = [
                    'booking_form_expired' => 'Booking form expired. Please search and book again.',
                    'chosen_payment_type_was_not_available_on_booking_form' => 'Selected payment type is no longer available.',
                    'incorrect_chosen_payment_type' => 'Payment type details do not match rate requirements.',
                    'return_path_required' => 'Return URL path is required for online payment rates.',
                    'not_enough_credit_card_data' => 'Credit card authentication token is required for this rate.',
                    'order_not_found' => 'Booking order not found. Please restart the booking process.',
                    'rate_not_found' => 'Rate is no longer available. Please select another room rate.',
                    'unauthorized_group_booking' => 'Group booking limit exceeded (>9 bookings). Please contact support.',
                    'supplier_data_required' => 'Supplier guest details are missing or incomplete.',
                    'card_type_not_supported' => 'The selected payment card type is not supported.',
                    'sandbox_restriction' => 'Test hotel booking can only be completed in sandbox environment.'
                ];

                $userFriendlyMessage = $finishErrorMessages[$finishError] ?? ('Finish booking failed: ' . $errorDetail);

                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'response_error' => $userFriendlyMessage,
                        'error' => $finishError,
                        'message' => $userFriendlyMessage,
                        'validation_error' => $validationError,
                        'partner_order_id' => $partnerOrderId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $booking['id']]);

                throw new Exception($userFriendlyMessage);
            }
        }

        $bookingData['ratehawk_finish_submitted_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', [
            'booking_status' => 'pending',
            'pnr' => $partnerOrderId,
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        } // end else create form + finish

        // ---- ASYNC PHASE A: the order now exists and finish is submitted, so the
        // booking is running on RateHawk's side. Return now instead of blocking
        // ~90s on the status poll; the invoice page takes over via
        // the invoice page then re-calls this endpoint with action=poll. ----
        if (!empty($_POST['async'])) {
            $bookingData['ratehawk_async'] = true;
            $db->update('bookings', [
                'booking_status' => 'processing',
                'pnr'            => $partnerOrderId,
                'booking_data'   => json_encode($bookingData),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            ob_end_clean();
            echo json_encode([
                'status'            => true,
                'success'           => true,
                'processing'        => true,
                'booking_status'    => 'processing',
                'Prn'               => (string) ($orderId ?: $partnerOrderId),
                'pnr'               => (string) ($orderId ?: $partnerOrderId),
                'booking_reference' => (string) ($orderId ?: $partnerOrderId),
                'reference'         => (string) ($orderId ?: $partnerOrderId),
                'message'           => 'Booking submitted — confirming with the hotel…',
                'response_error'    => '',
                'invoice_id'        => $invoiceId,
                'data'              => [
                    'booking_id'       => $booking['id'],
                    'invoice_id'       => $invoiceId,
                    'order_id'         => $orderId ?? null,
                    'partner_order_id' => $partnerOrderId,
                    'status'           => 'processing',
                    'confirmed'        => false,
                    'percent'          => 0,
                    'item_id'          => $itemId ?? null,
                ],
            ]);
            return;
        }

        // =====================================
        // CHECK BOOKING PROCESS (poll finish/status)
        // ETG: recommended once every 5s until ok / final error / booking cut-off.
        // Always send one final status request in the last second before cut-off.
        // =====================================
        @set_time_limit(0);
        $pollIntervalSec = 5;
        // ETG 5.2 — cut-off counted from the successful booking/finish response
        // (just handled above). Admin-configurable in the Content Sync tab.
        $bookingTimeoutSec = $rhCutoffSec;
        $pollStartedAt = time();
        $attempt = 0;
        $bookingConfirmed = false;
        $actualPnr = $partnerOrderId;
        $bookingStatus = 'pending';
        $lastPercent = 0;
        $finalErrors = [
            'soldout', 'provider', 'book_limit', 'block', 'charge', '3ds',
            'not_allowed', 'order_not_found', 'booking_finish_did_not_succeed',
            'decoding_json', 'endpoint_exceeded_limit', 'endpoint_not_active',
            'endpoint_not_found', 'incorrect_credentials', 'invalid_auth_header',
            'invalid_params', 'lock', 'no_auth_header', 'not_allowed_host',
            'overdue_debt', 'unexpected_method'
        ];

        while (!$bookingConfirmed) {
            $elapsed = time() - $pollStartedAt;
            if ($elapsed >= $bookingTimeoutSec) {
                break;
            }

            if ($attempt > 0) {
                $remaining = $bookingTimeoutSec - $elapsed;
                // Sleep 5s, but wake 1s before cut-off for the mandatory final check
                $sleepFor = min($pollIntervalSec, max(1, $remaining - 1));
                sleep($sleepFor);
                if ((time() - $pollStartedAt) >= $bookingTimeoutSec) {
                    break;
                }
            }
            $attempt++;

            $statusRequest = ['partner_order_id' => $partnerOrderId];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/finish/status/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($statusRequest),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $statusResponse = curl_exec($ch);
            $statusHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Log every Check booking process poll
            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'booking_status', $statusRequest, $statusResponse, (string) $attempt, [
                    'url' => $apiBaseUrl . '/hotel/order/booking/finish/status/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            // Docs: processing / timeout / unknown / 5xx → keep polling
            if ($statusResponse === false || $statusHttpCode >= 500 || $statusHttpCode === 0) {
                continue;
            }

            $statusApiResponse = json_decode($statusResponse, true);
            if (!is_array($statusApiResponse)) {
                continue;
            }

            $status = $statusApiResponse['status'] ?? 'error';
            $statusData = is_array($statusApiResponse['data'] ?? null) ? $statusApiResponse['data'] : [];
            $lastPercent = (int) ($statusData['percent'] ?? $lastPercent);
            $error = $statusApiResponse['error'] ?? '';

            if ($status === 'ok') {
                $bookingConfirmed = true;
                $bookingStatus = 'confirmed';
                $actualPnr = $statusData['reference']
                    ?? $statusData['hotel_confirmation_code']
                    ?? $statusData['booking_id']
                    ?? $partnerOrderId;
                break;
            }

            if ($status === 'processing') {
                continue;
            }

            if ($status === '3ds') {
                error_log('RateHawk Booking Status: 3D Secure required');
                throw new Exception('3D Secure payment required. Please contact support.');
            }

            if ($status === 'error' || $error !== '') {
                if (in_array($error, ['timeout', 'unknown'], true)) {
                    continue; // transient — keep polling
                }
                if (in_array($error, $finalErrors, true)) {
                    error_log("RateHawk Booking Status: final error - {$error}");
                    throw new Exception('Booking failed: ' . $error);
                }
                error_log("RateHawk Booking Status: Error - {$error}");
                continue;
            }
        }

        // =====================================
        // UPDATE BOOKING — confirmed OR timeout failure
        // =====================================
        $bookingData['ratehawk_partner_order_id'] = $partnerOrderId;
        $bookingData['ratehawk_order_id'] = $orderId ?? ($bookingData['ratehawk_order_id'] ?? null);
        $bookingData['ratehawk_item_id'] = $itemId ?? ($bookingData['ratehawk_item_id'] ?? null);
        $bookingData['ratehawk_last_status_percent'] = $lastPercent;
        $bookingData['ratehawk_last_status_check'] = date('Y-m-d H:i:s');

        if (!$bookingConfirmed) {
            $db->update('bookings', [
                'booking_status' => 'pending',
                'pnr' => $partnerOrderId,
                'booking_data' => json_encode($bookingData),
                'error_response' => json_encode([
                    'response_error' => 'Booking status timeout after ' . $bookingTimeoutSec . 's without confirmation',
                    'error' => 'booking_timeout',
                    'message' => 'Booking cut-off reached without ok status from RateHawk',
                    'partner_order_id' => $partnerOrderId,
                    'percent' => $lastPercent,
                    'attempts' => $attempt,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            throw new Exception(
                'Booking confirmation timed out after ' . $bookingTimeoutSec
                . ' seconds (RateHawk cut-off window). Last progress: ' . $lastPercent . '%.'
            );
        }

        // Confirmed. Fetch the real order number via /hotel/order/info/ and write
        // the row to 'confirmed' ($rhFinalize is defined near the top of this file,
        // also used by the action=poll branch). It mutates $bookingData / $orderId
        // by reference and returns the resolved PNR.
        $actualPnr = $rhFinalize($lastPercent);

        $message = 'Booking completed successfully';

        // =====================================
        // RETURN SUCCESS RESPONSE
        // (Format matches Hotelbeds for payment-gateway.php compatibility)
        // =====================================
        ob_end_clean();
        echo json_encode([
            'status' => true,
            'Prn' => $actualPnr,
            'booking_reference' => $actualPnr,
            'reference' => $actualPnr,
            'booking_status' => 'confirmed',
            'success' => true,
            'message' => $message,
            'response_error' => '',
            'invoice_id' => $invoiceId,
            'data' => [
                'booking_id' => $booking['id'],
                'invoice_id' => $invoiceId,
                'order_id' => $orderId ?? null,
                'partner_order_id' => $partnerOrderId,
                'pnr' => $actualPnr,
                'status' => 'confirmed',
                'confirmed' => true,
                'percent' => $lastPercent,
                'item_id' => $itemId ?? null
            ]
        ]);

    } catch (Exception $e) {
        // Save error to database
        if (isset($booking) && isset($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'response_error' => $e->getMessage(),
                    'error' => 'booking_error',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);
        }

        // Log error
        error_log("RateHawk Booking Error: " . $e->getMessage() . " (Invoice: " . ($invoiceId ?? 'N/A') . ")");
        
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'success' => false,
            'error' => 'booking_error',
            'message' => $e->getMessage(),
            'response_error' => $e->getMessage()
        ]);
    }

    exit;
});
