<?php
// ============================================================================
// FILE: app/routes/cruises/bookingRoutes.php
// CRUISE BOOKING — checkout form, submit, invoice PDF, cancellation request.
// Prices are always recomputed on the server from the imported catalogue;
// nothing price-related is taken from the browser.
// ============================================================================
@$SECURE or die('Access Denied!');

$__wgcCatalog = dirname(__DIR__, 3) . '/modules/cruises/widgety/catalog.php';
if (is_file($__wgcCatalog)) { require_once $__wgcCatalog; }

// CHECKOUT PAGE — /cruises/booking/{date_ref}?cabin=0-2BS&adults=2&children=0
$router->get('/cruises/booking/([A-Za-z0-9_\-]{3,64})', function ($dateRef) use ($SECURE, $db) {
    $cruise = function_exists('_wgc_sailing') ? _wgc_sailing($db, $dateRef) : null;
    $filters = function_exists('_wgc_filters') ? _wgc_filters($_GET) : [];
    $cabinKey = (string) ($_GET['cabin'] ?? '');
    $quote = $cruise ? _wgc_quote($db, $cruise['sailing'], $cabinKey, $filters['adults'], $filters['children'], _wgc_currency($db)) : null;

    if (!$cruise || !$quote) {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'This cabin is not available online. Please choose another cabin or sailing.'];
        header('Location: ' . ($cruise ? _wgc_url($cruise['key'], $cruise['name'], ['date' => $dateRef]) : root . 'cruises'));
        exit;
    }

    $adults = $filters['adults'];
    $children = $filters['children'];
    $old = $_SESSION['cruise_booking_old'] ?? [];
    $formError = $_SESSION['cruise_booking_error'] ?? '';
    unset($_SESSION['cruise_booking_old'], $_SESSION['cruise_booking_error']);

    $title = T::booking . ' - ' . $cruise['name'];
    $description = '';
    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/booking/index.php';
    require_once views . 'includes/footer.php';
});

