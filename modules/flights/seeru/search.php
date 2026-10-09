<?php

// Credentials will be loaded from database in the route handler

//function to get airline name from database
function get_airline_name($pdo, $airline_code) {
    $airline_query = $pdo->query("SELECT * FROM `flights_airlines` WHERE `code` = '$airline_code'")->fetch(\PDO::FETCH_OBJ);
    return ($airline_query && isset($airline_query->name)) ? $airline_query->name : $airline_code;
}

//function to get airport name from database
function get_airport_name($pdo, $airport_code, $fallback_name) {
    $airport_query = $pdo->query("SELECT * FROM `flights_airports` WHERE `code` = '$airport_code'")->fetch(\PDO::FETCH_OBJ);
    return ($airport_query && isset($airport_query->name)) ? $airport_query->name : $fallback_name;
}


//function to get baggage information
function get_baggage($leg, $segment) {
    $baggage = "0";
    $cabin_baggage = "7";

    // Check leg level baggage
    if (isset($leg['bags']['value'])) {
        $baggage = $leg['bags']['value'];
    }
    if (isset($leg['bags']['ADT']['cabin']['desc'])) {
        $cabin_baggage = $leg['bags']['ADT']['cabin']['desc'];
    }
    if (isset($leg['bags']['ADT']['checked']['desc'])) {
        $checked_bag = $leg['bags']['ADT']['checked']['desc'];
        if (!empty($checked_bag) && $checked_bag != '0') {
            $baggage = $checked_bag;
        }
    }

    // Check segment baggage
    if (isset($segment['bags']['value'])) {
        $baggage = $segment['bags']['value'];
    }
    if (isset($segment['bags']['ADT']['cabin']['desc'])) {
        $cabin_baggage = $segment['bags']['ADT']['cabin']['desc'];
    }
    if (isset($segment['bags']['ADT']['checked']['desc'])) {
        $checked_bag = $segment['bags']['ADT']['checked']['desc'];
        if (!empty($checked_bag) && $checked_bag != '0') {
            $baggage = $checked_bag;
        }
    }

    return [$baggage, $cabin_baggage];
}

//function to format duration
function format_duration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

function split_segments_by_stops($segments, $stops) {

    if (empty($segments) || !is_array($segments)) {
        return [];
    }

    $groups = [];
    $current_group = [];
    $stop_index = 0;


    $expected_stops = is_array($stops) ? array_values($stops) : [];
    $total_stops = count($expected_stops);

    foreach ($segments as $segment) {

        if (!is_array($segment) || !isset($segment['to']['airport'])) {
            continue;
        }

        $current_group[] = $segment;
        $arrival_airport = $segment['to']['airport'];


        if ($stop_index < $total_stops && $arrival_airport === $expected_stops[$stop_index]) {

            $groups[] = $current_group;
            $current_group = [];
            $stop_index++;
        }
    }


    if (!empty($current_group)) {
        $groups[] = $current_group;
    }

    if (empty($groups) && !empty($segments)) {
        $groups[] = $segments;
    }

    return $groups;
}


