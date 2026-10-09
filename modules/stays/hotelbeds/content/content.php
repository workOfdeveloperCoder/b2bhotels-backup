<?php

// Start output buffering to prevent any unwanted output
ob_start();

// Use the main database connection from config.php
global $router, $db;

require_once __DIR__ . '/reference_import.php';

// Function to get Hotelbeds database connection
function getHotelbedsDb() {
    global $db;

    // Get module configuration
    $module = $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelbeds',
        'type' => 'stays'
    ]);

    // If no separate database configured, use main database
    if (!$module || empty($module['host']) || empty($module['database'])) {
        error_log("Hotelbeds: Module config: " . json_encode($module));
        return $db;
    }

    try {
        // Create separate database connection for Hotelbeds
        $dsn = "mysql:host={$module['host']};dbname={$module['database']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, $module['username'], $module['password'] ?? '', $options);

        // Use Medoo with the separate connection
        $hotelbedsDb = new Medoo\Medoo([
            'type' => 'mysql',
            'pdo' => $pdo
        ]);
        
        return $hotelbedsDb;

    } catch (Exception $e) {
        error_log("Hotelbeds DB connection failed: " . $e->getMessage() . ". Falling back to main database.");
        return $db;
    }
}

// Validate credentials endpoint - Tests if credentials have Content API access
$router->post('hotels/hotelbeds/validate', function() use ($db) {
    $hotelbedsDb = getHotelbedsDb();
    ob_clean();
    header('Content-Type: application/json');

    try {
        $apiKey = $_POST['c1'] ?? '';
        $apiSecret = $_POST['c2'] ?? '';
        $environment = $_POST['env'] ?? 'test';

        if (empty($apiKey) || empty($apiSecret)) {
            echo json_encode([
                'success' => false,
                'message' => 'API credentials are required'
            ]);
            exit;
        }

        // Test with countries (locations) — smallest Content API probe
        $isProduction = ($environment === 'live');
        $baseUrl = $isProduction
            ? 'https://api.hotelbeds.com/hotel-content-api/1.0/locations/countries'
            : 'https://api.test.hotelbeds.com/hotel-content-api/1.0/locations/countries';

        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '?fields=all&language=ENG&from=1&to=10',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            echo json_encode([
                'success' => true,
                'message' => 'Credentials are valid and have Content API access'
            ]);
        } elseif ($httpCode === 403) {
            $errorData = json_decode($response, true);
            echo json_encode([
                'success' => false,
                'message' => 'Credentials are valid but do NOT have Content API access',
                'details' => 'Your API credentials only have Booking API access. You need to contact Hotelbeds to enable Content API access for your account.',
                'error_code' => $httpCode,
                'error_response' => $errorData['error']['message'] ?? 'Access denied'
            ]);
        } elseif ($httpCode === 401) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid API credentials',
                'details' => 'The API Key or Secret is incorrect',
                'error_code' => $httpCode
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => "API returned HTTP $httpCode",
                'response' => substr($response, 0, 500)
            ]);
        }
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});

// Statistics endpoint - Returns current content statistics
$router->get('stays/hotelbeds/stats', function() use ($db) {
    $hotelbedsDb = getHotelbedsDb();
    ob_clean(); // Clear any buffered output
    header('Content-Type: application/json');

    try {
        // Check if tables exist using Medoo
        $tablesExist = $hotelbedsDb->query("SHOW TABLES LIKE 'hotelbeds_hotels'")->fetchAll();

        if (!$tablesExist) {
            echo json_encode([
                'success' => true,
                'stats' => [
                    'total_hotels' => 0,
                    'total_hotels_source' => 'unique_db',
                    'total_destinations' => 0,
                    'total_countries' => 0,
                    'last_sync' => 'Never',
                    'status' => 'Not Started'
                ]
            ]);
            exit;
        }

        // Total Hotels = unique hotel_code values we store (not API total, not raw row count)
        $uniqueHotels = hotelbedsCountUniqueHotels($hotelbedsDb);
        $totalDestinations = $hotelbedsDb->count('hotelbeds_destinations');
        $totalCountries = $hotelbedsDb->count('hotelbeds_countries');

        $syncMeta = hotelbedsImportSyncMeta($hotelbedsDb);
        echo json_encode([
            'success' => true,
            'stats' => [
                'total_hotels' => $uniqueHotels,
                'total_hotels_source' => 'unique_db',
                'total_destinations' => (int) $totalDestinations,
                'total_countries' => (int) $totalCountries,
                'last_sync' => $syncMeta['last_sync'],
                'status' => $syncMeta['status']
            ]
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
});

// Content import endpoint - Starts the import process
$router->post('stays/hotelbeds/content_import', function() use ($db) {
    // Aggressive error suppression
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    
    @ob_start();
    
    $hotelbedsDb = getHotelbedsDb();
    header('Content-Type: application/json');

    try {
        $mode = $_POST['mode'] ?? 'update'; // fresh or update
        $apiKey = $_POST['c1'] ?? '';
        $apiSecret = $_POST['c2'] ?? '';
        $environment = $_POST['env'] ?? 'test'; // test or live

        // Validate credentials
        if (empty($apiKey) || empty($apiSecret)) {
            echo json_encode([
                'success' => false,
                'message' => 'API credentials are required'
            ]);
            exit;
        }

        // Create database schema if it doesn't exist
        try {
            createHotelbedsSchema($hotelbedsDb);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create database schema: ' . $e->getMessage() . '. Please ensure the database exists and has proper permissions.'
            ]);
            exit;
        }

        // Cancel any stuck in_progress sessions so we never resume a broken state
        try {
            $hotelbedsDb->update('hotelbeds_import_log', [
                'status' => 'cancelled',
                'updated_at' => $hotelbedsDb->raw('NOW()'),
            ], ['status' => 'in_progress']);
        } catch (Exception $e) {
            error_log('[HOTELBEDS] Failed to cancel prior imports: ' . $e->getMessage());
        }

        // If fresh mode, truncate all tables
        if ($mode === 'fresh') {
            try {
                truncateHotelbedsTables($hotelbedsDb);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to truncate tables: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        @set_time_limit(0);
        ini_set('max_execution_time', '0');

        // Get current hotel count from database if exists
        $currentHotelCount = 0;
        try {
            $currentHotelCount = $hotelbedsDb->count('hotelbeds_hotels');
        } catch (Exception $e) {
            // Table might not exist yet
        }

        $chunkSize = 25;
        $estimatedTotal = max($currentHotelCount, 250000);
        $estimatedChunks = ceil($estimatedTotal / $chunkSize);

        // Create import session FIRST (with credentials) so /process can run even if
        // reference import is slow or the browser times out on this request.
        $importState = [
            'mode' => $mode,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'environment' => $environment,
            'current_chunk' => 0,
            'total_chunks' => $estimatedChunks,
            'processed' => 0,
            'total' => $estimatedTotal,
            'api_total' => null,
            'chunk_size' => $chunkSize,
            'start_time' => time(),
            'logs' => json_encode([
                '[' . date('H:i:s') . '] [INIT] Import session created — loading Content API masters next…',
            ]),
            'reference_data_imported' => false,
            'rate_comments_imported' => false,
            'rate_comments_from' => 1,
            'rate_comments_inserted' => 0,
            'rate_comments_total' => null,
            'reference_records' => 0,
            'phase' => 'reference',
            'paused' => false,
            'current_operation' => 'Importing Content API reference data (countries, rooms, boards…)',
        ];

        $hotelbedsDb->insert('hotelbeds_import_log', [
            'mode' => $mode,
            'status' => 'in_progress',
            'import_state' => json_encode($importState),
            'started_at' => $hotelbedsDb->raw('NOW()'),
        ]);
        $importId = $hotelbedsDb->id();

        // Masters first (fast). Rate comments are imported page-by-page in /process
        // (Postman RateComments) before hotel chunks so you can pause after comments.
        $refResults = importHotelbedsReferenceData($hotelbedsDb, $apiKey, $apiSecret, $environment, [
            'include_rate_comments' => false,
        ]);

        $totalRefRecords = 0;
        foreach ($refResults as $type => $result) {
            if (!empty($result['success'])) {
                $totalRefRecords += (int) ($result['count'] ?? 0);
            }
        }

        $importState['reference_data_imported'] = true;
        $importState['rate_comments_imported'] = false;
        $importState['rate_comments_from'] = 1;
        $importState['rate_comments_inserted'] = 0;
        $importState['reference_records'] = $totalRefRecords;
        $importState['phase'] = 'rate_comments';
        $importState['current_operation'] = 'Masters ready — importing rate comments (paginated)…';
        $logs = json_decode($importState['logs'], true) ?: [];
        $logs[] = '[' . date('H:i:s') . '] [OK] Reference masters imported (' . $totalRefRecords . ' rows). Next: rate comments.';
        $importState['logs'] = json_encode(array_slice($logs, -200));

        $hotelbedsDb->update('hotelbeds_import_log', [
            'import_state' => json_encode($importState),
            'updated_at' => $hotelbedsDb->raw('NOW()'),
        ], ['id' => $importId]);

        error_log("[HOTELBEDS] [" . date('H:i:s') . "] [INFO] Current hotels in DB: $currentHotelCount, Estimated total: $estimatedTotal, Chunks: $estimatedChunks, Ref: $totalRefRecords");

        @ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Import initialized successfully',
            'import_id' => $importId,
            'reference_records' => $totalRefRecords,
            'next_phase' => 'rate_comments',
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});

// Progress endpoint - Returns current import progress
$router->get('stays/hotelbeds/progress', function() use ($db) {
    // Aggressive error suppression
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    
    @ob_start();
    
    $hotelbedsDb = getHotelbedsDb();
    header('Content-Type: application/json');

    // Get the latest import in progress
    $importLog = $hotelbedsDb->get('hotelbeds_import_log', '*', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if (!$importLog) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'No import in progress'
        ]);
        exit;
    }

    $import = json_decode($importLog['import_state'], true);
    if (!$import) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Invalid import state. Raw: ' . substr($importLog['import_state'], 0, 200)
        ]);
        exit;
    }

    // Calculate ETA
    $elapsed = time() - $import['start_time'];
    $progress = $import['processed'] / max($import['total'], 1);
    $eta = $progress > 0 ? round(($elapsed / $progress) - $elapsed) : 0;
    $etaFormatted = $eta > 0 ? gmdate("H:i:s", $eta) : 'Calculating...';

    // Live counts: Total Hotels = unique hotel_code in DB (progress bar still uses API total).
    $uniqueHotels = hotelbedsCountUniqueHotels($hotelbedsDb);
    $liveDestinations = 0; $liveCountries = 0;
    try { $liveDestinations = (int) $hotelbedsDb->count('hotelbeds_destinations'); } catch (Exception $e) {}
    try { $liveCountries = (int) $hotelbedsDb->count('hotelbeds_countries'); } catch (Exception $e) {}
    $syncMeta = hotelbedsImportSyncMeta($hotelbedsDb);

    @ob_end_clean();
    echo json_encode([
        'success' => true,
        'status' => $importLog['status'],
        'paused' => !empty($import['paused']),
        // Which engine opened this run. The page must not resume a legacy
        // 25-hotel chunk loop just because a session is open — it would drag a
        // reloaded browser straight back into the slow path.
        'engine' => $import['engine'] ?? 'legacy',
        'turbo_cursor' => (int) ($importLog['turbo_cursor'] ?? 0),
        'current_operation' => $import['current_operation'] ?? 'Processing...',
        'current_chunk' => $import['current_chunk'],
        'total_chunks' => $import['total_chunks'],
        'processed' => $import['processed'],
        'total' => $import['total'],
        'total_hotels' => $uniqueHotels,
        'total_destinations' => $liveDestinations,
        'total_countries' => $liveCountries,
        'last_sync' => $syncMeta['last_sync'],
        'eta' => $etaFormatted,
        'logs' => json_decode($import['logs'], true) ?? []
    ]);
    exit;
});

