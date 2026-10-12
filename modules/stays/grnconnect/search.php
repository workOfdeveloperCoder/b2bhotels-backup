<?php
// ============================================================================
// GRNCONNECT HOTEL SEARCH - LIVE AVAILABILITY (STREAMING WAVES)
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/search
//
// GRN docs (fastest max hotels):
//   POST api/v3/hotels/availability — hotel_codes REQUIRED, max 100 per call.
//   Split destinations into 100-code batches and send ALL of them in parallel.
//   cutoff_time default 15000ms (min 3000 multi-hotel, max 50000).
//   more_results: true → leftover rates via GET api/v3/hotels/availability/{search_id}
//   until more_results is false. Listing uses rates: concise.
// All 100-code batches go out in ONE curl_multi (wall clock ≈ cutoff_time).
// Hotels stream as each handle finishes. Leftover GET uses the remaining budget.
// Keep the stream open long enough to drain GRN's more_results pages.
// ============================================================================

if (!defined('GRNCONNECT_PARALLEL_SEARCH')) {
    define('GRNCONNECT_PARALLEL_SEARCH', true);
}
if (!defined('GRNCONNECT_AVAIL_CHUNK')) {
    define('GRNCONNECT_AVAIL_CHUNK', 100);
}
if (!defined('GRNCONNECT_CUTOFF_MS')) {
    // GRN searches until this cutoff, then returns every hotel it found.
    // 15s is the longest wait. Batches still run together, so this is one wait, not 13.
    define('GRNCONNECT_CUTOFF_MS', 15000);
}
if (!defined('GRNCONNECT_FIRST_CUTOFF_MS')) {
    define('GRNCONNECT_FIRST_CUTOFF_MS', 8000);
}
if (!defined('GRNCONNECT_SEARCH_MAX_HOTELS')) {
    define('GRNCONNECT_SEARCH_MAX_HOTELS', 5000);
}
if (!defined('GRNCONNECT_MAX_CONCURRENT')) {
    // Docs: send all 100-code batches in parallel. 50 × 100 = 5000 hotels.
    define('GRNCONNECT_MAX_CONCURRENT', 50);
}
if (!defined('GRNCONNECT_REFETCH_TIMEOUT')) {
    define('GRNCONNECT_REFETCH_TIMEOUT', 10);
}
if (!defined('GRNCONNECT_STREAM_BUDGET_S')) {
    // Allow initial availability, retries, and repeated leftover GETs to finish.
    define('GRNCONNECT_STREAM_BUDGET_S', 50);
}
if (!defined('GRNCONNECT_CLAIM_SIZE')) {
    // One worker claims every pending 100-code POST (curl_multi). Not 1-at-a-time.
    define('GRNCONNECT_CLAIM_SIZE', 50);
}

