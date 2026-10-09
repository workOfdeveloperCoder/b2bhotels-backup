<?php
/**
 * ============================================================================
 * STAYS / HOTEL BOOKING VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Follows unified design standard with flights, tours, visa, and esim vouchers
 * Features:
 * - Brand / Agency Logo on top left
 * - Booking Reference in header (9pt matching unified vouchers)
 * - Dynamic QR Code on top right linking to live invoice URL
 * - Unpaid payment warning alert (dynamic)
 * - Formatted dynamic hotel itinerary grids (Check-in, Check-out, Duration, Guests)
 * - Selected Rooms & Board Basis breakdown
 * - Multi-Guest details table with anti-collapse layout rules
 * - Preserved Cancellation policies (with multi-currency conversion),
 *   Hotel rate comments, Excluded taxes, and Special requests
 * - Itemized Fare Details breakdown & Total
 * - Guest Checklist card & dynamic Footer
 * ============================================================================
 */

if (!isset($SECURE)) {
    die('Direct access not permitted');
}

// 1. Prepare Hotel Data dynamically
$bookingData = is_array($bookingData) ? $bookingData : (json_decode($booking['booking_data'] ?? '{}', true) ?: []);
$travellersData = is_array($travellersData) ? $travellersData : (json_decode($booking['travellers'] ?? '{}', true) ?: []);

$hd = $bookingData['hotel_data'] ?? [];
$hotelName = (string) ($hd['name'] ?? $hd['hotel_name'] ?? $bookingData['hotel_name'] ?? $bookingData['name'] ?? 'Hotel Booking');
$hotelStars = (int) ($hd['stars'] ?? $hd['rating'] ?? $bookingData['hotel_stars'] ?? $bookingData['stars'] ?? 0);

$hotelAddress = trim((string) ($hd['address'] ?? $bookingData['hotel_address'] ?? ''));
$hotelCity = trim((string) ($hd['city'] ?? $bookingData['hotel_city'] ?? $bookingData['city'] ?? ''));
if ($hotelAddress === '') {
    $hotelAddress = trim(implode(', ', array_filter([
        $bookingData['hotel_street'] ?? $bookingData['address'] ?? '',
        $bookingData['hotel_postal_code'] ?? '',
        $hotelCity,
        $bookingData['hotel_country'] ?? $bookingData['country'] ?? '',
    ], static function ($part) {
        return $part !== null && trim((string) $part) !== '';
    })));
} elseif ($hotelCity !== '' && stripos($hotelAddress, $hotelCity) === false) {
    $hotelAddress .= ', ' . $hotelCity;
}

$supplierEmail = $bookingData['hotel_email'] ?? ($bookingData['email'] ?? '');
$supplierPhone = $bookingData['hotel_phone_number'] ?? ($bookingData['phone_number'] ?? '');
// Accommodation type as received from the supplier (Hotelbeds: from its catalogue).
// No invented default: when the supplier gave none, the badge is left out.
$accType = mb_strtoupper(stayAccommodationType((array) ($booking ?? []), $bookingData, $db ?? null));

// Check-in & Check-out Dates
$checkinRaw = $hd['checkin_date'] ?? $hd['check_in'] ?? $bookingData['checkin'] ?? $booking['checkin'] ?? '';
$checkoutRaw = $hd['checkout_date'] ?? $hd['check_out'] ?? $bookingData['checkout'] ?? $booking['checkout'] ?? '';

$checkinFormatted = !empty($checkinRaw) && strtotime($checkinRaw) ? date('D, d M Y', strtotime($checkinRaw)) : 'N/A';
$checkoutFormatted = !empty($checkoutRaw) && strtotime($checkoutRaw) ? date('D, d M Y', strtotime($checkoutRaw)) : 'N/A';

$nightsCount = (int) ($hd['total_nights'] ?? $hd['nights'] ?? $bookingData['nights'] ?? $nights ?? 1);
if ($nightsCount <= 0 && !empty($checkinRaw) && !empty($checkoutRaw) && strtotime($checkinRaw) && strtotime($checkoutRaw)) {
    $nightsCount = max(1, (int) ceil((strtotime($checkoutRaw) - strtotime($checkinRaw)) / 86400));
}

