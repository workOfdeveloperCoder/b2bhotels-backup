<?php
require_once __DIR__ . '/refundability.php';

// ============================================================================
// HOTELBEDS HOTEL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Search Hotelbeds hotels from local imported database with real-time API pricing,
// dynamic B2B/B2C markup application, and currency conversion.
//
// ENDPOINT: POST /stays/hotelbeds/search
//
// ============================================================================
// REQUEST PARAMETERS (matching manual hotel search structure)
// ============================================================================
//
// 1. destination (string)         - City/Location name (e.g., "Barcelona", "Madrid")
//                                   Searches hotelbeds_destinations and hotelbeds_hotels.city
//
// 2. destination_code (string)    - Optional: Destination code (e.g., "BCN", "MAD")
//
// 3. checkin (string)             - Check-in date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkin
//
// 4. checkout (string)            - Check-out date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkout
//
// 5. nationality (string)         - Guest nationality ISO code (e.g., "US", "GB")
//                                   Frontend: searchParams.nationality
//
// 6. rooms (integer)              - Number of rooms requested (default: 1)
//                                   Frontend: searchParams.rooms
//
// 7. adults (integer)             - Total adults across all rooms
//                                   Frontend: searchParams.adults
//
// 8. children (integer)           - Total children across all rooms
//                                   Frontend: searchParams.children
//
// 9. rooms_data (JSON string)     - Detailed room configuration array
//                                   Format: [{"adults":2,"children":1,"childAges":[5]}]
//
// 10. currency (string)           - Display currency code (e.g., "USD", "SAR")
//                                   Source: $searchSessionData['app_currency']
//                                   Default: USD
//
// 11. star_rating (string)        - Optional (legacy): single star rating (1-5 or "any")
//     star_ratings (JSON/CSV)     - Optional: star ratings to keep, e.g. [4,5]
//     accommodation_types (JSON)  - Optional: listing accommodation labels, e.g. ["Hotel","Villa"]
//     amenities (JSON)            - Optional: [{"label":"Free WiFi","terms":["free wifi","wifi",...]}]
//                                   The listing sends the terms it matches on itself, so
//                                   this endpoint holds no amenity vocabulary of its own.
//                                   These are catalogue filters: candidates are narrowed
//                                   BEFORE availability so every page already matches.
//                                   Response carries X-Filters-Applied so the listing knows.
//
// 12. page (integer)               - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 13. per_page (integer)           - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many hotels load at once
//
// ============================================================================
// PRICING & MARKUP LOGIC (Same as manual hotel system)
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Prices fetched from Hotelbeds Booking API (real-time availability)
// - Currency returned by API (usually hotel's local currency)
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: modules/helpers.php (lines 29-118)
// - Module type: 'stays' (retrieves hotelbeds markup from modules table)
// - Fields: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type: B2B (agents) vs B2C (customers)
//
// MARKUP ORDER (CRITICAL):
//   a) Apply markup to base price in ORIGINAL currency
//      Example: €50 + 20% B2C markup = €60
//   b) Convert marked-up price to display currency
//      Example: €60 × 1.10 USD rate = $66
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table
// - Base: USD (rate = 1.0)
// - Formula: (price / fromRate) × toRate
//
// STEP 4: TOTAL PRICE CALCULATION
// - API `net` is stay total (all nights) for that rate's occupancy (see rateKey `rooms~adults~children`)
// - Do not multiply by POST room count — each rate net already matches its occupancy line
// - price_per_night = MARKUP(net) ÷ nights
//
// ============================================================================

if (!defined('HOTELBEDS_SEARCH_BATCH_SIZE')) {
    define('HOTELBEDS_SEARCH_BATCH_SIZE', 100);
    define('HOTELBEDS_SEARCH_MAX_PER_REQUEST', 2000);
    define('HOTELBEDS_SEARCH_CACHE_TTL', 600);
    define('HOTELBEDS_SEARCH_MAX_BATCHES_PER_REQUEST', 5);
    // Wall-clock budget for the Availability batches of one search request.
    // 5 batches x 45s can outrun the PHP time limit, and a request killed
    // mid-batch returns nothing at all — the spinning loader with no results.
    // Stopping short instead returns the hotels found so far; scanned_through
    // is cached, so the next page request continues where this one stopped.
    define('HOTELBEDS_SEARCH_TIME_BUDGET', 75);
    // Rates Hotelbeds returns per room type in a listing search. The card only
    // shows each room type's cheapest price and an option count; booking goes
    // through the detail page, which fetches every rate for that one hotel.
    // Unlimited, a 100-hotel batch of Dubai 5-star hotels carried ~7,500 rates
    // (~8s from the API, and a multi-MB blob written to the session on every
    // page). Hotelbeds orders rates cheapest-first, so the hotel and room
    // minimum prices are unchanged by this cap.
    define('HOTELBEDS_SEARCH_MAX_RATES_PER_ROOM', 3);
}

/**
 * Per-phase stopwatch, reported as the X-Search-Timing response header so a
 * slow request can be traced to the catalogue DB, the Availability API or the
 * response build without touching the server. Marks are cumulative seconds
 * since the request started.
 */
function hotelbedsSearchMark(string $phase): void
{
    $GLOBALS['hotelbeds_search_marks'][$phase] = round(microtime(true) - ($GLOBALS['hotelbeds_search_started_at'] ?? microtime(true)), 3);
}

function hotelbedsSearchTimingHeader(): string
{
    $marks = $GLOBALS['hotelbeds_search_marks'] ?? [];
    $parts = [];
    $previous = 0.0;
    foreach ($marks as $phase => $at) {
        $parts[] = $phase . '=' . number_format($at - $previous, 3, '.', '');
        $previous = $at;
    }
    $parts[] = 'total=' . number_format(microtime(true) - ($GLOBALS['hotelbeds_search_started_at'] ?? microtime(true)), 3, '.', '');
    return 'X-Search-Timing: ' . implode(' ', $parts);
}

function hotelbedsSearchExtractStars(array $hotel): int
{
    if (isset($hotel['star_rating']) && $hotel['star_rating'] !== '' && $hotel['star_rating'] !== null) {
        return max(0, (int) round((float) $hotel['star_rating']));
    }
    if (!empty($hotel['category_code']) && preg_match('/\d+/', (string) $hotel['category_code'], $matches)) {
        return (int) $matches[0];
    }

    return 3;
}

/** Star count as the listing displays it: first digit of category_code, else 0. */
function hotelbedsSearchDisplayStars(array $hotel): int
{
    if (!empty($hotel['category_code']) && preg_match('/\d+/', (string) $hotel['category_code'], $matches)) {
        return (int) $matches[0];
    }
    return 0;
}

/**
 * Words of a hotel-name query worth matching on: lower-cased, punctuation
 * dropped, single characters ignored, de-duplicated, capped so a pasted
 * sentence cannot turn into dozens of LIKE clauses.
 */
function hotelbedsSearchNameTerms(string $query): array
{
    $query = mb_strtolower(trim($query));
    $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = [];
    foreach ($words as $word) {
        if (mb_strlen($word) >= 2 && !in_array($word, $terms, true)) {
            $terms[] = $word;
        }
    }
    return array_slice($terms, 0, 8);
}

/** Multi-value POST field: PHP array, JSON array string or comma-separated string. */
function hotelbedsSearchReadList($value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        $value = is_array($decoded) ? $decoded : explode(',', $trimmed);
    }
    return is_array($value) ? $value : [];
}

/**
 * Catalogue filters from the search POST:
 *   stars     → [int 1-5]
 *   types     → [normalised accommodation buckets]
 *   amenities → [label => [lower-cased search terms]] exactly as the listing sent them
 */
function hotelbedsSearchReadCatalogueFilters(array $post): array
{
    $stars = [];
    foreach (hotelbedsSearchReadList($post['star_ratings'] ?? []) as $star) {
        if (is_numeric($star) && (int) $star >= 1 && (int) $star <= 5 && !in_array((int) $star, $stars, true)) {
            $stars[] = (int) $star;
        }
    }
    $legacyStar = $post['star_rating'] ?? 'any';
    if (empty($stars) && is_numeric($legacyStar) && (int) $legacyStar >= 1 && (int) $legacyStar <= 5) {
        $stars[] = (int) $legacyStar;
    }
    sort($stars);

    $types = [];
    foreach (hotelbedsSearchReadList($post['accommodation_types'] ?? []) as $type) {
        if (is_array($type)) {
            continue;
        }
        $bucket = hotelbedsSearchAccommodationBucket((string) $type);
        if ($bucket !== '' && !in_array($bucket, $types, true)) {
            $types[] = $bucket;
        }
    }
    sort($types);

    $amenities = [];
    foreach (array_slice(hotelbedsSearchReadList($post['amenities'] ?? []), 0, 20) as $entry) {
        $label = trim((string) (is_array($entry) ? ($entry['label'] ?? '') : $entry));
        if ($label === '') {
            continue;
        }
        $terms = [mb_strtolower($label)];
        foreach ((is_array($entry) ? ($entry['terms'] ?? []) : []) as $term) {
            if (!is_array($term)) {
                $terms[] = mb_strtolower(trim((string) $term));
            }
        }
        $amenities[$label] = array_values(array_unique(array_filter($terms, static fn($t) => $t !== '')));
    }
    ksort($amenities);

    return ['stars' => $stars, 'types' => $types, 'amenities' => $amenities];
}

/**
 * Collapse a Hotelbeds accommodation code (H, A, P, …) or description onto the
 * bucket the listing compares on (its normalizeAccommodationType()).
 */
function hotelbedsSearchAccommodationBucket(string $type): string
{
    $raw = mb_strtolower(trim($type));
    if ($raw === '') {
        return 'hotel';
    }
    $has = static fn(string $needle): bool => strpos($raw, $needle) !== false;

    if ($has('apart') && ($has('hotel') || $raw === 'ah')) return 'apartment';
    if ($has('apartment') || $raw === 'a') return 'apartment';
    if ($has('villa') || $raw === 'v') return 'villa';
    if ($has('resort') || $raw === 'r') return 'resort';
    if ($has('guest') || $raw === 'gh') return 'guest house';
    if ($has('hostel') || $has('hostal') || $raw === 'hs' || $raw === 'ho') return 'hostel';
    if ($has('chalet') || $raw === 'ch') return 'chalet';
    if ($has('cottage')) return 'cottage';
    if ($has('bungalow') || $raw === 'bg') return 'bungalow';
    if ($has('holiday') || $has('vacation')) return 'holiday home';
    if ($has('hotel') || $raw === 'h') return 'hotel';
    return $raw;
}

/** Value for X-Filters-Applied: which catalogue filters narrowed this response. */
function hotelbedsSearchFiltersAppliedValue(array $filters): string
{
    $applied = [];
    foreach (['stars', 'types', 'amenities'] as $key) {
        if (!empty($filters[$key])) {
            $applied[] = $key;
        }
    }
    return $applied ? implode(',', $applied) : 'none';
}

/**
 * Narrow candidate rows by the listing's catalogue filters BEFORE availability.
 *
 * Stars and accommodation type are read off the row; amenities are looked up in
 * hotelbeds_amenities / hotelbeds_facilities with the terms the listing sent for
 * each label (the ones it matches on client-side), so the two never disagree
 * about a match. Every selected amenity must be present (AND); within one
 * amenity any term counts.
 */
