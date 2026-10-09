<?php
// FILE: app/routes/esim/invoiceRoutes.php
// eSIM booking invoice routes

@$SECURE or die('Access Denied!');

$router->post('/esim/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $respond = static function ($status, $message, $success = false) {
        http_response_code($status);
        echo json_encode(['success' => $success, 'message' => $message]);
    };

    if (empty($_SESSION['user_id'])) {
        $respond(401, 'Please log in to request cancellation.');
        return;
    }
    if (!is_string($_POST['csrf_token'] ?? null) || !CSRF::validateToken($_POST['csrf_token'])) {
        $respond(403, 'Your session has expired. Refresh the page and try again.');
        return;
    }
    $invoiceId = $_POST['invoice_id'] ?? '';
    if (!is_string($invoiceId) || !preg_match('/^[a-zA-Z0-9]+$/', $invoiceId)) {
        $respond(400, 'A valid invoice ID is required.');
        return;
    }

    try {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'esim']);
        if (!$booking) {
            $respond(404, 'Booking not found.');
            return;
        }
        $role = $db->get('users', 'role', ['user_id' => $_SESSION['user_id']]);
        if (!$role || ($role !== 'admin' && (string) ($booking['user_id'] ?? '') !== (string) $_SESSION['user_id'])) {
            $respond(403, 'You cannot request cancellation for this booking.');
            return;
        }
        if (in_array(strtolower((string) $booking['booking_status']), ['cancelled', 'voided'], true)) {
            $respond(409, 'This booking is already cancelled or voided.');
            return;
        }
        if ((int) $booking['cancellation_request'] === 1) {
            $respond(200, 'Cancellation request already submitted.', true);
            return;
        }
        $result = $db->update('bookings', ['cancellation_request' => 1], [
            'invoice_id' => $invoiceId,
            'module_type' => 'esim',
            'booking_status[!]' => ['cancelled', 'voided'],
        ]);
        if (!$result || $result->rowCount() < 1) {
            $respond(409, 'Unable to submit the request. Refresh the invoice and try again.');
            return;
        }
        $respond(200, 'Cancellation request submitted for review.', true);
    } catch (Throwable $e) {
        error_log('ESIM CANCELLATION REQUEST ERROR: ' . $e->getMessage());
        $respond(500, 'Unable to submit cancellation. Please try again.');
    }
});

$router->get('/api/esim/booking/download-invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'esim']);
    if (!$booking) {
        http_response_code(404);
        exit('Booking not found');
    }

    $isAdmin = isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';
    if (!$isAdmin && isset($_SESSION['user_id']) && !empty($booking['user_id'])
        && (string) $booking['user_id'] !== (string) $_SESSION['user_id']) {
        http_response_code(403);
        exit('Unauthorized access');
    }

    $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
    if (!$pdfPath || !is_file($pdfPath)) {
        http_response_code(500);
        exit('Error downloading invoice');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="esim_invoice_' . $invoiceId . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, no-store');
    readfile($pdfPath);
    exit;
});

$router->get('/invoice/esim/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    $booking = $db->get('bookings', '*', [
        'OR' => [
            'id' => $invoiceId,
            'invoice_id' => $invoiceId,
        ],
        'module_type' => 'esim',
    ]);

    if (!$booking) {
        http_response_code(404);
        $title = 'Invoice Not Found';
        $description = 'The requested invoice could not be found';
        $header = true;
        $footer = true;
        require_once views . 'includes/header.php';
        require_once views . 'errors/404.php';
        require_once views . 'includes/footer.php';
        exit;
    }

    $invoiceId = $booking['invoice_id'];

    $isAdmin = isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';

    if (!$isAdmin && isset($_SESSION['user_id']) && !empty($booking['user_id'])) {
        if ((string) $booking['user_id'] !== (string) $_SESSION['user_id']) {
            $_SESSION['error'] = 'Unauthorized access';
            header('Location: ' . root . 'bookings');
            exit;
        }
    }

    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';

        $token = $_GET['token'] ?? '';
        if (!$token) {
            $_SESSION['error'] = 'Missing payment token. Please try again.';
            header('Location: ' . root . 'invoice/esim/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = [
            'gateway' => $booking['payment_method'] ?? 'unknown',
            'transaction_id' => $_GET['transaction_id'] ?? $_GET['session_id'] ?? null,
            'session_id' => $_GET['session_id'] ?? null,
            'module_type' => 'esim',
            'module' => $booking['module'] ?? 'airalo',
        ];

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success') {
            if ($result['success'] ?? false) {
                $_SESSION['success'] = 'Payment successful! Your eSIM booking is confirmed.';
            } else {
                $_SESSION['error'] = 'Payment successful but booking confirmation failed: ' . ($result['message'] ?? 'Unknown error');
            }
        } elseif ($action === 'cancel') {
            $_SESSION['error'] = 'Payment was cancelled.';
        } else {
            $_SESSION['error'] = 'Payment failed. Please try again.';
        }

        header('Location: ' . root . 'invoice/esim/' . $invoiceId);
        exit;
    }

    // Recovery path: if payment is already marked paid but Airalo wasn't issued,
    // attempt issuance when opening invoice so customer can proceed to activation.
    $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
    if (!is_array($bookingData)) {
        $bookingData = [];
    }

    $airaloProvision = (array) ($bookingData['airalo_provision'] ?? []);
    $hasProvision = !empty($airaloProvision['id'])
        || !empty($airaloProvision['order_id'])
        || !empty($airaloProvision['qrcode_url'])
        || !empty($airaloProvision['iccid']);

    if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid' && !$hasProvision) {
        try {
            $issueUrl = root . 'modules/esim/airalo/issue';
            $ch = curl_init($issueUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['invoice_id' => $invoiceId]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('Airalo recovery request failed: ' . $curlError);
            }

            if ($httpCode !== 200) {
                throw new Exception('Airalo recovery request returned HTTP ' . $httpCode . ': ' . substr((string) $raw, 0, 300));
            }

            $issueResult = json_decode((string) $raw, true);
            if (!is_array($issueResult) || empty($issueResult['status'])) {
                throw new Exception('Airalo recovery issue failed: ' . ($issueResult['response_error'] ?? $issueResult['message'] ?? 'Unknown error'));
            }

            // Reload booking with latest provisioning data after successful recovery issue.
            $booking = $db->get('bookings', '*', [
                'invoice_id' => $invoiceId,
                'module_type' => 'esim',
            ]);
        } catch (Throwable $e) {
            error_log('ESIM INVOICE RECOVERY ISSUE ERROR | Invoice: ' . $invoiceId . ' | ' . $e->getMessage());
        }
    }

    $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', is_array($bookingData) ? $bookingData : []);

    $title = 'eSIM Invoice #' . $invoiceId;
    $description = 'View your eSIM booking invoice';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/esim/invoice/index.php';
    require_once views . 'includes/footer.php';
});
