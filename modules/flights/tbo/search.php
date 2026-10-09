<?php

$router->post('flights/tbo/search', function() use ($db) {
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

    $module = ($db->get("modules", "*",["name" => "tbo", "type" => "flights"]));

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'TBO module not configured',
            'response' => []
        ]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');


    try {

        REQUIRED(['origin', 'destination', 'departure_date', 'adults', 'childrens', 'infants', 'currency', 'type', 'class']);

        $type = $_POST['type'];
        $class_trip = '';

        if(strtolower($_POST['class']) == "economy"){$class_trip='M';}
        if(strtolower($_POST['class']) == "economy premium"){$class_trip='W';}
        if(strtolower($_POST['class']) == "business"){$class_trip='C';}
        if(strtolower($_POST['class']) == "first class"){$class_trip='F';}

        /*flight date & time*/
        $time1 = strtotime($_POST['departure_date']);
        $departureDate = date('d/m/Y',$time1);

        if($type != 'oneway'){
            $time2 = strtotime($_POST['return_date']);
            $returnDate = date('d/m/Y',$time2);
        }

        $destination = $_POST['destination'];
        $origin = $_POST['origin'];
        /*end flight date & time*/

        if($type == 'oneway'){
            $url = "https://api.tequila.kiwi.com/v2/search?fly_from=".strtoupper($origin)."&fly_to=".strtoupper($destination)."&date_from=".$departureDate."&date_to=".$departureDate."&adults={$_POST['adults']}&children={$_POST['childrens']}&infants={$_POST['infants']}&curr=".strtoupper($_POST['currency'])."&selected_cabins={$class_trip}";
        } else {
            $url = "https://api.tequila.kiwi.com/v2/search?fly_from=".strtoupper($origin)."&fly_to=".strtoupper($destination)."&date_from=".$departureDate."&date_to=".$departureDate."&return_from=".$returnDate."&return_to=".$returnDate."&adults={$_POST['adults']}&children={$_POST['childrens']}&infants={$_POST['infants']}&curr=".strtoupper($_POST['currency'])."&selected_cabins={$class_trip}";
        }

        // file_put_contents("logs/_API_REQUEST.log", date('Y-m-d H:i:s') . "\n" . print_r($url, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("apikey:{$module['c1']}",'content-Type: application/json'));

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);

        if (connection_aborted()) {
            error_log('search_guard: request aborted before supplier call');
            exit;
        }
        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $errorMsg = 'Error:' . curl_error($ch);
            // file_put_contents("logs/_Api_Error.log", date('Y-m-d H:i:s') . " - " . $errorMsg);
            echo $errorMsg;
        }

        $decode = json_decode($result, true);

        // Log response
        // file_put_contents("logs/_Api_Response.log", date('Y-m-d H:i:s') . "\n" . print_r(json_encode($decode), true));

        if(empty($decode['data'])){
            // file_put_contents("logs/_Api_Error.log", date('Y-m-d H:i:s') . " - No data in response\n");
            echo json_encode([]);
            return;
        } else {
            $data = $decode['data'];
        }

        $final_array = [];
        foreach ($data as $value) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }
            $booking_token = $value['booking_token'];
            $return_array = [];

            // Group segments by return flag (0 for departure, 1 for return)
            $departure_segments = [];
            $return_segments = [];

            foreach ($value['route'] as $segment) {
                if($segment['return'] == 0) {
                    $departure_segments[] = $segment;
                } else {
                    $return_segments[] = $segment;
                }
            }

            // Process departure segments
            if(!empty($departure_segments)) {
                $sub_array = array();
                $total = [];

                foreach($departure_segments as $duration){
                    $total[] = \timetodate($duration['utc_departure'], $duration['utc_arrival']);
                }

                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($departure_segments as $key) {
                    $class = '';
                    if($key['fare_category'] == 'M'){$class="economy";}
                    if($key['fare_category'] == 'W'){$class="economy premium";}
                    if($key['fare_category'] == 'C'){$class="business";}
                    if($key['fare_category'] == 'F'){$class="first class";}

                    $flightCurrency = $decode['currency'];

                    // Apply markup and conversion using helpers
                    $marked_up_total = MARKUP($value['price'], $module, $db, $flightCurrency, $currency);
                    $converted_total = CURRENCY_CONVERT($value['price'], $db, $flightCurrency, $currency);

                    $marked_up_adult = MARKUP($value['fare']['adults'], $module, $db, $flightCurrency, $currency);
                    $marked_up_child = MARKUP($value['fare']['children'], $module, $db, $flightCurrency, $currency);
                    $marked_up_infant = MARKUP($value['fare']['infants'], $module, $db, $flightCurrency, $currency);

                    $converted_adult = CURRENCY_CONVERT($value['fare']['adults'], $db, $flightCurrency, $currency);
                    $converted_child = CURRENCY_CONVERT($value['fare']['children'], $db, $flightCurrency, $currency);
                    $converted_infant = CURRENCY_CONVERT($value['fare']['infants'], $db, $flightCurrency, $currency);

                    $sub_array[] = (object)[
                        'img' => $key['airline'],
                        'flight_no' => $key['flight_no'],
                        'airline' => $key['airline'],
                        'class' => $class,
                        'baggage' => (isset($value['baglimit']['hold_weight'])) ? $value['baglimit']['hold_weight'] . " KG" : "0 PC",
                        'cabin_baggage' => (isset($value['baglimit']['hand_weight'])) ? $value['baglimit']['hand_weight'] . " KG" : "0 PC",
                        'departure_airport' => $key['cityFrom'],
                        'departure_time' => date("h:i a", strtotime($key['utc_departure'])),
                        'arrival_airport' => $key['cityTo'],
                        'arrival_time' => date("h:i a", strtotime($key['utc_arrival'])),
                        'departure_date' => date("d-m-Y", strtotime($key['utc_departure'])),
                        'arrival_date' => date("d-m-Y", strtotime($key['utc_arrival'])),
                        'departure_code' => $key['cityCodeFrom'],
                        'arrival_code' => $key['cityCodeTo'],
                        'currency' => $currency,
                        'price' => number_format((float)$marked_up_total['price'], 2, '.', ''),
                        'actual_price' => number_format((float)$converted_total['price'], 2, '.', ''),
                        'duration_time' => \timetodate($key['utc_departure'], $key['utc_arrival']),
                        'total_duration' => $totalHours.":".$totalMinutes,
                        'adult_price' => number_format((float)$marked_up_adult['price'], 2, '.', ''),
                        'child_price' => number_format((float)$marked_up_child['price'], 2, '.', ''),
                        'infant_price' => number_format((float)$marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price' => number_format((float)$converted_adult['price'], 2, '.', ''),
                        'actual_child_price' => number_format((float)$converted_child['price'], 2, '.', ''),
                        'actual_infant_price' => number_format((float)$converted_infant['price'], 2, '.', ''),
                        'options' => '',
                        'booking_data' => array(
                            'booking_token' => $booking_token,
                            'session_id' => $decode['search_id'],
                            'currency' => $currency,
                            'amount' => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price']
                        ),
                        'redirect_url' => '',
                        'refundable' => '1',
                        'supplier' => 'kiwi',
                        'type' => $_POST['type'],
                    ];
                }
                $return_array["segments"][] = $sub_array;
            }

            // Process return segments for round trips
            if(!empty($return_segments)) {
                $sub_array = array();
                $total = [];

                foreach($return_segments as $duration){
                    $total[] = \timetodate($duration['utc_departure'], $duration['utc_arrival']);
                }

                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($return_segments as $key) {
                    $class = '';
                    if($key['fare_category'] == 'M'){$class="economy";}
                    if($key['fare_category'] == 'W'){$class="economy premium";}
                    if($key['fare_category'] == 'C'){$class="business";}
                    if($key['fare_category'] == 'F'){$class="first class";}

                    $flightCurrency = $decode['currency'];

                    // Apply markup and conversion using helpers
                    $marked_up_total = MARKUP($value['price'], $module, $db, $flightCurrency, $currency);
                    $converted_total = CURRENCY_CONVERT($value['price'], $db, $flightCurrency, $currency);

                    $marked_up_adult = MARKUP($value['fare']['adults'], $module, $db, $flightCurrency, $currency);
                    $marked_up_child = MARKUP($value['fare']['children'], $module, $db, $flightCurrency, $currency);
                    $marked_up_infant = MARKUP($value['fare']['infants'], $module, $db, $flightCurrency, $currency);

                    $converted_adult = CURRENCY_CONVERT($value['fare']['adults'], $db, $flightCurrency, $currency);
                    $converted_child = CURRENCY_CONVERT($value['fare']['children'], $db, $flightCurrency, $currency);
                    $converted_infant = CURRENCY_CONVERT($value['fare']['infants'], $db, $flightCurrency, $currency);

                    $sub_array[] = (object)[
                        'img' => $key['airline'],
                        'flight_no' => $key['flight_no'],
                        'airline' => $key['airline'],
                        'class' => $class,
                        'baggage' => (isset($value['baglimit']['hold_weight'])) ? $value['baglimit']['hold_weight'] . " KG" : "0 PC",
                        'cabin_baggage' => (isset($value['baglimit']['hand_weight'])) ? $value['baglimit']['hand_weight'] . " KG" : "0 PC",
                        'departure_airport' => $key['cityFrom'],
                        'departure_time' => date("h:i a", strtotime($key['utc_departure'])),
                        'arrival_airport' => $key['cityTo'],
                        'arrival_time' => date("h:i a", strtotime($key['utc_arrival'])),
                        'departure_date' => date("d-m-Y", strtotime($key['utc_departure'])),
                        'arrival_date' => date("d-m-Y", strtotime($key['utc_arrival'])),
                        'departure_code' => $key['cityCodeFrom'],
                        'arrival_code' => $key['cityCodeTo'],
                        'currency' => $currency,
                        'price' => number_format((float)$marked_up_total['price'], 2, '.', ''),
                        'actual_price' => number_format((float)$converted_total['price'], 2, '.', ''),
                        'duration_time' => \timetodate($key['utc_departure'], $key['utc_arrival']),
                        'total_duration' => $totalHours.":".$totalMinutes,
                        'adult_price' => number_format((float)$marked_up_adult['price'], 2, '.', ''),
                        'child_price' => number_format((float)$marked_up_child['price'], 2, '.', ''),
                        'infant_price' => number_format((float)$marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price' => number_format((float)$converted_adult['price'], 2, '.', ''),
                        'actual_child_price' => number_format((float)$converted_child['price'], 2, '.', ''),
                        'actual_infant_price' => number_format((float)$converted_infant['price'], 2, '.', ''),
                        'options' => '',
                        'booking_data' => array(
                            'booking_token' => $booking_token,
                            'session_id' => $decode['search_id'],
                            'currency' => $currency,
                            'amount' => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price']
                        ),
                        'redirect_url' => '',
                        'refundable' => '1',
                        'supplier' => 'kiwi',
                        'type' => $_POST['type'],
                    ];
                }
                $return_array["segments"][] = $sub_array;
            }

            $final_array[] = $return_array;
        }

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        if (!empty($final_array)) {
            // file_put_contents("logs/_RESPONSE_AGGREGATED.log", date('Y-m-d H:i:s') . "\n" . print_r(json_encode($final_array), true));
            echo json_encode($final_array);
        } else {
            echo json_encode([]);
        }
    } catch (Exception $e) {
        // file_put_contents("logs/_Api_Error.log", date('Y-m-d H:i:s') . " - " . $e->getMessage());
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(array("status" => false, "message" => "An error occurred. Please try again later."));
    }
});