function hotelbedsSearchApplyCatalogueFilters($hotelbedsDb, array $candidates, array $filters): array
{
    $stars = $filters['stars'] ?? [];
    $types = $filters['types'] ?? [];
    $amenities = $filters['amenities'] ?? [];

    if (empty($candidates) || (empty($stars) && empty($types) && empty($amenities))) {
        return $candidates;
    }

    if (!empty($stars)) {
        $candidates = array_values(array_filter($candidates, static function ($hotel) use ($stars) {
            return in_array(hotelbedsSearchDisplayStars($hotel), $stars, true);
        }));
    }

    if (!empty($types) && !empty($candidates)) {
        // accommodation_type holds either a code (H, A, P…) or a description. One
        // lookup for the whole code table instead of one query per hotel.
        $typeNames = [];
        try {
            foreach ($hotelbedsDb->select('hotelbeds_accommodations', ['code', 'type_description']) ?: [] as $row) {
                $typeNames[strtoupper((string) $row['code'])] = (string) $row['type_description'];
            }
        } catch (Throwable $e) {
            $typeNames = [];
        }
        $candidates = array_values(array_filter($candidates, static function ($hotel) use ($types, $typeNames) {
            $raw = trim((string) ($hotel['accommodation_type'] ?? ''));
            $resolved = $typeNames[strtoupper($raw)] ?? $raw;
            return in_array(hotelbedsSearchAccommodationBucket($resolved !== '' ? $resolved : 'Hotel'), $types, true);
        }));
    }

    if (!empty($amenities) && !empty($candidates)) {
        $codes = array_values(array_unique(array_map(static fn($h) => (string) $h['hotel_code'], $candidates)));
        $pdo = $hotelbedsDb->pdo;

        foreach ($amenities as $label => $terms) {
            if (empty($terms) || empty($codes)) {
                continue;
            }
            $matched = [];
            foreach (array_chunk($codes, 500) as $chunk) {
                $params = $chunk;
                $likes = [];
                foreach ($terms as $term) {
                    $likes[] = '(LOWER(f.description) LIKE ? OR LOWER(a.facility_description) LIKE ?)';
                    $needle = '%' . $term . '%';
                    $params[] = $needle;
                    $params[] = $needle;
                }
                $sql = 'SELECT DISTINCT a.hotel_code
                        FROM hotelbeds_amenities a
                        LEFT JOIN hotelbeds_facilities f ON f.code = a.facility_code
                        WHERE a.hotel_code IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                          AND (' . implode(' OR ', $likes) . ')';
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
                        $matched[(string) $code] = true;
                    }
                } catch (Throwable $e) {
                    error_log('Hotelbeds search amenity filter failed (' . $label . '): ' . $e->getMessage());
                    // Cannot evaluate this amenity — leave the set as is rather than dropping everything.
                    continue 2;
                }
            }
            $codes = array_values(array_filter($codes, static fn($code) => isset($matched[$code])));
        }

        $keep = array_flip($codes);
        $candidates = array_values(array_filter($candidates, static function ($hotel) use ($keep) {
            return isset($keep[(string) $hotel['hotel_code']]);
        }));
    }

    return $candidates;
}

/**
 * Everything the response build needs from the content DB for one page of
 * hotels, in four queries instead of one per hotel / room / facility.
 *
 * @return array{hotel_images: array, room_images: array, hotel_amenities: array, facility_names: array}
 */
