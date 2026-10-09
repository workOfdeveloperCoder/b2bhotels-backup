<?php
/**
 * ============================================================================
 * FLIGHT BOOKING E-TICKET VOUCHER PDF
 * ============================================================================
 * 100% Dynamic Backend Data Mapping
 * Features:
 * - Brand Logo on top left
 * - E-TICKET & Booking Reference in header
 * - Dynamic QR Code on top right linking to live invoice URL
 * - Exact flight route connection graphic from uploaded design
 * - Formatted dynamic itinerary grids (Date, Time, Flight, Class, Baggage, Seat)
 * - Itemized Fare Details breakdown & Total
 * - Instructions & Important Information preserved in modern card layout
 * ============================================================================
 */

if (!isset($SECURE))
    die('Direct access not permitted');

// 1. Prepare Flight Data dynamically from backend
$fd = $bookingData['flight_data'] ?? [];
$routes = $fd['routes'] ?? [];
$segments = !empty($routes) ? ($routes[0]['segments'] ?? []) : ($fd['segments'] ?? []);

$firstSeg = !empty($segments) ? $segments[0] : [];
$lastSeg = !empty($segments) ? $segments[count($segments) - 1] : $firstSeg;

// Departure Airport & Code
$depAirportRaw = (string) ($firstSeg['departure_airport'] ?? $fd['departure_airport'] ?? '');
$originCode = strtoupper(trim((string) (
    $firstSeg['departure_code']
    ?? $firstSeg['from_airport']
    ?? $fd['departure_code']
    ?? $fd['from']
    ?? ''
)));
if (empty($originCode) && !empty($depAirportRaw) && preg_match('/\(([A-Z]{3})\)/', $depAirportRaw, $m)) {
    $originCode = $m[1];
}

// Arrival Airport & Code
$arrAirportRaw = (string) ($lastSeg['arrival_airport'] ?? $fd['arrival_airport'] ?? '');
$destCode = strtoupper(trim((string) (
    $lastSeg['arrival_code']
    ?? $lastSeg['to_airport']
    ?? $fd['arrival_code']
    ?? $fd['to']
    ?? ''
)));
if (empty($destCode) && !empty($arrAirportRaw) && preg_match('/\(([A-Z]{3})\)/', $arrAirportRaw, $m)) {
    $destCode = $m[1];
}

// Helper to parse City and Airport Name cleanly
$parseAirport = function ($raw, $code) {
    $city = '';
    $airport = '';
    if (preg_match('/^(.*?)\s*\((.*?)\)$/', $raw, $m)) {
        $city = trim($m[1]);
        $airport = trim($m[2]);
    } elseif (strpos($raw, ',') !== false) {
        $parts = explode(',', $raw, 2);
        $city = trim($parts[0]);
        $airport = trim($parts[1]);
    } elseif (!empty($raw)) {
        $city = $raw;
        $airport = $raw;
    } else {
        $city = $code ?: 'Airport';
        $airport = ($code ? ($code . ' ') : '') . 'International Airport';
    }
    return ['city' => $city, 'airport' => $airport];
};

$originParsed = $parseAirport($depAirportRaw, $originCode);
$destParsed = $parseAirport($arrAirportRaw, $destCode);

// Dynamic Departure Date
$rawDepDate = $firstSeg['departure_date'] ?? $firstSeg['departure_datetime'] ?? $fd['departure_date'] ?? $booking['booking_date'] ?? null;
if (!empty($rawDepDate) && strtotime($rawDepDate)) {
    $depDateFormatted = date('d M Y', strtotime($rawDepDate));
} else {
    $depDateFormatted = date('d M Y', strtotime($booking['created_at'] ?? 'now'));
}

// Dynamic Departure Time
$rawDepTime = $firstSeg['departure_time'] ?? $firstSeg['departure_datetime'] ?? $fd['departure_time'] ?? '';
if (!empty($rawDepTime) && strtotime($rawDepTime)) {
    $depTimeFormatted = date('h:i A', strtotime($rawDepTime));
} elseif (!empty($rawDepTime)) {
    $depTimeFormatted = $rawDepTime;
} else {
    $depTimeFormatted = '-';
}

