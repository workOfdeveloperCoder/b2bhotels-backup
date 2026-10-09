<?php
// FILE: app/routes/users/dashboard.php
// User dashboard route

@$SECURE or die('Access Denied!');

// ====================================
// DASHBOARD ROUTES
// ====================================

$router->get('/dashboard', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Get user data
    $user_id = $_SESSION['user_id'];
    $user = $db->get('users', '*', ['user_id' => $user_id]);

    // First-time agents land here after signup. Instead of a hard redirect,
    // show a welcome alert on the dashboard prompting them to complete their
    // agency profile (the booking/deposit pages still enforce via CHECK_AGENT).
    $agencyIncomplete = false;
    if (strtolower((string)($user['role'] ?? '')) === 'agent') {
        $agency = $db->get('agencies', '*', ['user_id' => $user_id]);
        $agencyIncomplete = (
            !$agency ||
            empty($agency['agency_name']) ||
            empty($agency['email']) ||
            empty($agency['phone'])
        );
    }

    // Fetch user bookings
    $allBookings = $db->select('bookings', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC']
    ]);

    // Calculate stats
    $totalBookings = count($allBookings);
    $confirmedBookings = count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed'));
    $pendingBookings = count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'pending'));
    $walletBalance = $user['balance'] ?? 0.00;
    $walletCurrency = $user['currency'] ?? 'USD';
    $isAgent = strtolower((string)($user['role'] ?? $_SESSION['user_role'] ?? '')) === 'agent';

    // Initialize dashboard data
    $dashboardData = [
        'total_bookings' => $totalBookings,
        'pending_bookings' => $pendingBookings,
        'confirmed_bookings' => $confirmedBookings,
        'wallet_balance' => number_format($walletBalance, 2),
        'member_since' => $user['created_at'] ?? 'N/A',
        'recent_bookings' => array_slice($allBookings, 0, 5),
        'currency' => $walletCurrency,
        'is_agent' => $isAgent,
        'agency_incomplete' => $agencyIncomplete,
    ];

    // META DATA
    $title = "Dashboard";
    $description = "User dashboard";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."auth/dashboard.php";
    require_once views."includes/footer.php";
});