//function to process segments
function process_segments($pdo, $db, $module, $segments, $leg, $flight, $totalDuration, $totalPrice, $adultPrice, $childPrice, $infantPrice, $refundable, $currency_code, $type, $layovers = []) {
    $segments_data = [];

    $currency = strtoupper($_POST['currency'] ?? 'USD');



    $total_segments = count($segments);

    if ($total_segments > 1) {
        $total_layover_minutes = 0;
        $layover_cities = [];
        for ($i = 0; $i < $total_segments - 1; $i++) {

            $lay_departure_time = date('h:i a', strtotime($segments[$i]['from']['date']));
            $lay_departure_date = date('d-m-Y', strtotime($segments[$i]['from']['date']));
            $lay_arrival_time = date('h:i a', strtotime($segments[$i]['to']['date']));
            $lay_arrival_date = date('d-m-Y', strtotime($segments[$i]['to']['date']));


            $arrivaltime = new DateTime($lay_arrival_date . ' ' . $lay_arrival_time);
            $next_departure_time = new DateTime($lay_departure_date . ' ' . $lay_departure_time);

            $interval = $arrivaltime->diff($next_departure_time);
            $total_layover_minutes += ($interval->h * 60) + $interval->i;

            $layover_cities[] = $segments[$i]['to']['airport'];
        }

        if ($total_layover_minutes > 0) {
            $hours = floor($total_layover_minutes / 60);
            $minutes = $total_layover_minutes % 60;
            $layover_durat =  sprintf("%d h %d m", $hours, $minutes);
        }
    }

    $layover_city = !empty($layover_cities) ? implode(',', $layover_cities) : '';
    $layover_duration =  isset($layover_durat) ? $layover_durat : '';


    foreach ($segments as $segment) {
        $airline_code = $segment['iata'];
        $airline_name = get_airline_name($pdo, $airline_code);

        $departure_code = $segment['from']['airport'];
        $arrival_code = $segment['to']['airport'];

        $departure_airport = get_airport_name($pdo, $departure_code, $segment['from']['airport_name']);
        $arrival_airport = get_airport_name($pdo, $arrival_code, $segment['to']['airport_name']);

        list($baggage, $cabin_baggage) = get_baggage($leg, $segment);

        $segmentDuration = format_duration($segment['duration']);
        $cabin_class = isset($segment['cabin_name']) ? strtolower($segment['cabin_name']) : 'economy';

        $departure_time = date('h:i a', strtotime($segment['from']['date']));
        $departure_date = date('d-m-Y', strtotime($segment['from']['date']));
        $arrival_time = date('h:i a', strtotime($segment['to']['date']));
        $arrival_date = date('d-m-Y', strtotime($segment['to']['date']));

        // Apply markup and conversion using helpers
        $adults = (int)$_POST['adults'];
        $childrens = (int)$_POST['childrens'];
        $infants = (int)$_POST['infants'];

        // Apply markup and conversion to group totals
        $marked_up_total = MARKUP($totalPrice, $module, $db, $currency_code, $currency);
        $converted_total = CURRENCY_CONVERT($totalPrice, $db, $currency_code, $currency);

        $marked_up_adult = MARKUP($adultPrice * $adults, $module, $db, $currency_code, $currency);
        $marked_up_child = MARKUP($childPrice * $childrens, $module, $db, $currency_code, $currency);
        $marked_up_infant = MARKUP($infantPrice * $infants, $module, $db, $currency_code, $currency);

        $converted_adult = CURRENCY_CONVERT($adultPrice * $adults, $db, $currency_code, $currency);
        $converted_child = CURRENCY_CONVERT($childPrice * $childrens, $db, $currency_code, $currency);
        $converted_infant = CURRENCY_CONVERT($infantPrice * $infants, $db, $currency_code, $currency);

        $segments_data[] = [
            'img' => $airline_code,
            'flight_no' => $segment['flightnumber'],
            'airline' => $airline_name,
            'class' => $cabin_class,
            'baggage' => $baggage . ' ',
            'cabin_baggage' => $cabin_baggage,
            'departure_airport' => $departure_airport,
            'departure_time' => $departure_time,
            'departure_date' => $departure_date,
            'departure_code' => $departure_code,
            'arrival_airport' => $arrival_airport,
            'arrival_date' => $arrival_date,
            'arrival_time' => $arrival_time,
            'arrival_code' => $arrival_code,
            'duration_time' => $segmentDuration,
            'total_duration' => $totalDuration,
            'currency' => $currency,
            'actual_price' => number_format($converted_total['price'], 2, '.', ''),
            'price' => number_format($marked_up_total['price'], 2, '.', ''),
            'adult_price' => number_format($marked_up_adult['price'], 2, '.', ''),
            'child_price' => number_format($marked_up_child['price'], 2, '.', ''),
            'infant_price' => number_format($marked_up_infant['price'], 2, '.', ''),
            'actual_adult_price' => number_format($converted_adult['price'], 2, '.', ''),
            'actual_child_price' => number_format($converted_child['price'], 2, '.', ''),
            'actual_infant_price' => number_format($converted_infant['price'], 2, '.', ''),
            'options' => 'packages data in array',
            'booking_data' => [
                'flight' => $flight,
                'currency' => $currency,
                'amount' => $marked_up_total['price'],
                'actual_amount' => $converted_total['price']
            ],
            'redirect_url' => '',
            'refundable' => $refundable,
            'supplier' => 'seeru',
            'type' => $type,
            'layover' => ['city'=>$layover_city, 'duration'=>$layover_duration],
        ];
    }

    return $segments_data;
}