// Cancel endpoint - Cancels the import process
$router->post('stays/hotelbeds/cancel', function() use ($db) {
    // Aggressive error suppression
    @ini_set('display_errors', '0');
    @error_reporting(0);
    
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    
    @ob_start();
    
    $hotelbedsDb = getHotelbedsDb();
    header('Content-Type: application/json');

    // Find the latest import in progress
    $importLog = $hotelbedsDb->get('hotelbeds_import_log', '*', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if ($importLog) {
        // Update import log status using Medoo
        $hotelbedsDb->update('hotelbeds_import_log', [
            'status' => 'cancelled',
            'updated_at' => $hotelbedsDb->raw('NOW()')
        ], ['id' => $importLog['id']]);
    }

    @ob_end_clean();
    echo json_encode(['success' => true]);
    exit;
});

// Pause endpoint - persists a "paused" flag inside the import state so a paused
// import survives a page refresh and can be resumed exactly where it stopped.
$router->post('stays/hotelbeds/pause', function() use ($db) {
    @ini_set('display_errors', '0'); @error_reporting(0);
    while (@ob_get_level()) @ob_end_clean(); @ob_start();
    $hotelbedsDb = getHotelbedsDb();
    header('Content-Type: application/json');
    $importLog = $hotelbedsDb->get('hotelbeds_import_log', '*', ['status' => 'in_progress', 'ORDER' => ['id' => 'DESC']]);
    if ($importLog) {
        $import = json_decode($importLog['import_state'], true) ?: [];
        $import['paused'] = true;
        $hotelbedsDb->update('hotelbeds_import_log', [
            'import_state' => json_encode($import),
            'updated_at' => $hotelbedsDb->raw('NOW()')
        ], ['id' => $importLog['id']]);
    }
    @ob_end_clean();
    echo json_encode(['success' => true, 'paused' => true]);
    exit;
});

// Resume endpoint - clears the paused flag; the UI then continues the chunk loop.
$router->post('stays/hotelbeds/resume', function() use ($db) {
    @ini_set('display_errors', '0'); @error_reporting(0);
    while (@ob_get_level()) @ob_end_clean(); @ob_start();
    $hotelbedsDb = getHotelbedsDb();
    header('Content-Type: application/json');
    $importLog = $hotelbedsDb->get('hotelbeds_import_log', '*', ['status' => 'in_progress', 'ORDER' => ['id' => 'DESC']]);
    if (!$importLog) {
        @ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No import in progress to resume']);
        exit;
    }
    $import = json_decode($importLog['import_state'], true) ?: [];
    $import['paused'] = false;
    // Do not skip rate comments — Resume continues the paginated RateComments phase.
    if (empty($import['api_key']) || empty($import['api_secret'])) {
        $moduleCreds = $db->get('modules', ['c1', 'c2', 'dev_mode'], [
            'name' => 'hotelbeds',
            'type' => 'stays',
        ]);
        if (!empty($moduleCreds['c1']) && !empty($moduleCreds['c2'])) {
            $import['api_key'] = $moduleCreds['c1'];
            $import['api_secret'] = $moduleCreds['c2'];
            if (empty($import['environment'])) {
                $import['environment'] = (($moduleCreds['dev_mode'] ?? '1') == '1') ? 'test' : 'live';
            }
        }
    }
    $logs = json_decode($import['logs'] ?? '[]', true) ?: [];
    $logs[] = '[' . date('H:i:s') . '] [RESUME] Cleared pause — chunk ' . (int) ($import['current_chunk'] ?? 0);
    $import['logs'] = json_encode(array_slice($logs, -200));
    $import['current_operation'] = 'Resuming hotel content chunks…';
    $hotelbedsDb->update('hotelbeds_import_log', [
        'import_state' => json_encode($import),
        'updated_at' => $hotelbedsDb->raw('NOW()')
    ], ['id' => $importLog['id']]);
    @ob_end_clean();
    echo json_encode([
        'success' => true,
        'paused' => false,
        'current_chunk' => (int) ($import['current_chunk'] ?? 0),
        'processed' => (int) ($import['processed'] ?? 0),
    ]);
    exit;
});

// Process import endpoint - Does the actual data import
if (!defined('HB_PAGE_SIZE')) {
    // Hotelbeds Content API rejects from/to spans above 1000 (HTTP 400).
    define('HB_PAGE_SIZE', 1000);
}

/** Tables written by the hotel content import, in parent-first order. */
function hotelbedsFastTables(): array
{
    return [
        'hotels'    => 'hotelbeds_hotels',
        'amenities' => 'hotelbeds_amenities',
        'images'    => 'hotelbeds_hotel_images',
        'rooms'     => 'hotelbeds_hotel_rooms',
        'issues'    => 'hotelbeds_hotel_issues',
        'terminals' => 'hotelbeds_hotel_terminals',
    ];
}

/** Column order used for both TSV shards and the INSERT fallback. */
function hotelbedsFastColumns(): array
{
    return [
        'hotels' => ['hotel_code','name','description','country_code','state_code','destination_code','zone_code',
            'latitude','longitude','category_code','category_name','accommodation_type','chain_code','segment_codes',
            'address','postal_code','city','email','web','phone_number','fax_number','license','ranking',
            'facilities','images','rooms','sync_run_id','created_at','updated_at'],
        'amenities' => ['hotel_code','facility_code','facility_description','facility_group_code','distance','order_by',
            'ind_fee','ind_yes_or_no','voucher','fee_amount','fee_currency','created_at','updated_at'],
        'images' => ['hotel_code','image_type','image_url','image_order','room_code','room_type','created_at','updated_at'],
        'rooms' => ['hotel_code','room_code','room_type','characteristic','description','min_pax','max_pax',
            'max_adults','max_children','room_facilities','created_at','updated_at'],
        'issues' => ['hotel_code','issue_code','issue_type','description','date_from','date_to','created_at','updated_at'],
        'terminals' => ['hotel_code','terminal_code','terminal_type','distance','description','created_at','updated_at'],
    ];
}

// ---------------------------------------------------------------------------
// Transform: API hotel object -> flat rows
// ---------------------------------------------------------------------------

/** Content API text nodes are either {"content": "..."} or a bare string. */
function hotelbedsFastText($value): string
{
    if (is_array($value)) {
        return (string) ($value['content'] ?? '');
    }
    return $value === null ? '' : (string) $value;
}

/** Content API booleans arrive as true/"true"/1/"1" depending on the field. */
function hotelbedsFastBool(array $node, string $key): ?int
{
    if (!array_key_exists($key, $node)) {
        return null;
    }
    $v = $node[$key];
    return ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 1 : 0;
}

/**
 * Map one page of API hotels onto the six target tables.
 *
 * Mapping is identical to the original importHotelbedsChunk() so imported data
 * is byte-for-byte comparable; only the write path changed.
 *
 * @param bool $storeRawJson keep the per-hotel facilities/images/rooms JSON
 *                           blobs on hotelbeds_hotels (7.6 GB across the full
 *                           catalogue). Off only when explicitly configured.
 */
function hotelbedsFastTransform(array $hotels, int $syncRunId, string $now, bool $storeRawJson = true): array
{
    $out = ['hotels' => [], 'amenities' => [], 'images' => [], 'rooms' => [], 'issues' => [], 'terminals' => []];
    $J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    foreach ($hotels as $hotel) {
        $code = $hotel['code'] ?? null;
        if ($code === null || $code === '') {
            continue;
        }
        $code = (string) $code;

        $facilities = (isset($hotel['facilities']) && is_array($hotel['facilities'])) ? $hotel['facilities'] : [];
        $images     = (isset($hotel['images']) && is_array($hotel['images'])) ? $hotel['images'] : [];
        $rooms      = (isset($hotel['rooms']) && is_array($hotel['rooms'])) ? $hotel['rooms'] : [];

        $segmentCodes = null;
        if (!empty($hotel['segmentCodes']) && is_array($hotel['segmentCodes'])) {
            $segmentCodes = json_encode($hotel['segmentCodes'], $J);
            if ($segmentCodes === false) {
                $segmentCodes = null;
            }
        }

        $encode = function (array $value) use ($J, $storeRawJson) {
            if (!$storeRawJson) {
                return '[]';
            }
            $json = json_encode($value, $J);
            return $json === false ? '[]' : $json;
        };

        $out['hotels'][] = [
            'hotel_code'         => $code,
            'name'               => $hotel['name']['content'] ?? 'Unknown',
            'description'        => $hotel['description']['content'] ?? '',
            'country_code'       => $hotel['countryCode'] ?? '',
            'state_code'         => $hotel['stateCode'] ?? '',
            'destination_code'   => $hotel['destinationCode'] ?? '',
            'zone_code'          => $hotel['zoneCode'] ?? '',
            'latitude'           => $hotel['coordinates']['latitude'] ?? null,
            'longitude'          => $hotel['coordinates']['longitude'] ?? null,
            'category_code'      => $hotel['categoryCode'] ?? '',
            'category_name'      => $hotel['category']['description']['content'] ?? ($hotel['categoryName']['content'] ?? ''),
            'accommodation_type' => $hotel['accommodationType']['typeDescription'] ?? ($hotel['accommodationTypeCode'] ?? ''),
            'chain_code'         => $hotel['chainCode'] ?? '',
            'segment_codes'      => $segmentCodes,
            'address'            => $hotel['address']['content'] ?? '',
            'postal_code'        => $hotel['postalCode'] ?? '',
            'city'               => $hotel['city']['content'] ?? '',
            'email'              => $hotel['email'] ?? '',
            'web'                => $hotel['web'] ?? '',
            'phone_number'       => $hotel['phones'][0]['phoneNumber'] ?? '',
            'fax_number'         => null,
            'license'            => $hotel['license'] ?? '',
            'ranking'            => $hotel['ranking'] ?? 0,
            'facilities'         => $encode($facilities),
            'images'             => $encode($images),
            'rooms'              => $encode($rooms),
            'sync_run_id'        => $syncRunId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        foreach ($facilities as $facility) {
            if (!isset($facility['facilityCode'])) {
                continue;
            }
            // Mandatory typology: indYesOrNo=false means the facility is NOT present.
            if (array_key_exists('indYesOrNo', $facility) && hotelbedsFastBool($facility, 'indYesOrNo') === 0) {
                continue;
            }
            $description = '';
            if (isset($facility['description']['content'])) {
                $description = $facility['description']['content'];
            } elseif (isset($facility['description']) && is_string($facility['description'])) {
                $description = $facility['description'];
            }
            $out['amenities'][] = [
                'hotel_code'           => $code,
                'facility_code'        => (int) $facility['facilityCode'],
                'facility_description' => $description,
                'facility_group_code'  => $facility['facilityGroupCode'] ?? null,
                'distance'             => isset($facility['distance']) ? (int) $facility['distance'] : null,
                'order_by'             => isset($facility['order']) ? (int) $facility['order'] : null,
                'ind_fee'              => hotelbedsFastBool($facility, 'indFee'),
                'ind_yes_or_no'        => hotelbedsFastBool($facility, 'indYesOrNo'),
                'voucher'              => hotelbedsFastBool($facility, 'voucher'),
                'fee_amount'           => isset($facility['amount']) && is_numeric($facility['amount'])
                    ? round((float) $facility['amount'], 2) : null,
                'fee_currency'         => !empty($facility['currency']) ? (string) $facility['currency'] : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        foreach ($images as $index => $image) {
            $out['images'][] = [
                'hotel_code'  => $code,
                'image_type'  => $image['imageTypeCode'] ?? 'GEN',
                'image_url'   => $image['path'] ?? '',
                'image_order' => $image['order'] ?? $index,
                'room_code'   => $image['roomCode'] ?? null,
                'room_type'   => $image['roomType'] ?? null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        foreach ($rooms as $room) {
            if (!isset($room['roomCode'])) {
                continue;
            }
            if (isset($room['description'])) {
                $roomDescription = is_array($room['description'])
                    ? ($room['description']['content'] ?? '')
                    : $room['description'];
            } elseif (isset($room['roomDescription']['content'])) {
                $roomDescription = $room['roomDescription']['content'];
            } else {
                $roomDescription = '';
            }

            $roomFacilities = [];
            if (isset($room['roomFacilities']) && is_array($room['roomFacilities'])) {
                foreach ($room['roomFacilities'] as $facility) {
                    if (isset($facility['facilityCode'])) {
                        $roomFacilities[] = [
                            'code'        => $facility['facilityCode'],
                            'description' => $facility['description'] ?? '',
                            'groupCode'   => $facility['facilityGroupCode'] ?? null,
                        ];
                    }
                }
            }

            $out['rooms'][] = [
                'hotel_code'      => $code,
                'room_code'       => $room['roomCode'],
                'room_type'       => $room['roomType'] ?? $room['type'] ?? '',
                'characteristic'  => $room['characteristicCode'] ?? $room['characteristic'] ?? '',
                'description'     => $roomDescription,
                'min_pax'         => isset($room['minPax']) ? (int) $room['minPax'] : 1,
                'max_pax'         => isset($room['maxPax']) ? (int) $room['maxPax'] : 2,
                'max_adults'      => isset($room['maxAdults']) ? (int) $room['maxAdults'] : 2,
                'max_children'    => isset($room['maxChildren']) ? (int) $room['maxChildren'] : 0,
                'room_facilities' => !empty($roomFacilities) ? json_encode($roomFacilities) : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if (!empty($hotel['issues']) && is_array($hotel['issues'])) {
            foreach ($hotel['issues'] as $issue) {
                $out['issues'][] = [
                    'hotel_code'  => $code,
                    'issue_code'  => isset($issue['issueCode']) ? (string) $issue['issueCode']
                        : (isset($issue['code']) ? (string) $issue['code'] : null),
                    'issue_type'  => isset($issue['issueType']) ? (string) $issue['issueType']
                        : (isset($issue['type']) ? (string) $issue['type'] : null),
                    'description' => hotelbedsFastText($issue['description'] ?? $issue['name'] ?? ''),
                    'date_from'   => !empty($issue['dateFrom']) ? substr((string) $issue['dateFrom'], 0, 10) : null,
                    'date_to'     => !empty($issue['dateTo']) ? substr((string) $issue['dateTo'], 0, 10) : null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        if (!empty($hotel['terminals']) && is_array($hotel['terminals'])) {
            foreach ($hotel['terminals'] as $terminal) {
                $out['terminals'][] = [
                    'hotel_code'    => $code,
                    'terminal_code' => isset($terminal['terminalCode']) ? (string) $terminal['terminalCode']
                        : (isset($terminal['code']) ? (string) $terminal['code'] : null),
                    'terminal_type' => isset($terminal['terminalType']) ? (string) $terminal['terminalType']
                        : (isset($terminal['type']) ? (string) $terminal['type'] : null),
                    'distance'      => isset($terminal['distance']) ? (int) $terminal['distance'] : null,
                    'description'   => hotelbedsFastText($terminal['description'] ?? $terminal['name'] ?? ''),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// TSV serialisation (the LOAD DATA wire format)
// ---------------------------------------------------------------------------

/**
 * Serialise rows to the tab-separated form LOAD DATA reads back losslessly:
 * NULL is \N, and backslash / tab / newline / CR / NUL are backslash-escaped.
 */
function hotelbedsFastWriteTsv($handle, array $rows, array $columns): int
{
    static $search  = ["\\", "\t", "\n", "\r", "\0"];
    static $replace = ['\\\\', '\\t', '\\n', '\\r', '\\0'];

    $bytes = 0;
    $buffer = '';
    foreach ($rows as $row) {
        $fields = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            if ($value === null) {
                $fields[] = '\N';
                continue;
            }
            $fields[] = str_replace($search, $replace, (string) $value);
        }
        $buffer .= implode("\t", $fields) . "\n";
        if (strlen($buffer) >= 4194304) {
            $bytes += (int) fwrite($handle, $buffer);
            $buffer = '';
        }
    }
    if ($buffer !== '') {
        $bytes += (int) fwrite($handle, $buffer);
    }
    return $bytes;
}

// ---------------------------------------------------------------------------
// Connections
// ---------------------------------------------------------------------------

/**
 * Raw PDO handle for the Hotelbeds database.
 *
 * The engine deliberately bypasses Medoo: it needs LOAD DATA LOCAL INFILE,
 * multi-statement-free session tuning, and one connection per forked worker.
 */
function hotelbedsFastPdo(array $cfg): PDO
{
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
    if (!empty($cfg['port'])) {
        $dsn .= ";port={$cfg['port']}";
    }
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_LOCAL_INFILE => true,
    ]);
    return $pdo;
}

/**
 * Session tuning for a bulk-load connection.
 *
 * Every one of these is session-scoped and reverts when the connection closes,
 * so an import can never leave the server detuned for the running site.
 */
function hotelbedsFastTuneSession(PDO $pdo): array
{
    $applied = [];
    $statements = [
        'unique_checks'      => 'SET SESSION unique_checks=0',
        'foreign_key_checks' => 'SET SESSION foreign_key_checks=0',
        'sql_log_bin'        => 'SET SESSION sql_log_bin=0',
        // No innodb_flush_log_at_trx_commit here: it is GLOBAL-only, and relaxing
        // it would weaken durability for the live site, not just this import.
        // It also would not buy much — a bulk load commits once per shard
        // (~2k commits for the whole catalogue), not once per row, which is what
        // made that setting matter for the row-at-a-time path this replaced.
        'sort_buffer'        => 'SET SESSION sort_buffer_size=8388608',
        'net_timeout'        => 'SET SESSION net_write_timeout=600, net_read_timeout=600',
    ];
    foreach ($statements as $key => $sql) {
        try {
            $pdo->exec($sql);
            $applied[] = $key;
        } catch (Throwable $e) {
            // sql_log_bin needs SUPER, which managed hosts often withhold.
            // Skipping any of these costs speed, never correctness.
        }
    }
    return $applied;
}

// ---------------------------------------------------------------------------
// Staging tables
// ---------------------------------------------------------------------------

function hotelbedsFastStageName(string $table): string
{
    return $table . '__stg';
}

/**
 * Build empty staging clones with every secondary index and foreign key
 * removed, so each LOAD DATA is a pure sequential append with no per-row
 * B-tree maintenance. Indexes are rebuilt in one sorted pass at finalize time.
 */
function hotelbedsFastPrepareStaging(PDO $pdo, string $schema): array
{
    $report = [];
    foreach (hotelbedsFastTables() as $key => $table) {
        $stage = hotelbedsFastStageName($table);
        $pdo->exec("DROP TABLE IF EXISTS `$stage`");
        $pdo->exec("CREATE TABLE `$stage` LIKE `$table`");

        // Foreign keys first — they own indexes that cannot be dropped before them.
        $fks = $pdo->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $fks->execute([$schema, $stage]);
        foreach ($fks->fetchAll(PDO::FETCH_COLUMN) as $name) {
            try { $pdo->exec("ALTER TABLE `$stage` DROP FOREIGN KEY `$name`"); } catch (Throwable $e) {}
        }

        $dropped = [];
        foreach ($pdo->query("SHOW INDEX FROM `$stage`")->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $name = $index['Key_name'];
            if ($name === 'PRIMARY' || isset($dropped[$name])) {
                continue; // keep the auto_increment PK: it is a sequential append
            }
            $dropped[$name] = true;
            try { $pdo->exec("ALTER TABLE `$stage` DROP INDEX `$name`"); } catch (Throwable $e) {}
        }
        $report[$key] = ['stage' => $stage, 'indexes_dropped' => array_keys($dropped)];
    }
    return $report;
}

/**
 * Rebuild on a staging table every secondary index its live counterpart has.
 *
 * Building them after the data has landed is one sorted pass per table instead
 * of tens of millions of random B-tree descents during the load. Foreign keys
 * are deliberately NOT rebuilt here: a constraint created on `x__stg` would be
 * rewritten by RENAME to point at the retired `x__old` table, so they are
 * restored after the swap by hotelbedsFastRestoreForeignKeys().
 */
function hotelbedsFastRebuildIndexes(PDO $pdo, string $table): array
{
    $stage = hotelbedsFastStageName($table);

    $indexes = [];
    foreach ($pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['Key_name'] === 'PRIMARY') {
            continue;
        }
        $indexes[$row['Key_name']]['unique'] = ((int) $row['Non_unique'] === 0);
        $indexes[$row['Key_name']]['cols'][(int) $row['Seq_in_index']] =
            '`' . $row['Column_name'] . '`' . ($row['Sub_part'] ? '(' . (int) $row['Sub_part'] . ')' : '');
    }

    $clauses = [];
    foreach ($indexes as $name => $definition) {
        ksort($definition['cols']);
        $clauses[] = 'ADD ' . ($definition['unique'] ? 'UNIQUE ' : '')
            . "INDEX `$name` (" . implode(',', $definition['cols']) . ')';
    }
    if (!$clauses) {
        return ['seconds' => 0.0, 'indexes' => 0];
    }

    // One ALTER for every index on the table so InnoDB sorts the data once.
    $t0 = microtime(true);
    $pdo->exec("ALTER TABLE `$stage` " . implode(', ', $clauses));
    return ['seconds' => round(microtime(true) - $t0, 2), 'indexes' => count($clauses)];
}

/**
 * Read the foreign keys defined on the live tables, before the swap replaces
 * them, so they can be recreated on the incoming tables afterwards.
 */
function hotelbedsFastCaptureForeignKeys(PDO $pdo, string $schema): array
{
    $sql = $pdo->prepare(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            AND k.TABLE_NAME IN ('" . implode("','", array_values(hotelbedsFastTables())) . "')"
    );
    $sql->execute([$schema]);
    return $sql->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recreate captured foreign keys on the freshly swapped-in tables.
 *
 * Integrity is already guaranteed by construction — child rows are only ever
 * written for hotels present in the same staged snapshot — so this restores the
 * ON DELETE CASCADE behaviour rather than fixing data.
 */
function hotelbedsFastRestoreForeignKeys(PDO $pdo, array $foreignKeys): array
{
    // foreign_key_checks=0 is what makes ALGORITHM=INPLACE legal for ADD
    // FOREIGN KEY. Without it InnoDB falls back to ALGORITHM=COPY and rebuilds
    // the whole child table — measured at 299s across the five constraints on a
    // full catalogue, versus well under a second in place. Skipping the
    // validation scan is safe here precisely because it is redundant: the
    // pre-swap orphan check already proved every child row resolves.
    try { $pdo->exec('SET SESSION foreign_key_checks=0'); } catch (Throwable $e) {}

    $timings = [];
    foreach ($foreignKeys as $fk) {
        $t0 = microtime(true);
        $clause = "ALTER TABLE `{$fk['TABLE_NAME']}` ADD FOREIGN KEY (`{$fk['COLUMN_NAME']}`)"
            . " REFERENCES `{$fk['REFERENCED_TABLE_NAME']}` (`{$fk['REFERENCED_COLUMN_NAME']}`)"
            . ' ON DELETE ' . ($fk['DELETE_RULE'] ?: 'CASCADE');
        try {
            $pdo->exec($clause . ', ALGORITHM=INPLACE, LOCK=NONE');
            $timings[$fk['TABLE_NAME']] = round(microtime(true) - $t0, 2);
            continue;
        } catch (Throwable $e) {
            // Older/stricter servers may refuse the in-place algorithm.
        }
        try {
            $pdo->exec($clause);
            $timings[$fk['TABLE_NAME']] = round(microtime(true) - $t0, 2);
        } catch (Throwable $e) {
            $timings[$fk['TABLE_NAME']] = 'failed: ' . $e->getMessage();
        }
    }
    return $timings;
}

/**
 * Publish the staged snapshot.
 *
 * A single RENAME TABLE is atomic across all six tables: readers see either the
 * entire old catalogue or the entire new one, never a mixture, and never an
 * empty table. The retired copies are dropped afterwards.
 */
function hotelbedsFastSwap(PDO $pdo): array
{
    $tables = array_values(hotelbedsFastTables());
    $pairs = [];
    foreach ($tables as $table) {
        $stage = hotelbedsFastStageName($table);
        $old   = $table . '__old';
        $pdo->exec("DROP TABLE IF EXISTS `$old`");
        $pairs[] = "`$table` TO `$old`";
        $pairs[] = "`$stage` TO `$table`";
    }

    $t0 = microtime(true);
    $pdo->exec('RENAME TABLE ' . implode(', ', $pairs));
    $seconds = round(microtime(true) - $t0, 3);

    // Children first: the retired parent may still be referenced by their FKs.
    foreach (array_reverse($tables) as $table) {
        try { $pdo->exec("DROP TABLE IF EXISTS `{$table}__old`"); } catch (Throwable $e) {}
    }
    return ['seconds' => $seconds, 'tables' => count($tables)];
}

// ---------------------------------------------------------------------------
// Bulk load
// ---------------------------------------------------------------------------

/**
 * Load one TSV shard into a staging table.
 *
 * LOAD DATA LOCAL INFILE is roughly two orders of magnitude cheaper per row
 * than prepared INSERTs. Where the server forbids it (local_infile=OFF on some
 * managed hosts) this falls back to extended multi-row INSERTs, sized to stay
 * under max_allowed_packet — still far faster than the row-at-a-time path it
 * replaces, just not as fast as the file load.
 */
function hotelbedsFastLoadShard(PDO $pdo, string $stage, string $file, array $columns, int $maxPacket): array
{
    $columnList = '`' . implode('`,`', $columns) . '`';
    try {
        $t0 = microtime(true);
        $sql = 'LOAD DATA LOCAL INFILE ' . $pdo->quote($file) . " INTO TABLE `$stage`"
             . " CHARACTER SET utf8mb4"
             . " FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\'"
             . " LINES TERMINATED BY '\\n' ($columnList)";
        $rows = $pdo->exec($sql);
        return ['method' => 'load_data', 'rows' => (int) $rows, 'seconds' => round(microtime(true) - $t0, 3)];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'infile') === false && stripos($message, 'local') === false) {
            throw $e; // a real load error (bad column, disk full) — do not mask it
        }
    }

    // Fallback path: replay the shard as extended INSERTs.
    $t0 = microtime(true);
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new RuntimeException("Cannot reopen shard $file");
    }
    $budget = max(262144, $maxPacket - 65536);
    $rows = 0;
    $batch = [];
    $bytes = 0;
    $flush = function () use ($pdo, $stage, $columnList, $columns, &$batch, &$bytes, &$rows) {
        if (!$batch) {
            return;
        }
        $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = "INSERT INTO `$stage` ($columnList) VALUES " . implode(',', array_fill(0, count($batch), $placeholder));
        $statement = $pdo->prepare($sql);
        $i = 1;
        foreach ($batch as $row) {
            foreach ($row as $value) {
                $statement->bindValue($i++, $value);
            }
        }
        $statement->execute();
        $rows += count($batch);
        $batch = [];
        $bytes = 0;
    };
    static $search  = ['\\\\', '\\t', '\\n', '\\r', '\\0'];
    static $replace = ["\\", "\t", "\n", "\r", "\0"];
    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\n");
        if ($line === '') {
            continue;
        }
        $fields = explode("\t", $line);
        foreach ($fields as $index => $field) {
            $fields[$index] = ($field === '\N') ? null : str_replace($search, $replace, $field);
        }
        $batch[] = $fields;
        $bytes += strlen($line);
        if ($bytes >= $budget) {
            $flush();
        }
    }
    $flush();
    fclose($handle);
    return ['method' => 'insert_fallback', 'rows' => $rows, 'seconds' => round(microtime(true) - $t0, 3)];
}

// ---------------------------------------------------------------------------
// Content API paging
// ---------------------------------------------------------------------------

function hotelbedsFastBaseUrl(string $environment): string
{
    return $environment === 'live'
        ? 'https://api.hotelbeds.com/hotel-content-api/1.0'
        : 'https://api.test.hotelbeds.com/hotel-content-api/1.0';
}

/** Build a configured, not-yet-executed cURL handle for one hotels page. */
function hotelbedsFastPageHandle(array $cfg, int $from, int $to)
{
    $query = [
        'from'                 => $from,
        'to'                   => $to,
        'fields'               => 'all',
        'language'             => 'ENG',
        'useSecondaryLanguage' => 'true',
    ];
    if (!empty($cfg['last_update_time'])) {
        // Incremental mode: only hotels touched since this date come back.
        $query['lastUpdateTime'] = $cfg['last_update_time'];
    }

    $timestamp = time();
    $signature = hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => hotelbedsFastBaseUrl($cfg['environment']) . '/hotels?' . http_build_query($query),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Api-key: ' . $cfg['api_key'],
            'X-Signature: ' . $signature,
        ],
    ]);
    return $ch;
}

/** HTTP statuses worth retrying: transport hiccups, throttling, gateway blips. */
function hotelbedsFastIsRetryable(int $httpCode, int $curlErrno): bool
{
    return $curlErrno !== 0 || in_array($httpCode, [0, 408, 425, 429, 500, 502, 503, 504], true);
}

// ---------------------------------------------------------------------------
// Spool directory layout
// ---------------------------------------------------------------------------




// ---------------------------------------------------------------------------
// Fetch worker: pages -> TSV shards
// ---------------------------------------------------------------------------


// ---------------------------------------------------------------------------
// Loader worker: TSV shards -> staging tables
// ---------------------------------------------------------------------------



// ---------------------------------------------------------------------------
// Rate comments (73k records — the other browser-driven serial loop)
// ---------------------------------------------------------------------------

/**
 * Import the whole rate-comment catalogue concurrently into a staging table,
 * then swap it in.
 *
 * The old path fetched 200 records per browser round-trip and inserted them one
 * row at a time (~370 sequential HTTP turns). This uses pages of 1000 with
 * several in flight, and a single bulk load.
 */
function hotelbedsFastImportRateComments(array $cfg, callable $log = null): array
{
    $pageSize    = 1000; // API maximum for this endpoint
    $concurrency = max(1, (int) $cfg['concurrency']);
    $base        = hotelbedsFastBaseUrl($cfg['environment']);
    $spool       = $cfg['spool'];
    $file        = "$spool/rate_comments.tsv";
    $columns     = ['incoming','code','hotel_code','rate_codes','date_start','date_end','description'];
    $log = $log ?: function () {};

    $handleFor = function (int $from) use ($base, $cfg, $pageSize) {
        $timestamp = time();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . '/types/ratecomments?' . http_build_query(['from' => $from, 'to' => $from + $pageSize - 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Api-key: ' . $cfg['api_key'],
                'X-Signature: ' . hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp),
            ],
        ]);
        return $ch;
    };

    // First page also tells us how many there are.
    $ch = $handleFor(1);
    $body = curl_exec($ch);
    if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        curl_close($ch);
        return ['success' => false, 'error' => 'rate comments: first page failed', 'rows' => 0];
    }
    curl_close($ch);
    $first = json_decode($body, true);
    unset($body);
    $total = (int) ($first['total'] ?? 0);

    $out = fopen($file, 'w');
    $rows = 0;
    $flatten = function (array $payload) use ($out, $columns, &$rows) {
        $flat = [];
        foreach ($payload['rateComments'] ?? [] as $item) {
            $incoming = isset($item['incoming']) ? (string) $item['incoming'] : '';
            $code     = isset($item['code']) ? (string) $item['code'] : '';
            if ($incoming === '' || $code === '') {
                continue;
            }
            $hotelCode = isset($item['hotel']) ? (string) $item['hotel'] : null;
            foreach (($item['commentsByRates'] ?? []) as $byRate) {
                $rateCodesRaw = $byRate['rateCodes'] ?? '';
                $rateCodes = is_array($rateCodesRaw)
                    ? implode(' ', array_map('strval', $rateCodesRaw))
                    : trim((string) $rateCodesRaw);
                foreach (($byRate['comments'] ?? []) as $comment) {
                    $description = hotelbedsFastText($comment['description'] ?? '');
                    if ($description === '') {
                        continue;
                    }
                    $flat[] = [
                        'incoming'    => $incoming,
                        'code'        => $code,
                        'hotel_code'  => $hotelCode,
                        'rate_codes'  => $rateCodes,
                        'date_start'  => !empty($comment['dateStart']) ? substr((string) $comment['dateStart'], 0, 10) : null,
                        'date_end'    => !empty($comment['dateEnd']) ? substr((string) $comment['dateEnd'], 0, 10) : null,
                        'description' => $description,
                    ];
                }
            }
        }
        if ($flat) {
            hotelbedsFastWriteTsv($out, $flat, $columns);
            $rows += count($flat);
        }
    };
    $flatten($first);
    unset($first);

    $queue = [];
    for ($from = $pageSize + 1; $from <= max($total, 1); $from += $pageSize) {
        $queue[] = $from;
    }

    $multi = curl_multi_init();
    $inFlight = 0;
    $launch = function () use (&$queue, &$inFlight, $multi, $concurrency, $handleFor) {
        while ($inFlight < $concurrency && $queue) {
            curl_multi_add_handle($multi, $handleFor(array_shift($queue)));
            $inFlight++;
        }
    };
    $launch();
    while ($inFlight > 0) {
        do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $inFlight--;
            if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $payload = json_decode(curl_multi_getcontent($ch), true);
                if (is_array($payload)) {
                    $flatten($payload);
                }
                unset($payload);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $launch();
        }
    }
    curl_multi_close($multi);
    fclose($out);

    $pdo = hotelbedsFastPdo($cfg['db']);
    hotelbedsFastTuneSession($pdo);
    $stage = hotelbedsFastStageName('hotelbeds_rate_comments');
    $pdo->exec("DROP TABLE IF EXISTS `$stage`");
    $pdo->exec("CREATE TABLE `$stage` LIKE `hotelbeds_rate_comments`");
    foreach ($pdo->query("SHOW INDEX FROM `$stage`")->fetchAll(PDO::FETCH_ASSOC) as $index) {
        if ($index['Key_name'] !== 'PRIMARY') {
            try { $pdo->exec("ALTER TABLE `$stage` DROP INDEX `{$index['Key_name']}`"); } catch (Throwable $e) {}
        }
    }
    $result = hotelbedsFastLoadShard($pdo, $stage, $file, $columns, 1048576);
    hotelbedsFastRebuildIndexes($pdo, 'hotelbeds_rate_comments');
    $pdo->exec("DROP TABLE IF EXISTS `hotelbeds_rate_comments__old`");
    $pdo->exec("RENAME TABLE `hotelbeds_rate_comments` TO `hotelbeds_rate_comments__old`, `$stage` TO `hotelbeds_rate_comments`");
    $pdo->exec("DROP TABLE IF EXISTS `hotelbeds_rate_comments__old`");
    @unlink($file);
    $log("rate comments: $rows rows from $total records ({$result['method']})");

    return ['success' => true, 'rows' => $rows, 'records' => $total, 'method' => $result['method']];
}

// ---------------------------------------------------------------------------
// Verification and repair (run before anything is published)
// ---------------------------------------------------------------------------

/**
 * Fetch specific hotels by code and append them to staging.
 *
 * Used to repair the two things paging cannot guarantee on its own: pages that
 * failed every retry, and hotels the API shifted out of the window while the
 * run was walking it.
 */
function hotelbedsFastRepairCodes(array $cfg, PDO $pdo, array $codes): array
{
    if (!$codes) {
        return ['repaired' => 0, 'requested' => 0, 'failed_batches' => 0];
    }
    $tables  = hotelbedsFastTables();
    $columns = hotelbedsFastColumns();
    $now     = date('Y-m-d H:i:s');
    $base    = hotelbedsFastBaseUrl($cfg['environment']);

    $codes = array_values(array_unique($codes));
    $repaired = 0;
    $failed   = 0;

    // 100 per request. A `codes=` query without an explicit from/to silently
    // returns only the first 100 hotels — HTTP 200, `total` reporting the full
    // count, and no error — so batching larger than that quietly recovered half
    // of what it was asked for. from/to is sent as well to lift that cap.
    foreach (array_chunk($codes, 100) as $batch) {
        $wanted = count($batch);
        $received = 0;

        for ($attempt = 1; $attempt <= 3 && $received < $wanted; $attempt++) {
            $timestamp = time();
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $base . '/hotels?' . http_build_query([
                    'from'                 => 1,
                    'to'                   => $wanted,
                    'fields'               => 'all',
                    'language'             => 'ENG',
                    'useSecondaryLanguage' => 'true',
                    'codes'                => implode(',', $batch),
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_ENCODING       => 'gzip',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Api-key: ' . $cfg['api_key'],
                    'X-Signature: ' . hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp),
                ],
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($httpCode !== 200 || $errno !== 0) {
                if ($attempt < 3 && hotelbedsFastIsRetryable($httpCode, $errno)) {
                    usleep(500000 * $attempt);
                    continue;
                }
                break;
            }

            $data = json_decode($body, true);
            unset($body);
            if (!isset($data['hotels']) || !$data['hotels']) {
                break;
            }

            $rows = hotelbedsFastTransform($data['hotels'], (int) $cfg['sync_run_id'], $now, (bool) $cfg['store_raw_json']);
            unset($data);
            foreach ($rows as $key => $tableRows) {
                if (!$tableRows) {
                    continue;
                }
                $file = $cfg['spool'] . "/repair_{$key}_" . getmypid() . '.tsv';
                $handle = fopen($file, 'w');
                hotelbedsFastWriteTsv($handle, $tableRows, $columns[$key]);
                fclose($handle);
                try {
                    hotelbedsFastLoadShard($pdo, hotelbedsFastStageName($tables[$key]), $file, $columns[$key], 1048576);
                } finally {
                    @unlink($file);
                }
            }
            $received = count($rows['hotels']);
            $repaired += $received;
            unset($rows);
        }

        if ($received < $wanted) {
            // Reported, not swallowed: the caller re-runs the sweep and the
            // completeness gate refuses to publish while hotels are still absent.
            $failed++;
        }
    }

    return ['repaired' => $repaired, 'requested' => count($codes), 'failed_batches' => $failed];
}

/**
 * Remove hotels that landed in staging more than once.
 *
 * Disjoint from/to windows make this impossible in a quiet catalogue, but an
 * incremental run (or a catalogue edited mid-walk) can shift pagination and
 * repeat a hotel. Every row for an affected code is dropped from all six tables
 * and the hotel is re-fetched by code, so the survivor is one complete copy
 * rather than a half-merged pair.
 */
function hotelbedsFastRepairDuplicates(array $cfg, PDO $pdo): array
{
    $tables = hotelbedsFastTables();
    $stage  = hotelbedsFastStageName($tables['hotels']);
    $duplicates = $pdo->query(
        "SELECT hotel_code FROM `$stage` GROUP BY hotel_code HAVING COUNT(*) > 1"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$duplicates) {
        return ['duplicates' => 0, 'repaired' => 0];
    }
    foreach (array_chunk($duplicates, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($tables as $table) {
            $pdo->prepare("DELETE FROM `" . hotelbedsFastStageName($table) . "` WHERE hotel_code IN ($in)")->execute($chunk);
        }
    }
    $repair = hotelbedsFastRepairCodes($cfg, $pdo, $duplicates);
    return ['duplicates' => count($duplicates), 'repaired' => $repair['repaired']];
}

/**
 * Gate the swap on the staged snapshot actually being complete.
 *
 * A run that fetched 60% of the catalogue must never replace a good 100%
 * catalogue, so a shortfall past the tolerance aborts publication and leaves
 * the live tables exactly as they were.
 */
function hotelbedsFastVerifyStaging(PDO $pdo, int $apiTotal, float $tolerance = 0.005): array
{
    $tables = hotelbedsFastTables();
    $counts = [];
    foreach ($tables as $key => $table) {
        $counts[$key] = (int) $pdo->query('SELECT COUNT(*) FROM `' . hotelbedsFastStageName($table) . '`')->fetchColumn();
    }
    $distinct = (int) $pdo->query('SELECT COUNT(DISTINCT hotel_code) FROM `'
        . hotelbedsFastStageName($tables['hotels']) . '`')->fetchColumn();

    // Children must never reference a hotel the snapshot does not contain.
    //
    // Checked over DISTINCT hotel_code rather than every child row: the answer
    // is identical, but it compares ~305k values instead of ~44M, and both
    // sides resolve from the index. Run this after the indexes are rebuilt —
    // against an index-free staging table it degrades to a nested-loop join.
    $orphans = [];
    $parent = hotelbedsFastStageName($tables['hotels']);
    foreach (['amenities', 'images', 'rooms', 'issues', 'terminals'] as $key) {
        if ($counts[$key] === 0) {
            continue;
        }
        $child = hotelbedsFastStageName($tables[$key]);
        $orphans[$key] = (int) $pdo->query(
            "SELECT COUNT(*) FROM (SELECT DISTINCT hotel_code FROM `$child`) c
               LEFT JOIN `$parent` h ON h.hotel_code = c.hotel_code
              WHERE h.hotel_code IS NULL"
        )->fetchColumn();
    }

    $shortfall = $apiTotal > 0 ? ($apiTotal - $distinct) / $apiTotal : 0.0;
    $ok = ($apiTotal <= 0 || $shortfall <= $tolerance) && array_sum($orphans) === 0 && $counts['hotels'] > 0;

    return [
        'ok'             => $ok,
        'counts'         => $counts,
        'distinct_codes' => $distinct,
        'api_total'      => $apiTotal,
        'shortfall_pct'  => round($shortfall * 100, 3),
        'orphans'        => $orphans,
    ];
}


/**
 * Publish an incremental snapshot by merging it into the live tables.
 *
 * An incremental run stages only the hotels the API reports as changed, so it
 * must NOT be published with hotelbedsFastSwap() — that would replace the whole
 * catalogue with the changed subset. Each staged hotel replaces its live
 * counterpart instead, and every other hotel is left untouched.
 *
 * Old child rows are removed with explicit set-based DELETEs rather than by
 * letting ON DELETE CASCADE do it. The cascade walks the foreign key per row,
 * which measured ~200s for a typical day's changes; the joins below do the same
 * work as five index range scans.
 *
 * The work is committed in chunks of hotel codes. Each chunk is atomic, so an
 * interruption leaves every individual hotel either wholly old or wholly new —
 * never a hotel whose images belong to one version and rooms to another — while
 * keeping the undo log bounded instead of holding millions of rows open.
 */
function hotelbedsFastMerge(PDO $pdo, int $chunkSize = 5000): array
{
    $tables      = hotelbedsFastTables();
    $columns     = hotelbedsFastColumns();
    $stageHotels = hotelbedsFastStageName($tables['hotels']);

    $t0 = microtime(true);
    $codes = $pdo->query("SELECT hotel_code FROM `$stageHotels`")->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['replaced' => 0, 'inserted' => array_fill_keys(array_keys($tables), 0), 'chunks' => 0];

    // Deletes are scoped to exactly the codes being replaced, so the cascade is
    // redundant work rather than a safety net; children are inserted back in the
    // same transaction that removed them.
    $pdo->exec('SET SESSION foreign_key_checks=0');

    foreach (array_chunk($codes, $chunkSize) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->beginTransaction();
        try {
            foreach (['amenities', 'images', 'rooms', 'issues', 'terminals'] as $key) {
                $pdo->prepare("DELETE FROM `{$tables[$key]}` WHERE hotel_code IN ($in)")->execute($chunk);
            }
            $pdo->prepare("DELETE FROM `{$tables['hotels']}` WHERE hotel_code IN ($in)")->execute($chunk);

            foreach ($tables as $key => $table) {
                $list  = '`' . implode('`,`', $columns[$key]) . '`';
                $stage = hotelbedsFastStageName($table);
                $statement = $pdo->prepare(
                    "INSERT INTO `$table` ($list) SELECT $list FROM `$stage` WHERE hotel_code IN ($in)"
                );
                $statement->execute($chunk);
                $counts['inserted'][$key] += $statement->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $counts['replaced'] += count($chunk);
        $counts['chunks']++;
    }

    foreach ($tables as $table) {
        try { $pdo->exec('DROP TABLE IF EXISTS `' . hotelbedsFastStageName($table) . '`'); } catch (Throwable $e) {}
    }

    $counts['seconds'] = round(microtime(true) - $t0, 2);
    return $counts;
}

// ---------------------------------------------------------------------------
// Process control
// ---------------------------------------------------------------------------




// ===========================================================================
// Turbo import — browser-driven, parallel, bulk-loaded.
//
// The /process loop further down walks the catalogue 25 hotels per request,
// one request at a time, with a 1.5s pause between them. These endpoints do
// the same job with pages of 1000 fetched several at a time and written with
// LOAD DATA INFILE into index-free staging tables, then published in one
// atomic swap. The browser is the orchestrator: it claims pages until there
// are none left, so no CLI process and no cron entry are required.
//
// Every endpoint releases the PHP session lock before doing any work —
// config.php calls session_start(), and without session_write_close() the
// session file lock would serialise the parallel requests and undo the point
// of the exercise.
// ===========================================================================

/** Shared setup for every turbo endpoint: JSON out, no session lock, no time limit. */
function hotelbedsTurboBoot(): void
{
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    // Release the session lock so sibling requests can run at the same time.
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ini_set('display_errors', '0');
    @error_reporting(0);
    @set_time_limit(600);
    // One page decodes ~78 MB of JSON into ~400 MB of PHP arrays, and the
    // transformed rows briefly exist alongside it. 1 GB was not enough for the
    // densest pages and they failed mid-run.
    @ini_set('memory_limit', '1536M');
    header('Content-Type: application/json');
}

function hotelbedsTurboFail(string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

/** A raw PDO on the Hotelbeds database, with LOCAL INFILE enabled. */
function hotelbedsTurboPdo($db): PDO
{
    $module = $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelbeds', 'type' => 'stays',
    ]);
    if (!$module || empty($module['host']) || empty($module['database'])) {
        // No separate database configured — reuse the main connection's PDO.
        return $db->pdo;
    }
    return hotelbedsFastPdo([
        'host'     => $module['host'],
        'database' => $module['database'],
        'username' => $module['username'],
        'password' => $module['password'] ?? '',
    ]);
}

/** Credentials + environment for the Content API calls. */
function hotelbedsTurboConfig($db, array $state = []): array
{
    $module = $db->get('modules', ['c1', 'c2', 'dev_mode'], ['name' => 'hotelbeds', 'type' => 'stays']);
    return [
        'api_key'          => trim((string) ($module['c1'] ?? '')),
        'api_secret'       => trim((string) ($module['c2'] ?? '')),
        'environment'      => ((string) ($module['dev_mode'] ?? '1') === '1') ? 'test' : 'live',
        'sync_run_id'      => (int) ($state['run_id'] ?? 0),
        'store_raw_json'   => true,
        'last_update_time' => $state['last_update_time'] ?? null,
        'max_attempts'     => 3,
        'concurrency'      => 1,
    ];
}

/** The current in-progress turbo run, or null. */
function hotelbedsTurboRun(PDO $pdo): ?array
{
    $row = $pdo->query(
        "SELECT * FROM hotelbeds_import_log WHERE status = 'in_progress' ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['state'] = json_decode((string) $row['import_state'], true) ?: [];
    return $row;
}

/** Where the browser writes TSV shards before they are loaded. */
/**
 * A writable directory for the TSV batches on their way into the database.
 *
 * The system temp directory is tried first but is not always writable by the
 * web user (open_basedir, or a locked-down PHP-FPM pool), so the app's own
 * cache/ and logs/ directories are used as fallbacks. Each file lives only for
 * the duration of one LOAD DATA and is deleted immediately afterwards.
 */
function hotelbedsTurboSpool(int $runId): string
{
    static $resolved = [];
    if (isset($resolved[$runId])) {
        return $resolved[$runId];
    }

    $appRoot = dirname(__DIR__, 4);
    foreach ([sys_get_temp_dir(), $appRoot . '/cache', $appRoot . '/logs', $appRoot] as $base) {
        if (!$base) {
            continue;
        }
        $dir = rtrim($base, '/') . '/hotelbeds-turbo-' . $runId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            continue;
        }
        if (is_writable($dir)) {
            return $resolved[$runId] = $dir;
        }
    }

    throw new RuntimeException(
        'No writable directory for import batches. Make one of these writable by the web user: '
        . sys_get_temp_dir() . ', ' . $appRoot . '/cache, ' . $appRoot . '/logs'
    );
}

// ---------------------------------------------------------------------------
// begin — create the run, size the catalogue, build staging tables
// ---------------------------------------------------------------------------

$router->post('stays/hotelbeds/turbo/begin', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $medoo = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $pdo]);
        createHotelbedsSchema($medoo);

        // A turbo run needs an atomic page counter the parallel requests can
        // claim from. Added here so existing installs upgrade themselves.
        try {
            $pdo->query('SELECT turbo_cursor FROM hotelbeds_import_log LIMIT 1');
        } catch (Throwable $e) {
            $pdo->exec('ALTER TABLE hotelbeds_import_log ADD COLUMN turbo_cursor INT NOT NULL DEFAULT 0');
        }

        $mode = (($_POST['mode'] ?? 'fresh') === 'update') ? 'incremental' : 'full';

        $cfg = hotelbedsTurboConfig($db);
        if ($cfg['api_key'] === '' || $cfg['api_secret'] === '') {
            hotelbedsTurboFail('Hotelbeds API credentials are not configured.');
        }

        // Incremental: ask the API only for hotels changed since the last
        // completed run. Unchanged hotels are never sent over the network.
        $lastUpdateTime = null;
        if ($mode === 'incremental') {
            $lastUpdateTime = $_POST['since'] ?? null;
            if (!$lastUpdateTime) {
                $lastUpdateTime = $pdo->query(
                    "SELECT DATE(completed_at) FROM hotelbeds_import_log
                      WHERE status = 'completed' AND completed_at IS NOT NULL ORDER BY id DESC LIMIT 1"
                )->fetchColumn();
            }
            if (!$lastUpdateTime) {
                hotelbedsTurboFail(
                    'No completed import to sync from. Run a Fresh Install once first, '
                    . 'or pass a date to sync from.',
                    ['needs_full' => true]
                );
            }
            $lastUpdateTime = preg_replace('/[^0-9\-T:]/', '', (string) $lastUpdateTime);
            $cfg['last_update_time'] = $lastUpdateTime;
        }

        // Size the catalogue (or the changed subset).
        $probe = hotelbedsFastPageHandle($cfg, 1, 1);
        $body = curl_exec($probe);
        $code = (int) curl_getinfo($probe, CURLINFO_HTTP_CODE);
        curl_close($probe);
        if ($code !== 200) {
            hotelbedsTurboFail("Content API returned HTTP $code. Check credentials and Content API access.");
        }
        $apiTotal = (int) (json_decode($body, true)['total'] ?? 0);
        unset($body);
        if ($apiTotal < 1) {
            hotelbedsTurboFail('The Content API reported 0 hotels for this request.');
        }

        // Retire any stuck session, then open this one.
        $pdo->exec("UPDATE hotelbeds_import_log SET status = 'cancelled', updated_at = NOW() WHERE status = 'in_progress'");

        $totalPages = (int) ceil($apiTotal / HB_PAGE_SIZE);
        $state = [
            'engine'           => 'turbo',
            'mode'             => $mode,
            'phase'            => 'hotels',
            'start_time'       => time(),
            'processed'        => 0,
            'total'            => $apiTotal,
            'api_total'        => $apiTotal,
            'current_chunk'    => 0,
            'total_chunks'     => $totalPages,
            'paused'           => false,
            'last_update_time' => $lastUpdateTime,
            'current_operation' => 'Importing hotel content…',
            'logs'             => json_encode(['[' . date('H:i:s') . '] [TURBO] '
                . number_format($apiTotal) . ' hotels in ' . $totalPages . ' pages of ' . HB_PAGE_SIZE]),
        ];
        $insert = $pdo->prepare(
            "INSERT INTO hotelbeds_import_log (mode, status, import_state, turbo_cursor, started_at)
             VALUES (?, 'in_progress', ?, 0, NOW())"
        );
        $insert->execute([$mode === 'full' ? 'fresh' : 'update', json_encode($state)]);
        $runId = (int) $pdo->lastInsertId();

        // Foreign keys are recorded before staging is built so they can be put
        // back after the swap replaces the live tables.
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $foreignKeys = hotelbedsFastCaptureForeignKeys($pdo, $schema);
        $state['foreign_keys'] = $foreignKeys;
        $state['run_id'] = $runId;
        $state['schema'] = $schema;

        hotelbedsFastTuneSession($pdo);
        hotelbedsFastPrepareStaging($pdo, $schema);

        // Rate comments get their own staging table on a full run.
        $rateTotal = 0;
        if ($mode === 'full') {
            $stage = hotelbedsFastStageName('hotelbeds_rate_comments');
            $pdo->exec("DROP TABLE IF EXISTS `$stage`");
            $pdo->exec("CREATE TABLE `$stage` LIKE `hotelbeds_rate_comments`");
            foreach ($pdo->query("SHOW INDEX FROM `$stage`")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                if ($ix['Key_name'] !== 'PRIMARY') {
                    try { $pdo->exec("ALTER TABLE `$stage` DROP INDEX `{$ix['Key_name']}`"); } catch (Throwable $e) {}
                }
            }
            $rateTotal = hotelbedsTurboRateCommentsTotal($cfg);
        }
        $state['rate_total'] = $rateTotal;
        $state['rate_pages'] = (int) ceil($rateTotal / HB_PAGE_SIZE);

        $pdo->prepare('UPDATE hotelbeds_import_log SET import_state = ? WHERE id = ?')
            ->execute([json_encode($state), $runId]);

        echo json_encode([
            'success'     => true,
            'run_id'      => $runId,
            'mode'        => $mode,
            'api_total'   => $apiTotal,
            'total_pages' => $totalPages,
            'page_size'   => HB_PAGE_SIZE,
            'rate_pages'  => $state['rate_pages'],
            'since'       => $lastUpdateTime,
        ]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    exit;
});

// ---------------------------------------------------------------------------
// page — claim the next page, fetch it, load it into staging
// ---------------------------------------------------------------------------

$router->post('stays/hotelbeds/turbo/page', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if (!$run) {
            hotelbedsTurboFail('No import is running.');
        }
        if (!empty($run['state']['paused'])) {
            echo json_encode(['success' => true, 'paused' => true]);
            exit;
        }
        $state = $run['state'];
        $runId = (int) $run['id'];

        $apiTotal = (int) ($state['api_total'] ?? 0);

        if (isset($_POST['from'], $_POST['to'])) {
            // A retry of a page that failed. It must re-run the SAME range —
            // claiming a fresh page instead would leave that range permanently
            // unimported, and the counter has already moved past it.
            $from = max(1, (int) $_POST['from']);
            $to   = min((int) $_POST['to'], $apiTotal);
            $pageNo = (int) ceil($from / HB_PAGE_SIZE);

            // Drop anything a partially-successful earlier attempt wrote, so the
            // retry cannot double-insert this range's rows.
            hotelbedsTurboClearRange($pdo, $from, $to, $state);
        } else {
            // Atomic fetch-and-increment: whichever request gets here first takes
            // the next page, so several browser requests never collide on one page
            // and a refresh simply picks up where the counter stands.
            $pdo->exec("UPDATE hotelbeds_import_log SET turbo_cursor = LAST_INSERT_ID(turbo_cursor + 1) WHERE id = $runId");
            $pageNo = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $from = ($pageNo - 1) * HB_PAGE_SIZE + 1;
            if ($from > $apiTotal) {
                echo json_encode(['success' => true, 'done' => true]);
                exit;
            }
            $to = min($from + HB_PAGE_SIZE - 1, $apiTotal);
        }

        $cfg = hotelbedsTurboConfig($db, $state);
        hotelbedsFastTuneSession($pdo);

        $result = hotelbedsTurboLoadPage($cfg, $pdo, $runId, $from, $to);
        if (!$result['success']) {
            // Hand the page back so another request retries it rather than
            // leaving a hole in the catalogue.
            echo json_encode([
                'success'      => false,
                'retryable'    => true,
                'page'         => $pageNo,
                'from'         => $from,
                'to'           => $to,
                'error'        => $result['error'],
                'http_code'    => $result['http_code'] ?? 0,
                'retry_after'  => $result['retry_after'] ?? 0,
                'rate_limited' => !empty($result['rate_limited']),
            ]);
            exit;
        }

        // Counter increments are atomic, so parallel pages cannot lose updates
        // the way a read-modify-write of the JSON state would.
        $pdo->prepare('UPDATE hotelbeds_import_log SET hotels_imported = hotels_imported + ?, updated_at = NOW() WHERE id = ?')
            ->execute([$result['hotels'], $runId]);
        $processed = (int) $pdo->query("SELECT hotels_imported FROM hotelbeds_import_log WHERE id = $runId")->fetchColumn();

        echo json_encode([
            'success'   => true,
            'page'      => $pageNo,
            'from'      => $from,
            'to'        => $to,
            'hotels'    => $result['hotels'],
            'rows'      => $result['rows'],
            'processed' => $processed,
            'total'     => $apiTotal,
            'done'      => $to >= $apiTotal,
        ]);
    } catch (Throwable $e) {
        // Carry from/to through the failure too: without them the client
        // cannot retry this exact range and the log just says "Page ?-?".
        hotelbedsTurboFail($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
            array_filter([
                'retryable' => true,
                'from'      => $from ?? null,
                'to'        => $to ?? null,
            ], static function ($v) { return $v !== null; }));
    }
    exit;
});

