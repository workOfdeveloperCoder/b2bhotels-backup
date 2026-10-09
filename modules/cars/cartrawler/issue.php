<?php
// modules/cars/cartrawler/issue.php
// CARTRAWLER CAR BOOKING ISSUE ENDPOINT
// Called by payment-gateway.php via HTTP POST after successful payment

@$SECURE or die('Access Denied!');

$router->post('cars/cartrawler/issue', function() use ($db) {

header('Content-Type: application/json');

// Clean output buffer to ensure pure JSON
while (ob_get_level()) {
    ob_end_clean();
}

try {
    $invoice_id = $_POST['invoice_id'] ?? '';

    if (empty($invoice_id)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required', 'response_error' => 'Invoice ID required']);
        exit;
    }

    // Fetch booking
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found', 'response_error' => 'Booking not found']);
        exit;
    }

    // Get module credentials
    $module = $db->get('modules', '*', ['name' => 'cartrawler', 'type' => 'cars']);
    if (!$module) {
        echo json_encode(['status' => false, 'message' => 'CarTrawler module not configured', 'response_error' => 'Module not configured']);
        exit;
    }

    $clientId   = $module['c1'] ?? '';
    $environment = ($module['dev_mode'] ?? '1') === '1' ? 'test' : 'live';
    $currency   = !empty($module['currency']) ? $module['currency'] : 'USD';

    if (empty($clientId)) {
        echo json_encode(['status' => false, 'message' => 'CarTrawler Client ID not configured', 'response_error' => 'Missing Client ID']);
        exit;
    }

    // Decode booking data
    $booking_data = json_decode($booking['booking_data'] ?? '{}', true);
    $car_data     = $booking_data['car_data'] ?? [];

    $pickup_location  = $car_data['pickup_location']  ?? '';
    $dropoff_location = $car_data['dropoff_location'] ?? $pickup_location;
    $pickup_datetime  = $car_data['pickup_datetime']  ?? '';
    $dropoff_datetime = $car_data['dropoff_datetime'] ?? '';
    $vendor_code      = $car_data['vendor_code']      ?? '';
    $reference_id     = $car_data['reference_id']     ?? '';

    if (empty($reference_id)) {
        error_log("CARTRAWLER ISSUE ERROR: Missing reference_id for Invoice " . $invoice_id);
        echo json_encode(['status' => false, 'message' => 'Reference ID missing from car booking data', 'response_error' => 'Missing reference_id']);
        exit;
    }

    // Fallback for missing datetimes from search_params
    if (empty($pickup_datetime) || empty($dropoff_datetime)) {
        $search_params = $booking_data['search_params'] ?? [];
        $p_date = $search_params['pickup_date'] ?? ''; // DD-MM-YYYY
        $p_time = $search_params['pickup_time'] ?? '10:00';
        $d_date = $search_params['return_date'] ?? ''; // DD-MM-YYYY
        $d_time = $search_params['return_time'] ?? '10:00';

        if (!empty($p_date) && !empty($d_date)) {
            $p_parts = explode('-', $p_date);
            $d_parts = explode('-', $d_date);
            if (count($p_parts) === 3 && count($d_parts) === 3) {
                if (empty($pickup_datetime)) $pickup_datetime = "{$p_parts[2]}-{$p_parts[1]}-{$p_parts[0]}T{$p_time}:00";
                if (empty($dropoff_datetime)) $dropoff_datetime = "{$d_parts[2]}-{$d_parts[1]}-{$d_parts[0]}T{$d_time}:00";
            }
        }
    }

    if (empty($pickup_datetime) || empty($dropoff_datetime)) {
        error_log("CARTRAWLER ISSUE ERROR: Missing dateTime for Invoice " . $invoice_id);
        echo json_encode(['status' => false, 'message' => 'Pickup/Dropoff Date/Time missing', 'response_error' => 'Missing dateTime']);
        exit;
    }

    $firstName = $booking['first_name'] ?? 'Guest';
    $lastName  = $booking['last_name']  ?? 'User';
    $email     = $booking['email']      ?? 'guest@example.com';
    $phone     = $booking['phone']      ?? '1234567890';
    $country   = $booking['country_code'] ?? 'US';

    $consumerIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $target     = ($environment === 'live') ? 'Production' : 'Test';

    // Build OTA_VehResRQ XML
    $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
<OTA_VehResRQ xmlns="http://www.opentravel.org/OTA/2003/05"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.opentravel.org/OTA/2003/05 OTA_VehResRQ.xsd"
    Version="1.005" Target="' . $target . '">
  <POS>
    <Source ISOCurrency="' . $currency . '">
      <RequestorID ID="' . htmlspecialchars($clientId) . '" Type="16" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <VehResRQCore>
    <VehRentalCore PickUpDateTime="' . htmlspecialchars($pickup_datetime) . '" ReturnDateTime="' . htmlspecialchars($dropoff_datetime) . '">
      <PickUpLocation LocationCode="' . htmlspecialchars($pickup_location) . '" CodeContext="IATA"/>
      <ReturnLocation LocationCode="' . htmlspecialchars($dropoff_location) . '" CodeContext="IATA"/>
    </VehRentalCore>
    <Customer>
      <Primary>
        <PersonName>
          <GivenName>' . htmlspecialchars($firstName) . '</GivenName>
          <Surname>' . htmlspecialchars($lastName) . '</Surname>
        </PersonName>
        <Telephone PhoneNumber="' . htmlspecialchars($phone) . '" PhoneTechType="1"/>
        <Email>' . htmlspecialchars($email) . '</Email>
        <CitizenCountryName Code="' . strtoupper(substr($country, 0, 2)) . '"/>
      </Primary>
    </Customer>
    <VendorPref CompanyShortName="' . htmlspecialchars($vendor_code) . '"/>
    <Reference ID="' . htmlspecialchars($reference_id) . '" Type="16"/>
  </VehResRQCore>
  <VehResRQInfo>
    <TPA_Extensions>
      <ConsumerIP>' . $consumerIP . '</ConsumerIP>
    </TPA_Extensions>
  </VehResRQInfo>
</OTA_VehResRQ>';

    // Log request for debugging
    // @file_put_contents('/tmp/cartrawler_issue_request.log', date('Y-m-d H:i:s') . " | Invoice: {$invoice_id}\n" . $xmlRequest . "\n---\n", FILE_APPEND);

    // API endpoint
    $baseUrl = ($environment === 'live')
        ? 'https://ota.cartrawler.com/cartrawlerota'
        : 'https://external-dev.cartrawler.com/cartrawlerota';

    // cURL call
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xmlRequest,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml',
            'User-Agent: PHPTravels-v10/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $apiResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    // Log response
    // @file_put_contents('/tmp/cartrawler_issue_response.log', date('Y-m-d H:i:s') . " | HTTP: {$httpCode}\n" . $apiResponse . "\n---\n", FILE_APPEND);

    if (!empty($curlError)) {
        echo json_encode([
            'status'         => false,
            'message'        => 'cURL error: ' . $curlError,
            'response_error' => $curlError
        ]);
        exit;
    }

    // Parse XML Response
    $pnr     = '';
    $success = false;
    $errMsg  = '';

    if ($httpCode === 200 && !empty($apiResponse)) {
        $xml = @simplexml_load_string($apiResponse);
        if ($xml === false) {
            $errMsg = 'Failed to parse XML response from CarTrawler.';
        } else {
            $rootName = $xml->getName();
            if ($rootName === 'OTA_ErrorRS') {
                $errMsg = (string)($xml['ErrorMessage'] ?? $xml['ShortText'] ?? 'CarTrawler returned an error.');
            } elseif (isset($xml->Errors)) {
                $errMsg = (string)($xml->Errors->Error['ShortText'] ?? $xml->Errors->Error ?? 'Supplier error.');
            } elseif (isset($xml->VehResRSCore->VehReservation)) {
                $res = $xml->VehResRSCore->VehReservation;
                $pnr = (string)($res->VehSegmentCore->ConfID['ID'] ?? '');
                if (empty($pnr)) {
                    $pnr = (string)($res->VehResRQInfo->Reference['ID'] ?? '');
                }
                if (!empty($pnr)) {
                    $success = true;
                } else {
                    $errMsg = 'Booking placed but PNR not found in response.';
                }
            } else {
                $errMsg = 'Unexpected response structure from CarTrawler.';
            }
        }
    } else {
        $errMsg = "CarTrawler API HTTP Error: {$httpCode}";
    }

    if ($success) {
        // Update booking with PNR
        $db->update('bookings', [
            'pnr'            => $pnr,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'booking_data'   => json_encode(array_merge($booking_data, [
                'cartrawler_pnr'  => $pnr,
                'issue_timestamp' => date('Y-m-d H:i:s')
            ]))
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'            => true,
            'Prn'               => $pnr,
            'pnr'               => $pnr,
            'booking_reference' => $pnr,
            'reference'         => $pnr,
            'message'           => 'Car booking confirmed with CarTrawler. PNR: ' . $pnr,
            'response_error'    => ''
        ]);
    } else {
        // Save error
        $db->update('bookings', [
            'error_response' => json_encode([
                'error'     => $errMsg,
                'response'  => mb_substr($apiResponse ?? '', 0, 500),
                'timestamp' => date('Y-m-d H:i:s')
            ])
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'         => false,
            'message'        => $errMsg,
            'response_error' => $errMsg
        ]);
    }

} catch (Exception $e) {
    error_log('CARTRAWLER ISSUE ERROR: ' . $e->getMessage());
    echo json_encode([
        'status'         => false,
        'message'        => 'Exception: ' . $e->getMessage(),
        'response_error' => $e->getMessage()
    ]);
    exit;
}

});
