<?php
// ============================================================================
// GRNCONNECT CONTENT / STATIC-DATA IMPORT CONTROLLER
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/import
// Creates the GRNConnect content database + tables and imports static assets
// (countries, cities, hotel codes) required to drive availability search.
//
// Actions: check | create_tables | import_countries | import_cities |
//          import_hotels | progress | reset
//
// Also exposed as executable modules/stays/grnconnect/import.php so live hosts
// that fall through to app/routes/globalRoutes.php still work.
// ============================================================================

if (!function_exists('grnconnect_raw_pdo')) {
    /**
     * Raw PDO connection to the content DB server (optionally without db name)
     * using the modules row host/username/password.
     */
    function grnconnect_raw_pdo($module, $withDatabase = true)
    {
        $host = $module['host'] ?: 'localhost';
        $dsn  = "mysql:host={$host};charset=utf8mb4";
        if ($withDatabase && !empty($module['database'])) {
            $dsn = "mysql:host={$host};dbname={$module['database']};charset=utf8mb4";
        }
        return new PDO($dsn, $module['username'] ?? 'root', $module['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}

if (!function_exists('grnconnect_create_schema')) {
    /** Create the content database and all tables if they do not exist. */
    function grnconnect_create_schema($module)
    {
        if (empty($module['database'])) {
            throw new Exception('Content database name is not configured for grnconnect.');
        }

        // Create the database first (connect without db name).
        $server = grnconnect_raw_pdo($module, false);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$module['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pdo = grnconnect_raw_pdo($module, true);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_countries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(4) UNIQUE NOT NULL,
                code3 VARCHAR(4),
                name VARCHAR(191),
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_cities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                city_code VARCHAR(32) UNIQUE NOT NULL,
                name VARCHAR(255),
                search_name VARCHAR(191),
                country_code VARCHAR(4),
                dest_code VARCHAR(32),
                INDEX idx_search (search_name),
                INDEX idx_country (country_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_hotels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hotel_code VARCHAR(32) UNIQUE NOT NULL,
                name VARCHAR(255),
                address TEXT,
                city_code VARCHAR(32),
                country_code VARCHAR(4),
                latitude DECIMAL(10,7),
                longitude DECIMAL(10,7),
                category VARCHAR(8),
                acc_name VARCHAR(64),
                chain_name VARCHAR(128),
                postal_code VARCHAR(32),
                description LONGTEXT,
                facilities LONGTEXT,
                images JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_city (city_code),
                INDEX idx_name (name),
                INDEX idx_geo (latitude, longitude)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_import_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task VARCHAR(32) UNIQUE NOT NULL,
                total INT DEFAULT 0,
                processed INT DEFAULT 0,
                status ENUM('idle','running','completed','error') DEFAULT 'idle',
                message TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Dedicated image gallery table (mirrors hotelbeds/stuba pattern).
        // Populated from GET api/v3/hotels/{code}/images during import / details fallback.
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

        return $pdo;
    }
}

if (!function_exists('grnconnect_module')) {
    /** Fetch the grnconnect modules row. */
    function grnconnect_module($db)
    {
        return $db->get('modules', '*', ['name' => 'grnconnect', 'type' => 'stays']);
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

if (!function_exists('grnconnect_handle_import')) {
    /**
     * Run a GRNConnect content-import action and exit with JSON.
     * Used by modules router AND by modules/stays/grnconnect/import.php.
     */
    function grnconnect_handle_import($db)
    {
        while (ob_get_level()) ob_end_clean();
        ob_start();
        header('Content-Type: application/json');

        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $send = function ($data) {
            if (ob_get_level()) ob_clean();
            echo json_encode($data, JSON_UNESCAPED_SLASHES);
            exit;
        };

        try {
            require_once __DIR__ . '/../api.php';

            $module = grnconnect_module($db);
            if (!$module) {
                $send(['success' => false, 'error' => 'GRNConnect module not configured']);
            }

            $action = $_POST['action'] ?? $_GET['action'] ?? 'check';

            // ------------------------------------------------------------------
            // CHECK - report DB existence + row counts
            // ------------------------------------------------------------------
            if ($action === 'check') {
                $stats = ['db_exists' => false, 'countries' => 0, 'cities' => 0, 'hotels' => 0, 'images' => 0];
                try {
                    $pdo = grnconnect_raw_pdo($module, true);
                    $stats['db_exists'] = true;
                    foreach ([
                        'countries' => 'grnconnect_countries',
                        'cities'    => 'grnconnect_cities',
                        'hotels'    => 'grnconnect_hotels',
                        'images'    => 'grnconnect_hotel_images',
                    ] as $k => $t) {
                        try {
                            $stats[$k] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
                        } catch (\Throwable $e) { /* table missing */ }
                    }
                } catch (\Throwable $e) {
                    // DB not created yet
                }
                $send(['success' => true, 'stats' => $stats, 'database' => $module['database'] ?? '']);
            }

            // ------------------------------------------------------------------
            // CREATE TABLES (also creates DB)
            // ------------------------------------------------------------------
            if ($action === 'create_tables') {
                grnconnect_create_schema($module);
                $send(['success' => true, 'message' => 'Content database and tables are ready.']);
            }

            $client = GRNConnectClient::fromModule($module, ['request_timeout' => 300]);
            if (!$client->hasCredentials()) {
                $send(['success' => false, 'error' => 'GRNConnect API key / base URL not configured']);
            }

            // Ensure schema exists for all import actions.
            $pdo = grnconnect_create_schema($module);

            $setProgress = function ($task, $status, $total, $processed, $message = '') use ($pdo) {
                $stmt = $pdo->prepare("
                    INSERT INTO grnconnect_import_progress (task, total, processed, status, message)
                    VALUES (:task, :total, :processed, :status, :message)
                    ON DUPLICATE KEY UPDATE total=:total2, processed=:processed2, status=:status2, message=:message2
                ");
                $stmt->execute([
                    ':task' => $task, ':total' => $total, ':processed' => $processed, ':status' => $status, ':message' => $message,
                    ':total2' => $total, ':processed2' => $processed, ':status2' => $status, ':message2' => $message,
                ]);
            };

            // ------------------------------------------------------------------
            // IMPORT COUNTRIES
            // ------------------------------------------------------------------
            if ($action === 'import_countries') {
                $res = $client->countries();
                if (!$res['success']) {
                    $setProgress('countries', 'error', 0, 0, $res['error']);
                    $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch countries']);
                }
                $countries = $res['data']['countries'] ?? [];
                $stmt = $pdo->prepare("
                    INSERT INTO grnconnect_countries (code, code3, name) VALUES (:code, :code3, :name)
                    ON DUPLICATE KEY UPDATE code3=:code3b, name=:nameb
                ");
                $count = 0;
                foreach ($countries as $c) {
                    if (empty($c['code'])) continue;
                    $stmt->execute([
                        ':code' => $c['code'], ':code3' => $c['code3'] ?? '', ':name' => $c['name'] ?? '',
                        ':code3b' => $c['code3'] ?? '', ':nameb' => $c['name'] ?? '',
                    ]);
                    $count++;
                }
                $setProgress('countries', 'completed', $count, $count);
                $send(['success' => true, 'imported' => $count, 'message' => "Imported {$count} countries."]);
            }

            // ------------------------------------------------------------------
            // IMPORT CITIES (all, or for a country)
            // ------------------------------------------------------------------
            if ($action === 'import_cities') {
                $country = trim($_POST['country'] ?? '');
                $res = $client->cities($country);
                if (!$res['success']) {
                    $setProgress('cities', 'error', 0, 0, $res['error']);
                    $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch cities']);
                }
                $cities = $res['data']['cities'] ?? [];
                $total  = count($cities);
                $setProgress('cities', 'running', $total, 0);

                $stmt = $pdo->prepare("
                    INSERT INTO grnconnect_cities (city_code, name, search_name, country_code, dest_code)
                    VALUES (:code, :name, :search, :country, :dest)
                    ON DUPLICATE KEY UPDATE name=:nameb, search_name=:searchb, country_code=:countryb, dest_code=:destb
                ");
                $count = 0;
                foreach ($cities as $c) {
                    if (empty($c['code'])) continue;
                    $name   = $c['name'] ?? '';
                    $search = grnconnect_clean_city_name($name);
                    $cc     = $c['country'] ?? ($country ?: '');
                    $stmt->execute([
                        ':code' => $c['code'], ':name' => $name, ':search' => $search, ':country' => $cc, ':dest' => $c['dest_code'] ?? '',
                        ':nameb' => $name, ':searchb' => $search, ':countryb' => $cc, ':destb' => $c['dest_code'] ?? '',
                    ]);
                    $count++;
                }
                $setProgress('cities', 'completed', $total, $count);
                $send(['success' => true, 'imported' => $count, 'message' => "Imported {$count} cities."]);
            }

            // ------------------------------------------------------------------
            // IMPORT HOTELS (by city_code or by country)
            // ------------------------------------------------------------------
            if ($action === 'import_hotels') {
                $cityCode = trim($_POST['city'] ?? $_POST['city_code'] ?? '');
                $country  = trim($_POST['country'] ?? '');

                if ($cityCode === '' && $country === '') {
                    $send(['success' => false, 'error' => 'Provide a city code or country code to import hotels.']);
                }

                $res = $cityCode !== '' ? $client->hotelsByCity($cityCode) : $client->hotelsByCountry($country);
                if (!$res['success']) {
                    $setProgress('hotels', 'error', 0, 0, $res['error']);
                    $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch hotels']);
                }

                $hotels = $res['data']['hotels'] ?? [];
                $total  = count($hotels);
                $setProgress('hotels', 'running', $total, 0);

                $stmt = $pdo->prepare("
                    INSERT INTO grnconnect_hotels
                        (hotel_code, name, address, city_code, country_code, latitude, longitude, category, acc_name, chain_name, postal_code, description, facilities, images)
                    VALUES
                        (:code, :name, :address, :city, :country, :lat, :lng, :cat, :acc, :chain, :postal, :descr, :fac, :images)
                    ON DUPLICATE KEY UPDATE
                        name=:nameb, address=:addressb, city_code=:cityb, country_code=:countryb, latitude=:latb, longitude=:lngb,
                        category=:catb, acc_name=:accb, chain_name=:chainb, postal_code=:postalb, description=:descrb, facilities=:facb, images=:imagesb
                ");

                $count = 0;
                $imagesImported = 0;
                $imagesSkipped  = 0;
                $imagesEmpty    = 0;
                foreach ($hotels as $h) {
                    $code = $h['code'] ?? $h['hotel_code'] ?? '';
                    if ($code === '') continue;
                    $images = isset($h['images']) ? json_encode($h['images']) : null;
                    $params = [
                        ':code'   => $code,
                        ':name'   => $h['name'] ?? '',
                        ':address'=> $h['address'] ?? '',
                        ':city'   => $h['city_code'] ?? $cityCode,
                        ':country'=> $h['country'] ?? $country,
                        ':lat'    => is_numeric($h['latitude'] ?? null) ? $h['latitude'] : null,
                        ':lng'    => is_numeric($h['longitude'] ?? null) ? $h['longitude'] : null,
                        ':cat'    => $h['category'] ?? '',
                        ':acc'    => $h['acc_name'] ?? '',
                        ':chain'  => $h['chain_name'] ?? '',
                        ':postal' => $h['postal_code'] ?? '',
                        ':descr'  => $h['description'] ?? '',
                        ':fac'    => $h['facilities'] ?? '',
                        ':images' => $images,
                    ];
                    // Duplicate the values for the UPDATE clause bindings.
                    $params += [
                        ':nameb' => $params[':name'], ':addressb' => $params[':address'], ':cityb' => $params[':city'],
                        ':countryb' => $params[':country'], ':latb' => $params[':lat'], ':lngb' => $params[':lng'],
                        ':catb' => $params[':cat'], ':accb' => $params[':acc'], ':chainb' => $params[':chain'],
                        ':postalb' => $params[':postal'], ':descrb' => $params[':descr'], ':facb' => $params[':fac'], ':imagesb' => $params[':images'],
                    ];
                    $stmt->execute($params);
                    $count++;

                    // Persist Images API gallery locally (skip if already imported).
                    if (function_exists('grnconnect_import_hotel_images')) {
                        try {
                            $imgResult = grnconnect_import_hotel_images($client, $pdo, $code, ['source' => 'import']);
                            if (!empty($imgResult['skipped'])) {
                                $imagesSkipped++;
                            } elseif (!empty($imgResult['empty'])) {
                                $imagesEmpty++;
                            } else {
                                $imagesImported += (int)($imgResult['saved'] ?? 0);
                                // Keep a compact URL list on the hotel row for search listing.
                                if (!empty($imgResult['urls'])) {
                                    $upd = $pdo->prepare('UPDATE grnconnect_hotels SET images = :images WHERE hotel_code = :code');
                                    $upd->execute([
                                        ':images' => json_encode(array_values($imgResult['urls'])),
                                        ':code'   => $code,
                                    ]);
                                }
                            }
                        } catch (\Throwable $imgEx) {
                            error_log('GRNConnect image import failed for ' . $code . ': ' . $imgEx->getMessage());
                        }
                    }

                    if ($count % 10 === 0) {
                        $setProgress('hotels', 'running', $total, $count, "Hotels {$count}/{$total}; images saved {$imagesImported}");
                    }
                }
                $setProgress('hotels', 'completed', $total, $count, "Hotels {$count}; images imported {$imagesImported}, skipped {$imagesSkipped}, empty {$imagesEmpty}");
                $send([
                    'success'         => true,
                    'imported'        => $count,
                    'images_imported' => $imagesImported,
                    'images_skipped'  => $imagesSkipped,
                    'images_empty'    => $imagesEmpty,
                    'message'         => "Imported {$count} hotels. Images: {$imagesImported} saved, {$imagesSkipped} skipped (already local), {$imagesEmpty} with no images.",
                ]);
            }

            // ------------------------------------------------------------------
            // PROGRESS
            // ------------------------------------------------------------------
            if ($action === 'progress') {
                $rows = $pdo->query("SELECT task, total, processed, status, message, updated_at FROM grnconnect_import_progress")->fetchAll(PDO::FETCH_ASSOC);
                $send(['success' => true, 'progress' => $rows]);
            }

            // ------------------------------------------------------------------
            // RESET - truncate content tables
            // ------------------------------------------------------------------
            if ($action === 'reset') {
                foreach ([
                    'grnconnect_hotel_images',
                    'grnconnect_hotels',
                    'grnconnect_cities',
                    'grnconnect_countries',
                    'grnconnect_import_progress',
                ] as $t) {
                    try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (\Throwable $e) {}
                }
                $send(['success' => true, 'message' => 'Content tables cleared.']);
            }

            $send(['success' => false, 'error' => 'Unknown action: ' . $action]);

        } catch (\Throwable $e) {
            $send(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}

// Modules API gateway registration (localhost / modules/index.php path)
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    $router->post('/stays/grnconnect/import', function () use ($db) {
        grnconnect_handle_import($db);
    });
}
