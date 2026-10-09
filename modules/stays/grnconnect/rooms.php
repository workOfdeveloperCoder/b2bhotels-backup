<?php
// ============================================================================
// GRNCONNECT HOTEL ROOMS — atomic packages + compatible single rates
// ============================================================================
// A rate_key is one atomic package. group_code only identifies rates that may
// be combined; it is not a package identifier. A package may contain different
// room types in its rooms[] array and must be selected/booked as one unit.
// ============================================================================

$router->post('/stays/grnconnect/rooms', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $send = function ($data) {
        if (ob_get_level()) ob_clean();
        echo json_encode($data);
        exit;
    };

    try {
        require_once __DIR__ . '/api.php';
        if (is_file(__DIR__ . '/selection.php')) {
            require_once __DIR__ . '/selection.php';
        }

        $input       = json_decode(file_get_contents('php://input'), true) ?: [];
        $hotelId     = (string)($input['hotel_id'] ?? '');
        $checkin     = grnconnect_to_iso_date($input['checkin'] ?? '');
        $checkout    = grnconnect_to_iso_date($input['checkout'] ?? '');
        $nationality = strtoupper(substr(trim($input['nationality'] ?? 'US'), 0, 2));
        $currency    = $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';

        if ($hotelId === '') $send(['success' => false, 'message' => 'Missing required parameter: hotel_id']);
        if ($checkin === '' || $checkout === '') $send(['success' => false, 'message' => 'Missing checkin/checkout dates']);

        $module = grnconnect_module($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            $send(['success' => false, 'message' => 'GRNConnect module not configured']);
        }
        $client = GRNConnectClient::fromModule($module);

        $rooms  = grnconnect_rooms_from_request($input);
        $nights = grnconnect_nights($checkin, $checkout);
        $searchId = grnconnect_resolve_search_id($input);
        $requestedRoomCount = max(1, count($rooms));

        $collectRates = static function ($sid, $hcode) use ($client) {
            $all = [];
            $best = null;

            $res = $client->refetch($sid, $hcode, $bundled = true);
            if ($res['success']) {
                $parsed = grnconnect_parse_refetch_hotel($res, $sid);
                if (!$best) $best = $parsed;
                foreach ($parsed['rates'] ?? [] as $rate) {
                    if (is_array($rate)) {
                        $key = (string)($rate['rate_key'] ?? '');
                        if ($key !== '') {
                            $all[$key] = $rate;
                        } else {
                            $all[] = $rate;
                        }
                    }
                }
            }

            return [$best, array_values($all)];
        };

        $parsed = null;
        $rates  = [];

        if ($searchId !== '') {
            [$parsed, $rates] = $collectRates($searchId, $hotelId);
        }

        // Fresh concise availability when sid missing/stale or no rates
        if (empty($rates)) {
            $availRes = $client->availability([
                'hotel_codes'        => [$hotelId],
                'checkin'            => $checkin,
                'checkout'           => $checkout,
                'client_nationality' => $nationality,
                'currency'           => $currency,
                'rates'              => 'concise',
                'rooms'              => $rooms,
            ], 45);

            if (!$availRes['success'] && ($availRes['error'] ?? '') !== '') {
                $send(['success' => false, 'message' => 'GRNConnect availability error: ' . $availRes['error']]);
            }

            $freshSid = (string)($availRes['data']['search_id'] ?? '');
            if ($freshSid === '') {
                // Still try hotels[0] rates from concise if present
                $hotel0 = $availRes['data']['hotels'][0] ?? null;
                if (!is_array($hotel0) || empty($hotel0['rates'])) {
                    $send(['success' => false, 'message' => 'GRNConnect did not return a search session. Please search again.']);
                }
                $rates = $hotel0['rates'];
                $parsed = [
                    'search_id' => '',
                    'hotel'     => $hotel0,
                    'rates'     => $rates,
                    'city_code' => (string)($hotel0['city_code'] ?? ''),
                ];
            } else {
                [$parsed, $rates] = $collectRates($freshSid, $hotelId);
                $searchId = $freshSid;

                if (empty($rates)) {
                    $hotel0 = $availRes['data']['hotels'][0] ?? null;
                    if (is_array($hotel0) && !empty($hotel0['rates'])) {
                        $rates = $hotel0['rates'];
                        $parsed = [
                            'search_id' => $freshSid,
                            'hotel'     => $hotel0,
                            'rates'     => $rates,
                            'city_code' => (string)($hotel0['city_code'] ?? ''),
                        ];
                    }
                }
            }
        }

        $searchId = (string)(($parsed['search_id'] ?? null) ?: $searchId);
        $cityCode = (string)($parsed['city_code'] ?? '');
        $hotel    = is_array($parsed['hotel'] ?? null) ? $parsed['hotel'] : [];
        $hotelSafe2stay = grnconnect_format_safe2stay($hotel);

        // Keep every rate. Bundled rates are atomic packages; single-room rates
        // remain available for compatible mix-and-match within one group_code.
        $allocSize = static function (array $rate) {
            if (function_exists('grnconnect_rate_allocation_size')) {
                return grnconnect_rate_allocation_size($rate);
            }
            $fromRooms = is_array($rate['rooms'] ?? null) ? count($rate['rooms']) : 0;
            $fromNo    = (int)($rate['no_of_rooms'] ?? 0);
            return max(1, $fromRooms, $fromNo);
        };

        $singleRates = [];
        $allRates    = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            $allRates[] = $rate;
            if ($allocSize($rate) === 1) {
                $singleRates[] = $rate;
            }
        }

        $ratesToShow = $allRates;
        $hasSingles  = !empty($singleRates);
        $hasPackages = count($allRates) > count($singleRates);
        $mixable     = $hasSingles && $requestedRoomCount > 1;

        $optionCtx = [
            'module'          => $module,
            'db'              => $db,
            'currency'        => $currency,
            'nights'          => $nights,
            'search_id'       => $searchId,
            'hotel_id'        => $hotelId,
            'city_code'       => $cityCode,
            'checkin'         => $checkin,
            'checkout'        => $checkout,
            'hotel_safe2stay' => $hotelSafe2stay,
            'mixable'         => $mixable,
        ];

        // Images
        $roomImages = [];
        $mainImage  = '';
        $contentDb  = grnconnect_content_db($module);
        if ($contentDb) {
            $resolved   = grnconnect_resolve_hotel_images($client, $contentDb, $hotelId);
            $roomImages = $resolved['urls'] ?? [];
            $mainImage  = $resolved['main_image'] ?? '';
        } else {
            $imagesResponse = $client->hotelImages($hotelId);
            foreach ($imagesResponse['data']['images']['regular'] ?? [] as $image) {
                $url = $image['url'] ?? '';
                if ($url === '') continue;
                if (!empty($image['main_image']) && $mainImage === '') {
                    $mainImage = $url;
                }
                $roomImages[] = $url;
            }
            $roomImages = array_values(array_unique($roomImages));
            if ($mainImage === '') {
                $mainImage = $roomImages[0] ?? '';
            }
        }

        // Build UI cards. Every multi-room rate_key is one independent bundle
        // card; never group different packages merely because their room names
        // look the same. Single-room rates retain the classic room grouping.
        $grouped = [];
        $bundleNumber = 0;
        foreach ($ratesToShow as $rate) {
            $rateSize = $allocSize($rate);
            if ($rateSize === 1) {
                $roomInfo = $rate['rooms'][0] ?? [];
                $parts    = function_exists('grnconnect_room_display_parts')
                    ? grnconnect_room_display_parts($roomInfo)
                    : ['title' => 'Room', 'detail' => ''];
                $roomName = $parts['title'] ?: 'Room';
                $groupKey = 'room|' . $roomName;
                if (!isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [
                        'display_name'  => $roomName,
                        'package_label' => '',
                        'is_package'    => false,
                        'bundle_number' => 0,
                        'rates'         => [],
                    ];
                }
            } else {
                $packageLabel = function_exists('grnconnect_rate_package_label')
                    ? grnconnect_rate_package_label($rate)
                    : (function_exists('grnconnect_rate_display_name')
                        ? grnconnect_rate_display_name($rate)
                        : 'Room');
                if ($packageLabel === '') $packageLabel = 'Room package';

                $bundleNumber++;
                $rateKey = trim((string)($rate['rate_key'] ?? ''));
                $groupKey = 'bundle|' . ($rateKey !== '' ? $rateKey : $bundleNumber);
                $roomName = 'Bundle ' . $bundleNumber;
                $grouped[$groupKey] = [
                    'display_name'  => $roomName,
                    'package_label' => $packageLabel,
                    'is_package'    => true,
                    'bundle_number' => $bundleNumber,
                    'rates'         => [],
                ];
            }
            if ($roomName === '') $roomName = 'Room';
            $grouped[$groupKey]['rates'][] = $rate;
        }

        $formattedRooms = [];
        foreach ($grouped as $groupKey => $groupData) {
            $roomName     = (string)$groupData['display_name'];
            $roomRates    = $groupData['rates'];
            $packageLabel = (string)$groupData['package_label'];
            $groupIsPackage = !empty($groupData['is_package']);
            $options      = [];
            $maxAdults    = 1;
            $maxChildren  = 0;
            $seenRateKeys = [];

            foreach ($roomRates as $rate) {
                $rateKey = (string)($rate['rate_key'] ?? '');
                if ($rateKey !== '' && isset($seenRateKeys[$rateKey])) continue;
                if ($rateKey !== '') $seenRateKeys[$rateKey] = true;

                $basePrice = grnconnect_rate_price_total($rate);
                if ($basePrice <= 0) continue;

                $optionCtx['room_name'] = $groupIsPackage && $packageLabel !== ''
                    ? $packageLabel
                    : $roomName;
                $built = grnconnect_build_room_option($rate, $optionCtx);
                if (empty($built)) continue;

                if (function_exists('grnconnect_enrich_room_option')) {
                    $built = grnconnect_enrich_room_option($built, $rate, $requestedRoomCount, 1);
                } else {
                    $size = $allocSize($rate);
                    $built['allocation_size'] = $size;
                    $built['rooms_in_rate']   = $size;
                    $built['mixable']         = $mixable && $size === 1;
                }

                $built['available_quantity'] = 1;
                $maxAdults   = max($maxAdults, (int)($built['adults'] ?? 1), (int)($built['for_adults'] ?? 1));
                $maxChildren = max($maxChildren, (int)($built['children'] ?? 0), (int)($built['for_children'] ?? 0));
                $options[]   = $built;
            }

            if (empty($options)) continue;

            $firstRoomInfo = $options[0]['rate_rooms'][0] ?? ($roomRates[0]['rooms'][0] ?? []);
            $firstPackageRooms = is_array($options[0]['package_rooms'] ?? null)
                ? $options[0]['package_rooms']
                : (is_array($options[0]['rate_rooms'] ?? null) ? $options[0]['rate_rooms'] : []);
            $isPackage = (int)($options[0]['allocation_size'] ?? $options[0]['rooms_in_rate'] ?? 1) > 1;
            $packageRoomCount = $isPackage
                ? max(1, (int)($options[0]['rooms_in_rate'] ?? 0), count($firstPackageRooms))
                : 1;
            $packageAdults = 0;
            $packageChildren = 0;
            if ($isPackage) {
                foreach ($firstPackageRooms as $packageRoom) {
                    if (!is_array($packageRoom)) continue;
                    $packageAdults += max(0, (int)($packageRoom['no_of_adults'] ?? 0));
                    $packageChildren += max(0, (int)($packageRoom['no_of_children'] ?? 0));
                }
            }

            $formattedRooms[] = [
                'room_id'             => md5($hotelId . '|' . $groupKey),
                'room_type_id'        => md5($hotelId . '|' . $groupKey),
                'room_name'           => $roomName,
                'room_type_name'      => $isPackage
                    ? ($packageLabel !== '' ? $packageLabel : ($packageRoomCount . '-room package'))
                    : ($firstRoomInfo['room_type'] ?? $roomName),
                'description'         => $isPackage
                    ? 'These rooms are supplied and selected together as one package.'
                    : (string)($firstRoomInfo['description'] ?? ''),
                'max_adults'          => $isPackage && $packageAdults > 0 ? $packageAdults : $maxAdults,
                'max_children'        => $isPackage ? $packageChildren : $maxChildren,
                'bed_type'            => function_exists('grnconnect_bed_type')
                    ? grnconnect_bed_type($firstRoomInfo)
                    : 'Standard',
                'amenities'           => [],
                'room_images'         => $roomImages,
                'room_main_image'     => $mainImage,
                'min_price_per_night' => round(min(array_column($options, 'price_per_night')), 2),
                'options_count'       => count($options),
                'options'             => $options,
                'is_package'          => count(array_filter($options, static function ($option) {
                    return (int)($option['allocation_size'] ?? $option['rooms_in_rate'] ?? 1) > 1;
                })) > 0,
                'bundle_number'       => (int)($groupData['bundle_number'] ?? 0),
                'bundle_label'        => $isPackage ? $roomName : '',
                'package_label'       => $packageLabel,
                'package_room_count'  => $packageRoomCount,
                'package_rooms'       => $firstPackageRooms,
            ];
        }

        $roomsConfig = array_map(static function ($r) {
            $ages = $r['children_ages'] ?? [];
            return [
                'adults'     => (int)($r['adults'] ?? 2),
                'children'   => is_array($ages) ? count($ages) : 0,
                'child_ages' => is_array($ages) ? array_values(array_map('intval', $ages)) : [],
            ];
        }, $rooms);

        $send([
            'success' => true,
            'data'    => [
                'hotel_id'       => $hotelId,
                'rooms'          => $formattedRooms,
                'checkin'        => $checkin,
                'checkout'       => $checkout,
                'nights'         => $nights,
                'currency'       => $currency,
                'live_pricing'   => !empty($formattedRooms),
                'search_id'      => $searchId,
                'mixable'        => $mixable,
                'bundled'        => $hasPackages && $requestedRoomCount > 1,
                'selection_mode' => $hasPackages && $hasSingles
                    ? 'hybrid'
                    : ($mixable ? 'guest_assign' : ($requestedRoomCount > 1 ? 'package' : 'classic')),
                'rooms_config'   => $roomsConfig,
                'rates_found'    => count($allRates),
                'singles_found'  => count($singleRates),
            ],
        ]);

    } catch (\Throwable $e) {
        $send(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
});