// SUBMIT BOOKING — creates the booking (pending / unpaid) and opens the invoice.
$router->post('/cruises/booking/submit', function () use ($SECURE, $db) {
    $in = $_POST;
    $dateRef = (string) ($in['date_ref'] ?? '');
    $cabinKey = (string) ($in['cabin'] ?? '');
    $filters = function_exists('_wgc_filters') ? _wgc_filters($in) : ['adults' => 1, 'children' => 0];
    $back = root . 'cruises/booking/' . rawurlencode($dateRef) . '?' . http_build_query([
        'cabin' => $cabinKey, 'adults' => $filters['adults'], 'children' => $filters['children'],
    ]);
    $fail = function (string $msg) use ($in, $back) {
        unset($in['csrf_token']);
        $_SESSION['cruise_booking_old'] = $in;
        $_SESSION['cruise_booking_error'] = $msg;
        header('Location: ' . $back);
        exit;
    };

    if (!CSRF::validateToken($in['csrf_token'] ?? '')) {
        $fail('Your session expired. Please submit the form again.');
    }

    $cruise = function_exists('_wgc_sailing') ? _wgc_sailing($db, $dateRef) : null;
    $baseCurrency = function_exists('_wgc_default_currency') ? _wgc_default_currency($db) : 'USD';
    $quote = $cruise ? _wgc_quote($db, $cruise['sailing'], $cabinKey, $filters['adults'], $filters['children'], $baseCurrency) : null;
    if (!$cruise || !$quote) {
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'This cabin is no longer available. Please choose another one.'];
        header('Location: ' . root . 'cruises');
        exit;
    }

    // LEAD GUEST
    $clean = fn($k, $len = 100) => trim(substr(strip_tags((string) ($in[$k] ?? '')), 0, $len));
    $lead = [
        'title'        => in_array($in['title'] ?? '', ['Mr', 'Mrs', 'Miss', 'Ms'], true) ? $in['title'] : 'Mr',
        'first_name'   => $clean('first_name'),
        'last_name'    => $clean('last_name'),
        'email'        => strtolower($clean('email', 150)),
        'country_code' => strtoupper($clean('country_code', 3)),
        'phone'        => preg_replace('/[^0-9]/', '', (string) ($in['phone'] ?? '')),
        'nationality'  => strtoupper($clean('nationality', 3)),
    ];
    if ($lead['first_name'] === '' || $lead['last_name'] === '') {
        $fail('Please enter the lead guest first and last name.');
    }
    if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        $fail('Please enter a valid email address.');
    }
    if (strlen($lead['phone']) < 5) {
        $fail('Please enter a valid phone number.');
    }
    if ($guestError = bookingPeopleError($lead, $in['travelers'] ?? [])) {
        $fail($guestError);
    }
    if (empty($in['terms'])) {
        $fail('Please accept the terms and conditions.');
    }

    // OTHER GUESTS (lead guest is guest 1)
    $travelers = [];
    $guestCount = $filters['adults'] + $filters['children'];
    for ($i = 2; $i <= $guestCount; $i++) {
        $g = (array) ($in['travelers'][$i] ?? []);
        $first = trim(substr(strip_tags((string) ($g['first_name'] ?? '')), 0, 100));
        $last = trim(substr(strip_tags((string) ($g['last_name'] ?? '')), 0, 100));
        if ($first === '' || $last === '') {
            $fail('Please enter the name of every guest.');
        }
        $travelers[] = [
            'title'      => in_array($g['title'] ?? '', ['Mr', 'Mrs', 'Miss', 'Ms', 'Master'], true) ? $g['title'] : '',
            'first_name' => $first,
            'last_name'  => $last,
            'type'       => $i <= $filters['adults'] ? 'adult' : 'child',
        ];
    }

    $sailing = $cruise['sailing'];
    $cabin = $quote['cabin'];
    $bookingData = [
        'supplier'          => 'widgety',
        'cruise_key'        => $cruise['key'],
        'cruise_ref'        => $sailing['cruise_ref'],
        'date_ref'          => $sailing['date_ref'],
        'cruise_name'       => $cruise['name'],
        'tour_name'         => $cruise['name'],
        'operator_name'     => $cruise['operator_name'],
        'ship_name'         => $sailing['ship'],
        'start_date'        => $sailing['date'],
        'end_date'          => $sailing['return'],
        'nights'            => $cruise['nights'],
        'duration'          => $cruise['nights'] . ' nights',
        'embark_port'       => $sailing['embark'],
        'disembark_port'    => $sailing['disembark'],
        'tour_location'     => trim($sailing['embark'] . ' → ' . $sailing['disembark'], ' →'),
        'image'             => $cruise['image'],
        'tour_image'        => $cruise['image'],
        'cabin'             => [
            'key' => $cabin['key'], 'fare' => $cabin['fare'], 'name' => $cabin['name'],
            'code' => $cabin['code'], 'room_type' => $cabin['room_type'],
        ],
        'price_lines'       => $quote['lines'],
        'supplier_total'    => $quote['supplier_total'],
        'supplier_currency' => $quote['supplier_currency'],
        'currency'          => $baseCurrency,
        'subtotal'          => $quote['subtotal'],
        'markup_total_tour_price' => $quote['subtotal'],
        'display_currency'  => $_SESSION['app_currency'] ?? $baseCurrency,
        'total_adults'      => $filters['adults'],
        'total_children'    => $filters['children'],
    ];

    // GUEST CHECKOUT → FIND OR CREATE THE CUSTOMER ACCOUNT (same as tours)
    $userId = $_SESSION['user_id'] ?? null;
    $userData = $userId ? $db->get('users', '*', ['user_id' => $userId]) : null;
    if (!$userId) {
        $existing = $db->get('users', '*', ['email' => $lead['email']]);
        if ($existing) {
            $userId = $existing['user_id'];
            $userData = $existing;
        } else {
            $newUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $ok = $db->insert('users', [
                'user_id'            => $newUserId,
                'first_name'         => $lead['first_name'],
                'last_name'          => $lead['last_name'],
                'email'              => $lead['email'],
                'password'           => password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
                'phone'              => $lead['phone'],
                'phone_country_code' => $lead['country_code'],
                'status'             => 'active',
                'role'               => 'user',
                'currency'           => defaultUserCurrency($db),
                'created_at'         => date('Y-m-d H:i:s'),
                'email_verified'     => 0,
            ]);
            if ($ok && $ok->rowCount()) {
                $userId = $newUserId;
                $userData = $db->get('users', '*', ['user_id' => $newUserId]);
            }
        }
    }

    $isAgent = strtolower((string) ($userData['role'] ?? ($_SESSION['user_role'] ?? ''))) === 'agent';
    $nationality = $db->get('countries', 'nicename', ['iso' => $lead['nationality']]) ?: $lead['nationality'];
    $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    $db->insert('bookings', [
        'invoice_id'           => $invoiceId,
        'language'             => getCurrentLanguage(),
        'booking_date'         => date('Y-m-d H:i:s'),
        'booking_status'       => 'pending',
        'price_original'       => $quote['base'],
        'price_markup'         => $quote['total'],
        'agent_earning'        => $isAgent ? max(0, $quote['markup']) : 0,
        'tax'                  => $quote['tax'],
        'tax_type'             => $quote['tax_type'],
        'first_name'           => $lead['first_name'],
        'last_name'            => $lead['last_name'],
        'email'                => $lead['email'],
        'address'              => '',
        'phone_country_code'   => getPhoneCode($lead['country_code'], $db),
        'phone'                => $lead['phone'],
        'country'              => $lead['country_code'],
        'adults'               => $filters['adults'],
        'infants'              => 0,
        'childs'               => $filters['children'],
        'child_ages'           => json_encode([]),
        'currency_markup'      => $baseCurrency,
        'cancellation_request' => 0,
        'cancellation_status'  => 0,
        'booking_data'         => json_encode($bookingData),
        'payment_status'       => 'unpaid',
        'transaction_id'       => null,
        'user_id'              => $userId,
        'user_data'            => $userData ? json_encode($userData) : null,
        'travellers'           => json_encode([
            'primary_guest'            => $lead,
            'travelers'                => $travelers,
            'booking_for_someone_else' => false,
        ]),
        'nationality'          => $nationality,
        'payment_gateway'      => preg_replace('/[^0-9]/', '', (string) ($in['payment'] ?? '')),
        'module_type'          => 'cruises',
        'pnr'                  => null,
        'booking_response'     => null,
        'error_response'       => null,
        'commission'           => $quote['markup'],
        'module'               => 'widgety',
        'special_requests'     => $clean('special_requests', 1000) ?: null,
        'promo_codes'          => null,
    ]);

    if (!$db->id()) {
        $fail('We could not save your booking. Please try again.');
    }

    triggerWebhook('cruises/booking', 'cruises.booking.confirmed', [
        'invoice_id'     => $invoiceId,
        'user_id'        => $userId,
        'customer_name'  => $lead['first_name'] . ' ' . $lead['last_name'],
        'customer_email' => $lead['email'],
        'cruise_name'    => $cruise['name'],
        'ship_name'      => $sailing['ship'],
        'start_date'     => $sailing['date'],
        'cabin'          => $cabin['name'],
        'total_amount'   => $quote['total'],
        'currency'       => $baseCurrency,
        'adults'         => $filters['adults'],
        'children'       => $filters['children'],
        'timestamp'      => date('Y-m-d H:i:s'),
    ]);

    header('Location: ' . root . 'invoice/cruises/' . $invoiceId);
    exit;
});