// Rooms list — selected_rooms is the actual booked room (name/board/price); it's
// what the web invoice reads too. rooms_data is occupancy config (adults/children
// per room) for several suppliers (manual "hotels" module, Hotelbeds search cache),
// not a room list, so it must never be preferred over selected_rooms.
$roomsList = !empty($bookingData['selected_rooms']) ? $bookingData['selected_rooms'] : ($bookingData['rooms_data'] ?? []);
if (!empty($roomsList) && !isset($roomsList[0]['room_name']) && !isset($roomsList[0]['name'])) {
    // Doesn't look like a room entry (e.g. occupancy config) — fall through to the default below.
    $roomsList = [];
}
if (empty($roomsList)) {
    $roomsList = [
        [
            'room_name' => $bookingData['room_type'] ?? 'Standard Room',
            'board_name' => $bookingData['board_type'] ?? 'Room Only',
            'quantity' => 1
        ]
    ];
}

// 2. Lead Guest & Contact
$leadGuestName = '';
$leadEmail = '';
$leadPhone = '';
$leadPhoneCode = '';

if (!empty($travellersData['primary_guest']) && is_array($travellersData['primary_guest'])) {
    $pg = $travellersData['primary_guest'];
    $leadGuestName = trim(($pg['title'] ?? '') . ' ' . ($pg['first_name'] ?? '') . ' ' . ($pg['last_name'] ?? ''));
    $leadEmail = trim((string) ($pg['email'] ?? ''));
    $leadPhone = trim((string) ($pg['phone'] ?? ''));
    $leadPhoneCode = trim((string) ($pg['country_code'] ?? $pg['phone_country_code'] ?? ''));
}

if (empty($leadGuestName)) {
    $leadGuestName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
}
if (empty($leadEmail)) {
    $leadEmail = trim((string) ($booking['email'] ?? ''));
}
if (empty($leadPhone)) {
    $leadPhone = trim((string) ($booking['phone'] ?? ''));
    $leadPhoneCode = trim((string) ($booking['phone_country_code'] ?? ''));
}

// 3. Normalize All Guests for Complete Multi-Guest Table
$guestList = [];
$guestCounters = ['Adult' => 0, 'Child' => 0];

