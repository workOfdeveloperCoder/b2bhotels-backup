<?php
// ============================================================================
// FILE: app/routes/api/paymentRoutes.php
// PAYMENT API (mobile app)
// ============================================================================

@$SECURE or die('Access Denied!');

/*
|--------------------------------------------------------------------------
| Helper: strip a leading field label from a stored credential value
|--------------------------------------------------------------------------
| Admins sometimes type the label into the value box, e.g.
|   c1 = "Account holder Elon Musk"  ->  "Elon Musk"
|   c2 = "Routing number  084009519" ->  "084009519"
| This normalises the value for structured API consumers (mobile app).
*/
if (!function_exists('_pg_strip_label')) {
    function _pg_strip_label($value, array $labels)
    {
        $v = trim((string) $value);
        if ($v === '') {
            return '';
        }

        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (stripos($v, $label) === 0) {
                $rest = ltrim(substr($v, strlen($label)), " \t\r\n:-–—|/");
                if ($rest !== '') {
                    return trim($rest);
                }
            }
        }

        return $v;
    }
}

/*
|--------------------------------------------------------------------------
| WIRE / BANK TRANSFER — BANK DETAILS FORM
|--------------------------------------------------------------------------
| Returns the bank details configured for the active bank_transfer gateway
| so the mobile app can render its "Wire Transfer" payment screen.
| Column mapping (payment_gateways): c1 Account Holder, c2 Routing Number,
| c3 Account Number, c4 Bank Address, c5 IBAN/SWIFT.
*/
$router->get('/api/payment/bank-transfer', function () use ($db) {

    header('Content-Type: application/json');

    try {

        $gateway = $db->get('payment_gateways', [
            'id',
            'name',
            'display_name',
            'type',
            'c1',
            'c2',
            'c3',
            'c4',
            'c5',
            'currency',
            'note'
        ], [
            'type'   => 'bank_transfer',
            'status' => 1,
            'active' => 1,
            'ORDER'  => ['default' => 'DESC', 'name' => 'ASC']
        ]);

        if (!$gateway) {
            http_response_code(404);
            echo json_encode([
                'status'  => 'error',
                'success' => false,
                'message' => 'Wire transfer is not available'
            ]);
            return;
        }

        $accountHolder = _pg_strip_label($gateway['c1'] ?? '', ['Account Holder', 'Holder']);
        $routingNumber = _pg_strip_label($gateway['c2'] ?? '', ['Routing Number', 'Routing', 'ABA', 'Sort Code']);
        $accountNumber = _pg_strip_label($gateway['c3'] ?? '', ['Account Number', 'Account No', 'Account']);
        $bankAddress   = _pg_strip_label($gateway['c4'] ?? '', ['Bank Address', 'Bank Name', 'Address', 'Bank']);
        $ibanSwift     = _pg_strip_label($gateway['c5'] ?? '', ['IBAN/SWIFT', 'IBAN / SWIFT', 'IBAN', 'SWIFT', 'BIC']);

        $instructions = trim((string) ($gateway['note'] ?? ''));
        if ($instructions === '') {
            $instructions = 'Please transfer the total amount to the bank account above and submit your payment proof.';
        }

        $logoUrl = rtrim(root, '/') . '/uploads/gateways/bank-transfer.png';

        echo json_encode([
            'status'  => 'success',
            'success' => true,
            'message' => 'Wire transfer details retrieved successfully',
            'data'    => [
                'id'             => $gateway['id'],
                'name'           => $gateway['name'],
                'display_name'   => $gateway['display_name'] ?: $gateway['name'],
                'type'           => $gateway['type'],
                'account_holder' => $accountHolder,
                'routing_number' => $routingNumber,
                'account_number' => $accountNumber,
                'bank_name'      => $bankAddress,
                'bank_address'   => $bankAddress,
                'iban'           => $ibanSwift,
                'swift'          => $ibanSwift,
                'iban_swift'     => $ibanSwift,
                'currency'       => $gateway['currency'] ?: '',
                'instructions'   => $instructions,
                'logo'           => $logoUrl
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'success' => false,
            'message' => 'Unable to load wire transfer details'
        ]);
    }
});