// ----------------------------------------------------------------------------
// HELPERS (shared with actions/issue.php)
// ----------------------------------------------------------------------------

if (!function_exists('grnconnect_pack_rate')) {
    function grnconnect_pack_rate(array $data)
    {
        return 'GRN.' . base64_encode(json_encode($data));
    }
}

if (!function_exists('grnconnect_unpack_rate')) {
    function grnconnect_unpack_rate($token)
    {
        if (!is_string($token) || $token === '') return null;
        if (strpos($token, 'GRN.') === 0) $token = substr($token, 4);
        $decoded = json_decode(base64_decode($token), true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('grnconnect_normalize_child_ages')) {
    /**
     * GRN children_ages must be integers 1–17.
     * Infants are age 1 (no separate infant field). Length must match child count.
     */
    function grnconnect_normalize_child_ages($ages, $childCount = null)
    {
        if (!is_array($ages)) {
            $ages = [];
        }
        $normalized = [];
        foreach ($ages as $a) {
            $a = (int)$a;
            $normalized[] = $a >= 1 ? min(17, $a) : 1;
        }

        $count = $childCount === null ? count($normalized) : max(0, (int)$childCount);
        if ($count < 1) {
            return [];
        }
        if (count($normalized) < $count) {
            while (count($normalized) < $count) {
                $normalized[] = 1;
            }
        } elseif (count($normalized) > $count) {
            $normalized = array_slice($normalized, 0, $count);
        }
        return array_values($normalized);
    }
}

if (!function_exists('grnconnect_rooms_from_input')) {
    function grnconnect_rooms_from_input($roomsInput)
    {
        if (!is_array($roomsInput)) {
            $count = max(1, (int)$roomsInput);
            $roomsInput = array_fill(0, $count, ['adults' => 2, 'children' => 0]);
        } elseif (!empty($roomsInput) && !isset($roomsInput[0])) {
            $roomsInput = [$roomsInput];
        }

        $rooms = [];
        foreach ($roomsInput as $r) {
            $room = ['adults' => max(1, (int)($r['adults'] ?? 2))];
            $rawAges = $r['child_ages'] ?? $r['children_ages'] ?? $r['childAges'] ?? [];
            if (!is_array($rawAges)) {
                $rawAges = [];
            }
            $childCount = (int)($r['children'] ?? 0);
            if ($childCount < 1) {
                $childCount = count($rawAges);
            }
            $ages = grnconnect_normalize_child_ages($rawAges, $childCount);
            if (!empty($ages)) {
                $room['children_ages'] = $ages;
            }
            $rooms[] = $room;
        }
        return empty($rooms) ? [['adults' => 2]] : $rooms;
    }
}

if (!function_exists('grnconnect_rate_taxes')) {
    function grnconnect_rate_taxes($rate)
    {
        $taxes = 0.0;
        if (!empty($rate['price_details']) && is_array($rate['price_details'])) {
            foreach ($rate['price_details'] as $group) {
                if (!is_array($group)) continue;
                foreach ($group as $charge) {
                    if (isset($charge['amount']) && empty($charge['included']) && ($charge['name'] ?? '') !== 'Total') {
                        $taxes += (float)$charge['amount'];
                    }
                }
            }
        }
        return round($taxes, 2);
    }
}

if (!function_exists('grnconnect_bed_type')) {
    function grnconnect_bed_type($roomInfo)
    {
        if (!empty($roomInfo['bed_types']) && is_array($roomInfo['bed_types'])) {
            $first = $roomInfo['bed_types'][0] ?? [];
            if (!empty($first['type'])) return $first['type'];
        }
        return 'Standard';
    }
}

if (!function_exists('grnconnect_room_display_parts')) {
    /**
     * Normalize GRN room title/description so the UI does not show repeated
     * occupancy tags or duplicated bed text in the main room heading.
     *
     * @return array{title:string, detail:string}
     */
    function grnconnect_room_display_parts($roomInfo)
    {
        if (!is_array($roomInfo)) {
            return ['title' => 'Room', 'detail' => ''];
        }

        $roomType = trim((string)($roomInfo['room_type'] ?? ''));
        $desc     = trim((string)($roomInfo['description'] ?? ''));

        $title  = '';
        $detail = '';

        if ($roomType !== '' && preg_match('/^(.*?)\s*\((.*?)\)\s*$/', $roomType, $m)) {
            $title  = trim((string)$m[1]);
            $detail = trim((string)$m[2]);
        }

        if ($title === '') {
            $title = $roomType !== '' ? $roomType : $desc;
        }

        if ($detail === '' && $desc !== '') {
            $descClean = preg_replace('/\s+/', ' ', $desc);
            if ($title !== '' && stripos($descClean, $title) === 0) {
                $detail = trim(substr($descClean, strlen($title)));
            } elseif (strcasecmp($descClean, $title) !== 0) {
                $detail = trim($descClean);
            }
        }

        $title  = trim($title) !== '' ? trim($title) : 'Room';
        $detail = trim((string)$detail);

        if ($detail !== '') {
            $detail = preg_replace('/\s*\/\s*/', '/', $detail);
            $detail = preg_replace('/\s+/', ' ', $detail);
        }

        return ['title' => $title, 'detail' => $detail];
    }
}

if (!function_exists('grnconnect_rate_package_label')) {
    function grnconnect_rate_package_label(array $rate)
    {
        $roomsInfo = $rate['rooms'] ?? [];
        if (!is_array($roomsInfo) || empty($roomsInfo)) return 'Room';
        $titles = [];
        $counts = [];
        foreach ($roomsInfo as $roomInfo) {
            if (!is_array($roomInfo)) continue;
            $parts = function_exists('grnconnect_room_display_parts')
                ? grnconnect_room_display_parts($roomInfo)
                : ['title' => trim((string)($roomInfo['room_type'] ?? ($roomInfo['description'] ?? 'Room')))];
            $name = trim((string)($parts['title'] ?? 'Room'));
            if ($name === '') $name = 'Room';
            if (!isset($counts[$name])) {
                $titles[] = $name;
                $counts[$name] = 0;
            }
            $counts[$name]++;
        }
        if (empty($titles)) return 'Room';
        return implode(' + ', array_map(static function ($name) use ($counts) {
            return $name . ($counts[$name] > 1 ? ' × ' . $counts[$name] : '');
        }, $titles));
    }
}
