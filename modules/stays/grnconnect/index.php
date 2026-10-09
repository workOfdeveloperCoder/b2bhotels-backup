<?php
// ============================================================================
// GRNCONNECT STAYS MODULE - ENTRY POINT
// ============================================================================
// Registers all GRNConnect hotel routes and shared helpers. Mirrors the
// structure used by the other stay providers (see stays/ratehawk/index.php).
// ============================================================================

global $router;

require_once __DIR__ . '/api.php';

include __DIR__ . '/creds.php';
include __DIR__ . '/content/content.php';
include __DIR__ . '/destination.php';
include __DIR__ . '/search.php';
include __DIR__ . '/details.php';
include __DIR__ . '/selection.php';
include __DIR__ . '/validation.php';
include __DIR__ . '/rooms.php';
include __DIR__ . '/recheck.php';

// ACTIONS
include __DIR__ . '/actions/issue.php';
include __DIR__ . '/actions/cancel.php';
include __DIR__ . '/actions/void.php';
include __DIR__ . '/actions/refund.php';

// ----------------------------------------------------------------------------
// LOGGING - writes request/response JSON files under modules/stays/grnconnect/logs
// ----------------------------------------------------------------------------
if (!function_exists('grnconnect_log')) {
    function grnconnect_log($category, $action, $requestData, $responseData, $extraId = '', $meta = [])
    {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $uniq      = uniqid();
        $prefix    = $category;
        if ($extraId) $prefix .= '_' . $extraId;
        if ($action)  $prefix .= '_' . $action;

        $reqFile = "{$dir}/{$prefix}_request_{$timestamp}_{$uniq}.json";
        $resFile = "{$dir}/{$prefix}_response_{$timestamp}_{$uniq}.json";

        $parseBody = function ($data) {
            if (is_array($data) || is_object($data)) return $data;
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $data;
            }
            return $data;
        };

        $reqLog = [
            'url'     => $meta['url'] ?? '',
            'method'  => $meta['method'] ?? '',
            'headers' => $meta['headers'] ?? [],
            'meta'    => [
                'http_code'   => $meta['http_code'] ?? '',
                'duration_ms' => $meta['duration_ms'] ?? '',
            ],
            'request' => $parseBody($requestData),
        ];
        $resLog = ['response_body' => $parseBody($responseData)];

        @file_put_contents($reqFile, json_encode($reqLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($reqFile, 0666);
        @file_put_contents($resFile, json_encode($resLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($resFile, 0666);
    }
}

// ----------------------------------------------------------------------------
// SHARED HELPERS - used by search / details / rooms / actions
// ----------------------------------------------------------------------------

if (!function_exists('grnconnect_module')) {
    /** Fetch the grnconnect modules row. */
    function grnconnect_module($db)
    {
        return $db->get('modules', '*', ['name' => 'grnconnect', 'type' => 'stays']);
    }
}

if (!function_exists('grnconnect_content_db')) {
    /**
     * Connect to the GRNConnect content database (host/database/username/password
     * columns of the modules row). Returns a Medoo instance or null.
     */
    function grnconnect_content_db($module)
    {
        if (empty($module['database'])) {
            return null;
        }
        try {
            return new \Medoo\Medoo([
                'type'      => 'mysql',
                'host'      => $module['host'] ?: 'localhost',
                'database'  => $module['database'],
                'username'  => $module['username'] ?? 'root',
                'password'  => $module['password'] ?? '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
        } catch (\Throwable $e) {
            error_log('GRNConnect content DB connection failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('grnconnect_to_iso_date')) {
    /** Normalize a DD-MM-YYYY or YYYY-MM-DD date to ISO YYYY-MM-DD. */
    function grnconnect_to_iso_date($date)
    {
        $date = trim((string)$date);
        if ($date === '') return $date;
        if (strpos($date, '-') !== false) {
            $parts = explode('-', $date);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            }
        }
        return $date;
    }
}

if (!function_exists('grnconnect_nights')) {
    function grnconnect_nights($checkinIso, $checkoutIso)
    {
        try {
            $d1 = new DateTime($checkinIso);
            $d2 = new DateTime($checkoutIso);
            return max(1, (int)$d1->diff($d2)->days);
        } catch (\Throwable $e) {
            return 1;
        }
    }
}

if (!function_exists('grnconnect_parse_facilities')) {
    /** GRNConnect returns facilities as a ";"-separated string. */
    function grnconnect_parse_facilities($facilities)
    {
        if (is_array($facilities)) return array_values(array_filter(array_map('trim', $facilities)));
        if (!is_string($facilities) || $facilities === '') return [];
        $parts = array_map('trim', explode(';', $facilities));
        $parts = array_values(array_filter($parts, function ($p) {
            return $p !== '' && strlen($p) > 1;
        }));
        return array_map(function ($p) {
            return ucfirst($p);
        }, $parts);
    }
}

if (!function_exists('grnconnect_clean_city_name')) {
    /** City name comes as "Dubai, Dubai, United Arab Emirates, AE" -> "Dubai". */
    function grnconnect_clean_city_name($name)
    {
        $name = trim((string)$name);
        if ($name === '') return '';
        $parts = explode(',', $name);
        return trim($parts[0]);
    }
}

if (!function_exists('grnconnect_hotel_select_cols')) {
    function grnconnect_hotel_select_cols()
    {
        return ['hotel_code', 'name', 'address', 'city_code', 'country_code', 'latitude', 'longitude', 'category', 'images', 'acc_name', 'facilities'];
    }
}

if (!function_exists('grnconnect_hotels_by_codes')) {
    /** Load hotel rows by hotel_code list (preserves order when possible). */
    function grnconnect_hotels_by_codes($contentDb, array $codes)
    {
        $codes = array_values(array_filter(array_map('strval', $codes)));
        if (empty($codes) || !$contentDb) return [];

        $rows = $contentDb->select('grnconnect_hotels', grnconnect_hotel_select_cols(), [
            'hotel_code' => $codes,
        ]) ?: [];

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string)$row['hotel_code']] = $row;
        }

        $ordered = [];
        foreach ($codes as $code) {
            if (isset($byCode[$code])) $ordered[] = $byCode[$code];
        }
        return $ordered;
    }
}

if (!function_exists('grnconnect_hotels_by_name')) {
    /** Search hotels by name (and optional address). */
    function grnconnect_hotels_by_name($contentDb, $query, $limit = 50, $offset = 0)
    {
        $needle = trim((string)$query);
        if ($needle === '' || !$contentDb) return ['rows' => [], 'total' => 0];

        $where = [
            'OR' => [
                'name[~]'    => $needle,
                'address[~]' => $needle,
            ],
        ];
        $total = (int)$contentDb->count('grnconnect_hotels', $where);
        $rows  = $contentDb->select('grnconnect_hotels', grnconnect_hotel_select_cols(), array_merge($where, [
            'ORDER' => ['name' => 'ASC'],
            'LIMIT' => [$offset, $limit],
        ])) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('grnconnect_hotels_by_city')) {
    /** Hotels in a GRN city_code, paginated. */
    function grnconnect_hotels_by_city($contentDb, $cityCode, $limit = 50, $offset = 0)
    {
        $cityCode = trim((string)$cityCode);
        if ($cityCode === '' || !$contentDb) return ['rows' => [], 'total' => 0];

        $where = ['city_code' => $cityCode];
        $total = (int)$contentDb->count('grnconnect_hotels', $where);
        $rows  = $contentDb->select('grnconnect_hotels', grnconnect_hotel_select_cols(), array_merge($where, [
            'ORDER' => ['name' => 'ASC'],
            'LIMIT' => [$offset, $limit],
        ])) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('grnconnect_resolve_city_code')) {
    /** Resolve a destination string / code to a GRN city_code. */
    function grnconnect_resolve_city_code($contentDb, $destination, $destinationCode = '')
    {
        if (!$contentDb) return '';

        $destinationCode = trim((string)$destinationCode);
        $destination     = trim((string)$destination);

        if ($destinationCode !== '' && !ctype_alpha($destinationCode)) {
            $row = $contentDb->get('grnconnect_cities', ['city_code'], ['city_code' => $destinationCode]);
            if ($row) return (string)$row['city_code'];
        }
        if ($destination !== '' && ctype_digit($destination)) {
            $row = $contentDb->get('grnconnect_cities', ['city_code'], ['city_code' => $destination]);
            if ($row) return (string)$row['city_code'];
        }

        $needle = grnconnect_clean_city_name($destination);
        if ($needle === '') return '';

        $city = $contentDb->get('grnconnect_cities', ['city_code', 'search_name', 'name'], [
            'OR' => [
                'search_name'    => $needle,
                'name'           => $needle,
                'search_name[~]' => $needle,
                'name[~]'        => $needle,
            ],
            'ORDER' => ['search_name' => 'ASC'],
            'LIMIT' => 1,
        ]);

        return $city ? (string)$city['city_code'] : '';
    }
}

if (!function_exists('grnconnect_hotels_by_circle')) {
    /**
     * Hotels within radius (km) of lat/lng using Haversine.
     * Uses a bounding-box prefilter for performance.
     */
    function grnconnect_hotels_by_circle($contentDb, $lat, $lng, $radiusKm = 20, $limit = 50, $offset = 0)
    {
        $lat = (float)$lat;
        $lng = (float)$lng;
        $radiusKm = max(1, min(100, (float)$radiusKm));
        $limit  = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        if (!$contentDb || abs($lat) > 90 || abs($lng) > 180) {
            return ['rows' => [], 'total' => 0];
        }

        // ~111 km per degree latitude; longitude shrinks by cos(lat)
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / max(0.01, (111.0 * cos(deg2rad($lat))));
        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        $pdo = $contentDb->pdo;
        $countSql = "
            SELECT COUNT(*) FROM (
                SELECT hotel_code,
                    (6371 * acos(
                        LEAST(1, GREATEST(-1,
                            cos(radians(:lat1)) * cos(radians(latitude)) * cos(radians(longitude) - radians(:lng1))
                            + sin(radians(:lat2)) * sin(radians(latitude))
                        ))
                    )) AS distance_km
                FROM grnconnect_hotels
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                  AND latitude BETWEEN :minLat AND :maxLat
                  AND longitude BETWEEN :minLng AND :maxLng
                HAVING distance_km <= :radius
            ) AS matched
        ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([
            ':lat1' => $lat, ':lng1' => $lng, ':lat2' => $lat,
            ':minLat' => $minLat, ':maxLat' => $maxLat,
            ':minLng' => $minLng, ':maxLng' => $maxLng,
            ':radius' => $radiusKm,
        ]);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT hotel_code, name, address, city_code, country_code, latitude, longitude, category, images, acc_name, facilities,
                (6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(:lat1)) * cos(radians(latitude)) * cos(radians(longitude) - radians(:lng1))
                        + sin(radians(:lat2)) * sin(radians(latitude))
                    ))
                )) AS distance_km
            FROM grnconnect_hotels
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
              AND latitude BETWEEN :minLat AND :maxLat
              AND longitude BETWEEN :minLng AND :maxLng
            HAVING distance_km <= :radius
            ORDER BY distance_km ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':lat1' => $lat, ':lng1' => $lng, ':lat2' => $lat,
            ':minLat' => $minLat, ':maxLat' => $maxLat,
            ':minLng' => $minLng, ':maxLng' => $maxLng,
            ':radius' => $radiusKm,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('grnconnect_resolve_location_coords')) {
    /** Resolve a place name to lat/lng from the main locations table. */
    function grnconnect_resolve_location_coords($db, $query)
    {
        $needle = grnconnect_clean_city_name($query);
        if ($needle === '' || !$db) return null;

        try {
            $row = $db->get('locations', ['id', 'city', 'country', 'latitude', 'longitude'], [
                'OR' => [
                    'city[~]'    => $needle,
                    'country[~]' => $needle,
                ],
                'status' => 1,
                'ORDER'  => ['city' => 'ASC'],
                'LIMIT'  => 1,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row || $row['latitude'] === null || $row['longitude'] === null || $row['latitude'] === '' || $row['longitude'] === '') {
            return null;
        }

        return [
            'latitude'  => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'city'      => $row['city'] ?? $needle,
            'country'   => $row['country'] ?? '',
        ];
    }
}

if (!function_exists('grnconnect_resolve_search_hotels')) {
    /**
     * Resolve hotel rows for availability search.
     * Modes: hotel | city | location | circle | auto
     *
     * @return array{mode:string,rows:array,total:int,meta:array}
     */
    function grnconnect_resolve_search_hotels($contentDb, $db, array $input, $batchSize = 50, $page = 1)
    {
        $batchSize = max(1, min(100, (int)$batchSize));
        $page      = max(1, (int)$page);
        $offset    = ($page - 1) * $batchSize;

        $mode     = strtolower(trim((string)($input['search_type'] ?? $input['mode'] ?? 'auto')));
        if (!in_array($mode, ['hotel', 'city', 'location', 'circle', 'auto'], true)) {
            $mode = 'auto';
        }

        $dest     = trim((string)($input['destination'] ?? $input['city'] ?? ''));
        $destCode = trim((string)($input['destination_code'] ?? ''));
        $hotelCode = trim((string)($input['hotel_code'] ?? $input['hotel_id'] ?? ''));
        $lat = $input['latitude'] ?? $input['lat'] ?? null;
        $lng = $input['longitude'] ?? $input['lng'] ?? $input['lon'] ?? null;
        $radius = (float)($input['radius'] ?? $input['radius_km'] ?? 20);
        if ($radius <= 0) $radius = 20;

        $meta = ['destination' => $dest, 'radius_km' => $radius];

        // Hotel mode only — never let a leftover hotel_code hijack city/auto searches
        if ($mode === 'hotel') {
            if ($hotelCode !== '') {
                $rows = grnconnect_hotels_by_codes($contentDb, [$hotelCode]);
                if (!empty($rows)) {
                    return ['mode' => 'hotel', 'rows' => $rows, 'total' => count($rows), 'meta' => $meta];
                }
            }
            $found = grnconnect_hotels_by_name($contentDb, $dest !== '' ? $dest : $hotelCode, $batchSize, $offset);
            return ['mode' => 'hotel', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
        }

        // Explicit circle (coords required)
        if ($mode === 'circle') {
            if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                $found = grnconnect_hotels_by_circle($contentDb, $lat, $lng, $radius, $batchSize, $offset);
                $meta['latitude']  = (float)$lat;
                $meta['longitude'] = (float)$lng;
                return ['mode' => 'circle', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
            }
            return ['mode' => 'circle', 'rows' => [], 'total' => 0, 'meta' => $meta];
        }

        // Explicit location name → coords → circle
        if ($mode === 'location') {
            if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                $found = grnconnect_hotels_by_circle($contentDb, $lat, $lng, $radius, $batchSize, $offset);
                $meta['latitude']  = (float)$lat;
                $meta['longitude'] = (float)$lng;
                return ['mode' => 'location', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
            }
            $coords = grnconnect_resolve_location_coords($db, $dest);
            if ($coords) {
                $found = grnconnect_hotels_by_circle($contentDb, $coords['latitude'], $coords['longitude'], $radius, $batchSize, $offset);
                $meta = array_merge($meta, $coords);
                return ['mode' => 'location', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
            }
            // Fall through to city if location has no coords
        }

        // City mode (explicit) or auto — city first
        if ($mode === 'city' || $mode === 'auto' || $mode === 'location') {
            $cityCode = grnconnect_resolve_city_code($contentDb, $dest, $destCode);
            if ($cityCode !== '') {
                $found = grnconnect_hotels_by_city($contentDb, $cityCode, $batchSize, $offset);
                $meta['city_code'] = $cityCode;
                return ['mode' => 'city', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
            }
            if ($mode === 'city') {
                return ['mode' => 'city', 'rows' => [], 'total' => 0, 'meta' => $meta];
            }
        }

        // AUTO fallbacks: location circle → hotel name
        if ($mode === 'auto') {
            $coords = grnconnect_resolve_location_coords($db, $dest);
            if ($coords) {
                $found = grnconnect_hotels_by_circle($contentDb, $coords['latitude'], $coords['longitude'], $radius, $batchSize, $offset);
                if ($found['total'] > 0) {
                    $meta = array_merge($meta, $coords);
                    return ['mode' => 'location', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
                }
            }

            if ($dest !== '') {
                $found = grnconnect_hotels_by_name($contentDb, $dest, $batchSize, $offset);
                if ($found['total'] > 0) {
                    return ['mode' => 'hotel', 'rows' => $found['rows'], 'total' => $found['total'], 'meta' => $meta];
                }
            }
        }

        return ['mode' => 'none', 'rows' => [], 'total' => 0, 'meta' => $meta];
    }
}

if (!function_exists('grnconnect_resolve_all_search_hotels')) {
    /**
     * Resolve ALL matching hotel rows (no UI page slice) for parallel availability.
     * Caps at GRNCONNECT_SEARCH_MAX_HOTELS to keep memory/API volume sane.
     *
     * @return array{mode:string,rows:array,total:int,meta:array}
     */
    function grnconnect_resolve_all_search_hotels($contentDb, $db, array $input)
    {
        $maxHotels = defined('GRNCONNECT_SEARCH_MAX_HOTELS')
            ? (int)GRNCONNECT_SEARCH_MAX_HOTELS
            : 3000;
        $maxHotels = max(100, min(10000, $maxHotels));

        // First resolve with a large page to get total + first slice.
        // grnconnect_resolve_search_hotels clamps batchSize to 100, so we page
        // through content DB until we have everything (or hit the cap).
        $page = 1;
        $batchSize = 100;
        $allRows = [];
        $mode = 'none';
        $meta = [];
        $total = 0;

        do {
            $resolved = grnconnect_resolve_search_hotels($contentDb, $db, $input, $batchSize, $page);
            $mode  = $resolved['mode'];
            $meta  = $resolved['meta'];
            $total = (int)$resolved['total'];
            $chunk = is_array($resolved['rows']) ? $resolved['rows'] : [];

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $code = (string)($row['hotel_code'] ?? '');
                if ($code === '') continue;
                $allRows[$code] = $row;
                if (count($allRows) >= $maxHotels) {
                    break 2;
                }
            }

            $page++;
            // Safety: stop if we already collected all reported rows
        } while (count($allRows) < $total && $page <= (int)ceil($maxHotels / $batchSize) + 1);

        return [
            'mode'  => $mode,
            'rows'  => array_values($allRows),
            'total' => $total > 0 ? $total : count($allRows),
            'meta'  => $meta,
        ];
    }
}

if (!function_exists('grnconnect_format_listing_hotel')) {
    /**
     * Map a GRN availability hotel + local content row into PHPTRAVELS listing shape.
     *
     * @return array|null null when hotel has no usable rate/price
     */
    function grnconnect_format_listing_hotel(array $hotel, array $local, $sid, $destination, $currency, $nights, $module, $db, $resolvedMode = '')
    {
        $code = (string)($hotel['hotel_code'] ?? '');
        if ($code === '') {
            return null;
        }

        $rate = $hotel['min_rate'] ?? (isset($hotel['rates'][0]) ? $hotel['rates'][0] : null);
        if (!$rate) {
            return null;
        }

        $basePrice = grnconnect_rate_price_total($rate);
        if ($basePrice <= 0) {
            return null;
        }

        $rateCurrency = $rate['currency'] ?? $currency;
        $finalPrice   = $basePrice;
        try {
            $mk = MARKUP($basePrice, $module, $db, $rateCurrency, $currency);
            if (isset($mk['price']) && $mk['price'] > 0) {
                $finalPrice = $mk['price'];
            }
        } catch (\Throwable $e) { /* keep base */ }

        $name     = $hotel['name'] ?? ($local['name'] ?? 'Hotel');
        $address  = $hotel['address'] ?? ($local['address'] ?? '');
        $category = $hotel['category'] ?? ($local['category'] ?? 0);
        $lat      = $hotel['geolocation']['latitude'] ?? ($local['latitude'] ?? null);
        $lng      = $hotel['geolocation']['longitude'] ?? ($local['longitude'] ?? null);

        $images = grnconnect_hotel_images($hotel['images'] ?? null);
        if (empty($images) && !empty($local['images'])) {
            $images = grnconnect_hotel_images($local['images']);
        }

        $accommodationType = trim((string)($hotel['acc_type'] ?? ''));
        if ($accommodationType === '' || is_numeric($accommodationType)) {
            $accommodationType = trim((string)($local['acc_name'] ?? ''));
        }
        if ($accommodationType === '') {
            $accommodationType = 'Hotel';
        }
        $promotion = function_exists('grnconnect_format_promotions')
            ? grnconnect_format_promotions($rate)
            : ['discount_percentage' => 0, 'label' => '', 'text' => '', 'details' => [], 'has_promotion' => false];

        $item = [
            'id'                  => $code,
            'hotel_id'            => $code,
            'original_id'         => $code,
            'name'                => $name,
            'stars'               => (float)$category,
            'star_rating'         => (float)$category,
            'rating'              => (float)$category,
            'address'             => $address,
            'city'                => $destination,
            'country'             => $hotel['country'] ?? ($local['country_code'] ?? ''),
            'location'            => trim((string)$destination),
            'latitude'            => $lat !== null ? (float)$lat : null,
            'longitude'           => $lng !== null ? (float)$lng : null,
            'images'              => $images,
            'image'               => $images[0] ?? '',
            'price'               => round($finalPrice, 2),
            'price_per_night'     => round($finalPrice / max(1, $nights), 2),
            'original_price'      => round($basePrice, 2),
            'currency'            => $currency,
            'meal'                => grnconnect_meal_plan($rate),
            'sid'                 => $sid,
            'has_available_rooms' => true,
            'accommodation_type'  => $accommodationType,
            'amenities'           => grnconnect_parse_facilities(
                $hotel['facilities'] ?? ($local['facilities'] ?? '')
            ),
            'discount'            => $promotion['discount_percentage'],
            'promotion_label'     => $promotion['label'],
            'promotion_text'      => $promotion['text'],
            'promotions_details'  => $promotion['details'],
            'has_promotion'       => $promotion['has_promotion'],
            'supplier'            => 'grnconnect',
            'search_mode'         => $resolvedMode,
        ];

        if (isset($local['distance_km'])) {
            $item['distance_km'] = round((float)$local['distance_km'], 2);
        }

        return $item;
    }
}

if (!function_exists('grnconnect_search_cache_dir')) {
    function grnconnect_search_cache_dir()
    {
        $dir = __DIR__ . '/cache/searches';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }
        return $dir;
    }
}

if (!function_exists('grnconnect_search_session_path')) {
    function grnconnect_search_session_path($token)
    {
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$token);
        if ($token === '') {
            return '';
        }
        return grnconnect_search_cache_dir() . '/' . $token . '.json';
    }
}

if (!function_exists('grnconnect_search_session_save')) {
    function grnconnect_search_session_save($token, array $state)
    {
        $path = grnconnect_search_session_path($token);
        if ($path === '') {
            return false;
        }
        $state['updated_at'] = time();
        $ok = @file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($ok !== false) {
            @chmod($path, 0666);
        }
        // Best-effort prune of stale sessions (>35 min — search_id TTL is 30)
        grnconnect_search_session_prune(35 * 60);
        return $ok !== false;
    }
}

if (!function_exists('grnconnect_search_session_load')) {
    function grnconnect_search_session_load($token)
    {
        $path = grnconnect_search_session_path($token);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('grnconnect_search_session_prune')) {
    function grnconnect_search_session_prune($maxAgeSeconds = 2100)
    {
        $dir = grnconnect_search_cache_dir();
        $files = @glob($dir . '/*.json') ?: [];
        $cutoff = time() - (int)$maxAgeSeconds;
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}

if (!function_exists('grnconnect_merge_batch_hotels')) {
    /**
     * Upsert hotels from an availability/refetch response into the master map.
     * hotel_code is the unique key. Existing entries are updated (price/sid/etc).
     *
     * @param array $masterByCode keyed by hotel_code
     * @return array list of upserted listing items from this response
     */
    function grnconnect_merge_batch_hotels(
        array &$masterByCode,
        array $apiHotels,
        $searchId,
        array $localByCode,
        $destination,
        $currency,
        $nights,
        $module,
        $db,
        $resolvedMode = ''
    ) {
        $upserted = [];
        foreach ($apiHotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $code = (string)($hotel['hotel_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $local = $localByCode[$code] ?? [];
            $item = grnconnect_format_listing_hotel(
                $hotel,
                is_array($local) ? $local : [],
                $searchId,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode
            );
            if ($item === null) {
                continue;
            }
            $masterByCode[$code] = $item;
            $upserted[] = $item;
        }
        return $upserted;
    }
}

if (!function_exists('grnconnect_batches_complete')) {
    /** True when every batch has more_results === false (or failed and stopped). */
    function grnconnect_batches_complete(array $batches)
    {
        foreach ($batches as $batch) {
            if (!empty($batch['more_results'])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('grnconnect_hotel_images')) {
    /**
     * Build a list of image URLs from an availability "images" object or a
     * stored JSON images column.
     */
    function grnconnect_hotel_images($images)
    {
        $urls = [];
        if (is_string($images) && $images !== '') {
            $decoded = json_decode($images, true);
            $images  = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $images;
        }
        if (is_array($images)) {
            if (isset($images['url']) && $images['url'] !== '') {
                $urls[] = $images['url'];
            }
            if (isset($images['main_image']) && $images['main_image'] !== '' && strpos($images['main_image'], 'http') === 0) {
                $urls[] = $images['main_image'];
            }
            // Plain list of URLs
            foreach ($images as $k => $img) {
                if (is_string($img) && strpos($img, 'http') === 0) {
                    $urls[] = $img;
                } elseif (is_array($img) && !empty($img['url'])) {
                    $urls[] = $img['url'];
                }
            }
        }
        return array_values(array_unique(array_filter($urls)));
    }
}

// ----------------------------------------------------------------------------
// HOTEL IMAGE PERSISTENCE (local DB first, Images API only when needed)
// ----------------------------------------------------------------------------

if (!function_exists('grnconnect_pdo')) {
    /** Normalize Medoo / PDO to a raw PDO connection. */
    function grnconnect_pdo($dbOrPdo)
    {
        if ($dbOrPdo instanceof \PDO) {
            return $dbOrPdo;
        }
        if (is_object($dbOrPdo) && isset($dbOrPdo->pdo) && $dbOrPdo->pdo instanceof \PDO) {
            return $dbOrPdo->pdo;
        }
        return null;
    }
}

if (!function_exists('grnconnect_ensure_images_table')) {
    function grnconnect_ensure_images_table($pdo)
    {
        if (!$pdo instanceof \PDO) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_hotel_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hotel_code VARCHAR(32) NOT NULL,
                url TEXT NOT NULL,
                path VARCHAR(512) DEFAULT NULL,
                caption VARCHAR(255) DEFAULT NULL,
                main_image TINYINT(1) NOT NULL DEFAULT 0,
                size_type VARCHAR(16) NOT NULL DEFAULT 'regular',
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hotel_code (hotel_code),
                INDEX idx_hotel_main (hotel_code, main_image),
                INDEX idx_hotel_order (hotel_code, display_order),
                UNIQUE KEY uniq_hotel_path_size (hotel_code, path(191), size_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('grnconnect_image_log')) {
    function grnconnect_image_log($event, $hotelCode, $extra = [])
    {
        $payload = array_merge(['event' => $event, 'hotel_code' => $hotelCode], $extra);
        if (function_exists('grnconnect_log')) {
            grnconnect_log('images', $event, $payload, ['ok' => true], $hotelCode);
        }
        error_log('GRNConnect images [' . $event . '] hotel=' . $hotelCode . ' ' . json_encode($extra));
    }
}

if (!function_exists('grnconnect_load_hotel_images')) {
    /**
     * Load persisted images for a hotel.
     * Prefer size_type=regular; fall back to any size if regular is empty.
     *
     * @return array list of rows (url, path, caption, main_image, display_order, size_type)
     */
    function grnconnect_load_hotel_images($dbOrPdo, $hotelCode)
    {
        $pdo = grnconnect_pdo($dbOrPdo);
        if (!$pdo || $hotelCode === '') {
            return [];
        }
        try {
            grnconnect_ensure_images_table($pdo);
            $stmt = $pdo->prepare("
                SELECT url, path, caption, main_image, display_order, size_type
                FROM grnconnect_hotel_images
                WHERE hotel_code = :code AND size_type = 'regular'
                ORDER BY main_image DESC, display_order ASC, id ASC
            ");
            $stmt->execute([':code' => $hotelCode]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return $rows;
            }
            // Fallback: any size_type if regular was never stored
            $stmt = $pdo->prepare("
                SELECT url, path, caption, main_image, display_order, size_type
                FROM grnconnect_hotel_images
                WHERE hotel_code = :code
                ORDER BY main_image DESC, display_order ASC, id ASC
            ");
            $stmt->execute([':code' => $hotelCode]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('GRNConnect load_hotel_images failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('grnconnect_hotel_has_images')) {
    function grnconnect_hotel_has_images($dbOrPdo, $hotelCode)
    {
        $pdo = grnconnect_pdo($dbOrPdo);
        if (!$pdo || $hotelCode === '') {
            return false;
        }
        try {
            grnconnect_ensure_images_table($pdo);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM grnconnect_hotel_images WHERE hotel_code = :code');
            $stmt->execute([':code' => $hotelCode]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('grnconnect_save_hotel_images')) {
    /**
     * Persist Images API payload into grnconnect_hotel_images.
     * Replaces any existing rows for the hotel (caller decides when to call).
     *
     * @return array{saved:int, urls:string[], main_image:string}
     */
    function grnconnect_save_hotel_images($dbOrPdo, $hotelCode, array $apiData)
    {
        $pdo = grnconnect_pdo($dbOrPdo);
        $result = ['saved' => 0, 'urls' => [], 'main_image' => ''];
        if (!$pdo || $hotelCode === '') {
            return $result;
        }

        grnconnect_ensure_images_table($pdo);

        $groups = $apiData['images'] ?? [];
        if (!is_array($groups) || empty($groups)) {
            return $result;
        }

        $insert = $pdo->prepare("
            INSERT INTO grnconnect_hotel_images
                (hotel_code, url, path, caption, main_image, size_type, display_order)
            VALUES
                (:hotel_code, :url, :path, :caption, :main_image, :size_type, :display_order)
            ON DUPLICATE KEY UPDATE
                url = VALUES(url),
                caption = VALUES(caption),
                main_image = VALUES(main_image),
                display_order = VALUES(display_order)
        ");

        $pdo->beginTransaction();
        try {
            // Clear previous rows so re-import stays consistent with supplier.
            $del = $pdo->prepare('DELETE FROM grnconnect_hotel_images WHERE hotel_code = :code');
            $del->execute([':code' => $hotelCode]);

            foreach (['regular', 'small', 'large'] as $sizeType) {
                $list = $groups[$sizeType] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                $order = 0;
                foreach ($list as $img) {
                    if (!is_array($img)) {
                        continue;
                    }
                    $url  = trim((string)($img['url'] ?? ''));
                    $path = trim((string)($img['path'] ?? ''));
                    if ($url === '' && $path !== '') {
                        $url = 'https://cdn.grnconnect.com/' . ltrim($path, '/');
                    }
                    if ($url === '') {
                        continue;
                    }
                    if ($path === '') {
                        $path = parse_url($url, PHP_URL_PATH) ?: $url;
                    }
                    $isMain = !empty($img['main_image']) ? 1 : 0;
                    $insert->execute([
                        ':hotel_code'    => $hotelCode,
                        ':url'           => $url,
                        ':path'          => $path,
                        ':caption'       => (string)($img['caption'] ?? ''),
                        ':main_image'    => $isMain,
                        ':size_type'     => $sizeType,
                        ':display_order' => $order,
                    ]);
                    $result['saved']++;
                    if ($sizeType === 'regular') {
                        if ($isMain && $result['main_image'] === '') {
                            $result['main_image'] = $url;
                        }
                        $result['urls'][] = $url;
                    }
                    $order++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Ensure main image is first in the URL list used by frontend.
        if ($result['main_image'] !== '') {
            $result['urls'] = array_values(array_unique(array_merge(
                [$result['main_image']],
                array_filter($result['urls'], function ($u) use ($result) {
                    return $u !== $result['main_image'];
                })
            )));
        } else {
            $result['urls'] = array_values(array_unique($result['urls']));
            $result['main_image'] = $result['urls'][0] ?? '';
        }

        return $result;
    }
}

if (!function_exists('grnconnect_import_hotel_images')) {
    /**
     * Import images for one hotel. Skips when local rows already exist.
     *
     * @return array{saved:int, urls:string[], main_image:string, skipped?:bool, empty?:bool}
     */
    function grnconnect_import_hotel_images($client, $dbOrPdo, $hotelCode, array $opts = [])
    {
        $source = $opts['source'] ?? 'import';
        $force  = !empty($opts['force']);

        if (!$force && grnconnect_hotel_has_images($dbOrPdo, $hotelCode)) {
            $rows = grnconnect_load_hotel_images($dbOrPdo, $hotelCode);
            $urls = array_column($rows, 'url');
            $main = '';
            foreach ($rows as $r) {
                if (!empty($r['main_image'])) {
                    $main = $r['url'];
                    break;
                }
            }
            if ($main === '') {
                $main = $urls[0] ?? '';
            }
            // grnconnect_image_log('cache_hit', $hotelCode, ['source' => $source, 'count' => count($urls)]);
            return [
                'saved'      => 0,
                'urls'       => $urls,
                'main_image' => $main,
                'skipped'    => true,
            ];
        }

        if ($force) {
            grnconnect_image_log('fallback_api', $hotelCode, ['source' => $source, 'force' => true]);
        } else {
            grnconnect_image_log('import', $hotelCode, ['source' => $source]);
        }

        $res = $client->hotelImages($hotelCode);
        // Soft 5137 (no images) is treated as success with empty data by the client.
        if (!$res['success'] && ($res['error_code'] ?? '') !== '5137') {
            // Still treat as empty collection — never throw for missing images.
            grnconnect_image_log('no_images_found', $hotelCode, [
                'source' => $source,
                'error'  => $res['error'] ?? 'unknown',
                'code'   => $res['error_code'] ?? '',
            ]);
            return ['saved' => 0, 'urls' => [], 'main_image' => '', 'empty' => true];
        }

        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $groups = $data['images'] ?? [];
        $hasAny = false;
        if (is_array($groups)) {
            foreach (['regular', 'small', 'large'] as $k) {
                if (!empty($groups[$k]) && is_array($groups[$k])) {
                    $hasAny = true;
                    break;
                }
            }
        }
        if (!$hasAny) {
            grnconnect_image_log('no_images_found', $hotelCode, ['source' => $source]);
            return ['saved' => 0, 'urls' => [], 'main_image' => '', 'empty' => true];
        }

        $saved = grnconnect_save_hotel_images($dbOrPdo, $hotelCode, $data);
        grnconnect_image_log('import_saved', $hotelCode, [
            'source' => $source,
            'saved'  => $saved['saved'],
            'urls'   => count($saved['urls']),
        ]);
        return $saved;
    }
}

if (!function_exists('grnconnect_resolve_hotel_images')) {
    /**
     * Local-first image resolver for details/rooms.
     * Returns ['urls' => string[], 'main_image' => string, 'rows' => array]
     */
    function grnconnect_resolve_hotel_images($client, $dbOrPdo, $hotelCode)
    {
        $rows = grnconnect_load_hotel_images($dbOrPdo, $hotelCode);
        if (!empty($rows)) {
            $urls = [];
            $main = '';
            foreach ($rows as $r) {
                if (empty($r['url'])) {
                    continue;
                }
                $urls[] = $r['url'];
                if (!empty($r['main_image']) && $main === '') {
                    $main = $r['url'];
                }
            }
            $urls = array_values(array_unique($urls));
            if ($main === '') {
                $main = $urls[0] ?? '';
            } else {
                $urls = array_values(array_unique(array_merge([$main], array_filter($urls, function ($u) use ($main) {
                    return $u !== $main;
                }))));
            }
            // grnconnect_image_log('cache_hit', $hotelCode, ['source' => 'details', 'count' => count($urls)]);
            return ['urls' => $urls, 'main_image' => $main, 'rows' => $rows];
        }

        // No local images → fetch from GRNConnect, persist, return.
        grnconnect_image_log('fallback_api', $hotelCode, ['source' => 'details']);
        $imported = grnconnect_import_hotel_images($client, $dbOrPdo, $hotelCode, [
            'source' => 'details_fallback',
            'force'  => true,
        ]);

        // Also refresh the compact hotel.images JSON for search listings.
        $pdo = grnconnect_pdo($dbOrPdo);
        if ($pdo && !empty($imported['urls'])) {
            try {
                $upd = $pdo->prepare('UPDATE grnconnect_hotels SET images = :images WHERE hotel_code = :code');
                $upd->execute([
                    ':images' => json_encode(array_values($imported['urls'])),
                    ':code'   => $hotelCode,
                ]);
            } catch (\Throwable $e) { /* best-effort */ }
        }

        return [
            'urls'       => $imported['urls'] ?? [],
            'main_image' => $imported['main_image'] ?? '',
            'rows'       => grnconnect_load_hotel_images($dbOrPdo, $hotelCode),
        ];
    }
}

if (!function_exists('grnconnect_rate_price_total')) {
    /** Total price for a rate (already for all rooms/nights in this rate). */
    function grnconnect_rate_price_total($rate)
    {
        if (isset($rate['price']) && is_numeric($rate['price'])) {
            return (float)$rate['price'];
        }
        // Fallback: price_details.*.Total
        if (!empty($rate['price_details']) && is_array($rate['price_details'])) {
            foreach ($rate['price_details'] as $group) {
                if (is_array($group)) {
                    foreach ($group as $charge) {
                        if (($charge['name'] ?? '') === 'Total' && isset($charge['amount'])) {
                            return (float)$charge['amount'];
                        }
                    }
                }
            }
        }
        return 0.0;
    }
}

if (!function_exists('grnconnect_meal_plan')) {
    function grnconnect_meal_plan($rate)
    {
        if (!empty($rate['boarding_details']) && is_array($rate['boarding_details'])) {
            return implode(', ', $rate['boarding_details']);
        }
        if (!empty($rate['rate_comments']['mealplan'])) {
            return $rate['rate_comments']['mealplan'];
        }
        return !empty($rate['includes_boarding']) ? 'Breakfast Included' : 'Room Only';
    }
}

if (!function_exists('grnconnect_format_policy_datetime')) {
    /** Display datetime as "31 Jul 2026, 07:29 PM IST". */
    function grnconnect_format_policy_datetime($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if ($ts === false) return $value;
        return date('d M Y, h:i A', $ts) . ' IST';
    }
}

if (!function_exists('grnconnect_format_charge_amount')) {
    /** Human charge label, e.g. "Cost of 1 night's stay". */
    function grnconnect_format_charge_amount($fee)
    {
        if ($fee === null || $fee === '') return '';
        if (is_numeric($fee)) return (string)$fee;
        if (!is_array($fee)) return (string)$fee;

        if (isset($fee['nights']) && $fee['nights'] !== '' && $fee['nights'] !== null) {
            $n = (int)$fee['nights'];
            if ($n <= 0) return '';
            return $n === 1
                ? "Cost of 1 night's stay"
                : "Cost of {$n} nights' stay";
        }
        if (isset($fee['percent']) && $fee['percent'] !== '' && $fee['percent'] !== null) {
            return $fee['percent'] . '% of total stay';
        }
        if (isset($fee['flat_fee']) && $fee['flat_fee'] !== '' && $fee['flat_fee'] !== null) {
            $cur = trim((string)($fee['currency'] ?? ''));
            return trim(($cur !== '' ? "{$cur} " : '') . $fee['flat_fee']);
        }
        if (isset($fee['amount']) && $fee['amount'] !== '' && $fee['amount'] !== null) {
            $cur = trim((string)($fee['currency'] ?? ''));
            return trim(($cur !== '' ? "{$cur} " : '') . $fee['amount']);
        }
        return '';
    }
}

if (!function_exists('grnconnect_format_no_show_fee')) {
    function grnconnect_format_no_show_fee($fee)
    {
        return grnconnect_format_charge_amount($fee);
    }
}

if (!function_exists('grnconnect_cancellation_detail_line')) {
    function grnconnect_cancellation_detail_line(array $detail)
    {
        $from = !empty($detail['from'])
            ? grnconnect_format_policy_datetime($detail['from'])
            : '';
        $prefix = $from !== '' ? "From {$from}: " : '';
        if (isset($detail['allowed_cancellation']) && $detail['allowed_cancellation'] === false) {
            return $prefix . 'Non-cancellable';
        }
        $charge = grnconnect_format_charge_amount($detail);
        if ($charge !== '') {
            return $prefix . $charge;
        }
        return $prefix !== '' ? rtrim($prefix, ': ') : '';
    }
}

if (!function_exists('grnconnect_cancellation_text')) {
    /** Human-readable cancellation policy from a rate's cancellation_policy. */
    function grnconnect_cancellation_text($rate)
    {
        $refundability = grnconnect_refundability($rate);
        $tag           = $refundability['tag'];

        if ($tag === 'non_cancellable') {
            return 'Non-Cancellable';
        }
        if ($tag === 'non_refundable') {
            return 'Non-refundable';
        }

        $cp     = $rate['cancellation_policy'] ?? [];
        $lines  = [];

        if ($tag === 'refundable' && !empty($cp['cancel_by_date'])) {
            $date = grnconnect_format_policy_datetime($cp['cancel_by_date']);
            if (strtotime($cp['cancel_by_date']) > time()) {
                $lines[] = 'Free cancellation until ' . $date;
            } else {
                $lines[] = 'Cancel by ' . $date;
            }
        }

        if (!empty($cp['details']) && is_array($cp['details'])) {
            foreach ($cp['details'] as $detail) {
                if (!is_array($detail)) continue;
                $line = grnconnect_cancellation_detail_line($detail);
                if ($line !== '') $lines[] = $line;
            }
        }

        $noShow = grnconnect_format_no_show_fee($cp['no_show_fee'] ?? null);
        if ($noShow !== '') {
            $lines[] = 'No-show charge: ' . $noShow;
        }

        if (!empty($lines)) {
            return implode("\n", $lines);
        }

        if (!empty($cp['policy_text'])) {
            return (string)$cp['policy_text'];
        }

        return $tag === 'partially_refundable'
            ? 'Partially refundable. Cancellation charges apply.'
            : 'Fully refundable. See rate details for cancellation terms.';
    }
}

if (!function_exists('grnconnect_is_free_cancellation')) {
    function grnconnect_is_free_cancellation($rate)
    {
        $refundability = grnconnect_refundability($rate);
        if ($refundability['tag'] !== 'refundable') return false;

        $cp = $rate['cancellation_policy'] ?? [];
        return !empty($cp['cancel_by_date']) && strtotime($cp['cancel_by_date']) > time();
    }
}

if (!function_exists('grnconnect_is_valid_search_id')) {
    /** True when value looks like a GRN search_id (not a room config like 2-0). */
    function grnconnect_is_valid_search_id($value)
    {
        $value = trim((string)$value);
        if ($value === '') return false;
        if (in_array(strtolower($value), ['unknown', '_', 'null'], true)) return false;
        if (preg_match('/^\d+-\d+/', $value)) return false;
        return (bool)preg_match('/^[a-z0-9_-]{8,}$/i', $value);
    }
}

if (!function_exists('grnconnect_resolve_search_id')) {
    /**
     * Resolve GRN search_id from the rooms request body or the stay detail URL.
     * The listing page appends sid as the last URL segment after room configs.
     */
    function grnconnect_resolve_search_id(array $input = [])
    {
        foreach (['search_id', 'sid', 'extra'] as $key) {
            if (!empty($input[$key]) && grnconnect_is_valid_search_id($input[$key])) {
                return trim((string)$input[$key]);
            }
        }

        $stay = $_SESSION['stay_detail'] ?? null;
        if (is_array($stay) && !empty($stay['search_id']) && grnconnect_is_valid_search_id($stay['search_id'])) {
            return trim((string)$stay['search_id']);
        }

        if (!is_array($stay)) return '';

        $urlParts = $stay['url_parts'] ?? [];
        if (count($urlParts) < 9) return '';

        $totalRooms = max(1, (int)($stay['total_rooms'] ?? ($input['rooms'] ?? 1)));
        $sidIndex   = 8 + $totalRooms;
        if (!isset($urlParts[$sidIndex])) return '';

        $sid = trim((string)$urlParts[$sidIndex]);
        return grnconnect_is_valid_search_id($sid) ? $sid : '';
    }
}

if (!function_exists('grnconnect_rooms_from_request')) {
    /** Build GRN occupancy from the rooms API payload or stay session. */
    function grnconnect_rooms_from_request(array $input = [])
    {
        if (!empty($input['rooms_data']) && is_array($input['rooms_data'])) {
            return grnconnect_rooms_from_input($input['rooms_data']);
        }

        $stay = $_SESSION['stay_detail'] ?? null;
        if (is_array($stay) && !empty($stay['rooms_data']) && is_array($stay['rooms_data'])) {
            $roomsData = $stay['rooms_data'];
            $totalRooms = max(1, (int)($stay['total_rooms'] ?? count($roomsData)));
            if (count($roomsData) > $totalRooms) {
                $roomsData = array_slice($roomsData, 0, $totalRooms);
            }
            return grnconnect_rooms_from_input($roomsData);
        }

        $roomsInput = $input['rooms'] ?? null;
        if (is_array($roomsInput) && isset($roomsInput[0]) && is_array($roomsInput[0])) {
            return grnconnect_rooms_from_input($roomsInput);
        }

        return grnconnect_rooms_from_input($roomsInput ?? [['adults' => 2, 'children' => 0]]);
    }
}

if (!function_exists('grnconnect_parse_refetch_hotel')) {
    /** Normalize refetch / availability response into hotel + rates + search_id. */
    function grnconnect_parse_refetch_hotel(array $res, $fallbackSearchId = '')
    {
        $data     = is_array($res['data'] ?? null) ? $res['data'] : [];
        $searchId = (string)($data['search_id'] ?? $fallbackSearchId);
        $hotel    = $data['hotel'] ?? null;

        if (!is_array($hotel) && !empty($data['hotels'][0]) && is_array($data['hotels'][0])) {
            $hotel = $data['hotels'][0];
        }

        if (!is_array($hotel)) {
            return ['search_id' => $searchId, 'hotel' => null, 'rates' => [], 'city_code' => ''];
        }

        return [
            'search_id' => $searchId,
            'hotel'     => $hotel,
            'rates'     => is_array($hotel['rates'] ?? null) ? $hotel['rates'] : [],
            'city_code' => (string)($hotel['city_code'] ?? ''),
        ];
    }
}

if (!function_exists('grnconnect_rate_display_name')) {
    /** Display label for a rate (single room or bundled multi-room package). */
    function grnconnect_rate_display_name(array $rate)
    {
        $roomsInfo = $rate['rooms'] ?? [];
        if (!is_array($roomsInfo) || empty($roomsInfo)) {
            return 'Room';
        }

        $names = [];
        foreach ($roomsInfo as $roomInfo) {
            if (!is_array($roomInfo)) continue;
            $name = trim((string)($roomInfo['description'] ?? ($roomInfo['room_type'] ?? '')));
            if ($name === '') $name = 'Room';
            $names[] = $name;
        }

        if (empty($names)) return 'Room';
        return count($names) === 1 ? $names[0] : implode(' + ', $names);
    }
}

if (!function_exists('grnconnect_rate_occupancy')) {
    /** Sum adults/children across all physical rooms in a bundled rate. */
    function grnconnect_rate_occupancy(array $rate)
    {
        $adults   = 0;
        $children = 0;
        $rooms    = is_array($rate['rooms'] ?? null) ? $rate['rooms'] : [];

        foreach ($rooms as $roomInfo) {
            if (!is_array($roomInfo)) continue;
            $adults += (int)($roomInfo['no_of_adults']
                ?? ($roomInfo['max_room_occupancy']['max_adults'] ?? 0));
            $children += (int)($roomInfo['no_of_children']
                ?? ($roomInfo['max_room_occupancy']['max_children'] ?? 0));
        }

        if ($adults < 1 && !empty($rooms[0]) && is_array($rooms[0])) {
            $adults = (int)($rooms[0]['no_of_adults']
                ?? ($rooms[0]['max_room_occupancy']['max_adults'] ?? 2));
        }

        return [
            'adults'   => max(1, $adults),
            'children' => max(0, $children),
        ];
    }
}

if (!function_exists('grnconnect_refundability')) {
    /**
     * Refundability classification:
     * supports_cancellation=false → non_cancellable
     * non_refundable=true          → non_refundable
     * under_cancellation=true      → partially_refundable
     * otherwise                    → refundable (fully refundable)
     */
    function grnconnect_refundability(array $rate)
    {
        if (empty($rate['supports_cancellation'])) {
            return [
                'tag'                  => 'non_cancellable',
                'label'                => 'Non-Cancellable',
                'refundable'           => 0,
                'partially_refundable' => 0,
                'non_cancellable'      => 1,
                'non_refundable'       => 0,
            ];
        }

        if (!empty($rate['non_refundable'])) {
            return [
                'tag'                  => 'non_refundable',
                'label'                => 'Non-refundable',
                'refundable'           => 0,
                'partially_refundable' => 0,
                'non_cancellable'      => 0,
                'non_refundable'       => 1,
            ];
        }

        $cp = $rate['cancellation_policy'] ?? [];
        if (!empty($cp['under_cancellation'])) {
            return [
                'tag'                  => 'partially_refundable',
                'label'                => 'Partially refundable',
                'refundable'           => 0,
                'partially_refundable' => 1,
                'non_cancellable'      => 0,
                'non_refundable'       => 0,
            ];
        }

        return [
            'tag'                  => 'refundable',
            'label'                => 'Fully refundable',
            'refundable'           => 1,
            'partially_refundable' => 0,
            'non_cancellable'      => 0,
            'non_refundable'       => 0,
        ];
    }
}

if (!function_exists('grnconnect_format_cancellation_details')) {
    function grnconnect_format_cancellation_details($details)
    {
        if (!is_array($details)) return [];
        $out = [];
        foreach ($details as $detail) {
            if (!is_array($detail)) continue;
            $from = (string)($detail['from'] ?? '');
            $row  = [
                'from'                 => $from,
                'from_display'         => grnconnect_format_policy_datetime($from),
                'allowed_cancellation' => $detail['allowed_cancellation'] ?? null,
                'nights'               => $detail['nights'] ?? null,
                'percent'              => $detail['percent'] ?? null,
                'flat_fee'             => $detail['flat_fee'] ?? null,
                'currency'             => $detail['currency'] ?? '',
                'charge_display'       => '',
            ];
            if (isset($detail['allowed_cancellation']) && $detail['allowed_cancellation'] === false) {
                $row['charge_display'] = 'Non-cancellable';
            } else {
                $row['charge_display'] = grnconnect_format_charge_amount($row);
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('grnconnect_format_cancellation_policy')) {
    function grnconnect_format_cancellation_policy(array $rate)
    {
        $refundability = grnconnect_refundability($rate);
        $tag           = $refundability['tag'];
        $cp            = $rate['cancellation_policy'] ?? [];
        $details       = grnconnect_format_cancellation_details($cp['details'] ?? []);
        $noShowFee     = $cp['no_show_fee'] ?? null;
        $noShowText    = grnconnect_format_no_show_fee($noShowFee);
        $cancelByDate  = (string)($cp['cancel_by_date'] ?? '');
        $cancelByDisplay = grnconnect_format_policy_datetime($cancelByDate);
        $freeCancel    = grnconnect_is_free_cancellation($rate);
        $noShowNote    = 'No show fee applies when the guest does not show up for check-in or attempts to cancel on check-in.';

        $base = [
            'text'                      => '',
            'refundability_tag'         => $tag,
            'policy_text'               => '',
            'under_cancellation'        => false,
            'cancel_by_date'            => '',
            'cancel_by_date_display'    => '',
            'free_cancellation'         => false,
            'details'                   => [],
            'no_show_fee'               => null,
            'no_show_fee_text'          => '',
            'no_show_note'              => $noShowNote,
            'amount_type'               => '',
        ];

        if ($tag === 'non_cancellable') {
            return array_merge($base, [
                'text'        => 'Non-Cancellable',
                'policy_text' => 'This rate does not support cancellation.',
            ]);
        }

        if ($tag === 'non_refundable') {
            return array_merge($base, [
                'text'        => 'Non-refundable',
                'policy_text' => 'This rate is non-refundable.',
            ]);
        }

        if ($tag === 'partially_refundable') {
            return array_merge($base, [
                'text'               => grnconnect_cancellation_text($rate),
                'policy_text'        => (string)($cp['policy_text'] ?? ''),
                'under_cancellation' => true,
                'details'            => $details,
                'no_show_fee'        => $noShowFee,
                'no_show_fee_text'   => $noShowText,
                'amount_type'        => (string)($cp['amount_type'] ?? ''),
            ]);
        }

        return array_merge($base, [
            'text'                   => grnconnect_cancellation_text($rate),
            'policy_text'            => (string)($cp['policy_text'] ?? ''),
            'cancel_by_date'         => $cancelByDate,
            'cancel_by_date_display' => $cancelByDisplay,
            'free_cancellation'      => $freeCancel,
            'details'                => $details,
            'no_show_fee'            => $noShowFee,
            'no_show_fee_text'       => $noShowText,
            'amount_type'            => (string)($cp['amount_type'] ?? ''),
        ]);
    }
}

if (!function_exists('grnconnect_format_price_details')) {
    function grnconnect_format_price_details(array $rate)
    {
        $lines  = [];
        $groups = [];
        $pd     = $rate['price_details'] ?? [];
        if (!is_array($pd)) {
            return ['lines' => [], 'groups' => []];
        }

        foreach ($pd as $groupKey => $charges) {
            if (!is_array($charges)) continue;
            $groupLines = [];
            foreach ($charges as $charge) {
                if (!is_array($charge)) continue;
                $line = [
                    'group'       => (string)$groupKey,
                    'name'        => (string)($charge['name'] ?? ''),
                    'amount'      => isset($charge['amount']) ? (float)$charge['amount'] : null,
                    'currency'    => (string)($charge['currency'] ?? ($rate['currency'] ?? '')),
                    'included'    => !empty($charge['included']),
                    'mandatory'   => !empty($charge['mandatory']),
                    'amount_type' => (string)($charge['amount_type'] ?? 'value'),
                    'comments'    => (string)($charge['comments'] ?? ''),
                ];
                $groupLines[] = $line;
                $lines[]      = $line;
            }
            if (!empty($groupLines)) {
                $groups[] = ['key' => (string)$groupKey, 'items' => $groupLines];
            }
        }

        return ['lines' => $lines, 'groups' => $groups];
    }
}

if (!function_exists('grnconnect_excluded_charges')) {
    /** Charges from price_details that are not included in the rate price. */
    function grnconnect_excluded_charges(array $rate)
    {
        $excluded = [];
        foreach (grnconnect_format_price_details($rate)['lines'] as $line) {
            if ($line['included'] || $line['name'] === 'Total' || $line['amount'] === null) {
                continue;
            }
            $excluded[] = [
                'name'          => $line['name'],
                'subType'       => $line['name'],
                'amount'        => $line['amount'],
                'clientAmount'  => $line['amount'],
                'clientCurrency'=> $line['currency'],
                'hotelCurrency' => $line['currency'],
                'mandatory'     => $line['mandatory'],
                'comments'      => $line['comments'],
            ];
        }
        return $excluded;
    }
}

if (!function_exists('grnconnect_format_rate_comments')) {
    /** Dynamic key/value pairs from rate_comments object. */
    function grnconnect_format_rate_comments(array $rate)
    {
        $rc = $rate['rate_comments'] ?? [];
        if (!is_array($rc)) return [];

        $out = [];
        foreach ($rc as $key => $value) {
            if ($value === null || $value === '') continue;
            if (is_array($value)) {
                $value = implode("\n", array_filter(array_map('strval', $value)));
            }
            $value = trim((string)$value);
            if ($value === '') continue;
            $out[] = [
                'key'   => (string)$key,
                'label' => ucwords(str_replace('_', ' ', (string)$key)),
                'value' => $value,
            ];
        }
        return $out;
    }
}

if (!function_exists('grnconnect_rate_comments_text')) {
    function grnconnect_rate_comments_text(array $rate)
    {
        $parts = [];
        foreach (grnconnect_format_rate_comments($rate) as $item) {
            $parts[] = $item['label'] . ': ' . $item['value'];
        }
        return implode("\n\n", $parts);
    }
}

if (!function_exists('grnconnect_format_other_inclusions')) {
    function grnconnect_format_other_inclusions(array $rate)
    {
        $inc = $rate['other_inclusions'] ?? [];
        if (!is_array($inc)) return [];

        return array_values(array_filter(array_map(function ($item) {
            if (is_string($item)) return trim($item);
            if (is_array($item)) {
                foreach (['text', 'name', 'description', 'value'] as $k) {
                    if (!empty($item[$k])) return trim((string)$item[$k]);
                }
                return trim(json_encode($item));
            }
            return '';
        }, $inc)));
    }
}

if (!function_exists('grnconnect_format_safe2stay')) {
    function grnconnect_format_safe2stay($source)
    {
        if (!is_array($source)) return null;
        $s2s = $source['safe2stay'] ?? null;
        if (!is_array($s2s)) return null;

        $items = [];
        foreach ($s2s as $key => $value) {
            if ($value === null || $value === '') continue;
            $items[] = [
                'key'   => (string)$key,
                'label' => ucwords(str_replace('_', ' ', (string)$key)),
                'value' => is_array($value) ? json_encode($value) : (string)$value,
            ];
        }

        return empty($items) ? null : $items;
    }
}

if (!function_exists('grnconnect_format_rate_rooms')) {
    /** Per-room details for bundled / multi-room rates. */
    function grnconnect_format_rate_rooms(array $rate)
    {
        $rooms = [];
        foreach ($rate['rooms'] ?? [] as $idx => $roomInfo) {
            if (!is_array($roomInfo)) continue;

            $childrenAges = $roomInfo['children_ages'] ?? [];
            if (!is_array($childrenAges)) $childrenAges = [];

            $rooms[] = [
                'index'             => $idx + 1,
                'room_type'         => (string)($roomInfo['room_type'] ?? ''),
                'description'       => (string)($roomInfo['description'] ?? ''),
                'room_reference'    => (string)($roomInfo['room_reference'] ?? ''),
                'no_of_adults'      => (int)($roomInfo['no_of_adults'] ?? 0),
                'no_of_children'    => (int)($roomInfo['no_of_children'] ?? 0),
                'children_ages'     => array_values(array_map('intval', $childrenAges)),
                'bed_types'         => is_array($roomInfo['bed_types'] ?? null) ? $roomInfo['bed_types'] : [],
                'max_room_occupancy'=> is_array($roomInfo['max_room_occupancy'] ?? null) ? $roomInfo['max_room_occupancy'] : [],
                'pax_label'         => grnconnect_room_pax_label($roomInfo),
            ];
        }
        return $rooms;
    }
}

if (!function_exists('grnconnect_room_pax_label')) {
    function grnconnect_room_pax_label(array $roomInfo)
    {
        $adults   = (int)($roomInfo['no_of_adults'] ?? ($roomInfo['max_room_occupancy']['max_adults'] ?? 0));
        $children = (int)($roomInfo['no_of_children'] ?? ($roomInfo['max_room_occupancy']['max_children'] ?? 0));
        $ages     = $roomInfo['children_ages'] ?? [];
        if (!is_array($ages)) $ages = [];

        $parts = [];
        if ($adults > 0) $parts[] = $adults . ' Adult' . ($adults > 1 ? 's' : '');
        if ($children > 0) {
            $childLabel = $children . ' Child' . ($children > 1 ? 'ren' : '');
            if (!empty($ages)) {
                $childLabel .= ' (ages: ' . implode(', ', array_map('intval', $ages)) . ')';
            }
            $parts[] = $childLabel;
        }
        return empty($parts) ? 'Guests' : implode(', ', $parts);
    }
}

if (!function_exists('grnconnect_rate_room_signature')) {
    /**
     * Stable ordered signature for every component in a rate package.
     * Used to prevent a refresh from replacing a selected mixed-room package
     * with another rate that happens to share its group_code/room_code.
     */
    function grnconnect_rate_room_signature(array $rate)
    {
        $parts = [];
        foreach ($rate['rooms'] ?? [] as $room) {
            if (!is_array($room)) continue;
            $name = strtolower(trim((string)($room['room_type'] ?? $room['description'] ?? 'room')));
            $name = preg_replace('/\s+/', ' ', $name);
            $parts[] = implode(':', [
                $name,
                (int)($room['no_of_adults'] ?? 0),
                (int)($room['no_of_children'] ?? 0),
                (int)($room['no_of_rooms'] ?? 1),
            ]);
        }
        return implode('|', $parts);
    }
}

if (!function_exists('grnconnect_format_promotions')) {
    /**
     * Normalize GRN promotion strings (for example "Save:14%").
     * GRN's returned rate price is already promotional, so this metadata is
     * display-only and must never be subtracted from the payable total again.
     */
    function grnconnect_format_promotions(array $rate)
    {
        $raw = $rate['promotions_details'] ?? [];
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [$raw];
        }

        $details = [];
        $discountPercentage = 0.0;
        foreach ($raw as $item) {
            if (is_array($item)) {
                $item = $item['description']
                    ?? $item['name']
                    ?? $item['text']
                    ?? $item['title']
                    ?? $item['value']
                    ?? '';
            }
            if (!is_scalar($item)) continue;

            $text = trim((string)$item);
            if ($text === '') continue;
            $details[] = $text;

            if (preg_match('/(?:save|discount|off)\s*:?\s*(\d+(?:\.\d+)?)\s*%/i', $text, $matches)
                || preg_match('/(\d+(?:\.\d+)?)\s*%/', $text, $matches)) {
                $discountPercentage = max(
                    $discountPercentage,
                    min(99.99, max(0, (float)$matches[1]))
                );
            }
        }

        $details = array_values(array_unique($details));
        $percentageLabel = $discountPercentage > 0
            ? 'Save ' . rtrim(rtrim(number_format($discountPercentage, 2, '.', ''), '0'), '.') . '%'
            : '';

        return [
            'details'             => $details,
            'text'                => implode(' · ', $details),
            'discount_percentage' => $discountPercentage,
            'label'               => $percentageLabel !== '' ? $percentageLabel : ($details[0] ?? ''),
            'has_promotion'       => !empty($details),
        ];
    }
}

if (!function_exists('grnconnect_build_room_option')) {
    /** Normalize a GRN rate into the frontend rooms option contract. */
    function grnconnect_build_room_option(array $rate, array $ctx)
    {
        $module       = $ctx['module'];
        $db           = $ctx['db'];
        $currency     = $ctx['currency'];
        $nights       = max(1, (int)($ctx['nights'] ?? 1));
        $searchId     = (string)($ctx['search_id'] ?? '');
        $hotelId      = (string)($ctx['hotel_id'] ?? '');
        $cityCode     = (string)($ctx['city_code'] ?? '');
        $checkin      = (string)($ctx['checkin'] ?? '');
        $checkout     = (string)($ctx['checkout'] ?? '');
        $roomName     = (string)($ctx['room_name'] ?? grnconnect_rate_display_name($rate));
        $hotelSafe2stay = $ctx['hotel_safe2stay'] ?? null;

        $rateKey      = (string)($rate['rate_key'] ?? '');
        $basePrice    = grnconnect_rate_price_total($rate);
        $rateCurrency = $rate['currency'] ?? $currency;
        $finalPrice   = $basePrice;

        try {
            $mk = MARKUP($basePrice, $module, $db, $rateCurrency, $currency);
            if (isset($mk['price']) && $mk['price'] > 0) $finalPrice = $mk['price'];
        } catch (\Throwable $e) { /* keep base */ }

        $occupancy      = grnconnect_rate_occupancy($rate);
        $refundability  = grnconnect_refundability($rate);
        $cancellation   = grnconnect_format_cancellation_policy($rate);
        $priceDetails   = grnconnect_format_price_details($rate);
        $commentsList   = grnconnect_format_rate_comments($rate);
        $commentsText   = grnconnect_rate_comments_text($rate);
        $otherInclusions = grnconnect_format_other_inclusions($rate);
        $rateRooms      = grnconnect_format_rate_rooms($rate);
        $safe2stay      = grnconnect_format_safe2stay($rate) ?? $hotelSafe2stay;
        $promotion      = grnconnect_format_promotions($rate);
        $roomInfo       = $rate['rooms'][0] ?? [];
        $freeCancel     = grnconnect_is_free_cancellation($rate);

        $roomsInRate = max(1, count($rateRooms), (int)($rate['no_of_rooms'] ?? 0));
        $packageLabel = function_exists('grnconnect_rate_package_label')
            ? grnconnect_rate_package_label($rate)
            : $roomName;
        $roomReferences = [];
        foreach ($rateRooms as $rateRoom) {
            $ref = trim((string)($rateRoom['room_reference'] ?? ''));
            if ($ref !== '') {
                $roomReferences[] = $ref;
            }
        }
        $token = grnconnect_pack_rate([
            'search_id'      => $searchId,
            'hotel_code'     => $hotelId,
            'city_code'      => $cityCode,
            'group_code'     => $rate['group_code'] ?? '',
            'rate_key'       => $rateKey,
            'room_code'      => $rate['room_code'] ?? '',
            'rate_type'      => $rate['rate_type'] ?? 'bookable',
            'room_reference' => $roomInfo['room_reference'] ?? '',
            'room_references'=> $roomReferences,
            'room_type'      => $roomName,
            'checkin'        => $checkin,
            'checkout'       => $checkout,
            'price'          => round($finalPrice, 2),
            'net_price'      => round($basePrice, 2),
            'currency'       => $currency,
            'non_refundable' => !empty($rate['non_refundable']),
            'meal'           => grnconnect_meal_plan($rate),
            'room_signature' => grnconnect_rate_room_signature($rate),
            'rooms_in_rate'  => $roomsInRate,
            'no_of_rooms'    => max(1, (int)($rate['no_of_rooms'] ?? $roomsInRate)),
            'bundled'        => $roomsInRate > 1,
        ]);

        $hasBreakfast = false;
        foreach ($rate['boarding_details'] ?? [] as $detail) {
            if (stripos($detail, 'breakfast') !== false) {
                $hasBreakfast = true;
                break;
            }
        }

        return [
            'option_id'              => $rateKey !== '' ? $rateKey : uniqid('grn_'),
            'package_id'             => $rateKey,
            'package_label'          => $packageLabel,
            'is_package'             => $roomsInRate > 1,
            'rate_key'               => $token,
            'board_name'             => grnconnect_meal_plan($rate),
            'board_code'             => !empty($rate['includes_boarding']) ? 'BB' : 'RO',
            'meal_plan'              => grnconnect_meal_plan($rate),
            'boarding_details'       => is_array($rate['boarding_details'] ?? null) ? $rate['boarding_details'] : [],
            'cancellation_free'      => $freeCancel ? 1 : 0,
            'refundable'             => (int)$refundability['refundable'],
            'partially_refundable'   => (int)$refundability['partially_refundable'],
            'non_cancellable'        => (int)($refundability['non_cancellable'] ?? 0),
            'non_refundable'         => (int)($refundability['non_refundable'] ?? (!empty($rate['non_refundable']) ? 1 : 0)),
            'refundability_tag'      => $refundability['tag'],
            'refundability_label'    => $refundability['label'],
            'cancellation_policy'    => $cancellation['text'],
            'cancellation_text'      => $cancellation['text'],
            'cancellation_details'   => $cancellation,
            'breakfast_included'     => $hasBreakfast,
            'price_per_night'        => round($finalPrice / $nights, 2),
            'price_per_room'         => round($finalPrice / $roomsInRate, 2),
            'price_per_room_per_night' => round($finalPrice / $roomsInRate / $nights, 2),
            'total_price'            => round($finalPrice, 2),
            'original_price'         => round($basePrice, 2),
            'base_price'             => round($basePrice, 2),
            'currency'               => $currency,
            'taxes'                  => grnconnect_rate_taxes($rate),
            'adults'                 => $occupancy['adults'],
            'children'               => $occupancy['children'],
            'rate_comments'          => $commentsText,
            'rate_comments_list'     => $commentsList,
            'has_rate_comments'      => !empty($commentsList),
            'price_details'          => $priceDetails,
            'price_details_groups'   => $priceDetails['groups'],
            'excluded_taxes'         => grnconnect_excluded_charges($rate),
            'other_inclusions'       => $otherInclusions,
            'safe2stay'              => $safe2stay,
            'rate_rooms'             => $rateRooms,
            'package_rooms'          => $rateRooms,
            'rooms_in_rate'          => $roomsInRate,
            'mixable'                => !empty($ctx['mixable']) && $roomsInRate <= 1,
            'occupancy_label'        => !empty($rateRooms[0]['pax_label'])
                ? (string)$rateRooms[0]['pax_label']
                : (($occupancy['adults'] ?? 1) . ' Adult' . (($occupancy['adults'] ?? 1) > 1 ? 's' : '')),
            'for_adults'             => (int)($rateRooms[0]['no_of_adults'] ?? $occupancy['adults'] ?? 1),
            'for_children'           => (int)($rateRooms[0]['no_of_children'] ?? $occupancy['children'] ?? 0),
            'max_room_occupancy'     => is_array($roomInfo['max_room_occupancy'] ?? null)
                ? $roomInfo['max_room_occupancy']
                : [
                    'max_adults'   => (int)($rateRooms[0]['no_of_adults'] ?? $occupancy['adults'] ?? 2),
                    'max_children' => (int)($rateRooms[0]['no_of_children'] ?? $occupancy['children'] ?? 0),
                ],
            'available_quantity'     => 1,
            'supports_cancellation'  => !empty($rate['supports_cancellation']),
            'supports_amendment'     => !empty($rate['supports_amendment']),
            'promotions_details'     => $promotion['details'],
            'promotion_text'         => $promotion['text'],
            'promotion_label'        => $promotion['label'],
            'has_promotion'          => $promotion['has_promotion'],
            'discount_percentage'    => $promotion['discount_percentage'],
            'credit_deduction'       => (string)($rate['credit_deduction'] ?? ''),
            'payment_type'           => is_array($rate['payment_type'] ?? null) ? $rate['payment_type'] : [],
            'pan_required'           => !empty($rate['pan_required']),
            'rate_type'              => (string)($rate['rate_type'] ?? 'bookable'),
            'group_code'             => (string)($rate['group_code'] ?? ''),
        ];
    }
}

if (!function_exists('grnconnect_rate_room_references')) {
    /** Room references for this rate_key, in rooms[] order (duplicates kept). */
    function grnconnect_rate_room_references(array $rate)
    {
        $refs = [];
        foreach ($rate['rooms'] ?? [] as $room) {
            if (!is_array($room)) {
                continue;
            }
            $ref = trim((string)($room['room_reference'] ?? ''));
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        return $refs;
    }
}

if (!function_exists('grnconnect_slim_rate_snapshot')) {
    /**
     * Keep only the fields issue.php needs. Full GRN rate + raw snapshots
     * can overflow bookings.booking_data and drop room_references.
     */
    function grnconnect_slim_rate_snapshot(array $rate)
    {
        $rooms = [];
        foreach ($rate['rooms'] ?? [] as $room) {
            if (!is_array($room)) {
                continue;
            }
            $rooms[] = [
                'room_reference' => (string)($room['room_reference'] ?? ''),
                'room_type'      => (string)($room['room_type'] ?? ($room['description'] ?? '')),
                'no_of_adults'   => (int)($room['no_of_adults'] ?? 0),
                'no_of_children' => (int)($room['no_of_children'] ?? 0),
                'children_ages'  => is_array($room['children_ages'] ?? null) ? array_values($room['children_ages']) : [],
            ];
        }
        return [
            'rate_key'     => (string)($rate['rate_key'] ?? ''),
            'group_code'   => (string)($rate['group_code'] ?? ''),
            'room_code'    => (string)($rate['room_code'] ?? ''),
            'no_of_rooms'  => (int)($rate['no_of_rooms'] ?? count($rooms)),
            'price'        => isset($rate['price']) ? (float)$rate['price'] : 0.0,
            'currency'     => (string)($rate['currency'] ?? ''),
            'rooms'        => $rooms,
        ];
    }
}

if (!function_exists('grnconnect_parse_recheck_rate')) {
    /** Extract the rate object from a GRN recheck API response. */
    function grnconnect_parse_recheck_rate(array $res)
    {
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        if (!empty($data['hotel']['rates'][0]) && is_array($data['hotel']['rates'][0])) {
            return $data['hotel']['rates'][0];
        }
        if (!empty($data['hotel']['rate']) && is_array($data['hotel']['rate'])) {
            return $data['hotel']['rate'];
        }
        if (!empty($data['hotels'][0]['rates'][0]) && is_array($data['hotels'][0]['rates'][0])) {
            return $data['hotels'][0]['rates'][0];
        }
        if (!empty($data['hotels'][0]['min_rate']) && is_array($data['hotels'][0]['min_rate'])) {
            return $data['hotels'][0]['min_rate'];
        }
        if (!empty($data['hotel']['min_rate']) && is_array($data['hotel']['min_rate'])) {
            return $data['hotel']['min_rate'];
        }
        if (!empty($data['rate']) && is_array($data['rate'])) {
            return $data['rate'];
        }
        return null;
    }
}

if (!function_exists('grnconnect_resolve_fresh_bundled_rate')) {
    /**
     * Bundled flow: refresh search_id + rate via refetch (or concise + refetch).
     * Returns search_id, matched rate, hotel node, city_code.
     */
    function grnconnect_resolve_fresh_bundled_rate($client, array $token, array $grnRooms, $nationality, $currency)
    {
        $hotelCode = (string)($token['hotel_code'] ?? '');
        $checkin   = grnconnect_to_iso_date($token['checkin'] ?? '');
        $checkout  = grnconnect_to_iso_date($token['checkout'] ?? '');
        $cityCode  = (string)($token['city_code'] ?? '');
        $searchId  = (string)($token['search_id'] ?? '');
        $rate      = null;
        $hotel     = null;
        // Match how the rate was sold: multi-room packages need bundled=true refetch
        $roomsInToken = (int)($token['rooms_in_rate'] ?? ($token['no_of_rooms'] ?? 0));
        $useBundled = !empty($token['bundled']) || $roomsInToken > 1;

        $applyRefetch = function (array $res, $fallbackSid = '') use ($token, &$cityCode) {
            if (!$res['success']) {
                return [null, null, null, ''];
            }
            $parsed   = grnconnect_parse_refetch_hotel($res, $fallbackSid);
            $matched  = grnconnect_match_rate($parsed['rates'], $token, $token['group_code'] ?? '');
            $sid      = (string)($parsed['search_id'] ?? $fallbackSid);
            $hotelRow = $parsed['hotel'];
            $city     = (string)($parsed['city_code'] ?? $cityCode);
            if ($city !== '') {
                $cityCode = $city;
            }
            return [$sid, $matched, $hotelRow, $cityCode];
        };

        if ($searchId !== '' && grnconnect_is_valid_search_id($searchId)) {
            [$searchId, $rate, $hotel, $cityCode] = $applyRefetch(
                $client->refetch($searchId, $hotelCode, $useBundled),
                $searchId
            );
            if (!$rate) {
                [$searchId, $rate, $hotel, $cityCode] = $applyRefetch(
                    $client->refetch($searchId, $hotelCode, !$useBundled),
                    $searchId
                );
            }
        }

        if (!$rate) {
            $availRes = $client->availability([
                'hotel_codes'        => [$hotelCode],
                'checkin'            => $checkin,
                'checkout'           => $checkout,
                'client_nationality' => $nationality,
                'currency'           => $currency,
                'rates'              => 'concise',
                'rooms'              => $grnRooms,
            ], 45);

            if (!$availRes['success']) {
                throw new Exception('Unable to refresh availability: ' . ($availRes['error'] ?: 'no response'));
            }

            $freshSid = (string)($availRes['data']['search_id'] ?? '');
            if ($freshSid === '') {
                throw new Exception('GRNConnect did not return a search session.');
            }

            [$searchId, $rate, $hotel, $cityCode] = $applyRefetch(
                $client->refetch($freshSid, $hotelCode, $useBundled),
                $freshSid
            );
            if (!$rate) {
                [$searchId, $rate, $hotel, $cityCode] = $applyRefetch(
                    $client->refetch($freshSid, $hotelCode, !$useBundled),
                    $freshSid
                );
            }
        }

        if (!$rate || $searchId === '') {
            throw new Exception('Selected rate is no longer available. Please search again.');
        }

        return [
            'search_id' => $searchId,
            'rate'      => $rate,
            'hotel'     => $hotel,
            'city_code' => $cityCode,
        ];
    }
}

if (!function_exists('grnconnect_booking_rate_rooms')) {
    /**
     * Book one payload room per rooms[] entry from search/refetch/recheck.
     * Do not clone rooms — each live room has its own room_reference.
     */
    function grnconnect_booking_rate_rooms(array $rate)
    {
        $rooms = [];
        foreach ($rate['rooms'] ?? [] as $room) {
            if (is_array($room)) {
                $rooms[] = $room;
            }
        }
        return $rooms;
    }
}

if (!function_exists('grnconnect_recheck_dedupe_key')) {
    /** Stable cache key for recheck dedupe (packed token or raw rate_key). */
    function grnconnect_recheck_dedupe_key($rateTokenOrKey)
    {
        $value = trim((string)$rateTokenOrKey);
        if ($value === '') {
            return '';
        }
        if (function_exists('grnconnect_unpack_rate')) {
            $unpacked = grnconnect_unpack_rate($value);
            $inner = is_array($unpacked) ? trim((string)($unpacked['rate_key'] ?? '')) : '';
            if ($inner !== '') {
                return $inner;
            }
        }
        return $value;
    }
}

if (!function_exists('grnconnect_checkout_recheck_reusable')) {
    /**
     * GRN expects one recheck per selected rate_key before book.
     * Reuse checkout recheck at issue when still fresh (default 15 min).
     */
    function grnconnect_checkout_recheck_reusable(array $meta, $rateKey, $groupCode, $maxAgeSec = 900)
    {
        if (empty($meta['search_id']) || empty($meta['rate_key']) || empty($meta['group_code'])) {
            return false;
        }
        if (grnconnect_recheck_dedupe_key($meta['rate_key']) !== grnconnect_recheck_dedupe_key($rateKey)) {
            return false;
        }
        if (trim((string)$meta['group_code']) !== trim((string)$groupCode)) {
            return false;
        }
        $snapshotRooms = is_array($meta['rate_snapshot']['rooms'] ?? null) ? $meta['rate_snapshot']['rooms'] : [];
        $hasRooms = !empty($snapshotRooms) || !empty($meta['room_references']);
        if (!$hasRooms) {
            return false;
        }
        $at = strtotime((string)($meta['rechecked_at'] ?? ''));
        if ($at <= 0 || (time() - $at) > max(60, (int)$maxAgeSec)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('grnconnect_rechecked_from_checkout')) {
    function grnconnect_rechecked_from_checkout(array $meta)
    {
        $rate = is_array($meta['rate_snapshot'] ?? null) ? $meta['rate_snapshot'] : [];
        if (empty($rate['rooms']) && !empty($meta['room_references']) && is_array($meta['room_references'])) {
            $rate['rooms'] = [];
            foreach ($meta['room_references'] as $ref) {
                $ref = trim((string)$ref);
                if ($ref === '') {
                    continue;
                }
                $rate['rooms'][] = ['room_reference' => $ref];
            }
        }
        if (empty($rate['rate_key'])) {
            $rate['rate_key'] = (string)($meta['rate_key'] ?? '');
        }
        if (empty($rate['group_code'])) {
            $rate['group_code'] = (string)($meta['group_code'] ?? '');
        }
        if (empty($rate['room_code']) && !empty($meta['room_code'])) {
            $rate['room_code'] = (string)$meta['room_code'];
        }
        return [
            'search_id'  => (string)($meta['search_id'] ?? ''),
            'group_code' => (string)($meta['group_code'] ?? ''),
            'rate_key'   => (string)($meta['rate_key'] ?? ''),
            'rate'       => $rate,
            'raw'        => is_array($meta['raw_snapshot'] ?? null) ? $meta['raw_snapshot'] : [],
            'reused_checkout_recheck' => true,
        ];
    }
}

if (!function_exists('grnconnect_find_checkout_recheck')) {
    /** Lookup a saved checkout recheck meta row by supplier rate_key. */
    function grnconnect_find_checkout_recheck(array $checkoutRechecks, $rateKey, $groupCode = '')
    {
        if (empty($checkoutRechecks)) {
            return null;
        }
        $target = grnconnect_recheck_dedupe_key($rateKey);
        foreach ($checkoutRechecks as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if (grnconnect_recheck_dedupe_key($meta['rate_key'] ?? '') !== $target) {
                continue;
            }
            if ($groupCode !== '' && trim((string)($meta['group_code'] ?? '')) !== trim((string)$groupCode)) {
                continue;
            }
            if (grnconnect_checkout_recheck_reusable($meta, $rateKey, $meta['group_code'] ?? $groupCode)) {
                return $meta;
            }
        }
        return null;
    }
}

if (!function_exists('grnconnect_issue_recheck_bundled_rate')) {
    /**
     * Issue-time recheck for a bundled GRN rate_key.
     * Book only with identifiers from the same search session that issued them.
     * Never pair a new search_id with a previous session's rate_key / room_code.
     */
    function grnconnect_issue_recheck_bundled_rate($client, array $token, array $checkoutRecheck, array $grnRooms, $nationality, $currency)
    {
        $sid   = trim((string)($checkoutRecheck['search_id'] ?? ($token['search_id'] ?? '')));
        $key   = trim((string)($checkoutRecheck['rate_key'] ?? ($token['rate_key'] ?? '')));
        $group = trim((string)($checkoutRecheck['group_code'] ?? ($token['group_code'] ?? '')));
        $city  = trim((string)($checkoutRecheck['city_code'] ?? ($token['city_code'] ?? '')));

        if ($sid !== '' && $key !== '' && $group !== '') {
            $sidOk = !function_exists('grnconnect_is_valid_search_id') || grnconnect_is_valid_search_id($sid);
            if ($sidOk) {
                try {
                    $rc = grnconnect_mandatory_recheck($client, $sid, $key, $group);
                    $rc['city_code'] = $city;
                    $rc['same_session'] = true;
                    return $rc;
                } catch (\Throwable $e) {
                    // Checkout search expired — open a new search below.
                }
            }
        }

        if (
            !empty($checkoutRecheck)
            && function_exists('grnconnect_checkout_recheck_reusable')
            && grnconnect_checkout_recheck_reusable($checkoutRecheck, $key, $group)
        ) {
            $fromCheckout = grnconnect_rechecked_from_checkout($checkoutRecheck);
            $fromCheckout['city_code'] = $city;
            return $fromCheckout;
        }

        $freshToken = $token;
        unset($freshToken['search_id']);
        $fresh = grnconnect_resolve_fresh_bundled_rate($client, $freshToken, $grnRooms, $nationality, $currency);
        $rc = grnconnect_mandatory_recheck(
            $client,
            (string)($fresh['search_id'] ?? ''),
            (string)($fresh['rate']['rate_key'] ?? ''),
            (string)($fresh['rate']['group_code'] ?? '')
        );
        $rc['city_code'] = (string)($fresh['city_code'] ?? $city);
        $rc['same_session'] = false;
        return $rc;
    }
}

if (!function_exists('grnconnect_mandatory_recheck')) {
    /** Mandatory recheck before booking. Returns fresh rate identifiers. */
    function grnconnect_mandatory_recheck($client, $searchId, $rateKey, $groupCode)
    {
        if ($searchId === '' || $rateKey === '' || $groupCode === '') {
            throw new Exception('Missing search session or rate identifiers for recheck.');
        }

        $rc = $client->recheck($searchId, $rateKey, $groupCode);
        if (!$rc['success']) {
            throw new Exception('Rate recheck failed: ' . ($rc['error'] ?: 'no response'));
        }

        $rate = grnconnect_parse_recheck_rate($rc);
        if (!$rate) {
            throw new Exception('Rate recheck returned no rate data.');
        }

        return [
            'search_id'  => (string)($rc['data']['search_id'] ?? $searchId),
            'rate'       => $rate,
            'group_code' => (string)($rate['group_code'] ?? $groupCode),
            'rate_key'   => (string)($rate['rate_key'] ?? $rateKey),
            'raw'        => is_array($rc['data'] ?? null) ? $rc['data'] : [],
        ];
    }
}

if (!function_exists('grnconnect_build_recheck_payload')) {
    /** Build checkout/issue payload from a rechecked bundled rate. */
    function grnconnect_build_recheck_payload($client, $module, $db, array $token, array $grnRooms, $nationality, $currency, $nights = 1)
    {
        try {
            $fresh = grnconnect_resolve_fresh_bundled_rate($client, $token, $grnRooms, $nationality, $currency);
        } catch (\Throwable $e) {
            // Normalize availability failures for checkout UI
            throw new Exception(
                function_exists('grnconnect_unavailable_message')
                    ? grnconnect_unavailable_message()
                    : $e->getMessage()
            );
        }

        $searchId  = $fresh['search_id'];
        $prefetch  = $fresh['rate'];
        $hotel     = is_array($fresh['hotel']) ? $fresh['hotel'] : [];
        $hotelCode = (string)($token['hotel_code'] ?? '');
        $cityCode  = (string)($fresh['city_code'] ?? ($token['city_code'] ?? ''));

        try {
            $rechecked = grnconnect_mandatory_recheck(
                $client,
                $searchId,
                (string)($prefetch['rate_key'] ?? ''),
                (string)($prefetch['group_code'] ?? '')
            );
        } catch (\Throwable $e) {
            throw new Exception(
                function_exists('grnconnect_unavailable_message')
                    ? grnconnect_unavailable_message()
                    : $e->getMessage()
            );
        }

        $rate = $rechecked['rate'];
        $raw  = is_array($rechecked['raw'] ?? null) ? $rechecked['raw'] : [];

        // Availability guard (before any booking continuation)
        if (function_exists('grnconnect_assert_recheck_bookable')) {
            $allocNeeded = max(
                1,
                (int)($token['rooms_in_rate'] ?? 0),
                (int)($token['no_of_rooms'] ?? 0),
                is_array($rate['rooms'] ?? null) ? count($rate['rooms']) : 0
            );
            grnconnect_assert_recheck_bookable($rate, $raw, $token, $allocNeeded);
        }

        $ctx  = [
            'module'          => $module,
            'db'              => $db,
            'currency'        => $currency,
            'nights'          => $nights,
            'search_id'       => $rechecked['search_id'],
            'hotel_id'        => $hotelCode,
            'city_code'       => $cityCode,
            'checkin'         => grnconnect_to_iso_date($token['checkin'] ?? ''),
            'checkout'        => grnconnect_to_iso_date($token['checkout'] ?? ''),
            'hotel_safe2stay' => grnconnect_format_safe2stay($rate) ?? grnconnect_format_safe2stay($hotel),
        ];

        $option = grnconnect_build_room_option($rate, $ctx);
        $netBefore = (float)($token['net_price'] ?? 0);
        $netAfter  = (float)($option['original_price'] ?? 0);
        if ($netAfter <= 0 && function_exists('grnconnect_rate_price_total')) {
            $netAfter = (float)grnconnect_rate_price_total($rate);
        }

        // Display/compare using selling prices (what checkout shows); fall back to net
        $sellBefore = (float)($token['price'] ?? 0);
        $sellAfter  = (float)($option['total_price'] ?? 0);
        if ($sellBefore <= 0) {
            $sellBefore = $netBefore;
        }
        if ($sellAfter <= 0) {
            $sellAfter = $netAfter;
        }

        $supplierPriceChanged = null;
        if (array_key_exists('price_changed', $raw)) {
            $supplierPriceChanged = !empty($raw['price_changed']);
        }

        $priceComparison = function_exists('grnconnect_build_price_comparison')
            ? grnconnect_build_price_comparison($sellBefore, $sellAfter, $currency, $supplierPriceChanged)
            : [
                'changed'        => $sellBefore > 0 && abs($sellBefore - $sellAfter) > 0.01,
                'previous_price' => round($sellBefore, 2),
                'updated_price'  => round($sellAfter, 2),
                'currency'       => strtoupper((string)$currency),
                'message'        => null,
            ];

        // Also flag change when net moved even if selling rounded equal
        if (!$priceComparison['changed'] && $netBefore > 0 && abs($netBefore - $netAfter) > 0.01) {
            $priceComparison['changed'] = true;
            $priceComparison['previous_price'] = round($sellBefore > 0 ? $sellBefore : $netBefore, 2);
            $priceComparison['updated_price'] = round($sellAfter > 0 ? $sellAfter : $netAfter, 2);
            $priceComparison['message'] = function_exists('grnconnect_price_change_message')
                ? grnconnect_price_change_message()
                : $priceComparison['message'];
        }

        $priceChanged = !empty($priceComparison['changed']);

        return [
            'search_id'         => $rechecked['search_id'],
            'group_code'        => $rechecked['group_code'],
            'rate_key'          => $rechecked['rate_key'],
            'room_code'         => (string)($rate['room_code'] ?? ''),
            'rate'              => $rate,
            'option'            => $option,
            'rechecked_at'      => date('c'),
            'price_changed'     => $priceChanged,
            'previous_price'    => $priceComparison['previous_price'],
            'updated_price'     => $priceComparison['updated_price'],
            'price_currency'    => $priceComparison['currency'],
            'price_comparison'  => $priceComparison,
            'available'         => true,
            'grn_recheck'       => [
                'search_id'          => $rechecked['search_id'],
                'group_code'         => $rechecked['group_code'],
                'rate_key'           => $rechecked['rate_key'],
                'room_code'          => (string)($rate['room_code'] ?? ''),
                'hotel_code'         => $hotelCode,
                'city_code'          => $cityCode,
                'rechecked_at'       => date('c'),
                'previous_price'     => $priceComparison['previous_price'],
                'updated_price'      => $priceComparison['updated_price'],
                'accepted_net_price' => round($netAfter, 2),
                'currency'           => $priceComparison['currency'],
                'price_changed'      => $priceChanged,
                'room_references'    => function_exists('grnconnect_rate_room_references')
                    ? grnconnect_rate_room_references($rate)
                    : [],
                'rate_snapshot'      => function_exists('grnconnect_slim_rate_snapshot')
                    ? grnconnect_slim_rate_snapshot($rate)
                    : $rate,
                'raw_snapshot'       => [],
            ],
        ];
    }
}
