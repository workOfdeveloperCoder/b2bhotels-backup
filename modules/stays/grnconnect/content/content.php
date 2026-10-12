<?php
// ============================================================================
// GRNCONNECT CONTENT / STATIC-DATA IMPORT CONTROLLER
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/import
// Creates the GRNConnect content database + tables and imports static assets
// (countries, cities, hotel codes) required to drive availability search.
//
// Actions: check | create_tables | import_countries | import_cities |
//          import_hotels | import_hotels_all | import_hotels_all_stop | check_hotel_codes |
//          import_hotel_codes | progress | reset
// Import All Hotels queues one DB job per city and processes them one at a time.
// It does not spawn a long-running CLI server process.
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
    function grnconnect_create_schema($module, $migrateHotels = true)
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
                hotels_imported TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_search (search_name),
                INDEX idx_country (country_code),
                INDEX idx_hotels_imported (hotels_imported)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $pdo->exec("ALTER TABLE grnconnect_cities ADD COLUMN hotels_imported TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) { /* already exists */ }
        try {
            $pdo->exec("ALTER TABLE grnconnect_cities ADD INDEX idx_hotels_imported (hotels_imported)");
        } catch (\Throwable $e) { /* already exists */ }

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
                category VARCHAR(32),
                acc_name VARCHAR(64),
                chain_name VARCHAR(128),
                postal_code VARCHAR(191),
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

        // Never ALTER a large hotels table on every request — that hung Import All on CloudPanel.
        if ($migrateHotels) {
            try {
                $col = $pdo->query("SHOW COLUMNS FROM grnconnect_hotels LIKE 'postal_code'")->fetch(PDO::FETCH_ASSOC);
                if ($col && stripos((string)($col['Type'] ?? ''), 'varchar(191)') === false) {
                    $pdo->exec("ALTER TABLE grnconnect_hotels MODIFY postal_code VARCHAR(191)");
                }
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $col = $pdo->query("SHOW COLUMNS FROM grnconnect_hotels LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
                if ($col && stripos((string)($col['Type'] ?? ''), 'varchar(32)') === false) {
                    $pdo->exec("ALTER TABLE grnconnect_hotels MODIFY category VARCHAR(32)");
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_import_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                city_code VARCHAR(32) NOT NULL,
                country_code VARCHAR(4) DEFAULT '',
                status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                hotels_new INT NOT NULL DEFAULT 0,
                hotels_updated INT NOT NULL DEFAULT 0,
                hotels_skipped INT NOT NULL DEFAULT 0,
                last_error TEXT,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_city (city_code),
                INDEX idx_status (status),
                INDEX idx_status_attempts (status, attempts, id)
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

if (!function_exists('grnconnect_clip')) {
    /** Truncate a string to a VARCHAR length (characters) so import cannot 1406. */
    function grnconnect_clip($value, $max)
    {
        $s = trim((string)$value);
        if ($s === '' || $max < 1) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string)mb_substr($s, 0, $max, 'UTF-8');
        }
        return (string)substr($s, 0, $max);
    }
}

if (!function_exists('grnconnect_normalize_hotel_record')) {
    function grnconnect_normalize_hotel_record(array $h)
    {
        if (isset($h['geolocation']) && is_array($h['geolocation'])) {
            if (!isset($h['latitude']) || $h['latitude'] === '' || $h['latitude'] === null) {
                $h['latitude'] = $h['geolocation']['latitude'] ?? null;
            }
            if (!isset($h['longitude']) || $h['longitude'] === '' || $h['longitude'] === null) {
                $h['longitude'] = $h['geolocation']['longitude'] ?? null;
            }
        }
        if (trim((string)($h['acc_name'] ?? '')) === '' && !empty($h['acc_type'])) {
            $h['acc_name'] = $h['acc_type'];
        }
        return $h;
    }
}

if (!function_exists('grnconnect_hotel_import_map')) {
    /** Normalize a GRN hotel for insert/compare. Fingerprint excludes LONGTEXT. */
    function grnconnect_hotel_import_map(array $h, $cityCode = '', $country = '')
    {
        $h = grnconnect_normalize_hotel_record($h);
        $lat = is_numeric($h['latitude'] ?? null) ? round((float)$h['latitude'], 7) : null;
        $lng = is_numeric($h['longitude'] ?? null) ? round((float)$h['longitude'], 7) : null;
        $row = [
            'code'    => grnconnect_clip((string)($h['code'] ?? $h['hotel_code'] ?? ''), 32),
            'name'    => grnconnect_clip($h['name'] ?? '', 255),
            'address' => (string)($h['address'] ?? ''),
            'city'    => grnconnect_clip($h['city_code'] ?? $cityCode, 32),
            'country' => grnconnect_clip($h['country'] ?? $country, 4),
            'lat'     => $lat,
            'lng'     => $lng,
            'cat'     => grnconnect_clip($h['category'] ?? '', 32),
            'acc'     => grnconnect_clip($h['acc_name'] ?? '', 64),
            'chain'   => grnconnect_clip($h['chain_name'] ?? '', 128),
            'postal'  => grnconnect_clip($h['postal_code'] ?? '', 191),
            'descr'   => (string)($h['description'] ?? ''),
            'fac'     => (string)($h['facilities'] ?? ''),
            'images'  => isset($h['images']) ? json_encode($h['images']) : null,
        ];
        $row['fp'] = $row['name'] . "\n" . $row['address'] . "\n" . $row['city'] . "\n" . $row['country'] . "\n"
            . ($row['lat'] === null ? '' : (string)$row['lat']) . "\n"
            . ($row['lng'] === null ? '' : (string)$row['lng']) . "\n"
            . $row['cat'] . "\n" . $row['acc'] . "\n" . $row['chain'] . "\n" . $row['postal'];
        return $row;
    }
}

if (!function_exists('grnconnect_parse_hotel_codes')) {
    /** Unique GRN hotel codes from pasted text (newlines, commas, spaces). */
    function grnconnect_parse_hotel_codes($raw)
    {
        preg_match_all('/[0-9]{5,16}/', (string)$raw, $m);
        return array_values(array_unique($m[0] ?? []));
    }
}

if (!function_exists('grnconnect_lookup_hotel_codes_in_db')) {
    /**
     * Split a code list into already-imported vs missing.
     * @return array{present: array<string,array>, missing: string[]}
     */
    function grnconnect_lookup_hotel_codes_in_db($pdo, array $codes)
    {
        $present = [];
        foreach (array_chunk($codes, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT hotel_code, name, city_code, country_code
                 FROM grnconnect_hotels WHERE hotel_code IN ({$in})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $present[(string)$row['hotel_code']] = $row;
            }
        }
        $missing = [];
        foreach ($codes as $code) {
            if (!isset($present[$code])) {
                $missing[] = $code;
            }
        }
        return ['present' => $present, 'missing' => $missing];
    }
}

if (!function_exists('grnconnect_hotels_from_hcode_response')) {
    function grnconnect_hotels_from_hcode_response(array $res)
    {
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $hotels = [];
        if (!empty($data['hotels']) && is_array($data['hotels'])) {
            $hotels = $data['hotels'];
        } elseif (!empty($data['hotel']) && is_array($data['hotel']) && !isset($data['hotel'][0])) {
            $hotels = [$data['hotel']];
        } elseif (!empty($data['hotel_code']) || !empty($data['code'])) {
            $hotels = [$data];
        }
        $out = [];
        foreach ($hotels as $h) {
            if (!is_array($h)) {
                continue;
            }
            $out[] = grnconnect_normalize_hotel_record($h);
        }
        return $out;
    }
}

if (!function_exists('grnconnect_fetch_hotels_via_static_hcodes')) {
    /**
     * GET api/v3/hotels?hcode=… on the static CDN. Returns hotels even with no live rate.
     *
     * @return array{hotels: array, failed: string[]}
     */
    function grnconnect_fetch_hotels_via_static_hcodes($client, array $codes)
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static function ($c) {
            return trim($c) !== '';
        })));
        $hotels = [];
        $failed = [];
        if (!$codes) {
            return ['hotels' => [], 'failed' => [], 'errors' => []];
        }
        $results = method_exists($client, 'hotelsByCodes')
            ? $client->hotelsByCodes($codes, 60)
            : [];
        $errors = [];
        foreach ($codes as $i => $code) {
            $res = $results[$i] ?? null;
            if ($res === null && method_exists($client, 'hotel')) {
                $res = $client->hotel($code);
            }
            if (empty($res['success'])) {
                $failed[] = $code;
                $err = trim((string)($res['error'] ?? ''));
                if ($err !== '' && count($errors) < 3) {
                    $errors[] = $code . ': ' . $err;
                }
                continue;
            }
            $batch = grnconnect_hotels_from_hcode_response($res);
            if (!$batch) {
                $failed[] = $code;
                continue;
            }
            foreach ($batch as $h) {
                $hotels[] = $h;
            }
        }
        return ['hotels' => $hotels, 'failed' => $failed, 'errors' => $errors];
    }
}

