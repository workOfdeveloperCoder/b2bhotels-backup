<?php
// ============================================================================
// RATEHAWK HOTEL DETAILS - DATABASE INFORMATION
// ============================================================================
// ENDPOINT: POST /stays/ratehawk/details
// PURPOSE: Fetch hotel details from local database
// ============================================================================

$router->post('stays/ratehawk/details', function() use ($db) {

    // CLEAN OUTPUT BUFFER
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $supplier = $input['supplier'] ?? 'ratehawk';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? '';
        $rooms = $input['rooms'] ?? [];

        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_id'
            ]);
            exit;
        }

        // ============================================================================
        // GET MODULE CONFIGURATION
        // ============================================================================
        $module = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'RateHawk module not configured'
            ]);
            exit;
        }

        // ============================================================================
        // CONNECT TO RATEHAWK MODULE DATABASE (modulesratehawk)
        // ============================================================================
        $ratehawkDb = new \Medoo\Medoo([
            'type'     => 'mysql',
            'host'     => $module['host'] ?? 'localhost',
            'database' => $module['database'],
            'username' => $module['username'],
            'password' => $module['password'],
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        // ============================================================================
        // FETCH HOTEL FROM DATABASE
        // ============================================================================
        $hotel = $ratehawkDb->get('ratehawk_hotels', '*', [
            'hotel_id' => $hotelId
        ]);

        if (!$hotel) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found'
            ]);
            exit;
        }

        // ============================================================================
        // PROCESS IMAGES
        // ============================================================================
        $imagesArray = is_array($hotel['images']) 
            ? $hotel['images'] 
            : (json_decode($hotel['images'], true) ?? []);
        
        $processedImages = array_map(function($url) {
            return str_replace('{size}', '1024x768', $url);
        }, $imagesArray);

        // ============================================================================
        // PROCESS AMENITIES
        // ============================================================================
        $amenitiesArray = is_array($hotel['amenities']) 
            ? $hotel['amenities'] 
            : (json_decode($hotel['amenities'], true) ?? []);
        
        $amenities = array_values(array_unique(array_filter($amenitiesArray, function($amenity) {
            return !in_array(trim($amenity), ['undefined', 'null', '', '1', 'N/A']);
        })));

        // ============================================================================
        // GUEST REVIEWS + POINTS OF INTEREST (populated by the Content API sync)
        // ============================================================================
        $ratehawkReviews = [];
        $ratehawkPois = [];
        $reviewHid = (int)($hotel['hid'] ?? 0);
        try {
            $reviewWhere = $reviewHid > 0 ? ['hid' => $reviewHid] : ['hotel_id' => $hotelId];
            $rows = $ratehawkDb->select('ratehawk_reviews', [
                'rating', 'review_plus', 'review_minus', 'author', 'traveller_type',
                'trip_type', 'room_name', 'review_date', 'nights', 'detailed'
            ], array_merge($reviewWhere, [
                'ORDER' => ['review_date' => 'DESC', 'id' => 'DESC'],
                'LIMIT' => 30,
            ])) ?: [];
            foreach ($rows as $r) {
                $ratehawkReviews[] = [
                    'rating'         => $r['rating'] !== null ? (float)$r['rating'] : null,
                    'positive'       => $r['review_plus'] ?: '',
                    'negative'       => $r['review_minus'] ?: '',
                    'author'         => $r['author'] ?: '',
                    'traveller_type' => $r['traveller_type'] ?: '',
                    'trip_type'      => $r['trip_type'] ?: '',
                    'room_name'      => $r['room_name'] ?: '',
                    'date'           => $r['review_date'] ?: '',
                    'nights'         => $r['nights'] !== null ? (int)$r['nights'] : null,
                    'detailed'       => is_array($r['detailed']) ? $r['detailed'] : (json_decode($r['detailed'] ?? 'null', true)),
                ];
            }

            if ($reviewHid > 0) {
                $poiRow = $ratehawkDb->get('ratehawk_pois', 'pois', ['hid' => $reviewHid]);
                if ($poiRow) {
                    $ratehawkPois = is_array($poiRow) ? $poiRow : (json_decode($poiRow, true) ?: []);
                }
            }
        } catch (\Throwable $e) {
            // sync tables not created yet — return empty arrays, never fail the page
        }

        // ============================================================================
        // BUILD LOCATION AND ADDRESS
        // ============================================================================
        $location = array_filter([
            $hotel['city'] ?? '', 
            $hotel['country'] ?? ''
        ]);
        
        $address = '';
        if (!empty($hotel['address']) && !in_array(strtolower(trim($hotel['address'])), 
            ['undefined', 'address not available', 'null', 'n/a'])) {
            $address = $hotel['address'];
        }

        // ============================================================================
        // PROCESS POLICIES
        // ============================================================================
        // ETG 2.3: metapolicy_struct / metapolicy_extra_info are "additional
        // accommodation conditions" / "additional hotel information" — hotel-level
        // rules, mandatory fees, know-before-you-go — NOT the cancellation policy.
        // This used to overwrite $cancellationPolicy with this content, so a box
        // titled "Cancellation Policy" showed things like an airport shuttle fee.
        // Kept as its own field now, shown under its own "Important Information" box.
        $hotelPolicies = trim((string) ($hotel['metapolicy_extra_info'] ?? ''));

        $metapolicyStruct = is_array($hotel['metapolicy_struct'])
            ? $hotel['metapolicy_struct']
            : (json_decode($hotel['metapolicy_struct'], true) ?? []);

        if (!empty($metapolicyStruct)) {
            $policyText = [];
            foreach ($metapolicyStruct as $policy) {
                if (isset($policy['text'])) {
                    $policyText[] = $policy['text'];
                }
            }
            if (!empty($policyText)) {
                $hotelPolicies = implode("\n\n", $policyText);
            }
        }

        // Cancellation terms genuinely differ per rate (see rh_parse_cancellation_policy
        // in rooms.php / search.php) — a single hotel-level page can't state one
        // definitive policy, so this stays an honest, generic pointer.
        $cancellationPolicy = 'Cancellation policies vary by room rate. Free cancellation may be '
            . 'available on some rates — the exact deadline and any partial-refund terms are '
            . 'shown for each room option when you search availability.';

        // ============================================================================
        // BUILD DESCRIPTION
        // ============================================================================
        $description = !empty($hotel['description']) 
            ? $hotel['description'] 
            : 'No description available.';
        
        $checkInTime = $hotel['check_in_time'] ?? '14:00';
        $checkOutTime = $hotel['check_out_time'] ?? '12:00';
        
        $description .= "\n\n<strong>Check-in:</strong> " . $checkInTime . 
                       "\n<strong>Check-out:</strong> " . $checkOutTime;

        // ============================================================================
        // BUILD RESPONSE
        // ============================================================================
        $response = [
            'success' => true,
            'data' => [
                'id' => $hotel['hotel_id'],
                'name' => $hotel['name'] ?? 'Hotel',
                'description' => $description,
                'address' => $address,
                'location' => implode(', ', $location),
                'cancellation_policy' => $cancellationPolicy,
                'hotel_policies' => $hotelPolicies,
                'stars' => $hotel['star_rating'] ?? 3,
                'rating' => (float)($hotel['rating'] ?? $hotel['star_rating'] ?? 3),
                'guest_rating' => isset($hotel['rating']) && $hotel['rating'] !== null ? round((float)$hotel['rating'], 1) : null,
                'guest_rating_count' => (int)($hotel['rating_count'] ?? 0),
                'reviews' => $ratehawkReviews ?? [],
                'pois' => $ratehawkPois ?? [],
                'distance_center' => isset($hotel['distance_center']) && $hotel['distance_center'] !== null ? (int)$hotel['distance_center'] : null,
                'latitude' => !empty($hotel['latitude']) ? floatval($hotel['latitude']) : null,
                'longitude' => !empty($hotel['longitude']) ? floatval($hotel['longitude']) : null,
                'images' => $processedImages,
                'amenities' => $amenities,
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'payment_methods' => is_array($hotel['payment_methods']) 
                    ? $hotel['payment_methods'] 
                    : (json_decode($hotel['payment_methods'], true) ?? []),
                'facts' => is_array($hotel['facts']) 
                    ? $hotel['facts'] 
                    : (json_decode($hotel['facts'], true) ?? []),
                'rooms' => [],
                'supplier' => $supplier,
                'checkin' => $checkin,
                'checkout' => $checkout
            ]
        ];

        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
        ob_end_flush();
    }

});
