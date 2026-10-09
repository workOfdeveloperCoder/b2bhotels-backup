<?php
/**
 * GRNCONNECT BOOKING REFUND HANDLER
 * =====================================
 * GRNConnect refunds are handled through the cancellation endpoint, which
 * returns any applicable cancellation_charges. Refund = booking price - charges.
 * Endpoint: POST /stays/grnconnect/refund
 */

$router->post('/stays/grnconnect/refund', function () use ($db) {
    if (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $send = function ($data, $code = 200) {
        if (ob_get_level()) ob_clean();
        http_response_code($code);
        echo json_encode($data);
        exit;
    };

    try {
        require_once __DIR__ . '/../api.php';

        $input     = json_decode(file_get_contents('php://input'), true) ?: [];
        $invoiceId = $input['invoice_id'] ?? $_POST['invoice_id'] ?? '';
        if (empty($invoiceId)) throw new Exception('Missing invoice_id');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'grnconnect']);
        if (!$booking) throw new Exception('Booking not found');
        if (empty($booking['pnr'])) throw new Exception('Booking has not been issued yet. Cannot refund.');

        $module = grnconnect_module($db);
        if (!$module) throw new Exception('GRNConnect module not configured');
        $client = GRNConnectClient::fromModule($module);

        $bookingData = json_decode($booking['booking_data'], true) ?: [];
        $reference   = $bookingData['grn_booking_reference'] ?? $booking['pnr'];

        $res    = $client->cancel($reference);
        $data   = is_array($res['data'] ?? null) ? $res['data'] : [];
        $status = strtolower($data['status'] ?? '');

        if (!$res['success'] && !in_array($status, ['confirmed', 'pending'], true)) {
            $db->update('bookings', [
                'error_response' => json_encode($data ?: ['error' => $res['error']]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
            throw new Exception('Refund failed: ' . ($res['error'] ?: ($data['cancellation_comments'] ?? 'unknown error')));
        }

        $originalAmount = (float)($booking['price_markup'] ?? $booking['price_original'] ?? 0);
        $penaltyAmount  = (float)($data['cancellation_charges']['amount'] ?? 0);
        $refundAmount   = max(0, round($originalAmount - $penaltyAmount, 2));

        $db->update('bookings', [
            'booking_status'        => 'refunded',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode([
                'refund_data'      => $data,
                'original_amount'  => $originalAmount,
                'penalty_amount'   => $penaltyAmount,
                'refund_amount'    => $refundAmount,
            ]),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        $send([
            'success' => true,
            'status'  => true,
            'message' => 'Booking refunded successfully',
            'data'    => [
                'booking_reference'      => $reference,
                'cancellation_reference' => $data['cancellation_reference'] ?? '',
                'original_amount'        => $originalAmount,
                'penalty_amount'         => $penaltyAmount,
                'refund_amount'          => $refundAmount,
            ],
        ]);

    } catch (\Throwable $e) {
        error_log('GRNConnect Refund Error: ' . $e->getMessage());
        $send(['success' => false, 'status' => false, 'message' => $e->getMessage()], 400);
    }
});