/** Fetch one page of hotels and bulk-load it into the staging tables. */
function hotelbedsTurboLoadPage(array $cfg, PDO $pdo, int $runId, int $from, int $to): array
{
    $ch = hotelbedsFastPageHandle($cfg, $from, $to);
    // Keep the response headers: a 429 carries Retry-After, and honouring it is
    // far better than every worker guessing the same fixed delay and colliding.
    curl_setopt($ch, CURLOPT_HEADER, true);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $headers = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    unset($raw);

    $retryAfter = 0;
    if (preg_match('/^\s*retry-after:\s*(\d+)/im', $headers, $m)) {
        $retryAfter = (int) $m[1];
    }

    if ($httpCode !== 200 || $errno !== 0) {
        // Prefer Hotelbeds' own wording ("Rate limit exceeded") over a bare
        // status code — it is what tells the operator what to actually change.
        $apiMessage = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $apiMessage = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '');
        }
        if ($apiMessage === '') {
            $apiMessage = $error !== '' ? $error : "HTTP $httpCode";
        }
        return [
            'success'     => false,
            'error'       => $apiMessage,
            'http_code'   => $httpCode,
            'retry_after' => $retryAfter,
            'rate_limited' => ($httpCode === 429),
        ];
    }
    $data = json_decode($body, true);
    unset($body);
    if (!isset($data['hotels'])) {
        return ['success' => false, 'error' => 'No hotels array in the API response', 'http_code' => $httpCode];
    }

    $rows = hotelbedsFastTransform($data['hotels'], $runId, date('Y-m-d H:i:s'), (bool) $cfg['store_raw_json']);
    $hotels = count($rows['hotels']);
    unset($data);

    $tables  = hotelbedsFastTables();
    $columns = hotelbedsFastColumns();
    $spool   = hotelbedsTurboSpool($runId);
    $written = 0;

    foreach ($rows as $key => $tableRows) {
        if (!$tableRows) {
            continue;
        }
        $file = sprintf('%s/%s-p%08d.tsv', $spool, $key, $from);
        $handle = fopen($file, 'w');
        hotelbedsFastWriteTsv($handle, $tableRows, $columns[$key]);
        fclose($handle);
        try {
            hotelbedsFastLoadShard($pdo, hotelbedsFastStageName($tables[$key]), $file, $columns[$key], 1048576);
            $written += count($tableRows);
        } finally {
            @unlink($file);
        }
    }
    unset($rows);

    return ['success' => true, 'hotels' => $hotels, 'rows' => $written];
}