function fetch_process_flights($pdo, $db, $module, $end_point, $c1, $data)

{
    // Create logs directory if it doesn't exist
    $logDir = __DIR__ . "/logs";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $all_flights = [];
    $last_result = null;
    $complete = 0;
    $iteration = 0;
    $max_iterations = 50;
    $has_shown_results = false;
    $loopStart = microtime(true);
    $maxWaitSeconds = 25;


    while ($complete != 100 && $iteration < $max_iterations && (microtime(true) - $loopStart) < $maxWaitSeconds) {
        if (connection_aborted()) {
            error_log('search_guard: request aborted during polling');
            exit;
        }
        $iteration++;


        $url = $end_point . 'flights/result/' . $data['search_id'];
        if ($last_result !== null) {
            $url .= '?after=' . urlencode($last_result);
        }


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $c1,
            'User-Agent: insomnia/11.1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        // Log response
        // file_put_contents("logs/_SEARCH_API_Result_RESPONSE.log",
        //     date('Y-m-d H:i:s') . " - Iteration $iteration\n" . print_r($response, true));


        if ($curlError || $httpCode !== 200) {
            if (!$has_shown_results && $iteration < $max_iterations) {
                if (connection_aborted()) {
                    error_log('search_guard: request aborted before retry sleep');
                    exit;
                }
                sleep(2);
                continue;
            }
            break;
        }

        // Decode response
        $batch_data = json_decode($response, true);
        if (!$batch_data) {
            if (!$has_shown_results && $iteration < $max_iterations) {
                if (connection_aborted()) {
                    error_log('search_guard: request aborted before retry sleep');
                    exit;
                }
                sleep(2);
                continue;
            }
            break;
        }


        $complete = $batch_data['complete'] ?? 0;
        $last_result = $batch_data['last_result'] ?? null;


        if (isset($batch_data['result']) && is_array($batch_data['result']) && count($batch_data['result']) > 0) {

            foreach ($batch_data['result'] as $flight) {
                $trip_id = $flight['trip_id'] ?? uniqid();
                $all_flights[$trip_id] = $flight;
            }


            if (!$has_shown_results) {
                $has_shown_results = true;


                $final_array = [];
                $target_currency = strtoupper($_POST['currency']);
                $type = $_POST['type'] ?? 'oneway';


                foreach ($all_flights as $flight) {
                    if (connection_aborted()) {
                        error_log('search_guard: request aborted in response normalization');
                        exit;
                    }
                    if (!empty($flight['instant_issue']) && $flight['instant_issue'] === true) {
                    $main_leg = $flight['legs'][0];
                    $total_price = $flight['price'];
                    $adult_price_raw = $flight['price_breakdowns']['ADT']['base'] ?? ($flight['price_breakdowns']['ADT']['total'] / max(1, $adults));
                    $child_price_raw = $flight['price_breakdowns']['CHD']['base'] ?? (($flight['price_breakdowns']['CHD']['total'] ?? 0) / max(1, $childrens));
                    $infant_price_raw = $flight['price_breakdowns']['INF']['base'] ?? (($flight['price_breakdowns']['INF']['total'] ?? 0) / max(1, $infants));
                    $source_currency = $flight['currency'] ?? 'USD';


                    $refundable = 0;
                    if (isset($flight['refundable_info'])) {
                        $refundable_info = strtolower($flight['refundable_info']);
                        if (strpos($refundable_info, 'refundable') !== false && strpos($refundable_info, 'non') === false) {
                            $refundable = 1;
                        }
                    }

                    $total_duration = format_duration($main_leg['duration']);
                    $flight_type = $_POST['type'] ?? 'oneway';

                    if ($flight_type == 'multiple') {
                        if (count($flight['legs']) != 1) {
                            continue;
                        }

                        $multiple_segments = [];
                        foreach ($flight['legs'] as $leg) {
                            $segments = $leg['segments'];
                            $stops = $leg['stops'] ?? [];
                            $split_groups = split_segments_by_stops($segments, $stops);

                            foreach ($split_groups as $group) {
                                $processed = process_segments(
                                    $pdo, $db, $module, $group, $leg, $flight,
                                    format_duration($leg['duration']),
                                    $total_price, $adult_price_raw, $child_price_raw, $infant_price_raw,
                                    $refundable, $source_currency, 'multiple'
                                );
                                $multiple_segments[] = $processed;
                            }


                        }
                        $final_array[] = ['segments' => $multiple_segments];

                    } else {
                        $oneway_segments = process_segments($pdo, $db, $module, $main_leg['segments'], $main_leg, $flight, $total_duration, $total_price, $adult_price_raw, $child_price_raw, $infant_price_raw, $refundable, $source_currency, $flight_type);

                        if (($type == "return" || $type == 'round') && isset($flight['legs'][1])) {
                            $return_leg = $flight['legs'][1];
                            $return_segments = process_segments($pdo, $db, $module, $return_leg['segments'], $return_leg, $flight, $total_duration, $total_price, $adult_price_raw, $child_price_raw, $infant_price_raw, $refundable, $source_currency, 'return');
                            $final_array[] = ['segments' => [$oneway_segments, $return_segments]];
                        } else {
                            $final_array[] = ['segments' => [$oneway_segments]];
                        }
                    }

                }

                }

                if (!empty($final_array)) {
                    header('Access-Control-Allow-Origin: *');
                    header('Content-Type: application/json');
                    echo json_encode($final_array);
                    @file_put_contents(__DIR__ . "/logs/_INITIAL_RESULTS_SHOWN.log",
                        date('Y-m-d H:i:s') . " - Showed initial results. Complete: $complete, Flights: " . count($all_flights));
                }
            }
        } else {

            if (!$has_shown_results && $iteration < $max_iterations) {
                if (connection_aborted()) {
                    error_log('search_guard: request aborted before retry sleep');
                    exit;
                }
                sleep(2);
                continue;
            }
        }


        if ($last_result === null) {
            break;
        }

        if (connection_aborted()) {
            error_log('search_guard: request aborted before poll wait');
            exit;
        }
        usleep(100000);
    }


    if (!$has_shown_results) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode([]);
        @file_put_contents(__DIR__ . "/logs/_API_ERROR.log",
            date('Y-m-d H:i:s') . "\nNo results found after $iteration iterations");
    } else {
        @file_put_contents(__DIR__ . "/logs/_FINAL_STATUS.log",
            date('Y-m-d H:i:s') .
            "\nFinal Complete: $complete, Total Iterations: $iteration, Total Flights: " . count($all_flights));
    }
}