// Dynamic Arrival Time
$rawArrTime = $lastSeg['arrival_time'] ?? $lastSeg['arrival_datetime'] ?? $fd['arrival_time'] ?? '';
if (!empty($rawArrTime) && strtotime($rawArrTime)) {
    $arrTimeFormatted = date('h:i A', strtotime($rawArrTime));
} elseif (!empty($rawArrTime)) {
    $arrTimeFormatted = $rawArrTime;
} else {
    $arrTimeFormatted = '-';
}

// Dynamic Duration
$flightDuration = (string) ($firstSeg['duration_time'] ?? $fd['duration_time'] ?? $fd['total_duration'] ?? '');
if (empty($flightDuration) && !empty($rawDepTime) && !empty($rawArrTime) && strtotime($rawDepTime) && strtotime($rawArrTime)) {
    $diffSec = abs(strtotime($rawArrTime) - strtotime($rawDepTime));
    $h = floor($diffSec / 3600);
    $m = floor(($diffSec % 3600) / 60);
    $flightDuration = "{$h}h {$m}m";
}
if (empty($flightDuration)) {
    $flightDuration = '-';
}

// Dynamic Flight Number & Airline
$flightNum = (string) ($firstSeg['flight_no'] ?? $firstSeg['flight_number'] ?? $fd['flight_no'] ?? $fd['flight_number'] ?? '-');
$airlineName = (string) ($firstSeg['airline'] ?? $firstSeg['airline_name'] ?? $fd['airline'] ?? $fd['airlineName'] ?? '');
$flightClass = ucfirst((string) ($firstSeg['class'] ?? $fd['class'] ?? 'Economy'));

// Dynamic Baggage
$flightBaggage = (string) ($firstSeg['baggage'] ?? $fd['baggage'] ?? '');
if (preg_match('/(\d+\s*x\s*\d+\s*kg|\d+\s*kg)/i', $flightBaggage, $bm)) {
    $flightBaggage = strtoupper($bm[1]);
}
if (empty($flightBaggage) || strtolower($flightBaggage) === 'n/a') {
    $flightBaggage = '-';
}

// Dynamic Passenger Name
$passengers = $travellersData['passengers'] ?? $travellersData ?? [];
$primaryPassengerName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));

if (empty($primaryPassengerName) && !empty($passengers) && is_array($passengers)) {
    $firstP = reset($passengers);
    if (is_array($firstP)) {
        $primaryPassengerName = trim(($firstP['title'] ?? '') . ' ' . ($firstP['first_name'] ?? '') . ' ' . ($firstP['last_name'] ?? ''));
    }
}
if (empty($primaryPassengerName)) {
    $primaryPassengerName = 'Guest Passenger';
}

// Collect and normalize all passengers for complete passenger list
$paxList = [];
$rawPassengers = $bookingData['passengers'] ?? $travellersData['passengers'] ?? $travellersData ?? [];
$paxTypeCounters = ['Adult' => 0, 'Child' => 0, 'Infant' => 0];

