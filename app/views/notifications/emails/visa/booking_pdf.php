<?php
/**
 * ============================================================================
 * VISA APPLICATION BOOKING VOUCHER PDF
 * ============================================================================
 * Clean, professional visa booking & inquiry voucher
 * Aligned with unified travel voucher design
 * Preserves all essential applicant passport data, dates, and pricing details
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Visa Data dynamically
$bookingData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$visaTitle = (string) ($bookingData['visa_type_name'] ?? $bookingData['title'] ?? $bookingData['name'] ?? 'Visa Application');
$visaType = (string) ($bookingData['visa_type'] ?? 'Tourist / Business Visa');

$fromCountry = (string) ($bookingData['from_country_name'] ?? $bookingData['origin_country'] ?? '');
$toCountry = (string) ($bookingData['to_country_name'] ?? $bookingData['location'] ?? $bookingData['country'] ?? 'International Destination');
$routeTitle = $fromCountry !== '' ? $fromCountry . ' → ' . $toCountry : $toCountry;

$entryDateRaw = $bookingData['entry_date'] ?? $bookingData['date'] ?? '';
$entryDateFormatted = !empty($entryDateRaw) ? date('D, M d, Y', strtotime(str_replace('/', '-', (string) $entryDateRaw))) : 'To be decided';

$processingSpeed = (string) ($bookingData['processing_speed_name'] ?? $bookingData['processing_time'] ?? 'Standard Processing');

$duration = (string) ($bookingData['duration'] ?? $bookingData['duration_days'] ?? '');
if (!empty($duration) && is_numeric($duration)) {
    $duration = $duration . ' Days Validity';
}
$entryType = (string) ($bookingData['entry_type'] ?? '');
$scopeText = !empty($duration) ? $duration : (!empty($entryType) ? $entryType : 'Standard Validity');

// Lead Applicant Details
$leadName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
if (empty($leadName) && !empty($travellersData)) {
    $firstTraveller = reset($travellersData);
    if (is_array($firstTraveller)) {
        $leadName = trim(($firstTraveller['first_name'] ?? '') . ' ' . ($firstTraveller['last_name'] ?? ''));
    }
}
if (empty($leadName)) {
    $leadName = 'Valued Applicant';
}

$leadEmail = $booking['email'] ?? '';
$phoneCode = $booking['phone_country_code'] ?? '';
$phoneNum = $booking['phone'] ?? '';
$leadPhoneFormatted = ($phoneCode ? '+' . ltrim($phoneCode, '+') . ' ' : '') . $phoneNum;
$leadPhoneFormatted = trim($leadPhoneFormatted);

$pnr = trim((string) ($booking['pnr'] ?? ''));

// Date helper
$formatPdfDate = function ($rawDate) {
    if (empty($rawDate) || $rawDate === '-') {
        return '-';
    }
    $ts = strtotime(str_replace('/', '-', (string) $rawDate));
    if ($ts && $ts > 0) {
        return date('d M Y', $ts);
    }
    return (string) $rawDate;
};

// 2. Applicants list extraction (Preserving DOB, Passport Expiry, Nationality, Gender)
$applicantsList = [];
if (!empty($travellersData) && is_array($travellersData)) {
    $idx = 1;
    foreach ($travellersData as $tr) {
        if (!is_array($tr)) continue;
        $title = trim((string) ($tr['title'] ?? ''));
        $firstName = trim((string) ($tr['first_name'] ?? ''));
        $lastName = trim((string) ($tr['last_name'] ?? ''));
        $fullName = trim($title . ' ' . $firstName . ' ' . $lastName);
        if ($fullName === '') continue;

        $nat = trim((string) ($tr['nationality'] ?? $tr['nationality_name'] ?? '-'));
        if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
            if (!empty($cName)) {
                $nat = $cName;
            }
        }

        $passport = trim((string) ($tr['passport_number'] ?? '-'));
        $passport = ($passport !== '') ? $passport : '-';

        $dobRaw = $tr['date_of_birth'] ?? $tr['dob'] ?? '';
        $dobFormatted = $formatPdfDate($dobRaw);

        $passportExpiryRaw = $tr['passport_expiry'] ?? $tr['passport_expiry_date'] ?? '';
        $passportExpiryFormatted = $formatPdfDate($passportExpiryRaw);

        $gender = !empty($tr['gender']) ? ucfirst(strtolower(trim((string) $tr['gender']))) : '';

        $applicantsList[] = [
            'num' => str_pad((string) $idx++, 2, '0', STR_PAD_LEFT),
            'name' => $fullName,
            'nationality' => $nat,
            'dob' => $dobFormatted,
            'passport' => $passport,
            'passport_expiry' => $passportExpiryFormatted,
            'gender' => $gender,
        ];
    }
}
if (empty($applicantsList)) {
    $applicantsList[] = [
        'num' => '01',
        'name' => $leadName,
        'nationality' => 'Primary Applicant',
        'dob' => '-',
        'passport' => '-',
        'passport_expiry' => '-',
        'gender' => '',
    ];
}

// 3. Pricing
$currency = $currency ?? $booking['currency_markup'] ?? 'USD';
$totalAmount = $totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2);
$taxAmountRaw = (float) ($booking['tax'] ?? 0);
$rawTotal = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
$basePrice = max(0, $rawTotal - $taxAmountRaw);

$travelersCount = count($applicantsList);
$unitPrice = (float) ($bookingData['price_per_traveler'] ?? $bookingData['price_per_person'] ?? ($travelersCount > 0 ? ($basePrice / $travelersCount) : 0));
$unitPriceConverted = $unitPrice * ($pdfDisplayRate ?? 1.0);
$subtotalConverted = $basePrice * ($pdfDisplayRate ?? 1.0);
$taxConverted = (float) ($pdfTaxAmount ?? ($taxAmountRaw * ($pdfDisplayRate ?? 1.0)));

// Status detection
$bookingStatus = strtolower(trim((string) ($booking['booking_status'] ?? 'pending')));
$isConfirmed = ($bookingStatus === 'confirmed');

// Live Functional QR Code
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/visa/' . $invoiceId);

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
    <title>Visa Application Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* 2. Applicant & PNR Row */
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
            font-size: 10.5pt;
            font-weight: bold;
            line-height: 1.2;
        }

        .box-subtitle {
            color: #64748b;
            font-size: 7.2pt;
            margin-top: 2px;
            font-weight: 500;
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
            color: #64748b;
            font-size: 6.5pt;
            margin-top: 1px;
        }

        /* 4. Details Card (Applicants Table) */
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
            padding: 4px 8px;
            border-bottom: 1px solid #e2e8f0;
            border-top: none;
        }

        .pax-table td {
            padding: 5px 8px;
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

        .pax-badge {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-size: 6.2pt;
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

        /* Instructions Card */
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

        /* Status Badge (Original style - no surrounding border) */
        .status-badge {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
            padding: 7px 12px;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .status-badge-title {
            font-size: 8.5pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 1px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .status-badge-text {
            font-size: 7pt;
            color: #1e3a8a;
            line-height: 1.3;
        }

        .status-badge.confirmed {
            background: #ecfdf5;
            border-left: 3px solid #059669;
        }

        .status-badge.confirmed .status-badge-title {
            color: #065f46;
        }

        .status-badge.confirmed .status-badge-text {
            color: #047857;
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
                        <span class="ref-label">INQUIRY REFERENCE:</span>
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

        <!-- 2. Applicant & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label">MAIN APPLICANT</div>
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
                        <div class="meta-label" style="text-align: right;">APPLICATION REF</div>
                        <div class="meta-value-right"><?= htmlspecialchars($pnr) ?></div>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <!-- 3. Status Badge (Original Style) -->
        <?php if ($isConfirmed): ?>
            <div class="status-badge confirmed">
                <div class="status-badge-title">APPLICATION CONFIRMED</div>
                <div class="status-badge-text">Your visa application has been confirmed and scheduled for processing.</div>
            </div>
        <?php else: ?>
            <div class="status-badge">
                <div class="status-badge-title">INQUIRY RECEIVED</div>
                <div class="status-badge-text">Your visa inquiry has been received and is currently under review by our visa specialists.</div>
            </div>
        <?php endif; ?>

        <!-- 4. Visa Service Box -->
        <div class="service-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="box-title"><?= htmlspecialchars($visaTitle) ?></div>
                        <div class="box-subtitle"><?= htmlspecialchars($routeTitle) ?><?= $visaType !== '' ? ' · ' . htmlspecialchars($visaType) : '' ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <span class="service-badge">VISA APPLICATION</span>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 3-Column Metadata Grid -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 38%; vertical-align: top;">
                        <div class="grid-label">INTENDED ENTRY DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($entryDateFormatted) ?></div>
                        <div class="grid-sub">Target Arrival</div>
                    </td>
                    <td style="width: 38%; vertical-align: top;">
                        <div class="grid-label">PROCESSING SPEED</div>
                        <div class="grid-value"><?= htmlspecialchars($processingSpeed) ?></div>
                        <div class="grid-sub">Review Timeline</div>
                    </td>
                    <td style="width: 24%; vertical-align: top;">
                        <div class="grid-label">VALIDITY / SCOPE</div>
                        <div class="grid-value"><?= htmlspecialchars($scopeText) ?></div>
                        <div class="grid-sub">Visa Coverage</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 5. Applicants Information Table Card (Preserves Name, Gender, Nationality, DOB, Passport No, Passport Expiry) -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">APPLICANTS INFORMATION</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta"><?= count($applicantsList) ?> APPLICANT<?= count($applicantsList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 27%; text-align: left;">APPLICANT NAME</th>
                            <th style="width: 17%; text-align: center;">NATIONALITY</th>
                            <th style="width: 17%; text-align: center;">DATE OF BIRTH</th>
                            <th style="width: 17%; text-align: center;">PASSPORT NO.</th>
                            <th style="width: 17%; text-align: center;">PASSPORT EXPIRY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applicantsList as $app): ?>
                            <tr>
                                <td class="pax-num" style="text-align: center;"><?= htmlspecialchars($app['num']) ?></td>
                                <td class="pax-name" style="text-align: left;">
                                    <?= htmlspecialchars($app['name']) ?>
                                    <?php if (!empty($app['gender'])): ?>
                                        <span style="font-size: 5.8pt; color: #64748b; font-weight: normal; margin-left: 2px;">(<?= htmlspecialchars($app['gender']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="pax-badge"><?= htmlspecialchars($app['nationality']) ?></span>
                                </td>
                                <td style="text-align: center; color: #334155; font-size: 6.8pt; font-weight: 500;">
                                    <?= htmlspecialchars($app['dob']) ?>
                                </td>
                                <td style="text-align: center; color: #0b2545; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.3px;">
                                    <?= htmlspecialchars($app['passport']) ?>
                                </td>
                                <td style="text-align: center; color: #334155; font-size: 6.8pt; font-weight: 500;">
                                    <?= htmlspecialchars($app['passport_expiry']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 6. Special Requests -->
        <?php if (!empty($bookingData['special_requests']) || !empty($booking['special_requests'])): ?>
            <div class="instructions-card">
                <div class="instructions-title">Special Requests</div>
                <div style="font-size: 6.8pt; color: #334155; line-height: 1.35;"><?= nl2br(htmlspecialchars((string) ($bookingData['special_requests'] ?: $booking['special_requests']))) ?></div>
            </div>
        <?php endif; ?>

        <!-- 7. Fare / Inquiry Summary Card Box -->
        <?php if ($rawTotal > 0 || (float) $totalAmount > 0): ?>
            <div class="fare-card">
                <div class="fare-header">
                    <table style="width: 100%;">
                        <tr>
                            <td style="width: 50%;">
                                <div class="fare-header-title">INQUIRY SUMMARY</div>
                            </td>
                            <td style="width: 50%; text-align: right;">
                                <div class="fare-header-amt">AMOUNT (<?= htmlspecialchars($currency) ?>)</div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="fare-body">
                    <table style="width: 100%;">
                        <?php if ($unitPriceConverted > 0 && $travelersCount > 0): ?>
                            <tr>
                                <td class="fare-item-label">Price Per Person (× <?= $travelersCount ?> Applicant<?= $travelersCount > 1 ? 's' : '' ?>)</td>
                                <td class="fare-item-val"><?= htmlspecialchars($currency) ?> <?= number_format($unitPriceConverted, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="fare-item-label">Visa Processing Application Fee</td>
                            <td class="fare-item-val"><?= htmlspecialchars($currency) ?> <?= number_format($subtotalConverted, 2) ?></td>
                        </tr>
                        <?php if ($taxConverted > 0): ?>
                            <tr>
                                <td class="fare-item-label">Government Embassy / Consular Taxes</td>
                                <td class="fare-item-val"><?= htmlspecialchars($currency) ?> <?= number_format($taxConverted, 2) ?></td>
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
        <?php endif; ?>

        <!-- 8. Footer -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail) ?><?php if (!empty($businessPhone)): ?> | <?= htmlspecialchars($businessPhone) ?><?php endif; ?>
            </div>
            <div class="footer-note">
                © <?= date('Y') ?> <?= htmlspecialchars($businessName) ?>. All rights reserved.<br>
                Reference: <?= htmlspecialchars((string) $invoiceId) ?> | Date: <?= htmlspecialchars((string) $bookingDate) ?><br>
                This is an automated booking voucher. Please do not reply to this document.
            </div>
        </div>

    </div>

</body>

</html>
