<?php
// ============================================================================
// TRAIN (RAIL) — MOBILE API HELPERS
// ============================================================================

@$SECURE or die('Access Denied!');

require_once __DIR__ . '/search.php';

if (!function_exists('_train_respond')) {
    function _train_respond(bool $success, string $message, $data = null, int $httpCode = 200): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('_train_module_ready')) {
    /** @return array module row or empty array when unavailable */
    function _train_module_ready($db): array
    {
        $cfg = _train_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0' || ($cfg['active'] ?? '0') === '0') {
            return [];
        }
        if (trim((string)($cfg['c1'] ?? '')) === '' || trim((string)($cfg['c2'] ?? '')) === '') {
            return [];
        }
        return $cfg;
    }
}

if (!function_exists('_train_is_module_ready')) {
    function _train_is_module_ready($db): bool
    {
        return _train_module_ready($db) !== [];
    }
}

if (!function_exists('_train_jwt_context')) {
    /**
     * Resolve logged-in user from Authorization / token headers.
     *
     * @return array{user_id:?string,user_data:?array,is_agent:bool}
     */
    function _train_jwt_context($db): array
    {
        if (!class_exists('JWT')) {
            require_once dirname(__DIR__, 3) . '/app/lib/jwt.php';
        }

        $headersLower = [];
        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headersLower[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }

        $authHeader = $headersLower['authorization'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if ($authHeader !== '') {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
        }
        if ($token === '') {
            $token = $headersLower['token'] ?? $headersLower['jwt'] ?? '';
        }

        $userId = null;
        $userData = null;
        $isAgent = false;

        if ($token !== '') {
            try {
                $tokenData = JWT::verify($token);
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = (string)$tokenData['user_id'];
                    $whereClause = ['user_id' => $userId];
                    if (is_numeric($userId)) {
                        $whereClause = ['OR' => ['user_id' => $userId, 'id' => (int)$userId]];
                    }
                    $userData = $db->get('users', '*', $whereClause) ?: null;
                    if ($userData) {
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = $userData['role'] ?? 'user';
                        $isAgent = strtolower((string)($userData['role'] ?? '')) === 'agent';
                    }
                }
            } catch (Throwable $e) {
                // Optional auth — ignore invalid tokens
            }
        }

        return [
            'user_id'   => $userId,
            'user_data' => $userData,
            'is_agent'  => $isAgent,
        ];
    }
}

if (!function_exists('_train_apply_search_markup')) {
    /** Apply B2C/B2B markup to trainQuery seat prices in-place. */
    function _train_apply_search_markup(array &$supplierPayload, $db, string $displayCurrency = 'USD'): void
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        if (empty($supplierPayload['data']['data']) || !is_array($supplierPayload['data']['data'])) {
            return;
        }

        foreach ($supplierPayload['data']['data'] as &$train) {
            if (empty($train['seats']) || !is_array($train['seats'])) {
                continue;
            }
            foreach ($train['seats'] as &$seat) {
                if (!is_array($seat)) {
                    continue;
                }
                _train_apply_seat_price_markup($seat, $db, $displayCurrency);
            }
            unset($seat);
            _train_enrich_seat_labels($train['seats']);
        }
        unset($train);

        $supplierPayload['currency'] = $displayCurrency;
    }
}

if (!function_exists('_train_count_passengers')) {
    function _train_count_passengers(array $passengers): array
    {
        $adults = 0;
        $children = 0;
        foreach ($passengers as $p) {
            $type = (int)($p['passenger_type'] ?? 1);
            if ($type === 2 || $type === 3) {
                $children++;
            } else {
                $adults++;
            }
        }
        return ['adults' => $adults, 'children' => $children];
    }
}

