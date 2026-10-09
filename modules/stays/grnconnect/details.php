<?php
// ============================================================================
// GRNCONNECT HOTEL DETAILS
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/details
// Returns normalized hotel content from the content DB, falling back to the
// live static hotel-detail endpoint when the hotel is not imported yet.
//
// Images: local DB first (grnconnect_hotel_images). Only calls the GRNConnect
// Images API when the hotel has no persisted images, then saves them.
// ============================================================================

$router->post('/stays/grnconnect/details', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $fail = function ($message) {
        if (ob_get_level()) ob_clean();
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    };

    try {
        require_once __DIR__ . '/api.php';

        $input    = json_decode(file_get_contents('php://input'), true) ?: [];
        $hotelId  = (string)($input['hotel_id'] ?? '');
        $supplier = $input['supplier'] ?? 'grnconnect';
        $checkin  = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';

        if ($hotelId === '') {
            $fail('Missing required parameter: hotel_id');
        }

        $module = grnconnect_module($db);
        if (!$module) {
            $fail('GRNConnect module not configured');
        }

        // ----- Try content DB first ----------------------------------------
        $hotel     = null;
        $contentDb = grnconnect_content_db($module);
        if ($contentDb) {
            $hotel = $contentDb->get('grnconnect_hotels', '*', ['hotel_code' => $hotelId]);
        }

        $client = GRNConnectClient::fromModule($module);

        // ----- Fallback to live static endpoint ----------------------------
        if (!$hotel) {
            $res  = $client->hotel($hotelId);
            $live = $res['data']['hotels'][0] ?? $res['data']['hotel'] ?? null;
            if ($live) {
                $hotel = [
                    'hotel_code'   => $live['code'] ?? $hotelId,
                    'name'         => $live['name'] ?? 'Hotel',
                    'address'      => $live['address'] ?? '',
                    'city_code'    => $live['city_code'] ?? '',
                    'country_code' => $live['country'] ?? '',
                    'latitude'     => $live['latitude'] ?? null,
                    'longitude'    => $live['longitude'] ?? null,
                    'category'     => $live['category'] ?? 0,
                    'description'  => $live['description'] ?? '',
                    'facilities'   => $live['facilities'] ?? '',
                    'images'       => null,
                ];
            }
        }

        if (!$hotel) {
            $fail('Hotel not found');
        }

        // ----- Images: local DB first, Images API only if missing ----------
        $imageUrls = [];
        $mainImage = '';
        if ($contentDb) {
            $resolved  = grnconnect_resolve_hotel_images($client, $contentDb, $hotelId);
            $imageUrls = $resolved['urls'] ?? [];
            $mainImage = $resolved['main_image'] ?? '';
        } else {
            // No content DB configured — best-effort live fetch (do not persist).
            grnconnect_image_log('fallback_api', $hotelId, ['source' => 'details_no_content_db']);
            $imagesResponse = $client->hotelImages($hotelId);
            foreach ($imagesResponse['data']['images']['regular'] ?? [] as $image) {
                $url = $image['url'] ?? '';
                if ($url === '') continue;
                if (!empty($image['main_image']) && $mainImage === '') {
                    $mainImage = $url;
                }
                $imageUrls[] = $url;
            }
            if ($mainImage !== '') {
                $imageUrls = array_values(array_unique(array_merge(
                    [$mainImage],
                    array_filter($imageUrls, function ($u) use ($mainImage) {
                        return $u !== $mainImage;
                    })
                )));
            } else {
                $imageUrls = array_values(array_unique($imageUrls));
                $mainImage = $imageUrls[0] ?? '';
            }
        }

        // Compatibility fallback: hotel.images JSON blob (legacy / search cache)
        if (empty($imageUrls) && !empty($hotel['images'])) {
            $imageUrls = grnconnect_hotel_images($hotel['images']);
            $mainImage = $imageUrls[0] ?? '';
        }

        $amenities = grnconnect_parse_facilities($hotel['facilities'] ?? '');

        $city = '';
        if (!empty($hotel['city_code']) && $contentDb) {
            $cityRow = $contentDb->get('grnconnect_cities', ['search_name'], ['city_code' => $hotel['city_code']]);
            if ($cityRow) $city = $cityRow['search_name'];
        }
        $location = trim(implode(', ', array_filter([$city, $hotel['country_code'] ?? ''])));

        $description = !empty($hotel['description']) ? $hotel['description'] : 'No description available.';

        $response = [
            'success' => true,
            'data'    => [
                'id'                  => $hotel['hotel_code'],
                'name'                => $hotel['name'] ?? 'Hotel',
                'description'         => $description,
                'address'             => $hotel['address'] ?? '',
                'location'            => $location,
                'cancellation_policy' => 'Cancellation policies vary by rate and are shown when selecting a room.',
                'stars'               => (float)($hotel['category'] ?? 0),
                'rating'              => (float)($hotel['category'] ?? 0),
                'latitude'            => !empty($hotel['latitude']) ? (float)$hotel['latitude'] : null,
                'longitude'           => !empty($hotel['longitude']) ? (float)$hotel['longitude'] : null,
                'image'               => $mainImage,
                'images'              => $imageUrls,
                'amenities'           => $amenities,
                'check_in_time'       => '14:00',
                'check_out_time'      => '12:00',
                'rooms'               => [],
                'supplier'            => $supplier,
                'checkin'             => $checkin,
                'checkout'            => $checkout,
            ],
        ];

        if (ob_get_level()) ob_clean();
        echo json_encode($response);
        exit;

    } catch (\Throwable $e) {
        $fail('Server error: ' . $e->getMessage());
    }
});
