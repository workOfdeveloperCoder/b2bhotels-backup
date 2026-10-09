<?php
// FILE: app/routes/api/users/dashboard.php
// Users API dashboard endpoint

@$SECURE or die('Access Denied!');

// ====================================
// USERS DASHBOARD API
// ====================================

$router->post('/api/users/dashboard', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
    // ---- AUTH ----
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        return;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        return;
    }

    $user_id = $tokenData['user_id'];

    // Get user data
    $user = $db->get('users', '*', ['user_id' => $user_id]);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        return;
    }

    // The mobile app sends its currently selected display currency. Dashboard
    // amounts can originate from bookings made in different currencies, so
    // convert every monetary value before returning it.
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $displayCurrency = strtoupper(trim((string) (
        $input['currency']
        ?? $input['display_currency']
        ?? $_POST['currency']
        ?? $_POST['display_currency']
        ?? $user['currency']
        ?? defaultUserCurrency($db)
    )));
    $activeCurrency = $db->get('currencies', 'name', [
        'name' => $displayCurrency,
        'status' => '1'
    ]);
    if (!$activeCurrency) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unsupported currency: ' . $displayCurrency
        ]);
        return;
    }
    $displayCurrency = strtoupper((string) $activeCurrency);

    $convertAmount = static function ($amount, $fromCurrency) use ($db, $displayCurrency): float {
        $fromCurrency = strtoupper(trim((string) $fromCurrency));
        if ($fromCurrency === '') {
            $fromCurrency = defaultUserCurrency($db);
        }
        return convertCurrencyAmount($db, (float) $amount, $fromCurrency, $displayCurrency);
    };

    // Get total bookings stats
    $totalBookings = $db->count('bookings', ['user_id' => $user_id]);
    $pendingBookings = $db->count('bookings', ['user_id' => $user_id, 'booking_status' => 'pending']);
    $confirmedBookings = $db->count('bookings', ['user_id' => $user_id, 'booking_status' => 'confirmed']);
    

    // Get recent bookings
    $recentBookingsRaw = $db->select('bookings', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC'],
        'LIMIT' => 10
    ]);

    $recentBookings = [];
    if (is_array($recentBookingsRaw)) {
        foreach ($recentBookingsRaw as $b) {
            $invoice_url = !empty($b['invoice_id']) ? root . 'invoice/' . ($b['module_type'] ?? $b['module'] ?? 'flight') . '/' . $b['invoice_id'] : null;
            $bookingCurrency = $b['currency_markup'] ?? $b['currency'] ?? defaultUserCurrency($db);
            $displayPrice = $convertAmount($b['price_markup'] ?? 0, $bookingCurrency);
            $recentBookings[] = [
                'id' => $b['id'] ?? '',
                'invoice_id' => $b['invoice_id'] ?? '',
                'module_type' => $b['module_type'] ?? $b['module'] ?? '',
                'booking_status' => $b['booking_status'] ?? 'pending',
                'payment_status' => $b['payment_status'] ?? 'unpaid',
                'price_markup' => number_format($displayPrice, 2, '.', ''),
                'currency_markup' => $displayCurrency,
                'date' => $b['created_at'] ?? $b['booking_date'] ?? '',
                'invoice_url' => $invoice_url
            ];
        }
    }

    // Credit ledger rows may also use different currencies.
    $creditsBalance = 0.0;
    $creditRows = $db->select('credits', ['type', 'credits', 'currency'], ['user_id' => $user_id]);
    foreach (is_array($creditRows) ? $creditRows : [] as $creditRow) {
        $amount = $convertAmount($creditRow['credits'] ?? 0, $creditRow['currency'] ?? $user['currency'] ?? '');
        $creditsBalance += strtolower((string) ($creditRow['type'] ?? '')) === 'debit' ? -$amount : $amount;
    }

    $walletCurrency = $user['currency'] ?? defaultUserCurrency($db);
    $walletBalance = $convertAmount($user['balance'] ?? 0, $walletCurrency);
    $isAgent = strtolower((string)($user['role'] ?? '')) === 'agent';

    // Agent earning is stored per booking. Cancelled and voided bookings keep
    // their historical value in the database but do not contribute to totals.
    $totalEarning = 0.0;
    if ($isAgent) {
        $earningBookings = $db->select('bookings', [
            'agent_earning',
            'currency_markup',
            'booking_status'
        ], ['user_id' => $user_id]);
        foreach (is_array($earningBookings) ? $earningBookings : [] as $earningBooking) {
            if (!bookingEarnsRevenue($earningBooking['booking_status'] ?? '')) {
                continue;
            }
            $totalEarning += $convertAmount(
                $earningBooking['agent_earning'] ?? 0,
                $earningBooking['currency_markup'] ?? $walletCurrency
            );
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Dashboard data retrieved',
        'data' => [
            'credits_balance' => number_format($creditsBalance, 2, '.', ''),
            'total_bookings' => $totalBookings,
            'pending_bookings' => $pendingBookings,
            'confirmed_bookings' => $confirmedBookings,
            'wallet_balance' => number_format((float)$walletBalance, 2, '.', ''),
            'total_agent_earning' => number_format($totalEarning, 2, '.', ''),
            'total_earning' => number_format($totalEarning, 2, '.', ''),
            'member_since' => $user['created_at'] ?? 'N/A',
            'currency' => $displayCurrency,
            'is_agent' => $isAgent,
            'recent_bookings' => $recentBookings ?: []
        ]
    ]);
    } catch (\Throwable $e) {
        error_log('Users Dashboard API Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Dashboard data could not be retrieved'
        ]);
    }
});
