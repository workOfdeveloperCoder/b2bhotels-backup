<?php
/**
 * ============================================================================
 * FERRIES BOOKING E-TICKET VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Follows exact design pattern of flights/booking_pdf.php
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

$kikotoApiPath = __DIR__ . '/../../../../../modules/ferries/kikoto/api.php';
if (file_exists($kikotoApiPath)) {
    require_once $kikotoApiPath;
}

// 1. Prepare Ferries Data dynamically
$bookingData = (isset($bookingData) && is_array($bookingData)) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = (isset($travellersData) && is_array($travellersData)) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

// Resolve Ports Cache
$ferriesPorts = [];
$portsCache = __DIR__ . '/../../../../cache/kikoto_ports_en.json';
if (file_exists($portsCache)) {
    $ferriesPorts = json_decode(file_get_contents($portsCache), true) ?: [];
}
$portName = static function (int $id) use ($ferriesPorts): string {
    foreach ($ferriesPorts as $p) {
        if ((int) ($p['id'] ?? 0) === $id) {
            return $p['name'] ?? '';
        }
    }
    return $id > 0 ? ('Port ' . $id) : '';
};

$confirmed = (array) ($bookingData['confirmed'] ?? []);
$sailings = [];
if (!empty($confirmed['sailings']) && is_array($confirmed['sailings'])) {
    $sailings = $confirmed['sailings'];
} else {
    $draft = $bookingData['draft']['selected_sailing'] ?? [];
    if (!empty($draft)) {
        $sailings[] = $draft;
    }
    if (!empty($bookingData['draft']['return_sailing'])) {
        $sailings[] = $bookingData['draft']['return_sailing'];
    }
}

$locators = (array) ($bookingData['locators'] ?? []);
$reference = (string) ($bookingData['reference'] ?? $booking['pnr'] ?? '');
$locator = implode(', ', array_filter($locators)) ?: ($booking['pnr'] ?? '');
$pnr = $reference ?: $locator;

// Vehicles & Pets
$pdfVehicles = $bookingData['vehicles'] ?? $bookingData['draft']['vehicles'] ?? [];
$pdfPets = $bookingData['pets'] ?? $bookingData['draft']['pets'] ?? [];
if (!is_array($pdfVehicles)) {
    $pdfVehicles = [];
}
if (!is_array($pdfPets)) {
    $pdfPets = [];
}

// Bonuses & Coupons
$pdfBonuses = [];
if (!empty($bookingData['bonuses']) && is_array($bookingData['bonuses'])) {
    $pdfBonuses = $bookingData['bonuses'];
} elseif (!empty($bookingData['draft']['bonuses']) && is_array($bookingData['draft']['bonuses'])) {
    $pdfBonuses = $bookingData['draft']['bonuses'];
}
$pdfCoupon = trim((string) ($bookingData['coupon'] ?? $bookingData['draft']['coupon'] ?? ''));

// Ticket types mapping
$ticketTypesById = [];
foreach (($bookingData['draft']['selected_sailing']['shipping_company']['ticket_types'] ?? []) as $tt) {
    if (is_array($tt) && isset($tt['id'])) {
        $ticketTypesById[(int) $tt['id']] = $tt;
    }
}
$ticketLabel = static function (int $typeId) use ($ticketTypesById): string {
    if ($typeId <= 0) {
        return '';
    }
    $tt = $ticketTypesById[$typeId] ?? null;
    if (!$tt) {
        return (string) $typeId;
    }
    $name = trim((string) ($tt['name'] ?? ''));
    $desc = trim((string) ($tt['description'] ?? ''));
    return $desc !== '' ? ($name . ' — ' . $desc) : ($name !== '' ? $name : (string) $typeId);
};

$pdfLinkedPassengerName = static function (int $pid) use ($travellersData): string {
    if ($pid <= 0) {
        return '';
    }
    foreach ($travellersData as $t) {
        if (!is_array($t)) {
            continue;
        }
        if ((int) ($t['id'] ?? 0) === $pid) {
            return trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
        }
    }
    return '';
};

// Passenger normalization
$passengerList = [];
$paxCounters = ['Adult' => 0, 'Child' => 0, 'Infant' => 0];

if (!empty($travellersData) && is_array($travellersData)) {
    foreach ($travellersData as $key => $t) {
        if (!is_array($t)) {
            continue;
        }

        $title = trim($t['title'] ?? '');
        $fName = trim($t['first_name'] ?? '');
        $lName = trim($t['last_name'] ?? '');
        $fullName = trim(($title !== '' ? $title . ' ' : '') . $fName . ' ' . $lName);
        if (empty($fullName) && !empty($t['name'])) {
            $fullName = trim($t['name']);
        }
        if (empty($fullName)) {
            continue;
        }

        $tType = 'Adult';
        if (stripos((string) $key, 'child') !== false || strtolower($t['type'] ?? '') === 'child') {
            $tType = 'Child';
        } elseif (stripos((string) $key, 'infant') !== false || strtolower($t['type'] ?? '') === 'infant') {
            $tType = 'Infant';
        }

        $paxCounters[$tType] = ($paxCounters[$tType] ?? 0) + 1;
        $typeLabel = $tType . ' ' . $paxCounters[$tType];

        $passengerList[] = [
            'name' => $fullName,
            'first_name' => $fName,
            'last_name' => $lName,
            'type' => $tType,
            'type_label' => $typeLabel,
            'seat' => $t['seat'] ?? $t['cabin'] ?? $t['accommodation'] ?? '',
            'nationality' => $t['nationality'] ?? $t['country'] ?? '',
            'passport' => $t['passport'] ?? $t['identity_number'] ?? $t['id_number'] ?? '',
            'email' => $t['email'] ?? '',
            'phone' => $t['phone'] ?? ''
        ];
    }
}

// Fallback to booking fields if no travellers parsed
if (empty($passengerList)) {
    $leadFullName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
    if (empty($leadFullName)) {
        $leadFullName = 'Primary Passenger';
    }
    $passengerList[] = [
        'name' => $leadFullName,
        'first_name' => $booking['first_name'] ?? '',
        'last_name' => $booking['last_name'] ?? '',
        'type' => 'Adult',
        'type_label' => 'Adult 1',
        'seat' => $bookingData['deck'] ?? 'Standard Deck / Seat',
        'nationality' => $booking['country'] ?? '',
        'passport' => '',
        'email' => $booking['email'] ?? '',
        'phone' => $booking['phone'] ?? ''
    ];
    $paxCounters['Adult'] = 1;
}

// Ports & Route detection
$firstSailing = $sailings[0] ?? [];
$depPort = !empty($firstSailing['departure_port_id'])
    ? $portName((int) $firstSailing['departure_port_id'])
    : (!empty($bookingData['departure_port']) ? (string) $bookingData['departure_port'] : 'Departure Port');

$arrPort = !empty($firstSailing['arrival_port_id'])
    ? $portName((int) $firstSailing['arrival_port_id'])
    : (!empty($firstSailing['destination_port_id'])
        ? $portName((int) $firstSailing['destination_port_id'])
        : (!empty($bookingData['arrival_port']) ? (string) $bookingData['arrival_port'] : 'Arrival Port'));

$routeTitle = $depPort . ' → ' . $arrPort;
if (!empty($bookingData['title']) && empty($firstSailing['departure_port_id'])) {
    $routeTitle = (string) $bookingData['title'];
}

$vesselName = (string) (
    $firstSailing['shipping_company']['name']
    ?? $firstSailing['vessel_name']
    ?? $bookingData['vessel_name']
    ?? $bookingData['operator']
    ?? 'Ferry Operator'
);

$depDT = $firstSailing['departure_datetime'] ?? $bookingData['departure_date'] ?? $booking['booking_date'] ?? '';
$depDateFormatted = $depDT ? date('D, M d, Y', strtotime($depDT)) : 'N/A';
$depTimeFormatted = !empty($firstSailing['departure_datetime'])
    ? date('h:i A', strtotime($firstSailing['departure_datetime']))
    : (!empty($bookingData['departure_time']) ? (string) $bookingData['departure_time'] : '');

$arrDT = $firstSailing['arrival_datetime'] ?? '';
$arrTimeFormatted = $arrDT ? date('h:i A', strtotime($arrDT)) : (!empty($bookingData['arrival_time']) ? (string) $bookingData['arrival_time'] : '');

$durationStr = (string) ($firstSailing['duration'] ?? $bookingData['duration'] ?? 'Direct Route');

// Lead contact info
$leadName = $passengerList[0]['name'] ?? trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
$leadEmail = $passengerList[0]['email'] ?: ($booking['email'] ?? '');
$phoneCode = $booking['phone_country_code'] ?? '';
$phoneNum = $passengerList[0]['phone'] ?: ($booking['phone'] ?? '');
$leadPhoneFormatted = ($phoneCode ? '+' . ltrim($phoneCode, '+') . ' ' : '') . $phoneNum;
$leadPhoneFormatted = trim($leadPhoneFormatted);

// Pricing
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

$adultsCount = (int) (!empty($booking['adults']) ? $booking['adults'] : max(1, $paxCounters['Adult']));
$childsCount = (int) ($booking['childs'] ?? $paxCounters['Child']);
$infantsCount = (int) ($booking['infants'] ?? $paxCounters['Infant']);

// Live Functional QR Code pointing to live booking URL
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/ferries/' . $invoiceId);

// Logo path
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
    <title>Ferry Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* Top Header */
        .header-table {
            margin-bottom: 8px;
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
        }

        .qr-box {
            display: inline-block;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 2px;
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
            margin: 8px 0 10px 0;
        }

        .divider-solid {
            border-top: 1px solid #e2e8f0;
            margin: 8px 0;
        }

        /* Passenger & PNR */
        .meta-label {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
        }

        .meta-value-right {
            font-size: 10pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            letter-spacing: 0.5px;
        }

        .lead-contact-line {
            font-size: 7.5pt;
            color: #334155;
            margin-top: 3px;
        }

        .contact-key {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .contact-val {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0f172a;
        }

        .contact-sep {
            color: #cbd5e1;
            font-weight: bold;
            margin: 0 5px;
        }

        /* Ferry Box */
        .service-box {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 12px;
            background: #ffffff;
            margin-top: 8px;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }

        .box-title {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
            line-height: 1.2;
        }

        .box-subtitle {
            font-size: 7.5pt;
            color: #64748b;
            margin-top: 2px;
        }

        .service-badge {
            display: inline-block;
            font-size: 6.5pt;
            font-weight: bold;
            color: #0284c7;
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 4px;
            padding: 2px 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .grid-label {
            font-size: 6.3pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 2px;
        }

        .grid-value {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0f172a;
        }

        /* Passenger Details Box */
        .pax-card {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .pax-header {
            background: #0b2545;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
            padding: 5px 12px;
        }

        .pax-header table {
            width: 100%;
            border: none;
            background: transparent;
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
            padding: 2px 8px 6px 8px;
        }

        .pax-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .pax-table th {
            font-size: 6.2pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 4px 4px 3px 4px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .pax-table td {
            font-size: 7.2pt;
            color: #0f172a;
            padding: 4px 4px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .pax-num {
            color: #94a3b8;
            font-weight: bold;
            font-size: 6.8pt;
        }

        .pax-name {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0f172a;
        }

        .pax-meta-sub {
            font-size: 6.2pt;
            color: #64748b;
            margin-top: 1px;
        }

        .pax-type-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            padding: 1px 4px;
            font-size: 6.5pt;
            font-weight: bold;
        }

        .seat-tag {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
            border-radius: 3px;
            padding: 1px 4px;
            font-size: 6.5pt;
            font-weight: bold;
        }

        /* Fare Details Box */
        .fare-card {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .fare-header {
            background: #0b2545;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
            padding: 5px 12px;
        }

        .fare-header table {
            width: 100%;
            border: none;
            background: transparent;
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
            padding: 6px 12px 6px 12px;
        }

        .fare-item-label {
            font-size: 7.5pt;
            color: #334155;
            font-weight: bold;
            padding: 2px 0;
        }

        .fare-item-val {
            font-size: 7.5pt;
            color: #0f172a;
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

        /* Wish Message Banner */
        .wish-banner {
            background: #f0f7ff;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            padding: 5px 12px;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }

        .wish-text {
            font-size: 7.2pt;
            font-weight: bold;
            color: #1e3a8a;
            line-height: 1.3;
        }

        /* Important Information / Instructions */
        .instructions-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 7px 12px;
            margin-top: 0;
            page-break-inside: avoid;
        }

        .instructions-title {
            font-size: 7pt;
            font-weight: bold;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            padding-bottom: 3px;
            border-bottom: 1px solid #e2e8f0;
        }

        .instruction-item {
            font-size: 6.3pt;
            color: #475569;
            line-height: 1.35;
            margin-bottom: 2px;
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

        <!-- 1. Top Header Row (Logo & Booking Reference on Left + QR Code on Right) -->
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
                        <div class="meta-label" style="text-align: right;">PNR</div>
                        <div class="meta-value-right"><?= htmlspecialchars($pnr) ?></div>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <!-- 3. Primary Ferry Route Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($routeTitle) ?></div>
                        <div class="box-subtitle"><?= htmlspecialchars($vesselName) ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge">FERRY VOYAGE</span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid: DATE, DEPARTURE, DURATION, PASSENGERS -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 28%; vertical-align: top;">
                        <div class="grid-label">DEPARTURE DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($depDateFormatted) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">DEPARTURE TIME</div>
                        <div class="grid-value"><?= htmlspecialchars($depTimeFormatted ?: '-') ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">DURATION</div>
                        <div class="grid-value"><?= htmlspecialchars($durationStr) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">PASSENGERS</div>
                        <div class="grid-value"><?= $adultsCount ?> Adult<?= $adultsCount > 1 ? 's' : '' ?><?php if ($childsCount > 0): ?>, <?= $childsCount ?> Chd<?php endif; ?></div>
                    </td>
                </tr>
            </table>

            <?php if (!empty($sailings)): ?>
                <?php foreach ($sailings as $sIdx => $s):
                    $sDepDT = $s['departure_datetime'] ?? '';
                    $sArrDT = $s['arrival_datetime'] ?? '';
                    $sDepTime = $sDepDT ? date('h:i A', strtotime($sDepDT)) : '';
                    $sArrTime = $sArrDT ? date('h:i A', strtotime($sArrDT)) : '';
                    $sDepPort = !empty($s['departure_port_id']) ? $portName((int) $s['departure_port_id']) : $depPort;
                    $sArrPort = !empty($s['arrival_port_id']) ? $portName((int) $s['arrival_port_id']) : (!empty($s['destination_port_id']) ? $portName((int) $s['destination_port_id']) : $arrPort);
                    $sLoc = $locators[$sIdx] ?? '';
                ?>
                    <div class="divider-solid"></div>
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 42%; vertical-align: middle;">
                                <div style="font-size: 8.5pt; font-weight: bold; color: #0f172a;"><?= htmlspecialchars($sDepPort) ?></div>
                                <?php if ($sDepTime): ?>
                                    <div style="font-size: 9.5pt; font-weight: bold; color: #0b2545;"><?= htmlspecialchars($sDepTime) ?></div>
                                <?php endif; ?>
                                <div style="font-size: 6.2pt; color: #64748b;"><?= $sIdx === 0 ? 'Outbound Departure' : 'Return Departure' ?></div>
                            </td>
                            <td style="width: 16%; vertical-align: middle; text-align: center;">
                                <div style="font-size: 13pt; color: #0284c7; font-weight: bold;">→</div>
                                <?php if (!empty($sLoc)): ?>
                                    <div style="font-size: 5.8pt; color: #64748b;">Loc: <?= htmlspecialchars($sLoc) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="width: 42%; text-align: right; vertical-align: middle;">
                                <div style="font-size: 8.5pt; font-weight: bold; color: #0f172a;"><?= htmlspecialchars($sArrPort) ?></div>
                                <?php if ($sArrTime): ?>
                                    <div style="font-size: 9.5pt; font-weight: bold; color: #0b2545;"><?= htmlspecialchars($sArrTime) ?></div>
                                <?php endif; ?>
                                <div style="font-size: 6.2pt; color: #64748b;"><?= $sIdx === 0 ? 'Outbound Arrival' : 'Return Arrival' ?></div>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 4. Passenger Details Card Box -->
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
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 42%; text-align: left;">PASSENGER NAME</th>
                            <th style="width: 16%; text-align: center;">TYPE</th>
                            <th style="width: 22%; text-align: center;">SEAT / CABIN</th>
                            <th style="width: 15%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passengerList as $pIdx => $pax): 
                            $numStr = str_pad((string)($pIdx + 1), 2, '0', STR_PAD_LEFT);
                            $pSubMeta = [];
                            if (!empty($pax['passport'])) {
                                $pSubMeta[] = 'ID: ' . htmlspecialchars($pax['passport']);
                            }
                            if (!empty($pax['nationality'])) {
                                $pSubMeta[] = htmlspecialchars($pax['nationality']);
                            }
                        ?>
                            <tr>
                                <td class="pax-num" style="text-align: center;"><?= $numStr ?></td>
                                <td class="pax-name" style="text-align: left;">
                                    <?= htmlspecialchars($pax['name']) ?>
                                    <?php if (!empty($pSubMeta)): ?>
                                        <div class="pax-meta-sub"><?= implode(' • ', $pSubMeta) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><span class="pax-type-badge"><?= htmlspecialchars($pax['type_label']) ?></span></td>
                                <td style="text-align: center;">
                                    <?php if (!empty($pax['seat'])): ?>
                                        <span class="seat-tag"><?= htmlspecialchars($pax['seat']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 6.8pt;">Deck / Standard</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">Confirmed</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Vehicles Details (if present) -->
        <?php if (!empty($pdfVehicles)): ?>
            <div class="pax-card">
                <div class="pax-header">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 60%; vertical-align: middle;">
                                <div class="pax-header-title">VEHICLES &amp; TRANSPORT</div>
                            </td>
                            <td style="width: 40%; text-align: right; vertical-align: middle;">
                                <div class="pax-header-meta"><?= count($pdfVehicles) ?> VEHICLE<?= count($pdfVehicles) > 1 ? 'S' : '' ?></div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="pax-body">
                    <table class="pax-table">
                        <thead>
                            <tr>
                                <th style="width: 6%; text-align: center;">#</th>
                                <th style="width: 34%; text-align: left;">VEHICLE TYPE</th>
                                <th style="width: 30%; text-align: center;">LICENSE PLATE</th>
                                <th style="width: 30%; text-align: center;">BRAND / MODEL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdfVehicles as $vi => $v): ?>
                                <tr>
                                    <td class="pax-num" style="text-align: center;"><?= str_pad((string)($vi + 1), 2, '0', STR_PAD_LEFT) ?></td>
                                    <td class="pax-name" style="text-align: left;"><?= htmlspecialchars($ticketLabel((int)($v['ticket_type_id'] ?? 0)) ?: 'Standard Vehicle') ?></td>
                                    <td style="text-align: center; font-family: monospace; font-weight: bold;"><?= htmlspecialchars((string)($v['license_plate'] ?? 'N/A')) ?></td>
                                    <td style="text-align: center; color: #475569;"><?= htmlspecialchars((string)($v['brand'] ?? 'N/A')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- 5b. Pets & Animals (if present) -->
        <?php if (!empty($pdfPets)): ?>
            <div class="pax-card">
                <div class="pax-header">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 60%; vertical-align: middle;">
                                <div class="pax-header-title">PETS &amp; SERVICE ANIMALS</div>
                            </td>
                            <td style="width: 40%; text-align: right; vertical-align: middle;">
                                <div class="pax-header-meta"><?= count($pdfPets) ?> PET<?= count($pdfPets) > 1 ? 'S' : '' ?></div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="pax-body">
                    <table class="pax-table">
                        <thead>
                            <tr>
                                <th style="width: 6%; text-align: center;">#</th>
                                <th style="width: 44%; text-align: left;">PET CATEGORY</th>
                                <th style="width: 50%; text-align: left;">NAME / DETAILS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdfPets as $pi => $pet): ?>
                                <tr>
                                    <td class="pax-num" style="text-align: center;"><?= str_pad((string)($pi + 1), 2, '0', STR_PAD_LEFT) ?></td>
                                    <td class="pax-name" style="text-align: left;"><?= htmlspecialchars($ticketLabel((int)($pet['ticket_type_id'] ?? 0)) ?: 'Pet Ticket') ?></td>
                                    <td style="text-align: left; color: #334155; font-size: 7.2pt;"><?= htmlspecialchars((string)($pet['name'] ?? 'Registered Pet')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- 6. Special Requests / Notes (if present) -->
        <?php if (!empty($booking['special_requests'])): ?>
            <div style="border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 8px; padding: 6px 10px; background: #ffffff;">
                <div style="font-size: 6.8pt; font-weight: bold; color: #0b2545; text-transform: uppercase; margin-bottom: 2px;">SPECIAL REQUESTS</div>
                <div style="font-size: 6.8pt; color: #334155;"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
            </div>
        <?php endif; ?>

        <!-- 6b. Applied Discounts & Bonuses (if present) -->
        <?php if (!empty($pdfBonuses) || $pdfCoupon !== ''): ?>
            <div style="border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 6px; padding: 5px 10px; margin-bottom: 6px; page-break-inside: avoid;">
                <div style="font-size: 6.5pt; font-weight: bold; color: #15803d; text-transform: uppercase;">DISCOUNT / BONUS APPLIED</div>
                <?php if ($pdfCoupon !== ''): ?>
                    <div style="font-size: 6.8pt; color: #166534; margin-top: 1px;">Coupon Code: <strong><?= htmlspecialchars($pdfCoupon) ?></strong></div>
                <?php endif; ?>
                <?php foreach ($pdfBonuses as $bonus): ?>
                    <div style="font-size: 6.8pt; color: #166534; margin-top: 1px;">
                        <?= htmlspecialchars((string)($bonus['name'] ?? 'Discount')) ?><?= !empty($bonus['description']) ? ' — ' . htmlspecialchars((string)$bonus['description']) : '' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- 7. Fare Details Card Box -->
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
                        <td class="fare-item-label">Ferry Voyage Fare (<?= count($passengerList) ?> Passenger<?= count($passengerList) > 1 ? 's' : '' ?><?= count($pdfVehicles) > 0 ? ', ' . count($pdfVehicles) . ' Vehicle(s)' : '' ?><?= count($pdfPets) > 0 ? ', ' . count($pdfPets) . ' Pet(s)' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Port Taxes &amp; Maritime Fees</td>
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

        <!-- 8. Important Information (Original) -->
        <div class="instructions-card">
            <div class="instructions-title">Important Information</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• Please arrive at the port at least 1 hour before departure</div>
                <div class="instruction-item">• Valid photo identification is required for all passengers</div>
                <div class="instruction-item">• This booking confirmation must be presented at check-in</div>
                <div class="instruction-item">• For sailing status updates, contact the ferry operator directly</div>
            </div>
        </div>

        <!-- 9. Footer (Original) -->
        <?php
        $footerEmail = $businessEmail ?? $settings['email_sender_email'] ?? 'support@phptravels.com';
        $footerPhone = $businessPhone ?? $settings['site_phone'] ?? '';
        ?>
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars((string) $footerEmail) ?><?php if (!empty($footerPhone)): ?> | <?= htmlspecialchars((string) $footerPhone) ?><?php endif; ?>
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