if (!empty($travellersData['travelers']) && is_array($travellersData['travelers'])) {
    foreach ($travellersData['travelers'] as $k => $item) {
        if (!is_array($item)) continue;
        $isDirectTraveler = isset($item['first_name']) || isset($item['name']);
        if ($isDirectTraveler) {
            $tType = stripos((string) $k, 'child') !== false ? 'Child' : 'Adult';
            $guestCounters[$tType]++;
            $gName = trim(($item['title'] ?? '') . ' ' . ($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
            if (empty($gName) && !empty($item['name'])) $gName = trim($item['name']);
            if (empty($gName)) $gName = 'Guest ' . (count($guestList) + 1);
            $roomNum = isset($item['room']) ? ('Room ' . $item['room']) : 'Room 1';
            
            $nat = trim((string) ($item['nationality_name'] ?? $item['nationality'] ?? ''));
            if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
                $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
                if (!empty($cName)) $nat = $cName;
            }

            $guestList[] = [
                'name' => $gName,
                'type_label' => $tType . ' ' . $guestCounters[$tType],
                'room' => $roomNum,
                'age' => $item['age'] ?? '',
                'passport' => $item['passport_number'] ?? $item['passport'] ?? '',
                'nationality' => $nat,
            ];
        } else {
            $roomNum = ((int) filter_var((string) $k, FILTER_SANITIZE_NUMBER_INT)) + 1;
            foreach ($item as $tKey => $t) {
                if (!is_array($t)) continue;
                $tType = stripos((string) $tKey, 'child') !== false ? 'Child' : 'Adult';
                $guestCounters[$tType]++;
                $gName = trim(($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                if (empty($gName) && !empty($t['name'])) $gName = trim($t['name']);
                if (empty($gName)) $gName = 'Guest ' . (count($guestList) + 1);

                $nat = trim((string) ($t['nationality_name'] ?? $t['nationality'] ?? ''));
                if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
                    $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
                    if (!empty($cName)) $nat = $cName;
                }

                $guestList[] = [
                    'name' => $gName,
                    'type_label' => $tType . ' ' . $guestCounters[$tType],
                    'room' => 'Room ' . $roomNum,
                    'age' => $t['age'] ?? '',
                    'passport' => $t['passport_number'] ?? $t['passport'] ?? '',
                    'nationality' => $nat,
                ];
            }
        }
    }
} elseif (!empty($travellersData) && is_array($travellersData)) {
    foreach ($travellersData as $k => $t) {
        if (in_array((string) $k, ['primary_guest', 'booking_for_someone_else'], true)) continue;
        if (!is_array($t)) continue;
        $tType = stripos((string) $k, 'child') !== false ? 'Child' : 'Adult';
        $guestCounters[$tType]++;
        $gName = trim(($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
        if (empty($gName) && !empty($t['name'])) $gName = trim($t['name']);
        if (empty($gName)) $gName = 'Guest ' . (count($guestList) + 1);
        $roomNum = isset($t['room']) ? ('Room ' . $t['room']) : 'Room 1';

        $nat = trim((string) ($t['nationality_name'] ?? $t['nationality'] ?? ''));
        if (strlen($nat) === 2 && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
            $cName = $GLOBALS['db']->get('countries', 'nicename', ['iso' => strtoupper($nat)]);
            if (!empty($cName)) $nat = $cName;
        }

        $guestList[] = [
            'name' => $gName,
            'type_label' => $tType . ' ' . $guestCounters[$tType],
            'room' => $roomNum,
            'age' => $t['age'] ?? '',
            'passport' => $t['passport_number'] ?? $t['passport'] ?? '',
            'nationality' => $nat,
        ];
    }
}

if (empty($guestList)) {
    $guestList[] = [
        'name' => !empty($leadGuestName) ? $leadGuestName : 'Guest Traveler',
        'type_label' => 'Adult 1',
        'room' => 'Room 1',
        'age' => '',
        'passport' => '',
        'nationality' => $booking['nationality'] ?? '',
    ];
    $guestCounters['Adult'] = max(1, $guestCounters['Adult']);
}

if (empty($leadGuestName) && !empty($guestList[0]['name'])) {
    $leadGuestName = $guestList[0]['name'];
}
if (empty($leadGuestName)) {
    $leadGuestName = 'Guest Traveler';
}

$leadPhoneFormatted = '';
if (!empty($leadPhone)) {
    $leadPhoneFormatted = (!empty($leadPhoneCode) ? ('+' . ltrim($leadPhoneCode, '+') . ' ') : '') . $leadPhone;
}

// PNR Reference
$pnr = trim((string) (
    $booking['pnr']
    ?? $bookingData['pnr']
    ?? $bookingData['booking_reference']
    ?? $hd['booking_reference']
    ?? ''
));
if ($pnr === '-' || strtolower($pnr) === 'n/a' || strtolower($pnr) === 'null') {
    $pnr = '';
}

$adultsCount = (int) ($booking['adults'] ?? $bookingData['adults'] ?? max(1, $guestCounters['Adult']));
$childrenCount = (int) ($booking['childs'] ?? $bookingData['children'] ?? $guestCounters['Child']);

// 4. Important Hotel Notes & Policies Preserved
$importantNotes = [];
if (!empty($booking['pnr'])) {
    $bookingResp = is_string($booking['booking_response'] ?? null) ? json_decode($booking['booking_response']) : ($booking['booking_response'] ?? null);
    if ($bookingResp && isset($bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments)) {
        $importantNotes[] = $bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments;
    }
}
if (empty($importantNotes) && !empty($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        if (!empty($room['option']['rate_comments'])) {
            $importantNotes[] = $room['option']['rate_comments'];
        }
    }
}
$importantNotes = array_unique(array_filter($importantNotes));

// Cancellation Policies (Amounts converted to user display currency)
$cancellationPolicies = [];
if (!empty($bookingData['cancellation_policy'])) {
    $cancellationPolicies[] = $bookingData['cancellation_policy'];
}

$pdfBookingCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? $currency ?? 'USD')));
$pdfDisplayCurrency = strtoupper(trim((string) (
    $_SESSION['app_currency']
    ?? $bookingData['display_currency']
    ?? $pdfBookingCurrency
)));
$pdfPolicyRate = 1.0;
if (
    $pdfDisplayCurrency !== ''
    && $pdfBookingCurrency !== ''
    && $pdfDisplayCurrency !== $pdfBookingCurrency
    && function_exists('getCurrencyConversionRate')
    && isset($db)
) {
    $pdfPolicyRate = (float) getCurrencyConversionRate($db, $pdfBookingCurrency, $pdfDisplayCurrency);
    if ($pdfPolicyRate <= 0) {
        $pdfPolicyRate = 1.0;
        $pdfDisplayCurrency = $pdfBookingCurrency;
    }
} elseif ($pdfDisplayCurrency === '') {
    $pdfDisplayCurrency = $pdfBookingCurrency;
}

if (!empty($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        $policyList = $room['option']['cancellation_policies'] ?? [];
        $roomPolicyText = '';
        if (!empty($policyList) && is_array($policyList) && function_exists('staysFormatCancellationPolicyText')) {
            $convertedPolicies = [];
            foreach ($policyList as $policyRow) {
                if (!is_array($policyRow)) continue;
                $convertedPolicies[] = [
                    'amount' => round(((float) ($policyRow['amount'] ?? 0)) * $pdfPolicyRate, 2),
                    'from' => $policyRow['from'] ?? '',
                ];
            }
            $roomPolicyText = staysFormatCancellationPolicyText($convertedPolicies, $pdfDisplayCurrency);
        }
        if ($roomPolicyText === '' && !empty($room['option']['cancellation_text'])) {
            $roomPolicyText = $room['option']['cancellation_text'];
        }
        if ($roomPolicyText !== '') {
            $cancellationPolicies[] = $roomPolicyText;
        }
    }
}
$cancellationPolicies = array_unique(array_filter($cancellationPolicies));

// Excluded Taxes
$excludedTaxes = [];
if (!empty($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        if (!empty($room['option']['excluded_taxes'])) {
            foreach ($room['option']['excluded_taxes'] as $tax) {
                $excludedTaxes[] = $tax;
            }
        }
    }
}

// Payment Gateway & Status resolution
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
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/stays/' . $invoiceId);

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
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Hotel Voucher - <?= htmlspecialchars((string) $invoiceId) ?></title>
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

        /* Guest & PNR */
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

        /* Hotel Card */
        .hotel-box {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 7px 12px;
            background: #ffffff;
            margin-bottom: 6px;
            page-break-inside: avoid;
        }

        .hotel-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: #0b2545;
            line-height: 1.2;
        }

        .hotel-stars {
            color: #f59e0b;
            font-size: 8pt;
            margin-top: 1px;
        }

        .hotel-address {
            font-size: 7pt;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.25;
        }

        .hotel-contact-sub {
            font-size: 6.5pt;
            color: #475569;
            margin-top: 2px;
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

        .room-title {
            font-size: 8pt;
            font-weight: bold;
            color: #0b2545;
        }

        .board-tag {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 6.5pt;
            font-weight: bold;
            margin-top: 1px;
        }

        /* Guest Details Box */
        .guest-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 6px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .guest-header {
            background: #0b2545;
            padding: 5px 12px;
        }

        .guest-header-title {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .guest-header-meta {
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-align: right;
            text-transform: uppercase;
        }

        .guest-body {
            padding: 0;
            background: #ffffff;
        }

        .guest-table {
            width: 100%;
            border-collapse: collapse;
        }

        .guest-table th {
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

        .guest-table td {
            font-size: 7pt;
            color: #0f172a;
            padding: 4px 6px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .guest-table tr:last-child td {
            border-bottom: none;
        }

        .guest-num {
            color: #64748b;
            font-weight: bold;
            font-size: 6.8pt;
            text-align: center;
        }

        .guest-name {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0b2545;
        }

        .guest-meta-sub {
            font-size: 6.2pt;
            color: #64748b;
            margin-top: 1px;
        }

        .guest-type-badge {
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

        .room-tag {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 6.2pt;
            font-weight: bold;
            text-transform: uppercase;
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

        .policy-red {
            background: #fef2f2;
            border: 1px solid #fecaca;
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
            padding-top: 3px;
        }

        .fare-total-val {
            font-size: 11.5pt;
            font-weight: bold;
            color: #0b2545;
            text-align: right;
            padding-top: 2px;
        }

        /* Important Information / Instructions */
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

        <!-- 1. Top Header Row (Logo & Reference on Left + QR Code on Right) -->
        <table class="header-table" style="width: 100%;">
            <tr>
                <!-- Brand / Agency Logo & Booking Reference on Top Left -->
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
                <!-- Top Right: Live Functional QR Code -->
                <td style="width: 30%; text-align: right; vertical-align: middle;">
                    <div class="qr-box">
                        <img class="qr-img" src="<?= $qrUrl ?>" width="44" height="44" style="width: 44px; height: 44px; display: block;" alt="QR Code" />
                    </div>
                </td>
            </tr>
        </table>

        <div class="divider-dashed"></div>

        <!-- 2. Guest & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label"><?= count($guestList) > 1 ? 'LEAD GUEST' : 'GUEST' ?></div>
                    <div class="meta-value"><?= htmlspecialchars($leadGuestName) ?></div>
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

        <!-- Payment Status Alert (if unpaid) -->
        <?php if ($isUnpaid): ?>
            <div class="alert-box">
                <div class="alert-title">PAYMENT REQUIRED</div>
                <div class="alert-text">
                    Please complete your payment using <strong><?= htmlspecialchars($gatewayName ?: 'the designated payment method') ?></strong> to confirm your reservation.
                </div>
            </div>
        <?php endif; ?>

        <!-- 3. Hotel Details Box -->
        <div class="hotel-box">
            <!-- Hotel Title, Rating & Address -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 78%; vertical-align: middle;">
                        <div class="hotel-title"><?= htmlspecialchars($hotelName) ?></div>
                        <?php if ($hotelStars > 0): ?>
                            <div class="hotel-stars"><?= str_repeat('★', min(5, $hotelStars)) ?></div>
                        <?php endif; ?>
                        <?php if ($hotelAddress !== ''): ?>
                            <div class="hotel-address"><?= htmlspecialchars($hotelAddress) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($supplierEmail) || !empty($supplierPhone)): ?>
                            <div class="hotel-contact-sub">
                                <?php if (!empty($supplierEmail)): ?>Email: <?= htmlspecialchars($supplierEmail) ?><?php endif; ?>
                                <?php if (!empty($supplierEmail) && !empty($supplierPhone)): ?> • <?php endif; ?>
                                <?php if (!empty($supplierPhone)): ?>Phone: <?= htmlspecialchars($supplierPhone) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="width: 22%; vertical-align: middle; text-align: right;">
                        <?php if ($accType !== ''): ?>
                            <span class="service-badge"><?= htmlspecialchars($accType) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid: CHECK-IN, CHECK-OUT, DURATION, GUESTS -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 28%; vertical-align: top;">
                        <div class="grid-label">CHECK-IN</div>
                        <div class="grid-value"><?= htmlspecialchars($checkinFormatted) ?></div>
                        <div class="grid-sub">From 14:00</div>
                    </td>
                    <td style="width: 28%; vertical-align: top;">
                        <div class="grid-label">CHECK-OUT</div>
                        <div class="grid-value"><?= htmlspecialchars($checkoutFormatted) ?></div>
                        <div class="grid-sub">Until 12:00</div>
                    </td>
                    <td style="width: 22%; vertical-align: top;">
                        <div class="grid-label">DURATION</div>
                        <div class="grid-value"><?= $nightsCount ?> Night<?= $nightsCount > 1 ? 's' : '' ?></div>
                    </td>
                    <td style="width: 22%; vertical-align: top;">
                        <div class="grid-label">GUESTS</div>
                        <div class="grid-value">
                            <?= $adultsCount ?> Adult<?= $adultsCount > 1 ? 's' : '' ?><?php if ($childrenCount > 0): ?>, <?= $childrenCount ?> Chd<?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Selected Rooms Block -->
            <?php if (!empty($roomsList)): ?>
                <div class="divider-solid"></div>
                <div style="font-size: 6pt; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px;">
                    SELECTED ROOMS
                </div>
                <?php foreach ($roomsList as $rIdx => $room):
                    $rName = $room['room_name'] ?? $room['name'] ?? $bookingData['room_type'] ?? 'Standard Room';
                    $rBoard = $room['board_name'] ?? $room['option']['board_name'] ?? $bookingData['board_type'] ?? null;
                    if ($rBoard === null) {
                        // No board name string on this booking: infer from what we do know
                        // rather than printing "Room Only" when breakfast was actually included.
                        $rBoard = !empty($room['option']['breakfast_included']) ? 'Breakfast Included' : 'Room Only';
                    }
                    $rQty = (int) ($room['quantity'] ?? 1);
                ?>
                    <table style="width: 100%; margin-bottom: 2px;">
                        <tr>
                            <td style="width: 65%;">
                                <span class="room-title"><?= htmlspecialchars($rName) ?></span>
                                <?php if ($rQty > 1): ?>
                                    <span style="font-size: 6.8pt; color: #64748b; font-weight: bold;">(<?= $rQty ?> Rooms)</span>
                                <?php endif; ?>
                            </td>
                            <td style="width: 35%; text-align: right;">
                                <span class="board-tag"><?= htmlspecialchars($rBoard) ?></span>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 4. Guest Details Card Box (Renders All Guests) -->
        <div class="guest-card">
            <div class="guest-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="guest-header-title">GUEST DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="guest-header-meta"><?= count($guestList) ?> GUEST<?= count($guestList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="guest-body">
                <table class="guest-table">
                    <thead>
                        <tr>
                            <th style="width: 6%; text-align: center;">#</th>
                            <th style="width: 44%; text-align: left;">GUEST NAME</th>
                            <th style="width: 18%; text-align: center;">TYPE</th>
                            <th style="width: 16%; text-align: center;">ROOM</th>
                            <th style="width: 16%; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guestList as $gIdx => $guest): 
                            $numStr = str_pad((string)($gIdx + 1), 2, '0', STR_PAD_LEFT);
                            $gSubMeta = [];
                            if (!empty($guest['age'])) {
                                $gSubMeta[] = 'Age: ' . htmlspecialchars((string) $guest['age']);
                            }
                            if (!empty($guest['passport'])) {
                                $gSubMeta[] = 'Passport: ' . htmlspecialchars((string) $guest['passport']);
                            }
                            if (!empty($guest['nationality'])) {
                                $gSubMeta[] = htmlspecialchars((string) $guest['nationality']);
                            }
                        ?>
                            <tr>
                                <td class="guest-num"><?= $numStr ?></td>
                                <td>
                                    <div class="guest-name"><?= htmlspecialchars($guest['name']) ?></div>
                                    <?php if (!empty($gSubMeta)): ?>
                                        <div class="guest-meta-sub"><?= implode(' • ', $gSubMeta) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="guest-type-badge"><?= htmlspecialchars($guest['type_label']) ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="room-tag"><?= htmlspecialchars($guest['room']) ?></span>
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

        <!-- 5. Hotel Specific Policies & Notes (Preserved) -->
        <?php if (!empty($cancellationPolicies)): ?>
            <div class="policy-card policy-amber">
                <div class="policy-title" style="color: #92400e;">CANCELLATION POLICY</div>
                <?php foreach ($cancellationPolicies as $policy): ?>
                    <div class="policy-text" style="color: #92400e; margin-bottom: 2px;">
                        <?= nl2br(htmlspecialchars($policy)) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($importantNotes)): ?>
            <div class="policy-card policy-blue">
                <div class="policy-title" style="color: #1e40af;">IMPORTANT HOTEL INFORMATION</div>
                <?php foreach ($importantNotes as $note): ?>
                    <div class="policy-text" style="color: #1e3a8a; margin-bottom: 2px;">
                        <?= nl2br(htmlspecialchars($note)) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($excludedTaxes)): ?>
            <div class="policy-card policy-red">
                <div class="policy-title" style="color: #991b1b;">EXCLUDED TAXES (PAYABLE AT HOTEL)</div>
                <table style="width: 100%;">
                    <?php foreach ($excludedTaxes as $tax): 
                        $taxFromCurrency = strtoupper(trim((string) ($tax['clientCurrency'] ?? $booking['currency_markup'] ?? 'USD')));
                        $taxAmountBase = (float) ($tax['clientAmount'] ?? 0);
                        $taxRate = 1.0;
                        if (
                            isset($pdfDisplayCurrency, $pdfDisplayRate)
                            && $taxFromCurrency !== ''
                            && strtoupper((string) $pdfDisplayCurrency) !== $taxFromCurrency
                            && function_exists('getCurrencyConversionRate')
                            && isset($db)
                        ) {
                            $taxRate = (float) getCurrencyConversionRate($db, $taxFromCurrency, $pdfDisplayCurrency);
                            if ($taxRate <= 0) $taxRate = 1.0;
                        } elseif (isset($pdfDisplayRate) && $taxFromCurrency === strtoupper((string) ($booking['currency_markup'] ?? ''))) {
                            $taxRate = (float) $pdfDisplayRate;
                        }
                        $taxDisplayCurrency = $pdfDisplayCurrency ?? $taxFromCurrency;
                        $taxDisplayAmount = round($taxAmountBase * $taxRate, 2);
                    ?>
                        <tr>
                            <td style="font-size: 6.5pt; color: #7f1d1d; text-align: left; padding: 1px 0;">
                                <?= htmlspecialchars($tax['subType'] ?? 'Local Tax') ?>
                            </td>
                            <td style="font-size: 6.5pt; color: #7f1d1d; font-weight: bold; text-align: right; padding: 1px 0;">
                                <?= htmlspecialchars($taxDisplayCurrency) ?> <?= number_format($taxDisplayAmount, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($booking['special_requests'])): ?>
            <div class="policy-card policy-blue">
                <div class="policy-title" style="color: #1e40af;">SPECIAL REQUESTS</div>
                <div class="policy-text" style="color: #1e3a8a;">
                    <?= nl2br(htmlspecialchars($booking['special_requests'])) ?>
                </div>
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
                        <td class="fare-item-label">Room Subtotal (<?= $nightsCount ?> Night<?= $nightsCount > 1 ? 's' : '' ?>)</td>
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

        <!-- 7. Guest Checklist (Original) -->
        <div class="instructions-card">
            <div class="instructions-title">Guest Checklist</div>
            <div style="padding-top: 2px;">
                <div class="instruction-item">• Please bring your booking reference number and valid ID</div>
                <div class="instruction-item">• Check-in time is from 14:00 and check-out is until 12:00</div>
                <div class="instruction-item">• Cancellation policy applies as per hotel terms</div>
                <div class="instruction-item">• This is your booking voucher - please keep it for your records</div>
                <div class="instruction-item">• Present this document at the hotel reception upon arrival</div>
            </div>
        </div>

        <!-- 8. Footer (Original) -->
        <div class="footer">
            <div class="footer-contact">
                Questions? Contact us at <?= htmlspecialchars($businessEmail ?? $settings['email_sender_email'] ?? 'support@phptravels.com') ?><?php if (!empty($businessPhone ?? $settings['site_phone'])): ?> | <?= htmlspecialchars($businessPhone ?? $settings['site_phone']) ?><?php endif; ?>
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
