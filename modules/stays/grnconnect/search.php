<?php
// ============================================================================
// GRNCONNECT HOTEL SEARCH - LIVE AVAILABILITY (PARALLEL BATCHES)
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/search
//
// GRN-recommended flow:
//   1. Collect ALL hotel codes for the destination
//   2. Split into chunks of 100 (API max)
//   3. Fire Availability requests in parallel with:
//        rates=concise, cutoff_time=15000, more_results=true
//   4. Merge hotels by hotel_code as each batch returns
//   5. Persist per-batch search_id + more_results
//   6. Poll via poll_token → Refetch by Search ID until more_results=false
//
// Feature flag: GRNCONNECT_PARALLEL_SEARCH (default true). When false, falls
// back to a single sequential availability call (legacy).
// ============================================================================

if (!defined('GRNCONNECT_PARALLEL_SEARCH')) {
    define('GRNCONNECT_PARALLEL_SEARCH', true);
}
if (!defined('GRNCONNECT_AVAIL_CHUNK')) {
    define('GRNCONNECT_AVAIL_CHUNK', 100);
}
if (!defined('GRNCONNECT_CUTOFF_MS')) {
    define('GRNCONNECT_CUTOFF_MS', 15000);
}

$router->post('/stays/grnconnect/search', function () use ($db) {
    @set_time_limit(120);

    // Release the session lock so parallel supplier searches don't block.
    $sessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionData = $_SESSION;
        session_write_close();
    }

    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $emptyResult = function ($message = '', $extra = []) {
        echo json_encode(array_merge([
            'status'          => 'success',
            'results'         => [],
            'total'           => 0,
            'page'            => 1,
            'per_page'        => 0,
            'total_pages'     => 0,
            'search_complete' => true,
            'poll_token'      => '',
            'message'         => $message,
        ], $extra));
        exit;
    };

    try {
        require_once __DIR__ . '/api.php';

        $pollToken = trim((string)($_POST['poll_token'] ?? ''));

        // =====================================================================
        // POLL PATH — refetch unfinished batches by search_id
        // =====================================================================
        if ($pollToken !== '') {
            $state = grnconnect_search_session_load($pollToken);
            if (!$state) {
                $emptyResult('Search session expired or not found.', [
                    'search_complete' => true,
                    'poll_token'      => '',
                ]);
            }

            $module = grnconnect_module($db);
            if (!$module || empty($module['c1']) || empty($module['c2'])) {
                throw new Exception('GRNConnect module not configured properly');
            }
            $client = GRNConnectClient::fromModule($module);

            $batches      = is_array($state['batches'] ?? null) ? $state['batches'] : [];
            $masterByCode = is_array($state['hotels_by_code'] ?? null) ? $state['hotels_by_code'] : [];
            $localByCode  = is_array($state['local_by_code'] ?? null) ? $state['local_by_code'] : [];
            $destination  = (string)($state['destination'] ?? '');
            $currency     = (string)($state['currency'] ?? 'USD');
            $nights       = max(1, (int)($state['nights'] ?? 1));
            $resolvedMode = (string)($state['search_mode'] ?? '');
            $failedBatches = is_array($state['failed_batches'] ?? null) ? $state['failed_batches'] : [];

            $pendingIds = [];
            $pendingIdx = [];
            foreach ($batches as $i => $batch) {
                if (!empty($batch['more_results']) && !empty($batch['search_id'])) {
                    $pendingIds[] = (string)$batch['search_id'];
                    $pendingIdx[] = $i;
                }
            }

            $upserted = [];

            if (!empty($pendingIds)) {
                $refetchResults = $client->refetchBySearchIdParallel($pendingIds, 'concise');

                foreach ($refetchResults as $ri => $res) {
                    $bi = $pendingIdx[$ri] ?? null;
                    if ($bi === null || !isset($batches[$bi])) {
                        continue;
                    }

                    if (!$res['success'] && ($res['error'] ?? '') !== '') {
                        $err = [
                            'batch'     => (int)($batches[$bi]['batch'] ?? ($bi + 1)),
                            'search_id' => (string)($batches[$bi]['search_id'] ?? ''),
                            'error'     => (string)$res['error'],
                            'phase'     => 'refetch',
                        ];
                        $failedBatches[] = $err;
                        error_log('GRNConnect search refetch batch failed: ' . json_encode($err));
                        // Stop polling this batch so one failure does not hang the UI
                        $batches[$bi]['more_results'] = false;
                        $batches[$bi]['error'] = (string)$res['error'];
                        continue;
                    }

                    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
                    $sid  = (string)($data['search_id'] ?? $batches[$bi]['search_id'] ?? '');
                    $more = !empty($data['more_results']);
                    $apiHotels = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];

                    $batches[$bi]['search_id']    = $sid !== '' ? $sid : $batches[$bi]['search_id'];
                    $batches[$bi]['more_results'] = $more;

                    $changed = grnconnect_merge_batch_hotels(
                        $masterByCode,
                        $apiHotels,
                        $batches[$bi]['search_id'],
                        $localByCode,
                        $destination,
                        $currency,
                        $nights,
                        $module,
                        $db,
                        $resolvedMode
                    );
                    foreach ($changed as $item) {
                        $upserted[$item['hotel_id']] = $item;
                    }
                }
            }

            $complete = grnconnect_batches_complete($batches);

            $state['batches']        = $batches;
            $state['hotels_by_code'] = $masterByCode;
            $state['failed_batches'] = $failedBatches;
            grnconnect_search_session_save($pollToken, $state);

            $allResults = array_values($masterByCode);
            echo json_encode([
                'status'          => 'success',
                'results'         => array_values($upserted),
                'results_total'   => count($allResults),
                'total'           => (int)($state['content_total'] ?? count($allResults)),
                'page'            => 1,
                'per_page'        => count($allResults),
                'total_pages'     => 1,
                'search_complete' => $complete,
                'poll_token'      => $complete ? '' : $pollToken,
                'batches'         => array_map(function ($b) {
                    return [
                        'batch'        => (int)($b['batch'] ?? 0),
                        'search_id'    => (string)($b['search_id'] ?? ''),
                        'more_results' => !empty($b['more_results']),
                        'error'        => $b['error'] ?? null,
                    ];
                }, $batches),
                'failed_batches'  => $failedBatches,
                'search_mode'     => $resolvedMode,
                'search_meta'     => $state['search_meta'] ?? [],
                'search_params'   => $state['search_params'] ?? [],
                'parallel_search' => true,
            ]);
            exit;
        }

        // =====================================================================
        // INITIAL SEARCH
        // =====================================================================
        $destination     = trim($_POST['destination'] ?? $_POST['city'] ?? '');
        $destinationCode = trim($_POST['destination_code'] ?? '');
        $searchType      = strtolower(trim($_POST['search_type'] ?? $_POST['mode'] ?? 'auto'));
        $hotelCode       = trim($_POST['hotel_code'] ?? $_POST['hotel_id'] ?? '');
        $latitude        = $_POST['latitude'] ?? $_POST['lat'] ?? '';
        $longitude       = $_POST['longitude'] ?? $_POST['lng'] ?? $_POST['lon'] ?? '';
        $radius          = (float)($_POST['radius'] ?? $_POST['radius_km'] ?? 20);
        $checkin         = grnconnect_to_iso_date($_POST['checkin'] ?? '');
        $checkout        = grnconnect_to_iso_date($_POST['checkout'] ?? '');
        $roomsCount      = max(1, (int)($_POST['rooms'] ?? 1));
        $adults          = max(1, (int)($_POST['adults'] ?? 2));
        $children        = (int)($_POST['children'] ?? 0);
        $childAges       = $_POST['child_ages'] ?? [];
        $nationality     = strtoupper(substr(trim($_POST['nationality'] ?? $_POST['residency'] ?? 'US'), 0, 2));
        $currency        = $_POST['currency'] ?? $sessionData['app_currency'] ?? 'USD';

        // Prefer session meta from destination autocomplete when POST omits it.
        $meta = $sessionData['hotel_search_meta'] ?? [];
        if (is_array($meta) && !empty($meta)) {
            $metaType = strtolower((string)($meta['type'] ?? 'auto'));
            $metaName = strtolower(trim((string)($meta['name'] ?? '')));
            $destLower = strtolower(grnconnect_clean_city_name($destination));

            $metaMatchesDest = ($metaName !== '' && $destLower !== '' && (strpos($metaName, $destLower) !== false || strpos($destLower, $metaName) !== false));

            if ($metaMatchesDest) {
                if ($searchType === 'auto' && in_array($metaType, ['hotel', 'city', 'location', 'circle'], true)) {
                    $searchType = $metaType;
                }
                if ($searchType === 'hotel' && $hotelCode === '' && !empty($meta['hotel_code'])) {
                    $hotelCode = (string)$meta['hotel_code'];
                }
                if (in_array($searchType, ['city', 'auto'], true) && $destinationCode === '' && !empty($meta['destination_code'])) {
                    $destinationCode = (string)$meta['destination_code'];
                }
                if (in_array($searchType, ['circle', 'location'], true)) {
                    if ($latitude === '' && isset($meta['latitude']) && $meta['latitude'] !== '' && $meta['latitude'] !== null) {
                        $latitude = $meta['latitude'];
                    }
                    if ($longitude === '' && isset($meta['longitude']) && $meta['longitude'] !== '' && $meta['longitude'] !== null) {
                        $longitude = $meta['longitude'];
                    }
                    if ((!isset($_POST['radius']) && !isset($_POST['radius_km'])) && !empty($meta['radius'])) {
                        $radius = (float)$meta['radius'];
                    }
                }
            }
        }

        if (in_array($searchType, ['city', 'auto'], true)) {
            $hotelCode = '';
            $latitude = '';
            $longitude = '';
        }
        if ($searchType === 'hotel') {
            $latitude = '';
            $longitude = '';
        }

        if ($checkin === '' || $checkout === '') {
            throw new Exception('Missing required parameters: checkin, checkout');
        }
        if ($destination === '' && $destinationCode === '' && $hotelCode === '' && ($latitude === '' || $longitude === '')) {
            throw new Exception('Missing required parameters: destination, hotel_code, or latitude/longitude');
        }

        $module = grnconnect_module($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('GRNConnect module not configured properly');
        }
        $client = GRNConnectClient::fromModule($module);

        $contentDb = grnconnect_content_db($module);
        if (!$contentDb) {
            $emptyResult('GRNConnect content database not configured. Import hotels first.');
        }

        $resolveInput = [
            'search_type'      => $searchType,
            'destination'      => $destination,
            'destination_code' => $destinationCode,
            'hotel_code'       => $hotelCode,
            'latitude'         => $latitude,
            'longitude'        => $longitude,
            'radius'           => $radius > 0 ? $radius : 20,
        ];

        $useParallel = GRNCONNECT_PARALLEL_SEARCH;

        // ----- Resolve hotels ------------------------------------------------
        if ($useParallel) {
            $resolved = grnconnect_resolve_all_search_hotels($contentDb, $db, $resolveInput);
        } else {
            $page = max(1, (int)($_POST['page'] ?? 1));
            $resolved = grnconnect_resolve_search_hotels($contentDb, $db, $resolveInput, 50, $page);
        }

        $hotelRows    = $resolved['rows'];
        $totalCount   = (int)$resolved['total'];
        $resolvedMode = $resolved['mode'];

        if ($totalCount === 0 || empty($hotelRows)) {
            $emptyResult('No GRNConnect hotels found for this search.', [
                'search_mode' => $resolvedMode,
                'search_meta' => $resolved['meta'],
            ]);
        }

        $hotelCodes  = array_values(array_unique(array_filter(array_map('strval', array_column($hotelRows, 'hotel_code')))));
        $localByCode = [];
        foreach ($hotelRows as $row) {
            $localByCode[(string)$row['hotel_code']] = $row;
        }

        $rooms  = grnconnect_build_rooms_payload($_POST, $roomsCount, $adults, $children, $childAges);
        $nights = grnconnect_nights($checkin, $checkout);

        // =====================================================================
        // LEGACY FALLBACK (feature flag off): single 50-hotel page request
        // =====================================================================
        if (!$useParallel) {
            $payload = [
                'hotel_codes'        => $hotelCodes,
                'checkin'            => $checkin,
                'checkout'           => $checkout,
                'client_nationality' => $nationality,
                'currency'           => $currency,
                'rates'              => 'concise',
                'rooms'              => $rooms,
            ];
            $res = $client->availability($payload);
            if (!$res['success'] && $res['error'] !== '') {
                throw new Exception('GRNConnect availability error: ' . $res['error']);
            }
            $sid = $res['data']['search_id'] ?? '';
            $masterByCode = [];
            grnconnect_merge_batch_hotels(
                $masterByCode,
                $res['data']['hotels'] ?? [],
                $sid,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode
            );
            $results = array_values($masterByCode);
            $batchSize = 50;
            $page = max(1, (int)($_POST['page'] ?? 1));
            echo json_encode([
                'status'          => 'success',
                'results'         => $results,
                'total'           => $totalCount,
                'page'            => $page,
                'per_page'        => $batchSize,
                'total_pages'     => (int)ceil($totalCount / $batchSize),
                'search_complete' => true,
                'poll_token'      => '',
                'search_mode'     => $resolvedMode,
                'search_meta'     => $resolved['meta'],
                'parallel_search' => false,
                'search_params'   => [
                    'checkin'     => $checkin,
                    'checkout'    => $checkout,
                    'rooms'       => $roomsCount,
                    'nights'      => $nights,
                    'search_type' => $resolvedMode,
                    'radius_km'   => $radius > 0 ? $radius : 20,
                ],
            ]);
            exit;
        }

        // =====================================================================
        // PARALLEL AVAILABILITY — chunks of 100
        // =====================================================================
        $chunkSize = max(1, min(100, (int)GRNCONNECT_AVAIL_CHUNK));
        $chunks    = array_chunk($hotelCodes, $chunkSize);
        $cutoffMs  = max(3000, min(50000, (int)GRNCONNECT_CUTOFF_MS));
        // Allow GRN to reach cutoff_time plus network/processing slack
        $reqTimeout = (int)ceil($cutoffMs / 1000) + 15;

        $payloads = [];
        foreach ($chunks as $chunk) {
            $payloads[] = [
                'hotel_codes'        => array_values($chunk),
                'checkin'            => $checkin,
                'checkout'           => $checkout,
                'client_nationality' => $nationality,
                'currency'           => $currency,
                'rates'              => 'concise',
                'cutoff_time'        => $cutoffMs,
                'more_results'       => true,
                'rooms'              => $rooms,
            ];
        }

        $responses = $client->availabilityParallel($payloads, $reqTimeout);

        $masterByCode  = [];
        $batches       = [];
        $failedBatches = [];
        $upserted      = [];

        foreach ($responses as $i => $res) {
            $batchNum = $i + 1;
            $chunkCodes = $chunks[$i] ?? [];

            if (!$res['success'] && ($res['error'] ?? '') !== '') {
                $err = [
                    'batch'       => $batchNum,
                    'hotel_count' => count($chunkCodes),
                    'error'       => (string)$res['error'],
                    'phase'       => 'availability',
                ];
                $failedBatches[] = $err;
                error_log('GRNConnect search availability batch failed: ' . json_encode($err));
                $batches[] = [
                    'batch'        => $batchNum,
                    'search_id'    => '',
                    'more_results' => false,
                    'hotel_codes'  => $chunkCodes,
                    'error'        => (string)$res['error'],
                ];
                continue;
            }

            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $sid  = (string)($data['search_id'] ?? '');
            $more = !empty($data['more_results']);
            $apiHotels = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];

            $batches[] = [
                'batch'        => $batchNum,
                'search_id'    => $sid,
                'more_results' => $more && $sid !== '',
                'hotel_codes'  => $chunkCodes,
                'error'        => null,
            ];

            $changed = grnconnect_merge_batch_hotels(
                $masterByCode,
                $apiHotels,
                $sid,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode
            );
            foreach ($changed as $item) {
                $upserted[$item['hotel_id']] = $item;
            }
        }

        $complete = grnconnect_batches_complete($batches);
        $token    = '';

        $searchParams = [
            'checkin'     => $checkin,
            'checkout'    => $checkout,
            'rooms'       => $roomsCount,
            'nights'      => $nights,
            'search_type' => $resolvedMode,
            'radius_km'   => $radius > 0 ? $radius : 20,
        ];

        if (!$complete) {
            $token = bin2hex(random_bytes(16));
            // Slim local map for poll enrichment (only fields used by formatter)
            $slimLocal = [];
            foreach ($localByCode as $code => $row) {
                $slimLocal[$code] = [
                    'hotel_code'   => $row['hotel_code'] ?? $code,
                    'name'         => $row['name'] ?? '',
                    'address'      => $row['address'] ?? '',
                    'country_code' => $row['country_code'] ?? '',
                    'latitude'     => $row['latitude'] ?? null,
                    'longitude'    => $row['longitude'] ?? null,
                    'category'     => $row['category'] ?? 0,
                    'images'       => $row['images'] ?? null,
                    'acc_name'     => $row['acc_name'] ?? 'Hotel',
                    'facilities'   => $row['facilities'] ?? '',
                    'distance_km'  => $row['distance_km'] ?? null,
                ];
            }

            grnconnect_search_session_save($token, [
                'created_at'     => time(),
                'destination'    => $destination,
                'currency'       => $currency,
                'nights'         => $nights,
                'search_mode'    => $resolvedMode,
                'search_meta'    => $resolved['meta'],
                'search_params'  => $searchParams,
                'content_total'  => $totalCount,
                'local_by_code'  => $slimLocal,
                'hotels_by_code' => $masterByCode,
                'batches'        => $batches,
                'failed_batches' => $failedBatches,
            ]);
        }

        $allResults = array_values($masterByCode);

        echo json_encode([
            'status'          => 'success',
            'results'         => $allResults,
            'results_total'   => count($allResults),
            'total'           => $totalCount,
            'page'            => 1,
            'per_page'        => count($allResults),
            'total_pages'     => 1,
            'search_complete' => $complete,
            'poll_token'      => $token,
            'batches'         => array_map(function ($b) {
                return [
                    'batch'        => (int)($b['batch'] ?? 0),
                    'search_id'    => (string)($b['search_id'] ?? ''),
                    'more_results' => !empty($b['more_results']),
                    'error'        => $b['error'] ?? null,
                ];
            }, $batches),
            'failed_batches'  => $failedBatches,
            'batch_count'     => count($batches),
            'hotel_codes'     => count($hotelCodes),
            'search_mode'     => $resolvedMode,
            'search_meta'     => $resolved['meta'],
            'search_params'   => $searchParams,
            'parallel_search' => true,
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status'          => 'error',
            'message'         => $e->getMessage(),
            'results'         => [],
            'search_complete' => true,
            'poll_token'      => '',
        ]);
    }
    exit;
});

