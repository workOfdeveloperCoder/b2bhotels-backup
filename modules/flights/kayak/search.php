<?php

function getKayakDuration($minutes)
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf("%d:%02d", $hours, $mins);
}

$router->post('flights/kayak/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }
    @set_time_limit(30);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'kayak',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Kayak module not configured',
            'response' => []
        ]);
        exit;
    }

    // Get API credentials from module config
    $api_key = $module['c1'] ?? '';
    $env = $module['env'] ?? 'sandbox';

    if (empty($api_key)) {
        echo json_encode([
            'status' => false,
            'message' => 'Kayak API key not configured',
            'response' => []
        ]);
        exit;
    }

    // Set endpoint based on environment
    $api_endpoint = ($env === 'production' || $env === 'live')
        ? 'https://en-us.kayakaffiliates.com'
        : 'https://sandbox-en-us.kayakaffiliates.com';

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    try {
        // Validate required parameters
        $requiredParams = [
            'origin' => 'Origin airport code (e.g., LHR)',
            'destination' => 'Destination airport code (e.g., DXB)',
            'departure_date' => 'Departure date (DD-MM-YYYY)',
            'adults' => 'Number of adults',
            'childrens' => 'Number of children',
            'infants' => 'Number of infants',
            'currency' => 'Currency code (e.g., USD)',
            'type' => 'Trip type (oneway/return)',
            'class' => 'Cabin class (economy/business/first)'
        ];

        foreach ($requiredParams as $param => $description) {
            if (!isset($_POST[$param]) || trim($_POST[$param]) === '') {
                echo json_encode([
                    'status' => false,
                    'message' => "$param is required - $description",
                    'response' => []
                ]);
                exit;
            }
        }

        $type = $_POST['type'];
        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $returnDate = $type === 'return' ? date('Y-m-d', strtotime($_POST['return_date'])) : null;
        $destination = strtoupper($_POST['destination']);
        $origin = strtoupper($_POST['origin']);
        $cabinClass = strtolower($_POST['class']); // economy, business, first

        // Build passengers array
        $passengers = [];
        for ($i = 0; $i < $_POST['adults']; $i++) {
            $passengers[] = "ADT";
        }
        for ($i = 0; $i < $_POST['childrens']; $i++) {
            $passengers[] = "CHD";
        }
        for ($i = 0; $i < $_POST['infants']; $i++) {
            $passengers[] = "INF";
        }

        // Build search request payload
        $legs = [
            [
                "origin" => [
                    "locationType" => "airports",
                    "airports" => [$origin]
                ],
                "destination" => [
                    "locationType" => "airports",
                    "airports" => [$destination]
                ],
                "date" => $departureDate,
                "flex" => "exact"
            ]
        ];

        // Add return leg if round trip
        if ($type === 'return' && $returnDate) {
            $legs[] = [
                "origin" => [
                    "locationType" => "airports",
                    "airports" => [$destination]
                ],
                "destination" => [
                    "locationType" => "airports",
                    "airports" => [$origin]
                ],
                "date" => $returnDate,
                "flex" => "exact"
            ];
        }

        $searchPayload = [
            "searchStartParameters" => [
                "cabin" => $cabinClass,
                "passengers" => $passengers,
                "legs" => $legs
            ]
        ];

        // Generate unique userTrackId
        $userTrackId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // Start search
        $searchUrl = $api_endpoint . '/i/api/affiliate/search/flight/v1/poll?apiKey=' . urlencode($api_key) . '&userTrackId=' . urlencode($userTrackId);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $searchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($searchPayload),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: kayakaffiliateapp',
                'x-original-client-ip: ' . ($_SERVER['REMOTE_ADDR'] ?? '8.8.8.8')
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            echo json_encode([
                'status' => false,
                'message' => 'API connection error: ' . $error,
                'response' => []
            ]);
            exit;
        }

        $searchResponse = json_decode($result, true);

        if ($httpCode !== 200 || !isset($searchResponse['searchId'])) {
            echo json_encode([
                'status' => false,
                'message' => 'Search initiation failed',
                'response' => [],
                'debug' => $searchResponse
            ]);
            exit;
        }

        $searchId = $searchResponse['searchId'];
        $cluster = $searchResponse['cluster'] ?? '';

        // Poll for results until complete (max 10 attempts)
        $maxAttempts = 5;
        $attempt = 0;
        $pollStart = microtime(true);
        $maxWait = 25;
        $pollPayload = json_encode(["searchId" => $searchId]);

        do {
            $attempt++;
            if (connection_aborted()) {
                error_log('search_guard: request aborted during polling');
                exit;
            }
            usleep(500000); // Wait 500ms between polls

            $pollUrl = $api_endpoint . '/i/api/affiliate/search/flight/v1/poll?apiKey=' . urlencode($api_key) . '&userTrackId=' . urlencode($userTrackId);
            if (!empty($cluster)) {
                $pollUrl .= '&cluster=' . urlencode($cluster);
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
            curl_setopt_array($ch, [
                CURLOPT_URL => $pollUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $pollPayload,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: kayakaffiliateapp',
                    'x-original-client-ip: ' . ($_SERVER['REMOTE_ADDR'] ?? '8.8.8.8')
                ],
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $result = curl_exec($ch);
            $pollResponse = json_decode($result, true);

            $status = $pollResponse['status'] ?? 'searching';

        } while ($status !== 'complete' && $attempt < $maxAttempts && (microtime(true) - $pollStart) < $maxWait);

        if (!isset($pollResponse['results']) || empty($pollResponse['results'])) {
            echo json_encode([]);
            exit;
        }

        // Transform Kayak results to standard format
        $final_array = [];
        $kayakCurrency = $pollResponse['currency'] ?? 'USD';

        foreach ($pollResponse['results'] as $result) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }
            // Get the cheapest booking option
            $bookingOptions = $result['bookingOptions'] ?? [];
            if (empty($bookingOptions)) continue;

            // Sort by price to get cheapest
            usort($bookingOptions, function($a, $b) {
                return ($a['displayPrice']['price'] ?? 999999) - ($b['displayPrice']['price'] ?? 999999);
            });

            $cheapestOption = $bookingOptions[0];
            $basePrice = (float)($cheapestOption['displayPrice']['price'] ?? 0);

            // Apply currency conversion and markup
            $converted_price = CURRENCY_CONVERT($basePrice, $db, $kayakCurrency, $currency);
            $marked_up_price = MARKUP($basePrice, $module, $db, $kayakCurrency, $currency);

            $return_array = ["segments" => []];

            // Process each leg
            foreach ($result['legs'] as $legRef) {
                $legId = $legRef['id'];
                $leg = $pollResponse['legs'][$legId] ?? null;
                if (!$leg) continue;

                $sub_array = [];

                // Process each segment in the leg
                foreach ($leg['segments'] as $segmentRef) {
                    $segmentId = $segmentRef['id'];
                    $segment = $pollResponse['segments'][$segmentId] ?? null;
                    if (!$segment) continue;

                    $airlineCode = $segment['airline'];
                    $airline = $pollResponse['airlines'][$airlineCode] ?? null;
                    $airlineName = $airline['displayName'] ?? $airlineCode;

                    $originCode = $segment['origin'];
                    $destCode = $segment['destination'];
                    $originAirport = $pollResponse['airports'][$originCode] ?? null;
                    $destAirport = $pollResponse['airports'][$destCode] ?? null;

                    // Get cabin class from segment
                    $cabinClass = $segment['cabin']['displayName'] ?? 'Economy';

                    // Get baggage from cheapest option fees
                    $baggage = 'Not Included';
                    $cabin_baggage = 'Not Included';

                    if (isset($cheapestOption['fees']['checkedBag']) && !empty($cheapestOption['fees']['checkedBag'])) {
                        $baggage = ucfirst($cheapestOption['fees']['checkedBag'][0]['restriction'] ?? 'Not Included');

                        // If there are multiple bags, we can indicate that, but user wants exact API response
                        $count = count($cheapestOption['fees']['checkedBag']);
                        if ($count > 1) { $baggage = $count . ' Bags (' . $baggage . ')'; }
                    }

                    if (isset($cheapestOption['fees']['carryOnBag']) && !empty($cheapestOption['fees']['carryOnBag'])) {
                        $cabin_baggage = ucfirst($cheapestOption['fees']['carryOnBag'][0]['restriction'] ?? 'Not Included');

                        $count = count($cheapestOption['fees']['carryOnBag']);
                        if ($count > 1) { $cabin_baggage = $count . ' Bags (' . $cabin_baggage . ')'; }
                    }

                    $sub_array[] = (object)[
                        'img' => $airlineCode,
                        'flight_no' => $segment['flightNumber'],
                        'airline' => $airlineName,
                        'class' => $cabinClass,
                        'baggage' => $baggage,
                        'cabin_baggage' => $cabin_baggage,
                        'departure_airport' => $originAirport['displayName'] ?? $originCode,
                        'departure_time' => date("h:i a", strtotime($segment['departureTime'])),
                        'arrival_airport' => $destAirport['displayName'] ?? $destCode,
                        'arrival_time' => date("h:i a", strtotime($segment['arrivalTime'])),
                        'departure_date' => date("d-m-Y", strtotime($segment['departureTime'])),
                        'arrival_date' => date("d-m-Y", strtotime($segment['arrivalTime'])),
                        'departure_code' => $originCode,
                        'arrival_code' => $destCode,
                        'currency' => $currency,
                        'price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'actual_price' => number_format($converted_price['price'], 2, '.', ''),
                        'duration_time' => getKayakDuration($segment['duration']),
                        'total_duration' => getKayakDuration($leg['duration']),
                        'adult_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'child_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'infant_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'actual_adult_price' => number_format($converted_price['price'], 2, '.', ''),
                        'actual_child_price' => number_format($converted_price['price'], 2, '.', ''),
                        'actual_infant_price' => number_format($converted_price['price'], 2, '.', ''),
                        'options' => '',
                        'booking_data' => [
                            'result_id' => $result['id'],
                            'search_id' => $searchId,
                            'cluster' => $cluster,
                            'provider_code' => $cheapestOption['providerCode'],
                            'booking_token' => $result['id'],
                            'currency' => $currency,
                            'amount' => $marked_up_price['price'],
                            'actual_amount' => $converted_price['price']
                        ],
                        'redirect_url' => $cheapestOption['bookingUrl'] ?? '',
                        'refundable' => '',
                        'supplier' => 'kayak',
                        'type' => $type,
                    ];
                }

                if (!empty($sub_array)) {
                    $return_array["segments"][] = $sub_array;
                }
            }

            if (!empty($return_array["segments"])) {
                $final_array[] = $return_array;
            }
        }

        echo json_encode($final_array);

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'An error occurred: ' . $e->getMessage(),
            'response' => []
        ]);
    }
});