if (!function_exists('_train_create_booking')) {
    /**
     * Create a local pending/unpaid rail booking (no supplier call).
     *
     * @return array{invoice_id:string,booking_id:int,final_price:float}
     */
    function _train_create_booking($db, array $input, array $userCtx = []): array
    {
        if (empty($input['journey']) || !is_array($input['journey']) || empty($input['passengers']) || !is_array($input['passengers'])) {
            throw new InvalidArgumentException('journey and passengers are required');
        }

        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $totalPrice = 0.0;
        foreach ($input['journey'] as $leg) {
            $totalPrice += (float)($leg['price_total_limit'] ?? 0.0);
        }
        if ($totalPrice <= 0) {
            throw new InvalidArgumentException('Invalid journey pricing');
        }

        $markupData = _train_apply_booking_markup($db, $totalPrice, $input);
        $finalPrice = (float)$markupData['final_price'];
        $commission = (float)$markupData['commission'];
        $isAgent = !empty($userCtx['is_agent']);
        $agentEarning = $isAgent ? $commission : 0.0;

        $passengers = $input['passengers'];
        $counts = _train_count_passengers($passengers);
        $storedChildAges = [];
        foreach ($passengers as $p) {
            $type = (int)($p['passenger_type'] ?? 1);
            if (($type === 2 || $type === 3) && isset($p['passenger_age'])) {
                $storedChildAges[] = (int)$p['passenger_age'];
            }
        }
        if ($storedChildAges === [] && !empty($input['child_ages'])) {
            $journeyType = (int)($input['journey'][0]['journey_type'] ?? $input['journey_type'] ?? 1);
            $storedChildAges = _train_parse_child_ages($input['child_ages'], $counts['children'], $journeyType);
        }

        $primaryGuest = $input['guest_details']['primary_guest'] ?? null;
        if (is_array($primaryGuest) && !empty($primaryGuest['first_name'])) {
            $firstName = htmlspecialchars(strip_tags(trim((string)$primaryGuest['first_name'])), ENT_QUOTES, 'UTF-8');
            $lastName  = htmlspecialchars(strip_tags(trim((string)$primaryGuest['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
            $email     = filter_var(trim((string)($primaryGuest['email'] ?? '')), FILTER_SANITIZE_EMAIL);
            $phone     = preg_replace('/[^0-9+\-\s]/', '', (string)($primaryGuest['phone'] ?? ''));
            $country   = $primaryGuest['country_code'] ?? '';
        } else {
            $firstP = $passengers[0] ?? [];
            $firstName = trim((string)($firstP['passenger_first_name'] ?? 'Guest'));
            $lastName  = trim((string)($firstP['passenger_last_name'] ?? ''));
            $email     = filter_var(trim((string)($input['contact_email'] ?? 'guest@example.com')), FILTER_SANITIZE_EMAIL);
            $phone     = preg_replace('/[^0-9+\-\s]/', '', (string)($input['contact_phone'] ?? ''));
            $country   = $firstP['passenger_country_code'] ?? '';
        }

        if ($firstName === '' || $email === '') {
            throw new InvalidArgumentException('Contact name and email are required');
        }

        $promoCodeStr = trim((string)($input['promo_code'] ?? ''));
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData = null;
        if ($promoCodeStr !== '' && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code'                => $promoData['code'],
                    'discount_type'       => $promoData['discount_type'],
                    'discount_value'      => floatval($promoData['discount_value']),
                    'discount_amount'     => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description'         => $promoData['description'],
                    'module'              => $promoData['module'],
                ]);
                $finalPrice = round($finalPrice - $promoDiscount, 2);
            }
        }

        $invoiceId = 'RAIL' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)) . date('His');
        $userId = $userCtx['user_id'] ?? null;
        $userData = $userCtx['user_data'] ?? null;

        if (empty($input['callBackUrl'])) {
            $input['callBackUrl'] = rtrim(root, '/') . '/ticket/offlinePush';
        }

        $db->insert('bookings', [
            'invoice_id'         => $invoiceId,
            'booking_status'     => 'pending',
            'payment_status'     => 'unpaid',
            'price_original'     => $totalPrice,
            'price_markup'       => $finalPrice,
            'tax'                => 0.0,
            'tax_type'           => 'percentage',
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'email'              => $email,
            'phone_country_code' => '',
            'phone'              => $phone,
            'country'            => $country,
            'address'            => '',
            'adults'             => $counts['adults'],
            'childs'             => $counts['children'],
            'child_ages'         => json_encode($storedChildAges),
            'module_type'        => 'rail',
            'module'             => 'train',
            'pnr'                => '',
            'booking_response'   => null,
            'booking_data'       => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'travellers'         => json_encode($passengers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_id'            => $userId,
            'user_data'          => $userData ? json_encode($userData) : null,
            'currency_markup'    => _train_target_currency($input),
            'commission'         => $commission,
            'agent_earning'      => $agentEarning,
            'booking_date'       => date('Y-m-d'),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
            'promo_codes'        => $promoCodeJson,
        ]);

        $bookingId = (int)$db->id();
        if ($bookingId && $promoCodeStr !== '' && $promoDiscount > 0 && $promoData) {
            $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
        }

        return [
            'invoice_id'  => $invoiceId,
            'booking_id'  => $bookingId,
            'final_price' => $finalPrice,
        ];
    }
}