/**
 * Remove whatever an earlier attempt at this page range managed to stage.
 *
 * Retries re-import the same hotels, so without this a page that failed
 * half-way would leave its first rows behind and the retry would duplicate
 * them. The hotels staged from this range identify themselves.
 */
function hotelbedsTurboClearRange(PDO $pdo, int $from, int $to, array $state): void
{
    $cfg = ['environment' => $state['environment'] ?? 'test'];
    $tables = hotelbedsFastTables();
    $stageHotels = hotelbedsFastStageName($tables['hotels']);

    // The page range is an API offset, not a hotel code, so the codes actually
    // written are read back from staging by insertion order.
    $offset = $from - 1;
    $limit  = $to - $from + 1;
    $codes = $pdo->query(
        "SELECT hotel_code FROM `$stageHotels` ORDER BY id LIMIT $limit OFFSET $offset"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$codes) {
        return;
    }
    foreach (array_chunk($codes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($tables as $table) {
            $pdo->prepare('DELETE FROM `' . hotelbedsFastStageName($table) . "` WHERE hotel_code IN ($in)")
                ->execute($chunk);
        }
    }
}

// ---------------------------------------------------------------------------
// repair — fill any hotels the paged walk missed
// ---------------------------------------------------------------------------

$router->post('stays/hotelbeds/turbo/repair', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if (!$run) {
            hotelbedsTurboFail('No import is running.');
        }
        $state = $run['state'];
        $cfg = hotelbedsTurboConfig($db, $state);
        hotelbedsFastTuneSession($pdo);

        $apiTotal = (int) ($state['api_total'] ?? 0);
        $stage = hotelbedsFastStageName('hotelbeds_hotels');
        $have = [];
        foreach ($pdo->query("SELECT hotel_code FROM `$stage`")->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $have[(string) $code] = true;
        }

        // Ask the API which hotel codes exist. fields=code returns ids only —
        // about 3 KB per 1000 hotels against 4.5 MB for fields=all — so a whole
        // catalogue sweep costs well under a megabyte.
        $missing = [];
        $from = max(1, (int) ($_POST['from'] ?? 1));
        $sweepPages = 0;
        for (; $from <= $apiTotal && $sweepPages < 40; $from += HB_PAGE_SIZE, $sweepPages++) {
            $timestamp = time();
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => hotelbedsFastBaseUrl($cfg['environment']) . '/hotels?' . http_build_query(array_filter([
                    'from' => $from, 'to' => min($from + HB_PAGE_SIZE - 1, $apiTotal),
                    'fields' => 'code', 'language' => 'ENG',
                    'lastUpdateTime' => $cfg['last_update_time'],
                ])),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_ENCODING       => 'gzip',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Api-key: ' . $cfg['api_key'],
                    'X-Signature: ' . hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp),
                ],
            ]);
            $body = curl_exec($ch);
            $ok = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
            curl_close($ch);
            if (!$ok) {
                continue;
            }
            foreach (json_decode($body, true)['hotels'] ?? [] as $hotel) {
                $code = (string) ($hotel['code'] ?? '');
                if ($code !== '' && !isset($have[$code])) {
                    $missing[] = $code;
                }
            }
            unset($body);
        }

        $repaired = 0;
        $failedBatches = 0;
        if ($missing) {
            $cfg['spool'] = hotelbedsTurboSpool((int) $run['id']);
            $result = hotelbedsFastRepairCodes($cfg, $pdo, $missing);
            $repaired = $result['repaired'];
            $failedBatches = $result['failed_batches'];
        }

        echo json_encode([
            'success'  => true,
            'missing'  => count($missing),
            'repaired' => $repaired,
            'failed_batches' => $failedBatches,
            'next_from' => $from <= $apiTotal ? $from : 0,
            'done'     => $from > $apiTotal,
        ]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage(), ['retryable' => true]);
    }
    exit;
});

// ---------------------------------------------------------------------------
// masters / rate comments — the other two Content API datasets
// ---------------------------------------------------------------------------

$router->post('stays/hotelbeds/turbo/masters', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $medoo = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $pdo]);
        $cfg = hotelbedsTurboConfig($db);
        $records = 0;
        if (function_exists('importHotelbedsReferenceData')) {
            $results = importHotelbedsReferenceData($medoo, $cfg['api_key'], $cfg['api_secret'],
                $cfg['environment'], ['include_rate_comments' => false]);
            foreach ($results as $r) {
                if (!empty($r['success'])) {
                    $records += (int) ($r['count'] ?? 0);
                }
            }
        }
        echo json_encode(['success' => true, 'records' => $records]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage());
    }
    exit;
});

