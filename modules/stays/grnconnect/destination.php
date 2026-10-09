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

    $query = isset($_POST['query']) ? trim($_POST['query']) : '';
    if ($query === '' || strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $results = [];

    try {
        $module = grnconnect_module($db);
        if (!$module || empty($module['database']) || (int)($module['status'] ?? 0) !== 1 || (int)($module['active'] ?? 0) !== 1) {
            echo json_encode([]);
            exit;
        }

        $contentDb = grnconnect_content_db($module);
        if (!$contentDb) {
            echo json_encode([]);
            exit;
        }

        $grnCities = $contentDb->select('grnconnect_cities', [
            'city_code', 'name', 'search_name', 'country_code'
        ], [
            'OR' => [
                'search_name[~]' => $query,
                'name[~]'        => $query,
            ],
            'ORDER' => ['search_name' => 'ASC'],
            'LIMIT' => 8,
        ]) ?: [];

        foreach ($grnCities as $city) {
            $label = $city['search_name'] ?: $city['name'];
            $results[] = [
                'id' => $city['city_code'],
                'type' => 'city',
                'lc' => 'city',
                'name' => $label,
                'airportname' => $label,
                'cityname' => $label,
                'countryname' => $city['country_code'] ?? '',
                'countrycode' => $city['country_code'] ?? '',
                'destination_code' => $city['city_code'],
                'supplier' => 'grnconnect',
                'is_hotel' => false,
            ];
        }

        $grnHotels = $contentDb->select('grnconnect_hotels', [
            'hotel_code', 'name', 'address', 'city_code', 'country_code', 'latitude', 'longitude'
        ], [
            'OR' => [
                'name[~]'    => $query,
                'address[~]' => $query,
            ],
            'ORDER' => ['name' => 'ASC'],
            'LIMIT' => 8,
        ]) ?: [];

        foreach ($grnHotels as $hotel) {
            $results[] = [
                'id' => $hotel['hotel_code'],
                'type' => 'hotel',
                'lc' => 'hotel',
                'name' => $hotel['name'],
                'airportname' => $hotel['name'],
                'cityname' => $hotel['address'] ?? ($hotel['city_code'] ?? ''),
                'countryname' => $hotel['country_code'] ?? '',
                'hotel_code' => $hotel['hotel_code'],
                'latitude' => $hotel['latitude'] !== null ? (float)$hotel['latitude'] : null,
                'longitude' => $hotel['longitude'] !== null ? (float)$hotel['longitude'] : null,
                'supplier' => 'grnconnect',
                'is_hotel' => true,
            ];
        }

        // Locations with coords for GRN circle search (from main locations table)
        $locations = $db->select('locations', [
            'id', 'country', 'country_code', 'city', 'latitude', 'longitude'
        ], [
            'OR' => [
                'city[~]' => $query,
                'country[~]' => $query,
            ],
            'status' => 1,
            'ORDER' => ['city' => 'ASC'],
            'LIMIT' => 5,
        ]) ?: [];

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
            unset($_SESSION['hotel_search_meta']);
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