if (!function_exists('_train_invoice_view')) {
    /** Build a mobile-friendly invoice payload from a bookings row. */
    function _train_invoice_view(array $booking): array
    {
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        $journey = $bookingData['journey'][0] ?? [];
        $passengers = json_decode($booking['travellers'] ?? '[]', true) ?: [];
        $bookingResponse = json_decode($booking['booking_response'] ?? '{}', true) ?: [];

        $rspPassengers = _train_extract_rsp_passengers($bookingResponse);

        $hasAssignedSeats = false;
        foreach ($rspPassengers as $rp) {
            if (!empty($rp['train_seat_no']) || !empty($rp['train_coach_no'])) {
                $hasAssignedSeats = true;
                break;
            }
        }

        $orderFailMsg = trim((string)(
            $bookingResponse['data']['fail_msg']
            ?? $bookingResponse['fail_msg']
            ?? ''
        ));
        if ($orderFailMsg === '') {
            $errorResponse = json_decode($booking['error_response'] ?? '{}', true) ?: [];
            $orderFailMsg = trim((string)($errorResponse['fail_msg_en'] ?? $errorResponse['fail_msg'] ?? ''));
        }

        $isUserCancelled = ($booking['booking_status'] ?? '') === 'cancelled'
            && (!empty($booking['cancellation_request']) || !empty($booking['cancellation_status']));

        $hasPnr = !empty($booking['pnr']);
        $seatsPending = ($booking['payment_status'] ?? '') === 'paid'
            && $hasPnr
            && !$hasAssignedSeats
            && !$isUserCancelled;

        $displayStatus = (($booking['payment_status'] ?? '') === 'paid' && $hasPnr)
            ? 'confirmed'
            : ($booking['booking_status'] ?? 'pending');

        $displayFailMsg = $hasPnr ? '' : $orderFailMsg;

        $booking['booking_data']   = $bookingData;
        $booking['booking_response'] = $bookingResponse;
        $booking['travellers']     = $passengers;
        $booking['journey']        = $journey;
        $seatCode = strtoupper(trim((string)($journey['seat_class'] ?? '')));
        $booking['seat_class_code']  = $seatCode;
        $booking['seat_class_label'] = _train_seat_class_label($seatCode);
        $booking['passenger_seats']  = _train_merge_passenger_seats($passengers, $rspPassengers, $displayFailMsg);
        $booking['supplier_passengers'] = array_map(
            static fn($p) => _train_enrich_supplier_passenger(is_array($p) ? $p : [], $displayFailMsg),
            $rspPassengers
        );
        $booking['display_status'] = $displayStatus;
        $booking['seats_pending']  = $seatsPending;
        $booking['seat_fail_message'] = $displayFailMsg;
        $booking['has_assigned_seats'] = $hasAssignedSeats;
        $booking['payment_url']    = rtrim(root, '/') . '/invoice/rail/' . ($booking['invoice_id'] ?? '');

        return $booking;
    }
}
