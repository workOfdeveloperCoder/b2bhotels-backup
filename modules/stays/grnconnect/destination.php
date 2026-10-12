<?php
// ============================================================================
// GRNCONNECT DESTINATION SUGGESTION + SEARCH META
// ============================================================================
// Kept in the GRNConnect module so shared stays routes stay supplier-agnostic.
// ENDPOINTS:
//   POST /stays/grnconnect/destination-suggestion
//   POST /stays/grnconnect/destination-meta
// ============================================================================

$router->post('/stays/grnconnect/destination-suggestion', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $query = isset($_POST['query']) ? trim($_POST['query']) : '';
    $query = preg_replace('/\s+/u', ' ', $query);
    if ($query === '' || mb_strlen($query) < 3) {
        echo json_encode([]);
        exit;
    }

    $results = [];

    try {
        $module = grnconnect_module($db);
        if (!$module || (int)($module['status'] ?? 0) !== 1 || (int)($module['active'] ?? 0) !== 1) {
            echo json_encode([]);
            exit;
        }

        $contentDb = grnconnect_content_db($module, [
            \PDO::ATTR_TIMEOUT => 8,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        if (!$contentDb) {
            echo json_encode([]);
            exit;
        }

        require_once dirname(__DIR__) . '/hotel_name_query.php';

        $pdo = $contentDb->pdo;
        stays_pdo_limit_runtime($pdo, 2000);
        $pat = stays_autocomplete_patterns($query);
        $prefix = $pat['prefix'];

        $hotels = [];
        $hotelSeen = [];
        try {
            foreach (stays_autocomplete_index_lookups($query, 'name') as $lookup) {
                if (count($hotels) >= 40) {
                    break;
                }
                $stmt = $pdo->prepare(
                    'SELECT hotel_code, name, address, city_code, country_code, latitude, longitude
                     FROM grnconnect_hotels WHERE ' . $lookup['sql'] . ' LIMIT 40'
                );
                $stmt->execute($lookup['params']);
                stays_autocomplete_merge_rows($hotels, $hotelSeen, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'hotel_code', 40);
            }
            if (count($hotels) < 40 && function_exists('stays_autocomplete_contains')) {
                $contains = stays_autocomplete_contains($query, 'name');
                $stmt = $pdo->prepare(
                    'SELECT hotel_code, name, address, city_code, country_code, latitude, longitude
                     FROM grnconnect_hotels WHERE ' . $contains['sql']
                    . ' ORDER BY ' . $contains['order_sql'] . ', name ASC LIMIT 40'
                );
                $stmt->execute(array_merge($contains['params'], $contains['order_params']));
                stays_autocomplete_merge_rows($hotels, $hotelSeen, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'hotel_code', 40);
            }
        } catch (\Throwable $e) {
            error_log('GRNConnect destination hotels: ' . $e->getMessage());
        }

        $grnCities = [];
        try {
            $grnCities = function_exists('grnconnect_autocomplete_cities')
                ? grnconnect_autocomplete_cities($pdo, $query, $prefix)
                : [];
        } catch (\Throwable $e) {
            error_log('GRNConnect city autocomplete: ' . $e->getMessage());
        }

        foreach ($grnCities as $city) {
            $label = $city['search_name'] ?: $city['name'];
            $country = trim((string)($city['country_name'] ?? $city['country_code'] ?? ''));
            $cityCode = trim((string)($city['city_code'] ?? ''));
            if ($cityCode === '') {
                continue;
            }
            $results[] = [
                'id' => $cityCode,
                'type' => 'city',
                'lc' => 'city',
                'name' => $label,
                'airportname' => $label,
                'cityname' => $label,
                'countryname' => $country,
                'countrycode' => $city['country_code'] ?? '',
                'destination_code' => $cityCode,
                'city_code' => $cityCode,
                'country_expand' => !empty($city['country_expand']),
                'supplier' => 'grnconnect',
                'is_hotel' => false,
            ];
        }

        foreach ($hotels as $hotel) {
            $results[] = [
                'id' => $hotel['hotel_code'],
                'type' => 'hotel',
                'lc' => 'hotel',
                'name' => $hotel['name'],
                'airportname' => $hotel['name'],
                'cityname' => $hotel['address'] ?? ($hotel['city_code'] ?? ''),
                'countryname' => $hotel['country_code'] ?? '',
                'hotel_code' => $hotel['hotel_code'],
                'city_code' => $hotel['city_code'] ?? '',
                'latitude' => $hotel['latitude'] !== null ? (float)$hotel['latitude'] : null,
                'longitude' => $hotel['longitude'] !== null ? (float)$hotel['longitude'] : null,
                'supplier' => 'grnconnect',
                'is_hotel' => true,
            ];
        }

        $locations = [];
        try {
            $locStmt = $db->pdo->prepare(
                'SELECT id, country, country_code, city, latitude, longitude
                 FROM locations
                 WHERE status = 1 AND city LIKE ?
                 LIMIT 5'
            );
            $locStmt->execute([$prefix]);
            $locations = $locStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('GRNConnect destination locations: ' . $e->getMessage());
        }

        if (!$grnCities) {
            foreach ($locations as $location) {
                if ($location['latitude'] === null || $location['longitude'] === null || $location['latitude'] === '' || $location['longitude'] === '') {
                    continue;
                }
                $results[] = [
                    'id' => 'grn-loc-' . $location['id'],
                    'type' => 'location',
                    'lc' => 'city',
                    'name' => $location['city'],
                    'airportname' => $location['city'],
                    'cityname' => $location['city'],
                    'countryname' => $location['country'],
                    'countrycode' => $location['country_code'],
                    'latitude' => (float)$location['latitude'],
                    'longitude' => (float)$location['longitude'],
                    'radius' => 20,
                    'supplier' => 'grnconnect',
                    'is_hotel' => false,
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('GRNConnect destination suggestion error: ' . $e->getMessage());
        echo json_encode([]);
        exit;
    }

    echo json_encode($results);
    exit;
});

$router->post('/stays/grnconnect/destination-meta', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $module = grnconnect_module($db);
        if (!$module || (int)($module['status'] ?? 0) !== 1 || (int)($module['active'] ?? 0) !== 1) {
            echo json_encode(['success' => false, 'message' => 'GRNConnect module not active']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        if (!empty($input['clear'])) {
            unset($_SESSION['hotel_search_meta'], $_SESSION['hotel_destination_code']);
            echo json_encode(['success' => true, 'cleared' => true]);
            exit;
        }

        $type = strtolower(trim((string)($input['type'] ?? 'auto')));
        $meta = [
            'type'             => in_array($type, ['hotel', 'city', 'location', 'circle'], true) ? $type : 'auto',
            'hotel_code'       => trim((string)($input['hotel_code'] ?? '')),
            'destination_code' => trim((string)($input['destination_code'] ?? ($type === 'city' ? ($input['id'] ?? '') : ''))),
            'latitude'         => isset($input['latitude']) && $input['latitude'] !== '' ? (float)$input['latitude'] : null,
            'longitude'        => isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : null,
            'radius'           => (float)($input['radius'] ?? 20),
            'name'             => trim((string)($input['name'] ?? $input['airportname'] ?? '')),
            'supplier'         => 'grnconnect',
        ];

        // Keep location typed as location; only force circle when client already sent circle
        if ($meta['type'] === 'hotel' && $meta['hotel_code'] === '' && !empty($input['id'])) {
            $meta['hotel_code'] = (string)$input['id'];
        }
        // City picks must not retain hotel/geo leftovers
        if ($meta['type'] === 'city') {
            $meta['hotel_code'] = '';
            $meta['latitude'] = null;
            $meta['longitude'] = null;
        }

        $_SESSION['hotel_search_meta'] = $meta;
        if ($meta['destination_code'] !== '') {
            $_SESSION['hotel_destination_code'] = $meta['destination_code'];
        }
        if ($meta['name'] !== '') {
            $_SESSION['hotel_destination'] = $meta['name'];
        }

        echo json_encode(['success' => true, 'meta' => $meta]);
        exit;
    } catch (\Throwable $e) {
        error_log('GRNConnect destination-meta error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to store destination meta',
            'error'   => $e->getMessage(),
        ]);
        exit;
    }
});
