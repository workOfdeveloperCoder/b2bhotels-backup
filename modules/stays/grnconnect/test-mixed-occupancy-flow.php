<?php
/**
 * GRNConnect mixed-occupancy issue + cancel test (CLI only).
 *
 * Search occupancy:
 *   Room 1: 2 Adults
 *   Room 2: 1 Adult + 2 Children (ages 6, 8)
 *   Room 3: 1 Adult + 1 Infant   (age 1 — GRN has no infant type)
 *
 * Picks a random hotel/rate, books with a sandbox test card when PAN is
 * required, then cancels. Logs under modules/stays/grnconnect/logs/
 *
 * Usage: php modules/stays/grnconnect/test-mixed-occupancy-flow.php [--no-cancel]
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$baseDir = dirname(__DIR__, 3);
chdir($baseDir);

$autoload = $baseDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname($baseDir) . '/b2bhotels/vendor/autoload.php';
}
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload not found.\n");
    exit(1);
}
require_once $autoload;

$envFile = $baseDir . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $_ENV[$k] = trim($v, " \t\"'");
    }
}

$env = $_ENV;
$db = new Medoo\Medoo([
    'type'     => 'mysql',
    'host'     => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_DATABASE'] ?? '',
    'username' => $env['DB_USERNAME'] ?? 'root',
    'password' => $env['DB_PASSWORD'] ?? '',
    'charset'  => 'utf8mb4',
]);

require_once __DIR__ . '/api.php';

if (!function_exists('grnconnect_log')) {
    function grnconnect_log($category, $action, $requestData, $responseData, $extraId = '', $meta = [])
    {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $timestamp = date('Y-m-d_H-i-s');
        $uniq      = uniqid();
        $prefix    = $category . ($action ? "_{$action}" : '');
        $parseBody = static function ($data) {
            if (is_array($data) || is_object($data)) {
                return $data;
            }
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : $data;
            }
            return $data;
        };
        @file_put_contents("{$dir}/{$prefix}_request_{$timestamp}_{$uniq}.json", json_encode([
            'url'     => $meta['url'] ?? '',
            'method'  => $meta['method'] ?? '',
            'headers' => $meta['headers'] ?? [],
            'meta'    => ['http_code' => $meta['http_code'] ?? '', 'duration_ms' => $meta['duration_ms'] ?? ''],
            'request' => $parseBody($requestData),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @file_put_contents("{$dir}/{$prefix}_response_{$timestamp}_{$uniq}.json", json_encode([
            'response_body' => $parseBody($responseData),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

function grnconnect_module($db)
{
    return $db->get('modules', '*', ['name' => 'grnconnect', 'type' => 'stays']);
}

function test_parse_recheck_rate(array $res)
{
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    foreach ([
        $data['hotel']['rates'][0] ?? null,
        $data['hotel']['rate'] ?? null,
        $data['hotel']['min_rate'] ?? null,
        $data['hotels'][0]['rates'][0] ?? null,
        $data['hotels'][0]['min_rate'] ?? null,
        $data['rate'] ?? null,
    ] as $rate) {
        if (is_array($rate) && !empty($rate['rate_key'])) {
            return $rate;
        }
    }
    return null;
}

function test_rate_room_count(array $rate)
{
    return max(
        (int)($rate['no_of_rooms'] ?? 0),
        is_array($rate['rooms'] ?? null) ? count($rate['rooms']) : 0
    );
}

function test_unique_pax_name($base, array &$used)
{
    $name = preg_replace('/[^A-Za-z]/', '', (string)$base) ?: 'Guest';
    $out  = $name;
    $n    = 0;
    while (isset($used[strtolower($out)])) {
        $n++;
        $out = $name . ($n === 1 ? 'A' : ($n === 2 ? 'B' : chr(64 + $n)));
    }
    $used[strtolower($out)] = true;
    return $out;
}

function test_paxes_for_rate_room(array $rateRoom, array &$usedNames)
{
    $adults   = max(1, (int)($rateRoom['no_of_adults'] ?? 2));
    $children = max(0, (int)($rateRoom['no_of_children'] ?? 0));
    $ages     = is_array($rateRoom['children_ages'] ?? null) ? array_values($rateRoom['children_ages']) : [];
    while (count($ages) < $children) {
        $ages[] = 1;
    }
    $ages = array_slice($ages, 0, $children);

    $adultPool = ['Hamza', 'Ali', 'Omar', 'Sara', 'Noor', 'Zain'];
    $paxes = [];
    for ($a = 0; $a < $adults; $a++) {
        $paxes[] = [
            'title'   => 'Mr.',
            'name'    => test_unique_pax_name($adultPool[$a] ?? 'Guest', $usedNames),
            'surname' => 'Certification',
            'type'    => 'AD',
        ];
    }
    foreach ($ages as $age) {
        $age = (int)$age;
        $age = $age >= 1 ? min(17, $age) : 1;
        $paxes[] = [
            'title'   => 'Mstr.',
            'name'    => test_unique_pax_name($age <= 1 ? 'Infant' : 'Child', $usedNames),
            'surname' => 'Certification',
            'type'    => 'CH',
            'age'     => $age,
        ];
    }
    return $paxes;
}

$noCancel = in_array('--no-cancel', $argv ?? [], true);

$out = static function ($step, $status, $detail = '') {
    $icon = $status === 'PASS' ? 'OK' : ($status === 'SKIP' ? '--' : 'XX');
    echo sprintf("[%s] %-28s %s%s\n", $icon, $step, $status, $detail !== '' ? " — {$detail}" : '');
};

$fail = static function ($step, $msg) use ($out) {
    $out($step, 'FAIL', $msg);
    exit(1);
};

echo "GRNConnect Mixed Occupancy Issue + Cancel\n";
echo "Room 1: 2 Adults | Room 2: 1 Adult + 2 Children | Room 3: 1 Adult + 1 Infant\n";
echo str_repeat('=', 72) . "\n";

$module = grnconnect_module($db);
if (!$module || empty($module['c1']) || empty($module['c2'])) {
    $fail('Setup', 'GRNConnect module not configured');
}

$client = GRNConnectClient::fromModule($module);
$host   = parse_url($module['c2'], PHP_URL_HOST) ?: 'configured';
$out('Setup', 'PASS', "sandbox host: {$host}");

$checkin     = date('Y-m-d', strtotime('+21 days'));
$checkout    = date('Y-m-d', strtotime('+22 days'));
$nationality = 'PK';
$currency    = 'USD';
$grnRooms    = [
    ['adults' => 2],
    ['adults' => 1, 'children_ages' => [6, 8]],
    ['adults' => 1, 'children_ages' => [1]],
];

$testCard = [
    'card_type'     => 'VI',
    'card_number'   => '4111111111111111',
    'card_cv2'      => '123',
    'expiry_month'  => '12',
    'expiry_year'   => (string)((int)date('Y') + 3),
    'given_name'    => 'Test',
    'family_name'   => 'Certification',
    'address_line1' => 'Sheikh Zayed Road',
    'city_name'     => 'Dubai',
];

$hotelCodes = [];
if (!empty($module['database'])) {
    try {
        $contentDb = new Medoo\Medoo([
            'type'     => 'mysql',
            'host'     => $module['host'] ?: 'localhost',
            'database' => $module['database'],
            'username' => $module['username'] ?? 'root',
            'password' => $module['password'] ?? '',
            'charset'  => 'utf8mb4',
        ]);
        $city = $contentDb->get('grnconnect_cities', 'city_code', [
            'OR'    => ['search_name[~]' => 'Dubai', 'name[~]' => 'Dubai'],
            'LIMIT' => 1,
        ]);
        if ($city) {
            $rows = $contentDb->select('grnconnect_hotels', 'hotel_code', [
                'city_code' => $city,
                'LIMIT'     => 80,
            ]) ?: [];
            $hotelCodes = array_values(array_filter(array_map('strval', $rows)));
        }
    } catch (Throwable $e) {
        $hotelCodes = [];
    }
}
if (empty($hotelCodes)) {
    $hotelCodes = ['1847163', '1847208', '1847146', '1847147', '1847148', '1847911'];
    $out('Hotel codes', 'SKIP', 'fallback list');
} else {
    $out('Hotel codes', 'PASS', count($hotelCodes) . ' from content DB');
}
shuffle($hotelCodes);

$searchRes = $client->availability([
    'hotel_codes'        => array_slice($hotelCodes, 0, 50),
    'checkin'            => $checkin,
    'checkout'           => $checkout,
    'client_nationality' => $nationality,
    'currency'           => $currency,
    'rates'              => 'concise',
    'rooms'              => $grnRooms,
], 60);

if (!$searchRes['success']) {
    $fail('A. Search', $searchRes['error'] ?: 'availability failed');
}

$searchId = (string)($searchRes['data']['search_id'] ?? '');
$hotels   = $searchRes['data']['hotels'] ?? [];
if ($searchId === '' || empty($hotels)) {
    $fail('A. Search', 'empty search_id or hotels');
}
shuffle($hotels);
$out('A. Search', 'PASS', 'sid=' . substr($searchId, 0, 12) . '… hotels=' . count($hotels));

$candidates = [];
foreach ($hotels as $h) {
    $mr = $h['min_rate'] ?? null;
    if (!is_array($mr) || (float)($mr['price'] ?? 0) <= 0) {
        continue;
    }
    if (test_rate_room_count($mr) >= 3) {
        $candidates[] = $h;
    }
}
if (empty($candidates)) {
    $candidates = $hotels;
}
$pickHotel = $candidates[array_rand($candidates)];
$hcode     = (string)($pickHotel['hotel_code'] ?? '');
$hotelName = (string)($pickHotel['name'] ?? $hcode);
if ($hcode === '') {
    $fail('Pick hotel', 'no hotel_code');
}
$out('Pick hotel', 'PASS', "random {$hotelName} ({$hcode})");

$refetchRes = $client->refetch($searchId, $hcode, true);
if (!$refetchRes['success']) {
    $fail('B. Refetch bundled', $refetchRes['error'] ?: 'refetch failed');
}

$data     = $refetchRes['data'] ?? [];
$searchId = (string)($data['search_id'] ?? $searchId);
$refHotel = $data['hotel'] ?? ($data['hotels'][0] ?? null);
$rates    = is_array($refHotel) ? ($refHotel['rates'] ?? []) : [];
$threeRoom = [];
foreach ($rates as $r) {
    if (is_array($r) && (float)($r['price'] ?? 0) > 0 && test_rate_room_count($r) >= 3) {
        $threeRoom[] = $r;
    }
}
$pickRate = !empty($threeRoom) ? $threeRoom[array_rand($threeRoom)] : ($rates[0] ?? null);
if (!$pickRate) {
    $fail('B. Refetch bundled', 'no rates');
}

$rateKey   = (string)($pickRate['rate_key'] ?? '');
$groupCode = (string)($pickRate['group_code'] ?? '');
$cityCode  = (string)($refHotel['city_code'] ?? ($pickHotel['city_code'] ?? ''));
$roomCount = test_rate_room_count($pickRate);
$out('B. Refetch bundled', 'PASS', 'rates=' . count($rates) . " picked_rooms={$roomCount}");

if ($rateKey === '' || $groupCode === '') {
    $fail('C. Recheck', 'missing rate_key/group_code');
}

$recheckRes = $client->recheck($searchId, $rateKey, $groupCode);
if (!$recheckRes['success']) {
    $fail('C. Recheck', $recheckRes['error'] ?: 'recheck failed');
}

$rcData    = $recheckRes['data'] ?? [];
$finalRate = test_parse_recheck_rate($recheckRes);
if (!$finalRate) {
    $fail('C. Recheck', 'no rate in response');
}

$searchId    = (string)($rcData['search_id'] ?? $searchId);
$rateKey     = (string)($finalRate['rate_key'] ?? $rateKey);
$groupCode   = (string)($finalRate['group_code'] ?? $groupCode);
$roomCode    = (string)($finalRate['room_code'] ?? '');
$price       = (float)($finalRate['price'] ?? 0);
$panRequired = !empty($finalRate['pan_required']);
$out('C. Recheck', 'PASS', "price={$price} {$currency} pan_required=" . ($panRequired ? 'yes' : 'no'));

$matchRooms = [];
foreach ($finalRate['rooms'] ?? [] as $room) {
    if (is_array($room)) {
        $matchRooms[] = $room;
    }
}
if (empty($matchRooms)) {
    $fail('C. Recheck', 'recheck returned no rooms[]');
}

$invoiceId = 'TEST' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$usedNames = [];
$itemRooms = [];
foreach ($matchRooms as $q => $rateRoom) {
    $roomEl = ['paxes' => test_paxes_for_rate_room($rateRoom, $usedNames)];
    $ref = trim((string)($rateRoom['room_reference'] ?? ''));
    if ($ref !== '') {
        $roomEl['room_reference'] = $ref;
    }
    $itemRooms[] = $roomEl;
    $out(
        'Room ' . ($q + 1) . ' payload',
        $ref !== '' ? 'PASS' : 'FAIL',
        'adults=' . (int)($rateRoom['no_of_adults'] ?? 0)
        . ' children=' . (int)($rateRoom['no_of_children'] ?? 0)
        . ' ref=' . ($ref !== '' ? substr($ref, 0, 16) . '…' : '(missing)')
    );
}

$missingRefs = 0;
foreach ($itemRooms as $roomEl) {
    if (trim((string)($roomEl['room_reference'] ?? '')) === '') {
        $missingRefs++;
    }
}
if ($missingRefs > 0 && $missingRefs < count($itemRooms)) {
    $fail('D. Book', 'some rooms missing room_reference — not copying other rooms');
}

$bookItem = ['rate_key' => $rateKey, 'rooms' => $itemRooms];
if ($roomCode !== '') {
    $bookItem['room_code'] = $roomCode;
}

$bookPayload = [
    'search_id'         => $searchId,
    'hotel_code'        => $hcode,
    'city_code'         => $cityCode,
    'group_code'        => $groupCode,
    'booking_name'      => $invoiceId,
    'agent_reference'   => $invoiceId,
    'checkin'           => $checkin,
    'checkout'          => $checkout,
    'payment_type'      => 'AT_WEB',
    'purpose_of_travel' => 1,
    'holder'            => [
        'title'               => 'Mr.',
        'name'                => 'Hamza',
        'surname'             => 'Certification',
        'email'               => 'cert-test@example.com',
        'phone_number'        => '971500000000',
        'client_nationality'  => $nationality,
    ],
    'booking_items'    => [$bookItem],
    'booking_comments' => 'GRN mixed occupancy certification test',
    'credit_card'      => $testCard,
];

$bookRes = $client->book($bookPayload);
$cardErr = strtolower((string)($bookRes['error'] ?? ''));
if (!$bookRes['success'] && (strpos($cardErr, 'card') !== false || strpos($cardErr, 'credit') !== false)) {
    if (!$panRequired) {
        unset($bookPayload['credit_card']);
        $out('D. Book card', 'SKIP', 'AT_WEB wallet — retry without card');
        $bookRes = $client->book($bookPayload);
    } else {
        $out('D. Book card', 'FAIL', $bookRes['error'] ?? 'invalid test card');
    }
} elseif (!empty($bookPayload['credit_card'])) {
    $out('D. Book card', 'PASS', 'sandbox VI 4111… pan_required=' . ($panRequired ? 'yes' : 'no'));
}

if (!$bookRes['success']) {
    $fail('D. Book', $bookRes['error'] ?: 'booking failed');
}

$bookData   = $bookRes['data'] ?? [];
$node       = $bookData['booking'] ?? ($bookData['bookings'][0] ?? $bookData);
$reference  = (string)($node['booking_reference'] ?? ($bookData['booking_reference'] ?? ''));
$bookStatus = strtolower((string)($node['booking_status'] ?? ($bookData['booking_status'] ?? '')));

if ($reference === '') {
    $fail('D. Book', 'missing booking_reference');
}

if (in_array($bookStatus, ['pending', 'processing', ''], true)) {
    for ($i = 0; $i < 12; $i++) {
        sleep(5);
        $fetch = $client->getBooking($reference, 'GRN');
        if (!$fetch['success']) {
            continue;
        }
        $fetched = $fetch['data']['booking'] ?? ($fetch['data']['bookings'][0] ?? $fetch['data']);
        $liveStatus = strtolower((string)($fetched['booking_status'] ?? ''));
        if ($liveStatus === 'confirmed') {
            $bookStatus = 'confirmed';
            break;
        }
        if (in_array($liveStatus, ['failed', 'rejected', 'cancelled'], true)) {
            $bookStatus = $liveStatus;
            break;
        }
    }
}
$out('D. Book', 'PASS', "ref={$reference} status={$bookStatus} rooms=" . count($itemRooms));

$fetchRes = $client->getBooking($reference, 'GRN');
$out('E. Fetch GRN ref', $fetchRes['success'] ? 'PASS' : 'FAIL', $fetchRes['success'] ? $reference : ($fetchRes['error'] ?? ''));

if ($noCancel) {
    $out('F. Cancel', 'SKIP', '--no-cancel');
} elseif ($bookStatus !== 'confirmed') {
    $out('F. Cancel', 'SKIP', "status={$bookStatus} — check with GRN Ops");
} else {
    $cancelRes = $client->cancel($reference);
    $cStatus   = strtolower((string)(($cancelRes['data'] ?? [])['status'] ?? ''));
    if ($cancelRes['success'] || in_array($cStatus, ['confirmed', 'pending', 'cancelled'], true)) {
        $out('F. Cancel', 'PASS', 'status=' . ($cStatus ?: 'ok'));
    } else {
        $out('F. Cancel', 'FAIL', $cancelRes['error'] ?? 'cancel failed');
        exit(1);
    }
}

echo str_repeat('-', 72) . "\n";
echo "Logs dir:  modules/stays/grnconnect/logs/\n";
echo "GRN ref:   {$reference}\n";
echo "Agent ref: {$invoiceId}\n";
echo "Hotel:     {$hotelName} ({$hcode})\n";
echo "Stay:      {$checkin} → {$checkout}\n";
echo "Rooms:     " . count($itemRooms) . " (each room_reference from that rooms[] entry)\n";
