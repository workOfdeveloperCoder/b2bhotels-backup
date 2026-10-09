<?php
/**
 * ============================================================================
 * CAR RENTAL & TRANSFER BOOKING VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Aligned with unified travel voucher design (Flights, Stays, Visa, Tours, eSIM)
 * Preserves all essential driver details, rental dates, locations, and pricing
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Car Data dynamically
$bookingData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);
$carData = (array) ($bookingData['car_data'] ?? []);
$searchParams = (array) ($bookingData['search_params'] ?? []);
$guestDetails = (array) ($bookingData['guest_details'] ?? []);

$serviceType = strtolower((string) ($bookingData['service_type'] ?? $searchParams['service_type'] ?? 'rental'));
$isTransfer = ($serviceType === 'transfer');

$carName = (string) ($carData['name'] ?? $carData['vehicle_name'] ?? $bookingData['car_name'] ?? $bookingData['name'] ?? $bookingData['title'] ?? ($isTransfer ? 'Private Transfer' : 'Car Rental'));
$carCategory = (string) ($carData['category'] ?? $carData['type'] ?? $bookingData['car_type'] ?? ($isTransfer ? 'Transfer Vehicle' : 'Rental Vehicle'));

// Vehicle features if available in response
$vehicleSpecs = [];
if (!empty($carData['transmission'])) {
    $vehicleSpecs[] = $carData['transmission'];
}
if (!empty($carData['seats'])) {
    $vehicleSpecs[] = $carData['seats'] . ' Seats';
}
if (!empty($carData['fuel_type'])) {
    $vehicleSpecs[] = $carData['fuel_type'];
}
if (!empty($vehicleSpecs)) {
    $carCategory .= ' · ' . implode(' · ', $vehicleSpecs);
}

// Locations resolution
$pickupLocation = trim((string) (
    $searchParams['pickup_location']
    ?? $carData['pickup_location']
    ?? $bookingData['pickup_location']
    ?? $bookingData['pickup']
    ?? $bookingData['location']
    ?? $bookingData['city']
    ?? ''
));

$dropoffLocation = trim((string) (
    $searchParams['dropoff_location']
    ?? $carData['dropoff_location']
    ?? $bookingData['dropoff_location']
    ?? $bookingData['dropoff']
    ?? $pickupLocation
));

$pickupLocationClean = (!empty($pickupLocation) && $pickupLocation !== '-' && strtolower($pickupLocation) !== 'null') ? ucwords(str_replace(['-', '_'], ' ', $pickupLocation)) : 'N/A';
$dropoffLocationClean = (!empty($dropoffLocation) && $dropoffLocation !== '-' && strtolower($dropoffLocation) !== 'null') ? ucwords(str_replace(['-', '_'], ' ', $dropoffLocation)) : 'N/A';

// Date & Time extraction helper
$parseCarDateTime = function ($dateVal, $timeVal = '') {
    if (empty($dateVal) || $dateVal === '-' || strtolower((string)$dateVal) === 'tbd' || strtolower((string)$dateVal) === 'null' || strtolower((string)$dateVal) === 'n/a') {
        return null;
    }
    $cleanDate = preg_replace('/\s+at\s+/i', ' ', trim((string) $dateVal));
    $cleanDate = str_replace('/', '-', $cleanDate);
    if (!empty($timeVal) && !preg_match('/\d{1,2}:\d{2}/', $cleanDate)) {
        $cleanDate .= ' ' . trim((string) $timeVal);
    }
    $ts = strtotime($cleanDate);
    return ($ts && $ts > 0) ? $ts : null;
};

// Pickup Date & Time resolution
$pickupRaw = $searchParams['pickup_datetime']
    ?? $carData['pickup_date']
    ?? $searchParams['pickup_date']
    ?? $bookingData['pickup_datetime']
    ?? $bookingData['pickup_date']
    ?? $searchParams['date']
    ?? $bookingData['date']
    ?? $booking['checkin']
    ?? '';

$pickupTimeRaw = $searchParams['pickup_time']
    ?? $bookingData['pickup_time']
    ?? $searchParams['time']
    ?? $bookingData['time']
    ?? '';

$pickupTs = $parseCarDateTime($pickupRaw, $pickupTimeRaw);

// Dropoff Date & Time resolution
$dropoffRaw = $searchParams['dropoff_datetime']
    ?? $carData['return_date']
    ?? $carData['dropoff_date']
    ?? $searchParams['return_date']
    ?? $searchParams['dropoff_date']
    ?? $bookingData['dropoff_datetime']
    ?? $bookingData['return_date']
    ?? $bookingData['dropoff_date']
    ?? $booking['checkout']
    ?? '';

$dropoffTimeRaw = $searchParams['return_time']
    ?? $searchParams['dropoff_time']
    ?? $bookingData['return_time']
    ?? $bookingData['dropoff_time']
    ?? '';

$dropoffTs = $parseCarDateTime($dropoffRaw, $dropoffTimeRaw);

// Format display strings dynamically
if ($pickupTs) {
    // If exact time was detected (not default midnight unless explicit), include it
    $hasTime = preg_match('/\d{1,2}:\d{2}/', (string) $pickupRaw) || !empty($pickupTimeRaw);
    $formattedPickup = $hasTime ? date('D, d M Y \a\t g:i A', $pickupTs) : date('D, d M Y', $pickupTs);
} else {
    $formattedPickup = (!empty($pickupRaw) && $pickupRaw !== '-' && strtolower((string)$pickupRaw) !== 'null') ? (string) $pickupRaw : 'N/A';
}

if ($dropoffTs) {
    $hasTime = preg_match('/\d{1,2}:\d{2}/', (string) $dropoffRaw) || !empty($dropoffTimeRaw);
    $formattedDropoff = $hasTime ? date('D, d M Y \a\t g:i A', $dropoffTs) : date('D, d M Y', $dropoffTs);
} else {
    $formattedDropoff = (!empty($dropoffRaw) && $dropoffRaw !== '-' && strtolower((string)$dropoffRaw) !== 'null') ? (string) $dropoffRaw : 'N/A';
}

// Duration (Days) calculation
$days = 1;
if ($pickupTs && $dropoffTs && $dropoffTs > $pickupTs) {
    $days = max(1, (int) ceil(($dropoffTs - $pickupTs) / 86400));
} elseif (!empty($bookingData['days']) && is_numeric($bookingData['days'])) {
    $days = (int) $bookingData['days'];
} elseif (!empty($bookingData['duration']) && is_numeric($bookingData['duration'])) {
    $days = (int) $bookingData['duration'];
}

// 2. Lead Driver / Customer Details
$leadName = '';
$leadEmail = '';
$leadPhone = '';
$leadPhoneCode = '';
$driverNat = '';
$driverLicense = '';
$driverPassport = '';

if (!empty($travellersData) && is_array($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadName = trim(($firstTraveller['title'] ?? '') . ' ' . ($firstTraveller['first_name'] ?? '') . ' ' . ($firstTraveller['last_name'] ?? ''));
        $leadEmail = trim((string) ($firstTraveller['email'] ?? ''));
        $leadPhone = trim((string) ($firstTraveller['phone'] ?? ''));
        $driverNat = trim((string) ($firstTraveller['nationality'] ?? ''));
        $driverLicense = trim((string) ($firstTraveller['license_number'] ?? $firstTraveller['license'] ?? ''));
        $driverPassport = trim((string) ($firstTraveller['passport_number'] ?? $firstTraveller['passport'] ?? ''));
    }
}

if (empty($leadName)) {
    $leadName = trim(($guestDetails['title'] ?? '') . ' ' . ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
}
if (empty($leadName)) {
    $leadName = 'Valued Customer';
}

if (empty($leadEmail)) {
    $leadEmail = trim((string) ($booking['email'] ?? ''));
}
if (empty($leadPhone)) {
    $leadPhone = trim((string) ($booking['phone'] ?? ''));
    $leadPhoneCode = trim((string) ($booking['phone_country_code'] ?? ''));
}
if (empty($driverNat)) {
    $driverNat = trim((string) ($guestDetails['nationality'] ?? $booking['nationality'] ?? ''));
}
if (empty($driverLicense)) {
    $driverLicense = trim((string) ($guestDetails['license_number'] ?? $guestDetails['driving_license'] ?? $bookingData['license_number'] ?? ''));
}
if (empty($driverPassport)) {
    $driverPassport = trim((string) ($guestDetails['passport'] ?? ''));
}

if (strlen($driverNat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
    $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($driverNat)]);
    if (!empty($cName)) {
        $driverNat = $cName;
    }
}

$leadPhoneFormatted = '';
if (!empty($leadPhone)) {
    $leadPhoneFormatted = (!empty($leadPhoneCode) ? ('+' . ltrim($leadPhoneCode, '+') . ' ') : '') . $leadPhone;
}

$pnr = trim((string) ($booking['pnr'] ?? ''));

// 3. Payment Gateway & Status resolution
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

// 4. Pricing & Financials
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

// Functional Live QR Code pointing to live invoice URL
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/cars/' . $invoiceId);

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

$cancellationPolicy = trim((string) ($carData['cancellation_policy'] ?? $bookingData['cancellation_policy'] ?? ''));
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Car Rental Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* 2. Customer & PNR Row */
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

        /* 4. Details Card (Driver & Vehicle Info) */
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

        <!-- 2. Customer & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label">MAIN DRIVER / CUSTOMER</div>
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
                        <div class="meta-label" style="text-align: right;">PNR / REF</div>
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

        <!-- 3. Car Rental / Transfer Service Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($carName) ?></div>
                        <div class="box-subtitle"><?= htmlspecialchars($carCategory) ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge"><?= $isTransfer ? 'TRANSFER SERVICE' : 'RENTAL VEHICLE' ?></span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 3-Column Grid: Pick-up, Drop-off, Duration -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 38%; vertical-align: top;">
                        <div class="grid-label">PICK-UP</div>
                        <div class="grid-value"><?= htmlspecialchars($formattedPickup) ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($pickupLocationClean) ?></div>
                    </td>
                    <td style="width: 38%; vertical-align: top;">
                        <div class="grid-label">DROP-OFF</div>
                        <div class="grid-value"><?= htmlspecialchars($formattedDropoff) ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($dropoffLocationClean) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">DURATION</div>
                        <div class="grid-value"><?= $days ?> Day<?= $days > 1 ? 's' : '' ?></div>
                        <div class="grid-sub"><?= $isTransfer ? 'Direct Route' : 'Rental Duration' ?></div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 4. Driver & Reservation Details Card -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">DRIVER &amp; RESERVATION DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta">CONFIRMED RESERVATION</div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 6%; text-align: center;">#</th>
                            <th style="width: 44%; text-align: left;">DRIVER NAME</th>
                            <th style="width: 32%; text-align: left;">CONTACT / IDENTIFICATION</th>
                            <th style="width: 18%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $driverSubMeta = [];
                        if (!empty($driverNat)) {
                            $driverSubMeta[] = htmlspecialchars($driverNat);
                        }
                        if (!empty($driverLicense)) {
                            $driverSubMeta[] = 'License: ' . htmlspecialchars($driverLicense);
                        } elseif (!empty($driverPassport)) {
                            $driverSubMeta[] = 'ID/Passport: ' . htmlspecialchars($driverPassport);
                        }
                        ?>
                        <tr>
                            <td class="pax-num">01</td>
                            <td>
                                <div class="pax-name"><?= htmlspecialchars($leadName) ?></div>
                                <?php if (!empty($driverSubMeta)): ?>
                                    <div class="pax-meta-sub"><?= implode(' • ', $driverSubMeta) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($leadEmail) ? htmlspecialchars($leadEmail) : '-' ?>
                                <?= !empty($leadPhoneFormatted) ? '<br><span style="font-size: 6.5pt; color: #64748b;">' . htmlspecialchars($leadPhoneFormatted) . '</span>' : '' ?>
                            </td>
                            <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">
                                Confirmed
                            </td>
                        </tr>
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
                        <td class="fare-item-label"><?= $isTransfer ? 'Transfer Charges' : ('Rental Charges (' . $days . ' Day' . ($days > 1 ? 's' : '') . ')') ?></td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Fees</td>
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
                <div class="instruction-item">• Valid driver's license and credit card required at pickup</div>
                <div class="instruction-item">• Main driver must be present at pickup</div>
                <div class="instruction-item">• Check vehicle for existing damage before leaving the lot</div>
                <div class="instruction-item">• Return vehicle with the same fuel level as pickup</div>
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
