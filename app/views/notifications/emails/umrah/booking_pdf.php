<?php
/**
 * ============================================================================
 * UMRAH PACKAGE BOOKING VOUCHER PDF
 * ============================================================================
 * Standardized Umrah Package Booking & Pilgrimage Voucher
 * Aligned with unified travel voucher design (Visa, Rail, Ferries, Flights, Tours)
 * Features dynamic Makkah/Madinah accommodation, pilgrim details, and inclusions
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Umrah Data dynamically & safely
$bookingData = (isset($bookingData) && is_array($bookingData)) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = (isset($travellersData) && is_array($travellersData)) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$businessEmail = $businessEmail ?? ($settings['email_sender_email'] ?? 'support@phptravels.com');
$businessPhone = $businessPhone ?? ($settings['site_phone'] ?? '');

$umrahName = (string) ($bookingData['umrah_name'] ?? $bookingData['title'] ?? $bookingData['name'] ?? 'Premium Umrah Package');
$location = (string) ($bookingData['umrah_location'] ?? $bookingData['location'] ?? 'Makkah & Madinah, Saudi Arabia');
$startDate = (string) ($bookingData['start_date'] ?? $bookingData['date'] ?? '');
$formattedDate = !empty($startDate) ? date('D, M d, Y', strtotime($startDate)) : 'Confirmed upon arrival';
$duration = (string) ($bookingData['duration'] ?? (!empty($bookingData['nights']) ? $bookingData['nights'] . ' Nights' : 'Full Package'));

$adults = (int) ($booking['adults'] ?? $bookingData['total_adults'] ?? 1);
$children = (int) ($booking['childs'] ?? $bookingData['total_children'] ?? 0);
$totalPilgrims = max(1, $adults + $children);

// Format pilgrim count without repeating adults twice
if ($adults > 0 && $children > 0) {
    $pilgrimPaxText = $adults . ' Adult' . ($adults > 1 ? 's' : '') . ', ' . $children . ' Child' . ($children > 1 ? 'ren' : '');
    $pilgrimSubText = 'Total: ' . $totalPilgrims . ' Pilgrims';
} elseif ($adults > 0) {
    $pilgrimPaxText = $adults . ' Adult' . ($adults > 1 ? 's' : '');
    $pilgrimSubText = 'Total Pilgrims';
} else {
    $pilgrimPaxText = $totalPilgrims . ' Pilgrim' . ($totalPilgrims > 1 ? 's' : '');
    $pilgrimSubText = 'Confirmed Guests';
}

$makkahHotel = (string) ($bookingData['makkah_hotel'] ?? '');
$madinahHotel = (string) ($bookingData['madinah_hotel'] ?? '');
$inclusions = (array) ($bookingData['inclusions'] ?? []);

// Filter inclusions to prevent duplicating hotel stays already shown in Accommodation section
$filteredInclusions = [];
foreach ($inclusions as $inc) {
    $incStr = trim((string) $inc);
    if ($incStr === '') continue;
    
    // Skip inclusion item if it's already detailed in the Accommodation box
    $isHotelLine = false;
    if (!empty($makkahHotel) && (stripos($incStr, 'makkah') !== false || stripos($incStr, 'fairmont') !== false) && (stripos($incStr, 'night') !== false || stripos($incStr, 'hotel') !== false || stripos($incStr, 'tower') !== false)) {
        $isHotelLine = true;
    }
    if (!empty($madinahHotel) && (stripos($incStr, 'madinah') !== false || stripos($incStr, 'taqwa') !== false) && (stripos($incStr, 'night') !== false || stripos($incStr, 'hotel') !== false)) {
        $isHotelLine = true;
    }
    
    if (!$isHotelLine) {
        $filteredInclusions[] = $incStr;
    }
}
if (empty($filteredInclusions)) {
    $filteredInclusions = $inclusions;
}

// Lead Pilgrim / Customer Details
$leadName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
if (empty($leadName) && !empty($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadName = trim(($firstTraveller['first_name'] ?? '') . ' ' . ($firstTraveller['last_name'] ?? ''));
    }
}
if (empty($leadName)) {
    $leadName = 'Valued Pilgrim';
}

$leadEmail = $booking['email'] ?? '';
$phoneCode = $booking['phone_country_code'] ?? '';
$phoneNum = $booking['phone'] ?? '';
$leadPhoneFormatted = ($phoneCode ? '+' . ltrim($phoneCode, '+') . ' ' : '') . $phoneNum;
$leadPhoneFormatted = trim($leadPhoneFormatted);

$pnr = trim((string) ($booking['pnr'] ?? ''));

// Flatten pilgrims list
$pilgrimList = [];
$travelersSource = $travellersData['travelers'] ?? $travellersData;
if (!empty($travelersSource) && is_array($travelersSource)) {
    $pIdx = 1;
    foreach ($travelersSource as $key => $value) {
        if (in_array((string) $key, ['primary_guest', 'booking_for_someone_else'], true)) continue;
        if (is_array($value) && !isset($value['first_name'])) {
            foreach ($value as $paxKey => $pax) {
                if (!is_array($pax)) continue;
                $name = trim(($pax['title'] ?? '') . ' ' . ($pax['first_name'] ?? '') . ' ' . ($pax['last_name'] ?? ''));
                if ($name === '') continue;
                $type = !empty($pax['type']) ? ucfirst($pax['type']) : (strpos((string) $paxKey, 'child') !== false ? 'Child' : (strpos((string) $paxKey, 'infant') !== false ? 'Infant' : 'Adult'));
                $nat = trim((string) ($pax['nationality'] ?? $pax['nationality_name'] ?? ''));
                if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
                    $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
                    if (!empty($cName)) {
                        $nat = $cName;
                    }
                }
                $passport = trim((string) ($pax['passport_number'] ?? $pax['passport'] ?? ''));
                $passportExp = trim((string) ($pax['passport_expiry'] ?? $pax['passport_expiry_date'] ?? ''));
                $pilgrimList[] = [
                    'num' => str_pad((string) $pIdx++, 2, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'type' => $type,
                    'nationality' => $nat ?: 'Confirmed',
                    'passport' => $passport,
                    'passport_expiry' => $passportExp,
                ];
            }
        } elseif (is_array($value)) {
            $name = trim(($value['title'] ?? '') . ' ' . ($value['first_name'] ?? '') . ' ' . ($value['last_name'] ?? ''));
            if ($name === '') continue;
            $keyStr = (string) $key;
            $type = !empty($value['type']) ? ucfirst($value['type']) : (strpos($keyStr, 'child') !== false ? 'Child' : (strpos($keyStr, 'infant') !== false ? 'Infant' : 'Adult'));
            $nat = trim((string) ($value['nationality'] ?? $value['nationality_name'] ?? ''));
            if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
                $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
                if (!empty($cName)) {
                    $nat = $cName;
                }
            }
            $passport = trim((string) ($value['passport_number'] ?? $value['passport'] ?? ''));
            $passportExp = trim((string) ($value['passport_expiry'] ?? $value['passport_expiry_date'] ?? ''));
            $pilgrimList[] = [
                'num' => str_pad((string) $pIdx++, 2, '0', STR_PAD_LEFT),
                'name' => $name,
                'type' => $type,
                'nationality' => $nat ?: 'Confirmed',
                'passport' => $passport,
                'passport_expiry' => $passportExp,
            ];
        }
    }
}
if (empty($pilgrimList)) {
    $pilgrimList[] = [
        'num' => '01',
        'name' => $leadName,
        'type' => 'Adult',
        'nationality' => 'Confirmed',
        'passport' => '',
        'passport_expiry' => '',
    ];
}

// Pricing
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

$pdfDisplayRate = $pdfDisplayRate ?? 1.0;
$taxAmountConverted = (float) ($pdfTaxAmount ?? ($taxAmountRaw * $pdfDisplayRate));
$basePriceConverted = $basePrice * $pdfDisplayRate;

// Live Functional QR Code
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/umrah/' . $invoiceId);

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
    <title>Umrah Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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
            border-radius: 8px;
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

        .inclusions-title {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 4px;
            margin-top: 2px;
        }

        .inclusion-item {
            font-size: 6.6pt;
            color: #334155;
            line-height: 1.35;
            margin-bottom: 2px;
        }

        /* 4. Details Card (Pilgrims) */
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
            padding: 4px 10px;
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
        }

        .pax-name {
            color: #0b2545;
            font-size: 7.5pt;
            font-weight: bold;
        }

        .pax-meta-sub {
            color: #64748b;
            font-size: 6.2pt;
            margin-top: 1px;
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

        /* Important Information / Instructions Card */
        .instructions-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 7px 12px;
            margin-bottom: 6px;
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

        <!-- 2. Lead Customer & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label"><?= count($pilgrimList) > 1 ? 'LEAD PILGRIM / CUSTOMER' : 'PILGRIM / CUSTOMER' ?></div>
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
                        <div class="meta-label" style="text-align: right;">PNR / BOOKING REF</div>
                        <div class="meta-value-right"><?= htmlspecialchars($pnr) ?></div>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <!-- 3. Umrah Package Service Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($umrahName) ?></div>
                        <div class="box-subtitle"><?= htmlspecialchars($location) ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge">UMRAH PACKAGE</span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 3-Column Info Grid: Start Date, Pilgrims, Accommodation -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 27%; vertical-align: top;">
                        <div class="grid-label">START DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($formattedDate) ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($duration) ?></div>
                    </td>
                    <td style="width: 23%; vertical-align: top;">
                        <div class="grid-label">PILGRIMS</div>
                        <div class="grid-value"><?= htmlspecialchars($pilgrimPaxText) ?></div>
                        <div class="grid-sub"><?= htmlspecialchars($pilgrimSubText) ?></div>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <div class="grid-label">HOTELS &amp; ACCOMMODATION</div>
                        <?php if (!empty($makkahHotel) && !empty($madinahHotel)): ?>
                            <div class="grid-value" style="font-size: 7.2pt; line-height: 1.3;">
                                <span style="color: #64748b; font-size: 6pt; font-weight: bold; text-transform: uppercase;">MAKKAH:</span>
                                <?= htmlspecialchars($makkahHotel) ?>
                            </div>
                            <div class="grid-sub" style="font-size: 7pt; margin-top: 2px;">
                                <span style="color: #64748b; font-size: 6pt; font-weight: bold; text-transform: uppercase;">MADINAH:</span>
                                <?= htmlspecialchars($madinahHotel) ?>
                            </div>
                        <?php elseif (!empty($makkahHotel)): ?>
                            <div class="grid-value" style="font-size: 7.5pt;">
                                <?= htmlspecialchars($makkahHotel) ?>
                            </div>
                            <div class="grid-sub">Makkah Hotel Accommodation</div>
                        <?php elseif (!empty($madinahHotel)): ?>
                            <div class="grid-value" style="font-size: 7.5pt;">
                                <?= htmlspecialchars($madinahHotel) ?>
                            </div>
                            <div class="grid-sub">Madinah Hotel Accommodation</div>
                        <?php else: ?>
                            <div class="grid-value" style="font-size: 7.5pt;">5-Star Hotel Accommodation</div>
                            <div class="grid-sub">Makkah &amp; Madinah</div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- Package Inclusions (Clean 2-Column Grid inside Service Box) -->
            <?php if (!empty($filteredInclusions)): ?>
                <div class="divider-solid"></div>
                <div class="inclusions-title">PACKAGE INCLUSIONS &amp; AMENITIES</div>
                <table style="width: 100%;">
                    <?php 
                    $incChunks = array_chunk(array_slice($filteredInclusions, 0, 6), 2);
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

        <!-- 4. Pilgrims Table Card -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">PILGRIM DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta"><?= count($pilgrimList) ?> PILGRIM<?= count($pilgrimList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 42%; text-align: left;">PILGRIM NAME</th>
                            <th style="width: 18%; text-align: center;">CATEGORY</th>
                            <th style="width: 18%; text-align: center;">NATIONALITY</th>
                            <th style="width: 17%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pilgrimList as $pilgrim): ?>
                            <tr>
                                <td class="pax-num" style="text-align: center;"><?= htmlspecialchars($pilgrim['num']) ?></td>
                                <td style="text-align: left;">
                                    <div class="pax-name"><?= htmlspecialchars($pilgrim['name']) ?></div>
                                    <?php if (!empty($pilgrim['passport'])): ?>
                                        <div class="pax-meta-sub">
                                            Passport: <?= htmlspecialchars($pilgrim['passport']) ?><?php if (!empty($pilgrim['passport_expiry'])): ?> (Exp: <?= htmlspecialchars($pilgrim['passport_expiry']) ?>)<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="pax-badge"><?= htmlspecialchars($pilgrim['type']) ?></span>
                                </td>
                                <td style="text-align: center; color: #334155; font-size: 6.8pt; font-weight: 500;">
                                    <?= htmlspecialchars($pilgrim['nationality']) ?>
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

        <!-- 5. Special Requests (if present) -->
        <?php if (!empty($booking['special_requests'])): ?>
            <div class="instructions-card">
                <div class="instructions-title">Special Requests</div>
                <div style="font-size: 6.8pt; color: #334155;"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
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
                        <td class="fare-item-label">Umrah Package Fare (<?= count($pilgrimList) ?> Pilgrim<?= count($pilgrimList) > 1 ? 's' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePriceConverted, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Visa Fees</td>
                        <td class="fare-item-val"><?= number_format($taxAmountConverted, 2) ?></td>
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

        <!-- 7. Important Information -->
        <div class="instructions-card">
            <div class="instructions-title">Important Information</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• Please ensure your passport is valid for at least 6 months</div>
                <div class="instruction-item">• Present this voucher (digital or printed) at the airport and hotel</div>
                <div class="instruction-item">• Verify your flight details and arrive at the airport at least 3 hours before departure</div>
                <div class="instruction-item">• Check hotel check-in and check-out times</div>
                <div class="instruction-item">• For any emergency, contact our 24/7 support line</div>
            </div>
        </div>

        <!-- 8. Footer -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail) ?><?php if (!empty($businessPhone)): ?> | <?= htmlspecialchars($businessPhone) ?><?php endif; ?>
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