/** How many rate comments the catalogue holds right now. */
function hotelbedsTurboRateCommentsTotal(array $cfg): int
{
    $timestamp = time();
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => hotelbedsFastBaseUrl($cfg['environment']) . '/types/ratecomments?from=1&to=1',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Api-key: ' . $cfg['api_key'],
            'X-Signature: ' . hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp),
        ],
    ]);
    $body = curl_exec($ch);
    $ok = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);
    return $ok ? (int) (json_decode($body, true)['total'] ?? 0) : 0;
}

$router->post('stays/hotelbeds/turbo/ratecomments', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if (!$run) {
            hotelbedsTurboFail('No import is running.');
        }
        $cfg  = hotelbedsTurboConfig($db, $run['state']);
        $from = max(1, (int) ($_POST['from'] ?? 1));
        hotelbedsFastTuneSession($pdo);

        $timestamp = time();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => hotelbedsFastBaseUrl($cfg['environment']) . '/types/ratecomments?'
                . http_build_query(['from' => $from, 'to' => $from + HB_PAGE_SIZE - 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Api-key: ' . $cfg['api_key'],
                'X-Signature: ' . hash('sha256', $cfg['api_key'] . $cfg['api_secret'] . $timestamp),
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'retryable' => true, 'from' => $from, 'error' => "HTTP $httpCode"]);
            exit;
        }

        $payload = json_decode($body, true);
        unset($body);
        $columns = ['incoming','code','hotel_code','rate_codes','date_start','date_end','description'];
        $flat = [];
        foreach ($payload['rateComments'] ?? [] as $item) {
            $incoming = isset($item['incoming']) ? (string) $item['incoming'] : '';
            $code     = isset($item['code']) ? (string) $item['code'] : '';
            if ($incoming === '' || $code === '') {
                continue;
            }
            $hotelCode = isset($item['hotel']) ? (string) $item['hotel'] : null;
            foreach (($item['commentsByRates'] ?? []) as $byRate) {
                $raw = $byRate['rateCodes'] ?? '';
                $rateCodes = is_array($raw) ? implode(' ', array_map('strval', $raw)) : trim((string) $raw);
                foreach (($byRate['comments'] ?? []) as $comment) {
                    $description = hotelbedsFastText($comment['description'] ?? '');
                    if ($description === '') {
                        continue;
                    }
                    $flat[] = [
                        'incoming'    => $incoming,
                        'code'        => $code,
                        'hotel_code'  => $hotelCode,
                        'rate_codes'  => $rateCodes,
                        'date_start'  => !empty($comment['dateStart']) ? substr((string) $comment['dateStart'], 0, 10) : null,
                        'date_end'    => !empty($comment['dateEnd']) ? substr((string) $comment['dateEnd'], 0, 10) : null,
                        'description' => $description,
                    ];
                }
            }
        }
        unset($payload);

        if ($flat) {
            $spool = hotelbedsTurboSpool((int) $run['id']);
            $file  = sprintf('%s/rc-p%08d.tsv', $spool, $from);
            $handle = fopen($file, 'w');
            hotelbedsFastWriteTsv($handle, $flat, $columns);
            fclose($handle);
            try {
                hotelbedsFastLoadShard($pdo, hotelbedsFastStageName('hotelbeds_rate_comments'), $file, $columns, 1048576);
            } finally {
                @unlink($file);
            }
        }
        echo json_encode(['success' => true, 'from' => $from, 'rows' => count($flat)]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage(), ['retryable' => true]);
    }
    exit;
});

// ---------------------------------------------------------------------------
// index / verify / publish — one short request each, so nothing hits a timeout
// ---------------------------------------------------------------------------

$router->post('stays/hotelbeds/turbo/index', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        hotelbedsFastTuneSession($pdo);
        $tables = hotelbedsFastTables();
        $key = (string) ($_POST['table'] ?? '');

        if ($key === 'rate_comments') {
            $result = hotelbedsFastRebuildIndexes($pdo, 'hotelbeds_rate_comments');
        } elseif (isset($tables[$key])) {
            $result = hotelbedsFastRebuildIndexes($pdo, $tables[$key]);
        } else {
            hotelbedsTurboFail("Unknown table '$key'");
        }
        echo json_encode(['success' => true, 'table' => $key] + $result);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage(), ['retryable' => true]);
    }
    exit;
});

$router->post('stays/hotelbeds/turbo/verify', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if (!$run) {
            hotelbedsTurboFail('No import is running.');
        }
        $state = $run['state'];
        $cfg = hotelbedsTurboConfig($db, $state);
        hotelbedsFastTuneSession($pdo);

        // A hotel that landed twice would otherwise fail the unique index and
        // leave a half-merged pair; every row for the code is dropped and the
        // hotel is re-fetched whole.
        $duplicates = hotelbedsFastRepairDuplicates($cfg, $pdo);
        // Tolerance 0.05%, not the default 0.5%: the client repairs whatever is
        // missing before publishing, so a gap should be reported, not absorbed.
        // A silently-accepted 0.4%% shortfall is ~1,200 hotels quietly dropped.
        $verify = hotelbedsFastVerifyStaging($pdo, (int) ($state['api_total'] ?? 0), 0.0005);

        echo json_encode([
            'success'    => true,
            'ok'         => $verify['ok'],
            'duplicates' => $duplicates['duplicates'],
            'counts'     => $verify['counts'],
            'distinct'   => $verify['distinct_codes'],
            'api_total'  => $verify['api_total'],
            'shortfall'  => $verify['shortfall_pct'],
            'orphans'    => $verify['orphans'],
        ]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage(), ['retryable' => true]);
    }
    exit;
});

$router->post('stays/hotelbeds/turbo/publish', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if (!$run) {
            hotelbedsTurboFail('No import is running.');
        }
        $state = $run['state'];
        $runId = (int) $run['id'];
        $mode  = $state['mode'] ?? 'full';
        hotelbedsFastTuneSession($pdo);

        // Refuse to publish a short catalogue over a good one.
        $verify = hotelbedsFastVerifyStaging($pdo, (int) ($state['api_total'] ?? 0), 0.0005);
        if (!$verify['ok'] && empty($_POST['force'])) {
            $pdo->prepare("UPDATE hotelbeds_import_log SET status='failed', error_message=?, updated_at=NOW() WHERE id=?")
                ->execute([sprintf('Catalogue short by %.3f%% — nothing published', $verify['shortfall_pct']), $runId]);
            hotelbedsTurboFail(sprintf(
                'Staged catalogue is short by %.3f%% (%s of %s hotels). Live data left untouched.',
                $verify['shortfall_pct'], number_format($verify['distinct_codes']), number_format($verify['api_total'])
            ), ['verify' => $verify]);
        }

        if ($mode === 'incremental') {
            // Only the changed hotels were staged: merge them in rather than
            // swapping, which would replace the catalogue with the subset.
            $merge = hotelbedsFastMerge($pdo);
            $published = ['mode' => 'merge', 'replaced' => $merge['replaced'], 'seconds' => $merge['seconds']];
        } else {
            $swap = hotelbedsFastSwap($pdo);
            $published = ['mode' => 'swap', 'tables' => $swap['tables'], 'seconds' => $swap['seconds']];

            // Rate comments live outside the six hotel tables and are collected
            // by their own slow background loop, so the staged table can still be
            // empty or part-filled when the hotels finish.
            //
            // Swapping it in unconditionally destroyed a good live table once:
            // the loop had failed silently, staging held 0 rows, and the rename
            // replaced 72,160 live comments with nothing. Publish the staged
            // copy only when it is at least as complete as what is already
            // there; otherwise keep the existing table and say so.
            $stage = hotelbedsFastStageName('hotelbeds_rate_comments');
            $published['rate_comments'] = 'unchanged';
            if ($pdo->query("SHOW TABLES LIKE '$stage'")->fetch()) {
                $staged = (int) $pdo->query("SELECT COUNT(*) FROM `$stage`")->fetchColumn();
                $live   = (int) $pdo->query('SELECT COUNT(*) FROM `hotelbeds_rate_comments`')->fetchColumn();

                if ($staged > 0 && ($live === 0 || $staged >= $live * 0.9)) {
                    $pdo->exec('DROP TABLE IF EXISTS `hotelbeds_rate_comments__old`');
                    $pdo->exec("RENAME TABLE `hotelbeds_rate_comments` TO `hotelbeds_rate_comments__old`, `$stage` TO `hotelbeds_rate_comments`");
                    $pdo->exec('DROP TABLE IF EXISTS `hotelbeds_rate_comments__old`');
                    $published['rate_comments'] = $staged;
                } else {
                    $pdo->exec("DROP TABLE IF EXISTS `$stage`");
                    $published['rate_comments'] = "kept existing $live (staged only $staged)";
                }
            }

            // The swap left the incoming tables without the constraints the old
            // ones carried; ALGORITHM=INPLACE puts them back in about a second.
            if (!empty($state['foreign_keys'])) {
                hotelbedsFastRestoreForeignKeys($pdo, $state['foreign_keys']);
            }
        }

        $liveHotels = (int) $pdo->query('SELECT COUNT(*) FROM hotelbeds_hotels')->fetchColumn();
        $state['phase'] = 'completed';
        $state['current_operation'] = 'Import completed successfully';
        $state['processed'] = $verify['distinct_codes'];

        $pdo->prepare(
            "UPDATE hotelbeds_import_log
                SET status='completed', hotels_imported=?, error_message=NULL,
                    completed_at=NOW(), updated_at=NOW(), import_state=?
              WHERE id=?"
        )->execute([$verify['distinct_codes'], json_encode($state), $runId]);

        @array_map('unlink', glob(hotelbedsTurboSpool($runId) . '/*') ?: []);
        @rmdir(hotelbedsTurboSpool($runId));

        echo json_encode([
            'success'      => true,
            'published'    => $published,
            'imported'     => $verify['distinct_codes'],
            'live_hotels'  => $liveHotels,
            'counts'       => $verify['counts'],
        ]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    exit;
});

$router->post('stays/hotelbeds/turbo/cancel', function () use ($db) {
    hotelbedsTurboBoot();
    try {
        $pdo = hotelbedsTurboPdo($db);
        $run = hotelbedsTurboRun($pdo);
        if ($run) {
            // Staging is discarded; the live catalogue was never touched.
            foreach (hotelbedsFastTables() as $table) {
                try { $pdo->exec('DROP TABLE IF EXISTS `' . hotelbedsFastStageName($table) . '`'); } catch (Throwable $e) {}
            }
            try { $pdo->exec('DROP TABLE IF EXISTS `' . hotelbedsFastStageName('hotelbeds_rate_comments') . '`'); } catch (Throwable $e) {}
            $pdo->prepare("UPDATE hotelbeds_import_log SET status='cancelled', updated_at=NOW() WHERE id=?")
                ->execute([(int) $run['id']]);
            @array_map('unlink', glob(hotelbedsTurboSpool((int) $run['id']) . '/*') ?: []);
        }
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        hotelbedsTurboFail($e->getMessage());
    }
    exit;
});