if (!function_exists('grnconnect_hotels_from_availability_response')) {
    /** hotel_code => hotel from one availability/refetch result. */
    function grnconnect_hotels_from_availability_response(array $res)
    {
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $hotels = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];
        $out = [];
        foreach ($hotels as $h) {
            if (!is_array($h)) {
                continue;
            }
            $h = grnconnect_normalize_hotel_record($h);
            $code = (string)($h['hotel_code'] ?? $h['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = $h;
        }
        return $out;
    }
}

if (!function_exists('grnconnect_availability_pending_search_ids')) {
    function grnconnect_availability_pending_search_ids(array $responses)
    {
        $ids = [];
        foreach ($responses as $res) {
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $sid = trim((string)($data['search_id'] ?? ''));
            if ($sid !== '' && !empty($data['more_results'])) {
                $ids[] = $sid;
            }
        }
        return $ids;
    }
}

if (!function_exists('grnconnect_city_dest_meta')) {
    function grnconnect_city_dest_meta($pdo, $cityCode)
    {
        $out = ['dest_code' => '', 'country_code' => ''];
        $cityCode = trim((string)$cityCode);
        if ($cityCode === '') {
            return $out;
        }
        try {
            $st = $pdo->prepare('SELECT dest_code, country_code FROM grnconnect_cities WHERE city_code = ? LIMIT 1');
            $st->execute([$cityCode]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $out['dest_code'] = trim((string)($row['dest_code'] ?? ''));
                $out['country_code'] = trim((string)($row['country_code'] ?? ''));
            }
        } catch (\Throwable $e) { /* column may not exist */ }
        return $out;
    }
}

if (!function_exists('grnconnect_hotel_codes_for_city')) {
    function grnconnect_hotel_codes_for_city($pdo, $cityCode)
    {
        $cityCode = trim((string)$cityCode);
        if ($cityCode === '') {
            return [];
        }
        $st = $pdo->prepare('SELECT hotel_code FROM grnconnect_hotels WHERE city_code = ?');
        $st->execute([$cityCode]);
        $codes = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
    }
}

if (!function_exists('grnconnect_hotel_codes_from_list')) {
    function grnconnect_hotel_codes_from_list(array $hotels)
    {
        $codes = [];
        foreach ($hotels as $h) {
            if (!is_array($h)) {
                continue;
            }
            $code = trim((string)($h['hotel_code'] ?? $h['code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }
        return array_values($codes);
    }
}

if (!function_exists('grnconnect_fetch_hotels_via_availability')) {
    /**
     * Live POST api/v3/hotels/availability (c2). Max 100 hotel_codes per request.
     * Empty $hotelCodes tries destination_code (older GRN). Latest docs require codes.
     *
     * @return array{success:bool,needs_codes:bool,hotels:array,listed:int,checkin:string,checkout:string,error:string}
     */
    function grnconnect_fetch_hotels_via_availability($client, array $hotelCodes, $cityCode = '', $destCode = '')
    {
        $checkin = date('Y-m-d', strtotime('+21 days'));
        $checkout = date('Y-m-d', strtotime('+22 days'));
        $base = [
            'checkin'            => $checkin,
            'checkout'           => $checkout,
            'client_nationality' => 'US',
            'currency'           => 'USD',
            'rates'              => 'concise',
            'cutoff_time'        => 20000,
            'more_results'       => true,
            'rooms'              => [['adults' => 2]],
            'options'            => ['hotel_info' => true],
        ];
        $cityCode = trim((string)$cityCode);
        $destCode = trim((string)$destCode);
        if ($cityCode !== '') {
            $base['city_code'] = $cityCode;
        }

        $codes = [];
        foreach ($hotelCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }
        $codes = array_values($codes);

        $empty = [
            'success'     => false,
            'needs_codes' => false,
            'hotels'      => [],
            'listed'      => 0,
            'checkin'     => $checkin,
            'checkout'    => $checkout,
            'error'       => '',
        ];

        if ($codes) {
            $payloads = [];
            foreach (array_chunk($codes, 100) as $chunk) {
                $payload = $base;
                $payload['hotel_codes'] = array_values($chunk);
                $payloads[] = $payload;
            }
            $responses = $client->availabilityParallel($payloads, 60);
        } else {
            $payload = $base;
            if ($destCode !== '') {
                $payload['destination_code'] = $destCode;
            } elseif ($cityCode !== '') {
                $payload['destination_code'] = $cityCode;
            } else {
                $empty['needs_codes'] = true;
                $empty['error'] = 'Live availability needs hotel codes or a destination code.';
                return $empty;
            }
            $res = $client->availability($payload, 60);
            $responses = [$res];
            if (empty($res['success'])) {
                $err = strtolower((string)($res['error'] ?? ''));
                $empty['needs_codes'] = true;
                $empty['error'] = $res['error'] ?: 'Live availability failed';
                if ($err !== '' && !preg_match('/hotel_code|required|invalid|destination|missing/i', $err)) {
                    $empty['needs_codes'] = true;
                }
                return $empty;
            }
        }

        $byCode = [];
        foreach ($responses as $res) {
            foreach (grnconnect_hotels_from_availability_response($res) as $code => $h) {
                $byCode[$code] = $h;
            }
        }

        for ($round = 0; $round < 3; $round++) {
            $pending = grnconnect_availability_pending_search_ids($responses);
            if (!$pending) {
                break;
            }
            $responses = $client->refetchBySearchIdParallel($pending, 'concise');
            foreach ($responses as $res) {
                foreach (grnconnect_hotels_from_availability_response($res) as $code => $h) {
                    $byCode[$code] = $h;
                }
            }
        }

        return [
            'success'     => true,
            'needs_codes' => false,
            'hotels'      => array_values($byCode),
            'listed'      => count($byCode),
            'checkin'     => $checkin,
            'checkout'    => $checkout,
            'error'       => '',
        ];
    }
}

if (!function_exists('grnconnect_upsert_hotels')) {
    /**
     * Insert hotels that are not already in the table. Existing hotel_codes are skipped.
     * One bad row is skipped so the city import cannot stall.
     */
    function grnconnect_upsert_hotels($pdo, array $hotels, $cityCode = '', $country = '', $client = null, $skipImages = false, $skipExisting = true)
    {
        $incoming = [];
        foreach ($hotels as $h) {
            if (!is_array($h)) {
                continue;
            }
            $row = grnconnect_hotel_import_map($h, $cityCode, $country);
            if ($row['code'] === '') {
                continue;
            }
            $incoming[$row['code']] = $row;
        }
        $empty = [
            'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
            'images_imported' => 0, 'images_skipped' => 0, 'images_empty' => 0,
        ];
        if (!$incoming) {
            return $empty;
        }

        $existingFp = [];
        foreach (array_chunk(array_keys($incoming), 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $find = $pdo->prepare("SELECT hotel_code FROM grnconnect_hotels WHERE hotel_code IN ({$in})");
            $find->execute($chunk);
            foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $found) {
                $existingFp[(string)$found] = true;
            }
        }

        $toInsert = [];
        $skipped = 0;
        foreach ($incoming as $code => $row) {
            if (isset($existingFp[$code])) {
                $skipped++;
                continue;
            }
            $toInsert[] = $row;
        }

        $imported = 0;
        $updated = 0;
        $failed = 0;
        $insertOne = $pdo->prepare('
            INSERT INTO grnconnect_hotels
                (hotel_code, name, address, city_code, country_code, latitude, longitude, category, acc_name, chain_name, postal_code, description, facilities, images)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $bindInsert = static function (array $row) {
            return [
                $row['code'], $row['name'], $row['address'], $row['city'], $row['country'],
                $row['lat'], $row['lng'], $row['cat'], $row['acc'], $row['chain'], $row['postal'],
                $row['descr'], $row['fac'], $row['images'],
            ];
        };

        foreach (array_chunk($toInsert, 80) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = 'INSERT INTO grnconnect_hotels
                (hotel_code, name, address, city_code, country_code, latitude, longitude, category, acc_name, chain_name, postal_code, description, facilities, images)
                VALUES ' . $place;
            $vals = [];
            foreach ($chunk as $row) {
                foreach ($bindInsert($row) as $v) {
                    $vals[] = $v;
                }
            }
            try {
                $pdo->prepare($sql)->execute($vals);
                $imported += count($chunk);
            } catch (\Throwable $e) {
                foreach ($chunk as $row) {
                    try {
                        $insertOne->execute($bindInsert($row));
                        $imported++;
                    } catch (\Throwable $rowEx) {
                        $failed++;
                        error_log('GRNConnect hotel import skipped ' . $row['code'] . ': ' . $rowEx->getMessage());
                    }
                }
            }
        }

        $imagesImported = 0;
        $imagesSkipped = 0;
        $imagesEmpty = 0;
        if (!$skipImages && $client !== null && function_exists('grnconnect_import_hotel_images')) {
            foreach ($toInsert as $row) {
                try {
                    $imgResult = grnconnect_import_hotel_images($client, $pdo, $row['code'], ['source' => 'import']);
                    if (!empty($imgResult['skipped'])) {
                        $imagesSkipped++;
                    } elseif (!empty($imgResult['empty'])) {
                        $imagesEmpty++;
                    } else {
                        $imagesImported += (int)($imgResult['saved'] ?? 0);
                    }
                } catch (\Throwable $imgEx) {
                    error_log('GRNConnect image import failed for ' . $row['code'] . ': ' . $imgEx->getMessage());
                }
            }
        }

        return [
            'imported'        => $imported,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'failed'          => $failed,
            'images_imported' => $imagesImported,
            'images_skipped'  => $imagesSkipped,
            'images_empty'    => $imagesEmpty,
        ];
    }
}

if (!function_exists('grnconnect_is_transport_error')) {
    /** True when the GRN fetch failed at HTTP/cURL layer and the city should be retried. */
    function grnconnect_is_transport_error(array $res)
    {
        if (!empty($res['curl_errno'])) {
            return true;
        }
        $err = strtolower((string)($res['error'] ?? ''));
        if ($err === '') {
            return false;
        }
        return strpos($err, 'connection error') !== false
            || strpos($err, 'timed out') !== false
            || strpos($err, 'timeout') !== false
            || strpos($err, 'empty response') !== false
            || strpos($err, 'http 0') !== false
            || strpos($err, 'ssl read') !== false
            || strpos($err, 'errno 54') !== false;
    }
}

if (!function_exists('grnconnect_import_job_dir')) {
    require_once __DIR__ . '/redis-job.php';

    function grnconnect_import_job_dir()
    {
        $root = dirname(__DIR__, 4);
        $candidates = [
            $root . '/app/cache/grnconnect-import',
            dirname(__DIR__) . '/logs',
            rtrim(sys_get_temp_dir(), '/') . '/grnconnect-import',
        ];
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
                @chmod($dir, 0777);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }
        return $candidates[0];
    }
}

if (!function_exists('grnconnect_import_job_path')) {
    function grnconnect_import_job_path()
    {
        return grnconnect_import_job_dir() . '/import-all-job.json';
    }
}

if (!function_exists('grnconnect_import_job_read')) {
    function grnconnect_import_job_read()
    {
        $path = grnconnect_import_job_path();
        $defaults = [
            'status'          => 'idle',
            'pid'             => 0,
            'stop'            => false,
            'total_cities'    => 0,
            'pending_cities'  => 0,
            'processed'       => 0,
            'imported'        => 0,
            'updated'         => 0,
            'skipped'         => 0,
            'error_count'     => 0,
            'last_error'      => '',
            'last_city'       => '',
            'started_at'      => '',
            'updated_at'      => '',
            'message'         => '',
            'token'           => '',
            'driver'          => 'queue',
            'pass_skip'       => [],
            'heartbeat_at'    => '',
            'remap'           => false,
            'queue_pending'   => 0,
        ];
        $redisRaw = function_exists('grnconnect_redis_get') ? grnconnect_redis_get('grnconnect:import:job') : null;
        if (is_string($redisRaw) && $redisRaw !== '') {
            $data = json_decode($redisRaw, true);
            if (is_array($data)) {
                $data['driver'] = 'redis';
                return array_merge($defaults, $data);
            }
        }
        if (!is_file($path)) {
            return $defaults;
        }
        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return $defaults;
        }
        return array_merge($defaults, $data);
    }
}

if (!function_exists('grnconnect_import_job_write')) {
    function grnconnect_import_job_write(array $job)
    {
        $job['updated_at'] = date('Y-m-d H:i:s');
        $json = json_encode($job, JSON_UNESCAPED_SLASHES);
        if (function_exists('grnconnect_redis_set')) {
            $ok = grnconnect_redis_set('grnconnect:import:job', $json, 604800);
            if ($ok) {
                $job['driver'] = 'redis';
            }
        }
        $path = grnconnect_import_job_path();
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, $json);
        @chmod($tmp, 0666);
        @rename($tmp, $path);
    }
}

if (!function_exists('grnconnect_import_try_lock')) {
    /**
     * Exclusive file lock so only ONE import worker runs on CloudPanel.
     * flock is free (no Redis). Released automatically if PHP dies.
     */
    function grnconnect_import_try_lock()
    {
        if (!empty($GLOBALS['__grn_import_lock_fp']) && is_resource($GLOBALS['__grn_import_lock_fp'])) {
            $GLOBALS['__grn_import_lock_depth'] = (int)($GLOBALS['__grn_import_lock_depth'] ?? 1) + 1;
            return true;
        }
        $path = grnconnect_import_job_dir() . '/import-all.lock';
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return false;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return false;
        }
        @ftruncate($fp, 0);
        @fwrite($fp, getmypid() . ' ' . date('c') . "\n");
        @fflush($fp);
        $GLOBALS['__grn_import_lock_fp'] = $fp;
        $GLOBALS['__grn_import_lock_depth'] = 1;
        return true;
    }
}

if (!function_exists('grnconnect_import_release_lock')) {
    function grnconnect_import_release_lock()
    {
        $depth = (int)($GLOBALS['__grn_import_lock_depth'] ?? 1);
        if ($depth > 1) {
            $GLOBALS['__grn_import_lock_depth'] = $depth - 1;
            return;
        }
        $GLOBALS['__grn_import_lock_depth'] = 0;
        if (!empty($GLOBALS['__grn_import_lock_fp']) && is_resource($GLOBALS['__grn_import_lock_fp'])) {
            @flock($GLOBALS['__grn_import_lock_fp'], LOCK_UN);
            @fclose($GLOBALS['__grn_import_lock_fp']);
        }
        unset($GLOBALS['__grn_import_lock_fp']);
    }
}

if (!function_exists('grnconnect_import_heartbeat')) {
    function grnconnect_import_heartbeat(array $job, $message = null)
    {
        $job['status'] = 'running';
        $job['heartbeat_at'] = date('Y-m-d H:i:s');
        if ($message !== null && $message !== '') {
            $job['message'] = $message;
        }
        grnconnect_import_job_write($job);
        return $job;
    }
}

if (!function_exists('grnconnect_import_pid_alive')) {
    function grnconnect_import_pid_alive($pid)
    {
        $pid = (int)$pid;
        if ($pid < 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = [];
        @exec('ps -p ' . $pid . ' -o pid=', $out);
        return !empty(array_filter($out, static function ($line) use ($pid) {
            return trim((string)$line) === (string)$pid;
        }));
    }
}

if (!function_exists('grnconnect_import_job_live')) {
    /** Job file plus whether a worker process is actually running. */
    function grnconnect_import_job_live()
    {
        $job = grnconnect_import_job_read();
        $alivePid = !empty($job['pid']) && grnconnect_import_pid_alive($job['pid']);
        $hbSrc = (string)(($job['heartbeat_at'] ?? '') !== '' ? $job['heartbeat_at'] : ($job['updated_at'] ?? ''));
        $updatedTs = $hbSrc !== '' ? strtotime($hbSrc) : 0;
        // Queue jobs have pid 0; a city fetch can take 90s+.
        $fresh = $updatedTs && (time() - $updatedTs) < 300;
        $running = ($job['status'] ?? '') === 'running' && empty($job['stop']);
        $job['alive'] = $running && ($alivePid || $fresh || $updatedTs === 0);
        $job['redis'] = (string)($job['driver'] ?? '') === 'redis';
        return $job;
    }
}

if (!function_exists('grnconnect_import_progress_message')) {
    function grnconnect_import_progress_message(array $job, $totalCities = null, $pendingLeft = null)
    {
        $totalCities = $totalCities !== null ? (int)$totalCities : (int)($job['total_cities'] ?? 0);
        $pendingLeft = $pendingLeft !== null ? (int)$pendingLeft : (int)($job['pending_cities'] ?? 0);
        $processed = (int)($job['processed'] ?? 0);
        if ($totalCities > 0) {
            $processed = max($processed, $totalCities - $pendingLeft);
        }
        $new = (int)($job['imported'] ?? 0);
        $upd = (int)($job['updated'] ?? 0);
        $skip = (int)($job['skipped'] ?? 0);
        $err = (int)($job['error_count'] ?? 0);
        $city = trim((string)($job['last_city'] ?? ''));

        $bits = [];
        if ($totalCities > 0) {
            $bits[] = 'Cities imported ' . $processed . '/' . $totalCities;
            $bits[] = $pendingLeft . ' remaining';
        } else {
            $bits[] = 'Cities imported ' . $processed;
        }
        $bits[] = $new . ' hotels new';
        $bits[] = $skip . ' skipped (already in catalog)';
        if ($upd > 0) {
            $bits[] = $upd . ' updated';
        }
        if ($err > 0) {
            $bits[] = $err . ' errors skipped';
        }
        if ($city !== '') {
            $bits[] = 'current city ' . $city;
        }

        $line = implode(' · ', $bits);
        if ($pendingLeft < 1 && $totalCities > 0 && empty($job['stop'])) {
            return 'Done. ' . $line . '.';
        }
        if (!empty($job['stop'])) {
            return 'Stop requested. This city will finish, then import pauses. ' . $line . '.';
        }
        return $line . '.';
    }
}

if (!function_exists('grnconnect_import_stats_fast')) {
    /** Exact row counts for the Import tab (33k hotels is a cheap COUNT). */
    function grnconnect_import_stats_fast($module, array $job)
    {
        $stats = [
            'db_exists'      => false,
            'database'       => (string)($module['database'] ?? ''),
            'countries'      => 0,
            'cities'         => 0,
            'hotels'         => 0,
            'images'         => 0,
            'pending_cities' => (int)($job['pending_cities'] ?? 0),
        ];
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
                    $stats[$k] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                } catch (\Throwable $e) { /* table missing */ }
            }
            try {
                $stats['pending_cities'] = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities WHERE hotels_imported = 0')->fetchColumn();
            } catch (\Throwable $e) {
                $stats['pending_cities'] = (int)$stats['cities'];
            }
        } catch (\Throwable $e) {
            // Content DB not created yet.
        }
        return $stats;
    }
}

