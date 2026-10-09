<?php
/**
 * ============================================================================
 * RAIL / TRAIN BOOKING E-TICKET VOUCHER PDF
 * ============================================================================
 * Follows exact design pattern of flights/booking_pdf.php
 * Strictly preserves original footer content & rail notices
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Rail Data dynamically
$bookingData = (isset($bookingData) && is_array($bookingData)) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = (isset($travellersData) && is_array($travellersData)) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);
$bookingResponse = (isset($bookingResponse) && is_array($bookingResponse)) ? $bookingResponse : (json_decode($booking['booking_response'] ?? '{}', true) ?: []);

$journey = (array) ($bookingData['journey'][0] ?? $bookingData['train'] ?? $bookingData['trip'] ?? []);

// Time & Date formatters
$extractTime = static function ($timeRaw, $tsRaw = 0) {
    if (!empty($timeRaw)) {
        $str = trim((string) $timeRaw);
        // Already formatted e.g. "09:12 AM" or "09:12"
        if (preg_match('/^(0?[1-9]|1[0-2]|0[0-9]|2[0-3]):[0-5][0-9](\s*(AM|PM|am|pm))?$/i', $str)) {
            return $str;
        }
        // 4 digits e.g. "0912"
        if (preg_match('/^([01][0-9]|2[0-3])([0-5][0-9])$/', $str, $m)) {
            return $m[1] . ':' . $m[2];
        }
        $parsed = strtotime($str);
        if ($parsed !== false && $parsed > 0) {
            return date('H:i', $parsed);
        }
    }
    $ts = (int) $tsRaw;
    if ($ts > 10000000) {
        return date('H:i', $ts);
    }
    return '';
};

$extractDate = static function ($dateRaw, $tsRaw = 0, $fallback = '') {
    if (!empty($dateRaw)) {
        $str = trim((string) $dateRaw);
        $parsed = strtotime($str);
        if ($parsed !== false && $parsed > 0) {
            return date('D, M d, Y', $parsed);
        }
    }
    $ts = (int) $tsRaw;
    if ($ts > 10000000) {
        return date('D, M d, Y', $ts);
    }
    if (!empty($fallback)) {
        $parsed = strtotime((string) $fallback);
        if ($parsed !== false && $parsed > 0) {
            return date('D, M d, Y', $parsed);
        }
    }
    return date('D, M d, Y');
};

// Stations Resolution
$fromCode = strtoupper(trim((string) ($journey['from_station_code'] ?? $bookingData['from_station_code'] ?? $bookingData['origin_code'] ?? '')));
$toCode   = strtoupper(trim((string) ($journey['to_station_code'] ?? $bookingData['to_station_code'] ?? $bookingData['destination_code'] ?? '')));

if (!function_exists('_train_station_label') && isset($db)) {
    $stationsFile = dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
    if (file_exists($stationsFile)) {
        require_once $stationsFile;
    }
}

$fromStation = '';
if (!empty($fromCode) && isset($db) && function_exists('_train_station_label')) {
    $fromStation = _train_station_label($db, $fromCode);
}
if (empty($fromStation)) {
    $fromStation = trim((string) (
        $journey['from_station_name']
        ?? $journey['from_station_english']
        ?? $journey['from_station']
        ?? $bookingData['departure_station']
        ?? $bookingData['from_station']
        ?? $bookingData['origin']
        ?? $bookingData['from']
        ?? $bookingData['departure']
        ?? $fromCode
        ?? ''
    ));
}
if (empty($fromStation)) {
    $fromStation = 'Departure Station';
}

$toStation = '';
if (!empty($toCode) && isset($db) && function_exists('_train_station_label')) {
    $toStation = _train_station_label($db, $toCode);
}
if (empty($toStation)) {
    $toStation = trim((string) (
        $journey['to_station_name']
        ?? $journey['to_station_english']
        ?? $journey['to_station']
        ?? $bookingData['arrival_station']
        ?? $bookingData['to_station']
        ?? $bookingData['destination']
        ?? $bookingData['to']
        ?? $bookingData['arrival']
        ?? $toCode
        ?? ''
    ));
}
if (empty($toStation)) {
    $toStation = 'Arrival Station';
}

// Departure & Arrival Times
$rawDepTime = $journey['departure_time']
    ?? $journey['from_time']
    ?? $journey['dept_time']
    ?? $journey['time']
    ?? $bookingData['departure_time']
    ?? $bookingData['from_time']
    ?? $bookingData['dept_time']
    ?? $bookingData['time']
    ?? '';

$rawArrTime = $journey['arrival_time']
    ?? $journey['to_time']
    ?? $journey['arr_time']
    ?? $bookingData['arrival_time']
    ?? $bookingData['to_time']
    ?? $bookingData['arr_time']
    ?? '';

$fromTs = (int) ($journey['from_date_time'] ?? $bookingData['from_date_time'] ?? 0);
$toTs   = (int) ($journey['to_date_time'] ?? $bookingData['to_date_time'] ?? 0);

$fromTime = $extractTime($rawDepTime, $fromTs);
$toTime   = $extractTime($rawArrTime, $toTs);

if ($fromTime === '' && !empty($booking['booking_date'])) {
    $bTs = strtotime($booking['booking_date']);
    if ($bTs !== false && date('H:i', $bTs) !== '00:00') {
        $fromTime = date('H:i', $bTs);
    }
}

// Departure & Arrival Dates
$rawDepDate = $journey['departure_date']
    ?? $journey['from_date']
    ?? $journey['travel_date']
    ?? $journey['date']
    ?? $bookingData['departure_date']
    ?? $bookingData['travel_date']
    ?? $bookingData['date']
    ?? $booking['booking_date']
    ?? '';

$rawArrDate = $journey['arrival_date']
    ?? $journey['to_date']
    ?? $bookingData['arrival_date']
    ?? $rawDepDate;

$departureDate = $extractDate($rawDepDate, $fromTs, $booking['booking_date'] ?? 'now');
$arrivalDate   = $extractDate($rawArrDate, $toTs, $rawDepDate);

// Duration Calculation
$runTimeMinutes = max(0, (int) ($journey['run_time'] ?? $bookingData['run_time'] ?? 0));
if ($runTimeMinutes <= 0 && $fromTs > 0 && $toTs > $fromTs) {
    $runTimeMinutes = (int) round(($toTs - $fromTs) / 60);
}
$durationLabel = '';
if ($runTimeMinutes > 0) {
    $hours = intdiv($runTimeMinutes, 60);
    $mins = $runTimeMinutes % 60;
    $durationLabel = $hours > 0 ? $hours . 'h ' . $mins . 'm' : $mins . 'm';
} else {
    $durationLabel = trim((string) (
        $journey['duration']
        ?? $bookingData['duration']
        ?? ''
    ));
}

// Train Info & Seat Class
$trafficNo = trim((string) (
    $journey['traffic_no']
    ?? $journey['train_no']
    ?? $bookingData['train_number']
    ?? $bookingData['traffic_no']
    ?? $bookingData['train_no']
    ?? $bookingData['name']
    ?? $bookingData['title']
    ?? 'Express Train'
));

$seatName = trim((string) (
    $journey['seat_name']
    ?? $journey['seat_class']
    ?? $bookingData['class']
    ?? $bookingData['seat_class']
    ?? $bookingData['seat_name']
    ?? 'Standard Class'
));

$pnr = trim((string) ($booking['pnr'] ?? ''));

$adultsCnt = (int) ($booking['adults'] ?? 1);
$childsCnt = (int) ($booking['childs'] ?? 0);
$totalPassengers = max(1, $adultsCnt + $childsCnt);

// Lead Customer / Passenger Details
$leadName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
if (empty($leadName) && !empty($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadTitle = trim((string) ($firstTraveller['title'] ?? ''));
        $leadFirst = trim((string) ($firstTraveller['first_name'] ?? $firstTraveller['passenger_first_name'] ?? ''));
        $leadLast  = trim((string) ($firstTraveller['last_name'] ?? $firstTraveller['passenger_last_name'] ?? ''));
        $leadName  = trim(($leadTitle !== '' ? $leadTitle . ' ' : '') . $leadFirst . ' ' . $leadLast);
        if ($leadName === '' && !empty($firstTraveller['name'])) {
            $leadName = trim((string) $firstTraveller['name']);
        }
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

// Passengers & Seats resolution
$rspPassengers = $bookingResponse['data']['journey'][0]['passengers']
    ?? $bookingResponse['journey'][0]['passengers']
    ?? $bookingResponse['poll']['journey'][0]['passengers']
    ?? [];
if (!is_array($rspPassengers)) {
    $rspPassengers = [];
}

$passengerList = [];
$travellersArray = is_array($travellersData) ? $travellersData : [];
if ($travellersArray === [] && !empty($bookingData['passengers']) && is_array($bookingData['passengers'])) {
    $travellersArray = $bookingData['passengers'];
}

if (!empty($travellersArray)) {
    $idx = 1;
    foreach ($travellersArray as $key => $tr) {
        if (!is_array($tr)) continue;
        $title = trim((string) ($tr['title'] ?? ''));
        $first = trim((string) ($tr['first_name'] ?? $tr['passenger_first_name'] ?? ''));
        $last  = trim((string) ($tr['last_name'] ?? $tr['passenger_last_name'] ?? ''));
        $name  = trim(($title !== '' ? $title . ' ' : '') . $first . ' ' . $last);
        if ($name === '' && !empty($tr['name'])) {
            $name = trim((string) $tr['name']);
        }
        if ($name === '') continue;

        $cardNo = trim((string) ($tr['passenger_card_no'] ?? $tr['identity_number'] ?? $tr['passport'] ?? $tr['id_number'] ?? $tr['doc_no'] ?? ''));
        $nationality = trim((string) ($tr['nationality'] ?? $tr['country'] ?? $tr['passenger_country_code'] ?? ''));

        $seatLine = trim((string) ($tr['seat'] ?? $tr['seat_no'] ?? $tr['train_seat_no'] ?? ''));
        if ($seatLine === '') {
            foreach ($rspPassengers as $rp) {
                if (!is_array($rp)) continue;
                if ($cardNo !== '' && trim((string) ($rp['passenger_card_no'] ?? '')) === $cardNo) {
                    $coach = trim((string) ($rp['train_coach_no'] ?? ''));
                    $seat = trim((string) ($rp['train_seat_no'] ?? ''));
                    if ($coach !== '' || $seat !== '') {
                        $seatLine = ($coach !== '' ? 'Car ' . $coach : '') . ($seat !== '' ? ($coach !== '' ? ' · Seat ' : 'Seat ') . $seat : '');
                    }
                    break;
                }
            }
        }
        if ($seatLine === '' && (!empty($bookingData['coach']) || !empty($bookingData['seats']) || !empty($bookingData['seat']))) {
            $coach = trim((string) ($bookingData['coach'] ?? ''));
            $seat = trim((string) ($bookingData['seats'] ?? $bookingData['seat'] ?? ''));
            if ($coach !== '' || $seat !== '') {
                $seatLine = ($coach !== '' ? 'Car ' . $coach : '') . ($seat !== '' ? ($coach !== '' ? ' · Seat ' : 'Seat ') . $seat : '');
            }
        }

        $pType = $tr['passenger_type'] ?? $tr['type'] ?? '';
        if (is_numeric($pType)) {
            $type = match ((int) $pType) {
                2 => 'Child',
                3 => 'Infant',
                default => 'Adult',
            };
        } elseif (!empty($pType)) {
            $type = ucfirst((string) $pType);
        } elseif (is_string($key) && stripos($key, 'child') !== false) {
            $type = 'Child';
        } elseif (is_string($key) && stripos($key, 'infant') !== false) {
            $type = 'Infant';
        } else {
            $type = 'Adult';
        }

        $passengerList[] = [
            'num'    => str_pad((string) $idx++, 2, '0', STR_PAD_LEFT),
            'name'   => $name,
            'type'   => $type,
            'doc_no' => $cardNo !== '' ? $cardNo : ($nationality !== '' ? $nationality : ''),
            'seat'   => $seatLine !== '' ? $seatLine : 'Confirmed',
        ];
    }
}

if (empty($passengerList)) {
    $fallbackSeat = 'Confirmed';
    if (!empty($bookingData['coach']) || !empty($bookingData['seats']) || !empty($bookingData['seat'])) {
        $coach = trim((string) ($bookingData['coach'] ?? ''));
        $seat = trim((string) ($bookingData['seats'] ?? $bookingData['seat'] ?? ''));
        if ($coach !== '' || $seat !== '') {
            $fallbackSeat = ($coach !== '' ? 'Car ' . $coach : '') . ($seat !== '' ? ($coach !== '' ? ' · Seat ' : 'Seat ') . $seat : '');
        }
    }
    $passengerList[] = [
        'num'    => '01',
        'name'   => $leadName,
        'type'   => 'Lead Passenger',
        'doc_no' => trim((string) ($booking['nationality'] ?? '')),
        'seat'   => $fallbackSeat,
    ];
}

// Pricing
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

// Live Functional QR Code
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/rail/' . $invoiceId);

// Logo resolution
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
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Rail E-Ticket Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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
        }

        /* 1. Top Header Row */
        .header-table {
            margin-bottom: 6px;
        }

        .ref-label {
            color: #64748b;
            font-size: 6.8pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .ref-code {
            color: #0b2545;
            font-size: 9pt;
            font-weight: bold;
            margin-left: 4px;
            letter-spacing: 0.5px;
        }

        .qr-box {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 3px;
            display: inline-block;
            background: #ffffff;
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
            color: #64748b;
            font-size: 6.5pt;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            margin-bottom: 1px;
        }

        .meta-value {
            color: #0b2545;
            font-size: 10pt;
            font-weight: bold;
            line-height: 1.2;
        }

        .meta-value-right {
            color: #0b2545;
            font-size: 10pt;
            font-weight: bold;
            text-align: right;
            line-height: 1.2;
        }

        .lead-contact-line {
            margin-top: 2px;
            font-size: 7pt;
            line-height: 1.3;
        }

        .contact-key {
            color: #475569;
            font-weight: bold;
            font-size: 6.5pt;
            letter-spacing: 0.3px;
        }

        .contact-val {
            color: #0b2545;
            font-weight: bold;
        }

        .contact-sep {
            color: #94a3b8;
            margin: 0 4px;
        }

        /* 3. Primary Service Box */
        .service-box {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 7px 12px;
            margin-bottom: 6px;
        }

        .box-title {
            color: #0b2545;
            font-size: 11pt;
            font-weight: bold;
            line-height: 1.2;
        }

        .box-subtitle {
            color: #64748b;
            font-size: 7.2pt;
            margin-top: 2px;
        }

        .service-badge {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            border-radius: 4px;
            font-size: 6.5pt;
            font-weight: bold;
            padding: 2px 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .grid-label {
            color: #64748b;
            font-size: 6pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
        }

        .grid-value {
            color: #0b2545;
            font-size: 8.5pt;
            font-weight: bold;
            line-height: 1.25;
        }

        .grid-sub {
            color: #475569;
            font-size: 6.8pt;
            margin-top: 1px;
        }

        /* 4. Details Card (Passengers & Coach/Seats) */
        .pax-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
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
            color: #64748b;
            font-size: 6pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 4px 10px;
            border-bottom: 1px solid #e2e8f0;
            border-top: none;
        }

        .pax-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 7pt;
        }

        .pax-table tr:last-child td {
            border-bottom: none;
        }

        .pax-num {
            color: #64748b;
            font-size: 7pt;
            font-weight: bold;
            width: 5%;
        }

        .pax-name {
            color: #0b2545;
            font-size: 7.5pt;
            font-weight: bold;
        }

        .pax-badge {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-size: 6pt;
            font-weight: bold;
            padding: 1px 5px;
            border-radius: 3px;
            text-transform: uppercase;
            display: inline-block;
        }

        /* 5. Fare Details Card */
        .fare-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
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
            font-size: 8.8pt;
            font-weight: bold;
            color: #0b2545;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-top: 4px;
        }

        .fare-total-val {
            font-size: 12pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            padding-top: 2px;
        }

        /* Payment Status Alert Box (Original) */
        .alert-box {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 6px 10px;
            margin-bottom: 6px;
            font-size: 7pt;
            color: #92400e;
            border-radius: 4px;
            page-break-inside: avoid;
        }

        /* Footer */
        .footer {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            margin-top: 6px;
            text-align: center;
            page-break-inside: avoid;
        }

        .footer-contact {
            font-size: 7pt;
            color: #475569;
            margin-bottom: 3px;
            font-weight: 500;
        }

        .footer-note {
            font-size: 6pt;
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
                            style="height: 36px; width: auto; max-width: 180px; display: block;" />
                    <?php else: ?>
                        <div style="font-size: 14pt; font-weight: bold; color: #0b2545;">
                            <?= htmlspecialchars(getBrandName($settings ?? null)) ?>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 6px;">
                        <span class="ref-label">BOOKING REFERENCE:</span>
                        <span class="ref-code"><?= htmlspecialchars((string) $invoiceId) ?></span>
                    </div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: middle;">
                    <div class="qr-box">
                        <img src="<?= $qrUrl ?>" width="44" height="44" style="width: 44px; height: 44px; display: block;" alt="QR Code" />
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

        <!-- 3. Train Journey Service Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($fromStation) ?> → <?= htmlspecialchars($toStation) ?></div>
                        <div class="box-subtitle">Train <?= htmlspecialchars($trafficNo) ?><?= $seatName !== '' ? ' · ' . htmlspecialchars($seatName) : '' ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge">RAIL JOURNEY</span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid: Departure Date, Departure, Arrival, Passengers -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 28%; vertical-align: top;">
                        <div class="grid-label">TRAVEL DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($departureDate) ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($durationLabel ?: 'Rail Service') ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">DEPARTURE</div>
                        <div class="grid-value"><?= htmlspecialchars($fromTime !== '' ? $fromTime : '—') ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($fromStation) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">ARRIVAL</div>
                        <div class="grid-value">
                            <?= htmlspecialchars($toTime !== '' ? $toTime : '—') ?>
                            <?php if (!empty($arrivalDate) && !empty($departureDate) && $arrivalDate !== $departureDate): ?>
                                <span style="font-size: 6pt; color: #64748b; font-weight: normal;">(+1)</span>
                            <?php endif; ?>
                        </div>
                        <div class="grid-sub"><?= htmlspecialchars($toStation) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">PASSENGERS</div>
                        <div class="grid-value"><?= $totalPassengers ?> Passenger<?= $totalPassengers > 1 ? 's' : '' ?></div>
                        <div class="grid-sub"><?= $adultsCnt ?> Adult<?= $adultsCnt > 1 ? 's' : '' ?><?= $childsCnt > 0 ? ', ' . $childsCnt . ' Child' : '' ?></div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 4. Passengers Table Card -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">PASSENGER &amp; SEAT ASSIGNMENT</div>
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
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 45%; text-align: left;">PASSENGER NAME</th>
                            <th style="width: 18%; text-align: center;">TYPE</th>
                            <th style="width: 16%; text-align: center;">COACH / SEAT</th>
                            <th style="width: 16%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passengerList as $pax): ?>
                            <tr>
                                <td class="pax-num" style="text-align: center;"><?= htmlspecialchars($pax['num']) ?></td>
                                <td class="pax-name">
                                    <?= htmlspecialchars($pax['name']) ?>
                                    <?php if (!empty($pax['doc_no'])): ?>
                                        <div style="font-size: 5.8pt; color: #64748b; font-weight: normal; margin-top: 1px;">
                                            <?= htmlspecialchars(strlen($pax['doc_no']) <= 3 ? 'Nationality: ' . $pax['doc_no'] : 'ID/Doc: ' . $pax['doc_no']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="pax-badge"><?= htmlspecialchars($pax['type']) ?></span>
                                </td>
                                <td style="text-align: center; color: #475569; font-size: 6.8pt;">
                                    <?= htmlspecialchars($pax['seat']) ?>
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

        <!-- 5. Special Requests (if any) -->
        <?php if (!empty($booking['special_requests'])): ?>
            <div style="border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 6px; padding: 5px 10px; margin-bottom: 6px; page-break-inside: avoid;">
                <div style="font-size: 6.5pt; font-weight: bold; color: #1e40af; text-transform: uppercase;">SPECIAL REQUESTS</div>
                <div style="font-size: 6.8pt; color: #1e3a8a; margin-top: 2px;"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
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
                        <td class="fare-item-label">Rail Ticket Base (<?= count($passengerList) ?> Passenger<?= count($passengerList) > 1 ? 's' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Station Fees</td>
                        <td class="fare-item-val"><?= number_format((float) ($pdfTaxAmount ?? ($taxAmountRaw * ($pdfDisplayRate ?? 1.0))), 2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding: 5px 0 2px 0;">
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

        <!-- 6. Payment Status Alert Box (Original) -->
        <?php if (($booking['payment_status'] ?? '') !== 'paid'): ?>
            <div class="alert-box">Payment is pending. Please complete payment to confirm this booking.</div>
        <?php endif; ?>

        <!-- 7. Footer (Original) -->
        <?php
        $footerEmail = $settings['email_sender_email'] ?? ($businessEmail ?? 'support@phptravels.com');
        $footerPhone = $settings['site_phone'] ?? ($businessPhone ?? '');
        ?>
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars((string) $footerEmail) ?><?php if (!empty($footerPhone)): ?> | <?= htmlspecialchars((string) $footerPhone) ?><?php endif; ?>
            </div>
            <div class="footer-note">
                © <?= date('Y') ?> <?= htmlspecialchars(getBrandName($settings ?? null)) ?>. All rights reserved.<br>
                Invoice #<?= htmlspecialchars((string) $invoiceId) ?> · Generated <?= date('M d, Y H:i') ?><br>
                This is an automated booking voucher. Please do not reply to this document.
            </div>
        </div>

    </div>

</body>

</html>
