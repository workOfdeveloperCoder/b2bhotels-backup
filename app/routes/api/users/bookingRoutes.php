<?php
// FILE: app/routes/api/users/Booking.php
// All booking routes (GET & POST)

@$SECURE or die('Access Denied!');

// ====================================
// BOOKING ROUTES
// ====================================

require_once 'app/lib/jwt.php';


$router->post('/api/bookings', function () use ($db) {

    header('Content-Type: application/json');

    // ================= GET AUTH HEADER =================
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] 
        ?? $headers['authorization'] 
        ?? $_SERVER['HTTP_AUTHORIZATION'] 
        ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    $token = trim($matches[1]);

    // ================= VERIFY TOKEN =================
    try {
        $decoded = JWT::verify($token);
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Invalid or expired token"
        ]);
        exit;
    }

    $user_id = $decoded['user_id'] ?? '';
    $isCustomer = ($decoded['role'] ?? '') === 'customer';

    if (empty($user_id)) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Invalid token payload"
        ]);
        exit;
    }

    // ================= GET BODY =================
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    $module = strtolower(trim($data['module'] ?? ($isCustomer ? ($data['filter'] ?? 'all') : 'all')));$module = strtolower(trim((string) ($data['module'] ?? $data['filter'] ?? 'all')));
    $module = str_replace(['-', ' '], '_', $module);
    if (in_array($module, ['ai_trip_planner', 'aitrip', 'ai'], true)) {
        $module = 'ai_trip';
    }
    $bookingStatus = strtolower(trim($data['booking_status'] ?? 'all'));
    $paymentStatus = strtolower(trim($data['payment_status'] ?? 'all'));

    // ================= BUILD QUERY =================
    $where = [
        "user_id" => $user_id
    ];
    $isAiTripFilter = $module === 'ai_trip';

    if ($module !== 'all') {
        if (!$isAiTripFilter) {
            // Handle both singular and plural from request
            $module = rtrim($module, 's');
            $where['module_type'] = [$module, $module . 's'];
        }
    }

    if ($bookingStatus !== 'all') {
        $where['booking_status'] = $bookingStatus;
    }

    if ($paymentStatus !== 'all') {
        $where['payment_status'] = $paymentStatus;
    }

    $where['ORDER'] = ["id" => "DESC"];

    $bookings = $db->select("bookings", "*", $where);

    if (!is_array($bookings)) {
        $bookings = [];
    }
    if ($isAiTripFilter) {
        // Multi-item plans are stored as ai_trip packages. A one-item plan
        // keeps its real module_type and is identified by its booking source.
        $bookings = array_values(array_filter($bookings, static function ($booking) {
            return strtolower((string) ($booking['module_type'] ?? '')) === 'ai_trip'
                || strtolower((string) ($booking['module'] ?? '')) === 'ai_trip'
                || str_contains((string) ($booking['booking_data'] ?? ''), 'ai_trip_single');
        }));
    }

    // ================= CLEAN DATA & EXTRACT POLICIES =================
    $cleanedBookings = [];
    foreach ($bookings as $b) {
        // Extract policies from booking_data if it exists
        $cancellation_policy = null;
        $privacy_policy = null;

        if (!empty($b['booking_data'])) {
            $bookingData = json_decode($b['booking_data'], true);
            if (is_array($bookingData)) {
                $cancellation_policy = $bookingData['cancellation_policy'] ?? null;
                $privacy_policy = $bookingData['privacy_policy'] ?? null;
            }
        }

        $invoice_url = !empty($b['invoice_id']) ? root . 'invoice/' . ($b['module_type'] ?? $b['module'] ?? 'flight') . '/' . $b['invoice_id'] : null;

        // Customer apps still consume the detailed booking history contract.
        if ($isCustomer) {
            unset($b['user_data'], $b['password']);
            // No supplier net price or platform margin in the customer's booking history.
            $b = bookingForClient($b);
            foreach (['price_markup', 'tax'] as $priceField) {
                if (isset($b[$priceField])) {
                    $b[$priceField] = round((float)$b[$priceField], 2);
                }
            }
            if ($invoice_url !== null) {
                $b['invoice_url'] = $invoice_url;
            }
            $b['cancellation_policy'] = $cancellation_policy;
            $b['privacy_policy'] = $privacy_policy;
            $cleanedBookings[] = $b;
            continue;
        }

        $cleanedBookings[] = [
            'id' => $b['id'] ?? '',
            'invoice_id' => $b['invoice_id'] ?? '',
            'module_type' => $b['module_type'] ?? $b['module'] ?? '',
            'booking_status' => $b['booking_status'] ?? 'pending',
            'payment_status' => $b['payment_status'] ?? 'unpaid',
            'price_markup' => isset($b['price_markup']) ? number_format((float)$b['price_markup'], 2, '.', '') : "0.00",
            'currency_markup' => $b['currency_markup'] ?? $b['currency'] ?? 'USD',
            'date' => $b['created_at'] ?? $b['booking_date'] ?? '',
            'invoice_url' => $invoice_url,
            'cancellation_policy' => $cancellation_policy,
            'privacy_policy' => $privacy_policy
        ];
    }
    $bookings = $cleanedBookings;

    if ($isCustomer) {
        // Legacy customer statistics describe the returned (filtered) bookings.
        $stats = [
            'total' => count($bookings),
            'confirmed' => count(array_filter($bookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed')),
            'pending' => count(array_filter($bookings, fn($b) => ($b['booking_status'] ?? '') === 'pending')),
            'total_amount' => array_sum(array_column($bookings, 'price_markup'))
        ];
    } else {
        // ================= STATS (Global for user) =================
        $allUserBookings = $db->select("bookings", ["booking_status", "payment_status", "price_markup"], ["user_id" => $user_id]);
        if (!is_array($allUserBookings)) $allUserBookings = [];

        $totalAmount = array_sum(array_column($allUserBookings, 'price_markup'));

        $stats = [
            "total_bookings" => count($allUserBookings),
            "booking_status" => [
                "all" => count($allUserBookings),
                "confirmed" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed')),
                "pending" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'pending')),
                "cancelled" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'cancelled'))
            ],
            "payment_status" => [
                "all" => count($allUserBookings),
                "paid" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'paid')),
                "unpaid" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'unpaid')),
                "refunded" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'refunded'))
            ],
            "total_amount" => number_format((float)$totalAmount, 2, '.', '')
        ];
    }

    $response = json_encode([
        "status" => true,
        "stats" => $stats,
        "bookings" => $bookings
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Bookings response could not be encoded",
            "error" => json_last_error_msg()
        ]);
        exit;
    }

    echo $response;
    exit;
});
