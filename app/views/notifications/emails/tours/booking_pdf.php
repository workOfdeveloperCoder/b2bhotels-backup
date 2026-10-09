<?php
/**
 * ============================================================================
 * TOURS & ACTIVITIES BOOKING VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Features:
 * - Brand / Agency Logo on top left
 * - Booking Reference in header (9pt matching unified vouchers)
 * - Dynamic QR Code on top right linking to live invoice URL
 * - Unpaid payment warning alert (dynamic)
 * - Formatted dynamic tour itinerary grids (Date, Duration, Travelers, Meeting/Pickup)
 * - Tour highlights and inclusions breakdown (dynamic)
 * - Multi-Traveler details table (100% dynamic, zero hardcoding)
 * - Preserved Special requests
 * - Itemized Fare Details breakdown & Total
 * - Tour Important Information card & dynamic Footer
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Tour Data dynamically
$tourData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$tourName = (string) ($tourData['title'] ?? $tourData['name'] ?? $tourData['tour_name'] ?? $tourData['tour']['name'] ?? 'Tour Booking');
$location = trim((string) ($tourData['location'] ?? $tourData['city'] ?? $tourData['tour_location'] ?? $tourData['tour']['location'] ?? ''));
$tourType = strtoupper(trim((string) ($tourData['tour_type'] ?? $tourData['category'] ?? 'TOUR & ACTIVITY')));

$startDateRaw = $tourData['date'] ?? $tourData['start_date'] ?? $booking['booking_date'] ?? $booking['created_at'] ?? '';
$formattedDate = !empty($startDateRaw) && strtotime(str_replace('/', '-', (string) $startDateRaw)) ? date('D, d M Y', strtotime(str_replace('/', '-', (string) $startDateRaw))) : 'Confirmed in Voucher';
$duration = (string) ($tourData['duration'] ?? '1 Day');

$meetingPoint = trim((string) ($tourData['meeting_point'] ?? $tourData['pickup_location'] ?? 'Confirmed in voucher'));
$inclusions = $tourData['inclusions'] ?? $tourData['highlights'] ?? [];
if (!is_array($inclusions)) {
    $inclusions = [];
}

// 2. Lead Traveler & Contact
$leadTravelerName = '';
$leadEmail = '';
$leadPhone = '';
$leadPhoneCode = '';

if (!empty($travellersData['primary_guest']) && is_array($travellersData['primary_guest'])) {
    $pg = $travellersData['primary_guest'];
    $leadTravelerName = trim(($pg['title'] ?? '') . ' ' . ($pg['first_name'] ?? '') . ' ' . ($pg['last_name'] ?? ''));
    $leadEmail = trim((string) ($pg['email'] ?? ''));
    $leadPhone = trim((string) ($pg['phone'] ?? ''));
    $leadPhoneCode = trim((string) ($pg['country_code'] ?? $pg['phone_country_code'] ?? ''));
}

if (empty($leadTravelerName)) {
    $leadTravelerName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
}
if (empty($leadEmail)) {
    $leadEmail = trim((string) ($booking['email'] ?? ''));
}
if (empty($leadPhone)) {
    $leadPhone = trim((string) ($booking['phone'] ?? ''));
    $leadPhoneCode = trim((string) ($booking['phone_country_code'] ?? ''));
}

// 3. Normalize All Travelers for Dynamic Multi-Traveler Table
$travelerList = [];
$paxCounters = ['Adult' => 0, 'Child' => 0, 'Infant' => 0];

// Extract traveler source array cleanly
$rawTravelers = [];
if (!empty($travellersData['travelers']) && is_array($travellersData['travelers'])) {
    $rawTravelers = $travellersData['travelers'];
} elseif (!empty($tourData['guest']) && is_array($tourData['guest'])) {
    $rawTravelers = $tourData['guest'];
} elseif (!empty($tourData['passengers']) && is_array($tourData['passengers'])) {
    $rawTravelers = $tourData['passengers'];
} elseif (!empty($bookingData['passengers']) && is_array($bookingData['passengers'])) {
    $rawTravelers = $bookingData['passengers'];
} elseif (!empty($travellersData) && is_array($travellersData)) {
    foreach ($travellersData as $k => $v) {
        if (in_array((string) $k, ['primary_guest', 'booking_for_someone_else'], true)) continue;
        if (is_array($v)) {
            $rawTravelers[$k] = $v;
        }
    }
}

if (!empty($rawTravelers)) {
    foreach ($rawTravelers as $k => $t) {
        if (!is_array($t)) continue;
        $kStr = (string) $k;
        $tType = 'Adult';
        if (stripos($kStr, 'child') !== false) {
            $tType = 'Child';
        } elseif (stripos($kStr, 'infant') !== false) {
            $tType = 'Infant';
        } elseif (!empty($t['type'])) {
            $typeStr = strtolower((string) $t['type']);
            if (in_array($typeStr, ['child', 'chd'], true)) $tType = 'Child';
            if (in_array($typeStr, ['infant', 'inf'], true)) $tType = 'Infant';
        } elseif (!empty($t['age']) && is_numeric($t['age'])) {
            if ((int) $t['age'] < 2) $tType = 'Infant';
            elseif ((int) $t['age'] < 12) $tType = 'Child';
        }

        $paxCounters[$tType]++;
        $tName = trim(($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
        if (empty($tName) && !empty($t['name'])) $tName = trim($t['name']);
        if (empty($tName)) $tName = 'Traveler ' . (count($travelerList) + 1);

        $nat = trim((string) ($t['nationality_name'] ?? $t['nationality'] ?? ''));
        if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
            if (!empty($cName)) {
                $nat = $cName;
            }
        }

        $travelerList[] = [
            'name' => $tName,
            'type_label' => $tType . ' ' . $paxCounters[$tType],
            'age' => $t['age'] ?? '',
            'passport' => $t['passport_number'] ?? $t['passport'] ?? '',
            'nationality' => $nat,
        ];
    }
}

if (empty($travelerList)) {
    $travelerList[] = [
        'name' => !empty($leadTravelerName) ? $leadTravelerName : 'Guest Traveler',
        'type_label' => 'Adult 1',
        'age' => '',
        'passport' => '',
        'nationality' => $booking['nationality'] ?? '',
    ];
    $paxCounters['Adult'] = max(1, $paxCounters['Adult']);
}

if (empty($leadTravelerName) && !empty($travelerList[0]['name'])) {
    $leadTravelerName = $travelerList[0]['name'];
}
if (empty($leadTravelerName)) {
    $leadTravelerName = 'Guest Traveler';
}

$leadPhoneFormatted = '';
if (!empty($leadPhone)) {
    $leadPhoneFormatted = (!empty($leadPhoneCode) ? ('+' . ltrim($leadPhoneCode, '+') . ' ') : '') . $leadPhone;
}

// PNR Reference
$pnr = trim((string) (
    $booking['pnr']
    ?? $tourData['pnr']
    ?? $tourData['booking_reference']
    ?? ''
));
if ($pnr === '-' || strtolower($pnr) === 'n/a' || strtolower($pnr) === 'null') {
    $pnr = '';
}

$adults = (int) (!empty($booking['adults']) ? $booking['adults'] : (!empty($tourData['total_adults']) ? $tourData['total_adults'] : (!empty($tourData['adults']) ? $tourData['adults'] : max(1, $paxCounters['Adult']))));
$children = (int) (!empty($booking['childs']) ? $booking['childs'] : (!empty($tourData['total_children']) ? $tourData['total_children'] : (!empty($tourData['children']) ? $tourData['children'] : $paxCounters['Child'])));
$infants = (int) (!empty($booking['infants']) ? $booking['infants'] : (!empty($tourData['total_infants']) ? $tourData['total_infants'] : (!empty($tourData['infants']) ? $tourData['infants'] : $paxCounters['Infant'])));

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
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/tours/' . $invoiceId);

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
    <title>Tour Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* Traveler & PNR */
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

        /* Payment Required Alert */
        .alert-box {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 7px 12px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .alert-title {
            font-size: 8pt;
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

        /* Tour Service Box */
        .tour-box {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 7px 12px;
            background: #ffffff;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .tour-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: #0b2545;
            line-height: 1.2;
        }

        .tour-location {
            font-size: 7.2pt;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
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
            font-size: 6.5pt;
            color: #64748b;
            margin-top: 1px;
        }

        .inclusions-title {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }

        .inclusion-item {
            font-size: 6.8pt;
            color: #334155;
            padding: 1px 0;
        }

        /* Traveler Details Card */
        .traveler-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
            background: #ffffff;
        }

        .traveler-header {
            background: #0b2545;
            padding: 5px 12px;
        }

        .traveler-header-title {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .traveler-header-meta {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-align: right;
            text-transform: uppercase;
        }

        .traveler-body {
            padding: 0;
        }

        .traveler-table {
            width: 100%;
            border-collapse: collapse;
        }

        .traveler-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 6pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 4px 8px;
            border-bottom: 1px solid #e2e8f0;
            border-top: none;
        }

        .traveler-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 7pt;
        }

        .traveler-table tr:last-child td {
            border-bottom: none;
        }

        .traveler-num {
            color: #64748b;
            font-size: 7pt;
            font-weight: bold;
        }

        .traveler-name {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0b2545;
        }

        .traveler-meta-sub {
            font-size: 6.2pt;
            color: #64748b;
            margin-top: 1px;
        }

        .traveler-type-badge {
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

        /* Note / Special Requests */
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 12px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .info-card-title {
            font-size: 6.8pt;
            font-weight: bold;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            padding-bottom: 2px;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-card-text {
            font-size: 6.8pt;
            color: #334155;
            line-height: 1.35;
        }

        /* Fare Details Box */
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
            padding-top: 4px;
        }

        .fare-total-val {
            font-size: 11.5pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            padding-top: 2px;
        }

        /* Important Information */
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

        <!-- 2. Lead Traveler & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label"><?= count($travelerList) > 1 ? 'LEAD TRAVELER' : 'TRAVELER' ?></div>
                    <div class="meta-value"><?= htmlspecialchars($leadTravelerName) ?></div>
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

        <!-- 3. Payment Status Alert (Dynamic) -->
        <?php if ($isUnpaid): ?>
            <div class="alert-box">
                <div class="alert-title">⚠ PAYMENT REQUIRED</div>
                <div class="alert-text">
                    Please complete your payment<?php if (!empty($gatewayName)): ?> using <strong><?= htmlspecialchars($gatewayName) ?></strong><?php endif; ?> to confirm your tour reservation.
                </div>
            </div>
        <?php endif; ?>

        <!-- 4. Tour Service Box -->
        <div class="tour-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="tour-title"><?= htmlspecialchars($tourName) ?></div>
                        <?php if ($location !== ''): ?>
                            <div class="tour-location"><?= htmlspecialchars($location) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge"><?= htmlspecialchars($tourType) ?></span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid: TOUR DATE, DURATION, TRAVELERS, MEETING / PICKUP -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 26%; vertical-align: top;">
                        <div class="grid-label">TOUR DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($formattedDate) ?></div>
                        <div class="grid-sub">Scheduled Date</div>
                    </td>
                    <td style="width: 20%; vertical-align: top;">
                        <div class="grid-label">DURATION</div>
                        <div class="grid-value"><?= htmlspecialchars($duration) ?></div>
                        <div class="grid-sub">Tour Duration</div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">TRAVELERS</div>
                        <div class="grid-value">
                            <?= $adults ?> Adult<?= $adults > 1 ? 's' : '' ?><?php if ($children > 0): ?>, <?= $children ?> Chd<?php endif; ?><?php if ($infants > 0): ?>, <?= $infants ?> Inf<?php endif; ?>
                        </div>
                        <div class="grid-sub">Total Guests</div>
                    </td>
                    <td style="width: 30%; vertical-align: top;">
                        <div class="grid-label">MEETING / PICKUP</div>
                        <div class="grid-value" style="font-size: 7.5pt;"><?= htmlspecialchars($meetingPoint) ?></div>
                        <div class="grid-sub">Pickup Location</div>
                    </td>
                </tr>
            </table>

            <!-- Inclusions / Highlights (Rendered only if dynamic data exists) -->
            <?php if (!empty($inclusions)): ?>
                <div class="divider-solid"></div>
                <div class="inclusions-title">INCLUSIONS &amp; HIGHLIGHTS</div>
                <table style="width: 100%;">
                    <?php 
                    $incChunks = array_chunk(array_slice($inclusions, 0, 6), 2);
                    foreach ($incChunks as $chunk): 
                    ?>
                        <tr>
                            <td style="width: 50%; padding-right: 6px;">
                                <div class="inclusion-item">✓ <?= htmlspecialchars($chunk[0]) ?></div>
                            </td>
                            <td style="width: 50%; padding-left: 6px;">
                                <?php if (isset($chunk[1])): ?>
                                    <div class="inclusion-item">✓ <?= htmlspecialchars($chunk[1]) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <!-- 5. Traveler Details Card (100% Dynamic, No Hardcoded Status) -->
        <div class="traveler-card">
            <div class="traveler-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="traveler-header-title">TRAVELER DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="traveler-header-meta"><?= count($travelerList) ?> TRAVELER<?= count($travelerList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="traveler-body">
                <table class="traveler-table">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 45%; text-align: left;">TRAVELER NAME</th>
                            <th style="width: 18%; text-align: center;">CATEGORY</th>
                            <th style="width: 16%; text-align: center;">AGE / DETAILS</th>
                            <th style="width: 16%; text-align: center;">NATIONALITY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($travelerList as $tIdx => $traveler): 
                            $numStr = str_pad((string)($tIdx + 1), 2, '0', STR_PAD_LEFT);
                            $ageDisplay = !empty($traveler['age']) ? ('Age ' . htmlspecialchars($traveler['age'])) : '-';
                            $natDisplay = !empty($traveler['nationality']) ? htmlspecialchars($traveler['nationality']) : '-';
                            $passportSub = !empty($traveler['passport']) ? ('Passport: ' . htmlspecialchars($traveler['passport'])) : '';
                        ?>
                            <tr>
                                <td style="width: 5%; text-align: center;" class="traveler-num"><?= $numStr ?></td>
                                <td style="width: 45%; text-align: left;">
                                    <div class="traveler-name"><?= htmlspecialchars($traveler['name']) ?></div>
                                    <?php if (!empty($passportSub)): ?>
                                        <div class="traveler-meta-sub"><?= $passportSub ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 18%; text-align: center;">
                                    <span class="traveler-type-badge"><?= htmlspecialchars($traveler['type_label']) ?></span>
                                </td>
                                <td style="width: 16%; text-align: center; color: #334155; font-size: 6.8pt; font-weight: 500;">
                                    <?= $ageDisplay ?>
                                </td>
                                <td style="width: 16%; text-align: center; color: #334155; font-size: 6.8pt; font-weight: 500;">
                                    <?= $natDisplay ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 6. Special Requests (if present) -->
        <?php if (!empty($booking['special_requests']) || !empty($tourData['special_requests'])): ?>
            <div class="info-card">
                <div class="info-card-title">SPECIAL REQUESTS</div>
                <div class="info-card-text">
                    <?= nl2br(htmlspecialchars((string) ($booking['special_requests'] ?: $tourData['special_requests']))) ?>
                </div>
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
                        <td class="fare-item-label">Tour Base Rate (<?= count($travelerList) ?> Traveler<?= count($travelerList) > 1 ? 's' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <?php if ($taxAmountRaw > 0): ?>
                        <tr>
                            <td class="fare-item-label">Taxes &amp; Fees</td>
                            <td class="fare-item-val"><?= number_format((float) ($pdfTaxAmount ?? $taxAmountRaw ?? 0), 2) ?></td>
                        </tr>
                    <?php endif; ?>
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

        <!-- 8. Important Information (Original 5 Points) -->
        <div class="instructions-card">
            <div class="instructions-title">Important Information</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• Please arrive at the meeting point 15 minutes before departure</div>
                <div class="instruction-item">• Present this voucher (digital or printed) to the tour guide</div>
                <div class="instruction-item">• Tours operate rain or shine unless otherwise notified</div>
                <div class="instruction-item">• Comfortable walking shoes are recommended</div>
                <div class="instruction-item">• For any emergency during the tour, contact our support line</div>
            </div>
        </div>

        <!-- 9. Footer -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail ?? $settings['email_sender_email'] ?? 'support@phptravels.com') ?><?php if (!empty($businessPhone ?? $settings['site_phone'])): ?> | <?= htmlspecialchars($businessPhone ?? $settings['site_phone']) ?><?php endif; ?>
            </div>
            <div class="footer-note">
                © <?= date('Y') ?> <?= htmlspecialchars(getBrandName($settings ?? null)) ?>. All rights reserved.<br>
                Booking Date: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'] ?? $booking['created_at'])) ?><br>
                This is an automated booking voucher. Please do not reply to this document.
            </div>
        </div>

    </div>

</body>

</html>