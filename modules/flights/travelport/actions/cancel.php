<?php

$router->post('flights/travelport/cancel', function() use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        function log_cert($step, $data) {
            $file = "/Applications/XAMPP/xamppfiles/htdocs/travelport/certification/cancel_" . date('H-i-s') . ".json";
            $current = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            $current[$step] = $data;
            file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT));
        }

        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);
        log_cert("Localhost_Cancel_Params", $request_data);

        $invoice_id = $request_data['invoice_id'] ?? '';
        $booking = $db->query("SELECT * FROM bookings WHERE id = '$invoice_id' AND module = 'travelport' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$booking) { echo json_encode(["status" => false, "message" => "Booking not found"]); exit; }

        $module = $db->get('modules', '*', ['name' => 'travelport']);
        $clientId = $module['c1']; $clientSecret = $module['c2'];
        $pcc = $module['c6']; $accessGroup = $module['c5'];

        // STEP 1: Token
        $tokenUrl = "https://auth.pp.travelport.net/oauth/token";
        $auth = base64_encode($clientId . ":" . $clientSecret);
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $auth", "Content-Type: application/x-www-form-urlencoded"]);
        $tokenRes = curl_exec($ch);
        $tokenData = json_decode($tokenRes, true);
        curl_close($ch);
        $token = $tokenData['access_token'] ?? '';

        $baseUrl = "https://api.pp.travelport.net/11";
        $pnr = $booking['pnr'];

        // STEP 2: Initiate Workbench
        $initUrl = "$baseUrl/air/book/session/reservationworkbench/buildfromlocator?Locator=$pnr";
        $headers = ["Authorization: Bearer $token", "Content-Type: application/json", "Accept: application/json", "TVP-PCC-Core: $pcc", "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup", "Content-Version: 11"];
        log_cert("Travelport_Req_Initiate", ["url" => $initUrl, "headers" => $headers]);
        $ch = curl_init($initUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $initRes = curl_exec($ch);
        curl_close($ch);
        $initData = json_decode($initRes, true);
        log_cert("Travelport_Res_Initiate", $initData);

        $resId = $initData['ReservationResponse']['Identifier']['value'] ?? null;
        $offerValue = $initData['ReservationResponse']['Reservation']['Offer'][0]['Identifier']['value'] ?? null;

        // STEP 3: Cancel Offer
        $cancelOfferUrl = "$baseUrl/air/book/airoffer/reservationworkbench/$resId/offers/canceloffer";
        $cancelPayload = ["@type" => "OfferQueryCancelOffer", "BuildFromOffer" => ["@type" => "BuildFromOfferAir", "OfferIdentifier" => ["Identifier" => ["value" => $offerValue]]]];
        log_cert("Travelport_Req_CancelOffer", ["url" => $cancelOfferUrl, "payload" => $cancelPayload]);
        $ch = curl_init($cancelOfferUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancelPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $cancelRes = curl_exec($ch);
        curl_close($ch);
        $cancelData = json_decode($cancelRes, true);
        log_cert("Travelport_Res_CancelOffer", $cancelData);

        // STEP 4: Commit
        $commitUrl = "$baseUrl/air/book/reservation/reservations/$resId";
        $commitPayload = ["@type" => "ReservationQueryCommitReservation"];
        log_cert("Travelport_Req_Commit", ["url" => $commitUrl, "payload" => $commitPayload]);
        $ch = curl_init($commitUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($commitPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $commitRes = curl_exec($ch);
        curl_close($ch);
        $commitData = json_decode($commitRes, true);
        log_cert("Travelport_Res_Commit", $commitData);

        if (isset($commitData['ReservationResponse']['Result']['Error'])) {
            $final_res = ["status" => false, "message" => "Cancellation failed", "response" => $commitData];
        } else {
            $db->update("bookings", ["booking_status" => "cancelled"], ["id" => $booking['id']]);
            $final_res = ["status" => true, "message" => "Booking cancelled successfully", "response" => $commitData];
        }
        log_cert("Final_Localhost_Response", $final_res);
        echo json_encode($final_res);

    } catch (Exception $e) {
        echo json_encode(["status" => false, "message" => $e->getMessage()]);
    }
});