$router->post('stays/hotelbeds/process', function() use ($db) {
    // Increase PHP execution time and memory for large batches
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    // Aggressive error suppression
    @ini_set('display_errors', '0');
    @error_reporting(0);

    while (@ob_get_level()) {
        @ob_end_clean();
    }

    @ob_start();

    // Last-resort net: turn a true PHP fatal (out-of-memory / timeout) into a JSON
    // error so the client shows the real reason instead of an opaque 500 page.
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            while (@ob_get_level()) { @ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json'); }
            echo json_encode([
                'success' => false,
                'completed' => true,
                'fatal' => true,
                'error' => 'PHP fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']
            ]);
        }
    });

    try {
        $hotelbedsDb = getHotelbedsDb();
        header('Content-Type: application/json');

    // Get the latest import in progress
    $importLog = $hotelbedsDb->get('hotelbeds_import_log', '*', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if (!$importLog) {
        echo json_encode([
            'success' => false,
            'error' => 'No import session found'
        ]);
        exit;
    }

    $import = json_decode($importLog['import_state'], true);
    if (!$import) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid import state'
        ]);
        exit;
    }

    // Respect a server-side pause: don't process another chunk while paused.
    if (!empty($import['paused'])) {
        @ob_end_clean();
        echo json_encode(['success' => true, 'completed' => false, 'paused' => true, 'message' => 'Import is paused']);
        exit;
    }

    try {
        // Resolve credentials: prefer import_state, fall back to module c1/c2
        $apiKey = trim((string) ($import['api_key'] ?? ''));
        $apiSecret = trim((string) ($import['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            $moduleCreds = $db->get('modules', ['c1', 'c2', 'dev_mode'], [
                'name' => 'hotelbeds',
                'type' => 'stays',
            ]);
            $apiKey = trim((string) ($moduleCreds['c1'] ?? ''));
            $apiSecret = trim((string) ($moduleCreds['c2'] ?? ''));
            if ($apiKey !== '' && $apiSecret !== '') {
                $import['api_key'] = $apiKey;
                $import['api_secret'] = $apiSecret;
                if (empty($import['environment'])) {
                    // modules.dev_mode: 1 = test/development, 0 = live/production
                    $import['environment'] = (($moduleCreds['dev_mode'] ?? '1') == '1') ? 'test' : 'live';
                }
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'import_state' => json_encode($import),
                    'updated_at' => $hotelbedsDb->raw('NOW()'),
                ], ['id' => $importLog['id']]);
            }
        }

        if ($apiKey === '' || $apiSecret === '') {
            echo json_encode([
                'success' => false,
                'error' => 'API credentials not found in import state or module settings',
            ]);
            exit;
        }

        $environment = $import['environment'] ?? 'test';

        // If content_import was interrupted after creating the session, finish masters here
        if (empty($import['reference_data_imported'])) {
            @set_time_limit(0);
            ini_set('max_execution_time', '0');
            $import['current_operation'] = 'Importing Content API reference data…';
            $logs = json_decode($import['logs'] ?? '[]', true) ?: [];
            $logs[] = '[' . date('H:i:s') . '] [REF] Loading Content API masters (rate comments deferred)…';
            $import['logs'] = json_encode(array_slice($logs, -200));
            $hotelbedsDb->update('hotelbeds_import_log', [
                'import_state' => json_encode($import),
                'updated_at' => $hotelbedsDb->raw('NOW()'),
            ], ['id' => $importLog['id']]);

            $refResults = importHotelbedsReferenceData($hotelbedsDb, $apiKey, $apiSecret, $environment, [
                'include_rate_comments' => false,
            ]);
            $totalRefRecords = 0;
            foreach ($refResults as $result) {
                if (!empty($result['success'])) {
                    $totalRefRecords += (int) ($result['count'] ?? 0);
                }
            }

            $import['reference_data_imported'] = true;
            $import['rate_comments_imported'] = false;
            $import['rate_comments_from'] = 1;
            $import['rate_comments_inserted'] = 0;
            $import['reference_records'] = $totalRefRecords;
            $import['phase'] = 'rate_comments';
            $import['current_operation'] = 'Masters ready — importing rate comments (paginated)…';
            $logs[] = '[' . date('H:i:s') . '] [OK] Reference masters imported (' . $totalRefRecords . ' rows). Next: rate comments.';
            $import['logs'] = json_encode(array_slice($logs, -200));
            $hotelbedsDb->update('hotelbeds_import_log', [
                'import_state' => json_encode($import),
                'updated_at' => $hotelbedsDb->raw('NOW()'),
            ], ['id' => $importLog['id']]);

            @ob_end_clean();
            echo json_encode([
                'success' => true,
                'completed' => false,
                'continue' => true,
                'status' => 'in_progress',
                'phase' => 'rate_comments',
                'current_operation' => $import['current_operation'],
                'current_chunk' => (int) ($import['current_chunk'] ?? 0),
                'total_chunks' => (int) ($import['total_chunks'] ?? 0),
                'processed' => (int) ($import['processed'] ?? 0),
                'total' => (int) ($import['total'] ?? 0),
                'eta' => 'Calculating...',
                'logs' => json_decode($import['logs'], true) ?: [],
                'message' => 'Reference masters imported, starting rate comments…',
            ]);
            exit;
        }

        // Postman RateComments — one page per /process turn (safe for proxies/timeouts)
        if (empty($import['rate_comments_imported'])) {
            @set_time_limit(180);
            $from = max(1, (int) ($import['rate_comments_from'] ?? 1));
            $pageSize = 200;
            $rc = function_exists('importHotelbedsRateCommentsPage')
                ? importHotelbedsRateCommentsPage($hotelbedsDb, $apiKey, $apiSecret, $environment, $from, $pageSize)
                : ['success' => false, 'error' => 'importHotelbedsRateCommentsPage missing', 'done' => false, 'inserted' => 0, 'next_from' => $from];

            $logs = json_decode($import['logs'] ?? '[]', true) ?: [];

            if (empty($rc['success'])) {
                $logs[] = '[' . date('H:i:s') . '] [RETRYABLE] Rate comments page ' . $from . ': ' . ($rc['error'] ?? 'failed');
                $import['logs'] = json_encode(array_slice($logs, -200));
                $import['current_operation'] = 'Rate comments page failed — will retry';
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'import_state' => json_encode($import),
                    'error_message' => $rc['error'] ?? 'rate comments page failed',
                    'updated_at' => $hotelbedsDb->raw('NOW()'),
                ], ['id' => $importLog['id']]);

                @ob_end_clean();
                echo json_encode([
                    'success' => false,
                    'completed' => false,
                    'retryable' => true,
                    'error' => $rc['error'] ?? 'Rate comments page failed',
                    'phase' => 'rate_comments',
                    'logs' => $logs,
                ]);
                exit;
            }

            $inserted = (int) ($rc['inserted'] ?? 0);
            $import['rate_comments_inserted'] = (int) ($import['rate_comments_inserted'] ?? 0) + $inserted;
            if (!empty($rc['total'])) {
                $import['rate_comments_total'] = (int) $rc['total'];
            }
            $totalRc = $import['rate_comments_total'] ?? null;
            $logs[] = '[' . date('H:i:s') . '] [RC] page ' . ($rc['from'] ?? $from) . '-' . ($rc['to'] ?? '') .
                ' +' . $inserted . ' rows (total inserted ' . $import['rate_comments_inserted'] .
                ($totalRc ? ' / ~' . $totalRc : '') . ')';

            if (!empty($rc['done'])) {
                $import['rate_comments_imported'] = true;
                $import['phase'] = 'hotels';
                $import['current_operation'] = 'Rate comments done — starting hotel chunks (or Pause to stop here)';
                $logs[] = '[' . date('H:i:s') . '] [OK] Rate comments catalogue imported (' . $import['rate_comments_inserted'] . ' rows). Hotels next.';
            } else {
                $import['rate_comments_from'] = (int) ($rc['next_from'] ?? ($from + $pageSize));
                $import['phase'] = 'rate_comments';
                $import['current_operation'] = 'Importing rate comments… page from ' . $import['rate_comments_from'] .
                    ($totalRc ? ' of ~' . $totalRc : '');
            }

            $import['logs'] = json_encode(array_slice($logs, -200));
            $hotelbedsDb->update('hotelbeds_import_log', [
                'import_state' => json_encode($import),
                'error_message' => null,
                'updated_at' => $hotelbedsDb->raw('NOW()'),
            ], ['id' => $importLog['id']]);

            @ob_end_clean();
            echo json_encode([
                'success' => true,
                'completed' => false,
                'continue' => true,
                'status' => 'in_progress',
                'phase' => $import['phase'],
                'current_operation' => $import['current_operation'],
                'current_chunk' => (int) ($import['current_chunk'] ?? 0),
                'total_chunks' => (int) ($import['total_chunks'] ?? 0),
                'processed' => (int) ($import['rate_comments_inserted'] ?? 0),
                'total' => (int) ($import['rate_comments_total'] ?? max(1, (int) ($import['rate_comments_inserted'] ?? 1))),
                'eta' => 'Calculating...',
                'logs' => $logs,
                'message' => !empty($rc['done']) ? 'Rate comments done, hotels next…' : 'Rate comments page imported…',
            ]);
            exit;
        }

        // Get chunk size from import state (dynamic)
        $chunkSize = $import['chunk_size'] ?? 25;
        $currentChunk = $import['current_chunk'];
        $from = $currentChunk * $chunkSize + 1;
        $to = ($currentChunk + 1) * $chunkSize;

        // Update import status
        $import['current_operation'] = "Processing chunk " . ($currentChunk + 1) . " (records $from-$to)";
        $logs = json_decode($import['logs'], true) ?? [];
        $import['logs'] = json_encode($logs);

        // Save updated state to database
        $hotelbedsDb->update('hotelbeds_import_log', [
            'import_state' => json_encode($import),
            'updated_at' => $hotelbedsDb->raw('NOW()')
        ], ['id' => $importLog['id']]);

        // Make API call to Hotelbeds
        $result = importHotelbedsChunk(
            $hotelbedsDb,
            $apiKey,
            $apiSecret,
            $from,
            $to,
            $import['mode'],
            $environment,
            (int) $importLog['id']
        );

        // A pause may have been requested while this long-running chunk was in
        // flight. Re-read and preserve it instead of overwriting it with the
        // stale state loaded at the beginning of this request.
        $latestStateJson = $hotelbedsDb->get('hotelbeds_import_log', 'import_state', ['id' => $importLog['id']]);
        $latestState = $latestStateJson ? json_decode($latestStateJson, true) : null;
        if (is_array($latestState) && !empty($latestState['paused'])) {
            $import['paused'] = true;
        }

        // Log the result for debugging
        error_log("Import chunk result: " . json_encode($result));

        // Debug: If we got this far, the function call worked
        if (!$result) {
            $logs[] = "[" . date('H:i:s') . "] [ERROR] importHotelbedsChunk returned null/false";
            $logs = array_slice($logs, -200);
            $import['logs'] = json_encode($logs);
            
            echo json_encode([
                'success' => false,
                'completed' => true,
                'error' => 'importHotelbedsChunk returned null/false',
                'detailed_log' => $logs
            ]);
            exit;
        }

        // If API call failed, log detailed information
        if (!$result['success']) {
            error_log("API call failed: " . ($result['error'] ?? 'Unknown error'));
            if (isset($result['debug'])) {
            }
        }

        if ($result['success']) {
            // Update progress
            $import['current_chunk']++;
            $import['processed'] += $result['records_processed'];

            // Replace the hard-coded estimate with the API's real catalogue size
            // so the progress bar and ETA are accurate (updated on every chunk).
            if (!empty($result['api_total']) && $result['api_total'] > 0) {
                $import['api_total'] = (int) $result['api_total'];
                $import['total'] = (int) $result['api_total'];
                $chunkSize = $import['chunk_size'] ?? 25;
                $import['total_chunks'] = (int) ceil($import['total'] / max(1, $chunkSize));
            }

            // Simple log format: [time] chunk X hotels Y
            $logs[] = "[" . date('H:i:s') . "] chunk " . $import['current_chunk'] . " hotels " . $import['processed'];
            if (!empty($result['hotels_failed'])) {
                $logs[] = "[" . date('H:i:s') . "] [WARN] " . (int) $result['hotels_failed'] . " hotels failed to save in this chunk (check PHP error log)";
            }

            // After first chunk, populate countries and destinations from hotel data
            // This is needed because test API returns 404 for these endpoints
            if ($import['current_chunk'] === 1 && !isset($import['countries_populated'])) {
                $countriesCount = populateCountriesFromHotels($hotelbedsDb);
                $destinationsCount = populateDestinationsFromHotels($hotelbedsDb);
                $logs[] = "[" . date('H:i:s') . "] Populated $countriesCount countries and $destinationsCount destinations";
                $import['countries_populated'] = true;
            }

            $logs = array_slice($logs, -200);
            $import['logs'] = json_encode($logs);

            // Complete only when we truly finished the catalogue — never on a lone short page.
            $isComplete = hotelbedsHotelImportIsComplete($import, $result, $from, $to);

            if ($isComplete) {
                // Purge orphans only after a full catalogue walk (processed ≈ api_total).
                $purged = 0;
                $apiTotal = (int) ($import['api_total'] ?? 0);
                $processed = (int) ($import['processed'] ?? 0);
                $safeToPurge = $apiTotal > 0 && $processed >= max(1, $apiTotal - (int) ($import['chunk_size'] ?? 25));
                if ($safeToPurge) {
                    try {
                        $purged = purgeStaleHotelbedsHotels($hotelbedsDb, (int) $importLog['id']);
                        if ($purged > 0) {
                            $logs[] = '[' . date('H:i:s') . '] [CLEANUP] Removed ' . $purged . ' hotels no longer in the API catalogue';
                            $logs = array_slice($logs, -200);
                            $import['logs'] = json_encode($logs);
                        }
                    } catch (Throwable $e) {
                        error_log('[HOTELBEDS] Stale hotel purge failed: ' . $e->getMessage());
                        $logs[] = '[' . date('H:i:s') . '] [WARN] Stale hotel cleanup failed: ' . $e->getMessage();
                        $logs = array_slice($logs, -200);
                        $import['logs'] = json_encode($logs);
                    }
                } else {
                    // Incomplete catalogue — keep existing rows; do not purge mid-sync.
                }

                $uniqueHotels = hotelbedsCountUniqueHotels($hotelbedsDb);

                // Mark import as completed
                $import['current_operation'] = 'Import completed successfully';
                $import['purged_hotels'] = $purged;

                // Update database log
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'status' => 'completed',
                    'hotels_imported' => $import['processed'],
                    'error_message' => null,
                    'completed_at' => $hotelbedsDb->raw('NOW()'),
                    'updated_at' => $hotelbedsDb->raw('NOW()'),
                    'import_state' => json_encode($import)
                ], ['id' => $importLog['id']]);

                @ob_end_clean();
                $syncMeta = hotelbedsImportSyncMeta($hotelbedsDb);
                echo json_encode([
                    'success' => true,
                    'completed' => true,
                    'message' => 'Import completed successfully',
                    'total_processed' => $import['processed'],
                    'purged_hotels' => $purged,
                    'total_hotels' => $uniqueHotels,
                    'last_sync' => $syncMeta['last_sync'],
                    'logs' => $logs,
                ]);
            } else {
                // Save updated progress to database
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'error_message' => null,
                    'import_state' => json_encode($import),
                    'updated_at' => $hotelbedsDb->raw('NOW()')
                ], ['id' => $importLog['id']]);

                // Return the full progress payload so the client can update the UI
                // without a second /progress round-trip per chunk (halves latency).
                $elapsed = time() - $import['start_time'];
                $prog = $import['processed'] / max($import['total'], 1);
                $eta = $prog > 0 ? round(($elapsed / $prog) - $elapsed) : 0;
                $etaFormatted = $eta > 0 ? gmdate("H:i:s", $eta) : 'Calculating...';
                $uniqueHotels = hotelbedsCountUniqueHotels($hotelbedsDb);
                $liveDestinations = 0;
                try { $liveDestinations = (int) $hotelbedsDb->count('hotelbeds_destinations'); } catch (Exception $e) {}
                $syncMeta = hotelbedsImportSyncMeta($hotelbedsDb);

                @ob_end_clean();
                echo json_encode([
                    'success' => true,
                    'completed' => false,
                    'continue' => true,
                    'status' => 'in_progress',
                    'paused' => !empty($import['paused']),
                    'current_operation' => $import['current_operation'],
                    'current_chunk' => $import['current_chunk'],
                    'total_chunks' => $import['total_chunks'],
                    'processed' => $import['processed'],
                    'total' => $import['total'],
                    'total_hotels' => $uniqueHotels,
                    'total_destinations' => $liveDestinations,
                    'last_sync' => $syncMeta['last_sync'],
                    'eta' => $etaFormatted,
                    'logs' => $logs,
                    'message' => 'Chunk processed, continuing...'
                ]);
            }
        } else {
            $isRetryable = !empty($result['retryable']);
            $import['current_operation'] = ($isRetryable ? 'Temporary error; waiting to retry: ' : 'Import failed: ') . $result['error'];
            $logs[] = "[" . date('H:i:s') . "] [" . ($isRetryable ? 'RETRYABLE' : 'ERROR') . "] " . $result['error'];
            
            // Add detailed debug logs if available
            if (isset($result['detailed_log']) && is_array($result['detailed_log'])) {
                foreach ($result['detailed_log'] as $logLine) {
                    $logs[] = $logLine;
                }
            }
            
            $logs = array_slice($logs, -200);
            $import['logs'] = json_encode($logs);

            if ($isRetryable) {
                // Preserve current_chunk and status so the same range resumes.
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'error_message' => $result['error'],
                    'import_state' => json_encode($import),
                    'updated_at' => $hotelbedsDb->raw('NOW()')
                ], ['id' => $importLog['id']]);

                @ob_end_clean();
                echo json_encode([
                    'success' => false,
                    'completed' => false,
                    'retryable' => true,
                    'error' => $result['error'],
                    'detailed_log' => $result['detailed_log'] ?? []
                ]);
                exit;
            }

            // Permanent failures still stop the import.
            $hotelbedsDb->update('hotelbeds_import_log', [
                'status' => 'failed',
                'error_message' => $result['error'],
                'import_state' => json_encode($import),
                'updated_at' => $hotelbedsDb->raw('NOW()')
            ], ['id' => $importLog['id']]);

            @ob_end_clean();
            echo json_encode([
                'success' => false,
                'completed' => true,
                'error' => $result['error'],
                'detailed_log' => $result['detailed_log'] ?? []
            ]);
            exit;
        }

    } catch (Throwable $e) {
        $detail = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        // Record the error but keep the import RESUMABLE: status stays in_progress
        // and current_chunk is unchanged, so a Resume simply reprocesses this chunk
        // (safe — the batched write is delete-then-insert, i.e. idempotent).
        if (isset($importLog['id']) && isset($hotelbedsDb)) {
            try {
                $hotelbedsDb->update('hotelbeds_import_log', [
                    'error_message' => $detail,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $importLog['id']]);
            } catch (Throwable $e2) {}
        }

        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'completed' => false,
            'retryable' => true,
            'error' => $detail
        ]);
        exit;
    }

    } catch (Throwable $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'completed' => true,
            'error' => 'Fatal error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
        ]);
    }

    exit;
});

// Helper function to import reference data from Hotelbeds API
function populateCountriesFromHotels($hotelbedsDb) {
    try {
        // Get unique countries from hotels
        $countries = $hotelbedsDb->query("
            SELECT DISTINCT 
                country_code as code,
                country_code as name
            FROM hotelbeds_hotels 
            WHERE country_code IS NOT NULL 
            AND country_code != ''
            ORDER BY country_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($countries as $country) {
            try {
                $hotelbedsDb->insert('hotelbeds_countries', [
                    'code' => $country['code'],
                    'name' => $country['name'],
                    'iso_code' => $country['code']
                ]);
                $count++;
            } catch (PDOException $e) {
                // Skip duplicates
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    error_log("[HOTELBEDS] Country insert error: " . $e->getMessage());
                }
            }
        }
        
        error_log("[HOTELBEDS] [" . date('H:i:s') . "] Populated $count countries from hotel data");
        return $count;
        
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Error populating countries: " . $e->getMessage());
        return 0;
    }
}

function populateDestinationsFromHotels($hotelbedsDb) {
    try {
        // Get unique destinations from hotels
        $destinations = $hotelbedsDb->query("
            SELECT DISTINCT 
                destination_code as code,
                destination_code as name,
                country_code,
                zone_code
            FROM hotelbeds_hotels 
            WHERE destination_code IS NOT NULL 
            AND destination_code != ''
            ORDER BY destination_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($destinations as $dest) {
            try {
                $hotelbedsDb->insert('hotelbeds_destinations', [
                    'code' => $dest['code'],
                    'name' => $dest['name'],
                    'country_code' => $dest['country_code'],
                    'zone_code' => $dest['zone_code']
                ]);
                $count++;
            } catch (PDOException $e) {
                // Skip duplicates
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    error_log("[HOTELBEDS] Destination insert error: " . $e->getMessage());
                }
            }
        }
        
        error_log("[HOTELBEDS] [" . date('H:i:s') . "] Populated $count destinations from hotel data");
        return $count;
        
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Error populating destinations: " . $e->getMessage());
        return 0;
    }
}

