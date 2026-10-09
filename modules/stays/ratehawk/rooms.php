<?php
// ============================================================================
// RATEHAWK HOTEL ROOMS - REAL-TIME AVAILABILITY & PRICING
// ============================================================================
// ENDPOINT: POST /stays/ratehawk/rooms
// PURPOSE: Fetch available rooms with live pricing from RateHawk API
// FALLBACK: Database rooms if API unavailable
// ============================================================================

$router->post('/stays/ratehawk/rooms', function() use ($db) {

    // CLEAN OUTPUT BUFFER
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $hotelId = $input['hotel_id'] ?? '';
        $checkin = trim($input['checkin'] ?? '');
        $checkout = trim($input['checkout'] ?? '');
        $nationality = $input['nationality'] ?? 'US';

        // Prefer rooms_data (per-room adults/children/ages). Frontend sends rooms as a count integer.
        $rooms = $input['rooms_data'] ?? null;
        if (is_string($rooms)) {
            $rooms = json_decode($rooms, true);
        }
        if (!is_array($rooms) || empty($rooms)) {
            $rooms = $input['rooms'] ?? [['adults' => 2, 'children' => 0, 'childAges' => []]];
        }
        // Normalize rooms: if integer/string, convert to array of room objects
        if (!is_array($rooms)) {
            $count = max(1, (int)$rooms);
            $rooms = array_fill(0, $count, ['adults' => 2, 'children' => 0, 'childAges' => []]);
        } elseif (!empty($rooms) && !isset($rooms[0])) {
            // Single room object passed without wrapping array
            $rooms = [$rooms];
        }
        // Ensure each room has normalized occupancy
        $rooms = array_values(array_map(function ($room) {
            if (!is_array($room)) {
                return ['adults' => 2, 'children' => 0, 'childAges' => []];
            }
            $adults = max(1, (int)($room['adults'] ?? 2));
            $children = max(0, (int)($room['children'] ?? 0));
            $ages = $room['childAges'] ?? $room['children_ages'] ?? $room['child_ages'] ?? [];
            if (!is_array($ages)) {
                $ages = [];
            }
            $ages = array_map('intval', array_values($ages));
            while (count($ages) < $children) {
                $ages[] = 1;
            }
            if (count($ages) > $children) {
                $ages = array_slice($ages, 0, $children);
            }
            return [
                'adults' => $adults,
                'children' => $children,
                'childAges' => $ages,
            ];
        }, $rooms));

        $currency = $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';
        $sessionCurrency = $_SESSION['app_currency'] ?? $currency;

        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_id'
            ]);
            exit;
        }

        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters: checkin and checkout dates'
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

        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

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

        // Decoder for room-amenity / bedding / socket slugs (ratehawk_static, filled
        // by the Content API sync). Falls back to a prettified slug when absent.
        require_once __DIR__ . '/content/hotel-schema.php';

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
        // CALCULATE NUMBER OF NIGHTS
        // ============================================================================
        $number_of_nights = 1;
        if (!empty($checkin) && !empty($checkout)) {
            try {
                $checkin_parts = explode('-', $checkin);
                $checkout_parts = explode('-', $checkout);
                if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
                    $checkin_date = "{$checkin_parts[2]}-{$checkin_parts[1]}-{$checkin_parts[0]}";
                    $checkout_date = "{$checkout_parts[2]}-{$checkout_parts[1]}-{$checkout_parts[0]}";
                    $date1 = new DateTime($checkin_date);
                    $date2 = new DateTime($checkout_date);
                    $number_of_nights = max(1, (int)$date1->diff($date2)->days);
                }
            } catch (Exception $e) {
                $number_of_nights = 1;
            }
        }

        // ============================================================================
        // PREPARE DATABASE ROOMS AS FALLBACK
        // ============================================================================
        $roomsData = is_array($hotel['rooms']) 
            ? $hotel['rooms'] 
            : (json_decode($hotel['rooms'], true) ?? []);

        if (is_array($roomsData)) {
            $roomsData = array_values(array_filter($roomsData, function($room) {
                return is_array($room) && !empty($room);
            }));
        } else {
            $roomsData = [];
        }

        $formattedRooms = [];

        foreach ($roomsData as $idx => $room) {
            $uniqueRoomId = $hotelId . '_room_' . $idx . '_' . uniqid();
            $basePrice = rand(50, 300);

            $pricePerNight = MARKUP($basePrice, $module, $db, $moduleCurrency, $sessionCurrency);
            $totalPrice = $pricePerNight['price'] * $number_of_nights;

            $roomAmenities = is_array($room['room_amenities'] ?? null) 
                ? $room['room_amenities'] 
                : (json_decode($room['room_amenities'] ?? '[]', true) ?? []);

            $roomImages = is_array($room['images'] ?? null) 
                ? $room['images'] 
                : (json_decode($room['images'] ?? '[]', true) ?? []);
            
            $processedRoomImages = array_map(function($url) {
                return str_replace('{size}', '1024x768', $url);
            }, $roomImages);

            $formattedRooms[] = [
                'room_id' => $uniqueRoomId,
                'room_type_id' => $uniqueRoomId,
                'room_name' => $room['name'] ?? 'Standard Room',
                'room_type_name' => $room['name_struct']['main_name'] ?? $room['name'] ?? 'Standard',
                'description' => $room['description'] ?? '',
                'size_sqm' => $room['size'] ?? null,
                'max_adults' => $room['rg_ext']['capacity'] ?? 2,
                // RateHawk's rg_ext has no children-capacity field — 0 (hides the
                // badge) is honest; it was previously a fabricated "2" always shown.
                'max_children' => 0,
                'bed_type' => $room['name_struct']['bedding_type'] ?? 'Double Bed',
                'amenities' => array_map(function($amenity, $index) use ($ratehawkDb) {
                    return [
                        'id' => $index + 1,
                        'name' => rh_static_label($ratehawkDb, 'room_amenities', $amenity)
                    ];
                }, $roomAmenities, array_keys($roomAmenities)),
                'room_images' => $processedRoomImages,
                'room_main_image' => !empty($processedRoomImages) ? $processedRoomImages[0] : null,
                'min_price_per_night' => round($pricePerNight['price'], 2),
                'options_count' => 1,
                'options' => [
                    [
                        'option_id' => $hotelId . '_room_' . $idx . '_opt_1',
                        'board_name' => 'Room Only',
                        'board_code' => 'RO',
                        'meal_plan' => 'Room Only',
                        'cancellation_free' => 1,
                        'refundable' => 1,
                        'breakfast_included' => 0,
                        'price_per_night' => round($pricePerNight['price'], 2),
                        'total_price' => round($totalPrice, 2),
                        'currency' => $sessionCurrency,
                        'adults' => $rooms[0]['adults'] ?? 2,
                        'children' => $rooms[0]['children'] ?? 0,
                        'available_quantity' => max(1, count($rooms)),
                        'rate_key' => base64_encode($hotelId . '|room_' . $idx . '|opt_1')
                    ]
                ]
            ];
        }

        if (empty($formattedRooms)) {
            $uniqueRoomId = $hotelId . '_std_' . uniqid();
            $basePrice = rand(50, 200);
            $pricePerNight = MARKUP($basePrice, $module, $db, $moduleCurrency, $sessionCurrency);
            $totalPrice = $pricePerNight['price'] * $number_of_nights;

            $formattedRooms[] = [
                'room_id' => $uniqueRoomId,
                'room_type_id' => $uniqueRoomId,
                'room_name' => 'Standard Room',
                'room_type_name' => 'Standard',
                'description' => 'Comfortable standard room with modern amenities',
                'size_sqm' => null,
                'max_adults' => 2,
                'max_children' => 0,
                'bed_type' => 'Double Bed',
                'amenities' => [],
                'room_main_image' => null,
                'min_price_per_night' => round($pricePerNight['price'], 2),
                'options_count' => 1,
                'options' => [
                    [
                        'option_id' => $hotelId . '_std_001',
                        'board_name' => 'Room Only',
                        'board_code' => 'RO',
                        'meal_plan' => 'Room Only',
                        'cancellation_free' => 1,
                        'refundable' => 1,
                        'breakfast_included' => 0,
                        'price_per_night' => round($pricePerNight['price'], 2),
                        'total_price' => round($totalPrice, 2),
                        'currency' => $sessionCurrency,
                        'adults' => $rooms[0]['adults'] ?? 2,
                        'children' => $rooms[0]['children'] ?? 0,
                        'available_quantity' => max(1, count($rooms)),
                        'rate_key' => base64_encode($hotelId . '|std|001')
                    ]
                ]
            ];
        }

        // ============================================================================
        // CALL RATEHAWK PREBOOK API FOR REAL-TIME PRICING
        // ============================================================================
                $keyId = trim($module['c1'] ?? '');
        $apiKey = trim($module['c3'] ?? '');
        
        if (empty($keyId) || empty($apiKey)) {
            echo json_encode([
                'success' => false,
                'message' => 'RateHawk API credentials not configured'
            ]);
            exit;
        }

        $apiBaseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        // CONVERT DATE FORMAT: DD-MM-YYYY → YYYY-MM-DD
        $checkinParts = explode('-', $checkin);
        $checkoutParts = explode('-', $checkout);
        
        if (count($checkinParts) === 3 && count($checkoutParts) === 3) {
            $apiCheckin = (strlen($checkinParts[0]) === 4) 
                ? $checkin 
                : $checkinParts[2] . '-' . $checkinParts[1] . '-' . $checkinParts[0];
            
            $apiCheckout = (strlen($checkoutParts[0]) === 4) 
                ? $checkout 
                : $checkoutParts[2] . '-' . $checkoutParts[1] . '-' . $checkoutParts[0];
        } else {
            $apiCheckin = $checkin;
            $apiCheckout = $checkout;
        }

        // PREPARE API REQUEST — children must be ages[], not a count
        // ETG 23.09.2026: `id` (string hotel_id slug) is deprecated on /search/hp/ —
        // use `hid`, which is strictly numeric and rejects string slugs with
        // invalid_json_format (verified live). $hotel['hid'] is the numeric id we
        // already store for every hotel; fall back to the legacy `id`+slug only in
        // the unlikely case it's missing on an old/unsynced row.
        $hotelpageRequest = [];
        if (!empty($hotel['hid'])) {
            $hotelpageRequest['hid'] = (int) $hotel['hid'];
        } else {
            $hotelpageRequest['id'] = (string) $hotelId;
        }
        $hotelpageRequest += [
            'checkin' => $apiCheckin,
            'checkout' => $apiCheckout,
            'residency' => strtolower($nationality),
            'language' => 'en',
            'guests' => array_map(function($room) {
                $roomAdults = max(1, (int)($room['adults'] ?? 2));
                $roomChildren = max(0, (int)($room['children'] ?? 0));
                $ages = $room['childAges'] ?? $room['children_ages'] ?? $room['child_ages'] ?? [];
                if (!is_array($ages)) {
                    $ages = [];
                }
                $ages = array_map('intval', array_values($ages));
                while (count($ages) < $roomChildren) {
                    $ages[] = 1;
                }
                if (count($ages) > $roomChildren) {
                    $ages = array_slice($ages, 0, $roomChildren);
                }

                $guestRoom = ['adults' => $roomAdults];
                if ($roomChildren > 0) {
                    $guestRoom['children'] = $ages;
                }
                return $guestRoom;
            }, $rooms),
            'currency' => $moduleCurrency
        ];

        // CALL API
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/search/hp/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($hotelpageRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $hotelpageResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('rooms', 'hotelpage', $hotelpageRequest, $hotelpageResponse, '', [
                'url' => $apiBaseUrl . '/search/hp/',
                'method' => 'POST',
                'headers' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ***'
                ],
                'response_headers' => ''
            ]);
        }


        // ============================================================================
        // PROCESS API RESPONSE OR USE FALLBACK
        // ============================================================================
        $usingLivePricing = false;
        
        if (!$curlError && $httpCode === 200) {
            $apiData = json_decode($hotelpageResponse, true);
            $hotels = $apiData['data']['hotels'] ?? [];
            
            if (!empty($hotels)) {
                $hotelData = $hotels[0];
                $rates = $hotelData['rates'] ?? [];
                
                // ------------------------------------------------------------------
                // ETG Integration Requirements 2.4 — room static data (photos +
                // amenities) MUST be matched by the full rg_ext identifier, never by
                // room_name. Build an index of the stored static room groups keyed by
                // their rg_ext signature, then match each live rate's rg_ext against
                // it. A rate whose rg_ext has no static match becomes its own room
                // block built from the rate's own data — WITHOUT photos.
                // ------------------------------------------------------------------
                $rgSignature = function ($ext) {
                    if (!is_array($ext) || empty($ext)) return '';
                    ksort($ext);
                    $parts = [];
                    foreach ($ext as $k => $v) {
                        $parts[] = $k . '=' . (is_scalar($v) ? $v : json_encode($v));
                    }
                    return implode('|', $parts);
                };

                // ETG 3.11 — the room label shown to guests. PRIMARY: rate.room_name
                // exactly as ETG returns it. FALLBACK (only if room_name is empty):
                // construct it from room_data_trans / room_groups —
                //   main_name + (bathroom) + (beds/bedding_type) + (misc_room_type / amenities_data)
                $buildRoomName = function ($rate, $staticMatch) {
                    $rn = trim((string) ($rate['room_name'] ?? ''));
                    if ($rn !== '') return $rn;

                    $t = is_array($rate['room_data_trans'] ?? null) ? $rate['room_data_trans'] : [];
                    $base = trim((string) (
                        ($staticMatch['name_struct']['main_name'] ?? null)
                        ?? ($t['main_name'] ?? null)
                        ?? ($staticMatch['name'] ?? '')
                    ));

                    $parts = [];
                    if ($base !== '') $parts[] = $base;
                    if (!empty($t['bathroom'])) $parts[] = '(' . $t['bathroom'] . ')';

                    if (!empty($t['bedding_type'])) {
                        $parts[] = '(' . $t['bedding_type'] . ')';
                    } elseif (!empty($t['beds']) && is_array($t['beds'])) {
                        $beds = [];
                        foreach ($t['beds'] as $b) {
                            if (empty($b['bed'])) continue;
                            $cnt = (int) ($b['count'] ?? 1);
                            $beds[] = $cnt . ' ' . $b['bed'] . ' bed' . ($cnt > 1 ? 's' : '');
                        }
                        if ($beds) $parts[] = '(' . implode(', ', $beds) . ')';
                    }

                    if (!empty($t['misc_room_type'])) {
                        $parts[] = '(' . $t['misc_room_type'] . ')';
                    } elseif (!empty($rate['amenities_data']) && is_array($rate['amenities_data'])) {
                        $parts[] = '(' . implode(', ', array_slice($rate['amenities_data'], 0, 4)) . ')';
                    }

                    $out = trim(implode(' ', $parts));
                    return $out !== '' ? $out : 'Room';
                };

                $staticGroupIndex = [];
                foreach ($roomsData as $sg) {
                    if (!is_array($sg) || empty($sg['rg_ext'])) continue;
                    $sig = $rgSignature($sg['rg_ext']);
                    if ($sig !== '' && !isset($staticGroupIndex[$sig])) {
                        $staticGroupIndex[$sig] = $sg;
                    }
                }

                // Group rates by rg_ext ONLY — never by room_name (ETG 23.09.2026:
                // two rates must not be merged into one card unless their rg_ext
                // objects are identical, even if room_name happens to match).
                //   - rg_ext matches a stored static group -> grouped under that
                //     group's signature (gets photos/amenities from static data).
                //   - rg_ext present but no static match -> still grouped by that
                //     rate's OWN rg_ext signature (a "new" block, no photos), NOT
                //     by name — two such rates only share a card if their rg_ext
                //     is identical.
                //   - no rg_ext at all (degenerate/missing data) -> falls back to
                //     name grouping as a last resort; this should be rare.
                $groupedRooms = [];
                foreach ($rates as $rate) {
                    $sig = $rgSignature($rate['rg_ext'] ?? []);
                    $staticMatch = ($sig !== '' && isset($staticGroupIndex[$sig]))
                        ? $staticGroupIndex[$sig]
                        : null;

                    if ($staticMatch) {
                        $groupKey = 'rg:' . $sig;
                    } elseif ($sig !== '') {
                        $groupKey = 'newrg:' . $sig;
                    } else {
                        $groupKey = 'new:' . mb_strtolower($buildRoomName($rate, null));
                    }

                    if (!isset($groupedRooms[$groupKey])) {
                        $groupedRooms[$groupKey] = ['static' => $staticMatch, 'rates' => []];
                    }
                    $groupedRooms[$groupKey]['rates'][] = $rate;
                }

                $formattedRooms = [];
                $usingLivePricing = true;

                foreach ($groupedRooms as $groupKey => $group) {
                    $apiRates    = $group['rates'];
                    $staticMatch = $group['static'];
                    $firstRate   = $apiRates[0];

                    // ETG 3.11 "room name reflection": rate.room_name is the primary
                    // label; $buildRoomName constructs a compliant name only if it's empty.
                    $roomName = $buildRoomName($firstRate, $staticMatch);

                    // Photos + amenities come ONLY from a matched static group.
                    $roomImages = [];
                    $staticAmenities = [];
                    if ($staticMatch) {
                        $imgs = is_array($staticMatch['images'] ?? null)
                            ? $staticMatch['images']
                            : (json_decode($staticMatch['images'] ?? '[]', true) ?? []);
                        $roomImages = array_map(function ($url) {
                            return str_replace('{size}', '1024x768', $url);
                        }, $imgs);
                        $staticAmenities = is_array($staticMatch['room_amenities'] ?? null)
                            ? $staticMatch['room_amenities']
                            : (json_decode($staticMatch['room_amenities'] ?? '[]', true) ?? []);
                    }

                    $rateOptions = [];
                    $maxAdults = 2;
                    foreach ($apiRates as $rate) {
                        $bookHash = $rate['book_hash'] ?? null;
                        if (empty($bookHash)) continue;

                        $paymentType = $rate['payment_options']['payment_types'][0] ?? [];
                        $basePrice = (float)($paymentType['show_amount'] ?? $paymentType['amount'] ?? 0);
                        if ($basePrice <= 0) continue;

                        $apiCurrency = strtoupper((string)(
                            $paymentType['show_currency_code']
                            ?? $paymentType['currency_code']
                            ?? $moduleCurrency
                        ));

                        $priceData = MARKUP($basePrice, $module, $db, $apiCurrency, $sessionCurrency);
                        $finalPrice = $priceData['price'] ?? $basePrice;
                        $pricePerNight = $finalPrice / $number_of_nights;

                        if (isset($rate['rg_ext']['capacity']) && (int)$rate['rg_ext']['capacity'] > 0) {
                            $maxAdults = max($maxAdults, (int)$rate['rg_ext']['capacity']);
                        }

                        // RateHawk `allotment` = how many rooms of this exact rate can
                        // be booked in one reservation (max 9 per ETG). Drives the
                        // quantity selector; without it the UI caps at 1 and a
                        // multi-room search of one room type becomes unbookable.
                        $allotment = (int) ($rate['allotment'] ?? 0);
                        $availableQty = $allotment > 0 ? min(9, $allotment) : 1;

                        // ETG 3.7 — parse the full (up to 3-layer) cancellation policy,
                        // with every time explicitly labelled UTC (ETG always returns
                        // UTC+0). Also fixes a pre-existing bug: `refundable` was
                        // `empty(policies)`, which is backwards — a rate WITH a free
                        // cancellation window normally DOES have a populated policies[]
                        // (the free layer + the penalty layers after it), so that check
                        // was marking genuinely non-refundable rates as refundable.
                        $cancellationPenalties = $rate['payment_options']['cancellation_penalties'] ?? [];
                        $cancellationPolicy = rh_parse_cancellation_policy($cancellationPenalties, $sessionCurrency);
                        $hasFreeCancellation = $cancellationPolicy['has_free_cancellation'];

                        $rateOptions[] = [
                            'option_id' => $bookHash,
                            'book_hash' => $bookHash,
                            'board_name' => ucfirst(str_replace('_', ' ', $rate['meal'] ?? 'room_only')),
                            'board_code' => strtoupper($rate['meal'] ?? 'RO'),
                            'meal_plan' => ucfirst(str_replace('_', ' ', $rate['meal'] ?? 'Room Only')),
                            'cancellation_free' => $hasFreeCancellation,
                            'refundable' => $hasFreeCancellation,
                            'breakfast_included' => in_array($rate['meal'] ?? '', ['breakfast', 'half_board', 'full_board', 'all_inclusive']),
                            'price_per_night' => round($pricePerNight, 2),
                            'total_price' => round($finalPrice, 2),
                            'original_price' => round($basePrice, 2),
                            'currency' => $sessionCurrency,
                            'adults' => $rate['adults'] ?? $rooms[0]['adults'] ?? 2,
                            'children' => count($rate['children'] ?? []),
                            'available_quantity' => $availableQty,
                            'allotment' => $allotment,
                            'rate_key' => $bookHash,
                            // ETG 3.5 — taxes/fees not included in the price, shown separately.
                            'excluded_taxes' => rh_extract_excluded_taxes($paymentType),
                            // ETG 3.7 — full layered cancellation policy, all times UTC.
                            'cancellation_policy' => $cancellationPolicy,
                            'cancellation_policies' => $cancellationPolicy['layers'],
                            'cancellation_text' => $cancellationPolicy['text'],
                            'free_cancellation_until' => $cancellationPolicy['free_cancellation_before'],
                            'free_cancellation_until_label' => $cancellationPolicy['free_cancellation_before_label'],
                        ];
                    }

                    if (empty($rateOptions)) continue;

                    // Amenities: prefer the matched static group's list; for an
                    // unmatched (new) block use the rate's own amenities_data.
                    $amenitySource = !empty($staticAmenities)
                        ? $staticAmenities
                        : ($firstRate['amenities_data'] ?? []);

                    $roomId = md5($groupKey);

                    $formattedRooms[] = [
                        'room_id' => $roomId,
                        'room_type_id' => $roomId,
                        'room_name' => $roomName,
                        'room_type_name' => $staticMatch['name_struct']['main_name']
                            ?? $firstRate['room_data_trans']['main_name']
                            ?? $roomName,
                        'description' => $firstRate['room_name_info']['original_rate_name'] ?? '',
                        'size_sqm' => null,
                        'max_adults' => $maxAdults,
                        // RateHawk's rg_ext carries no children-capacity field — 0
                        // (hides the badge) instead of a fabricated "2" on every room.
                        'max_children' => 0,
                        'bed_type' => $staticMatch['name_struct']['bedding_type']
                            ?? $firstRate['room_data_trans']['bedding_type']
                            ?? 'Standard',
                        // true = photos/amenities came from a real rg_ext static match
                        'rg_matched' => (bool) $staticMatch,
                        'amenities' => array_map(function ($amenity, $index) use ($ratehawkDb) {
                            return [
                                'id' => $index + 1,
                                'name' => rh_static_label($ratehawkDb, 'room_amenities', $amenity)
                            ];
                        }, $amenitySource, array_keys($amenitySource)),
                        'room_images' => $roomImages,
                        'room_main_image' => !empty($roomImages) ? $roomImages[0] : null,
                        'min_price_per_night' => round(min(array_column($rateOptions, 'price_per_night')), 2),
                        'options_count' => count($rateOptions),
                        'options' => $rateOptions
                    ];
                }
            }
        }
        
        if (empty($formattedRooms)) {
            $usingLivePricing = false;
        }

        // ============================================================================
        // BUILD RESPONSE
        // ============================================================================
        echo json_encode([
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'rooms' => $formattedRooms,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => $number_of_nights,
                'currency' => $sessionCurrency,
                'live_pricing' => $usingLivePricing
            ]
        ]);

    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }

    ob_end_flush();
});
