<?php
// ============================================================================
// FILE: app/routes/cruises/invoiceRoutes.php
// CRUISE INVOICE — /invoice/cruises/{invoice_id}
// Payment confirms the booking (Widgety is content-only; the sailing is then
// finalised with the cruise line offline, like tours).
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/invoice/cruises/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invoice not found'];
        header('Location: ' . root . 'cruises');
        exit;
    }
    if ($booking['module_type'] !== 'cruises') {
        header('Location: ' . root . 'invoice/' . $invoiceId);
        exit;
    }

    // BOOKING EXPIRY (unpaid invoices only, admins always see them)
    $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
    if ($booking['payment_status'] !== 'paid' && !$isAdmin) {
        $expiryMinutes = $db->get('settings', 'booking_expiry_time');
        if ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0
            && (time() - strtotime($booking['created_at'])) / 60 > $expiryMinutes) {
            triggerWebhook('cruises/invoice', 'cruises.invoice.expired', [
                'invoice_id' => $invoiceId,
                'booking_id' => $booking['id'],
                'timestamp'  => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'This booking session has expired. Please make a new booking.'];
            header('Location: ' . root . 'cruises');
            exit;
        }
    }

    // PAYMENT GATEWAY CALLBACK
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';
        if (!$token) {
            $_SESSION['payment_notice'] = ['type' => 'error', 'title' => 'Payment Update', 'message' => 'Missing payment token. Please try again.'];
            header('Location: ' . root . 'invoice/cruises/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = ['gateway_data' => $_GET, 'module_type' => 'cruises', 'module' => $booking['module'] ?: 'widgety'];
        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id'] = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $_SESSION['payment_notice'] = ['type' => 'success', 'title' => 'Payment Successful',
                'message' => 'Cruise Invoice #' . $invoiceId . ' has been paid successfully.'];
        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = ['type' => 'warning', 'title' => 'Payment Cancelled',
                'message' => 'You have cancelled the payment. No charges were made.'];
        } else {
            $_SESSION['payment_notice'] = ['type' => 'error', 'title' => 'Payment Failed',
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.')];
        }
        header('Location: ' . root . 'invoice/cruises/' . $invoiceId);
        exit;
    }

    triggerWebhook('cruises/invoice', 'cruises.invoice.viewed', [
        'invoice_id'     => $invoiceId,
        'booking_id'     => $booking['id'],
        'payment_status' => $booking['payment_status'],
        'booking_status' => $booking['booking_status'],
        'timestamp'      => date('Y-m-d H:i:s'),
    ]);

    $bookingData = json_decode((string) $booking['booking_data'], true) ?: [];
    $travellersData = json_decode((string) $booking['travellers'], true) ?: [];
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    $title = 'Cruise Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = 'Cruise booking invoice for ' . $booking['first_name'] . ' ' . $booking['last_name'];
    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/invoice/index.php';
    require_once views . 'includes/footer.php';
});