// Helper function to import a chunk of data from Hotelbeds API
function importHotelbedsChunk($db, $apiKey, $apiSecret, $from, $to, $mode, $environment = 'test', $syncRunId = null) {
    $chunkSize = $to - $from + 1;
    
    // Get Hotelbeds database connection
    $hotelbedsDb = getHotelbedsDb();

    try {
        // Determine API base URL based on environment
        $isProduction = ($environment === 'live');
        $baseUrl = $isProduction
            ? 'https://api.hotelbeds.com/hotel-content-api/1.0/hotels'
            : 'https://api.test.hotelbeds.com/hotel-content-api/1.0/hotels';

        // Build query parameters
        $queryParams = http_build_query([
            'from' => $from,
            'to' => $to,
            'fields' => 'all',
            'language' => 'ENG',
            // True = if ENG text is missing, Hotelbeds may still return English as secondary.
            // Keeps imported hotel names/descriptions/facilities in English whenever possible.
            'useSecondaryLanguage' => 'true'
        ]);

        $url = $baseUrl . '?' . $queryParams;

        // Create signature for authentication (Hotelbeds specific format)
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        // Setup cURL with proper Hotelbeds authentication
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip', // Enable gzip decompression
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature
            ],
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);

        // Enhanced debug logging
        error_log("Base URL: " . ($isProduction ? 'PRODUCTION' : 'TEST'));
        error_log("Full URL: " . $url);
        error_log("API Key Length: " . strlen($apiKey));
        error_log("API Secret Length: " . strlen($apiSecret));
        error_log("Timestamp: " . $timestamp);
        error_log("Signature (SHA256): " . $signature);
        error_log("HTTP Code: " . $httpCode);
        error_log("Response Length: " . strlen($response));
        if ($curlError) {
            error_log("cURL Error: " . $curlError);
        }

        if ($httpCode !== 200) {
            // Try to decode error response for better error message
            $errorData = json_decode($response, true);
            $errorMessage = "HTTP $httpCode: Failed to fetch data from Hotelbeds API";

            if ($curlErrno !== 0 || $curlError !== '') {
                $errorMessage .= " (cURL $curlErrno: $curlError)";
            }

            if ($errorData && isset($errorData['error'])) {
                $errorMessage .= " - " . ($errorData['error']['message'] ?? $errorData['error']);
            } elseif ($errorData && isset($errorData['message'])) {
                $errorMessage .= " - " . $errorData['message'];
            }

            // Build detailed error log for terminal display
            $detailedLog = [
                '',
                '??????????????????????????????????????????????????????',
                '[DEBUG] Hotelbeds API Request Details',
                '??????????????????????????????????????????????????????',
                '',
                '?? REQUEST ENDPOINT:',
                '   ' . $url,
                '',
                '?? AUTHENTICATION HEADERS:',
                '   Api-key: ' . $apiKey,
                '   X-Signature: ' . $signature,
                '',
                '? SIGNATURE GENERATION:',
                '   Timestamp: ' . $timestamp,
                '   Algorithm: SHA256(ApiKey + ApiSecret + Timestamp)',
                '   Result: ' . $signature,
                '',
                '?? HTTP RESPONSE:',
                '   Status Code: ' . $httpCode,
                '   cURL Error: ' . ($curlErrno !== 0 ? "$curlErrno: $curlError" : 'None'),
                '   Content-Type: ' . ($curlInfo['content_type'] ?? 'N/A'),
                '   Response Time: ' . round($curlInfo['total_time'] ?? 0, 2) . 's',
                '',
                '?? RESPONSE BODY:',
                '   ' . substr($response, 0, 500),
                '',
                '??????????????????????????????????????????????????????',
                '?? SHARE THIS LOG WITH HOTELBEDS SUPPORT (Case #53161116)',
                '??????????????????????????????????????????????????????',
                ''
            ];

            if ($httpCode === 403) {
                $detailedLog[] = '';
                $detailedLog[] = '??  NOTICE: HTTP 403 - Access Denied';
                $detailedLog[] = '    Hotelbeds confirmed your API key has Content API access (Case #53161116)';
                $detailedLog[] = '    This suggests a possible signature or header formatting issue.';
                $detailedLog[] = '';
            }

            $retryableHttpCodes = [0, 408, 425, 429, 500, 502, 503, 504];
            $isRetryable = $curlErrno !== 0 || in_array((int) $httpCode, $retryableHttpCodes, true);

            return [
                'success' => false,
                'retryable' => $isRetryable,
                'error' => $errorMessage,
                'detailed_log' => $detailedLog,
                'debug' => [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'curl_errno' => $curlErrno,
                    'response' => substr($response, 0, 500),
                    'curl_error' => $curlError,
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                    'api_key' => $apiKey,
                    'help' => $httpCode === 403 ? 'Content API access confirmed by Hotelbeds - possible signature issue' : null
                ]
            ];
        }

        $data = json_decode($response, true);

        if (!$data) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from Hotelbeds API: ' . substr($response, 0, 200)
            ];
        }

        if (isset($data['error'])) {
            return [
                'success' => false,
                'error' => 'Hotelbeds API Error: ' . $data['error']['message']
            ];
        }

        if (!isset($data['hotels'])) {
            return [
                'success' => false,
                'error' => 'No hotels data in response. Response keys: ' . implode(', ', array_keys($data))
            ];
        }

        $hotels = $data['hotels'];
        $recordsProcessed = 0;
        $terminalLogs = [];
        $skippedHotels = 0;

        // Capture what we need from the (large) decoded response up front so we can
        // free it before the DB writes and keep peak memory low.
        $apiTotal = isset($data['total']) ? (int) $data['total'] : null;
        $hotelsReturned = count($hotels);

        // Accumulate the whole chunk in memory, then write it with a handful of
        // batched queries at the end instead of thousands of per-row queries.
        $now = date('Y-m-d H:i:s');
        $hotelRows = [];
        $amenityRows = [];
        $imageRows = [];
        $roomRows = [];
        $issueRows = [];
        $terminalRows = [];
        $codes = [];

        // Process each hotel
        foreach ($hotels as $hotel) {
            try {
                // Skip hotels with missing critical data
                if (empty($hotel['code'])) {
                    $skippedHotels++;
                    error_log("[HOTELBEDS] Skipped hotel: missing code");
                    continue;
                }
                
                // Prepare hotel data with safe JSON encoding
                $facilitiesJson = '[]';
                $imagesJson = '[]';
                $roomsJson = '[]';
                
                if (isset($hotel['facilities']) && is_array($hotel['facilities'])) {
                    $facilitiesJson = json_encode($hotel['facilities'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($facilitiesJson === false) $facilitiesJson = '[]';
                }

                if (isset($hotel['images']) && is_array($hotel['images'])) {
                    $imagesJson = json_encode($hotel['images'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($imagesJson === false) $imagesJson = '[]';
                }

                if (isset($hotel['rooms']) && is_array($hotel['rooms'])) {
                    $roomsJson = json_encode($hotel['rooms'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($roomsJson === false) $roomsJson = '[]';
                }

                $segmentCodesJson = null;
                if (!empty($hotel['segmentCodes']) && is_array($hotel['segmentCodes'])) {
                    $segmentCodesJson = json_encode($hotel['segmentCodes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($segmentCodesJson === false) {
                        $segmentCodesJson = null;
                    }
                }
            
                // Prepare hotel data
                $hotelData = [
                'hotel_code' => $hotel['code'],
                'name' => $hotel['name']['content'] ?? 'Unknown',
                'description' => $hotel['description']['content'] ?? '',
                'country_code' => $hotel['countryCode'] ?? '',
                'state_code' => $hotel['stateCode'] ?? '',
                'destination_code' => $hotel['destinationCode'] ?? '',
                'zone_code' => $hotel['zoneCode'] ?? '',
                'latitude' => $hotel['coordinates']['latitude'] ?? null,
                'longitude' => $hotel['coordinates']['longitude'] ?? null,
                'category_code' => $hotel['categoryCode'] ?? '',
                'category_name' => $hotel['category']['description']['content'] ?? ($hotel['categoryName']['content'] ?? ''),
                'accommodation_type' => $hotel['accommodationType']['typeDescription'] ?? ($hotel['accommodationTypeCode'] ?? ''),
                'chain_code' => $hotel['chainCode'] ?? '',
                'segment_codes' => $segmentCodesJson,
                'address' => $hotel['address']['content'] ?? '',
                'postal_code' => $hotel['postalCode'] ?? '',
                'city' => $hotel['city']['content'] ?? '',
                'email' => $hotel['email'] ?? '',
                'web' => $hotel['web'] ?? '',
                'phone_number' => $hotel['phones'][0]['phoneNumber'] ?? '',
                'license' => $hotel['license'] ?? '',
                'ranking' => $hotel['ranking'] ?? 0,
                'facilities' => $facilitiesJson,
                'images' => $imagesJson,
                'rooms' => $roomsJson,
                'updated_at' => $hotelbedsDb->raw('NOW()')
            ];

            // Collect this hotel for a single batched write at the end of the chunk.
            $hotelData['created_at'] = $now;
            $hotelData['updated_at'] = $now;
            if ($syncRunId !== null && (int) $syncRunId > 0) {
                $hotelData['sync_run_id'] = (int) $syncRunId;
            }
            $hotelRows[] = $hotelData;
            $codes[] = $hotelData['hotel_code'];

            // Process amenities/facilities (Content API: indFee=true => paid; indYesOrNo=false => not present)
            if (isset($hotel['facilities']) && is_array($hotel['facilities']) && count($hotel['facilities']) > 0) {
                foreach ($hotel['facilities'] as $facility) {
                    if (!isset($facility['facilityCode'])) continue;

                    // Mandatory typology: indYesOrNo=false means facility is NOT present at hotel
                    if (array_key_exists('indYesOrNo', $facility) && ($facility['indYesOrNo'] === false || $facility['indYesOrNo'] === 'false' || $facility['indYesOrNo'] === 0 || $facility['indYesOrNo'] === '0')) {
                        continue;
                    }

                    // Extract description properly
                    $facilityDesc = '';
                    if (isset($facility['description']['content'])) {
                        $facilityDesc = $facility['description']['content'];
                    } elseif (isset($facility['description']) && is_string($facility['description'])) {
                        $facilityDesc = $facility['description'];
                    }

                    $indFee = null;
                    if (array_key_exists('indFee', $facility)) {
                        $indFee = ($facility['indFee'] === true || $facility['indFee'] === 'true' || $facility['indFee'] === 1 || $facility['indFee'] === '1') ? 1 : 0;
                    }
                    $indYesOrNo = null;
                    if (array_key_exists('indYesOrNo', $facility)) {
                        $indYesOrNo = ($facility['indYesOrNo'] === true || $facility['indYesOrNo'] === 'true' || $facility['indYesOrNo'] === 1 || $facility['indYesOrNo'] === '1') ? 1 : 0;
                    }
                    $voucher = null;
                    if (array_key_exists('voucher', $facility)) {
                        $voucher = ($facility['voucher'] === true || $facility['voucher'] === 'true' || $facility['voucher'] === 1 || $facility['voucher'] === '1') ? 1 : 0;
                    }

                    $amenityData = [
                        'hotel_code' => $hotelData['hotel_code'],
                        'facility_code' => $facility['facilityCode'],
                        'facility_description' => $facilityDesc,
                        'facility_group_code' => $facility['facilityGroupCode'] ?? null,
                        'distance' => isset($facility['distance']) ? (int)$facility['distance'] : null,
                        'order_by' => isset($facility['order']) ? (int)$facility['order'] : null,
                        'ind_fee' => $indFee,
                        'ind_yes_or_no' => $indYesOrNo,
                        'voucher' => $voucher,
                        'fee_amount' => isset($facility['amount']) && is_numeric($facility['amount']) ? round((float) $facility['amount'], 2) : null,
                        'fee_currency' => !empty($facility['currency']) ? (string) $facility['currency'] : null,
                    ];

                    $amenityRows[] = $amenityData;
                }
            }

            // Process hotel images
            if (isset($hotel['images']) && is_array($hotel['images']) && count($hotel['images']) > 0) {
                foreach ($hotel['images'] as $index => $image) {
                    $imageData = [
                        'hotel_code' => $hotelData['hotel_code'],
                        'image_type' => $image['imageTypeCode'] ?? 'GEN',
                        'image_url' => $image['path'] ?? '',
                        'image_order' => $image['order'] ?? $index,
                        'room_code' => $image['roomCode'] ?? null,
                        'room_type' => $image['roomType'] ?? null
                    ];

                    $imageRows[] = $imageData;
                }
            }

            // Process hotel rooms
            if (isset($hotel['rooms']) && is_array($hotel['rooms']) && count($hotel['rooms']) > 0) {
                foreach ($hotel['rooms'] as $room) {
                    if (!isset($room['roomCode'])) continue;
                    
                    // Extract description from various possible locations
                    $roomDesc = '';
                    if(isset($room['description'])){
                        $roomDesc = $room['description'];
                    } elseif (isset($room['roomDescription']['content'])) {
                        $roomDesc = $room['roomDescription']['content'];
                    } elseif (isset($room['description']['content'])) {
                        $roomDesc = $room['description']['content'];
                    } else {
                        $roomDesc = '';
                    }
                    
                    // Extract room facilities
                    $roomFacilities = [];
                    if (isset($room['roomFacilities']) && is_array($room['roomFacilities'])) {
                        foreach ($room['roomFacilities'] as $facility) {
                            if (isset($facility['facilityCode'])) {
                                $roomFacilities[] = [
                                    'code' => $facility['facilityCode'],
                                    'description' => $facility['description'] ?? '',
                                    'groupCode' => $facility['facilityGroupCode'] ?? null
                                ];
                            }
                        }
                    }
                    
                    $roomData = [
                        'hotel_code' => $hotelData['hotel_code'],
                        'room_code' => $room['roomCode'],
                        'room_type' => $room['roomType'] ?? $room['type'] ?? '',
                        'characteristic' => $room['characteristicCode'] ?? $room['characteristic'] ?? '',
                        'description' => $roomDesc,
                        'min_pax' => isset($room['minPax']) ? (int)$room['minPax'] : 1,
                        'max_pax' => isset($room['maxPax']) ? (int)$room['maxPax'] : 2,
                        'max_adults' => isset($room['maxAdults']) ? (int)$room['maxAdults'] : 2,
                        'max_children' => isset($room['maxChildren']) ? (int)$room['maxChildren'] : 0,
                        'room_facilities' => !empty($roomFacilities) ? json_encode($roomFacilities) : null
                    ];

                    $roomRows[] = $roomData;
                }
            }

            // Per-hotel issues (Content API hotels.issues)
            if (!empty($hotel['issues']) && is_array($hotel['issues'])) {
                foreach ($hotel['issues'] as $issue) {
                    $issueRows[] = [
                        'hotel_code' => $hotelData['hotel_code'],
                        'issue_code' => isset($issue['issueCode']) ? (string) $issue['issueCode'] : (isset($issue['code']) ? (string) $issue['code'] : null),
                        'issue_type' => isset($issue['issueType']) ? (string) $issue['issueType'] : (isset($issue['type']) ? (string) $issue['type'] : null),
                        'description' => hotelbedsContentText($issue['description'] ?? $issue['name'] ?? ''),
                        'date_from' => !empty($issue['dateFrom']) ? substr((string) $issue['dateFrom'], 0, 10) : null,
                        'date_to' => !empty($issue['dateTo']) ? substr((string) $issue['dateTo'], 0, 10) : null,
                    ];
                }
            }

            // Per-hotel terminals (Content API hotels.terminals)
            if (!empty($hotel['terminals']) && is_array($hotel['terminals'])) {
                foreach ($hotel['terminals'] as $terminal) {
                    $terminalRows[] = [
                        'hotel_code' => $hotelData['hotel_code'],
                        'terminal_code' => isset($terminal['terminalCode']) ? (string) $terminal['terminalCode'] : (isset($terminal['code']) ? (string) $terminal['code'] : null),
                        'terminal_type' => isset($terminal['terminalType']) ? (string) $terminal['terminalType'] : (isset($terminal['type']) ? (string) $terminal['type'] : null),
                        'distance' => isset($terminal['distance']) ? (int) $terminal['distance'] : null,
                        'description' => hotelbedsContentText($terminal['description'] ?? $terminal['name'] ?? ''),
                    ];
                }
            }

            } catch (Exception $e) {
                // Skip this hotel if there's any error
                $skippedHotels++;
                $errorMsg = "[" . date('H:i:s') . "] [ERROR] Skipped hotel " . ($hotel['code'] ?? 'unknown') . ": " . $e->getMessage();
                error_log("[HOTELBEDS] " . $errorMsg);
                $terminalLogs[] = $errorMsg;
            }
        }

        // ----------------------------------------------------------------
        // Batched write. One delete pass + a few multi-row inserts, instead of
        // thousands of single-row queries. This is the difference between
        // ~2.5 min/chunk and a few seconds against a remote DB — and it keeps
        // each request short enough to stay under the host's proxy timeout.
        // ----------------------------------------------------------------

        // Free the decoded API response — we've extracted everything into the
        // row arrays and no longer need it during the (memory-heavy) inserts.
        unset($data, $hotels);

        // De-dup hotels by code within the chunk (a duplicate would fail the
        // whole multi-row insert because hotel_code is UNIQUE).
        if (!empty($hotelRows)) {
            $uniqueHotels = [];
            foreach ($hotelRows as $r) { $uniqueHotels[$r['hotel_code']] = $r; }
            $hotelRows = array_values($uniqueHotels);
            $codes = array_keys($uniqueHotels);
        } else {
            $codes = [];
        }

        // Upsert parents FIRST (never delete hotel rows before write — that caused
        // "processed" to climb while COUNT(*) stayed stuck when inserts failed).
        $hotelsSaved = 0;
        $hotelsFailed = 0;
        $savedCodes = [];
        foreach (array_chunk($hotelRows, 25) as $batch) {
            foreach ($batch as $row) {
                $code = $row['hotel_code'] ?? null;
                if ($code === null || $code === '') {
                    $hotelsFailed++;
                    continue;
                }
                try {
                    $hotelbedsDb->insert('hotelbeds_hotels', $row);
                    $hotelsSaved++;
                    $savedCodes[] = $code;
                } catch (Throwable $e) {
                    try {
                        $update = $row;
                        unset($update['created_at'], $update['hotel_code']);
                        $hotelbedsDb->update('hotelbeds_hotels', $update, ['hotel_code' => $code]);
                        $hotelsSaved++;
                        $savedCodes[] = $code;
                    } catch (Throwable $e2) {
                        $hotelsFailed++;
                        error_log('[HOTELBEDS] hotelbeds_hotels save failed for ' . $code . ': ' . $e2->getMessage());
                    }
                }
            }
        }

        // Refresh child rows only for hotels we actually saved.
        $savedCodes = array_values(array_unique($savedCodes));
        if (!empty($savedCodes)) {
            foreach (array_chunk($savedCodes, 500) as $cc) {
                foreach (['hotelbeds_amenities', 'hotelbeds_hotel_images', 'hotelbeds_hotel_rooms', 'hotelbeds_hotel_issues', 'hotelbeds_hotel_terminals'] as $tbl) {
                    try { $hotelbedsDb->delete($tbl, ['hotel_code' => $cc]); } catch (Exception $e) {}
                }
            }
        }

        // Insert helper: fast multi-row batches, but if a batch fails (e.g. one bad
        // row, or a packet-size limit) fall back to per-row so we only drop the
        // offending row instead of the whole batch. Best of both — speed + safety.
        $bulkInsert = function($table, $rows, $size) use ($hotelbedsDb) {
            foreach (array_chunk($rows, $size) as $batch) {
                try {
                    $hotelbedsDb->insert($table, $batch);
                } catch (Throwable $e) {
                    foreach ($batch as $row) {
                        try { $hotelbedsDb->insert($table, $row); }
                        catch (Throwable $e2) { error_log("[HOTELBEDS] $table row skipped: " . $e2->getMessage()); }
                    }
                }
            }
        };

        // Filter children to saved hotels only
        $savedSet = array_fill_keys($savedCodes, true);
        $filterSaved = function(array $rows) use ($savedSet) {
            if (empty($savedSet)) {
                return [];
            }
            return array_values(array_filter($rows, function ($r) use ($savedSet) {
                return isset($r['hotel_code']) && isset($savedSet[$r['hotel_code']]);
            }));
        };

        $bulkInsert('hotelbeds_amenities', $filterSaved($amenityRows), 300);
        $bulkInsert('hotelbeds_hotel_images', $filterSaved($imageRows), 300);
        $bulkInsert('hotelbeds_hotel_rooms', $filterSaved($roomRows), 300);
        $bulkInsert('hotelbeds_hotel_issues', $filterSaved($issueRows), 300);
        $bulkInsert('hotelbeds_hotel_terminals', $filterSaved($terminalRows), 300);

        // Count only hotels actually written — not merely returned by the API.
        $recordsProcessed = $hotelsSaved;

        // A short page alone is NOT proof of catalogue end (transient/truncated responses
        // happen). Caller decides completion using api_total + from/to + processed.
        $pageSize = max(1, ($to - $from + 1));
        $isLastChunk = ($hotelsReturned === 0)
            || ($apiTotal !== null && $apiTotal > 0 && $from > (int) $apiTotal)
            || ($apiTotal !== null && $apiTotal > 0 && $hotelsReturned < $pageSize && $to >= (int) $apiTotal);

        return [
            'success' => true,
            'records_processed' => $recordsProcessed,
            'hotels_failed' => $hotelsFailed,
            'skipped' => $skippedHotels,
            'hotels_returned' => $hotelsReturned,
            'is_last_chunk' => $isLastChunk,
            // The API reports the real catalogue size — surface it so the caller
            // can replace the hard-coded estimate and drive an accurate progress bar.
            'api_total' => $apiTotal
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
        ];
    }
}

// Helper function to create database schema
function createHotelbedsSchema($db) {
    // Hotels table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_hotels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        country_code VARCHAR(10),
        state_code VARCHAR(10),
        destination_code VARCHAR(10),
        zone_code VARCHAR(10),
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        category_code VARCHAR(10),
        category_name VARCHAR(100),
        accommodation_type VARCHAR(50),
        chain_code VARCHAR(10),
        segment_codes TEXT,
        address VARCHAR(255),
        postal_code VARCHAR(20),
        city VARCHAR(100),
        email VARCHAR(255),
        web VARCHAR(255),
        phone_number VARCHAR(50),
        fax_number VARCHAR(50),
        license VARCHAR(100),
        ranking INT,
        facilities LONGTEXT,
        images LONGTEXT,
        rooms LONGTEXT,
        sync_run_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        INDEX idx_destination (destination_code),
        INDEX idx_country (country_code),
        INDEX idx_sync_run (sync_run_id),
        -- Autocomplete matches on name and the listing resolves a city to
        -- destination codes. Without these two the 300k-row, multi-GB table is
        -- scanned end to end on every keystroke (measured 11s and 21s).
        INDEX idx_name (name(64)),
        INDEX idx_city (city(64))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Existing installs: widen TEXT columns + stamp column for orphan cleanup after update imports
    try {
        $db->query("ALTER TABLE hotelbeds_hotels
            MODIFY description LONGTEXT,
            MODIFY facilities LONGTEXT,
            MODIFY images LONGTEXT,
            MODIFY rooms LONGTEXT");
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Failed to widen hotel text columns: " . $e->getMessage());
    }
    try {
        $syncCol = $db->query("SHOW COLUMNS FROM hotelbeds_hotels LIKE 'sync_run_id'")->fetchAll();
        if (empty($syncCol)) {
            $db->query("ALTER TABLE hotelbeds_hotels ADD COLUMN sync_run_id INT NULL, ADD INDEX idx_sync_run (sync_run_id)");
        }
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Failed to add sync_run_id: " . $e->getMessage());
    }

    // Countries table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_countries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        iso_code VARCHAR(5),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Destinations table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_destinations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        country_code VARCHAR(10),
        zone_code VARCHAR(10),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_country (country_code),
        INDEX idx_dest_name (name(64))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Rooms table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) UNIQUE NOT NULL,
        type VARCHAR(10),
        characteristic VARCHAR(10),
        description VARCHAR(255),
        min_pax INT DEFAULT 1,
        max_pax INT DEFAULT 2,
        max_adults INT DEFAULT 2,
        max_children INT DEFAULT 0,
        min_adults INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Add columns to existing hotelbeds_rooms table if they don't exist
    try {
        $columns = $db->query("SHOW COLUMNS FROM hotelbeds_rooms LIKE 'min_pax'")->fetchAll();
        if (empty($columns)) {
            $db->query("ALTER TABLE hotelbeds_rooms 
                ADD COLUMN min_pax INT DEFAULT 1 AFTER description,
                ADD COLUMN max_pax INT DEFAULT 2 AFTER min_pax,
                ADD COLUMN max_adults INT DEFAULT 2 AFTER max_pax,
                ADD COLUMN max_children INT DEFAULT 0 AFTER max_adults,
                ADD COLUMN min_adults INT DEFAULT 1 AFTER max_children");
        }
    } catch (Exception $e) {
        // Ignore if table doesn't exist yet
    }

    // Boards table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_boards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Accommodations table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_accommodations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        type_description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Categories (star ratings / category masters)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        simple_code INT,
        accommodation_type VARCHAR(50),
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Chains
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_chains (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Segments
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_segments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Image type masters
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_image_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Zones (from destinations.locations)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        destination_code VARCHAR(10) NOT NULL,
        country_code VARCHAR(10),
        name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_zone_dest (destination_code, code),
        INDEX idx_destination (destination_code),
        INDEX idx_country (country_code),
        INDEX idx_zone_name (name(64))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Facility groups
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_facility_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code INT UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Facility typologies (Postman FacilityTypologies)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_facility_typologies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code INT UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Group categories (Postman GroupCategories)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_group_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Languages (Postman Languages)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_languages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Facilities table (reference data)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_facilities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code INT UNIQUE NOT NULL,
        description VARCHAR(255),
        facility_group_code INT,
        facility_type_code INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Issues master
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        type VARCHAR(50),
        name VARCHAR(255),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Terminals master
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_terminals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        type VARCHAR(50),
        name VARCHAR(255),
        description TEXT,
        country_code VARCHAR(10),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Currencies (Content API)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_currencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        description VARCHAR(255),
        currency_type VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Promotions
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_promotions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(255),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Rate comments — rateCommentsId = incoming|code|rateCodes
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_rate_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incoming VARCHAR(20) NOT NULL,
        code VARCHAR(50) NOT NULL,
        hotel_code VARCHAR(20),
        rate_codes VARCHAR(100) NOT NULL DEFAULT '',
        date_start DATE NULL,
        date_end DATE NULL,
        description TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rate_comment_lookup (incoming, code),
        INDEX idx_rate_comment_hotel (hotel_code),
        INDEX idx_rate_comment_dates (date_start, date_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Amenities table (per-hotel facilities)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_amenities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) NOT NULL,
        facility_code INT NOT NULL,
        facility_description VARCHAR(255),
        facility_group_code INT,
        distance INT DEFAULT NULL,
        order_by INT DEFAULT NULL,
        ind_fee TINYINT(1) DEFAULT NULL,
        ind_yes_or_no TINYINT(1) DEFAULT NULL,
        voucher TINYINT(1) DEFAULT NULL,
        fee_amount DECIMAL(12, 2) DEFAULT NULL,
        fee_currency VARCHAR(10) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        INDEX idx_facility_code (facility_code),
        FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Existing installs: add Content API fee / presence flags on amenities
    try {
        $feeCol = $db->query("SHOW COLUMNS FROM hotelbeds_amenities LIKE 'ind_fee'")->fetchAll();
        if (empty($feeCol)) {
            $db->query("ALTER TABLE hotelbeds_amenities
                ADD COLUMN ind_fee TINYINT(1) DEFAULT NULL AFTER order_by,
                ADD COLUMN ind_yes_or_no TINYINT(1) DEFAULT NULL AFTER ind_fee,
                ADD COLUMN voucher TINYINT(1) DEFAULT NULL AFTER ind_yes_or_no,
                ADD COLUMN fee_amount DECIMAL(12, 2) DEFAULT NULL AFTER voucher,
                ADD COLUMN fee_currency VARCHAR(10) DEFAULT NULL AFTER fee_amount");
        }
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Failed to add amenity fee columns: " . $e->getMessage());
    }

    // Hotel Images table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_hotel_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) NOT NULL,
        image_type VARCHAR(50),
        image_url VARCHAR(500),
        image_order INT DEFAULT 0,
        room_code VARCHAR(20) DEFAULT NULL,
        room_type VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Hotel Room Types table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_hotel_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) NOT NULL,
        room_code VARCHAR(20) NOT NULL,
        room_type VARCHAR(10),
        characteristic VARCHAR(10),
        description VARCHAR(255),
        min_pax INT DEFAULT 1,
        max_pax INT DEFAULT 2,
        max_adults INT DEFAULT 2,
        max_children INT DEFAULT 0,
        room_facilities LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        INDEX idx_room_code (room_code),
        FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-hotel issues (from Hotels Content payload)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_hotel_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) NOT NULL,
        issue_code VARCHAR(50),
        issue_type VARCHAR(50),
        description TEXT,
        date_from DATE NULL,
        date_to DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-hotel terminals (from Hotels Content payload)
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_hotel_terminals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_code VARCHAR(20) NOT NULL,
        terminal_code VARCHAR(50),
        terminal_type VARCHAR(50),
        distance INT DEFAULT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_code (hotel_code),
        FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->query("ALTER TABLE hotelbeds_hotel_rooms MODIFY room_facilities LONGTEXT");
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Failed to widen hotel room facilities column: " . $e->getMessage());
    }

    // segment_codes JSON on hotels (existing installs)
    try {
        $segCol = $db->query("SHOW COLUMNS FROM hotelbeds_hotels LIKE 'segment_codes'")->fetchAll();
        if (empty($segCol)) {
            $db->query("ALTER TABLE hotelbeds_hotels ADD COLUMN segment_codes TEXT NULL AFTER chain_code");
        }
    } catch (Exception $e) {
        error_log("[HOTELBEDS] Failed to add segment_codes: " . $e->getMessage());
    }

    // Import log table
    $db->query("CREATE TABLE IF NOT EXISTS hotelbeds_import_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mode ENUM('fresh', 'update') NOT NULL,
        status ENUM('in_progress', 'completed', 'failed', 'cancelled') DEFAULT 'in_progress',
        hotels_imported INT DEFAULT 0,
        destinations_imported INT DEFAULT 0,
        countries_imported INT DEFAULT 0,
        import_state LONGTEXT,
        error_message TEXT,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add import_state column if it doesn't exist (for existing installations)
    $columns = $db->query("SHOW COLUMNS FROM hotelbeds_import_log LIKE 'import_state'")->fetchAll();
    if (empty($columns)) {
        $db->query("ALTER TABLE hotelbeds_import_log ADD COLUMN import_state LONGTEXT AFTER countries_imported");
    }

    // Destination-autocomplete indexes for installs whose tables predate them.
    // Every one of these backs a `name LIKE 'x%'` or city lookup that the
    // suggestion endpoint and the listing search run on each request; without
    // them those became full scans of a multi-gigabyte table.
    foreach ([
        'hotelbeds_hotels'       => ['idx_name' => 'name(64)', 'idx_city' => 'city(64)'],
        'hotelbeds_destinations' => ['idx_dest_name' => 'name(64)'],
        'hotelbeds_zones'        => ['idx_zone_name' => 'name(64)'],
    ] as $indexTable => $wanted) {
        try {
            $existing = [];
            foreach ($db->query("SHOW INDEX FROM `$indexTable`")->fetchAll() as $row) {
                $existing[$row['Key_name']] = true;
            }
            foreach ($wanted as $indexName => $definition) {
                if (isset($existing[$indexName])) {
                    continue;
                }
                // INPLACE/NONE so adding it to a large live table neither
                // rebuilds it nor blocks the site while it builds.
                try {
                    $db->query("ALTER TABLE `$indexTable` ADD INDEX `$indexName` ($definition), ALGORITHM=INPLACE, LOCK=NONE");
                } catch (Exception $inner) {
                    $db->query("ALTER TABLE `$indexTable` ADD INDEX `$indexName` ($definition)");
                }
            }
        } catch (Exception $e) {
            error_log("[HOTELBEDS] Could not ensure indexes on $indexTable: " . $e->getMessage());
        }
    }
}


/**
 * Whether the hotel catalogue import has truly finished.
 * Do not treat a short/truncated API page as the end unless we are at/past api_total.
 */
function hotelbedsHotelImportIsComplete(array $import, array $result, int $from, int $to): bool {
    $apiTotal = (int) ($import['api_total'] ?? $result['api_total'] ?? 0);
    $processed = (int) ($import['processed'] ?? 0);
    $returned = (int) ($result['hotels_returned'] ?? $result['records_processed'] ?? 0);
    $chunkSize = max(1, (int) ($import['chunk_size'] ?? ($to - $from + 1)));
    $currentChunk = (int) ($import['current_chunk'] ?? 0);

    // Empty page after we have already imported hotels → catalogue end.
    if ($returned === 0 && ($currentChunk > 0 || $processed > 0)) {
        return true;
    }

    if ($apiTotal > 0) {
        if ($from > $apiTotal) {
            return true;
        }
        if ($processed >= $apiTotal) {
            return true;
        }
        // Short final page only when this range reaches the catalogue end.
        if ($returned > 0 && $returned < $chunkSize && $to >= $apiTotal) {
            return true;
        }
        $totalChunks = (int) ceil($apiTotal / $chunkSize);
        return $currentChunk >= $totalChunks && $processed >= max(1, $apiTotal - $chunkSize);
    }

    // Without api_total, only trust an explicit empty page (handled above) or
    // the safe is_last_chunk flag from the chunk helper (empty / past end).
    return !empty($result['is_last_chunk']) && $returned === 0;
}

/**
 * Last Sync + status for Import UI.
 * Prefer latest completed import's completed_at; if none, use latest hotel row updated_at
 * when content exists (partial/running imports never wrote status=completed → "Never" before).
 *
 * @return array{last_sync: string, status: string}
 */
function hotelbedsImportSyncMeta($db): array {
    $running = null;
    try {
        $running = $db->get('hotelbeds_import_log', ['status', 'updated_at'], [
            'status' => 'in_progress',
            'ORDER' => ['id' => 'DESC'],
        ]);
    } catch (Exception $e) {
    }

    $lastCompleted = null;
    try {
        $lastCompleted = $db->get('hotelbeds_import_log', ['status', 'updated_at', 'completed_at', 'hotels_imported'], [
            'status' => 'completed',
            'ORDER' => ['id' => 'DESC'],
        ]);
    } catch (Exception $e) {
    }

    $lastSync = 'Never';
    $ts = null;

    if ($lastCompleted) {
        $ts = $lastCompleted['completed_at'] ?? null;
        if (empty($ts) || $ts === '0000-00-00 00:00:00') {
            $ts = $lastCompleted['updated_at'] ?? null;
        }
    }

    // No completed run yet (still importing / cancelled / never finished) but hotels exist:
    // show when hotel content was last written.
    if (empty($ts) || $ts === '0000-00-00 00:00:00') {
        try {
            $row = $db->query(
                'SELECT MAX(updated_at) AS last_hotel_sync FROM hotelbeds_hotels'
            )->fetch(PDO::FETCH_ASSOC);
            $hotelTs = $row['last_hotel_sync'] ?? null;
            if (!empty($hotelTs) && $hotelTs !== '0000-00-00 00:00:00') {
                $ts = $hotelTs;
            }
        } catch (Exception $e) {
        }
    }

    // Last resort: any import log that actually imported hotels
    if (empty($ts) || $ts === '0000-00-00 00:00:00') {
        try {
            $any = $db->get('hotelbeds_import_log', ['updated_at', 'completed_at', 'hotels_imported'], [
                'hotels_imported[>]' => 0,
                'ORDER' => ['id' => 'DESC'],
            ]);
            if ($any) {
                $ts = $any['completed_at'] ?? null;
                if (empty($ts) || $ts === '0000-00-00 00:00:00') {
                    $ts = $any['updated_at'] ?? null;
                }
            }
        } catch (Exception $e) {
        }
    }

    if (!empty($ts) && $ts !== '0000-00-00 00:00:00') {
        $lastSync = date('M d, Y h:i A', strtotime($ts));
    }

    if ($running) {
        return ['last_sync' => $lastSync, 'status' => 'in_progress'];
    }
    if ($lastCompleted) {
        return ['last_sync' => $lastSync, 'status' => 'completed'];
    }

    return ['last_sync' => $lastSync, 'status' => $lastSync === 'Never' ? 'Ready' : 'Ready'];
}

/**
 * Unique hotels stored locally (DISTINCT hotel_code), not API catalogue size and not raw row count.
 */
function hotelbedsCountUniqueHotels($db): int {
    try {
        $row = $db->query(
            "SELECT COUNT(DISTINCT hotel_code) AS c
             FROM hotelbeds_hotels
             WHERE hotel_code IS NOT NULL AND hotel_code != ''"
        )->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['c'] ?? 0);
    } catch (Exception $e) {
        try {
            return (int) $db->count('hotelbeds_hotels');
        } catch (Exception $e2) {
            return 0;
        }
    }
}

/**
 * Last known Hotelbeds Content API hotel catalogue size (`response.total`).
 * Used for import progress denominator only — not the Total Hotels UI counter.
 */
function hotelbedsResolveApiHotelTotal($db): int {
    try {
        $rows = $db->select('hotelbeds_import_log', ['import_state', 'status', 'hotels_imported'], [
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 25,
        ]);
    } catch (Exception $e) {
        return 0;
    }

    if (empty($rows) || !is_array($rows)) {
        return 0;
    }

    foreach ($rows as $row) {
        $state = json_decode($row['import_state'] ?? '', true);
        if (!is_array($state)) {
            continue;
        }
        if (!empty($state['api_total']) && (int) $state['api_total'] > 0) {
            return (int) $state['api_total'];
        }
        // Pre-api_total key: chunk handler overwrote `total` with API total after first hotels page
        $chunk = (int) ($state['current_chunk'] ?? 0);
        $processed = (int) ($state['processed'] ?? 0);
        $imported = (int) ($row['hotels_imported'] ?? 0);
        if (($chunk > 0 || $processed > 0 || $imported > 0) && !empty($state['total']) && (int) $state['total'] > 0) {
            return (int) $state['total'];
        }
    }

    return 0;
}

/**
 * After a full catalogue import, delete hotels (and child rows) that were not
 * stamped with this import's sync_run_id — i.e. codes no longer returned by the API.
 *
 * @return int Number of hotel rows removed
 */
function purgeStaleHotelbedsHotels($db, int $syncRunId): int {
    if ($syncRunId <= 0) {
        return 0;
    }

    // Hotels never touched by this run (NULL = pre-stamp legacy rows, or other run ids).
    $orphanCodes = $db->select('hotelbeds_hotels', 'hotel_code', [
        'OR' => [
            'sync_run_id' => null,
            'sync_run_id[!]' => $syncRunId,
        ],
    ]);

    if (empty($orphanCodes) || !is_array($orphanCodes)) {
        return 0;
    }

    $orphanCodes = array_values(array_unique(array_filter($orphanCodes)));
    $removed = 0;
    $childTables = [
        'hotelbeds_amenities',
        'hotelbeds_hotel_images',
        'hotelbeds_hotel_rooms',
        'hotelbeds_hotel_issues',
        'hotelbeds_hotel_terminals',
    ];

    foreach (array_chunk($orphanCodes, 500) as $chunk) {
        foreach ($childTables as $tbl) {
            try {
                $db->delete($tbl, ['hotel_code' => $chunk]);
            } catch (Exception $e) {
                error_log("[HOTELBEDS] purge $tbl: " . $e->getMessage());
            }
        }
        try {
            $db->delete('hotelbeds_hotels', ['hotel_code' => $chunk]);
            $removed += count($chunk);
        } catch (Exception $e) {
            error_log('[HOTELBEDS] purge hotels: ' . $e->getMessage());
        }
    }

    return $removed;
}

// Helper function to truncate all Hotelbeds tables
function truncateHotelbedsTables($db) {
    // Child hotel tables first, then hotels, then all Content API masters
    $tables = [
        'hotelbeds_hotel_rooms',
        'hotelbeds_hotel_images',
        'hotelbeds_hotel_issues',
        'hotelbeds_hotel_terminals',
        'hotelbeds_amenities',
        'hotelbeds_hotels',
        'hotelbeds_rate_comments',
        'hotelbeds_zones',
        'hotelbeds_destinations',
        'hotelbeds_countries',
        'hotelbeds_rooms',
        'hotelbeds_boards',
        'hotelbeds_accommodations',
        'hotelbeds_categories',
        'hotelbeds_chains',
        'hotelbeds_segments',
        'hotelbeds_image_types',
        'hotelbeds_issues',
        'hotelbeds_terminals',
        'hotelbeds_currencies',
        'hotelbeds_promotions',
        'hotelbeds_facility_groups',
        'hotelbeds_facility_typologies',
        'hotelbeds_group_categories',
        'hotelbeds_languages',
        'hotelbeds_facilities',
    ];

    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        try {
            $db->query("TRUNCATE TABLE $table");
        } catch (Exception $e) {
            error_log("[HOTELBEDS] Failed to truncate $table: " . $e->getMessage());
        }
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
}

// Helper function to drop all Hotelbeds tables (for complete cleanup)
function dropHotelbedsTables($db) {
    $tables = [
        'hotelbeds_hotel_rooms',
        'hotelbeds_hotel_images',
        'hotelbeds_hotel_issues',
        'hotelbeds_hotel_terminals',
        'hotelbeds_amenities',
        'hotelbeds_hotels',
        'hotelbeds_rate_comments',
        'hotelbeds_zones',
        'hotelbeds_destinations',
        'hotelbeds_countries',
        'hotelbeds_rooms',
        'hotelbeds_boards',
        'hotelbeds_accommodations',
        'hotelbeds_categories',
        'hotelbeds_chains',
        'hotelbeds_segments',
        'hotelbeds_image_types',
        'hotelbeds_issues',
        'hotelbeds_terminals',
        'hotelbeds_currencies',
        'hotelbeds_promotions',
        'hotelbeds_facility_groups',
        'hotelbeds_facility_typologies',
        'hotelbeds_group_categories',
        'hotelbeds_languages',
        'hotelbeds_facilities',
        'hotelbeds_import_log',
    ];

    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        try {
            $db->query("DROP TABLE IF EXISTS $table");
        } catch (Exception $e) {
            error_log("[HOTELBEDS] Failed to drop $table: " . $e->getMessage());
        }
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
}

// POST endpoint: Manually populate countries/destinations from existing hotel data
$router->post('/stays/hotelbeds/populate-countries-destinations', function($request) use ($db) {
    try {
        $hotelbedsDb = getHotelbedsDb();
        
        if (!$hotelbedsDb) {
            echo json_encode(['success' => false, 'error' => 'Cannot connect to Hotelbeds database']);
            exit;
        }
        
        $countriesCount = populateCountriesFromHotels($hotelbedsDb);
        $destinationsCount = populateDestinationsFromHotels($hotelbedsDb);
        
        echo json_encode([
            'success' => true,
            'countries' => $countriesCount,
            'destinations' => $destinationsCount,
            'message' => "Populated $countriesCount countries and $destinationsCount destinations from hotel data"
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

// Helper function to create import log entry
function createImportLog($db, $mode) {
    $db->insert('hotelbeds_import_log', [
        'mode' => $mode,
        'status' => 'in_progress',
        'started_at' => $db->raw('NOW()')
    ]);
    return $db->id();
}
