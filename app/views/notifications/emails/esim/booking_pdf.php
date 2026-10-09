<?php
/**
 * ============================================================================
 * ESIM BOOKING E-TICKET VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Follows exact design pattern of flights/booking_pdf.php
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare eSIM Booking Data dynamically
$bookingData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$selectedPackage = (array) ($bookingData['selected_package'] ?? []);
$country = (array) ($bookingData['country'] ?? []);
$airaloOrder = (array) ($bookingData['airalo_order'] ?? []);
$airaloProvision = (array) ($bookingData['airalo_provision'] ?? []);
$airaloFirstSim = (array) ($airaloProvision['first_sim'] ?? []);

// Package Details & Fallbacks
$packageName = (string) ($selectedPackage['title'] ?? $bookingData['title'] ?? $bookingData['name'] ?? 'Global eSIM Package');
$countryCoverage = (string) ($country['name'] ?? $selectedPackage['country'] ?? $bookingData['coverage_regions'] ?? $bookingData['country'] ?? 'Global Multi-Region');
$dataAllowance = (string) ($selectedPackage['data_limit'] ?? $bookingData['data_plan'] ?? $bookingData['data'] ?? 'High-Speed Data');
$validity = (string) ($selectedPackage['duration'] ?? (!empty($bookingData['validity_days']) ? $bookingData['validity_days'] . ' Days' : (!empty($bookingData['duration']) ? $bookingData['duration'] : '30 Days')));
$orderType = ucfirst((string) ($airaloOrder['type'] ?? $bookingData['type'] ?? 'Data Only'));
$quantity = (int) ($airaloOrder['quantity'] ?? $bookingData['quantity'] ?? 1);

// Technical & Activation Details
$iccid = (string) ($airaloProvision['iccid'] ?? $airaloFirstSim['iccid'] ?? $bookingData['iccid'] ?? '');
$smdpAddress = (string) ($airaloProvision['smdp_address'] ?? $airaloFirstSim['smdp_address'] ?? $airaloFirstSim['lpa'] ?? $bookingData['smdp_address'] ?? '');
$matchingId = (string) ($airaloProvision['matching_id'] ?? $airaloFirstSim['matching_id'] ?? $bookingData['matching_id'] ?? '');
$manualRaw = $airaloProvision['manual_installation'] ?? $airaloFirstSim['manual_installation'] ?? $bookingData['manual_installation'] ?? '';
$manualData = is_array($manualRaw) ? $manualRaw : [];
$activationCode = (string) ($manualData['code'] ?? $manualData['activation_code'] ?? $manualData['lpa'] ?? $bookingData['activation_code'] ?? $smdpAddress);
$topupTarget = (string) ($airaloOrder['topup_target'] ?? $bookingData['topup_target'] ?? '');

// Primary Customer / Contact Details
$leadName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
if (empty($leadName) && !empty($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadName = trim(($firstTraveller['first_name'] ?? '') . ' ' . ($firstTraveller['last_name'] ?? ''));
    }
}
if (empty($leadName)) {
    $leadName = 'Valued Customer';
}

$leadEmail = $booking['email'] ?? '';
$phoneCode = $booking['phone_country_code'] ?? '';
$phoneNum = $booking['phone'] ?? '';
$leadPhoneFormatted = ($phoneCode ? '+' . ltrim($phoneCode, '+') . ' ' : '') . $phoneNum;
$leadPhoneFormatted = trim($leadPhoneFormatted);

$pnr = $booking['pnr'] ?? $airaloProvision['id'] ?? $airaloProvision['order_id'] ?? '';

// Pricing
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

// Booking Date
$rawBookingDate = $booking['booking_date'] ?? $booking['created_at'] ?? 'now';
$bookingDateFormatted = !empty($rawBookingDate) && strtotime($rawBookingDate) ? date('d M Y', strtotime($rawBookingDate)) : date('d M Y');

// Payment & Booking Status
$bookingStatus = strtolower((string) ($booking['booking_status'] ?? 'pending'));
$paymentStatus = strtolower((string) ($booking['payment_status'] ?? 'unpaid'));
$isPaid = ($paymentStatus === 'paid');

$bookingStatusLabel = strtoupper($booking['booking_status'] ?? 'PENDING');
$paymentStatusLabel = strtoupper($booking['payment_status'] ?? 'UNPAID');

$bookingStatusPillClass = match ($bookingStatus) {
    'confirmed' => 'status-pill-confirmed',
    'cancelled', 'voided' => 'status-pill-cancelled',
    'refunded' => 'status-pill-refunded',
    default => 'status-pill-pending',
};

$paymentStatusPillClass = match ($paymentStatus) {
    'paid' => 'status-pill-paid',
    'unpaid' => 'status-pill-unpaid',
    'refunded' => 'status-pill-refunded',
    default => 'status-pill-pending',
};

// Payment Method / Gateway
$rawGateway = trim((string) ($booking['payment_gateway'] ?? $booking['payment_method'] ?? ''));
$paymentMethodName = '';

if (!empty($bookingData['payment']['gateway_name'])) {
    $paymentMethodName = (string) $bookingData['payment']['gateway_name'];
} elseif ($rawGateway !== '') {
    if (!is_numeric($rawGateway)) {
        $paymentMethodName = $rawGateway;
    } else {
        global $db;
        if (isset($db) && is_object($db)) {
            $gwRow = $db->get('payment_gateways', ['name', 'display_name'], ['id' => (int) $rawGateway]);
            if ($gwRow) {
                $paymentMethodName = function_exists('getGatewayDisplayName') ? getGatewayDisplayName($gwRow) : ($gwRow['display_name'] ?? $gwRow['name'] ?? '');
            }
        }
    }
}

// Clean formatting and prevent numeric IDs from showing
if (is_numeric($paymentMethodName)) {
    $paymentMethodName = '';
} elseif ($paymentMethodName !== '') {
    $paymentMethodName = ucwords(str_replace(['_', '-'], ' ', $paymentMethodName));
}

// Live Functional QR Code pointing to live booking URL
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/esim/' . $invoiceId);

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
    <title>eSIM Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* Customer & PNR */
        .meta-label {
            font-size: 6.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 11pt;
            font-weight: bold;
            color: #0f172a;
        }

        .meta-value-right {
            font-size: 11pt;
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

        .status-pill-paid {
            display: inline-block;
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pill-unpaid {
            display: inline-block;
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pill-confirmed {
            display: inline-block;
            background: #f0f9ff;
            color: #0284c7;
            border: 1px solid #bae6fd;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pill-pending {
            display: inline-block;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pill-cancelled {
            display: inline-block;
            background: #f8fafc;
            color: #64748b;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pill-refunded {
            display: inline-block;
            background: #fdf4ff;
            color: #a21caf;
            border: 1px solid #f0abfc;
            border-radius: 4px;
            padding: 1.5px 6px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Service Box */
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

        /* Details Card Box */
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

        .code-box {
            font-family: monospace;
            font-size: 7pt;
            font-weight: bold;
            color: #0f172a;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            padding: 1px 4px;
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

        /* Important Information / Instructions */
        .instructions-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 7px 12px;
            margin-top: 0;
            margin-bottom: 8px;
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
                        <span style="margin-left: 8px;">
                            <span class="<?= $bookingStatusPillClass ?>" style="vertical-align: middle;"><?= htmlspecialchars($bookingStatusLabel) ?></span>
                            <span class="<?= $paymentStatusPillClass ?>" style="vertical-align: middle; margin-left: 3px;"><?= htmlspecialchars($paymentStatusLabel) ?></span>
                        </span>
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

        <!-- 2. Customer, Booking Date & Order Reference Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: 44%; vertical-align: top;">
                    <div class="meta-label">CUSTOMER</div>
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
                <td style="width: 26%; vertical-align: top; text-align: left;">
                    <div class="meta-label">BOOKING DATE</div>
                    <div class="meta-value" style="font-size: 10pt; margin-top: 1px;"><?= htmlspecialchars($bookingDateFormatted) ?></div>
                </td>
                <td style="width: 30%; vertical-align: top; text-align: right;">
                    <?php if (!empty($pnr)): ?>
                        <div class="meta-label" style="text-align: right;">ORDER REFERENCE</div>
                        <div class="meta-value-right"><?= htmlspecialchars($pnr) ?></div>
                    <?php else: ?>
                        <div class="meta-label" style="text-align: right;">INVOICE ID</div>
                        <div class="meta-value-right">#<?= htmlspecialchars((string) $invoiceId) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- 3. Primary eSIM Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($packageName) ?></div>
                        <div class="box-subtitle"><?= htmlspecialchars($countryCoverage) ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge">GLOBAL ESIM</span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid: DATA, VALIDITY, SPEED, QUANTITY -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 28%; vertical-align: top;">
                        <div class="grid-label">DATA ALLOWANCE</div>
                        <div class="grid-value"><?= htmlspecialchars($dataAllowance) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">VALIDITY</div>
                        <div class="grid-value"><?= htmlspecialchars($validity) ?></div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">SPEED / NETWORK</div>
                        <div class="grid-value">5G / 4G LTE</div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">QUANTITY / TYPE</div>
                        <div class="grid-value"><?= $quantity ?> <?= htmlspecialchars($orderType) ?></div>
                    </td>
                </tr>
            </table>

            <?php if (!empty($topupTarget)): ?>
                <div class="divider-solid"></div>
                <div style="font-size: 6.5pt; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">
                    TOP-UP TARGET ICCID: <span class="code-box"><?= htmlspecialchars($topupTarget) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- 4. Activation Details Card Box -->
        <?php $paxRowIdx = 1; ?>
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">ESIM ACTIVATION DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta">INSTANT DIGITAL DELIVERY</div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 32%; text-align: left;">FIELD / PARAMETER</th>
                            <th style="width: 45%; text-align: left;">VALUE / CODE</th>
                            <th style="width: 18%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="pax-num" style="text-align: center;"><?= str_pad((string)($paxRowIdx++), 2, '0', STR_PAD_LEFT) ?></td>
                            <td class="pax-name">eSIM Identifier (ICCID)</td>
                            <td>
                                <?php if (!empty($iccid)): ?>
                                    <span class="code-box"><?= htmlspecialchars($iccid) ?></span>
                                <?php else: ?>
                                    <span style="color: #64748b; font-size: 7pt;">Provisioned on active line</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">Ready</td>
                        </tr>
                        <?php if (!empty($activationCode)): ?>
                        <tr>
                            <td class="pax-num" style="text-align: center;"><?= str_pad((string)($paxRowIdx++), 2, '0', STR_PAD_LEFT) ?></td>
                            <td class="pax-name">Activation / LPA Code</td>
                            <td><span class="code-box"><?= htmlspecialchars($activationCode) ?></span></td>
                            <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">Active</td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($smdpAddress) && $smdpAddress !== $activationCode): ?>
                        <tr>
                            <td class="pax-num" style="text-align: center;"><?= str_pad((string)($paxRowIdx++), 2, '0', STR_PAD_LEFT) ?></td>
                            <td class="pax-name">SM-DP+ Address</td>
                            <td><span class="code-box"><?= htmlspecialchars($smdpAddress) ?></span></td>
                            <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">Server</td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($matchingId)): ?>
                        <tr>
                            <td class="pax-num" style="text-align: center;"><?= str_pad((string)($paxRowIdx++), 2, '0', STR_PAD_LEFT) ?></td>
                            <td class="pax-name">Matching ID</td>
                            <td><span class="code-box"><?= htmlspecialchars($matchingId) ?></span></td>
                            <td style="text-align: center; color: #047857; font-weight: bold; font-size: 6.8pt;">Ready</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Special Requests / Notes (if present) -->
        <?php if (!empty($booking['special_requests'])): ?>
            <div style="border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 8px; padding: 6px 10px; background: #ffffff;">
                <div style="font-size: 6.8pt; font-weight: bold; color: #0b2545; text-transform: uppercase; margin-bottom: 2px;">SPECIAL NOTES</div>
                <div style="font-size: 6.8pt; color: #334155;"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
            </div>
        <?php endif; ?>

        <!-- 6. Payment Summary Card Box -->
        <div class="fare-card">
            <div class="fare-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 50%;">
                            <div class="fare-header-title">PAYMENT SUMMARY</div>
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
                        <td class="fare-item-label">eSIM Package (<?= $quantity ?> Item<?= $quantity > 1 ? 's' : '' ?>)</td>
                        <td class="fare-item-val"><?= number_format($basePrice * ($pdfDisplayRate ?? 1.0), 2) ?></td>
                    </tr>
                    <?php if ((float) ($pdfTaxAmount ?? $taxAmountRaw ?? 0) > 0): ?>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Fees</td>
                        <td class="fare-item-val"><?= number_format((float) ($pdfTaxAmount ?? $taxAmountRaw ?? 0), 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($paymentMethodName)): ?>
                    <tr>
                        <td class="fare-item-label">Payment Method</td>
                        <td class="fare-item-val" style="font-weight: 600; color: #475569; font-size: 7.2pt;"><?= htmlspecialchars($paymentMethodName) ?></td>
                    </tr>
                    <?php endif; ?>
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

        <!-- 7. Instructions & Important Information Card -->
        <div class="instructions-card">
            <div class="instructions-title">eSIM Installation &amp; Setup Instructions</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• <strong>iOS Setup:</strong> Go to <em>Settings &rarr; Cellular / Mobile Data &rarr; Add eSIM</em>, then scan your QR code or select <em>Enter Details Manually</em> (enter the SM-DP+ Address and Activation Code above).</div>
                <div class="instruction-item">• <strong>Android Setup:</strong> Go to <em>Settings &rarr; Network &amp; Internet &rarr; SIMs &rarr; Add eSIM / Download SIM</em>, then scan your QR code or enter code manually.</div>
                <div class="instruction-item">• <strong>Data Roaming:</strong> You must enable <strong>Data Roaming</strong> on your eSIM profile once you land at your destination to connect to local networks.</div>
                <div class="instruction-item">• <strong>APN Settings:</strong> Set Access Point Name (APN) to <span style="font-family: monospace; font-weight: bold; color: #0f172a;">globaldata</span> if not configured automatically by your carrier.</div>
                <div class="instruction-item">• <strong>Online Digital Invoice:</strong> Visit <a href="<?= htmlspecialchars(root . 'invoice/esim/' . rawurlencode((string) $invoiceId)) ?>" style="color: #2563eb; text-decoration: underline;"><?= htmlspecialchars(root . 'invoice/esim/' . $invoiceId) ?></a> for 1-tap iPhone install and real-time status.</div>
            </div>
        </div>

        <!-- 8. Footer -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail) ?><?php if (!empty($businessPhone)): ?> | <?= htmlspecialchars($businessPhone) ?><?php endif; ?>
            </div>
            <div class="footer-note">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($businessName) ?>. All rights reserved.<br>
                Please keep this invoice for your records.
            </div>
        </div>
    </div>

</body>

</html>