$router->post('flights/seeru/search', function() use ($db) {
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
    global $pdo;

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

    // Set headers
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'seeru',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Seeru module not configured',
            'response' => []
        ]);
        exit;
    }

    // Get credentials from module configuration
    $c1 = $module['c2'] ?? ''; // Refresh token (JWT)
    $api_key = $module['c1'] ?? ''; // API Key
    $environment = $module['c3'] ?? 'test'; // Environment (test/production)

    // Validate credentials
    if (empty($c1)) {
        echo json_encode([
            'status' => false,
            'message' => 'Seeru refresh token not configured. Please add credentials in module settings.',
            'response' => []
        ]);
        exit;
    }

    // Determine endpoint based on environment
    if ($environment === 'production' || $environment === 'live') {
        $end_point = 'https://live-api.seeru.travel/v1/';
    } else {
        $end_point = 'https://sandbox-api.seeru.travel/v1/';
    }


    // Create logs directory if it doesn't exist
    $logDir = __DIR__ . "/logs";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    // Log request
    // file_put_contents(__DIR__ . "/logs/_REQ.log", date('Y-m-d H:i:s') . "\n" . print_r($_REQUEST, true));
    if (isset($_POST['origin']) && trim($_POST['origin']) !== "") {
    } else {
        echo "origin : LHE - param or value missing ";
        die;
    }
    if (isset($_POST['destination']) && trim($_POST['destination']) !== "") {
    } else {
        echo "destination : DXB - param or value missing ";
        die;
    }
    if (isset($_POST['departure_date']) && trim($_POST['departure_date']) !== "") {
    } else {
        echo "departure_date : 10-10-2021 - param or value missing ";
        die;
    }
    if (isset($_POST['childrens']) && trim($_POST['childrens']) !== "") {
    } else {
        echo "childrens : 1 - param or value missing ";
        die;
    }
    if (isset($_POST['adults']) && trim($_POST['adults']) !== "") {
    } else {
        echo "adults : 1 - param or value missing ";
        die;
    }
    if (isset($_POST['infants']) && trim($_POST['infants']) !== "") {
    } else {
        echo "infants : 0 - param or value missing ";
        die;
    }
    if (isset($_POST['type']) && trim($_POST['type']) !== "") {
    } else {
        echo "type : oneway - param or value missing ";
        die;
    }
    if (isset($_POST['class']) && trim($_POST['class']) !== "") {
    } else {
        echo "class : economy - param or value missing ";
        die;
    }
    if (isset($_POST['currency']) && trim($_POST['currency']) !== "") {
    } else {
        echo "currency : USD - param or value missing ";
        die;
    }
    // Currency from request or default to USD
    $currency = strtoupper($_POST['currency'] ?? 'USD');

    // Convert input to uppercase
    $origin = strtoupper($_POST['origin']);
    $destination = strtoupper($_POST['destination']);

    $departureDate = strtoupper(date('Y-m-d', strtotime($_POST['departure_date'])));
    if (isset($_POST['return_date'])){
        $returnDate = strtoupper(date('Y-m-d', strtotime($_POST['return_date'])));
    }
    $type = $_POST['type'];
    $class = strtoupper($_POST['class']);
    $adults = (int)$_POST['adults'];
    $childrens = (int)$_POST['childrens'];
    $infants = (int)$_POST['infants'];

    switch ($class) {
        case "economy":
            $classType = "e";
            break;
        case "business":
            $classType = "b";
            break;
        case "first":
        case "premium_economy":
            $classType = "f";
            break;
        default:
            $classType = "e";
    }

    /*flight route oneway*/
    if($type == "oneway" ){
        $url = $end_point."flights/search/".$origin.'-'.$destination.'-'.str_replace("-", "", $departureDate).'/'.$adults.'/'.$childrens.'/'.$infants.'/?cabin='.$classType;
    }
    /*end flight route oneway*/

    /*flight route round*/
    if($type == "return" || $type == 'round') {
        if (empty($returnDate)) {
            echo "return_date : 10-10-2021 - param or value missing ";
            die;
        }
        $url = $end_point."flights/search/".$origin.'-'.$destination.'-'.str_replace("-", "", $departureDate).':'.$destination.'-'.$origin.'-'.str_replace("-", "", $returnDate).'/'.$adults.'/'.$childrens.'/'.$infants.'/?cabin='.$classType;
    }
    /*end flight route round*/


    /* Flight route multiple */
    if ($type == 'multiple') {
        $routes = json_decode($_POST['routes']);

        // Check if more than 4 routes
        if (count($routes) > 4) {
            echo json_encode([
                'status' => false,
                'message' => 'You can only select up to 4 flight segments.'
            ]);
            die;
        }
        $multiroute = [];
        foreach ($routes as $key => $value) {
            $from = strtoupper($value->from);
            $to = strtoupper($value->to);
            $date = strtoupper(date('Ymd', strtotime($value->date)));
            $multiroute[] = $from . '-' . $to . '-' . $date;
        }

        $multi_route = implode(":", $multiroute);

        $url = $end_point."flights/search/".$multi_route.'/'.$adults.'/'.$childrens.'/'.$infants.'/?cabin='.$classType;
    }
    /* End Flight route multiple */


    // Log ID Search request
    // file_put_contents("logs/_SEARCH_API_ID_REQUEST.log", date('Y-m-d H:i:s') . "\n" . print_r($url, true));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Authorization: Bearer ' .$c1, 'User-Agent: insomnia/11.1.0']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable compression
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    // Log ID Search Response
    // file_put_contents("logs/_SEARCH_API_ID_RESPONSE.log", date('Y-m-d H:i:s') . "\n" . print_r($response, true));
    $data = json_decode($response, true);

    if ($httpCode != 200 || $curlError || !isset($data['search_id'])) {
        // Log error
        @file_put_contents(__DIR__ . "/logs/_SEARCH_API_ID_ERROR.log", date('Y-m-d H:i:s') . "\n" . print_r($curlError, true));
        echo "Error: " . ($curlError ?: 'Invalid response from API');
        die;
    }
// =========================================================== TOKEN GENERATED

    // Log Result Search request
    // file_put_contents("logs/_SEARCH_API_Result_REQUEST.log", date('Y-m-d H:i:s') . "\n" . print_r( $end_point.'flights/result/'.$data['search_id'], true));

    // Execute the function
    fetch_process_flights($pdo, $db, $module, $end_point, $c1, $data);


});