if (!empty($rawPassengers) && is_array($rawPassengers)) {
    foreach ($rawPassengers as $k => $p) {
        if (!is_array($p)) continue;
        $kStr = (string) $k;

        $pType = 'Adult';
        if (stripos($kStr, 'child') !== false) {
            $pType = 'Child';
        } elseif (stripos($kStr, 'infant') !== false) {
            $pType = 'Infant';
        } elseif (!empty($p['type'])) {
            $t = strtolower((string) $p['type']);
            if (in_array($t, ['child', 'chd'], true)) $pType = 'Child';
            if (in_array($t, ['infant', 'inf'], true)) $pType = 'Infant';
        }
        $paxTypeCounters[$pType] = ($paxTypeCounters[$pType] ?? 0) + 1;
        $pTypeLabel = $pType . ' ' . $paxTypeCounters[$pType];

        $pName = trim(($p['title'] ?? '') . ' ' . ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        if (empty($pName) && !empty($p['name'])) {
            $pName = trim($p['name']);
        }
        if (empty($pName)) {
            $pName = 'Passenger ' . (count($paxList) + 1);
        }

        // Seat
        $pSeat = '';
        $pId = $p['id'] ?? null;
        if (!empty($p['seat'])) {
            $pSeat = trim((string) $p['seat']);
        } elseif ($pId && !empty($bookingData['seat']) && is_array($bookingData['seat'])) {
            foreach ($bookingData['seat'] as $sIdx => $seats) {
                if (is_array($seats) && isset($seats[$pId]['designator'])) {
                    $pSeat = $seats[$pId]['designator'];
                    break;
                }
            }
        }

        // Baggage
        $pBaggage = '';
        if (!empty($p['baggage'])) {
            $pBaggage = trim((string) $p['baggage']);
        } elseif ($pId && !empty($bookingData['baggage']) && is_array($bookingData['baggage'])) {
            $pBags = [];
            foreach ($bookingData['baggage'] as $bag) {
                if (isset($bag['passengerId']) && $bag['passengerId'] === $pId) {
                    $qty = (int) ($bag['quantity'] ?? 1);
                    $name = $bag['name'] ?? 'Extra Bag';
                    $pBags[] = "{$qty}x {$name}";
                }
            }
            if (!empty($pBags)) {
                $pBaggage = implode(', ', $pBags);
            }
        }

        $passport = $p['passport_number'] ?? $p['passport'] ?? '';

        $paxList[] = [
            'name' => $pName,
            'type_label' => $pTypeLabel,
            'type' => $pType,
            'passport' => $passport,
            'nationality' => $p['nationality_name'] ?? $p['nationality'] ?? '',
            'seat' => $pSeat,
            'baggage' => $pBaggage,
        ];
    }
}

// Fallback if no passenger array found
if (empty($paxList)) {
    $paxList[] = [
        'name' => $primaryPassengerName,
        'type_label' => 'Adult 1',
        'type' => 'Adult',
        'passport' => '',
        'nationality' => '',
        'seat' => '',
        'baggage' => '',
    ];
}

// Dynamic Seat Assignment
$flightSeat = '-';
if (!empty($bookingData['seat']) && is_array($bookingData['seat'])) {
    $firstSeat = reset($bookingData['seat']);
    if (is_array($firstSeat)) {
        $firstPSeat = reset($firstSeat);
        if (!empty($firstPSeat['designator'])) {
            $flightSeat = strtoupper($firstPSeat['designator']);
        }
    }
} elseif (!empty($travellersData) && is_array($travellersData)) {
    foreach ($travellersData as $t) {
        if (!empty($t['seat'])) {
            if (preg_match('/^([0-9]+[A-Z])/i', trim($t['seat']), $sm)) {
                $flightSeat = strtoupper($sm[1]);
            } else {
                $flightSeat = trim(explode(' ', $t['seat'])[0]);
            }
            break;
        }
    }
}

// Dynamic Lead Contact Details
$leadEmail = trim((string) ($booking['email'] ?? ''));
if (empty($leadEmail) && !empty($rawPassengers) && is_array($rawPassengers)) {
    $firstP = reset($rawPassengers);
    if (is_array($firstP) && !empty($firstP['email'])) {
        $leadEmail = trim((string) $firstP['email']);
    }
}

$leadPhone = trim((string) ($booking['phone'] ?? ''));
$leadPhoneCode = trim((string) ($booking['phone_country_code'] ?? ''));
if (empty($leadPhone) && !empty($rawPassengers) && is_array($rawPassengers)) {
    $firstP = reset($rawPassengers);
    if (is_array($firstP) && !empty($firstP['phone'])) {
        $leadPhone = trim((string) $firstP['phone']);
        if (empty($leadPhoneCode) && !empty($firstP['phone_country_code'])) {
            $leadPhoneCode = trim((string) $firstP['phone_country_code']);
        }
    }
}
$leadPhoneFormatted = '';
if (!empty($leadPhone)) {
    $leadPhoneFormatted = (!empty($leadPhoneCode) ? ('+' . ltrim($leadPhoneCode, '+') . ' ') : '') . $leadPhone;
}

// Dynamic Airline PNR (Only show if available, otherwise show nothing)
$pnr = trim((string) (
    $booking['pnr']
    ?? $bookingData['pnr']
    ?? $bookingData['flight_data']['pnr']
    ?? $bookingData['flight_data']['booking_reference']
    ?? $bookingData['booking_reference']
    ?? ''
));
if ($pnr === '-' || strtolower($pnr) === 'n/a' || strtolower($pnr) === 'null') {
    $pnr = '';
}

// Dynamic Pricing
$ancTotal = (float) ($bookingData['ancillary_data']['total_base'] ?? 0);
$taxAmount = (float) ($booking['tax'] ?? 0);
$baseFare = (float) ($rawTotal - $ancTotal - $taxAmount);
if ($baseFare <= 0) {
    $baseFare = (float) ($booking['price_original'] ?? ($rawTotal - $taxAmount));
}
if ($baseFare <= 0) {
    $baseFare = (float) $rawTotal;
}

// Functional Live QR Code pointing to live booking URL
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(root . 'invoice/flights/' . $invoiceId);

// Logo path
$logoImgPath = $invoiceLogo ?? (uploads . 'global/logo.png');

// Flight route graphic — crisp base64 PNG (prevents mPDF from creating temporary SVG files on disk)
$routeImgB64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAeAAAABICAYAAAAu7CaiAAAACXBIWXMAAA7EAAAOxAGVKw4bAAADOElEQVR4nO3du04bURCA4SXKO1AgREWFoOP9H4AOREWFEAVPQaqNHMfGt3POzHi/T0qRKFm5YPJ7zvoyTQAAAAAAAAAAAAAAlVxEPwBgf0+v79+7/s7j3Y25hgIMKiS2Htz726ud/+bl7fOf3wsy5GQwIZnV6O4T3F1WgyzGkIdhhCTm8LaI7jZzjIUY4hlCCDYivOuEGOIZPgj09Pr+PTK8617ePkUYghg8CBCx9W5jG4YYBg4Gi956t7ENw1iGDQbKGt+ZCMM4Bg0GyR7fmQjDGIYMBqgS35kIQ38GDDqrFt+ZCENfhgs6qhrfmQhDP7+jHwDws8vrh//+7OvjOeCRAC39in4AcK56br+botzD/e3VXt/ABBxOgKGD6kfPq0QY+hBgSG7bcfOoLRjoQ4ChsZHbr6NoqEuAoQAvuoLzI8DQUMS9X1sw1CTAMNjl9cPfXy2vCdQiwBDokHDuOoYWYahFgKGRY4+fK4XTMTS0I8Aw2KZNtlWEK8Uclk6AoZB9Xg0twlCDAEMSwgnL4ltOoIFj7v9mCe4x7zH2LUlwOhswLFyWJwKwNAIMAAGafR/wIW9N+OnoynVcp8d1Mh6Xfn08p9k+L68f0n3cZZWfLddZ5nVa/J9iAwaAAAIMQbJsv9Pkyx4gQrpjOaio8qugp+nwAHsVNJyu2T1goI1WH7Zhq4XcHEFDgG0BFV9YDgGGBh7vbi5e3j5PukaVaDp+hjYEGAqx/cL5EGAYbFNEW0VTfKEOAYZgognL5D4ONHTM25H2tev4eUTI3f+FdmzAcAZs0VCPAENx4gs1CTA01OLtSJtk+NQsx8/QlgBDYbZfqMuzWeig5YuxTvnUrFZsv9CeDRgAAggwdNDrXvDM9gv1GSroqOf7gkcQX+jHBgwAATyzhc6qbsG2X+jLcMEA1SIsvtCfAYNBqkRYfGEMQwYDZY+w+MI4Bg0Gyxph8YWxDBsEeHp9/56macoQ4vn9yuILYxk4CBS9Ddt6IY7Bg2AR27CtF+IZPkhiRIiFF/IwhJDMHOJpahPj1c+kFl7IwzBCYqsxnqb9grz+JRCiCzkZTChkPcibCC4AAAAAAAAAAABw9v4AaoilrpnbKkoAAAAASUVORK5CYII=';
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Flight Invoice - <?= htmlspecialchars($invoiceId) ?></title>
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

        /* Flight Box */
        .flight-box {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 12px;
            background: #ffffff;
            margin-top: 8px;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }

        .airport-code {
            font-size: 20pt;
            font-weight: bold;
            color: #0f172a;
            line-height: 1;
        }

        .airport-city {
            font-size: 8.5pt;
            font-weight: bold;
            color: #334155;
            margin-top: 2px;
        }

        .airport-name {
            font-size: 6.8pt;
            color: #64748b;
            margin-top: 1px;
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

        .pax-table thead {
            display: table-header-group;
        }

        .pax-table tr {
            page-break-inside: avoid;
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

        .bag-tag {
            display: inline-block;
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
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
    </style>
</head>

<body>

    <div class="ticket-card">

        <!-- 1. Top Header Row (Logo & Booking Reference on Left + QR Code on Right) -->
        <table class="header-table" style="width: 100%;">
            <tr>
                <!-- Brand / Agency Logo & Booking Reference on Top Left -->
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
                        <span class="ref-code"><?= htmlspecialchars($invoiceId) ?></span>
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

        <!-- 2. Passenger & PNR Row -->
        <table style="width: 100%; margin-bottom: 4px;">
            <tr>
                <td style="width: <?= !empty($pnr) ? '62%' : '100%' ?>; vertical-align: top;">
                    <div class="meta-label"><?= count($paxList) > 1 ? 'LEAD PASSENGER' : 'PASSENGER' ?></div>
                    <div class="meta-value"><?= htmlspecialchars($primaryPassengerName) ?></div>
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

        <!-- 3. Flight Details Box (Route + Grids) -->
        <div class="flight-box">
            <!-- Route (Origin -> Destination with Exact Flight Path Icon) -->
            <table style="width: 100%;">
                <tr>
                    <!-- Origin -->
                    <td style="width: 35%; vertical-align: middle;">
                        <div class="airport-code"><?= htmlspecialchars($originCode) ?></div>
                        <div class="airport-city"><?= htmlspecialchars($originParsed['city']) ?></div>
                        <div class="airport-name"><?= htmlspecialchars($originParsed['airport']) ?></div>
                    </td>
                    <!-- Flight Route: crisp vector commercial flight path (PNG base64) -->
                    <td style="width: 30%; vertical-align: middle; text-align: center;">
                        <img src="<?= $routeImgB64 ?>"
                            style="width: 160px; height: 24px; display: block; margin: 0 auto;" alt="Flight Route" />
                        <div style="font-size: 6pt; color: #94a3b8; text-align: center; margin-top: 2px;">
                            <?= htmlspecialchars($flightDuration) ?></div>
                    </td>
                    <!-- Destination -->
                    <td style="width: 35%; vertical-align: middle; text-align: right;">
                        <div class="airport-code" style="text-align: right;"><?= htmlspecialchars($destCode) ?></div>
                        <div class="airport-city" style="text-align: right;">
                            <?= htmlspecialchars($destParsed['city']) ?>
                        </div>
                        <div class="airport-name" style="text-align: right;">
                            <?= htmlspecialchars($destParsed['airport']) ?>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid 1: DATE, DEPARTURE, ARRIVAL, DURATION -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">DATE</div>
                        <div class="grid-value"><?= htmlspecialchars($depDateFormatted) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">DEPARTURE</div>
                        <div class="grid-value"><?= htmlspecialchars($depTimeFormatted) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">ARRIVAL</div>
                        <div class="grid-value"><?= htmlspecialchars($arrTimeFormatted) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">DURATION</div>
                        <div class="grid-value"><?= htmlspecialchars($flightDuration) ?></div>
                    </td>
                </tr>
            </table>

            <div class="divider-solid"></div>

            <!-- 4-Column Grid 2: FLIGHT, CLASS, BAGGAGE, PASSENGERS -->
            <table style="width: 100%;">
                <tr>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">FLIGHT</div>
                        <div class="grid-value"><?= htmlspecialchars($flightNum) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">CLASS</div>
                        <div class="grid-value"><?= htmlspecialchars($flightClass) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">BAGGAGE (ADT)</div>
                        <div class="grid-value"><?= htmlspecialchars($flightBaggage) ?></div>
                    </td>
                    <td style="width: 25%; vertical-align: top;">
                        <div class="grid-label">PASSENGERS</div>
                        <div class="grid-value"><?= count($paxList) ?> <?= count($paxList) > 1 ? 'Pax' : 'Pax' ?></div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 4. Passenger Details Card Box (Renders All Passengers) -->
        <div class="pax-card">
            <div class="pax-header">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; vertical-align: middle;">
                            <div class="pax-header-title">PASSENGER DETAILS</div>
                        </td>
                        <td style="width: 40%; text-align: right; vertical-align: middle;">
                            <div class="pax-header-meta"><?= count($paxList) ?> PASSENGER<?= count($paxList) > 1 ? 'S' : '' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="pax-body">
                <table class="pax-table">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">#</th>
                            <th style="width: 43%; text-align: left;">PASSENGER NAME</th>
                            <th style="width: 14%; text-align: center;">TYPE</th>
                            <th style="width: 18%; text-align: center;">SEAT</th>
                            <th style="width: 20%; text-align: center;">BAGGAGE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paxList as $pIdx => $pax): 
                            $numStr = str_pad((string)($pIdx + 1), 2, '0', STR_PAD_LEFT);
                            $pSubMeta = [];
                            if (!empty($pax['passport'])) {
                                $pSubMeta[] = 'Passport: ' . htmlspecialchars($pax['passport']);
                            }
                            if (!empty($pax['nationality'])) {
                                $pSubMeta[] = htmlspecialchars($pax['nationality']);
                            }
                        ?>
                            <tr>
                                <td style="width: 5%; text-align: center;" class="pax-num"><?= $numStr ?></td>
                                <td style="width: 43%; text-align: left;">
                                    <div class="pax-name"><?= htmlspecialchars($pax['name']) ?></div>
                                    <?php if (!empty($pSubMeta)): ?>
                                        <div class="pax-meta-sub"><?= implode(' • ', $pSubMeta) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 14%; text-align: center;">
                                    <span class="pax-type-badge"><?= htmlspecialchars($pax['type_label'] ?? $pax['type']) ?></span>
                                </td>
                                <td style="width: 18%; text-align: center;">
                                    <?php if (!empty($pax['seat']) && $pax['seat'] !== '-'): ?>
                                        <span class="seat-tag"><?= htmlspecialchars($pax['seat']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 7pt;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 20%; text-align: center;">
                                    <?php if (!empty($pax['baggage']) && $pax['baggage'] !== '-'): ?>
                                        <span class="bag-tag"><?= htmlspecialchars($pax['baggage']) ?></span>
                                    <?php elseif (!empty($flightBaggage) && $flightBaggage !== '-'): ?>
                                        <span class="bag-tag"><?= htmlspecialchars($flightBaggage) ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 7pt;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Fare Details Card Box -->
        <div class="fare-card">
            <!-- Dark Blue Header Bar (div with top rounded border) -->
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
            <!-- Itemized Amounts -->
            <div class="fare-body">
                <table style="width: 100%;">
                    <tr>
                        <td class="fare-item-label">Base Fare</td>
                        <td class="fare-item-val"><?= number_format($baseFare, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="fare-item-label">Taxes &amp; Fees</td>
                        <td class="fare-item-val"><?= number_format($taxAmount, 2) ?></td>
                    </tr>
                    <?php if ($ancTotal > 0): ?>
                        <tr>
                            <td class="fare-item-label">Passenger Service Fee / Extras</td>
                            <td class="fare-item-val"><?= number_format($ancTotal, 2) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="2" style="padding: 5px 0 2px 0;">
                            <div style="border-top: 1px dashed #cbd5e1;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="fare-total-label">TOTAL AMOUNT</td>
                        <td class="fare-total-val"><?= htmlspecialchars($currency) ?>
                            <?= htmlspecialchars($totalAmount) ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 5. Thank You & Wish Banner -->
        <table class="wish-banner" style="width: 100%;">
            <tr>
                <td style="vertical-align: middle; text-align: center; padding: 3px 8px;">
                    <div class="wish-text">
                        Thank you for choosing to fly with us. Wishing you a pleasant journey!
                    </div>
                </td>
            </tr>
        </table>

        <!-- 6. Important Information / Instructions Preserved -->
        <div class="instructions-card">
            <div class="instructions-title">
                <?= htmlspecialchars(translateTo('important_information', $language) ?: 'Important Information') ?>
            </div>
            <table>
                <tr>
                    <td style="width: 50%; vertical-align: top; padding-right: 8px;">
                        <div class="instruction-item">•
                            <?= htmlspecialchars(translateTo('flights_arrive_early_notice', $language) ?: 'Please arrive at the airport at least 2-3 hours before departure.') ?>
                        </div>
                        <div class="instruction-item">•
                            <?= htmlspecialchars(translateTo('flights_valid_id_notice', $language) ?: 'Valid photo identification is required for all passengers.') ?>
                        </div>
                        <div class="instruction-item">•
                            <?= htmlspecialchars(translateTo('flights_baggage_notice', $language) ?: 'Check baggage allowance and restrictions before traveling.') ?>
                        </div>
                    </td>
                    <td style="width: 50%; vertical-align: top; padding-left: 8px;">
                        <div class="instruction-item">•
                            <?= htmlspecialchars(translateTo('flights_not_boarding_pass_notice', $language) ?: 'This booking confirmation is not an official boarding pass.') ?>
                        </div>
                        <div class="instruction-item">•
                            <?= htmlspecialchars(translateTo('flights_status_updates_notice', $language) ?: 'For real-time flight status updates, check with the airline.') ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

    </div>

</body>

</html>