<?php
/**
 * ============================================================================
 * BUS BOOKING E-TICKET VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Aligned with unified travel voucher design (Flights, Stays, Visa, Tours, eSIM, Cars)
 * Features:
 * - Brand / Agency Logo on top left
 * - Booking Reference in header (9pt matching unified vouchers)
 * - Dynamic QR Code on top right linking to live invoice URL
 * - Unpaid payment warning alert (dynamic)
 * - Formatted dynamic bus itinerary grids (Single or Round-Trip support)
 * - Multi-Passenger details table with assigned seat numbers
 * - Preserved Cancellation policies & Special requests
 * - Itemized Fare Details breakdown & Total
 * - Important Information card & dynamic Footer
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Bus Data dynamically
$bookingData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$trip = (array) ($bookingData['trip'] ?? []);

// Extract journeys (Support both one-way and round-trip)
$journeys = [];
if (!empty($bookingData['journeys']) && is_array($bookingData['journeys'])) {
    $journeys = $bookingData['journeys'];
} else {
    $journeys = [
        [
            'type' => 'outbound',
            'route_id' => $bookingData['route_id'] ?? 0,
            'date' => $bookingData['departure_date'] ?? $bookingData['travel_date'] ?? $bookingData['date'] ?? '',
            'trip' => !empty($trip) ? $trip : $bookingData
        ]
    ];
}

$adultsCnt = (int) ($booking['adults'] ?? 1);
$childsCnt = (int) ($booking['childs'] ?? 0);
$totalPassengers = max(1, $adultsCnt + $childsCnt);

// Date formatting helper
$formatBusDate = function ($dateVal) {
    if (empty($dateVal) || $dateVal === '-' || strtolower((string) $dateVal) === 'tbd' || strtolower((string) $dateVal) === 'null' || strtolower((string) $dateVal) === 'n/a') {
        return 'N/A';
    }
    $cleanDate = str_replace('/', '-', trim((string) $dateVal));
    $ts = strtotime($cleanDate);
    if ($ts && $ts > 0) {
        return date('D, d M Y', $ts);
    }
    return (string) $dateVal;
};

// 2. Lead Passenger / Customer Details
$leadName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
if (empty($leadName) && !empty($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadName = trim(($firstTraveller['first_name'] ?? '') . ' ' . ($firstTraveller['last_name'] ?? ''));
    }
}
if (empty($leadName)) {
    $leadName = 'Valued Passenger';
}

$leadEmail = $booking['email'] ?? '';
$phoneCode = $booking['phone_country_code'] ?? '';
$phoneNum = $booking['phone'] ?? '';
$leadPhoneFormatted = ($phoneCode ? '+' . ltrim($phoneCode, '+') . ' ' : '') . $phoneNum;
$leadPhoneFormatted = trim($leadPhoneFormatted);

$pnr = trim((string) ($booking['pnr'] ?? ''));

// 3. Normalize Passengers List with Seat Numbers
$passengerList = [];
if (!empty($travellersData) && is_array($travellersData)) {
    $paxIdx = 1;
    foreach ($travellersData as $tr) {
        if (!is_array($tr)) continue;
        $name = trim(($tr['title'] ?? '') . ' ' . ($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? ''));
        if (empty($name) && !empty($tr['name'])) {
            $name = trim($tr['name']);
        }
        if ($name === '') continue;

        $type = !empty($tr['type']) ? ucfirst(strtolower(trim((string) $tr['type']))) : 'Adult';
        $seat = !empty($tr['seat']) ? (stripos((string) $tr['seat'], 'seat') !== false ? (string) $tr['seat'] : 'Seat ' . $tr['seat']) : (!empty($bookingData['seat_number']) ? (string) $bookingData['seat_number'] : 'Confirmed');

        $nat = trim((string) ($tr['nationality'] ?? ''));
        if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
            if (!empty($cName)) $nat = $cName;
        }

        $passengerList[] = [
            'num' => str_pad((string) $paxIdx++, 2, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => $type,
            'seat' => $seat,
            'nationality' => $nat,
        ];
    }
}

if (empty($passengerList)) {
    $defaultSeat = !empty($bookingData['seat_number']) ? (string) $bookingData['seat_number'] : 'Assigned at boarding';
    $passengerList[] = [
        'num' => '01',
        'name' => $leadName,
        'type' => 'Lead Passenger',
        'seat' => $defaultSeat,
        'nationality' => $booking['nationality'] ?? '',
    ];
}

// 4. Payment Gateway & Status resolution
$rawGateway = trim((string) ($booking['payment_gateway'] ?? $booking['payment_method'] ?? ''));
$gatewayName = '';
if ($rawGateway !== '') {
    if (is_numeric($rawGateway) && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
        $gwRow = $GLOBALS['db']->get('payment_gateways', ['name', 'display_name'], ['id' => (int) $rawGateway]);
        if ($gwRow) {
            $gatewayName = $gwRow['display_name'] ?? $gwRow['name'] ?? $rawGateway;
        }
    }
    if ($gatewayName === '') {
        $gatewayName = ucfirst(str_replace(['-', '_'], ' ', $rawGateway));
    }
}
$isUnpaid = strtolower(trim((string) ($booking['payment_status'] ?? ''))) === 'unpaid';

// 5. Pricing & Financials
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

// Functional Live QR Code pointing to live invoice URL
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/bus/' . $invoiceId);

// Logo path resolution
$logoImgPath = '';
if (!empty($invoiceLogo) && file_exists($invoiceLogo)) {
    $logoImgPath = $invoiceLogo;
} elseif (file_exists(root . 'uploads/global/logo.png')) {
    $logoImgPath = root . 'uploads/global/logo.png';
} elseif (file_exists(__DIR__ . '/../../../../../uploads/global/logo.png')) {
    $logoImgPath = __DIR__ . '/../../../../../uploads/global/logo.png';
} else {
    $logoImgPath = $invoiceLogo ?? (uploads . 'global/logo.png');
}

$cancellationPolicy = trim((string) ($trip['cancellation_policy'] ?? $bookingData['cancellation_policy'] ?? ''));
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Bus E-Ticket Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: inter, DejaVuSans, Arial, sans-serif;
            color: #0f172a;
            background: #ffffff;
            font-size: 8pt;
            line-height: 1.25;
        }

        /* Outer Container */
        .ticket-card {
            background: #ffffff;
            margin: 0;
            padding: 0;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }

        td {
            padding: 0;
            vertical-align: top;
            border: none;
        }

        /* 1. Top Header Row */
        .header-table {
            margin-bottom: 6px;
        }

        .ref-label {
            font-size: 6.8pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ref-code {
            font-size: 9pt;
            font-weight: bold;
            color: #0b2545;
            margin-left: 4px;
            letter-spacing: 0.5px;
        }

        .qr-box {
            display: inline-block;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 3px;
            background: #ffffff;
            text-align: right;
        }

        .qr-img {
            width: 44px;
            height: 44px;
            display: block;
        }

        .divider-dashed {
            border-top: 1px dashed #cbd5e1;
            margin: 6px 0 6px 0;
        }

        .divider-solid {
            border-top: 1px solid #e2e8f0;
            margin: 5px 0;
        }

        /* 2. Passenger & PNR Row */
        .meta-label {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
        }

        .meta-value {
            font-size: 10pt;
            font-weight: bold;
            color: #0b2545;
            line-height: 1.2;
        }

        .meta-value-right {
            font-size: 10pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            line-height: 1.2;
            letter-spacing: 0.5px;
        }

        .lead-contact-line {
            font-size: 7pt;
            color: #334155;
            margin-top: 2px;
            line-height: 1.3;
        }

        .contact-key {
            font-size: 6.5pt;
            font-weight: bold;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .contact-val {
            font-size: 7pt;
            font-weight: bold;
            color: #0b2545;
        }

        .contact-sep {
            color: #94a3b8;
            margin: 0 4px;
        }

        /* Payment Status Alert Box */
        .alert-box {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 5px 10px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .alert-title {
            font-size: 7.5pt;
            font-weight: bold;
            color: #92400e;
            margin-bottom: 1px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .alert-text {
            font-size: 6.8pt;
            color: #b45309;
            line-height: 1.3;
        }

        /* 3. Primary Service Box */
        .service-box {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 7px 12px;
            margin-bottom: 6px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .box-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: #0b2545;
            line-height: 1.2;
        }

        .box-subtitle {
            font-size: 7pt;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.25;
        }

        .service-badge {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 6.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .grid-label {
            font-size: 6pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
        }

        .grid-value {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0b2545;
            line-height: 1.25;
        }

        .grid-sub {
            font-size: 6.2pt;
            color: #64748b;
            margin-top: 1px;
        }

        /* 4. Details Card (Passengers) */
        .pax-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .pax-header {
            background: #0b2545;
            padding: 5px 12px;
        }

        .pax-header-title {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .pax-header-meta {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-align: right;
            text-transform: uppercase;
        }

        .pax-body {
            padding: 0;
            background: #ffffff;
        }

        .pax-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pax-table th {
            background: #f8fafc;
            font-size: 6pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 4px 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .pax-table td {
            font-size: 7pt;
            color: #0f172a;
            padding: 4px 6px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .pax-table tr:last-child td {
            border-bottom: none;
        }

        .pax-num {
            color: #64748b;
            font-weight: bold;
            font-size: 6.8pt;
            text-align: center;
        }

        .pax-name {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0b2545;
        }

        .pax-meta-sub {
            font-size: 6.2pt;
            color: #64748b;
            margin-top: 1px;
        }

        .pax-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .seat-badge {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 6.2pt;
            font-weight: bold;
        }

        /* Specific Policy Alert Boxes */
        .policy-card {
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .policy-amber {
            background: #fffbeb;
            border: 1px solid #fef08a;
        }

        .policy-blue {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }

        .policy-title {
            font-size: 6.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 2px;
        }

        .policy-text {
            font-size: 6.5pt;
            line-height: 1.35;
        }

        /* 5. Fare Details Card */
        .fare-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .fare-header {
            background: #0b2545;
            padding: 5px 12px;
        }

        .fare-header-title {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .fare-header-amt {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-align: right;
            text-transform: uppercase;
        }

        .fare-body {
            padding: 6px 12px;
            background: #ffffff;
        }

        .fare-item-label {
            font-size: 7pt;
            color: #475569;
            padding: 2px 0;
        }

        .fare-item-val {
            font-size: 7pt;
            color: #0b2545;
            font-weight: bold;
            text-align: right;
            padding: 2px 0;
        }

        .fare-total-label {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0b2545;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-top: 3px;
        }

        .fare-total-val {
            font-size: 11.5pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            padding-top: 2px;
        }

        /* Important Information / Instructions Card */
        .instructions-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 12px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .instructions-title {
            font-size: 6.8pt;
            font-weight: bold;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            padding-bottom: 2px;
            border-bottom: 1px solid #e2e8f0;
        }

        .instruction-item {
            font-size: 6.2pt;
            color: #475569;
            line-height: 1.35;
            margin-bottom: 1.5px;
        }

        /* Footer */
        .footer {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 7px 10px;
            margin-top: 6px;
            text-align: center;
            page-break-inside: avoid;
        }

        .footer-contact {
            font-size: 6.8pt;
            color: #475569;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .footer-note {
            font-size: 5.8pt;
            color: #718096;
            line-height: 1.3;
        }
    </style>
</head>

<body>

    <div class="ticket-card">

        <!-- 1. Top Header Row -->
        <table class="header-table" style="width: 100%;">
            <tr>
                <td style="width: 70%; vertical-align: middle;">
                    <?php if (file_exists($logoImgPath)): ?>
                        <img src="<?= $logoImgPath ?>" alt="<?= htmlspecialchars(getBrandName($settings ?? null)) ?>"
                            style="height: 34px; width: auto; max-width: 180px; display: block;" />
                    <?php else: ?>
                        <div style="font-size: 13pt; font-weight: bold; color: #0b2545;">
                            <?= htmlspecialchars(getBrandName($settings ?? null)) ?>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 5px;">
                        <span class="ref-label">BOOKING REFERENCE:</span>
                        <span class="ref-code"><?= htmlspecialchars((string) $invoiceId) ?></span>
                    </div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: middle;">
                    <div class="qr-box">
                        <img class="qr-img" src="<?= $qrUrl ?>" width="44" height="44" style="width: 44px; height: 44px; display: block;" alt="QR Code" />
                    </div>
                </td>
            </tr>
        </table>

        <div class="divider-dashed"></div>

        <!-- 2. Passenger & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label"><?= count($passengerList) > 1 ? 'LEAD PASSENGER' : 'PASSENGER' ?></div>
                    <div class="meta-value"><?= htmlspecialchars($leadName) ?></div>
                    <?php if (!empty($leadEmail) || !empty($leadPhoneFormatted)): ?>
                        <div class="lead-contact-line">
                            <?php if (!empty($leadEmail)): ?>
                                <span class="contact-key">EMAIL:</span> <span class="contact-val"><?= htmlspecialchars($leadEmail) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($leadEmail) && !empty($leadPhoneFormatted)): ?>
                                <span class="contact-sep">•</span>
                            <?php endif; ?>
                            <?php if (!empty($leadPhoneFormatted)): ?>
                                <span class="contact-key">PHONE:</span> <span class="contact-val"><?= htmlspecialchars($leadPhoneFormatted) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <?php if (!empty($pnr)): ?>
                    <td style="width: 38%; vertical-align: top; text-align: right;">
                        <div class="meta-label" style="text-align: right;">PNR / TICKET</div>
                        <div class="meta-value-right"><?= htmlspecialchars($pnr) ?></div>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <!-- Payment Status Alert (if unpaid) -->
        <?php if ($isUnpaid): ?>
            <div class="alert-box">
                <div class="alert-title">PAYMENT REQUIRED</div>
                <div class="alert-text">
                    Please complete your payment using <strong><?= htmlspecialchars($gatewayName ?: 'the designated payment method') ?></strong> to confirm your reservation.
                </div>
            </div>
        <?php endif; ?>

        <!-- 3. Bus Journey Box(es) (Supports One-Way and Round-Trip) -->
        <?php foreach ($journeys as $jIdx => $journey): 
            $jTrip = (array) ($journey['trip'] ?? $trip);
            $jBusName = (string) ($jTrip['service_name'] ?? $jTrip['name'] ?? $bookingData['service_name'] ?? $bookingData['bus_name'] ?? $bookingData['name'] ?? $bookingData['title'] ?? 'Express Bus Service');
            $jOperator = (string) ($jTrip['operator'] ?? $jTrip['operator_name'] ?? $bookingData['operator'] ?? $bookingData['operator_name'] ?? 'Bus Operator');
            
            $jOrigin = (string) ($jTrip['origin'] ?? $jTrip['departure_station'] ?? $jTrip['from_station'] ?? $jTrip['from'] ?? $bookingData['origin'] ?? $bookingData['departure_station'] ?? $bookingData['from_station'] ?? $bookingData['from'] ?? 'Origin Station');
            $jDestination = (string) ($jTrip['destination'] ?? $jTrip['arrival_station'] ?? $jTrip['to_station'] ?? $jTrip['to'] ?? $bookingData['destination'] ?? $bookingData['arrival_station'] ?? $bookingData['to_station'] ?? $bookingData['to'] ?? 'Destination Station');
            
            $jDepTime = (string) ($jTrip['departure_time'] ?? $jTrip['dept_time'] ?? $jTrip['time'] ?? $bookingData['departure_time'] ?? $bookingData['dept_time'] ?? $bookingData['time'] ?? '');
            $jArrTime = (string) ($jTrip['arrival_time'] ?? $jTrip['arr_time'] ?? $bookingData['arrival_time'] ?? $bookingData['arr_time'] ?? '');
            $jDuration = (string) ($jTrip['duration'] ?? $bookingData['duration'] ?? '');
            
            $jBusType = trim((string) (($jTrip['bus_type'] ?? $bookingData['bus_type'] ?? $bookingData['bus_number'] ?? '') . (!empty($jTrip['seat_class']) ? ' · ' . $jTrip['seat_class'] : (!empty($bookingData['seat_class']) ? ' · ' . $bookingData['seat_class'] : ''))));

            $jDateRaw = (string) ($journey['date'] ?? $bookingData['departure_date'] ?? $bookingData['travel_date'] ?? $bookingData['date'] ?? '');
            $jFormattedDate = $formatBusDate($jDateRaw);

            $jType = strtolower((string) ($journey['type'] ?? 'outbound'));
            $badgeLabel = (count($journeys) > 1) ? ($jType === 'return' ? 'RETURN JOURNEY' : 'OUTBOUND JOURNEY') : 'BUS JOURNEY';
        ?>
            <div class="service-box">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 78%; vertical-align: middle;">
                            <div class="box-title"><?= htmlspecialchars($jOrigin) ?> → <?= htmlspecialchars($jDestination) ?></div>
                            <div class="box-subtitle">
                                <?= htmlspecialchars($jOperator) ?><?= $jBusName !== '' ? ' · ' . htmlspecialchars($jBusName) : '' ?><?= $jBusType !== '' ? ' (' . htmlspecialchars($jBusType) . ')' : '' ?>
                            </div>
                        </td>
                        <td style="width: 22%; vertical-align: middle; text-align: right;">
                            <span class="service-badge"><?= htmlspecialchars($badgeLabel) ?></span>
                        </td>
                    </tr>
                </table>

                <div class="divider-solid"></div>

                <!-- 4-Column Grid: Travel Date, Departure, Arrival, Passengers -->
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 28%; vertical-align: top;">
                            <div class="grid-label">TRAVEL DATE</div>
                            <div class="grid-value"><?= htmlspecialchars($jFormattedDate) ?></div>
                            <div class="grid-sub"><?= htmlspecialchars($jDuration ?: 'Scheduled Route') ?></div>
                        </td>
                        <td style="width: 24%; vertical-align: top;">
                            <div class="grid-label">DEPARTURE</div>
                            <div class="grid-value"><?= htmlspecialchars(!empty($jDepTime) ? $jDepTime : 'Scheduled') ?></div>
                            <div class="grid-sub"><?= htmlspecialchars($jOrigin) ?></div>
                        </td>
                        <td style="width: 24%; vertical-align: top;">
                            <div class="grid-label">ARRIVAL</div>
                            <div class="grid-value"><?= htmlspecialchars(!empty($jArrTime) ? $jArrTime : 'Scheduled') ?></div>
                            <div class="grid-sub"><?= htmlspecialchars($jDestination) ?></div>
                        </td>
                        <td style="width: 24%; vertical-align: top;">
                            <div class="grid-label">TRAVELERS</div>
                            <div class="grid-value"><?= $totalPassengers ?> Passenger<?= $totalPassengers > 1 ? 's' : '' ?></div>
                            <div class="grid-sub"><?= $adultsCnt ?> Adult<?= $adultsCnt > 1 ? 's' : '' ?><?= $childsCnt > 0 ? ', ' . $childsCnt . ' Child' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- 4. Passengers Table Card (Displays Name, Type, Seat, Status) -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">PASSENGER DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta"><?= count($passengerList) ?> PASSENGER<?= count($passengerList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 6%; text-align: center;">#</th>
                            <th style="width: 44%; text-align: left;">PASSENGER NAME</th>
                            <th style="width: 18%; text-align: center;">TYPE</th>
                            <th style="width: 18%; text-align: center;">SEAT</th>
                            <th style="width: 14%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passengerList as $pax): ?>
                            <tr>
                                <td class="pax-num"><?= htmlspecialchars($pax['num']) ?></td>
                                <td>
                                    <div class="pax-name"><?= htmlspecialchars($pax['name']) ?></div>
                                    <?php if (!empty($pax['nationality'])): ?>
                                        <div class="pax-meta-sub"><?= htmlspecialchars($pax['nationality']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="pax-badge"><?= htmlspecialchars($pax['type']) ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="seat-badge"><?= htmlspecialchars($pax['seat']) ?></span>
                                </td>
                                <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">
                                    Confirmed
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Special Requests / Policies (if any) -->
        <?php if (!empty($cancellationPolicy)): ?>
            <div class="policy-card policy-amber">
                <div class="policy-title" style="color: #92400e;">CANCELLATION POLICY</div>
                <div class="policy-text" style="color: #92400e;"><?= nl2br(htmlspecialchars($cancellationPolicy)) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($booking['special_requests'])): ?>
            <div class="policy-card policy-blue">
                <div class="policy-title" style="color: #1e40af;">SPECIAL REQUESTS</div>
                <div class="policy-text" style="color: #1e3a8a;"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
            </div>
        <?php endif; ?>

        <!-- 6. Fare Details Card Box -->
        <div class="fare-card">
            <div class="fare-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 50%;">
                            <div class="fare-header-title">FARE DETAILS</div>
                        </td>
                        <td style="width: 50%; text-align: right;">
                            <div class="fare-header-amt">AMOUNT (<?= htmlspecialchars($currency) ?>)</div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="fare-body">
                <table style="width: 100%;">
                    <tr>
                        <td class="fare-item-label">Ticket Base Fare (<?= count($passengerList) ?> Passenger<?= count($passengerList) > 1 ? 's' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Transport Fees</td>
                        <td class="fare-item-val"><?= number_format((float) ($pdfTaxAmount ?? ($taxAmountRaw * ($pdfDisplayRate ?? 1.0))), 2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding: 4px 0 2px 0;">
                            <div style="border-top: 1px dashed #cbd5e1;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="fare-total-label">TOTAL AMOUNT</td>
                        <td class="fare-total-val"><?= htmlspecialchars($currency) ?> <?= htmlspecialchars($totalAmount) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 7. Important Information (Original) -->
        <div class="instructions-card">
            <div class="instructions-title">Important Information</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• Arrive at the boarding point at least 30 minutes before departure</div>
                <div class="instruction-item">• Carry a valid photo ID matching the lead passenger name</div>
                <div class="instruction-item">• Show this voucher (printed or digital) to the operator at boarding</div>
                <div class="instruction-item">• Luggage allowances and policies are set by the bus operator</div>
            </div>
        </div>

        <!-- 8. Footer (Original) -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail ?? $settings['email_sender_email'] ?? 'support@phptravels.com') ?><?php if (!empty($businessPhone) || !empty($settings['site_phone'])): ?> | <?= htmlspecialchars($businessPhone ?? $settings['site_phone']) ?><?php endif; ?>
            </div>
            <div class="footer-note">
                © <?= date('Y') ?> <?= htmlspecialchars(getBrandName($settings ?? null)) ?>. All rights reserved.<br>
                Booking Date: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'] ?? $booking['created_at'] ?? 'now')) ?><br>
                This is an automated booking voucher. Please do not reply to this document.
            </div>
        </div>

    </div>

</body>

</html>