if (!function_exists('grnconnect_build_rooms_payload')) {
    /**
     * Build the GRN "rooms" occupancy array. Uses rooms_data JSON when the
     * frontend supplies it; otherwise replicates the flat adults/children.
     */
    function grnconnect_build_rooms_payload($post, $roomsCount, $adults, $children, $childAges)
    {
        $rooms = [];

        if (!empty($post['rooms_data'])) {
            $decoded = is_array($post['rooms_data']) ? $post['rooms_data'] : json_decode($post['rooms_data'], true);
            if (is_array($decoded) && !empty($decoded)) {
                // Prefer shared normalizer (handles childAges camelCase + infant age 1).
                if (function_exists('grnconnect_rooms_from_input')) {
                    return grnconnect_rooms_from_input($decoded);
                }
                foreach ($decoded as $r) {
                    $room = ['adults' => max(1, (int)($r['adults'] ?? 2))];
                    $rawAges = $r['child_ages'] ?? $r['children_ages'] ?? $r['childAges'] ?? [];
                    if (!is_array($rawAges)) {
                        $rawAges = [];
                    }
                    $childCount = (int)($r['children'] ?? 0);
                    if ($childCount < 1) {
                        $childCount = count($rawAges);
                    }
                    $ages = [];
                    foreach (array_slice($rawAges, 0, max(0, $childCount)) as $a) {
                        $a = (int)$a;
                        $ages[] = $a >= 1 ? min(17, $a) : 1;
                    }
                    while (count($ages) < $childCount) {
                        $ages[] = 1;
                    }
                    if (!empty($ages)) {
                        $room['children_ages'] = $ages;
                    }
                    $rooms[] = $room;
                }
                return $rooms;
            }
        }

        if (!is_array($childAges)) {
            $childAges = ($childAges === '' || $childAges === null) ? [] : explode(',', $childAges);
        }
        if (function_exists('grnconnect_normalize_child_ages')) {
            $childAges = grnconnect_normalize_child_ages($childAges, (int)$children);
        } else {
            $childAges = array_values(array_map(function ($a) {
                $a = (int)$a;
                return $a >= 1 ? min(17, $a) : 1;
            }, array_slice($childAges, 0, max(0, (int)$children))));
            while (count($childAges) < (int)$children) {
                $childAges[] = 1;
            }
        }

        for ($i = 0; $i < $roomsCount; $i++) {
            $room = ['adults' => $adults];
            if ($i === 0 && !empty($childAges)) {
                $room['children_ages'] = $childAges;
            }
            $rooms[] = $room;
        }
        return $rooms;
    }
}