$router->post('/stays/grnconnect/search', function () use ($db) {
    @ignore_user_abort(false);
    @set_time_limit(55);

    // Session may already be closed by modules/index.php; still copy for reads.
    $sessionData = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $pollTokenIn = trim((string)($_POST['poll_token'] ?? ''));
    $wantStream = $pollTokenIn === '' && (
        (string)($_POST['stream'] ?? '') === '1'
        || (string)($_GET['stream'] ?? '') === '1'
    );

    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!$wantStream) {
        ob_start();
        header('Content-Type: application/json');
    }

    $emptyResult = function ($message = '', $extra = []) use ($wantStream) {
        $body = array_merge([
            'status'          => 'success',
            'results'         => [],
            'total'           => 0,
            'page'            => 1,
            'per_page'        => 0,
            'total_pages'     => 0,
            'search_complete' => true,
            'poll_token'      => '',
            'message'         => $message,
        ], $extra);
        if ($wantStream) {
            grnconnect_stream_headers();
            grnconnect_stream_emit(array_merge($body, [
                'type' => 'done',
                'stream' => true,
            ]));
            exit;
        }
        echo json_encode($body);
        exit;
    };

    try {
        require_once __DIR__ . '/api.php';

        $pollToken = trim((string)($_POST['poll_token'] ?? ''));

        // =====================================================================
        // POLL PATH — claim a few batches, call GRN without holding the lock
        // so other browser workers can run in parallel.
        // =====================================================================
        if ($pollToken !== '') {
            if (connection_aborted()) {
                exit;
            }
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

            $claimSize = defined('GRNCONNECT_CLAIM_SIZE') ? (int)GRNCONNECT_CLAIM_SIZE : 50;
            $claimSize = max(1, min(50, $claimSize));
            $claimedWave = [];
            $claimedRefetch = [];
            $upserted = [];
            $snapshotAll = false;

            $lock = grnconnect_search_try_lock($pollToken, true);
            if ($lock) {
                try {
                    $fresh = grnconnect_search_session_load($pollToken);
                    if (is_array($fresh)) {
                        $state = $fresh;
                    }
                    $contentDbPoll = grnconnect_content_db($module);
                    list($claimedWave, $claimedRefetch) = grnconnect_claim_search_work($state, $claimSize);
                    if (empty($claimedWave) && empty($claimedRefetch)) {
                        grnconnect_append_catalog_page($state, $contentDbPoll, $db);
                        if (!empty($state['pending_payloads'])) {
                            list($claimedWave, $claimedRefetch) = grnconnect_claim_search_work($state, $claimSize);
                        }
                    }
                    grnconnect_search_session_save($pollToken, $state, false);
                } finally {
                    grnconnect_search_unlock($lock);
                }
            } else {
                $snapshotAll = true;
            }

            if (!$snapshotAll && (!empty($claimedWave) || !empty($claimedRefetch))) {
                $reqTimeout = (int)($state['req_timeout'] ?? grnconnect_search_req_timeout());
                if (!empty($claimedWave[0]['cutoff_time'])) {
                    $reqTimeout = max(5, (int)ceil(((int)$claimedWave[0]['cutoff_time']) / 1000) + 2);
                } elseif (empty($claimedWave)) {
                    $reqTimeout = grnconnect_refetch_timeout();
                }
                $reqTimeout = min(55, max(5, $reqTimeout));
                $mixed = $client->mixedListingWave($claimedWave, $claimedRefetch, $reqTimeout, 'concise');
                $lock2 = grnconnect_search_try_lock($pollToken, true);
                if ($lock2) {
                    try {
                        $fresh = grnconnect_search_session_load($pollToken);
                        if (is_array($fresh)) {
                            $state = $fresh;
                        }
                        $upserted = grnconnect_apply_claimed_results(
                            $client,
                            $state,
                            $module,
                            $db,
                            $claimedWave,
                            $claimedRefetch,
                            $mixed
                        );
                        grnconnect_search_session_save($pollToken, $state, false);
                    } finally {
                        grnconnect_search_unlock($lock2);
                    }
                }
            }

            $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
            $pendingPayloads = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
            $masterByCode = is_array($state['hotels_by_code'] ?? null) ? $state['hotels_by_code'] : [];
            $inFlight = is_array($state['in_flight_payloads'] ?? null) ? $state['in_flight_payloads'] : [];
            $complete = empty($inFlight)
                && !empty($state['catalog_complete'])
                && grnconnect_search_finished($batches, $pendingPayloads);
            $allResults = array_values($masterByCode);

            echo json_encode([
                'status'           => 'success',
                'results'          => $snapshotAll ? $allResults : array_values($upserted),
                'results_total'    => count($allResults),
                'total'            => (int)($state['content_total'] ?? count($allResults)),
                'page'             => 1,
                'per_page'         => count($allResults),
                'total_pages'      => 1,
                'search_complete'  => $complete,
                'poll_token'       => $complete ? '' : $pollToken,
                'pending_batches'  => count($pendingPayloads),
                'in_flight_batches'=> count($inFlight),
                'claimed_avail'    => count($claimedWave),
                'claimed_refetch'  => count($claimedRefetch),
                'batches'          => array_map(function ($b) {
                    return [
                        'batch'        => (int)($b['batch'] ?? 0),
                        'search_id'    => (string)($b['search_id'] ?? ''),
                        'more_results' => !empty($b['more_results']),
                        'error'        => $b['error'] ?? null,
                    ];
                }, $batches),
                'failed_batches'   => $state['failed_batches'] ?? [],
                'search_mode'      => (string)($state['search_mode'] ?? ''),
                'search_meta'      => $state['search_meta'] ?? [],
                'search_params'    => $state['search_params'] ?? [],
                'parallel_search'  => true,
            ]);
            exit;
        }

        // =====================================================================
        // INITIAL SEARCH
        // =====================================================================
        $destination     = trim($_POST['destination'] ?? $_POST['city'] ?? '');
        $destinationCode = trim($_POST['destination_code'] ?? $_POST['city_code'] ?? '');
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

            $destHead = strtolower(trim(explode(',', $destination)[0] ?? $destLower));
            $metaHead = strtolower(trim(explode(',', (string)($meta['name'] ?? ''))[0] ?? $metaName));
            if (in_array($metaType, ['city', 'location'], true)) {
                $metaMatchesDest = ($destHead !== '' && $metaHead !== '' && $destHead === $metaHead);
            } else {
                // Hotel leftover "Dubai Marriott" must not match city search "Dubai"
                $metaMatchesDest = ($metaHead !== '' && $destHead !== '' && $destHead === $metaHead);
            }

            if ($metaMatchesDest) {
                if ($searchType === 'auto' && in_array($metaType, ['hotel', 'city', 'location', 'circle'], true)) {
                    $searchType = $metaType === 'location' ? 'city' : $metaType;
                }
                if ($searchType === 'location') {
                    $searchType = 'city';
                }
                if ($searchType === 'hotel' && $hotelCode === '' && !empty($meta['hotel_code'])) {
                    $hotelCode = (string)$meta['hotel_code'];
                }
                if (in_array($searchType, ['city', 'auto'], true) && $destinationCode === '' && (!empty($meta['city_code']) || !empty($meta['destination_code']))) {
                    $destinationCode = (string)($meta['city_code'] ?: $meta['destination_code']);
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

        if ($searchType === 'location') {
            $searchType = 'city';
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

        if ($useParallel) {
            $firstSlice = defined('GRNCONNECT_SEARCH_MAX_HOTELS') ? (int)GRNCONNECT_SEARCH_MAX_HOTELS : 5000;
            $resolved = grnconnect_resolve_search_hotels($contentDb, $db, $resolveInput, $firstSlice, 1);
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
        $apiCityCode = trim((string)($resolved['meta']['city_code'] ?? $destinationCode));
        $apiHotelCode = trim((string)($resolved['meta']['hotel_code'] ?? $hotelCode));
        if ($apiHotelCode !== '' && !in_array($apiHotelCode, $hotelCodes, true)) {
            array_unshift($hotelCodes, $apiHotelCode);
        } elseif ($apiHotelCode !== '' && $resolvedMode === 'hotel') {
            $hotelCodes = array_values(array_unique(array_merge([$apiHotelCode], $hotelCodes)));
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
            if ($apiCityCode !== '') {
                $payload['city_code'] = $apiCityCode;
            }
            if ($apiHotelCode !== '') {
                $payload['hotel_code'] = $apiHotelCode;
            }
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
                $resolvedMode,
                $contentDb
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
        // ALL 100-code batches in ONE parallel GRN wave (≤14s). Stream as they land.
        // =====================================================================
        if (connection_aborted()) {
            exit;
        }

        $tResolve = (int)round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
        $startedAt = microtime(true);
        $cutoffMs = max(3000, min(15000, (int)GRNCONNECT_CUTOFF_MS));
        $budgetS = function_exists('grnconnect_stream_budget_s') ? grnconnect_stream_budget_s() : 14;
        $budgetS = max(10, min(50, (int)$budgetS));
        if ($wantStream) {
            $budgetS = min(50, max($budgetS, (int)ceil($cutoffMs / 1000) + 30));
        }

        $chunkSize  = max(1, min(100, (int)GRNCONNECT_AVAIL_CHUNK));
        $reqTimeout = grnconnect_search_req_timeout($cutoffMs);

        $availCommon = [
            'checkin'            => $checkin,
            'checkout'           => $checkout,
            'client_nationality' => $nationality,
            'currency'           => $currency,
            'rates'              => 'concise',
            'rooms'              => $rooms,
            'more_results'       => true,
        ];

        $payloads = [];
        foreach (array_chunk($hotelCodes, $chunkSize) as $chunk) {
            $payloads[] = array_merge($availCommon, [
                'hotel_codes' => array_values($chunk),
                'cutoff_time' => $cutoffMs,
            ]);
        }

        $searchParams = [
            'checkin'     => $checkin,
            'checkout'    => $checkout,
            'rooms'       => $roomsCount,
            'nights'      => $nights,
            'search_type' => $resolvedMode,
            'radius_km'   => $radius > 0 ? $radius : 20,
        ];

        $token = bin2hex(random_bytes(16));
        $masterByCode = [];
        $batches = [];
        $failedBatches = [];

        $chunkBody = function ($type, array $upserted, $complete) use (
            &$masterByCode,
            $totalCount,
            $token,
            $resolvedMode,
            $resolved,
            $searchParams,
            $hotelCodes
        ) {
            return [
                'type'             => $type,
                'status'           => 'success',
                'results'          => array_values($upserted),
                'results_total'    => count($masterByCode),
                'total'            => $totalCount,
                'page'             => 1,
                'per_page'         => count($masterByCode),
                'total_pages'      => 1,
                'search_complete'  => $complete,
                'poll_token'       => $complete ? '' : $token,
                'pending_batches'  => 0,
                'hotel_codes'      => count($hotelCodes),
                'search_mode'      => $resolvedMode,
                'search_meta'      => $resolved['meta'],
                'search_params'    => $searchParams,
                'parallel_search'  => true,
                'stream'           => true,
            ];
        };

        if ($wantStream) {
            grnconnect_stream_headers();
        }

        $tAvailStart = microtime(true);
        $availDurations = [];
        $retryPayloads = [];
        $deadline = $startedAt + $budgetS;
        $processAvailabilityResult = function ($i, $res, $job) use (
            &$masterByCode,
            &$batches,
            &$failedBatches,
            &$availDurations,
            &$retryPayloads,
            $localByCode,
            $destination,
            $currency,
            $nights,
            $module,
            $db,
            $resolvedMode,
            $wantStream,
            $chunkBody,
            $contentDb
        ) {
            $availDurations[] = (int)($res['duration_ms'] ?? 0);
            $payload = is_array($job['_payload'] ?? null) ? $job['_payload'] : [];
            $changed = grnconnect_ingest_availability_wave(
                [$res],
                [$payload],
                $batches,
                $masterByCode,
                $failedBatches,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode,
                $retryPayloads,
                $contentDb
            );
            if ($wantStream && !empty($changed)) {
                grnconnect_stream_emit($chunkBody('hotels', $changed, false));
            }
        };
        if (!empty($payloads) && !connection_aborted()) {
            $client->availabilityEach($payloads, $processAvailabilityResult, $reqTimeout);
        }
        // Retry failed 100-code batches while there is room in the search budget.
        while (!empty($retryPayloads) && !connection_aborted() && microtime(true) + 1 < $deadline) {
            $retryWave = $retryPayloads;
            $retryPayloads = [];
            $remaining = max(4, (int)floor($deadline - microtime(true)));
            $client->availabilityEach($retryWave, $processAvailabilityResult, min($reqTimeout, $remaining));
        }
        $availMs = (int)round((microtime(true) - $tAvailStart) * 1000);

        $tRefetchStart = microtime(true);
        $refetchRounds = 0;
        while ((microtime(true) + 0.5) < $deadline) {
            $refetchIds = [];
            $refetchIdx = [];
            foreach ($batches as $bi => $batch) {
                if (!empty($batch['more_results']) && !empty($batch['search_id'])) {
                    $refetchIds[] = (string)$batch['search_id'];
                    $refetchIdx[] = $bi;
                }
            }
            if (empty($refetchIds)) {
                break;
            }
            $refetchRounds++;
            $left = (int)max(2, min(12, floor($deadline - microtime(true))));
            $refetchRes = $client->refetchBySearchIdParallel($refetchIds, 'concise', $left);
            $changed = grnconnect_ingest_refetch_wave(
                $refetchRes,
                $refetchIdx,
                $batches,
                $masterByCode,
                $failedBatches,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode,
                $contentDb
            );
            if ($wantStream && !empty($changed)) {
                grnconnect_stream_emit($chunkBody('hotels', $changed, false));
            }
        }
        $refetchMs = (int)round((microtime(true) - $tRefetchStart) * 1000);
        $totalMs = (int)round((microtime(true) - $startedAt) * 1000);

        if (function_exists('grnconnect_search_timing_log')) {
            grnconnect_search_timing_log([
                'destination'    => $destination,
                'codes'          => count($hotelCodes),
                'batches'        => count($payloads),
                'hotels'         => count($masterByCode),
                'resolve_ms'     => $tResolve,
                'avail_ms'       => $availMs,
                'avail_max_ms'   => $availDurations ? max($availDurations) : 0,
                'refetch_ms'     => $refetchMs,
                'refetch_rounds' => $refetchRounds,
                'total_ms'       => $totalMs,
                'cutoff_ms'      => $cutoffMs,
            ]);
        }

        $body = $chunkBody('done', $wantStream ? [] : $masterByCode, true);
        $body['results_total'] = count($masterByCode);
        $body['search_complete'] = true;
        $body['poll_token'] = '';
        $body['timing_ms'] = $totalMs;
        if (!$wantStream) {
            $body['results'] = array_values($masterByCode);
        }

        if ($wantStream) {
            grnconnect_stream_emit($body);
            exit;
        }

        echo json_encode($body);

    } catch (Exception $e) {
        $err = [
            'status'          => 'error',
            'message'         => $e->getMessage(),
            'results'         => [],
            'search_complete' => true,
            'poll_token'      => '',
        ];
        if (!empty($wantStream)) {
            if (!headers_sent()) {
                grnconnect_stream_headers();
            }
            grnconnect_stream_emit(array_merge($err, [
                'type'   => 'error',
                'stream' => true,
            ]));
        } else {
            echo json_encode($err);
        }
    }
    exit;
});

if (!function_exists('grnconnect_stream_headers')) {
    /** NDJSON stream headers. X-Accel-Buffering: no stops nginx from holding the first hotels. */
    function grnconnect_stream_headers()
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        while (ob_get_level()) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Fastcgi-Buffering: no');
        header('Connection: keep-alive');
        ob_implicit_flush(true);
    }
}

if (!function_exists('grnconnect_stream_emit')) {
    /**
     * Write one NDJSON event and flush. A pad field beats proxy buffering
     * so the browser can paint after the first GRN wave.
     */
    function grnconnect_stream_emit(array $payload)
    {
        $payload['_pad'] = str_repeat(' ', 16384);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }
}

if (!function_exists('grnconnect_stream_refetch_rounds')) {
    /**
     * Keep GET-by-search_id until more_results is false (or $maxRounds / budget).
     * This is how leftover hotels after cutoff_time actually arrive.
     */
    function grnconnect_stream_refetch_rounds(
        $client,
        array &$state,
        $module,
        $db,
        $token,
        $totalCount,
        $resolvedMode,
        $destination,
        $currency,
        $nights,
        $timeout,
        $maxRounds = 4,
        $startedAt = null,
        $budgetS = 14
    ) {
        $maxRounds = max(1, min(8, (int)$maxRounds));
        $timeout = max(2, min(8, (int)$timeout));
        $budgetS = max(8, min(20, (int)$budgetS));
        $startedAt = $startedAt !== null ? (float)$startedAt : microtime(true);
        $deadline = $startedAt + $budgetS;
        for ($round = 0; $round < $maxRounds; $round++) {
            if (connection_aborted()) {
                return;
            }
            if (microtime(true) >= $deadline) {
                return;
            }
            $refetchIds = [];
            $refetchIdx = [];
            $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
            foreach ($batches as $bi => $batch) {
                if (!empty($batch['more_results']) && !empty($batch['search_id'])) {
                    $refetchIds[] = (string)$batch['search_id'];
                    $refetchIdx[] = $bi;
                }
            }
            if (empty($refetchIds)) {
                return;
            }

            $refetchJobs = [];
            foreach ($refetchIds as $sid) {
                $refetchJobs[] = [
                    'method' => 'GET',
                    'path'   => 'api/v3/hotels/availability/' . rawurlencode($sid),
                    'opts'   => [
                        'query'    => ['rates' => 'concise'],
                        'timeout'  => $timeout,
                        'category' => 'search',
                        'action'   => 'refetch_by_search_id',
                        'no_retry' => true,
                        'connect_timeout' => 3,
                    ],
                ];
            }

            $client->requestMultiEach($refetchJobs, function ($j, $res) use (
                &$state,
                $refetchIdx,
                $module,
                $db,
                $destination,
                $currency,
                $nights,
                $resolvedMode,
                $token,
                $totalCount
            ) {
                if (connection_aborted()) {
                    return;
                }
                $upserted = grnconnect_ingest_refetch_wave(
                    [$res],
                    [$refetchIdx[$j] ?? 0],
                    $state['batches'],
                    $state['hotels_by_code'],
                    $state['failed_batches'],
                    grnconnect_ensure_locals(
                        $module,
                        grnconnect_collect_response_codes([$res]),
                        []
                    ),
                    $destination,
                    $currency,
                    $nights,
                    $module,
                    $db,
                    $resolvedMode
                );
                grnconnect_search_session_save($token, $state, false);
                if (empty($upserted)) {
                    return;
                }
                $master = $state['hotels_by_code'] ?? [];
                grnconnect_stream_emit([
                    'type'            => 'hotels',
                    'status'          => 'success',
                    'results'         => array_values($upserted),
                    'results_total'   => count($master),
                    'total'           => $totalCount,
                    'search_complete' => false,
                    'poll_token'      => $token,
                    'search_mode'     => $resolvedMode,
                    'stream'          => true,
                ]);
            });
        }
    }
}

if (!function_exists('grnconnect_search_wave_size')) {
    function grnconnect_search_wave_size()
    {
        $n = defined('GRNCONNECT_MAX_CONCURRENT') ? (int)GRNCONNECT_MAX_CONCURRENT : 50;
        return max(1, min(55, $n));
    }
}

if (!function_exists('grnconnect_search_req_timeout')) {
    function grnconnect_search_req_timeout($cutoffMs = null)
    {
        if ($cutoffMs === null) {
            $cutoffMs = defined('GRNCONNECT_CUTOFF_MS') ? (int)GRNCONNECT_CUTOFF_MS : 3000;
        }
        $cutoffMs = max(2000, min(50000, (int)$cutoffMs));
        $sec = (int)ceil($cutoffMs / 1000);
        return min(40, max($sec + 25, 12));
    }
}

if (!function_exists('grnconnect_refetch_timeout')) {
    function grnconnect_refetch_timeout()
    {
        $s = defined('GRNCONNECT_REFETCH_TIMEOUT') ? (int)GRNCONNECT_REFETCH_TIMEOUT : 10;
        return max(2, min(12, $s));
    }
}

if (!function_exists('grnconnect_stream_budget_s')) {
    function grnconnect_stream_budget_s()
    {
        $s = defined('GRNCONNECT_STREAM_BUDGET_S') ? (int)GRNCONNECT_STREAM_BUDGET_S : 50;
        return max(10, min(50, $s));
    }
}

if (!function_exists('grnconnect_search_timing_log')) {
    /** Append one compact timing line (no GRN payloads) for listing diagnosis. */
    function grnconnect_search_timing_log(array $row)
    {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }
        $row['at'] = date('c');
        @file_put_contents(
            $dir . '/search_timing.log',
            json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('grnconnect_first_batch_count')) {
    function grnconnect_first_batch_count()
    {
        $n = defined('GRNCONNECT_FIRST_BATCHES') ? (int)GRNCONNECT_FIRST_BATCHES : 2;
        return max(1, min(8, $n));
    }
}

if (!function_exists('grnconnect_first_cutoff_ms')) {
    function grnconnect_first_cutoff_ms()
    {
        $ms = defined('GRNCONNECT_FIRST_CUTOFF_MS') ? (int)GRNCONNECT_FIRST_CUTOFF_MS : 5000;
        return max(3000, min(8000, $ms));
    }
}

if (!function_exists('grnconnect_first_req_timeout')) {
    function grnconnect_first_req_timeout()
    {
        return max(4, (int)ceil(grnconnect_first_cutoff_ms() / 1000) + 2);
    }
}

if (!function_exists('grnconnect_queue_remaining_payloads')) {
    /**
     * Load catalog codes after the first 100-code GRN call.
     * @return array{0:array,1:array,2:int} rest payloads, extra locals, catalog total
     */
    function grnconnect_queue_remaining_payloads(
        $contentDb,
        $db,
        array $resolveInput,
        array $firstCodes,
        array $common,
        $restCutoffMs,
        $chunkSize
    ) {
        $restPayloads = [];
        $extraLocals = [];
        $total = count($firstCodes);
        if (!$contentDb || !function_exists('grnconnect_resolve_all_search_hotels')) {
            return [$restPayloads, $extraLocals, $total];
        }
        $all = grnconnect_resolve_all_search_hotels($contentDb, $db, $resolveInput);
        $seen = [];
        foreach ($firstCodes as $code) {
            $code = (string)$code;
            if ($code !== '') {
                $seen[$code] = true;
            }
        }
        $restCodes = [];
        foreach (is_array($all['rows'] ?? null) ? $all['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string)($row['hotel_code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $restCodes[] = $code;
            $extraLocals[$code] = $row;
        }
        $chunkSize = max(1, min(100, (int)$chunkSize));
        $restCutoffMs = max(3000, min(20000, (int)$restCutoffMs));
        foreach (array_chunk($restCodes, $chunkSize) as $chunk) {
            $restPayloads[] = array_merge($common, [
                'hotel_codes'  => array_values($chunk),
                'cutoff_time'  => $restCutoffMs,
                'more_results' => true,
            ]);
        }
        $total = max($total, (int)($all['total'] ?? 0), count($seen));
        return [$restPayloads, $extraLocals, $total];
    }
}

if (!function_exists('grnconnect_search_finished')) {
    /** True when every availability payload has been sent and no batch still has more_results. */
    function grnconnect_search_finished(array $batches, array $pendingPayloads)
    {
        if (!empty($pendingPayloads)) {
            return false;
        }
        return function_exists('grnconnect_batches_complete')
            ? grnconnect_batches_complete($batches)
            : true;
    }
}

if (!function_exists('grnconnect_ensure_locals')) {
    /** Load content-DB rows for hotel codes missing from $existing. */
    function grnconnect_ensure_locals($module, array $codes, array $existing = [])
    {
        $need = [];
        foreach ($codes as $code) {
            $code = (string)$code;
            if ($code !== '' && !isset($existing[$code])) {
                $need[] = $code;
            }
        }
        $need = array_values(array_unique($need));
        if (empty($need) || !$module) {
            return $existing;
        }
        $contentDb = function_exists('grnconnect_content_db') ? grnconnect_content_db($module) : null;
        if (!$contentDb || !function_exists('grnconnect_hotels_by_codes')) {
            return $existing;
        }
        foreach (grnconnect_hotels_by_codes($contentDb, $need, true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string)($row['hotel_code'] ?? '');
            if ($code !== '') {
                $existing[$code] = $row;
            }
        }
        return $existing;
    }
}

if (!function_exists('grnconnect_collect_response_codes')) {
    function grnconnect_collect_response_codes(array $responses)
    {
        $codes = [];
        foreach ($responses as $res) {
            $hotels = is_array($res['data']['hotels'] ?? null) ? $res['data']['hotels'] : [];
            foreach ($hotels as $hotel) {
                if (!is_array($hotel)) {
                    continue;
                }
                $code = (string)($hotel['hotel_code'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }
        return $codes;
    }
}

if (!function_exists('grnconnect_append_catalog_page')) {
    /**
     * Pull the next 400 catalog codes into pending GRN payloads.
     * Called under the search lock when the first wave is already in flight.
     */
    function grnconnect_append_catalog_page(array &$state, $contentDb, $db)
    {
        if (!empty($state['catalog_complete'])) {
            return;
        }
        if (!$contentDb || !function_exists('grnconnect_resolve_search_hotels')) {
            $state['catalog_complete'] = true;
            return;
        }
        $input = is_array($state['resolve_input'] ?? null) ? $state['resolve_input'] : [];
        if (empty($input)) {
            $state['catalog_complete'] = true;
            return;
        }
        $pageSize = max(100, min(800, (int)($state['catalog_page_size'] ?? 400)));
        $page = (int)($state['catalog_page'] ?? 1) + 1;
        $resolved = grnconnect_resolve_search_hotels($contentDb, $db, $input, $pageSize, $page);
        $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
        $seen = is_array($state['seen_codes'] ?? null) ? $state['seen_codes'] : [];
        $newCodes = [];
        foreach ($rows as $row) {
            $code = (string)($row['hotel_code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $newCodes[] = $code;
        }
        $common = is_array($state['avail_common'] ?? null) ? $state['avail_common'] : [];
        $common['cutoff_time'] = max(3000, (int)($state['rest_cutoff_ms'] ?? 8000));
        $common['more_results'] = true;
        $pending = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
        foreach (array_chunk($newCodes, 100) as $chunk) {
            $pending[] = array_merge($common, [
                'hotel_codes' => array_values($chunk),
            ]);
        }
        $state['pending_payloads'] = $pending;
        $state['seen_codes'] = $seen;
        $state['catalog_page'] = $page;
        $total = (int)($state['content_total'] ?? 0);
        $loaded = count($seen);
        $maxHotels = defined('GRNCONNECT_SEARCH_MAX_HOTELS') ? (int)GRNCONNECT_SEARCH_MAX_HOTELS : 5000;
        if (empty($rows) || ($total > 0 && $loaded >= $total) || $loaded >= $maxHotels) {
            $state['catalog_complete'] = true;
        }
    }
}

if (!function_exists('grnconnect_claim_search_work')) {
    /**
     * Take up to $limit pending POSTs and leftover GET search_ids.
     * Other workers can claim the rest — GRN is called AFTER this lock is released.
     * @return array{0:array,1:array} [availability payloads, refetch search_ids]
     */
    function grnconnect_claim_search_work(array &$state, $limit = 6)
    {
        $limit = max(1, min(50, (int)$limit));
        $now = time();
        $expire = 25;

        $pending = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
        $inFlight = is_array($state['in_flight_payloads'] ?? null) ? $state['in_flight_payloads'] : [];
        $stillFlight = [];
        foreach ($inFlight as $p) {
            if (!is_array($p)) {
                continue;
            }
            $age = $now - (int)($p['_claimed_at'] ?? 0);
            if ($age >= $expire) {
                unset($p['_claimed_at']);
                $pending[] = $p;
            } else {
                $stillFlight[] = $p;
            }
        }

        $wave = [];
        $kept = [];
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (count($wave) < $limit) {
                $p['_claimed_at'] = $now;
                $wave[] = $p;
                $stillFlight[] = $p;
            } else {
                $kept[] = $p;
            }
        }
        $state['pending_payloads'] = $kept;
        $state['in_flight_payloads'] = $stillFlight;

        $jobSlots = max(0, $limit - count($wave));
        $refetchIds = [];
        $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
        foreach ($batches as $i => $batch) {
            if ($jobSlots <= 0) {
                break;
            }
            if (empty($batch['more_results']) || empty($batch['search_id'])) {
                continue;
            }
            $inf = (int)($batch['refetch_inflight'] ?? 0);
            if ($inf > 0 && ($now - $inf) < $expire) {
                continue;
            }
            $batches[$i]['refetch_inflight'] = $now;
            $refetchIds[] = (string)$batch['search_id'];
            $jobSlots--;
        }
        $state['batches'] = $batches;
        return [$wave, $refetchIds];
    }
}

if (!function_exists('grnconnect_apply_claimed_results')) {
    /**
     * Merge one worker's GRN responses into the shared session.
     */
    function grnconnect_apply_claimed_results(
        $client,
        array &$state,
        $module,
        $db,
        array $wave,
        array $refetchIds,
        array $mixed
    ) {
        $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
        $masterByCode = is_array($state['hotels_by_code'] ?? null) ? $state['hotels_by_code'] : [];
        $failedBatches = is_array($state['failed_batches'] ?? null) ? $state['failed_batches'] : [];
        $pendingPayloads = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
        $inFlight = is_array($state['in_flight_payloads'] ?? null) ? $state['in_flight_payloads'] : [];
        $destination = (string)($state['destination'] ?? '');
        $currency = (string)($state['currency'] ?? 'USD');
        $nights = max(1, (int)($state['nights'] ?? 1));
        $resolvedMode = (string)($state['search_mode'] ?? '');

        $availRes = is_array($mixed['availability'] ?? null) ? $mixed['availability'] : [];
        $refetchRes = is_array($mixed['refetch'] ?? null) ? $mixed['refetch'] : [];

        $codes = [];
        foreach ($wave as $payload) {
            foreach ($payload['hotel_codes'] ?? [] as $code) {
                $codes[] = (string)$code;
            }
        }
        $codes = array_merge($codes, grnconnect_collect_response_codes($availRes), grnconnect_collect_response_codes($refetchRes));
        $localByCode = grnconnect_ensure_locals($module, $codes, []);

        $upserted = [];
        if (!empty($wave)) {
            $retryPayloads = [];
            $changed = grnconnect_ingest_availability_wave(
                $availRes,
                $wave,
                $batches,
                $masterByCode,
                $failedBatches,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode,
                $retryPayloads
            );
            foreach ($changed as $item) {
                $upserted[$item['hotel_id']] = $item;
            }
            if (!empty($retryPayloads)) {
                foreach ($retryPayloads as $p) {
                    unset($p['_claimed_at']);
                    $pendingPayloads[] = $p;
                }
            }
            $claimedKeys = [];
            foreach ($wave as $p) {
                $claimedKeys[md5(json_encode($p['hotel_codes'] ?? []))] = true;
            }
            $stillFlight = [];
            foreach ($inFlight as $p) {
                $k = md5(json_encode($p['hotel_codes'] ?? []));
                if (!isset($claimedKeys[$k])) {
                    $stillFlight[] = $p;
                }
            }
            $inFlight = $stillFlight;
        }

        if (!empty($refetchIds)) {
            $refetchIdx = [];
            foreach ($refetchIds as $sid) {
                $found = null;
                foreach ($batches as $i => $batch) {
                    if ((string)($batch['search_id'] ?? '') === (string)$sid) {
                        $found = $i;
                        break;
                    }
                }
                $refetchIdx[] = $found !== null ? $found : -1;
            }
            $validRes = [];
            $validIdx = [];
            foreach ($refetchIdx as $ri => $bi) {
                if ($bi < 0) {
                    continue;
                }
                $validRes[] = $refetchRes[$ri] ?? ['success' => false, 'error' => 'missing', 'data' => null];
                $validIdx[] = $bi;
            }
            if (!empty($validIdx)) {
                $changed = grnconnect_ingest_refetch_wave(
                    $validRes,
                    $validIdx,
                    $batches,
                    $masterByCode,
                    $failedBatches,
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
            foreach ($batches as $i => $batch) {
                $sid = (string)($batch['search_id'] ?? '');
                if ($sid !== '' && in_array($sid, $refetchIds, true)) {
                    unset($batches[$i]['refetch_inflight']);
                }
            }
            $batches = array_values($batches);
        }

        $state['pending_payloads'] = $pendingPayloads;
        $state['in_flight_payloads'] = $inFlight;
        $state['batches'] = $batches;
        $state['hotels_by_code'] = $masterByCode;
        $state['failed_batches'] = $failedBatches;
        return $upserted;
    }
}

if (!function_exists('grnconnect_execute_mixed_wave')) {
    /**
     * Fire leftover-rate refetches and remaining availability in one curl_multi.
     * Mutates $state (batches, hotels, pending payloads).
     *
     * @return array upserted listing items
     */
    function grnconnect_execute_mixed_wave($client, array &$state, $module, $db)
    {
        $waveSize = grnconnect_search_wave_size();
        $pendingPayloads = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
        $reqTimeout = (int)($state['req_timeout'] ?? grnconnect_search_req_timeout());
        if (empty($pendingPayloads)) {
            $reqTimeout = grnconnect_refetch_timeout();
        }
        $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
        $masterByCode = is_array($state['hotels_by_code'] ?? null) ? $state['hotels_by_code'] : [];
        $failedBatches = is_array($state['failed_batches'] ?? null) ? $state['failed_batches'] : [];
        $destination = (string)($state['destination'] ?? '');
        $currency = (string)($state['currency'] ?? 'USD');
        $nights = max(1, (int)($state['nights'] ?? 1));
        $resolvedMode = (string)($state['search_mode'] ?? '');

        $refetchIds = [];
        $refetchIdx = [];
        foreach ($batches as $i => $batch) {
            if (!empty($batch['more_results']) && !empty($batch['search_id'])) {
                $refetchIds[] = (string)$batch['search_id'];
                $refetchIdx[] = $i;
            }
        }

        $availSlots = min(count($pendingPayloads), $waveSize);
        $refetchSlots = min(count($refetchIds), max(0, $waveSize - $availSlots));

        $wave = $availSlots > 0 ? array_splice($pendingPayloads, 0, $availSlots) : [];
        if ($refetchSlots > 0) {
            $refetchIds = array_slice($refetchIds, 0, $refetchSlots);
            $refetchIdx = array_slice($refetchIdx, 0, $refetchSlots);
        } else {
            $refetchIds = [];
            $refetchIdx = [];
        }

        $state['pending_payloads'] = $pendingPayloads;
        if (empty($wave) && empty($refetchIds)) {
            $state['batches'] = $batches;
            $state['hotels_by_code'] = $masterByCode;
            $state['failed_batches'] = $failedBatches;
            return [];
        }

        $mixed = $client->mixedListingWave($wave, $refetchIds, $reqTimeout, 'concise');
        $availRes = is_array($mixed['availability'] ?? null) ? $mixed['availability'] : [];
        $refetchRes = is_array($mixed['refetch'] ?? null) ? $mixed['refetch'] : [];

        $codes = [];
        foreach ($wave as $payload) {
            foreach ($payload['hotel_codes'] ?? [] as $code) {
                $codes[] = (string)$code;
            }
        }
        $codes = array_merge($codes, grnconnect_collect_response_codes($availRes), grnconnect_collect_response_codes($refetchRes));
        $localByCode = grnconnect_ensure_locals($module, $codes, []);

        $upserted = [];
        if (!empty($wave)) {
            $retryPayloads = [];
            $changed = grnconnect_ingest_availability_wave(
                $availRes,
                $wave,
                $batches,
                $masterByCode,
                $failedBatches,
                $localByCode,
                $destination,
                $currency,
                $nights,
                $module,
                $db,
                $resolvedMode,
                $retryPayloads
            );
            foreach ($changed as $item) {
                $upserted[$item['hotel_id']] = $item;
            }
            if (!empty($retryPayloads)) {
                $pendingPayloads = array_merge($pendingPayloads, $retryPayloads);
            }
        }
        if (!empty($refetchIds)) {
            $changed = grnconnect_ingest_refetch_wave(
                $refetchRes,
                $refetchIdx,
                $batches,
                $masterByCode,
                $failedBatches,
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

        $state['pending_payloads'] = $pendingPayloads;
        $state['batches'] = $batches;
        $state['hotels_by_code'] = $masterByCode;
        $state['failed_batches'] = $failedBatches;
        return $upserted;
    }
}

if (!function_exists('grnconnect_drain_search')) {
    /** Keep running mixed waves after the first HTTP response (php-fpm). */
    function grnconnect_drain_search($client, $token, $module, $db)
    {
        @ignore_user_abort(true);
        @set_time_limit(180);
        if (is_object($client) && method_exists($client, 'setIgnoreClientAbort')) {
            $client->setIgnoreClientAbort(true);
        }
        $lock = grnconnect_search_try_lock($token, true);
        if (!$lock) {
            return;
        }
        try {
            for ($i = 0; $i < 24; $i++) {
                $state = grnconnect_search_session_load($token);
                if (!is_array($state)) {
                    break;
                }
                $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
                $pending = is_array($state['pending_payloads'] ?? null) ? $state['pending_payloads'] : [];
                if (grnconnect_search_finished($batches, $pending)) {
                    break;
                }
                grnconnect_execute_mixed_wave($client, $state, $module, $db);
                grnconnect_search_session_save($token, $state, false);
            }
        } finally {
            grnconnect_search_unlock($lock);
        }
    }
}

if (!function_exists('grnconnect_ingest_availability_wave')) {
    /**
     * Merge one availability wave into the master hotel map and batch list.
     *
     * @return array upserted listing items
     */
    function grnconnect_ingest_availability_wave(
        array $responses,
        array $payloads,
        array &$batches,
        array &$masterByCode,
        array &$failedBatches,
        array $localByCode,
        $destination,
        $currency,
        $nights,
        $module,
        $db,
        $resolvedMode,
        ?array &$retryPayloads = null,
        $contentDb = null
    ) {
        if ($retryPayloads === null) {
            $retryPayloads = [];
        }
        $upserted = [];
        $batchBase = count($batches);
        foreach ($responses as $i => $res) {
            $batchNum = $batchBase + $i + 1;
            $chunkCodes = $payloads[$i]['hotel_codes'] ?? [];

            if (!$res['success'] && ($res['error'] ?? '') !== '') {
                $err = [
                    'batch'       => $batchNum,
                    'hotel_count' => count($chunkCodes),
                    'error'       => (string)$res['error'],
                    'phase'       => 'availability',
                ];
                $failedBatches[] = $err;
                error_log('GRNConnect search availability batch failed: ' . json_encode($err));
                $payload = $payloads[$i];
                $payload['_retries'] = (int)($payload['_retries'] ?? 0) + 1;
                $curlErrno = (int)($res['curl_errno'] ?? 0);
                // A timed-out batch already used the cutoff window. Retrying it
                // starts a second wave and drops the hotel count for this search.
                if ($curlErrno !== 28 && $payload['_retries'] < 3) {
                    $retryPayloads[] = $payload;
                } else {
                    $batches[] = [
                        'batch'        => $batchNum,
                        'search_id'    => '',
                        'more_results' => false,
                        'hotel_codes'  => $chunkCodes,
                        'error'        => (string)$res['error'],
                    ];
                }
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
                $resolvedMode,
                $contentDb
            );
            foreach ($changed as $item) {
                $upserted[$item['hotel_id']] = $item;
            }
        }
        return $upserted;
    }
}

if (!function_exists('grnconnect_ingest_refetch_wave')) {
    /**
     * Merge refetch-by-search_id results into existing batches.
     *
     * @return array upserted listing items
     */
    function grnconnect_ingest_refetch_wave(
        array $refetchResults,
        array $pendingIdx,
        array &$batches,
        array &$masterByCode,
        array &$failedBatches,
        array $localByCode,
        $destination,
        $currency,
        $nights,
        $module,
        $db,
        $resolvedMode,
        $contentDb = null
    ) {
        $upserted = [];
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
                $batches[$bi]['refetch_errors'] = (int)($batches[$bi]['refetch_errors'] ?? 0) + 1;
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
                $resolvedMode,
                $contentDb
            );
            foreach ($changed as $item) {
                $upserted[$item['hotel_id']] = $item;
            }
        }
        return $upserted;
    }
}

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
