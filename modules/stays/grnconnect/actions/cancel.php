<?php
/**
 * GRNCONNECT BOOKING CANCELLATION HANDLER
 * =====================================
 * Cancels a confirmed booking via DELETE api/v3/hotels/bookings/{reference}
 */

$router->post('/stays/grnconnect/cancel', function () use ($db) {
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

        if (!$db) throw new Exception('Database connection not available');

        $input     = json_decode(file_get_contents('php://input'), true) ?: [];
        $invoiceId = $input['invoice_id'] ?? $_POST['invoice_id'] ?? '';
        if (empty($invoiceId)) throw new Exception('Missing invoice_id parameter');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'grnconnect']);
        if (!$booking) throw new Exception('Booking not found');
        if (empty($booking['pnr'])) throw new Exception('Booking has not been issued yet. Cannot cancel.');
        if (in_array($booking['booking_status'], ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already cancelled');
        }

        $module = grnconnect_module($db);
        if (!$module) throw new Exception('GRNConnect module not configured');
        $client = GRNConnectClient::fromModule($module);

        $bookingData = json_decode($booking['booking_data'], true) ?: [];
        $reference   = $bookingData['grn_booking_reference'] ?? $booking['pnr'];

        $res = $client->cancel($reference);

        $data   = is_array($res['data'] ?? null) ? $res['data'] : [];
        $status = strtolower($data['status'] ?? '');

        if (!$res['success'] && $status === '') {
            $db->update('bookings', [
                'error_response' => json_encode($data ?: ['error' => $res['error']]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
            throw new Exception('Cancellation failed: ' . ($res['error'] ?: 'unknown error'));
        }

        if (in_array($status, ['confirmed', 'pending', 'cancelled'], true) || $res['success']) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode($data),
                'updated_at'            => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            $send([
                'status'  => true,
                'success' => true,
                'message' => $status === 'pending'
                    ? 'Cancellation requested. Awaiting supplier confirmation.'
                    : 'Booking cancelled successfully',
                'data'    => [
                    'booking_id'             => $booking['id'],
                    'invoice_id'             => $invoiceId,
                    'booking_reference'      => $reference,
                    'cancellation_reference' => $data['cancellation_reference'] ?? '',
                    'cancellation_charges'   => $data['cancellation_charges'] ?? null,
                    'status'                 => $status ?: 'cancelled',
                ],
            ]);
        }

        $db->update('bookings', [
            'error_response' => json_encode($data),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);
        throw new Exception('Cancellation ' . ($status ?: 'failed') . '. ' . ($data['cancellation_comments'] ?? ''));

    } catch (\Throwable $e) {
        if (isset($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode(['error' => 'cancellation_exception', 'message' => $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }
        error_log('GRNConnect Cancel Error: ' . $e->getMessage() . ' (Invoice: ' . ($invoiceId ?? 'N/A') . ')');
        $send(['status' => false, 'success' => false, 'error' => 'cancellation_error', 'message' => $e->getMessage()], 400);
    }
});
