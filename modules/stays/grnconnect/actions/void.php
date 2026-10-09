<?php
/**
 * GRNCONNECT BOOKING VOID HANDLER
 * =====================================
 * GRNConnect has no dedicated void operation. A not-yet-issued booking is
 * voided locally; an issued booking is cancelled through the DELETE endpoint.
 * Endpoint: POST /stays/grnconnect/void
 */

$router->post('/stays/grnconnect/void', function () use ($db) {
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

        // Not issued yet -> void locally.
        if (empty($booking['pnr'])) {
            $db->update('bookings', [
                'booking_status' => 'voided',
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
            $send(['success' => true, 'status' => true, 'message' => 'Booking voided successfully']);
        }

        // Issued -> cancel via supplier.
        $module = grnconnect_module($db);
        if (!$module) throw new Exception('GRNConnect module not configured');
        $client = GRNConnectClient::fromModule($module);

        $bookingData = json_decode($booking['booking_data'], true) ?: [];
        $reference   = $bookingData['grn_booking_reference'] ?? $booking['pnr'];

        $res    = $client->cancel($reference);
        $data   = is_array($res['data'] ?? null) ? $res['data'] : [];
        $status = strtolower($data['status'] ?? '');

        if ($res['success'] || in_array($status, ['confirmed', 'pending'], true)) {
            $db->update('bookings', [
                'booking_status'        => 'voided',
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode($data),
                'updated_at'            => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
            $send(['success' => true, 'status' => true, 'message' => 'Booking voided successfully', 'data' => $data]);
        }

        $db->update('bookings', [
            'error_response' => json_encode($data ?: ['error' => $res['error']]),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);
        throw new Exception('Void failed: ' . ($res['error'] ?: ($data['cancellation_comments'] ?? 'unknown error')));

    } catch (\Throwable $e) {
        error_log('GRNConnect Void Error: ' . $e->getMessage());
        $send(['success' => false, 'status' => false, 'message' => $e->getMessage()], 400);
    }
});