function hotelbedsSearchLoadPageCatalogue($hotelbedsDb, array $hotelCodes): array
{
    $out = ['hotel_images' => [], 'room_images' => [], 'hotel_amenities' => [], 'facility_names' => []];
    $hotelCodes = array_values(array_unique(array_filter(array_map('strval', $hotelCodes), 'strlen')));
    if (empty($hotelCodes) || !$hotelbedsDb) {
        return $out;
    }

    try {
        foreach ($hotelbedsDb->select('hotelbeds_facilities', ['code', 'description']) ?: [] as $row) {
            $out['facility_names'][(string) $row['code']] = (string) $row['description'];
        }
    } catch (Throwable $e) {
        error_log('Hotelbeds search facilities preload failed: ' . $e->getMessage());
    }

    try {
        $images = $hotelbedsDb->select('hotelbeds_hotel_images', ['hotel_code', 'room_code', 'image_url', 'image_type'], [
            'hotel_code' => $hotelCodes,
            'ORDER' => ['hotel_code' => 'ASC', 'image_order' => 'ASC'],
        ]) ?: [];
        foreach ($images as $img) {
            $hotelCode = (string) $img['hotel_code'];
            $out['hotel_images'][$hotelCode][] = ['image_url' => $img['image_url'], 'image_type' => $img['image_type']];
            $roomCode = trim((string) ($img['room_code'] ?? ''));
            if ($roomCode !== '') {
                $out['room_images'][$hotelCode][$roomCode][] = ['image_url' => $img['image_url']];
            }
        }
    } catch (Throwable $e) {
        error_log('Hotelbeds search images preload failed: ' . $e->getMessage());
    }

    try {
        $amenities = $hotelbedsDb->select('hotelbeds_amenities', [
            '[>]hotelbeds_facilities' => ['facility_code' => 'code']
        ], [
            'hotelbeds_amenities.hotel_code',
            'hotelbeds_amenities.facility_description',
            'hotelbeds_facilities.description',
        ], [
            'hotelbeds_amenities.hotel_code' => $hotelCodes,
            'ORDER' => ['hotelbeds_amenities.hotel_code' => 'ASC', 'hotelbeds_amenities.order_by' => 'ASC'],
        ]) ?: [];
        foreach ($amenities as $row) {
            $out['hotel_amenities'][(string) $row['hotel_code']][] = [
                'facility_description' => $row['facility_description'],
                'description' => $row['description'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Hotelbeds search amenities preload failed: ' . $e->getMessage());
    }

    return $out;
}

/** Budget-first candidate ordering before live Availability (local heuristic). */
function hotelbedsSearchOrderCandidateCodes(array $candidateHotelsByCode): array
{
    $list = array_values($candidateHotelsByCode);
    usort($list, function ($a, $b) {
        $starsA = hotelbedsSearchExtractStars($a);
        $starsB = hotelbedsSearchExtractStars($b);
        if ($starsA !== $starsB) {
            return $starsA <=> $starsB;
        }

        $rankA = (int) ($a['ranking'] ?? 999999);
        $rankB = (int) ($b['ranking'] ?? 999999);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return array_values(array_map(static function ($hotel) {
        return (string) $hotel['hotel_code'];
    }, $list));
}

function hotelbedsSearchPostAvailability(
    string $baseUrl,
    string $apiKey,
    string $apiSecret,
    array $apiPayload,
    bool $useMtls,
    int $connectTimeout,
    int $requestTimeout
): array {
    $payload = $apiPayload;
    $hotelCodes = $payload['hotels']['hotel'] ?? [];
    if (count($hotelCodes) > HOTELBEDS_SEARCH_MAX_PER_REQUEST) {
        $hotelCodes = array_slice($hotelCodes, 0, HOTELBEDS_SEARCH_MAX_PER_REQUEST);
        $payload['hotels']['hotel'] = $hotelCodes;
    }

    $attempts = 3;
    $lastResult = [
        'http_code' => 0,
        'body' => '',
        'curl_error' => 'No attempt made',
        'payload' => $payload,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(15, $connectTimeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(45, $requestTimeout));

        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($ch, $useMtls);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/hotels',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(45, $requestTimeout),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature,
                'Accept: application/json',
                'Content-Type: application/json',
                'Accept-Encoding: gzip',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $lastResult = [
            'http_code' => $httpCode,
            'body' => $body,
            'curl_error' => $curlError,
            'payload' => $payload,
        ];

        if ($httpCode === 200 && !empty($body)) {
            return $lastResult;
        }

        $retryable = ($curlError !== '')
            && (stripos($curlError, 'timed out') !== false || stripos($curlError, 'resolve') !== false || stripos($curlError, 'Could not resolve') !== false);

        if (!$retryable || $attempt >= $attempts) {
            break;
        }

        usleep(750000 * $attempt);
    }

    return $lastResult;
}

function hotelbedsSearchParseAvailableFromResponse(?array $apiData): array
{
    $available = [];
    if (!is_array($apiData) || empty($apiData['hotels']['hotels']) || !is_array($apiData['hotels']['hotels'])) {
        return $available;
    }

    foreach ($apiData['hotels']['hotels'] as $apiHotel) {
        $minRate = (float) ($apiHotel['minRate'] ?? 0);
        if (
            $minRate > 0
            && isset($apiHotel['rooms'])
            && is_array($apiHotel['rooms'])
            && count($apiHotel['rooms']) > 0
        ) {
            $available[] = [
                'code' => (string) $apiHotel['code'],
                'min_rate' => $minRate,
            ];
        }
    }

    return $available;
}

function hotelbedsSearchMergeAvailable(array &$availableByCode, array $newEntries): void
{
    foreach ($newEntries as $entry) {
        $code = (string) ($entry['code'] ?? '');
        if ($code === '') {
            continue;
        }
        if (!isset($availableByCode[$code]) || (float) $entry['min_rate'] < (float) $availableByCode[$code]['min_rate']) {
            $availableByCode[$code] = [
                'code' => $code,
                'min_rate' => (float) $entry['min_rate'],
            ];
        }
    }
}

function hotelbedsSearchAvailableListSorted(array $availableByCode): array
{
    $list = array_values($availableByCode);
    usort($list, static function ($a, $b) {
        if ($a['min_rate'] !== $b['min_rate']) {
            return $a['min_rate'] <=> $b['min_rate'];
        }

        return strcmp($a['code'], $b['code']);
    });

    return $list;
}

function hotelbedsSearchMergeApiHotelsCache(array &$cacheEntry, ?array $apiData): void
{
    if (!is_array($apiData) || empty($apiData['hotels']['hotels']) || !is_array($apiData['hotels']['hotels'])) {
        return;
    }

    if (!isset($cacheEntry['api_hotels']) || !is_array($cacheEntry['api_hotels'])) {
        $cacheEntry['api_hotels'] = [];
    }

    foreach ($apiData['hotels']['hotels'] as $apiHotel) {
        $code = (string) ($apiHotel['code'] ?? '');
        if ($code !== '') {
            $cacheEntry['api_hotels'][$code] = $apiHotel;
        }
    }
}

function hotelbedsSearchCachedApiHotelsForCodes(array $cacheEntry, array $hotelCodes): array
{
    $cached = $cacheEntry['api_hotels'] ?? [];
    if (!is_array($cached) || empty($cached)) {
        return [];
    }

    $hotels = [];
    foreach ($hotelCodes as $code) {
        $code = (string) $code;
        if ($code !== '' && isset($cached[$code]) && is_array($cached[$code])) {
            $hotels[] = $cached[$code];
        }
    }

    return $hotels;
}

function hotelbedsSearchSaveProgressiveCache(string $cacheKey, array $cacheEntry): void
{
    if (session_id() === '') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['hotelbeds_availability_cache'] = $_SESSION['hotelbeds_availability_cache'] ?? [];
    foreach ($_SESSION['hotelbeds_availability_cache'] as $key => $entry) {
        if (!is_array($entry) || (time() - (int) ($entry['created_at'] ?? 0)) >= HOTELBEDS_SEARCH_CACHE_TTL) {
            unset($_SESSION['hotelbeds_availability_cache'][$key]);
        }
    }

    $_SESSION['hotelbeds_availability_cache'][$cacheKey] = $cacheEntry;
    session_write_close();
}

/**
 * Fetch Availability in batches of HOTELBEDS_SEARCH_BATCH_SIZE until enough
 * priced hotels exist for the requested page, or candidates are exhausted.
 */
function hotelbedsSearchProgressiveFill(
    array &$cacheEntry,
    array $baseApiPayload,
    string $baseUrl,
    string $apiKey,
    string $apiSecret,
    bool $useMtls,
    int $connectTimeout,
    int $requestTimeout,
    int $neededCount,
    int &$batchCallsMade,
    $db = null,
    ?float $deadline = null
): void {
    $availableByCode = [];
    foreach ($cacheEntry['available'] ?? [] as $row) {
        if (!empty($row['code'])) {
            $availableByCode[(string) $row['code']] = [
                'code' => (string) $row['code'],
                'min_rate' => (float) ($row['min_rate'] ?? 0),
            ];
        }
    }

    $candidateCodes = $cacheEntry['candidate_codes'] ?? [];
    $scannedThrough = (int) ($cacheEntry['scanned_through'] ?? 0);
    $batchSize = HOTELBEDS_SEARCH_BATCH_SIZE;

    while (
        count($availableByCode) < $neededCount
        && $scannedThrough < count($candidateCodes)
        && $batchCallsMade < HOTELBEDS_SEARCH_MAX_BATCHES_PER_REQUEST
    ) {
        // Stop before a batch that cannot finish inside this request's budget.
        if ($deadline !== null && microtime(true) >= $deadline) {
            error_log('Hotelbeds search: time budget reached after ' . $batchCallsMade . ' batch(es), returning partial results');
            break;
        }

        $batch = array_slice($candidateCodes, $scannedThrough, $batchSize);
        if (empty($batch)) {
            break;
        }

        $payload = $baseApiPayload;
        $payload['hotels'] = ['hotel' => array_map('intval', $batch)];

        $result = hotelbedsSearchPostAvailability(
            $baseUrl,
            $apiKey,
            $apiSecret,
            $payload,
            $useMtls,
            $connectTimeout,
            $requestTimeout
        );
        $batchCallsMade++;

        if ($db && function_exists('log_setting') && log_setting($db, 'hotelbeds') == '1') {
            $decoded = !empty($result['body']) ? json_decode($result['body'], true) : ['error' => $result['curl_error'] ?: 'Empty response'];
            logApiCall(
                'hotelbeds_search_batch',
                $result['payload'],
                $decoded,
                $result['http_code'],
                function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs'),
                'Hotelbeds_Search_Batch'
            );
        }

        if ($result['http_code'] === 200 && !empty($result['body'])) {
            $scannedThrough += count($batch);
            $apiData = json_decode($result['body'], true);
            hotelbedsSearchMergeAvailable($availableByCode, hotelbedsSearchParseAvailableFromResponse(is_array($apiData) ? $apiData : null));
            hotelbedsSearchMergeApiHotelsCache($cacheEntry, is_array($apiData) ? $apiData : null);
            continue;
        }

        $curlError = (string) ($result['curl_error'] ?? '');
        $retryable = ($result['http_code'] === 0 && $curlError !== '')
            && (stripos($curlError, 'timed out') !== false
                || stripos($curlError, 'resolve') !== false
                || stripos($curlError, 'Could not resolve') !== false);

        error_log('Hotelbeds progressive batch failed HTTP ' . $result['http_code'] . ': ' . $curlError);

        if ($retryable) {
            // Keep scanned_through unchanged so the next search retries this batch.
            break;
        }

        $scannedThrough += count($batch);
    }

    $cacheEntry['scanned_through'] = $scannedThrough;
    $cacheEntry['available'] = hotelbedsSearchAvailableListSorted($availableByCode);
    $cacheEntry['exhausted'] = $scannedThrough >= count($candidateCodes);
}

$router->post('stays/hotelbeds/search', function () use ($db) {
    // Progressive batches may run up to 5 × Availability calls on deep pages.
    // Keep the PHP limit comfortably above the batch budget so the request ends
    // by returning results, never by being killed mid-call.
    @set_time_limit(180);
    $searchStartedAt = microtime(true);
    $searchDeadline = $searchStartedAt + HOTELBEDS_SEARCH_TIME_BUDGET;
    $GLOBALS['hotelbeds_search_started_at'] = $searchStartedAt;
    $GLOBALS['hotelbeds_search_marks'] = [];
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
            error_log('search_guard: session lock released');
        }
    }

    if (connection_aborted()) {
        if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
            error_log('search_guard: request aborted early');
        }
        exit;
    }

    // ========================================
    // CLEAN OUTPUT BUFFER
    // ========================================
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION - Extract search parameters
    // ========================================
    $destination = $_POST['destination'] ?? $_POST['city'] ?? '';
    $destination_code = $_POST['destination_code'] ?? '';
    // Atlas zone inside that destination, when the guest picked an area rather than a
    // whole destination. Only meaningful alongside destination_code.
    $destination_zone = strtoupper(trim((string) ($_POST['destination_zone'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{1,10}$/', $destination_zone)) {
        $destination_zone = '';
    }

    $page = max(1, (int) ($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));

    $star_rating = $_POST['star_rating'] ?? 'any';
    // Catalogue filters from the listing sidebar (stars / type / amenities).
    $catalogueFilters = hotelbedsSearchReadCatalogueFilters($_POST);
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';
    $rooms = (int) ($_POST['rooms'] ?? 1);
    $adults = (int) ($_POST['adults'] ?? 2);
    $children = (int) ($_POST['children'] ?? $_POST['childs'] ?? 0);
    $nationality = $_POST['nationality'] ?? 'US';
    $rooms_data_json = $_POST['rooms_data'] ?? '[]';
    $currency = $_POST['currency'] ?? 'USD';
    $hotel_name = trim($_POST['hotel_name'] ?? '');
    // ISO country of the destination the guest actually picked. City names are not
    // unique worldwide, so without this a name lookup happily merges Bali (Indonesia)
    // with Bali (Rajasthan, India) and Syracuse (USA) with Syracuse (Sicily).
    $destination_country = strtoupper(trim((string) ($_POST['destination_country'] ?? '')));
    if (!preg_match('/^[A-Z]{2,3}$/', $destination_country)) {
        $destination_country = '';
    }

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // ========================================
    // PARSE ROOMS DATA
    // ========================================
    $rooms_data = [];
    try {
        $rooms_data = json_decode($rooms_data_json, true);
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
    } catch (Exception $e) {
        error_log('Hotelbeds search - Failed to parse rooms_data: ' . $e->getMessage());
        $rooms_data = [];
    }

    // Extract child ages for logging
    $all_child_ages = [];
    foreach ($rooms_data as $room_index => $room) {
        if (isset($room['children']) && $room['children'] > 0 && !empty($room['childAges'])) {
            foreach ($room['childAges'] as $age) {
                $all_child_ages[] = [
                    'room' => $room_index + 1,
                    'age' => (int) $age
                ];
            }
        }
    }

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'destination' => $destination,
            'destination_code' => $destination_code,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'nationality' => $nationality,
            'rooms_data' => $rooms_data,
            'child_ages' => $all_child_ages,
            'currency' => $sessionCurrency,
            'star_rating' => $star_rating,
            'filters' => $catalogueFilters,
            'hotel_name' => $hotel_name,
            'supplier' => 'hotelbeds'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string) $user_id,
            'module' => 'stays',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Hotelbeds search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE CALCULATION (API expects Y-m-d; input is usually d-m-Y from URL/form)
    // ========================================
    if ($checkin === '' && !empty($searchSessionData['hotels_checkin_date'])) {
        $checkin = (string) $searchSessionData['hotels_checkin_date'];
    }
    if ($checkout === '' && !empty($searchSessionData['hotels_checkout_date'])) {
        $checkout = (string) $searchSessionData['hotels_checkout_date'];
    }

    $stayDates = function_exists('hotelbedsParseStayDates')
        ? hotelbedsParseStayDates($checkin, $checkout)
        : null;

    if (!$stayDates) {
        error_log('Hotelbeds search invalid or missing dates: checkin=' . $checkin . ' checkout=' . $checkout);
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        header('X-Search-Error: invalid_dates');
        echo json_encode([]);
        exit;
    }

    $checkin_date = $stayDates['checkin_ymd'];
    $checkout_date = $stayDates['checkout_ymd'];
    $number_of_nights = $stayDates['nights'];
    $checkin = $stayDates['checkin_dmY'];
    $checkout = $stayDates['checkout_dmY'];

    $todayMidnight = new DateTime('today');
    $checkinMidnight = DateTime::createFromFormat('Y-m-d', $checkin_date);
    if ($checkinMidnight instanceof DateTime && $checkinMidnight < $todayMidnight) {
        error_log('Hotelbeds search rejected past check-in: ' . $checkin_date);
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        header('X-Search-Error: past_checkin');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // HOTELBEDS DATABASE CONNECTION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'hotelbeds',
        'type' => 'stays'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $dbHost = $module['host'] ?? 'localhost';
    $dbName = $module['database'] ?? '';
    $dbUser = $module['username'] ?? 'root';
    $dbPass = $module['password'] ?? '';
    $apiKey = $module['c1'] ?? '';
    $apiSecret = $module['c2'] ?? '';
    $environment = ($module['dev_mode'] ?? '1') == '1' ? 'test' : 'live';
    $hotelbedsSettings = function_exists('readHotelbedsSettings')
        ? readHotelbedsSettings()
        : ['use_mtls' => 0];
    $transport = function_exists('hotelbedsResolveBookingTransport')
        ? hotelbedsResolveBookingTransport($module, $hotelbedsSettings)
        : [
            'environment' => $environment,
            'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
            'error' => null,
        ];
    $environment = $transport['environment'];
    $useMtls = $transport['use_mtls'];

    if (!empty($transport['error'])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError($transport['error'])
                : 'One or more rates are no longer available. Please search again.',
        ]);
        exit;
    }

    if (empty($dbName)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'EUR';

    // Create Hotelbeds database connection
    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $hotelbedsPDO = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $hotelbedsDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $hotelbedsPDO]);
        hotelbedsSearchMark('connect');
    } catch (Exception $e) {
        error_log('Hotelbeds DB Error: ' . $e->getMessage());
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // DESTINATION & HOTEL LOOKUP
    // ========================================
    $destinationCodes = [];
    $cleanDestination = !empty($destination) ? trim(str_replace('-', ' ', $destination)) : '';
    // True once the destination resolves to a real Hotelbeds (Atlas) destination code.
    // Atlas grouping is what hotelbeds.com itself searches on, so when it is available
    // it is authoritative and the messy `city` column must not widen or narrow it.
    $destinationIsAtlasResolved = false;
    // Atlas "destination_code|zone_code" pairs the search resolved to, when the term
    // names a zone rather than a destination. Empty for a normal city search.
    $zoneKeys = [];
    // True when those pairs came from an exact code the guest picked, rather than from
    // matching a typed name. An exact zone needs no city safety net — and must not get
    // one, or the union below would hand back the whole destination.
    $zoneLockIsExact = false;

    // Priority 1: the exact Atlas code the guest picked from the suggestions. This is
    // the only signal that can separate a zone from the destination it is named after
    // — "Dubai" the area (484 hotels) and "Dubai" the destination (840) share a slug,
    // so by name alone the area is unreachable.
    // Codes are 2-4 chars, not always 3: Maldives is MV. Slugs like "dubai" are not
    // codes and fall through to the name lookup below.
    if (!empty($destination_code)) {
        $codeCandidate = strtoupper(trim((string) $destination_code));
        if (preg_match('/^[A-Z0-9]{2,10}$/', $codeCandidate)) {
            $destRow = $hotelbedsDb->get('hotelbeds_destinations', 'code', ['code' => $codeCandidate]);
            if (empty($destRow)) {
                $hotelMatch = $hotelbedsDb->count('hotelbeds_hotels', ['destination_code' => $codeCandidate]);
                if ($hotelMatch > 0) {
                    $destRow = $codeCandidate;
                }
            }
            if (!empty($destRow)) {
                $destinationCodes[] = $codeCandidate;
                $destinationIsAtlasResolved = true;

                // An area pick narrows that destination to one zone. Verified against
                // the zone table so a stale or hand-edited code cannot silently empty
                // the listing — an unknown zone is ignored, leaving the destination.
                if ($destination_zone !== '') {
                    $zoneExists = $hotelbedsDb->count('hotelbeds_zones', [
                        'destination_code' => $codeCandidate,
                        'code' => $destination_zone,
                    ]);
                    if ($zoneExists > 0) {
                        $zoneKeys[$codeCandidate . '|' . $destination_zone] = true;
                        $zoneLockIsExact = true;
                        // The zone is the lock now, so the destination must not be
                        // treated as authoritative — that would skip the area filter
                        // and return the whole destination.
                        $destinationIsAtlasResolved = false;
                    } else {
                        error_log('Hotelbeds search: unknown zone ' . $destination_zone
                            . ' for destination ' . $codeCandidate . ' — using whole destination');
                        $destination_zone = '';
                    }
                }
            }
        }
    }

    if (!empty($destination) && empty($destinationCodes)) {
        // Only lookup destinations if no code was passed from frontend

        // 1. Search hotelbeds_destinations - EXACT match first, then starts-with
        $destWhere = [
            'OR' => [
                'name' => [$destination, $cleanDestination],         // Exact match
                'name[~]' => $destination . '%',                         // Starts with (not contains)
                'code' => strtoupper($destination)                   // Exact code match
            ]
        ];
        // Deliberately not filtered by country in SQL — country_code can be empty for
        // imported rows, and an empty column must not wipe out a valid match. The
        // country preference is applied below, where "unknown" can still be kept.
        $destRecords = $hotelbedsDb->select('hotelbeds_destinations', ['code', 'name', 'country_code'], $destWhere);

        $destMatches = [];
        if ($destRecords && is_array($destRecords)) {
            foreach ($destRecords as $dest) {
                // Extra guard: only add if destination name starts with our search term
                if (stripos($dest['name'], $destination) === 0 || strtoupper($dest['code']) === strtoupper($destination)) {
                    $destMatches[(string) $dest['code']] = [
                        'code' => (string) $dest['code'],
                        'name' => (string) $dest['name'],
                        'country_code' => strtoupper(trim((string) ($dest['country_code'] ?? ''))),
                        'exact' => strcasecmp((string) $dest['name'], $destination) === 0
                            || strcasecmp((string) $dest['name'], $cleanDestination) === 0,
                    ];
                }
            }
        }

        // A destination name can exist in several countries. Merging them produces a
        // listing that mixes continents, so keep one country: the one the guest picked
        // when known, otherwise the country holding the best (exact) name match.
        $resolvedCountry = $destination_country;
        if ($resolvedCountry !== '') {
            // Keep the guest's country only if it is actually represented; otherwise
            // fall through to scoring so the search does not come back empty.
            $countryRepresented = false;
            foreach ($destMatches as $match) {
                if ($match['country_code'] === $resolvedCountry) {
                    $countryRepresented = true;
                    break;
                }
            }
            if (!$countryRepresented && !empty($destMatches)) {
                error_log('Hotelbeds search: no "' . $destination . '" destination in '
                    . $resolvedCountry . ' — falling back to name match');
                $resolvedCountry = '';
                // The guest's country is not in the data, so it must not be used to
                // filter hotels either — that would return an empty listing.
                $destination_country = '';
            }
        }
        if ($resolvedCountry === '' && !empty($destMatches)) {
            $countryScores = [];
            foreach ($destMatches as $match) {
                if ($match['country_code'] === '') {
                    continue;
                }
                $countryScores[$match['country_code']] = ($countryScores[$match['country_code']] ?? 0)
                    + ($match['exact'] ? 100 : 1);
            }
            if (count($countryScores) > 1) {
                // "Bali" is a town in Rajasthan as well as the island, and both match
                // exactly. Break the tie on inventory size so the destination the guest
                // almost certainly meant wins.
                $codesByCountry = [];
                foreach ($destMatches as $match) {
                    if ($match['country_code'] !== '') {
                        $codesByCountry[$match['country_code']][] = $match['code'];
                    }
                }
                foreach ($countryScores as $countryCode => $score) {
                    $codes = $codesByCountry[$countryCode] ?? [];
                    if (empty($codes)) {
                        continue;
                    }
                    $hotelCount = (int) $hotelbedsDb->count('hotelbeds_hotels', [
                        'destination_code' => array_values(array_unique($codes)),
                    ]);
                    // Inventory only ever breaks ties; it can never outrank an exact
                    // name match (worth 100) over a starts-with one (worth 1).
                    $countryScores[$countryCode] = $score + min(99, $hotelCount / 100);
                }
            }

            if (!empty($countryScores)) {
                arsort($countryScores);
                $resolvedCountry = (string) array_key_first($countryScores);
                if (count($countryScores) > 1) {
                    error_log('Hotelbeds search: "' . $destination . '" matches destinations in '
                        . implode(', ', array_keys($countryScores)) . ' — using ' . $resolvedCountry);
                }
            }
        }

        // Hotelbeds' own portal resolves a typed name at the Atlas level that carries
        // it EXACTLY and holds the most inventory. "Islamabad" is the Islamabad zone
        // (35 hotels), not the "Islamabad-Northern Region" destination that merely
        // starts with it — a region spanning Lahore, Peshawar and Murree, which is
        // what a starts-with destination match alone returned. "Lahore" is the Lahore
        // zone inside that same region (24 hotels), not the tiny "Lahore" destination
        // (2 hotels) that happens to share the name. "Dubai" stays the Dubai
        // destination (840), because the same-named zone inside it is smaller (484).
        $exactDestinationCodes = [];
        foreach ($destMatches as $match) {
            if (!$match['exact']) {
                continue;
            }
            if ($resolvedCountry !== '' && $match['country_code'] !== '' && $match['country_code'] !== $resolvedCountry) {
                continue;
            }
            $exactDestinationCodes[] = $match['code'];
        }

        if (mb_strlen($cleanDestination) >= 3) {
            try {
                $exactZoneWhere = [
                    'name' => array_values(array_unique(array_filter([$destination, $cleanDestination]))),
                    'LIMIT' => 50,
                ];
                if ($resolvedCountry !== '') {
                    $exactZoneWhere['country_code'] = $resolvedCountry;
                }
                $exactZoneRows = $hotelbedsDb->select('hotelbeds_zones', ['code', 'destination_code', 'country_code', 'name'], $exactZoneWhere) ?: [];

                $exactZoneKeys = [];
                $exactZoneDests = [];
                $exactZoneCountries = [];
                foreach ($exactZoneRows as $zoneRow) {
                    $zoneDest = strtoupper(trim((string) ($zoneRow['destination_code'] ?? '')));
                    $zoneCode = strtoupper(trim((string) ($zoneRow['code'] ?? '')));
                    if ($zoneDest === '' || $zoneCode === '') {
                        continue;
                    }
                    $exactZoneKeys[$zoneDest . '|' . $zoneCode] = true;
                    $exactZoneDests[$zoneDest] = true;
                    $zoneCountry = strtoupper(trim((string) ($zoneRow['country_code'] ?? '')));
                    if ($zoneCountry !== '') {
                        $exactZoneCountries[$zoneCountry] = true;
                    }
                }

                // Same generic-name guard as the zone fallback below: "Centre" or
                // "Beach" exists in dozens of destinations and is not a place.
                $exactZoneSpreadTooWide = count($exactZoneDests) > 5
                    || ($resolvedCountry === '' && count($exactZoneCountries) > 1);

                if (!empty($exactZoneKeys) && !$exactZoneSpreadTooWide) {
                    $zoneInventory = 0;
                    $zoneCountRows = $hotelbedsDb->select('hotelbeds_hotels', [
                        'destination_code',
                        'zone_code',
                        'total' => Medoo\Medoo::raw('COUNT(*)'),
                    ], [
                        'destination_code' => array_keys($exactZoneDests),
                        'GROUP' => ['destination_code', 'zone_code'],
                    ]) ?: [];
                    foreach ($zoneCountRows as $countRow) {
                        $countKey = strtoupper(trim((string) ($countRow['destination_code'] ?? '')))
                            . '|' . strtoupper(trim((string) ($countRow['zone_code'] ?? '')));
                        if (isset($exactZoneKeys[$countKey])) {
                            $zoneInventory += (int) ($countRow['total'] ?? 0);
                        }
                    }

                    $exactDestinationInventory = 0;
                    if (!empty($exactDestinationCodes)) {
                        $exactDestinationInventory = (int) $hotelbedsDb->count('hotelbeds_hotels', [
                            'destination_code' => array_values(array_unique($exactDestinationCodes)),
                        ]);
                    }

                    if ($zoneInventory > 0 && $zoneInventory > $exactDestinationInventory) {
                        $zoneKeys = $exactZoneKeys;
                        $destinationCodes = array_keys($exactZoneDests);
                        // The zone is the lock now; the destinations that merely start
                        // with (or tie by name with) the term must not widen it back out.
                        $destMatches = [];
                        error_log('Hotelbeds search: "' . $destination . '" resolved to exact Atlas zone(s) '
                            . implode(', ', array_keys($exactZoneKeys)) . ' (' . $zoneInventory
                            . ' hotels) over destination match (' . $exactDestinationInventory . ' hotels)');
                    }
                }
            } catch (Throwable $e) {
                error_log('Hotelbeds search exact zone lookup failed: ' . $e->getMessage());
            }
        }

        foreach ($destMatches as $match) {
            if ($resolvedCountry !== '' && $match['country_code'] !== '' && $match['country_code'] !== $resolvedCountry) {
                continue;
            }
            if (!in_array($match['code'], $destinationCodes, true)) {
                $destinationCodes[] = $match['code'];
                $destinationIsAtlasResolved = true;
            }
        }

        // The suggestion list is built from the app's own `locations` table, which does
        // not share a vocabulary with Hotelbeds Atlas or with the hotels' city column —
        // a guest picks "Genting" (Malaysia) while the content holds "GENTING
        // HIGHLANDS". Matching only on equality therefore returns an empty listing for
        // a destination we do hold inventory for, so widen in ranked fallback tiers
        // instead of giving up. Each tier runs only when every better one found
        // nothing.

        // 2. Fallback A: an Atlas ZONE. Hotelbeds' own portal searches at zone level —
        //    its result tab reads "Genting Highlands, Kuantan and Pahang", a zone inside
        //    the "Kuantan and Pahang" destination. A resort area that is not a
        //    destination in its own right exists ONLY as a zone, so a city-column guess
        //    finds just the subset of its hotels whose free-text city happens to spell
        //    the area out. Match the zone and the whole area comes back.
        if (empty($destinationCodes) && mb_strlen($cleanDestination) >= 3) {
            try {
                $zoneTerms = array_values(array_unique(array_filter([$destination, $cleanDestination])));
                $zoneWhere = [
                    'OR' => [
                        'name' => $zoneTerms,
                        'name[~]' => array_map(static function ($term) {
                            return $term . '%';
                        }, $zoneTerms),
                    ],
                    'LIMIT' => 500,
                ];
                if ($resolvedCountry !== '') {
                    $zoneWhere['country_code'] = $resolvedCountry;
                }
                $zoneRows = $hotelbedsDb->select('hotelbeds_zones', ['code', 'destination_code', 'country_code', 'name'], $zoneWhere) ?: [];

                $zoneDestCodes = [];
                $zoneCountries = [];
                foreach ($zoneRows as $zoneRow) {
                    $zoneDest = strtoupper(trim((string) ($zoneRow['destination_code'] ?? '')));
                    $zoneCode = strtoupper(trim((string) ($zoneRow['code'] ?? '')));
                    if ($zoneDest === '' || $zoneCode === '') {
                        continue;
                    }
                    $zoneKeys[$zoneDest . '|' . $zoneCode] = true;
                    $zoneDestCodes[$zoneDest] = true;
                    $zoneCountry = strtoupper(trim((string) ($zoneRow['country_code'] ?? '')));
                    if ($zoneCountry !== '') {
                        $zoneCountries[$zoneCountry] = true;
                    }
                }

                // Zone names repeat worldwide ("Centre", "Beach", "Airport"). A term that
                // lands in a handful of destinations is a real place; one spread across
                // many, or across countries when the guest gave us none to anchor on, is
                // a generic word — locking to it would return a scatter of unrelated
                // places, so drop it and let the city tier try instead.
                $zoneSpreadTooWide = count($zoneDestCodes) > 5
                    || ($resolvedCountry === '' && count($zoneCountries) > 1);
                if ($zoneSpreadTooWide) {
                    error_log('Hotelbeds search: "' . $destination . '" matches zones in '
                        . count($zoneDestCodes) . ' destinations / ' . count($zoneCountries)
                        . ' countries — too generic, ignoring zones');
                    $zoneKeys = [];
                } elseif (!empty($zoneDestCodes)) {
                    $destinationCodes = array_keys($zoneDestCodes);
                    error_log('Hotelbeds search: "' . $destination . '" resolved to Atlas zone(s) in '
                        . implode(', ', $destinationCodes));
                }
            } catch (Throwable $e) {
                error_log('Hotelbeds search zone lookup failed: ' . $e->getMessage());
                $zoneKeys = [];
            }
        }

        // 3. Fallback B: the term is longer than the Atlas name — "Dubai Marina" typed,
        //    Atlas holds "Dubai". Take the longest destination name that opens the term
        //    on a word boundary. Still a real Atlas destination, so still authoritative.
        if (empty($destinationCodes)) {
            try {
                // Atlas names carry hyphens the URL slug turned into spaces
                // ("Islamabad-Northern Region" arrives as "islamabad northern region"),
                // so compare with hyphens folded on both sides.
                $reverseSql = 'SELECT code, name FROM hotelbeds_destinations'
                    . ' WHERE (LOWER(name) = LOWER(:term)'
                    . ' OR LOWER(REPLACE(name, \'-\', \' \')) = LOWER(REPLACE(:term3, \'-\', \' \'))'
                    . ' OR LOWER(:term2) LIKE CONCAT(LOWER(name), \' %\'))'
                    . ' AND name <> \'\'';
                $reverseArgs = [':term' => $cleanDestination, ':term2' => $cleanDestination, ':term3' => $cleanDestination];
                if ($resolvedCountry !== '') {
                    $reverseSql .= ' AND country_code = :country';
                    $reverseArgs[':country'] = $resolvedCountry;
                }
                $reverseSql .= ' ORDER BY CHAR_LENGTH(name) DESC LIMIT 1';

                $reverseStmt = $hotelbedsPDO->prepare($reverseSql);
                $reverseStmt->execute($reverseArgs);
                $reverseMatch = $reverseStmt->fetch();
                if (!empty($reverseMatch['code'])) {
                    $destinationCodes[] = (string) $reverseMatch['code'];
                    $destinationIsAtlasResolved = true;
                    error_log('Hotelbeds search: "' . $destination . '" resolved to Atlas destination "'
                        . $reverseMatch['name'] . '" (' . $reverseMatch['code'] . ') by prefix');
                }
            } catch (Throwable $e) {
                error_log('Hotelbeds search reverse destination lookup failed: ' . $e->getMessage());
            }
        }

        // 4. Fallback C: no Atlas destination carries this name at all, so infer the
        //    codes from the hotels' own city column — matched as a prefix, because the
        //    content spells cities out more fully than the picker does ("Genting" must
        //    reach "GENTING HIGHLANDS"). Codes found this way are NOT authoritative:
        //    the city column is free text, so the city lock below still applies and
        //    trims each destination back to the searched city.
        //
        //    This tier must never run once Atlas resolved the destination. Hotels
        //    sitting in destination SHJ (Sharjah) or AE1 (Ajman) carry city "DUBAI", so
        //    widening a resolved DXB search with them pulls whole neighbouring
        //    destinations into the candidate set and inflates the headline count.
        if (empty($destinationCodes)) {
            $cityTerms = array_values(array_unique(array_filter([$destination, $cleanDestination])));
            $cityPrefixes = array_map(static function ($term) {
                return $term . '%';
            }, $cityTerms);

            $cityWhere = [
                'OR' => [
                    'city' => $cityTerms,                          // Exact city match
                    'city[~]' => $cityPrefixes,                    // "Genting" → "GENTING HIGHLANDS"
                    'destination_code' => strtoupper($destination) // Exact code match
                ],
                'GROUP' => 'destination_code'
            ];
            if ($resolvedCountry !== '') {
                $cityWhere['country_code'] = $resolvedCountry;
            }
            $cityDestCodes = $hotelbedsDb->select('hotelbeds_hotels', 'destination_code', $cityWhere);

            if ($cityDestCodes && is_array($cityDestCodes)) {
                foreach ($cityDestCodes as $code) {
                    if (!empty($code) && !in_array($code, $destinationCodes)) {
                        $destinationCodes[] = $code;
                    }
                }
            }
        }

        // Only lock hotels to a country the guest actually chose. A country merely
        // inferred from name scoring already limited the destination codes above;
        // applying it to hotels as well would drop rows whose country_code is empty.
        if ($destination_country !== '' && $resolvedCountry !== $destination_country) {
            $destination_country = '';
        }
    }

    // ========================================
    // STEP 1: FETCH HOTELS FROM LOCAL DATABASE
    // ========================================
    // Candidates come from the resolved Hotelbeds destination code(s) — the same
    // grouping hotelbeds.com searches on, so DXB yields Dubai and SHJ/AE1 stay out.
    // The city-name lock below is only a fallback for unresolved destinations.
    $whereConditions = [];

    if (!empty($destinationCodes)) {
        $whereConditions['destination_code'] = array_values(array_unique($destinationCodes));
    } elseif (!empty($destination)) {
        // Fallback: nothing resolved at all — match the city name as a prefix so a
        // short picker label still reaches the fuller spelling in the content
        // ("Genting" → "GENTING HIGHLANDS"). The city lock below trims the rest.
        $whereConditions['city[~]'] = ($cleanDestination !== '' ? $cleanDestination : $destination) . '%';
    }

    // Second lock. A hotel-name search skips the city lock below, so without this a
    // search for "marriott" in Bali could return a Marriott from another country.
    if ($destination_country !== '') {
        $whereConditions['country_code'] = $destination_country;
    }

    // Star / type / amenity filters are applied to the candidate rows below
    // (hotelbedsSearchApplyCatalogueFilters) — a category_code LIKE '5EST' clause
    // would miss 5LUX, APTH5, H5_5 and every other 5-star code Hotelbeds uses.

    // Hotel-name search: every word typed must appear somewhere in the name, in any
    // order — "Nassima Apartments" and "Nassima Hotel Apartments" both reach
    // "Nassima Tower Hotel Apartments". A whole-phrase LIKE would need the exact
    // word sequence.
    if (!empty($hotel_name)) {
        $nameTerms = hotelbedsSearchNameTerms($hotel_name);
        $nameMatch = [
            'name[~]' => !empty($nameTerms)
                ? ['AND' => array_map(static fn($term) => '%' . $term . '%', $nameTerms)]
                : '%' . $hotel_name . '%',
        ];
        if (ctype_digit($hotel_name)) {
            $nameMatch['hotel_code'] = (int) $hotel_name;
        }
        $whereConditions['OR'] = $nameMatch;
    }

    $offset = ($page - 1) * $per_page;
    // City lock is the fallback guard for when the destination could NOT be resolved to
    // an Atlas code and the candidate set therefore came from the free-text city column.
    // When Atlas did resolve it, its grouping is the same one hotelbeds.com searches on
    // and locking on top of it only drops valid hotels ("Al Jafiliya - Dubai",
    // "Deira, Dubai"), so it is left off. It also applies to hotel-name searches — a
    // name typed inside a Dubai listing must stay inside Dubai.
    $cityLock = '';
    if (!$destinationIsAtlasResolved && !$zoneLockIsExact && $cleanDestination !== '') {
        // Skip city lock when the "destination" is only a 3-letter Atlas code with no city label
        $isCodeOnly = (bool) preg_match('/^[A-Za-z]{3}$/', $cleanDestination);
        if (!$isCodeOnly) {
            $cityLock = strtoupper($cleanDestination);
        }
    }
    $availabilityCacheKey = hash('sha256', json_encode([
        'destination' => strtoupper($destination),
        'destination_codes' => array_values(array_unique($destinationCodes)),
        'city_lock' => $cityLock,
        'zone_keys' => array_keys($zoneKeys),
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nationality' => strtoupper($nationality),
        'source_market' => function_exists('hotelbedsConfiguredSourceMarket') ? hotelbedsConfiguredSourceMarket() : '',
        'packaging' => function_exists('hotelbedsPackagingRatesAllowed') ? (int) hotelbedsPackagingRatesAllowed() : 1,
        'rooms_data' => $rooms_data,
        'filters' => $catalogueFilters,
        'destination_country' => $destination_country,
        'hotel_name' => strtolower($hotel_name)
    ]));
    $candidateHotels = $hotelbedsDb->select('hotelbeds_hotels', '*', $whereConditions) ?: [];

    // Area lock: trim the destination back to the area actually searched. A hotel
    // qualifies on EITHER signal, because Hotelbeds populates neither consistently —
    // in Genting Highlands, Scapes Hotel carries city "GENTING HIGHLANDS" but zone
    // "Kuantan", while Grand Ion Delemen carries the Genting Highlands zone and a
    // different city. Requiring both would drop half the area; the union is what the
    // supplier's own zone search returns.
    if ($cityLock !== '' || !empty($zoneKeys)) {
        $candidateHotels = array_values(array_filter($candidateHotels, static function ($hotel) use ($cityLock, $zoneKeys) {
            if (!empty($zoneKeys)) {
                $zoneKey = strtoupper(trim((string) ($hotel['destination_code'] ?? '')))
                    . '|' . strtoupper(trim((string) ($hotel['zone_code'] ?? '')));
                if (isset($zoneKeys[$zoneKey])) {
                    return true;
                }
            }

            if ($cityLock === '') {
                return false;
            }

            $city = strtoupper(trim((string) ($hotel['city'] ?? '')));
            if ($city === '') {
                return false;
            }
            if ($city === $cityLock) {
                return true;
            }
            // "DUBAI MARINA", "DUBAI-…" — still Dubai; not "SHARJAH"
            if (str_starts_with($city, $cityLock)) {
                $next = $city[strlen($cityLock)] ?? '';
                return $next === '' || $next === ' ' || $next === '-' || $next === ',';
            }
            return false;
        }));
    }

    // Catalogue filters: keep only candidates that match the sidebar selection so
    // the availability scan (and every page it yields) is already the filtered set.
    hotelbedsSearchMark('candidates');
    $candidateHotels = hotelbedsSearchApplyCatalogueFilters($hotelbedsDb, $candidateHotels, $catalogueFilters);
    hotelbedsSearchMark('filters');
    $filtersAppliedHeader = 'X-Filters-Applied: ' . hotelbedsSearchFiltersAppliedValue($catalogueFilters);

    $candidateHotelsByCode = [];
    foreach ($candidateHotels as $candidateHotel) {
        $candidateHotelsByCode[(string) $candidateHotel['hotel_code']] = $candidateHotel;
    }

    if (empty($candidateHotelsByCode)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        header('X-Search-Exhausted: true');
        header($filtersAppliedHeader);
        echo json_encode([]);
        exit;
    }

    $cachedAvailability = $searchSessionData['hotelbeds_availability_cache'][$availabilityCacheKey] ?? null;
    $cacheIsValid = is_array($cachedAvailability)
        && (int) ($cachedAvailability['version'] ?? 0) === 3
        && isset($cachedAvailability['created_at'], $cachedAvailability['available'], $cachedAvailability['candidate_codes'])
        && is_array($cachedAvailability['available'])
        && is_array($cachedAvailability['candidate_codes'])
        && (time() - (int) $cachedAvailability['created_at']) < HOTELBEDS_SEARCH_CACHE_TTL;

    if (!$cacheIsValid) {
        $cachedAvailability = [
            'version' => 3,
            'created_at' => time(),
            'candidate_codes' => hotelbedsSearchOrderCandidateCodes($candidateHotelsByCode),
            'scanned_through' => 0,
            'available' => [],
            'api_hotels' => [],
            'exhausted' => false,
        ];
    }

    $exactAvailableTotal = count($cachedAvailability['available']);
    $candidatesExhausted = !empty($cachedAvailability['exhausted']);
    $hotelCodes = [];
    $hotels = [];

    // Initialize available hotels array
    $availableHotelsFromApi = [];
    $pageCatalogue = hotelbedsSearchLoadPageCatalogue($hotelbedsDb, []);

    // ========================================
    // STEP 3: PROGRESSIVE AVAILABILITY + PAGE REPRICE
    // ========================================
    if (!empty($apiKey) && !empty($apiSecret)) {
        $isProduction = ($environment === 'live');
        $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
            : ($useMtls
                ? ($isProduction ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
                : ($isProduction ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

        // Build API payload
        $apiPayload = [
            "stay" => [
                "checkIn" => $checkin_date,
                "checkOut" => $checkout_date
            ],
            "occupancies" => []
        ];
        $occupancyValidationError = null;

        // Build occupancies from rooms_data
        if (!empty($rooms_data)) {
            foreach ($rooms_data as $room) {
                $roomAdults = max(1, (int) ($room['adults'] ?? 2));
                $roomChildren = max(0, (int) ($room['children'] ?? 0));
                $occupancy = [
                    "rooms" => 1,
                    "adults" => $roomAdults,
                    "children" => $roomChildren
                ];

                // Hotelbeds requires exactly one CH pax (including age) for
                // every requested child. Use only the ages supplied by the
                // search form; never invent or replace a child's age.
                if ($roomChildren > 0) {
                    $childAges = isset($room['childAges']) && is_array($room['childAges'])
                        ? array_values($room['childAges'])
                        : [];

                    if (count($childAges) !== $roomChildren) {
                        $occupancyValidationError = 'A valid age is required for every child in each room.';
                        break;
                    }

                    $occupancy['paxes'] = [];
                    foreach ($childAges as $age) {
                        $occupancy['paxes'][] = [
                            "type" => "CH",
                            "age" => max(0, (int) $age)
                        ];
                    }
                }

                $apiPayload['occupancies'][] = $occupancy;
            }
        } else {
            // Legacy fallback when per-room data is unavailable. Distribute
            // totals across rooms so Hotelbeds receives per-room occupancy.
            $rooms = max(1, $rooms);
            for ($roomIndex = 0; $roomIndex < $rooms; $roomIndex++) {
                $roomAdults = intdiv($adults, $rooms) + ($roomIndex < ($adults % $rooms) ? 1 : 0);
                $roomChildren = intdiv($children, $rooms) + ($roomIndex < ($children % $rooms) ? 1 : 0);
                $occupancy = [
                    "rooms" => 1,
                    "adults" => max(1, $roomAdults),
                    "children" => max(0, $roomChildren)
                ];

                if ($roomChildren > 0) {
                    $occupancyValidationError = 'Per-room child ages are required for Hotelbeds availability.';
                    break;
                }

                $apiPayload['occupancies'][] = $occupancy;
            }
        }

        if ($occupancyValidationError !== null) {
            error_log('Hotelbeds search occupancy validation: ' . $occupancyValidationError);
            ob_end_clean();
            header('Content-Type: application/json');
            header('X-Total-Results: 0');
            header('X-Destination-Total: 0');
            header('X-Total-Pages: 0');
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: false');
            echo json_encode([]);
            return;
        }

        // Base Availability payload (hotel codes added per batch / page)
        $baseApiPayload = $apiPayload;
        // Packaging (flight+hotel) rates are only priced correctly when sold as
        // a package, so ask Hotelbeds for them only when the admin allows them;
        // the per-rate skip below is the safety net if the API still returns
        // some. When they are hidden, don't cap rates per room — the cheapest
        // rates could all be packaging ones and the cap would leave the room
        // with nothing (or a wrong "from" price).
        $packagingAllowed = function_exists('hotelbedsPackagingRatesAllowed')
            ? hotelbedsPackagingRatesAllowed()
            : true;
        $baseApiPayload['filter'] = array_merge($baseApiPayload['filter'] ?? [], [
            'packaging' => $packagingAllowed,
        ]);
        if (HOTELBEDS_SEARCH_MAX_RATES_PER_ROOM > 0 && $packagingAllowed) {
            $baseApiPayload['filter']['maxRatesPerRoom'] = (int) HOTELBEDS_SEARCH_MAX_RATES_PER_ROOM;
        }

        if (function_exists('hotelbedsApplyAvailabilityMarketFields')) {
            $baseApiPayload = hotelbedsApplyAvailabilityMarketFields($baseApiPayload, $db, $nationality);
        } elseif (!empty($nationality)) {
            $baseApiPayload['nationality'] = strtoupper(trim((string) $nationality));
        }

        // --- price trace: the exact Availability request every price below is
        // derived from, plus the settings that decide which rates Hotelbeds may
        // return. Body only — Api-key / X-Signature are cURL headers and are
        // never passed in here.
        if (function_exists('hotelbedsPriceTraceEnabled') && hotelbedsPriceTraceEnabled($db)) {
            $traceSettings = function_exists('readHotelbedsSettings') ? readHotelbedsSettings() : [];
            hotelbedsPriceTraceLog('availability_request', [
                'environment' => $environment,
                'base_url' => $baseUrl,
                'destination' => $destination,
                'destination_code' => $destination_code,
                'page' => $page,
                'per_page' => $per_page,
                'check_in' => $checkin_date,
                'check_out' => $checkout_date,
                'nights' => (int) $number_of_nights,
                'occupancies' => $baseApiPayload['occupancies'] ?? [],
                'nationality' => $baseApiPayload['nationality'] ?? null,
                'source_market_sent' => $baseApiPayload['sourceMarket'] ?? null,
                'source_market_configured' => function_exists('hotelbedsConfiguredSourceMarket')
                    ? hotelbedsConfiguredSourceMarket()
                    : null,
                'filter' => $baseApiPayload['filter'] ?? [],
                'allow_packaging_rates' => !empty($traceSettings['allow_packaging_rates']),
                'use_mtls' => $useMtls,
                'module_currency' => $moduleCurrency,
                'session_currency' => $sessionCurrency,
                'markup_b2c' => $module['markup_b2c'] ?? null,
                'markup_b2b' => $module['markup_b2b'] ?? null,
                'markup_type_b2c' => $module['markup_type_b2c'] ?? null,
                'markup_type_b2b' => $module['markup_type_b2b'] ?? null,
                'user_role' => $searchSessionData['user_role'] ?? null,
                'request_body' => $baseApiPayload,
            ]);
        }

        $neededForPage = $offset + $per_page;
        $batchCallsMade = 0;
        hotelbedsSearchMark('prepare');
        hotelbedsSearchProgressiveFill(
            $cachedAvailability,
            $baseApiPayload,
            $baseUrl,
            $apiKey,
            $apiSecret,
            $useMtls,
            $connectTimeout,
            $requestTimeout,
            $neededForPage,
            $batchCallsMade,
            $db,
            $searchDeadline
        );
        hotelbedsSearchMark('availability_x' . $batchCallsMade);
        hotelbedsSearchSaveProgressiveCache($availabilityCacheKey, $cachedAvailability);
        hotelbedsSearchMark('session_save');

        $exactAvailableTotal = count($cachedAvailability['available']);
        $candidatesExhausted = !empty($cachedAvailability['exhausted']);
        $pageSlice = array_slice($cachedAvailability['available'], $offset, $per_page);
        $hotelCodes = array_values(array_filter(array_map(static function ($row) {
            return (string) ($row['code'] ?? '');
        }, $pageSlice)));

        $hotels = [];
        foreach ($hotelCodes as $hotelCode) {
            if (isset($candidateHotelsByCode[$hotelCode])) {
                $hotels[] = $candidateHotelsByCode[$hotelCode];
            }
        }

        if (empty($hotelCodes)) {
            ob_end_clean();
            header('Content-Type: application/json');
            header('X-Total-Results: 0');
            header('X-Destination-Total: ' . count($candidateHotelsByCode));
            header('X-Grand-Total: ' . $exactAvailableTotal);
            header('X-Total-Pages: ' . max(1, (int) ceil($exactAvailableTotal / max(1, $per_page))));
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: ' . ((!$candidatesExhausted) ? 'true' : 'false'));
            header('X-Search-Exhausted: ' . ($candidatesExhausted ? 'true' : 'false'));
            header($filtersAppliedHeader);
            header(hotelbedsSearchTimingHeader());
            echo json_encode([]);
            exit;
        }

        // Reprice page slice when needed; reuse batch cache when reprice fails (DNS timeouts).
        $cachedApiHotels = hotelbedsSearchCachedApiHotelsForCodes($cachedAvailability, $hotelCodes);
        $needsReprice = count($cachedApiHotels) < count($hotelCodes);
        $apiHotelsToProcess = [];
        $httpCode = 0;
        $curlError = '';

        if ($needsReprice) {
            $repricePayload = $baseApiPayload;
            $repricePayload['hotels'] = ['hotel' => array_map('intval', $hotelCodes)];

            $repriceResult = hotelbedsSearchPostAvailability(
                $baseUrl,
                $apiKey,
                $apiSecret,
                $repricePayload,
                $useMtls,
                $connectTimeout,
                $requestTimeout
            );

            $apiResponse = $repriceResult['body'];
            $httpCode = $repriceResult['http_code'];
            $curlError = $repriceResult['curl_error'];

            $log_setting = log_setting($db, 'hotelbeds');

            if ($log_setting == '1') {
                $apiResponseDecoded = !empty($apiResponse) ? json_decode($apiResponse, true) : ['error' => 'Empty response'];
                $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs');
                $type = 'Hotelbeds_Search';
                logApiCall('hotelbeds_search', $repriceResult['payload'], $apiResponseDecoded, $httpCode, $path, $type);
            }

            if ($httpCode === 200 && !empty($apiResponse)) {
                $apiData = json_decode($apiResponse, true);
                if (isset($apiData['hotels']['hotels']) && is_array($apiData['hotels']['hotels'])) {
                    $apiHotelsToProcess = $apiData['hotels']['hotels'];
                    hotelbedsSearchMergeApiHotelsCache($cachedAvailability, $apiData);
                    hotelbedsSearchSaveProgressiveCache($availabilityCacheKey, $cachedAvailability);
                }
            }
        }

        if (empty($apiHotelsToProcess) && !empty($cachedApiHotels)) {
            if ($httpCode !== 200) {
                error_log('Hotelbeds search using cached batch availability after reprice failure HTTP ' . $httpCode . ': ' . $curlError);
            }
            $apiHotelsToProcess = $cachedApiHotels;
        }

        hotelbedsSearchMark($needsReprice ? 'reprice_api' : 'reprice_skipped');

        // Catalogue rows for this page, fetched once. The loops below used to run
        // a query per room (images), per room facility (name) and per rate (a
        // fresh DB connection for rate comments the listing never shows) — a
        // 5-star page with ~400 rates cost thousands of round trips.
        $pageCatalogue = hotelbedsSearchLoadPageCatalogue($hotelbedsDb, array_map(static function ($apiHotel) {
            return (string) ($apiHotel['code'] ?? '');
        }, $apiHotelsToProcess));
        hotelbedsSearchMark('catalogue_preload');

        // ========================================
        // STEP 4: PARSE API RESPONSE WITH DETAILED ROOM OPTIONS
        // ========================================
        if (!empty($apiHotelsToProcess)) {
                $apiHotels = $apiHotelsToProcess;

                foreach ($apiHotels as $apiHotel) {
                    $hotelCode = (string) $apiHotel['code'];
                    $apiCurrency = $apiHotel['currency'] ?? $moduleCurrency;

                    $minRate = floatval($apiHotel['minRate'] ?? 0);

                    if ($minRate > 0 && isset($apiHotel['rooms']) && is_array($apiHotel['rooms']) && count($apiHotel['rooms']) > 0) {
                        // ========================================
                        // PROCESS ROOMS - Build structure matching manual hotels
                        // ========================================
                        $groupedRoomOptions = [];
                        $min_price_per_night_markup = null;
                        $min_total_price_markup = null;
                        // --- price trace: one buffer per hotel, filled below and
                        // emitted once after this hotel's rooms are processed.
                        $traceOn = function_exists('hotelbedsPriceTraceEnabled') && hotelbedsPriceTraceEnabled($db);
                        $traceRates = [];
                        $traceChosen = null;

                        foreach ($apiHotel['rooms'] as $apiRoom) {
                            $roomCode = $apiRoom['code'] ?? '';
                            $roomName = $apiRoom['name'] ?? 'Standard Room';

                            // Get room rates
                            if (isset($apiRoom['rates']) && is_array($apiRoom['rates'])) {
                                foreach ($apiRoom['rates'] as $rateIndex => $rate) {
                                    $traceSkipped = function_exists('hotelbedsShouldSkipPackagingRate')
                                        && hotelbedsShouldSkipPackagingRate($rate);

                                    // --- price trace: log the rate whether or not it survives the
                                    // packaging skip; the dropped ones are what a portal-vs-site
                                    // price gap is usually hiding behind.
                                    $traceRateIdx = null;
                                    if ($traceOn) {
                                        $traceRates[] = hotelbedsPriceTraceRateFacts($rate, (string) $roomCode, (string) $roomName)
                                            + ['excluded_by' => $traceSkipped ? 'packaging_skip' : null];
                                        $traceRateIdx = array_key_last($traceRates);
                                    }

                                    if ($traceSkipped) {
                                        continue;
                                    }

                                    $netPrice = floatval($rate['net'] ?? 0);

                                    if ($netPrice > 0) {
                                        // Hotelbeds `net` is total stay price for this rate (all nights), not per night.
                                        // Occupancy room count is encoded in rateKey (e.g. 1~2~0); do not × POST rooms.
                                        $stayNet = $netPrice;

                                        // --- price trace: the FX rows and markup config every price
                                        // below is computed from. Once per request, not per rate.
                                        if ($traceOn && empty($tracePricingContextDone)) {
                                            $tracePricingContextDone = true;
                                            hotelbedsPriceTraceLog('pricing_context', [
                                                'api_currency' => $apiCurrency,
                                                'display_currency' => $sessionCurrency,
                                                'module_currency' => $moduleCurrency,
                                                'conversion_needed' => ($apiCurrency !== $sessionCurrency),
                                                'currencies' => hotelbedsPriceTraceCurrencyContext($db, [$apiCurrency, $sessionCurrency, $moduleCurrency]),
                                                'module_markup' => [
                                                    'markup_b2c' => $module['markup_b2c'] ?? null,
                                                    'markup_b2b' => $module['markup_b2b'] ?? null,
                                                    'markup_type_b2c' => $module['markup_type_b2c'] ?? null,
                                                    'markup_type_b2b' => $module['markup_type_b2b'] ?? null,
                                                ],
                                                'user_role' => $searchSessionData['user_role'] ?? null,
                                                'user_id' => isset($searchSessionData['user_id']) ? (string) $searchSessionData['user_id'] : null,
                                            ]);
                                        }

                                        $total_markup = MARKUP($stayNet, $module, $db, $apiCurrency, $sessionCurrency);
                                        $stayNights = max(1, (int) $number_of_nights);

                                        $total_price_markup = [
                                            'price' => round($total_markup['price'], 2),
                                            'markup' => round($total_markup['markup'], 2),
                                            'markup_percentage' => $total_markup['markup_percentage'],
                                            'markup_type' => $total_markup['markup_type'],
                                            'markup_value' => $total_markup['markup_value'],
                                            'base_price' => round($stayNet, 2),
                                            'converted_base_price' => round($total_markup['converted_base_price'], 2),
                                        ];

                                        $price_per_night_markup = $total_markup;
                                        $price_per_night_markup['price'] = round($total_markup['price'] / $stayNights, 2);
                                        $price_per_night_markup['markup'] = round($total_markup['markup'] / $stayNights, 2);
                                        $price_per_night_markup['base_price'] = round($stayNet / $stayNights, 2);
                                        $price_per_night_markup['converted_base_price'] = round(
                                            $total_markup['converted_base_price'] / $stayNights,
                                            2
                                        );

                                        // --- price trace: this rate's own before/after across the only
                                        // two transformations the listing applies — MARKUP() (markup +
                                        // FX) and the per-night division.
                                        if ($traceOn && $traceRateIdx !== null) {
                                            $traceRates[$traceRateIdx]['pricing'] = [
                                                'before_markup' => [
                                                    'net_stay_total' => round($stayNet, 2),
                                                    'currency' => $apiCurrency,
                                                    'nights' => $stayNights,
                                                ],
                                                'after_markup' => [
                                                    'markup_amount' => $total_price_markup['markup'],
                                                    'markup_type' => $total_price_markup['markup_type'],
                                                    'markup_value' => $total_price_markup['markup_value'],
                                                    'markup_percentage' => $total_price_markup['markup_percentage'],
                                                    'converted_base_price' => $total_price_markup['converted_base_price'],
                                                    'currency_converted' => $total_markup['currency_converted'] ?? null,
                                                    'stay_total' => $total_price_markup['price'],
                                                    'currency' => $sessionCurrency,
                                                ],
                                                'after_per_night_division' => [
                                                    'price_per_night' => $price_per_night_markup['price'],
                                                    'currency' => $sessionCurrency,
                                                ],
                                                'calculation' => hotelbedsPriceTraceMarkupSteps(
                                                    $stayNet,
                                                    $total_markup,
                                                    (string) $apiCurrency,
                                                    (string) $sessionCurrency,
                                                    $stayNights
                                                ),
                                            ];
                                        }

                                        // Track minimum prices
                                        if (!$min_price_per_night_markup || $price_per_night_markup['price'] < $min_price_per_night_markup['price']) {
                                            $min_price_per_night_markup = $price_per_night_markup;
                                            $min_total_price_markup = $total_price_markup;

                                            // --- price trace: the rate the card's "from" price comes from,
                                            // with the arithmetic MARKUP() already performed on it.
                                            if ($traceOn) {
                                                $traceChosen = [
                                                    'rate' => hotelbedsPriceTraceRateFacts($rate, (string) $roomCode, (string) $roomName),
                                                    'calculation' => hotelbedsPriceTraceMarkupSteps(
                                                        $stayNet,
                                                        $total_markup,
                                                        (string) $apiCurrency,
                                                        (string) $sessionCurrency,
                                                        $stayNights
                                                    ),
                                                    'total_price' => $total_price_markup['price'],
                                                    'price_per_night' => $price_per_night_markup['price'],
                                                ];
                                            }
                                        }

                                        // Group rooms by room code (similar to room_type_id in manual hotels)
                                        if (!isset($groupedRoomOptions[$roomCode])) {
                                            // Get room images from hotelbeds_room_images table
                                            $roomImages = [];

                                            $roomImageRecords = array_slice(
                                                $pageCatalogue['room_images'][$hotelCode][$roomCode] ?? [],
                                                0,
                                                5
                                            );

                                            foreach ($roomImageRecords as $img) {
                                                $roomImages[] = [
                                                    'url' => 'https://photos.hotelbeds.com/giata/bigger/' . $img['image_url'],
                                                    'default' => empty($roomImages) // First image is default
                                                ];
                                            }

                                            // Get room facilities/amenities
                                            $roomAmenities = [];
                                            if (isset($apiRoom['facilities']) && is_array($apiRoom['facilities'])) {
                                                foreach ($apiRoom['facilities'] as $facilityCode) {
                                                    $facilityName = $pageCatalogue['facility_names'][(string) $facilityCode] ?? null;

                                                    if ($facilityName) {
                                                        $roomAmenities[] = [
                                                            'id' => $facilityCode,
                                                            'name' => $facilityName
                                                        ];
                                                    }
                                                }
                                            }

                                            $groupedRoomOptions[$roomCode] = [
                                                'room_name' => $roomName,
                                                'room_type_id' => $roomCode,
                                                'room_id' => $roomCode,
                                                'amenities' => $roomAmenities,
                                                'room_images' => $roomImages,
                                                'room_main_image' => !empty($roomImages) ? $roomImages[0]['url'] : '',
                                                'options' => [],
                                                'min_price_per_night' => $price_per_night_markup['price'],
                                                'max_adults' => 2, // Default, will be updated
                                                'max_children' => 0 // Default, will be updated
                                            ];
                                        }

                                        // Extract board type (meal plan)
                                        $boardName = $rate['boardName'] ?? 'Room Only';
                                        $boardCode = $rate['boardCode'] ?? 'RO';

                                        // Rate comments: pass through what Availability returned. The listing
                                        // card never shows them; the detail page (rooms.php) decodes
                                        // rateCommentsId when a room is opened. Doing it here meant a new
                                        // DB connection, a lookup and possibly a Content API call per rate.
                                        $rateCommentsText = trim((string) ($rate['rateComments'] ?? ''));
                                        $rateCommentsId = trim((string) ($rate['rateCommentsId'] ?? ''));

                                        // Extract adults and children from rate
                                        $maxAdults = (int) ($rate['adults'] ?? 2);
                                        $maxChildren = (int) ($rate['children'] ?? 0);

                                        // Refundability from cancellation policies (+ NRF/NOR hints)
                                        $rateClass = strtoupper(trim((string) ($rate['rateClass'] ?? '')));
                                        if ($rateClass === '') {
                                            $rateClass = hotelbedsRateKeyRateClass($rate['rateKey'] ?? '');
                                        }
                                        $refundFlags = hotelbedsResolveRefundabilityFromRate($rate);
                                        $refundable = (int) $refundFlags['refundable'];
                                        $cancellationFree = (int) $refundFlags['cancellation_free'];

                                        // Determine if breakfast is included
                                        $breakfastIncluded = 0;
                                        if (stripos($boardCode, 'BB') !== false || stripos($boardName, 'breakfast') !== false) {
                                            $breakfastIncluded = 1;
                                        }

                                        // Add option to room group (matching manual hotels structure)
                                        $groupedRoomOptions[$roomCode]['options'][] = [
                                            'option_index' => $rateIndex,
                                            'price_per_night' => $price_per_night_markup['price'],
                                            'price_per_night_details' => $price_per_night_markup,
                                            'total_price' => $total_price_markup['price'],
                                            'total_price_details' => $total_price_markup,
                                            'base_price' => round($total_price_markup['converted_base_price'], 2),
                                            'original_price' => round($total_price_markup['converted_base_price'], 2),
                                            'room_id' => $roomCode,
                                            'rate_key' => $rate['rateKey'] ?? '', // Important for booking
                                            'rate_class' => $rate['rateClass'] ?? '',
                                            'rate_type' => $rate['rateType'] ?? '',
                                            'rate_class' => $rateClass,
                                            'needs_recheck' => (strtoupper(trim((string) ($rate['rateType'] ?? ''))) === 'RECHECK') ? 1 : 0,
                                            'board_code' => $boardCode,
                                            'board_name' => $boardName,
                                            'rate_comments' => $rateCommentsText !== '' ? $rateCommentsText : null,
                                            'rate_comments_id' => $rateCommentsId !== '' ? $rateCommentsId : null,
                                            'max_adults' => $maxAdults,
                                            'max_children' => $maxChildren,
                                            'breakfast_included' => $breakfastIncluded,
                                            'refundable' => $refundable,
                                            'cancellation_free' => $cancellationFree,
                                            'discount_percentage' => 0, // Hotelbeds doesn't provide this
                                            'packaging' => $rate['packaging'] ?? false,
                                            'allotment' => $rate['allotment'] ?? null
                                        ];

                                        // Update max capacity
                                        if ($price_per_night_markup['price'] < $groupedRoomOptions[$roomCode]['min_price_per_night']) {
                                            $groupedRoomOptions[$roomCode]['min_price_per_night'] = $price_per_night_markup['price'];
                                        }

                                        if ($maxAdults > $groupedRoomOptions[$roomCode]['max_adults']) {
                                            $groupedRoomOptions[$roomCode]['max_adults'] = $maxAdults;
                                        }

                                        if ($maxChildren > $groupedRoomOptions[$roomCode]['max_children']) {
                                            $groupedRoomOptions[$roomCode]['max_children'] = $maxChildren;
                                        }
                                    }
                                }
                            }
                        }

                        // Convert grouped rooms to array and add options_count
                        $room_options_data = [];
                        foreach ($groupedRoomOptions as $roomCode => $group) {
                            $group['options_count'] = count($group['options']);
                            $room_options_data[] = $group;
                        }

                        // --- price trace: one line per hotel. Everything needed to replay
                        // Hotelbeds response -> rate selection -> markup/FX -> shown price.
                        if ($traceOn) {
                            $traceCheapestAll = null;
                            $traceCheapestKept = null;
                            foreach ($traceRates as $traceRate) {
                                $traceNet = $traceRate['net'] ?? null;
                                if ($traceNet === null) {
                                    continue;
                                }
                                if ($traceCheapestAll === null || $traceNet < ($traceCheapestAll['net'] ?? INF)) {
                                    $traceCheapestAll = $traceRate;
                                }
                                if (empty($traceRate['excluded_by'])
                                    && ($traceCheapestKept === null || $traceNet < ($traceCheapestKept['net'] ?? INF))) {
                                    $traceCheapestKept = $traceRate;
                                }
                            }

                            usort($traceRates, static function ($a, $b) {
                                return ($a['net'] ?? INF) <=> ($b['net'] ?? INF);
                            });

                            hotelbedsPriceTraceLog('hotel_price', [
                                'hotel_id' => $hotelCode,
                                'hotel_name' => $candidateHotelsByCode[$hotelCode]['name'] ?? ($apiHotel['name'] ?? null),
                                'check_in' => $checkin_date,
                                'check_out' => $checkout_date,
                                'nights' => (int) $number_of_nights,
                                'occupancies' => $baseApiPayload['occupancies'] ?? [],
                                'nationality' => $baseApiPayload['nationality'] ?? null,
                                'source_market_sent' => $baseApiPayload['sourceMarket'] ?? null,
                                'filter' => $baseApiPayload['filter'] ?? [],
                                'api_currency' => $apiCurrency,
                                'display_currency' => $sessionCurrency,
                                'api_min_rate' => $minRate,
                                'api_max_rate' => isset($apiHotel['maxRate']) ? (float) $apiHotel['maxRate'] : null,
                                'rates_returned' => count($traceRates),
                                'rates_excluded_packaging' => count(array_filter($traceRates, static function ($r) {
                                    return !empty($r['excluded_by']);
                                })),
                                // If these two differ, the site's "from" price is higher than the
                                // cheapest rate Hotelbeds actually returned, and the delta is the gap.
                                'cheapest_rate_returned' => $traceCheapestAll,
                                'cheapest_rate_used' => $traceCheapestKept,
                                'net_gap_from_exclusions' => ($traceCheapestAll && $traceCheapestKept)
                                    ? round(($traceCheapestKept['net'] ?? 0) - ($traceCheapestAll['net'] ?? 0), 2)
                                    : null,
                                'selected' => $traceChosen,
                                'final_price_per_night' => $min_price_per_night_markup ? round($min_price_per_night_markup['price'], 2) : 0,
                                'final_total_price' => $min_total_price_markup ? round($min_total_price_markup['price'], 2) : 0,
                                // Sorted cheapest-first above, so this keeps the rates a
                                // "from" price could ever come from without writing a
                                // multi-megabyte line for a 25-hotel page.
                                'all_rates' => array_slice($traceRates, 0, 12),
                            ]);
                        }

                        // Store in available hotels array
                        $availableHotelsFromApi[$hotelCode] = [
                            'min_price_per_night' => $min_price_per_night_markup ? round($min_price_per_night_markup['price'], 2) : 0,
                            'min_total_price' => $min_total_price_markup ? round($min_total_price_markup['price'], 2) : 0,
                            'min_price_per_night_details' => $min_price_per_night_markup,
                            'min_total_price_details' => $min_total_price_markup,
                            'currency' => $sessionCurrency,
                            'api_currency' => $apiCurrency,
                            'room_options' => $room_options_data,
                            'has_available_rooms' => count($room_options_data) > 0
                        ];
                    }
                }
        } elseif ($httpCode !== 0 && $httpCode !== 200) {
            error_log("Hotelbeds API Error - HTTP {$httpCode}: {$curlError}");
        }
    }

    hotelbedsSearchMark('parse_rates');

    // ========================================
    // STEP 5: BUILD RESPONSE WITH DETAILED ROOM OPTIONS
    // ========================================
    $formattedHotels = [];

    foreach ($hotels as $hotel) {
        $hotelCode = $hotel['hotel_code'];

        // Skip hotels with no real API price/availability
        if (!isset($availableHotelsFromApi[$hotelCode])) {
            continue;
        }

        $apiPricing = $availableHotelsFromApi[$hotelCode];
        if (empty($apiPricing['has_available_rooms']) || empty($apiPricing['room_options'])) {
            continue;
        }

        // Extract geolocation
        $latitude = !empty($hotel['latitude']) ? floatval($hotel['latitude']) : null;
        $longitude = !empty($hotel['longitude']) ? floatval($hotel['longitude']) : null;

        // Extract star rating
        $stars = 0;
        if (!empty($hotel['category_code'])) {
            preg_match('/\d+/', $hotel['category_code'], $matches);
            $stars = !empty($matches[0]) ? (int) $matches[0] : 0;
        }

        // Get hotel images
        $hotelImages = [];
        $hotelImage = '';
        $imageBaseUrl = 'https://photos.hotelbeds.com/giata/bigger/';

        $imageRecords = array_slice($pageCatalogue['hotel_images'][(string) $hotelCode] ?? [], 0, 10);

        foreach ($imageRecords as $img) {
            $imageUrl = $imageBaseUrl . $img['image_url'];
            $hotelImages[] = $imageUrl;

            if (empty($hotelImage)) {
                $hotelImage = $imageUrl;
            }
        }

        // Get hotel amenities (enough for listing filter; cards only show first few)
        $hotelAmenities = [];
        $facilityRecords = array_slice($pageCatalogue['hotel_amenities'][(string) $hotelCode] ?? [], 0, 80);

        foreach ($facilityRecords as $facility) {
            $description = trim((string) ($facility['description'] ?? ''));
            if ($description === '' || is_numeric($description)) {
                $description = trim((string) ($facility['facility_description'] ?? ''));
            }
            if ($description === '' || is_numeric($description)) {
                continue;
            }

            // Deduplicate identical amenities and skip numeric labels
            $existingNames = array_column($hotelAmenities, 'name');
            if (in_array($description, $existingNames, true)) {
                continue;
            }

            $hotelAmenities[] = [
                'id' => count($hotelAmenities) + 1,
                'name' => $description
            ];
        }

        // ========================================
        // BUILD RESPONSE OBJECT (MATCHING MANUAL HOTELS)
        // ========================================
        $hotelRefundable = 0;
        $hotelCancellationFree = 0;
        foreach ($apiPricing['room_options'] as $roomOption) {
            foreach ($roomOption['options'] ?? [] as $opt) {
                if (!empty($opt['refundable'])) {
                    $hotelRefundable = 1;
                }
                if (!empty($opt['cancellation_free'])) {
                    $hotelCancellationFree = 1;
                }
            }
        }

        $formattedHotels[] = [
            // Basic hotel information
            'hotel_id' => $hotelCode,
            'name' => $hotel['name'],
            'img' => $hotelImage,
            'images' => $hotelImages,
            'location' => $hotel['city'] ?? $hotel['destination_code'],
            'address' => $hotel['address'] ?? '',
            'stars' => $stars,
            'rating' => (float) $stars,

            // Geolocation
            'latitude' => $latitude,
            'longitude' => $longitude,

            // Pricing (with markup + currency conversion applied)
            'display_price' => $apiPricing['min_total_price'],
            'display_price_per_night' => $apiPricing['min_price_per_night'],
            'actual_price' => $apiPricing['min_total_price'],
            'actual_price_per_night' => $apiPricing['min_price_per_night'],
            'actual_price_details' => $apiPricing['min_total_price_details'],
            'actual_price_per_night_details' => $apiPricing['min_price_per_night_details'],
            'currency' => $sessionCurrency,
            'original_currency' => $apiPricing['api_currency'],

            // Hotel features
            'amenities' => $hotelAmenities,
            // Resolve code OR stored typeDescription → listing filter label (Hotel, Apartment, …)
            'accommodation_type' => (function_exists('hotelbedsLookupAccommodationName')
                ? hotelbedsLookupAccommodationName($hotelbedsDb, (string) ($hotel['accommodation_type'] ?? ''), 'Hotel')
                : (!empty($hotel['accommodation_type']) ? (string) $hotel['accommodation_type'] : 'Hotel')),

            // ROOM OPTIONS - NOW STRUCTURED LIKE MANUAL HOTELS
            'room_options' => $apiPricing['room_options'],
            'has_available_rooms' => $apiPricing['has_available_rooms'],

            // Supplier info
            'supplier' => 'hotelbeds',
            'supplier_name' => 'hotelbeds',
            'supplier_id' => '16',
            'color' => '#012b7e',

            // Additional metadata
            'country_code' => $hotel['country_code'] ?? '',
            'destination_code' => $hotel['destination_code'] ?? '',
            'chain_code' => $hotel['chain_code'] ?? '',
            'category_code' => $hotel['category_code'] ?? '',

            // Policies
            'phone' => $hotel['phone'] ?? '',
            'email' => $hotel['email'] ?? '',
            'website' => $hotel['web'] ?? '',
            'refundable' => $hotelRefundable,
            'cancellation_free' => $hotelCancellationFree,
        ];

        // Content masters (zone/chain/category names) — Hotelbeds only; light lookup for listing cards
        if (function_exists('hotelbedsEnrichHotelContentMeta')) {
            $contentMeta = hotelbedsEnrichHotelContentMeta($hotelbedsDb, $hotel, [
                'include_amenities' => false,
                'include_issues' => false,
                'include_terminals' => false,
            ]);
            $formattedHotels[count($formattedHotels) - 1] = array_merge(
                $formattedHotels[count($formattedHotels) - 1],
                [
                    'destination_name' => $contentMeta['destination_name'] ?? '',
                    'zone_code' => $contentMeta['zone_code'] ?? '',
                    'zone_name' => $contentMeta['zone_name'] ?? '',
                    'chain_name' => $contentMeta['chain_name'] ?? '',
                    'category_name' => $contentMeta['category_name'] ?? '',
                    'segments' => $contentMeta['segments'] ?? [],
                ]
            );
            if (!empty($contentMeta['zone_name']) || !empty($contentMeta['destination_name'])) {
                $locBits = array_filter([
                    $hotel['city'] ?? null,
                    $contentMeta['zone_name'] ?? null,
                    $contentMeta['destination_name'] ?? null,
                ]);
                if (!empty($locBits)) {
                    $formattedHotels[count($formattedHotels) - 1]['location'] = implode(', ', $locBits);
                }
            }
        }

        // Limit results to requested per_page
        if (count($formattedHotels) >= $per_page) {
            break;
        }
    }

    // ========================================
    // CALCULATE PAGINATION HEADERS
    // ========================================
    $totalResults = count($formattedHotels);
    $hasMore = ($offset + $per_page) < $exactAvailableTotal || !$candidatesExhausted;
    $totalPages = $candidatesExhausted
        ? max(1, (int) ceil($exactAvailableTotal / max(1, $per_page)))
        : max($page, (int) ceil(max($exactAvailableTotal, $offset + $per_page) / max(1, $per_page)));

    // ========================================
    // JSON RESPONSE
    // ========================================
    ob_end_clean();

    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Destination-Total: ' . count($candidateHotelsByCode));
    header('X-Grand-Total: ' . $exactAvailableTotal);
    header('X-Total-Pages: ' . $totalPages);
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));
    // Whether every candidate in the destination has now been priced. Until this is
    // true, X-Grand-Total is only "how many we have found so far", not the real total,
    // so the listing must not present it as a settled figure.
    header('X-Search-Exhausted: ' . ($candidatesExhausted ? 'true' : 'false'));
    header($filtersAppliedHeader);
    hotelbedsSearchMark('build_cards');
    header(hotelbedsSearchTimingHeader());

    echo json_encode($formattedHotels);
});