if (!function_exists('grnconnect_import_apply_remap')) {
    /** If a previous pass marked cities done, walk them again to insert hotels that were never saved. */
    function grnconnect_import_apply_remap($pdo, array $job)
    {
        if (empty($job['remap'])) {
            return $job;
        }
        try {
            $pdo->exec('UPDATE grnconnect_cities SET hotels_imported = 0');
            $job['total_cities'] = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities')->fetchColumn();
            $job['pending_cities'] = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities WHERE hotels_imported = 0')->fetchColumn();
            $job['processed'] = 0;
        } catch (\Throwable $e) {
            $job['last_error'] = 'Remap failed: ' . $e->getMessage();
        }
        $job['remap'] = false;
        grnconnect_import_job_write($job);
        return $job;
    }
}

if (!function_exists('grnconnect_import_ensure_jobs_table')) {
    function grnconnect_import_ensure_jobs_table($pdo)
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS grnconnect_import_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                city_code VARCHAR(32) NOT NULL,
                country_code VARCHAR(4) DEFAULT '',
                status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                hotels_new INT NOT NULL DEFAULT 0,
                hotels_updated INT NOT NULL DEFAULT 0,
                hotels_skipped INT NOT NULL DEFAULT 0,
                last_error TEXT,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_city (city_code),
                INDEX idx_status (status),
                INDEX idx_status_attempts (status, attempts, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('grnconnect_import_recover_stale_jobs')) {
    function grnconnect_import_recover_stale_jobs($pdo)
    {
        try {
            $pdo->exec("
                UPDATE grnconnect_import_jobs
                SET status = 'pending', started_at = NULL
                WHERE status = 'processing'
                  AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
            ");
        } catch (\Throwable $e) { /* table may not exist yet */ }
    }
}

if (!function_exists('grnconnect_import_enqueue_cities')) {
    /**
     * Queue one job per city that still needs hotels. No long-running process.
     */
    function grnconnect_import_enqueue_cities($pdo, $remap = false)
    {
        grnconnect_import_ensure_jobs_table($pdo);
        if ($remap) {
            try {
                $pdo->exec('UPDATE grnconnect_cities SET hotels_imported = 0');
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $pdo->exec('TRUNCATE TABLE grnconnect_import_jobs');
            } catch (\Throwable $e) { /* ignore */ }
        }
        grnconnect_import_recover_stale_jobs($pdo);
        $pdo->exec("
            INSERT IGNORE INTO grnconnect_import_jobs (city_code, country_code, status)
            SELECT city_code, IFNULL(country_code, ''), 'pending'
            FROM grnconnect_cities
            WHERE hotels_imported = 0
        ");
        $pdo->exec("
            UPDATE grnconnect_import_jobs j
            INNER JOIN grnconnect_cities c ON c.city_code = j.city_code AND c.hotels_imported = 0
            SET j.status = 'pending', j.started_at = NULL, j.finished_at = NULL
            WHERE j.status IN ('done', 'failed')
        ");
        return (int)$pdo->query("SELECT COUNT(*) FROM grnconnect_import_jobs WHERE status = 'pending'")->fetchColumn();
    }
}

if (!function_exists('grnconnect_cities_matching_name')) {
    /**
     * City rows for a place name (Dubai, Dubai Marina, Dubai, AE, …).
     * Includes cities that have zero local hotels so missing catalogs can still import.
     *
     * @return array<int, array{city_code:string,country_code:string,name:string}>
     */
    function grnconnect_cities_matching_name($pdo, $cityName)
    {
        $needle = trim((string)$cityName);
        if ($needle === '') {
            return [];
        }
        if (function_exists('grnconnect_clean_city_name')) {
            $needle = grnconnect_clean_city_name($needle);
        }
        $likeComma = $needle . ',%';
        $likeSpace = $needle . ' %';
        $likeParen = $needle . ' (%';
        $likeDash  = $needle . '-%';
        $stmt = $pdo->prepare(
            'SELECT city_code, IFNULL(country_code, \'\') AS country_code, IFNULL(name, \'\') AS name
             FROM grnconnect_cities
             WHERE name = ? OR search_name = ?
                OR name LIKE ? OR search_name LIKE ?
                OR name LIKE ? OR search_name LIKE ?
                OR name LIKE ? OR search_name LIKE ?
                OR name LIKE ? OR search_name LIKE ?
             ORDER BY CASE WHEN name = ? THEN 0 WHEN search_name = ? THEN 1 ELSE 2 END, name'
        );
        $stmt->execute([
            $needle, $needle,
            $likeComma, $likeComma,
            $likeSpace, $likeSpace,
            $likeParen, $likeParen,
            $likeDash, $likeDash,
            $needle, $needle,
        ]);
        $rows = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = trim((string)($row['city_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $rows[] = [
                'city_code'    => $code,
                'country_code' => (string)($row['country_code'] ?? ''),
                'name'         => (string)($row['name'] ?? ''),
            ];
        }
        return $rows;
    }
}

if (!function_exists('grnconnect_import_missing_hotels_for_cities')) {
    /**
     * Download GRN hotel lists for the given cities in parallel waves.
     * Existing hotel_codes are skipped; images are not fetched.
     *
     * @param callable|null $onProgress function(array $info): void
     */
    function grnconnect_import_missing_hotels_for_cities($pdo, $client, array $cities, $onProgress = null)
    {
        $stats = [
            'cities'      => 0,
            'grn_hotels'  => 0,
            'imported'    => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'city_errors' => 0,
        ];
        $list = [];
        foreach ($cities as $city) {
            if (is_string($city)) {
                $city = ['city_code' => $city, 'country_code' => ''];
            }
            $code = trim((string)($city['city_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $list[] = [
                'city_code'    => $code,
                'country_code' => (string)($city['country_code'] ?? ''),
            ];
        }
        $stats['cities'] = count($list);
        if (!$list) {
            return $stats;
        }

        $mark = $pdo->prepare('UPDATE grnconnect_cities SET hotels_imported = 1 WHERE city_code = :code');
        $done = 0;
        foreach (array_chunk($list, 4) as $wave) {
            $codes = array_column($wave, 'city_code');
            $results = method_exists($client, 'hotelsByCityParallel')
                ? $client->hotelsByCityParallel($codes, 90)
                : [];
            foreach ($wave as $i => $city) {
                $code = $city['city_code'];
                $country = $city['country_code'];
                $res = $results[$i] ?? ['success' => false, 'error' => 'no result', 'data' => null];
                if (empty($results) && method_exists($client, 'hotelsByCity')) {
                    $res = $client->hotelsByCity($code, 90);
                }
                $done++;
                if (is_callable($onProgress)) {
                    $onProgress([
                        'message' => 'Fetching GRN hotels ' . $done . '/' . $stats['cities']
                            . ' (' . $code . '). Existing catalog rows are skipped.',
                        'done'    => $done,
                        'total'   => $stats['cities'],
                    ]);
                }
                if (empty($res['success'])) {
                    $stats['city_errors']++;
                    continue;
                }
                $hotels = is_array($res['data']['hotels'] ?? null) ? $res['data']['hotels'] : [];
                $stats['grn_hotels'] += count($hotels);
                $result = grnconnect_upsert_hotels(
                    $pdo,
                    $hotels,
                    $code,
                    $country,
                    $client,
                    true,
                    true
                );
                $stats['imported'] += (int)($result['imported'] ?? 0);
                $stats['skipped'] += (int)($result['skipped'] ?? 0);
                $stats['failed'] += (int)($result['failed'] ?? 0);
                try {
                    $mark->execute([':code' => $code]);
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        return $stats;
    }
}

if (!function_exists('grnconnect_import_enqueue_city_codes')) {
    /**
     * Re-queue specific cities so hotelsByCity runs again.
     * Existing hotel_codes stay; only missing codes are inserted.
     */
    function grnconnect_import_enqueue_city_codes($pdo, array $codes)
    {
        $codes = array_values(array_unique(array_filter(array_map(static function ($c) {
            return trim((string)$c);
        }, $codes))));
        if (!$codes) {
            return 0;
        }
        grnconnect_import_ensure_jobs_table($pdo);
        $mark = $pdo->prepare('UPDATE grnconnect_cities SET hotels_imported = 0 WHERE city_code = ?');
        $ins = $pdo->prepare("
            INSERT INTO grnconnect_import_jobs (city_code, country_code, status, attempts)
            SELECT city_code, IFNULL(country_code, ''), 'pending', 0
            FROM grnconnect_cities WHERE city_code = ?
            ON DUPLICATE KEY UPDATE
                status = 'pending',
                attempts = 0,
                started_at = NULL,
                finished_at = NULL,
                last_error = NULL
        ");
        foreach ($codes as $code) {
            $mark->execute([$code]);
            $ins->execute([$code]);
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM grnconnect_import_jobs WHERE status = 'pending'")->fetchColumn();
    }
}

if (!function_exists('grnconnect_import_queue_pending')) {
    function grnconnect_import_queue_pending($pdo)
    {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM grnconnect_import_jobs WHERE status = 'pending'")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('grnconnect_import_claim_job')) {
    function grnconnect_import_claim_job($pdo)
    {
        $stmt = $pdo->query("
            SELECT id, city_code, country_code, attempts
            FROM grnconnect_import_jobs
            WHERE status = 'pending'
            ORDER BY attempts ASC, id ASC
            LIMIT 1
        ");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return null;
        }
        $upd = $pdo->prepare("
            UPDATE grnconnect_import_jobs
            SET status = 'processing', attempts = attempts + 1, started_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $upd->execute([':id' => $row['id']]);
        if ($upd->rowCount() < 1) {
            return null;
        }
        $row['attempts'] = (int)$row['attempts'] + 1;
        return $row;
    }
}

if (!function_exists('grnconnect_import_finish_job')) {
    function grnconnect_import_finish_job($pdo, $id, $status, $imported = 0, $updated = 0, $skipped = 0, $error = '')
    {
        $allowed = ['pending', 'done', 'failed'];
        if (!in_array($status, $allowed, true)) {
            $status = 'failed';
        }
        $stmt = $pdo->prepare("
            UPDATE grnconnect_import_jobs
            SET status = :status,
                hotels_new = :n,
                hotels_updated = :u,
                hotels_skipped = :s,
                last_error = :e,
                started_at = IF(:status2 = 'pending', NULL, started_at),
                finished_at = IF(:status3 IN ('done','failed'), NOW(), NULL)
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'  => $status,
            ':status2' => $status,
            ':status3' => $status,
            ':n'       => (int)$imported,
            ':u'       => (int)$updated,
            ':s'       => (int)$skipped,
            ':e'       => (string)$error,
            ':id'      => (int)$id,
        ]);
    }
}

if (!function_exists('grnconnect_php_binary')) {
    function grnconnect_php_binary()
    {
        $candidates = [
            PHP_BINARY,
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
            '/Applications/XAMPP/xamppfiles/bin/php',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        foreach (glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: [] as $p) {
            $candidates[] = $p;
        }
        foreach ($candidates as $bin) {
            if (!is_string($bin) || $bin === '' || $bin === 'php') {
                continue;
            }
            $base = strtolower(basename($bin));
            if (strpos($base, 'cgi') !== false || strpos($base, 'fpm') !== false || strpos($base, 'lsphp') !== false) {
                continue;
            }
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        return 'php';
    }
}

if (!function_exists('grnconnect_exec_enabled')) {
    function grnconnect_exec_enabled()
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array('exec', $list, true);
    }
}

if (!function_exists('grnconnect_spawn_import_worker')) {
    /** @deprecated Import All uses the job queue, not a CLI process. */
    function grnconnect_spawn_import_worker()
    {
        return 0;
    }
}

if (!function_exists('grnconnect_import_self_url')) {
    function grnconnect_import_self_url()
    {
        $path = '/modules/stays/grnconnect/import';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $appUrl = '';
        if (defined('root') && is_string(root) && root !== '') {
            $appUrl = (string)root;
        }
        if ($appUrl === '') {
            $envFile = dirname(__DIR__, 4) . '/.env';
            if (is_file($envFile)) {
                $env = @parse_ini_file($envFile);
                $appUrl = (string)($env['APP_URL'] ?? '');
            }
        }
        if ($appUrl !== '') {
            $u = parse_url($appUrl);
            if (!empty($u['path']) && $u['path'] !== '/') {
                $path = rtrim($u['path'], '/') . $path;
            }
            if ($host === '' && !empty($u['host'])) {
                $host = (string)$u['host'];
            }
        }
        return ['host' => preg_replace('/:\d+$/', '', $host), 'path' => $path];
    }
}

if (!function_exists('grnconnect_fire_import_continue')) {
    /**
     * Dispatch the next queued city job as its own HTTP request (not a CLI process).
     */
    function grnconnect_fire_import_continue($token)
    {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }
        $post = http_build_query([
            'action' => 'import_hotels_all_continue',
            'token'  => $token,
        ]);
        $info = grnconnect_import_self_url();
        $host = $info['host'];
        $path = $info['path'];
        if ($host === '') {
            return false;
        }

        if (grnconnect_exec_enabled()) {
            $cmd = 'curl -sS -k --connect-timeout 2 --max-time 180'
                . ' -H ' . escapeshellarg('Host: ' . $host)
                . ' -X POST ' . escapeshellarg('http://127.0.0.1' . $path)
                . ' -d ' . escapeshellarg($post)
                . ' >/dev/null 2>&1 &';
            @exec($cmd);
            return true;
        }

        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $fp = @stream_socket_client('tcp://127.0.0.1:80', $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $fp = @stream_socket_client('ssl://127.0.0.1:443', $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
        }
        if (!$fp) {
            return false;
        }
        $out = "POST {$path} HTTP/1.1\r\n";
        $out .= "Host: {$host}\r\n";
        $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out .= "Content-Length: " . strlen($post) . "\r\n";
        $out .= "Connection: Close\r\n\r\n";
        $out .= $post;
        stream_set_timeout($fp, 1);
        @stream_set_blocking($fp, false);
        @fwrite($fp, $out);
        @fclose($fp);
        return true;
    }
}

if (!function_exists('grnconnect_send_and_detach')) {
    /** Return JSON to the browser, then keep running (CloudPanel PHP-FPM). */
    function grnconnect_send_and_detach(array $data)
    {
        @ignore_user_abort(true);
        while (ob_get_level()) {
            ob_end_clean();
        }
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        header('X-Accel-Buffering: no');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            @flush();
        }
        @set_time_limit(0);
    }
}

if (!function_exists('grnconnect_set_import_progress')) {
    function grnconnect_set_import_progress($pdo, $task, $status, $total, $processed, $message = '')
    {
        if (!$pdo) {
            return;
        }
        $allowed = ['idle', 'running', 'completed', 'error'];
        if (!in_array($status, $allowed, true)) {
            $status = 'idle';
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO grnconnect_import_progress (task, total, processed, status, message)
                VALUES (:task, :total, :processed, :status, :message)
                ON DUPLICATE KEY UPDATE total=:total2, processed=:processed2, status=:status2, message=:message2
            ");
            $stmt->execute([
                ':task' => $task, ':total' => $total, ':processed' => $processed, ':status' => $status, ':message' => $message,
                ':total2' => $total, ':processed2' => $processed, ':status2' => $status, ':message2' => $message,
            ]);
        } catch (\Throwable $e) { /* progress table optional */ }
    }
}

if (!function_exists('grnconnect_import_all_batch')) {
    /**
     * Process one batch of pending cities. Safe for CLI or short HTTP requests.
     * Errors are recorded and never abort the job.
     *
     * @return array{done:bool,stopped:bool,pending:int,processed:int,imported:int}
     */
    function grnconnect_import_all_batch($db, $limit = 4, $httpMode = false)
    {
        require_once dirname(__DIR__) . '/api.php';
        $job = grnconnect_import_job_read();
        if (!empty($job['stop'])) {
            $job['status'] = 'idle';
            $job['pid'] = 0;
            $job['message'] = grnconnect_import_progress_message($job);
            grnconnect_import_job_write($job);
            return ['done' => false, 'stopped' => true, 'pending' => (int)$job['pending_cities'], 'processed' => (int)$job['processed'], 'imported' => (int)$job['imported']];
        }

        $module = grnconnect_module($db);
        if (!$module) {
            $job['status'] = 'idle';
            $job['last_error'] = 'GRNConnect module not configured';
            $job['message'] = $job['last_error'];
            grnconnect_import_job_write($job);
            return ['done' => false, 'stopped' => true, 'pending' => 0, 'processed' => 0, 'imported' => 0];
        }

        $pdo = grnconnect_create_schema($module);
        if (!grnconnect_import_try_lock()) {
            return [
                'done' => false, 'stopped' => false, 'busy' => true,
                'pending' => (int)$job['pending_cities'], 'processed' => (int)$job['processed'],
                'imported' => (int)$job['imported'],
            ];
        }

        try {
        $job = grnconnect_import_heartbeat($job);
        $job['pid'] = 0;
        $job['driver'] = 'queue';
        grnconnect_import_ensure_jobs_table($pdo);
        if (!empty($job['remap'])) {
            grnconnect_import_enqueue_cities($pdo, true);
            $job['remap'] = false;
        }
        grnconnect_import_recover_stale_jobs($pdo);
        $client = GRNConnectClient::fromModule($module, [
            'request_timeout' => $httpMode ? 45 : 90,
            'logging'         => false,
        ]);
        if (!$client->hasCredentials()) {
            $job['status'] = 'idle';
            $job['last_error'] = 'GRNConnect API key / base URL not configured';
            $job['message'] = $job['last_error'];
            grnconnect_import_job_write($job);
            return ['done' => false, 'stopped' => true, 'pending' => 0, 'processed' => 0, 'imported' => 0];
        }

        $totalCities = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities')->fetchColumn();
        $pendingCities = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities WHERE hotels_imported = 0')->fetchColumn();
        $queuePending = grnconnect_import_queue_pending($pdo);
        $job['total_cities'] = $totalCities;
        $job['pending_cities'] = $pendingCities;
        $job['queue_pending'] = $queuePending;
        $job['processed'] = max(0, $totalCities - $pendingCities);
        $job['status'] = 'running';

        if ($totalCities < 1) {
            $job['status'] = 'idle';
            $job['message'] = 'Import countries and cities first, then import all hotels.';
            grnconnect_import_job_write($job);
            return ['done' => false, 'stopped' => true, 'pending' => 0, 'processed' => 0, 'imported' => 0];
        }
        if ($pendingCities < 1 || $queuePending < 1) {
            $job['status'] = 'completed';
            $job['pid'] = 0;
            $job['queue_pending'] = 0;
            $job['pending_cities'] = 0;
            $job['message'] = grnconnect_import_progress_message($job, $totalCities, 0);
            grnconnect_import_job_write($job);
            grnconnect_set_import_progress($pdo, 'hotels_all', 'completed', $totalCities, $totalCities, $job['message']);
            return ['done' => true, 'stopped' => false, 'pending' => 0, 'processed' => $totalCities, 'imported' => (int)$job['imported']];
        }

        $claimed = grnconnect_import_claim_job($pdo);
        if (!$claimed) {
            $job['message'] = 'No queued city ready; waiting for the next job.';
            $job['queue_pending'] = grnconnect_import_queue_pending($pdo);
            grnconnect_import_job_write($job);
            return ['done' => false, 'stopped' => false, 'pending' => $pendingCities, 'processed' => (int)$job['processed'], 'imported' => (int)$job['imported']];
        }

        $cities = [[
            'city_code'    => $claimed['city_code'],
            'country_code' => $claimed['country_code'],
        ]];
        $claimedId = (int)$claimed['id'];
        $claimedAttempts = (int)$claimed['attempts'];

        $markCity = $pdo->prepare('UPDATE grnconnect_cities SET hotels_imported = 1 WHERE city_code = :code');
        $fetchTimeout = $httpMode ? 45 : 90;
        $maxTransport = $httpMode ? 2 : 6;
        $jobRowImported = 0;
        $jobRowUpdated = 0;
        $jobRowSkipped = 0;
        $jobRowError = '';

        foreach ($cities as $city) {
            $disk = grnconnect_import_job_read();
            if (!empty($disk['stop'])) {
                grnconnect_import_finish_job($pdo, $claimedId, 'pending');
                $job['stop'] = true;
                $job['status'] = 'idle';
                $job['pid'] = 0;
                $job['message'] = grnconnect_import_progress_message($job);
                grnconnect_import_job_write($job);
                return ['done' => false, 'stopped' => true, 'pending' => (int)$job['pending_cities'], 'processed' => (int)$job['processed'], 'imported' => (int)$job['imported']];
            }
            $code = trim((string)($city['city_code'] ?? ''));
            if ($code === '') {
                grnconnect_import_finish_job($pdo, $claimedId, 'failed', 0, 0, 0, 'Empty city code');
                continue;
            }
            $job['last_city'] = $code;
            $job = grnconnect_import_heartbeat($job, grnconnect_import_progress_message($job));
            $keepProcessing = $pdo->prepare("UPDATE grnconnect_import_jobs SET status = 'processing', started_at = IFNULL(started_at, NOW()) WHERE id = :id");
            $keepProcessing->execute([':id' => $claimedId]);
            $res = ['success' => false, 'error' => 'fetch failed'];
            $transportTries = 0;
            while ($transportTries < $maxTransport) {
                try {
                    $res = $client->hotelsByCity($code, $fetchTimeout);
                } catch (\Throwable $e) {
                    $res = ['success' => false, 'error' => $e->getMessage(), 'curl_errno' => 1];
                }
                if (!empty($res['success']) || !grnconnect_is_transport_error($res)) {
                    break;
                }
                $transportTries++;
                $job['last_error'] = $code . ': ' . ($res['error'] ?? 'connection error');
                $job['message'] = 'Retrying city ' . $code . ' (' . $transportTries . '/' . $maxTransport . '). '
                    . grnconnect_import_progress_message($job);
                grnconnect_import_job_write($job);
                if ($httpMode) {
                    usleep(400000);
                } else {
                    sleep(min(12, $transportTries * 2));
                }
            }

            if (empty($res['success'])) {
                $job['error_count'] = (int)$job['error_count'] + 1;
                $job['last_error'] = $code . ': ' . ($res['error'] ?? 'fetch failed');
                $jobRowError = $job['last_error'];
                if (grnconnect_is_transport_error($res)) {
                    $retryStatus = $claimedAttempts >= 6 ? 'failed' : 'pending';
                    if ($retryStatus === 'failed') {
                        try {
                            $markCity->execute([':code' => $code]);
                        } catch (\Throwable $e) { /* keep going */ }
                    }
                    grnconnect_import_finish_job($pdo, $claimedId, $retryStatus, 0, 0, 0, $jobRowError);
                    continue;
                }
                try {
                    $markCity->execute([':code' => $code]);
                } catch (\Throwable $e) { /* keep going */ }
                grnconnect_import_finish_job($pdo, $claimedId, 'failed', 0, 0, 0, $jobRowError);
                continue;
            }

            try {
                $result = grnconnect_upsert_hotels(
                    $pdo,
                    $res['data']['hotels'] ?? [],
                    $code,
                    (string)($city['country_code'] ?? ''),
                    $client,
                    true,
                    true
                );
                $jobRowImported = (int)($result['imported'] ?? 0);
                $jobRowUpdated = (int)($result['updated'] ?? 0);
                $jobRowSkipped = (int)($result['skipped'] ?? 0);
                $job['imported'] = (int)$job['imported'] + $jobRowImported;
                $job['updated'] = (int)($job['updated'] ?? 0) + $jobRowUpdated;
                $job['skipped'] = (int)($job['skipped'] ?? 0) + $jobRowSkipped;
                $failedRows = (int)($result['failed'] ?? 0);
                if ($failedRows > 0) {
                    $job['error_count'] = (int)$job['error_count'] + $failedRows;
                    $job['last_error'] = $code . ': ' . $failedRows . ' hotel rows failed (skipped)';
                    $jobRowError = $job['last_error'];
                }
            } catch (\Throwable $cityEx) {
                $job['error_count'] = (int)$job['error_count'] + 1;
                $job['last_error'] = $code . ': ' . $cityEx->getMessage();
                $jobRowError = $job['last_error'];
            }
            try {
                $markCity->execute([':code' => $code]);
            } catch (\Throwable $e) { /* keep going */ }
            grnconnect_import_finish_job($pdo, $claimedId, 'done', $jobRowImported, $jobRowUpdated, $jobRowSkipped, $jobRowError);
        }

        try {
            $pendingLeft = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities WHERE hotels_imported = 0')->fetchColumn();
        } catch (\Throwable $e) {
            $pendingLeft = (int)$job['pending_cities'];
        }
        $processed = max(0, $totalCities - $pendingLeft);
        $job['pass_skip'] = [];
        $job['pending_cities'] = $pendingLeft;
        $job['queue_pending'] = grnconnect_import_queue_pending($pdo);
        $job['processed'] = $processed;
        $job['status'] = $pendingLeft < 1 ? 'completed' : 'running';
        $job['pid'] = 0;
        $job['driver'] = 'queue';
        $job['message'] = grnconnect_import_progress_message($job, $totalCities, $pendingLeft);
        grnconnect_import_job_write($job);
        grnconnect_set_import_progress($pdo, 'hotels_all', $job['status'] === 'completed' ? 'completed' : 'running', $totalCities, $processed, $job['message']);

        return [
            'done'      => $pendingLeft < 1,
            'stopped'   => false,
            'pending'   => $pendingLeft,
            'processed' => $processed,
            'imported'  => (int)$job['imported'],
        ];
        } finally {
            grnconnect_import_release_lock();
        }
    }
}

if (!function_exists('grnconnect_run_import_all_loop')) {
    /**
     * Optional cron drain: process a few queued city jobs then exit.
     * Import All Hotels does not spawn this as a server process.
     */
    function grnconnect_run_import_all_loop($db)
    {
        @set_time_limit(120);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '1024M');
        $max = 3;
        for ($i = 0; $i < $max; $i++) {
            $job = grnconnect_import_job_read();
            if (!empty($job['stop']) || ($job['status'] ?? '') !== 'running') {
                return;
            }
            $result = grnconnect_import_all_batch($db, 1, false);
            if (!empty($result['done']) || !empty($result['stopped']) || !empty($result['busy'])) {
                return;
            }
        }
    }
}

if (!function_exists('grnconnect_insert_cities_batch')) {
    /**
     * Insert cities in chunks. Existing city_code rows keep country_code,
     * dest_code, and hotels_imported — only name (and search_name derived
     * from it) is refreshed.
     *
     * @return array{0:int,1:int} [inserted, updated]
     */
    function grnconnect_insert_cities_batch($pdo, array $cities, $fallbackCountry = '')
    {
        $inserted = 0;
        $updated = 0;
        $clean = function_exists('grnconnect_clean_city_name')
            ? 'grnconnect_clean_city_name'
            : static function ($n) {
                return trim((string)$n);
            };
        foreach (array_chunk($cities, 200) as $chunk) {
            $place = [];
            $params = [];
            $codes = [];
            foreach ($chunk as $c) {
                if (empty($c['code'])) {
                    continue;
                }
                $code = (string)$c['code'];
                $name = (string)($c['name'] ?? '');
                $place[] = '(?,?,?,?,?)';
                $params[] = $code;
                $params[] = $name;
                $params[] = $clean($name);
                $params[] = (string)($c['country'] ?? ($fallbackCountry ?: ''));
                $params[] = (string)($c['dest_code'] ?? '');
                $codes[] = $code;
            }
            if (empty($place)) {
                continue;
            }
            $existed = 0;
            try {
                $ph = implode(',', array_fill(0, count($codes), '?'));
                $existStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM grnconnect_cities WHERE city_code IN ({$ph})"
                );
                $existStmt->execute($codes);
                $existed = (int)$existStmt->fetchColumn();
            } catch (\Throwable $e) {
                $existed = 0;
            }
            $sql = 'INSERT INTO grnconnect_cities (city_code, name, search_name, country_code, dest_code) VALUES '
                . implode(',', $place)
                . ' ON DUPLICATE KEY UPDATE name = VALUES(name), search_name = VALUES(search_name)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $n = count($place);
            $inserted += max(0, $n - $existed);
            $updated += $existed;
        }
        return [$inserted, $updated];
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
                $job = grnconnect_import_job_live();
                if (
                    ($job['status'] ?? '') === 'running'
                    && empty($job['stop'])
                    && !empty($job['token'])
                    && ($job['driver'] ?? '') !== 'fast'
                ) {
                    $hbSrc = (string)(($job['heartbeat_at'] ?? '') !== '' ? $job['heartbeat_at'] : ($job['updated_at'] ?? ''));
                    $hb = $hbSrc !== '' ? strtotime($hbSrc) : 0;
                    if ($hb && (time() - $hb) > 180) {
                        grnconnect_fire_import_continue((string)$job['token']);
                    }
                }
                $stats = grnconnect_import_stats_fast($module, $job);
                $send(['success' => true, 'stats' => $stats, 'database' => $module['database'] ?? '', 'job' => $job]);
            }

            // ------------------------------------------------------------------
            // STOP BACKGROUND IMPORT
            // ------------------------------------------------------------------
            if ($action === 'import_hotels_all_stop') {
                $job = grnconnect_import_job_read();
                $job['stop'] = true;
                $job['token'] = '';
                $job['status'] = 'idle';
                $job['pid'] = 0;
                $job['message'] = grnconnect_import_progress_message($job);
                grnconnect_import_job_write($job);
                $send([
                    'success' => true,
                    'message' => $job['message'],
                    'job'     => grnconnect_import_job_live(),
                ]);
            }

            // ------------------------------------------------------------------
            // IMPORT ALL HOTELS — enqueue city jobs, then dispatch the first job
            // ------------------------------------------------------------------
            if ($action === 'import_hotels_all') {
                @ignore_user_abort(true);
                @set_time_limit(180);

                $job = grnconnect_import_job_read();
                $job['status'] = 'running';
                $job['stop'] = false;
                $job['pid'] = 0;
                $job['remap'] = false;
                $job['token'] = bin2hex(random_bytes(16));
                $job['started_at'] = date('Y-m-d H:i:s');
                $job['heartbeat_at'] = date('Y-m-d H:i:s');
                $job['driver'] = 'queue';
                $job['last_error'] = '';
                $job['message'] = 'Queueing city jobs…';
                grnconnect_import_job_write($job);

                grnconnect_send_and_detach([
                    'success' => true,
                    'running' => true,
                    'job'     => grnconnect_import_job_live(),
                    'message' => 'Queueing city jobs. Refresh phpMyAdmin in a few seconds.',
                ]);

                $queuePending = 0;
                $totalCities = 0;
                $pendingCities = 0;
                try {
                    $pdo = grnconnect_raw_pdo($module, true);
                    grnconnect_import_ensure_jobs_table($pdo);
                    $totalCities = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities')->fetchColumn();
                    $pendingCities = (int)$pdo->query('SELECT COUNT(*) FROM grnconnect_cities WHERE hotels_imported = 0')->fetchColumn();
                    $remap = ($totalCities > 0 && $pendingCities < 1);
                    $queuePending = grnconnect_import_enqueue_cities($pdo, $remap);
                    if ($remap) {
                        $pendingCities = $totalCities;
                    }
                    $job = grnconnect_import_job_read();
                    $job['status'] = 'running';
                    $job['stop'] = false;
                    $job['total_cities'] = $totalCities;
                    $job['pending_cities'] = $pendingCities;
                    $job['queue_pending'] = $queuePending;
                    $job['imported'] = 0;
                    $job['updated'] = 0;
                    $job['skipped'] = 0;
                    $job['error_count'] = 0;
                    $job['driver'] = 'queue';
                    $job['heartbeat_at'] = date('Y-m-d H:i:s');
                    $job['message'] = 'Queued ' . $queuePending . ' city jobs (one job per city, not per hotel).';
                    grnconnect_import_job_write($job);
                    grnconnect_fire_import_continue((string)$job['token']);
                } catch (\Throwable $e) {
                    $job = grnconnect_import_job_read();
                    $job['status'] = 'idle';
                    $job['last_error'] = $e->getMessage();
                    $job['message'] = 'Could not queue cities: ' . $e->getMessage();
                    grnconnect_import_job_write($job);
                }
                exit;
            }

            // ------------------------------------------------------------------
            // FAST RE-IMPORT MISSING HOTELS FOR A CITY NAME (e.g. Dubai)
            // Parallel hotelsByCity, skip existing codes, skip images.
            // ------------------------------------------------------------------
            if ($action === 'import_hotels_refresh') {
                $cityName = trim((string)($_POST['city_name'] ?? $_POST['city'] ?? ''));
                $cityName = preg_replace('/\s+/u', ' ', $cityName);
                if ($cityName === '') {
                    $send(['success' => false, 'error' => 'Enter a city name, e.g. Dubai']);
                }

                @ignore_user_abort(true);
                @set_time_limit(0);

                $job = grnconnect_import_job_read();
                $job['status'] = 'running';
                $job['stop'] = false;
                $job['pid'] = 0;
                $job['remap'] = false;
                $job['token'] = bin2hex(random_bytes(16));
                $job['started_at'] = date('Y-m-d H:i:s');
                $job['heartbeat_at'] = date('Y-m-d H:i:s');
                $job['driver'] = 'fast';
                $job['last_error'] = '';
                $job['imported'] = 0;
                $job['updated'] = 0;
                $job['skipped'] = 0;
                $job['error_count'] = 0;
                $job['message'] = 'Finding GRN cities for ' . $cityName . '…';
                grnconnect_import_job_write($job);

                grnconnect_send_and_detach([
                    'success' => true,
                    'running' => true,
                    'job'     => grnconnect_import_job_live(),
                    'message' => 'Finding missing hotels for ' . $cityName . '. Existing rows stay.',
                ]);

                try {
                    $pdo = grnconnect_raw_pdo($module, true);
                    $client = GRNConnectClient::fromModule($module, ['request_timeout' => 90]);
                    $cities = grnconnect_cities_matching_name($pdo, $cityName);
                    if (empty($cities)) {
                        $job = grnconnect_import_job_read();
                        $job['status'] = 'idle';
                        $job['message'] = 'No GRN cities found for "' . $cityName . '". Import Cities first (country AE), then retry.';
                        $job['last_error'] = $job['message'];
                        grnconnect_import_job_write($job);
                        exit;
                    }

                    $job = grnconnect_import_job_read();
                    $job['total_cities'] = count($cities);
                    $job['pending_cities'] = count($cities);
                    $job['message'] = 'Fetching GRN hotel lists for ' . count($cities) . ' ' . $cityName . ' cities (parallel). Existing hotels are skipped.';
                    $job['heartbeat_at'] = date('Y-m-d H:i:s');
                    grnconnect_import_job_write($job);

                    $stats = grnconnect_import_missing_hotels_for_cities(
                        $pdo,
                        $client,
                        $cities,
                        static function ($info) {
                            $job = grnconnect_import_job_read();
                            if (!empty($info['message'])) {
                                $job['message'] = $info['message'];
                            }
                            if (isset($info['done'], $info['total'])) {
                                $job['pending_cities'] = max(0, (int)$info['total'] - (int)$info['done']);
                            }
                            $job['heartbeat_at'] = date('Y-m-d H:i:s');
                            grnconnect_import_job_write($job);
                        }
                    );

                    $job = grnconnect_import_job_read();
                    $job['status'] = 'completed';
                    $job['stop'] = false;
                    $job['pending_cities'] = 0;
                    $job['imported'] = (int)$stats['imported'];
                    $job['skipped'] = (int)$stats['skipped'];
                    $job['error_count'] = (int)$stats['city_errors'] + (int)$stats['failed'];
                    $job['heartbeat_at'] = date('Y-m-d H:i:s');
                    $job['message'] = 'Done for ' . $cityName . ': GRN listed ' . (int)$stats['grn_hotels']
                        . ', already in catalog ' . (int)$stats['skipped']
                        . ', imported ' . (int)$stats['imported'] . ' new'
                        . ((int)$stats['city_errors'] > 0 ? (', ' . (int)$stats['city_errors'] . ' city fetches failed') : '')
                        . '.';
                    if ((int)$stats['imported'] === 0 && (int)$stats['skipped'] > 0) {
                        $job['message'] .= ' Catalog already had every GRN hotel for these cities.';
                    }
                    grnconnect_import_job_write($job);
                } catch (\Throwable $e) {
                    $job = grnconnect_import_job_read();
                    $job['status'] = 'idle';
                    $job['last_error'] = $e->getMessage();
                    $job['message'] = 'Could not import missing hotels: ' . $e->getMessage();
                    grnconnect_import_job_write($job);
                }
                exit;
            }

            // ------------------------------------------------------------------
            // RUN ONE QUEUED CITY JOB
            // ------------------------------------------------------------------
            if ($action === 'import_hotels_all_continue') {
                @set_time_limit(180);
                @ignore_user_abort(true);
                $job = grnconnect_import_job_read();
                $token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
                if ($token === '' || empty($job['token']) || !hash_equals((string)$job['token'], $token)) {
                    $send(['success' => false, 'error' => 'Invalid import token']);
                }
                if (($job['driver'] ?? '') === 'fast') {
                    $send(['success' => true, 'running' => ($job['status'] ?? '') === 'running', 'job' => $job]);
                }
                if (!empty($job['stop']) || ($job['status'] ?? '') !== 'running') {
                    if (!empty($job['stop'])) {
                        $job['status'] = 'idle';
                        $job['message'] = grnconnect_import_progress_message($job);
                        grnconnect_import_job_write($job);
                    }
                    $send(['success' => true, 'stopped' => true, 'job' => $job]);
                }
                $result = ['done' => false, 'busy' => false, 'stopped' => false];
                try {
                    $result = grnconnect_import_all_batch($db, 1, true);
                } catch (\Throwable $e) {
                    $job = grnconnect_import_job_read();
                    $job['error_count'] = (int)$job['error_count'] + 1;
                    $job['last_error'] = $e->getMessage();
                    $job['message'] = 'Job error (continuing): ' . $e->getMessage();
                    grnconnect_import_job_write($job);
                }
                $disk = grnconnect_import_job_read();
                if (
                    empty($disk['stop'])
                    && ($disk['status'] ?? '') === 'running'
                    && (int)($disk['pending_cities'] ?? 0) > 0
                    && empty($result['done'])
                    && empty($result['stopped'])
                    && empty($result['busy'])
                ) {
                    grnconnect_fire_import_continue($token);
                }
                $send([
                    'success' => true,
                    'running' => ($disk['status'] ?? '') === 'running',
                    'done'    => !empty($result['done']),
                    'job'     => grnconnect_import_job_live(),
                    'message' => $disk['message'] ?? 'Queued city job finished.',
                ]);
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
            // Cities/countries must not ALTER the huge hotels table first.
            $pdo = grnconnect_create_schema(
                $module,
                !in_array($action, ['import_cities', 'import_countries'], true)
            );

            $setProgress = function ($task, $status, $total, $processed, $message = '') use ($pdo) {
                grnconnect_set_import_progress($pdo, $task, $status, $total, $processed, $message);
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
                    INSERT IGNORE INTO grnconnect_countries (code, code3, name) VALUES (:code, :code3, :name)
                ");
                $count = 0;
                $skipped = 0;
                foreach ($countries as $c) {
                    if (empty($c['code'])) continue;
                    $stmt->execute([
                        ':code' => $c['code'], ':code3' => $c['code3'] ?? '', ':name' => $c['name'] ?? '',
                    ]);
                    if ($stmt->rowCount() > 0) {
                        $count++;
                    } else {
                        $skipped++;
                    }
                }
                $setProgress('countries', 'completed', $count + $skipped, $count);
                $send(['success' => true, 'imported' => $count, 'skipped' => $skipped, 'message' => "Imported {$count} new countries, skipped {$skipped} already imported."]);
            }

            // ------------------------------------------------------------------
            // IMPORT CITIES (all, or for a country)
            // ------------------------------------------------------------------
            if ($action === 'import_cities') {
                @set_time_limit(300);
                $country = trim($_POST['country'] ?? '');
                $res = $client->cities($country);
                if (!$res['success']) {
                    $setProgress('cities', 'error', 0, 0, $res['error']);
                    $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch cities']);
                }
                $cities = $res['data']['cities'] ?? [];
                $total  = count($cities);
                $setProgress('cities', 'running', $total, 0);

                $count = 0;
                $updated = 0;
                if (function_exists('grnconnect_insert_cities_batch')) {
                    list($count, $updated) = grnconnect_insert_cities_batch($pdo, $cities, $country);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO grnconnect_cities (city_code, name, search_name, country_code, dest_code)
                        VALUES (:code, :name, :search, :country, :dest)
                        ON DUPLICATE KEY UPDATE name = VALUES(name), search_name = VALUES(search_name)
                    ");
                    foreach ($cities as $c) {
                        if (empty($c['code'])) continue;
                        $name   = $c['name'] ?? '';
                        $search = grnconnect_clean_city_name($name);
                        $cc     = $c['country'] ?? ($country ?: '');
                        $existed = false;
                        try {
                            $chk = $pdo->prepare('SELECT 1 FROM grnconnect_cities WHERE city_code = ? LIMIT 1');
                            $chk->execute([(string)$c['code']]);
                            $existed = (bool)$chk->fetchColumn();
                        } catch (\Throwable $e) {
                            $existed = false;
                        }
                        $stmt->execute([
                            ':code' => $c['code'], ':name' => $name, ':search' => $search, ':country' => $cc, ':dest' => $c['dest_code'] ?? '',
                        ]);
                        if ($existed) {
                            $updated++;
                        } else {
                            $count++;
                        }
                    }
                }
                $setProgress('cities', 'completed', $total, $count);
                $send([
                    'success' => true,
                    'imported' => $count,
                    'updated' => $updated,
                    'skipped' => $updated,
                    'message' => "Imported {$count} new cities, updated name on {$updated} existing.",
                ]);
            }

            // ------------------------------------------------------------------
            // CHECK PASTED HOTEL CODES AGAINST THE CATALOG (no GRN call)
            // ------------------------------------------------------------------
            if ($action === 'check_hotel_codes') {
                $codes = grnconnect_parse_hotel_codes($_POST['codes'] ?? $_POST['hotel_codes'] ?? '');
                if (!$codes) {
                    $send(['success' => false, 'error' => 'Paste one or more hotel codes.']);
                }
                $look = grnconnect_lookup_hotel_codes_in_db($pdo, $codes);
                $presentRows = array_values($look['present']);
                $send([
                    'success'       => true,
                    'listed'        => count($codes),
                    'present_count' => count($presentRows),
                    'missing_count' => count($look['missing']),
                    'present'       => $presentRows,
                    'missing'       => $look['missing'],
                    'message'       => count($look['missing'])
                        ? (count($look['missing']) . ' of ' . count($codes) . ' codes are not in the catalog.')
                        : ('All ' . count($codes) . ' codes are already in the catalog.'),
                ]);
            }

            // ------------------------------------------------------------------
            // IMPORT MISSING HOTELS BY CODE (static hcode only; no rate search)
            // ------------------------------------------------------------------
            if ($action === 'import_hotel_codes') {
                $codes = grnconnect_parse_hotel_codes($_POST['codes'] ?? $_POST['hotel_codes'] ?? '');
                if (!$codes) {
                    $send(['success' => false, 'error' => 'Paste one or more hotel codes.']);
                }
                if (count($codes) > 500) {
                    $send(['success' => false, 'error' => 'Paste at most 500 hotel codes at a time.']);
                }

                try {
                    $pdo->exec("DELETE FROM grnconnect_hotels WHERE name = CONCAT('GRN ', hotel_code)");
                } catch (\Throwable $e) { /* ignore */ }

                $look = grnconnect_lookup_hotel_codes_in_db($pdo, $codes);
                $missing = $look['missing'];
                if (!$missing) {
                    $send([
                        'success'       => true,
                        'imported'      => 0,
                        'skipped'       => count($look['present']),
                        'failed'        => 0,
                        'missing_count' => 0,
                        'message'       => 'All ' . count($codes) . ' codes are already in the catalog. Nothing to fetch.',
                    ]);
                }

                $static = grnconnect_fetch_hotels_via_static_hcodes($client, $missing);
                $fetched = is_array($static['hotels'] ?? null) ? $static['hotels'] : [];
                $got = [];
                foreach ($fetched as $h) {
                    $code = (string)($h['hotel_code'] ?? $h['code'] ?? '');
                    if ($code !== '') {
                        $got[$code] = true;
                    }
                }
                $fetchFailed = [];
                foreach ($missing as $code) {
                    if (empty($got[$code])) {
                        $fetchFailed[] = $code;
                    }
                }

                $result = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
                if ($fetched) {
                    $result = grnconnect_upsert_hotels($pdo, $fetched, '', '', $client, true, true);
                }
                $imported = (int)($result['imported'] ?? 0);
                $skipped = (int)($result['skipped'] ?? 0) + count($look['present']);
                $failed = (int)($result['failed'] ?? 0);
                $grnErrors = is_array($static['errors'] ?? null) ? $static['errors'] : [];
                $send([
                    'success'        => true,
                    'listed'         => count($codes),
                    'missing_count' => count($missing),
                    'imported'       => $imported,
                    'skipped'        => $skipped + count($fetchFailed),
                    'failed'         => $failed,
                    'fetch_failed'   => $fetchFailed,
                    'message'        => "Imported {$imported} hotels GRN returned. Skipped "
                        . (count($look['present']) + count($fetchFailed))
                        . ' (already in catalog or GRN has no static record).'
                        . ($fetchFailed ? ' Skipped codes: ' . implode(', ', $fetchFailed) . '.' : '')
                        . ($grnErrors ? ' GRN: ' . implode('; ', $grnErrors) : ''),
                ]);
            }

            // ------------------------------------------------------------------
            // IMPORT HOTELS (city = live availability; country = static list)
            // ------------------------------------------------------------------
            if ($action === 'import_hotels') {
                $cityCode = trim($_POST['city'] ?? $_POST['city_code'] ?? '');
                $country  = trim($_POST['country'] ?? '');

                if ($cityCode === '' && $country === '') {
                    $send(['success' => false, 'error' => 'Provide a city code or country code to import hotels.']);
                }

                $hotels = [];
                $listed = 0;
                $probeIn = '';
                $probeOut = '';

                if ($cityCode !== '') {
                    $cityMeta = grnconnect_city_dest_meta($pdo, $cityCode);
                    $destCode = $cityMeta['dest_code'] !== '' ? $cityMeta['dest_code'] : $cityCode;
                    if ($country === '') {
                        $country = $cityMeta['country_code'];
                    }

                    $setProgress('hotels', 'running', 0, 0, 'Live availability for city ' . $cityCode . '…');

                    $live = grnconnect_fetch_hotels_via_availability($client, [], $cityCode, $destCode);
                    $probeIn = (string)($live['checkin'] ?? '');
                    $probeOut = (string)($live['checkout'] ?? '');

                    if (empty($live['success']) || (int)($live['listed'] ?? 0) === 0) {
                        $codes = grnconnect_hotel_codes_for_city($pdo, $cityCode);
                        if (!$codes) {
                            $res = $client->hotelsByCity($cityCode);
                            if (!$res['success']) {
                                $setProgress('hotels', 'error', 0, 0, $res['error']);
                                $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch hotel IDs for live availability']);
                            }
                            $codes = grnconnect_hotel_codes_from_list($res['data']['hotels'] ?? []);
                        }
                        $look = grnconnect_lookup_hotel_codes_in_db($pdo, $codes);
                        $probe = $look['missing'] ? $look['missing'] : $codes;
                        if (!$probe) {
                            $setProgress('hotels', 'completed', 0, 0, 'No hotel codes to send to live availability.');
                            $send([
                                'success' => true,
                                'imported' => 0,
                                'updated' => 0,
                                'skipped' => 0,
                                'listed' => 0,
                                'message' => 'No hotel codes for this city. Import Cities first, or paste hotel codes.',
                            ]);
                        }
                        $setProgress('hotels', 'running', count($probe), 0, 'Live availability for ' . count($probe) . ' hotel codes…');
                        $live = grnconnect_fetch_hotels_via_availability($client, $probe, $cityCode, '');
                        $probeIn = (string)($live['checkin'] ?? $probeIn);
                        $probeOut = (string)($live['checkout'] ?? $probeOut);
                    }

                    if (empty($live['success']) && empty($live['hotels'])) {
                        $setProgress('hotels', 'error', 0, 0, $live['error'] ?? 'Live availability failed');
                        $send(['success' => false, 'error' => $live['error'] ?: 'Live availability failed']);
                    }

                    $hotels = is_array($live['hotels'] ?? null) ? $live['hotels'] : [];
                    $listed = (int)($live['listed'] ?? count($hotels));
                } else {
                    $res = $client->hotelsByCountry($country);
                    if (!$res['success']) {
                        $setProgress('hotels', 'error', 0, 0, $res['error']);
                        $send(['success' => false, 'error' => $res['error'] ?: 'Failed to fetch hotels']);
                    }
                    $hotels = $res['data']['hotels'] ?? [];
                    $listed = count($hotels);
                }

                $total = count($hotels);
                $setProgress('hotels', 'running', $total, 0);

                $result = grnconnect_upsert_hotels($pdo, $hotels, $cityCode, $country, $client, true, true);
                $count = (int)$result['imported'];
                $updated = (int)($result['updated'] ?? 0);
                $skipped = (int)($result['skipped'] ?? 0);
                $imagesImported = (int)$result['images_imported'];
                $imagesSkipped  = (int)$result['images_skipped'];
                $imagesEmpty    = (int)$result['images_empty'];

                if ($cityCode !== '') {
                    try {
                        $mark = $pdo->prepare('UPDATE grnconnect_cities SET hotels_imported = 1 WHERE city_code = :code');
                        $mark->execute([':code' => $cityCode]);
                    } catch (\Throwable $e) { /* column may not exist yet */ }
                }

                $datesNote = ($cityCode !== '' && $probeIn !== '' && $probeOut !== '')
                    ? " Probe {$probeIn}–{$probeOut} (2 adults, 1 room)."
                    : '';
                $setProgress('hotels', 'completed', $total, $count, "Live listed {$listed}; {$count} new, {$skipped} already in catalog.");
                $send([
                    'success'         => true,
                    'imported'        => $count,
                    'updated'         => $updated,
                    'skipped'         => $skipped,
                    'listed'          => $listed,
                    'images_imported' => $imagesImported,
                    'images_skipped'  => $imagesSkipped,
                    'images_empty'    => $imagesEmpty,
                    'message'         => "Live availability listed {$listed} hotels with rates. Imported {$count} new, skipped {$skipped} already in catalog."
                        . $datesNote,
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
                    'grnconnect_import_jobs',
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