// DOWNLOAD INVOICE PDF
$router->get('/api/cruises/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', ['invoice_id', 'module_type'], ['invoice_id' => $invoiceId]);
    if (!$booking || $booking['module_type'] !== 'cruises') {
        http_response_code(404);
        die('Invoice not found');
    }
    $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
    if (!$pdfPath || !file_exists($pdfPath)) {
        http_response_code(500);
        die('Error downloading invoice');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cruise_invoice_' . $invoiceId . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($pdfPath);
    exit;
});

// REQUEST CANCELLATION
$router->post('/api/cruises/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $invoiceId = (string) ($input['invoice_id'] ?? '');
    $booking = $invoiceId !== '' ? $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'cruises']) : null;

    if (!$booking) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    if ((int) $booking['cancellation_request'] === 1) {
        echo json_encode(['success' => false, 'message' => 'Cancellation already requested']);
        exit;
    }

    $db->update('bookings', ['cancellation_request' => 1], ['invoice_id' => $invoiceId]);
    try {
        NOTIFY::cancellation('cruises', [
            'email'        => $booking['email'] ?? '',
            'phone'        => $booking['phone'] ?? '',
            'first_name'   => $booking['first_name'] ?? '',
            'last_name'    => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? '',
        ], [
            'invoice_id'  => $invoiceId,
            'amount'      => $booking['price_markup'] ?? 0,
            'currency'    => $booking['currency_markup'] ?? 'USD',
            'module_type' => 'Cruise',
        ]);
    } catch (\Throwable $e) {
        error_log('CRUISE CANCELLATION NOTIFY | ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'message' => 'Cancellation request submitted successfully']);
    exit;
});
