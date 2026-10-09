<?php
// ============================================================================
// WIDGETY CRUISE & TOURS API V3 — CLIENT HELPERS
// ----------------------------------------------------------------------------
// CONTENT PROVIDER (SHIPS, OPERATORS, PORTS, CRUISE & TOUR HOLIDAYS). NOT A
// RESERVATION ENGINE — BOOKINGS ARE CONFIRMED IN-PLATFORM (SEE payment-gateway).
//
// CONFIRMED CONTRACT (verified live with the trial key + Widgety support):
//   BASE : https://www.widgety.co.uk/api   (the www host — confirmed by support)
//   AUTH : QUERY PARAMS ?app_id=<c1>&token=<c2>   (HEADER AUTH IS REJECTED 401)
//   LIST : holidays.json?market=us&page=N  → { holidays:[{holiday_ref,name,
//          operator,operator_title,holiday}], _links:{next,last}, count, total }
//          25 per page; `market` MUST be the lowercase ISO-2 code ("us").
//   ITEM : holidays/<holiday_ref>.json → full detail incl. operating_seasons[]
//          .dates[] (sailings: dates, ship, ports, cabin-grade pricing).
//   NOTE : USE THE V3 ("Holidays") ENDPOINT — V2 (`cruises`) RETURNS BAD GATEWAY.
//          No IP whitelisting is required (credentials are the access).
//
// CREDENTIALS ON THE `modules` ROW (name='widgety', type='cruises'):
//   c1 = APP ID
//   c2 = TOKEN
//   c3 = BASE URL          (OPTIONAL — DEFAULTS TO https://www.widgety.co.uk/api)
//   c4 = MARKET            (OPTIONAL — ISO-2 e.g. "us"; "USA"/"UK" are normalised)
//   c5 = OPERATOR FILTER   (OPTIONAL — CSV OF WIDGETY OPERATOR IDS/SLUGS TO LIMIT IMPORT)
//   host/database/username/password = THE MODULE'S OWN CONTENT DB
//
// Docs: https://developer.widgety.co.uk  |  support@widgety.co.uk
// ============================================================================

if (!defined('WIDGETY_DEFAULT_BASE')) {
    define('WIDGETY_DEFAULT_BASE', 'https://www.widgety.co.uk/api');
}
// V3 HOLIDAYS ENDPOINT PATH. HELD AS A CONSTANT SO IT IS TRIVIAL TO CONFIRM /
// CORRECT AGAINST THE DEVELOPER SITE WITHOUT TOUCHING CALL SITES.
if (!defined('WIDGETY_EP_HOLIDAYS')) {
    define('WIDGETY_EP_HOLIDAYS', 'holidays');
}
if (!defined('WIDGETY_EP_OPERATORS')) {
    define('WIDGETY_EP_OPERATORS', 'operators');
}
if (!defined('WIDGETY_EP_SHIPS')) {
    define('WIDGETY_EP_SHIPS', 'ships');
}
if (!defined('WIDGETY_EP_PORTS')) {
    define('WIDGETY_EP_PORTS', 'ports');
}

if (!function_exists('_widgety_base_url')) {
    /**
     * RESOLVE THE API BASE URL. Widgety serves the API on the www host; older
     * configs saved the bare domain, so that is rewritten.
     */
    function _widgety_base_url(string $raw): string
    {
        $base = rtrim(trim($raw) ?: WIDGETY_DEFAULT_BASE, '/');
        return (string) preg_replace('#^https?://widgety\.co\.uk#i', 'https://www.widgety.co.uk', $base);
    }
}

if (!function_exists('_widgety_market')) {
    /**
     * NORMALISE A MARKET TO WIDGETY'S LOWERCASE ISO-2 CODE. The V3 holidays
     * endpoint 404s on "USA"/"US" — it only accepts e.g. "us".
     */
    function _widgety_market(string $market): string
    {
        $m = strtolower(trim($market));
        $alias = ['usa' => 'us', 'uk' => 'gb', 'gbr' => 'gb', 'aus' => 'au', 'can' => 'ca', 'deu' => 'de', 'ger' => 'de'];
        return $alias[$m] ?? ($m !== '' ? $m : 'us');
    }
}

if (!function_exists('_widgety_market_currency')) {
    /** PRICES ARE QUOTED IN THE MARKET'S CURRENCY (Widgety sends no currency field). */
    function _widgety_market_currency(string $market, string $fallback = 'USD'): string
    {
        $map = ['us' => 'USD', 'gb' => 'GBP', 'au' => 'AUD', 'ca' => 'CAD', 'nz' => 'NZD', 'de' => 'EUR', 'eu' => 'EUR'];
        return $map[$market] ?? $fallback;
    }
}

if (!function_exists('_widgety_cfg')) {
    /** LOAD THE WIDGETY MODULE ROW + RESOLVE BASE URL / MARKET / FILTERS. */
    function _widgety_cfg($db): array
    {
        $m = $db->get('modules', '*', ['name' => 'widgety', 'type' => 'cruises']);
        if (!$m) {
            throw new Exception('Widgety module is not registered');
        }
        $base = _widgety_base_url((string) ($m['c3'] ?? ''));
        $market = _widgety_market((string) ($m['c4'] ?? ''));

        return [
            'module'   => $m,
            'app_id'   => trim((string) ($m['c1'] ?? '')),
            'token'    => trim((string) ($m['c2'] ?? '')),
            'base_url' => $base,
            'market'   => $market,
            'operators'=> array_values(array_filter(array_map('trim', explode(',', (string) ($m['c5'] ?? ''))))),
            'currency' => _widgety_market_currency($market, (string) ($m['currency'] ?? 'USD')),
            'dev_mode' => (string) ($m['dev_mode'] ?? '0') === '1',
            'logging'  => (int) ($m['logging_enabled'] ?? 0) === 1,
        ];
    }
}

if (!function_exists('_widgety_request')) {
    /**
     * PERFORM A WIDGETY API GET. AUTH IS ALWAYS QUERY-PARAM (app_id + token).
     * $path IS AN ENDPOINT NAME ('operators') OR A FULL ABSOLUTE URL (e.g. a
     * `_links.next.href` returned by the API, which already carries auth).
     * RETURNS ['http_code'=>int, 'json'=>array|null, 'raw'=>string, 'error'=>string].
     */
    function _widgety_request(array $cfg, string $path, array $query = []): array
    {
        if (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) {
            $url = $path; // ALREADY A FULLY-FORMED HAL LINK (CARRIES ITS OWN AUTH)
        } else {
            $params = array_merge(
                ['app_id' => $cfg['app_id'], 'token' => $cfg['token']],
                $query
            );
            $url = $cfg['base_url'] . '/' . ltrim($path, '/') . '.json?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '', // ACCEPT GZIP — WIDGETY PAYLOADS ARE LARGE
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: PHPTRAVELS-v10'],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $json = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        return ['http_code' => $code, 'json' => $json, 'raw' => (string) $raw, 'error' => $err];
    }
}

if (!function_exists('_widgety_error_message')) {
    /** FLATTEN A WIDGETY ERROR RESPONSE INTO ONE HUMAN-READABLE MESSAGE. */
    function _widgety_error_message(array $res, string $fallback = 'Widgety request failed.'): string
    {
        $j = $res['json'];
        if (is_array($j) && isset($j['error']['message']) && is_string($j['error']['message'])) {
            return $j['error']['message'];
        }
        if (is_array($j) && !empty($j['message']) && is_string($j['message'])) {
            return $j['message'];
        }
        if ($res['error'] !== '') {
            return $res['error'];
        }
        return $fallback . ' (HTTP ' . $res['http_code'] . ')';
    }
}

if (!function_exists('_widgety_collection')) {
    /**
     * EXTRACT THE ITEM ARRAY FROM A HAL RESPONSE. WIDGETY NESTS COLLECTIONS UNDER
     * `_embedded.<key>` (e.g. `_embedded.operators`). RETURNS [] WHEN ABSENT.
     * $preferKey LETS A CALLER NAME THE EXPECTED KEY; OTHERWISE THE FIRST
     * ARRAY-VALUED EMBEDDED ENTRY IS USED.
     */
    function _widgety_collection(?array $json, string $preferKey = ''): array
    {
        if (!is_array($json)) {
            return [];
        }
        $embedded = $json['_embedded'] ?? null;
        if (is_array($embedded)) {
            if ($preferKey !== '' && isset($embedded[$preferKey]) && is_array($embedded[$preferKey])) {
                return array_values($embedded[$preferKey]);
            }
            foreach ($embedded as $v) {
                if (is_array($v) && array_is_list($v)) {
                    return $v;
                }
            }
        }
        // SOME ENDPOINTS MAY RETURN A TOP-LEVEL LIST OR A NAMED TOP-LEVEL ARRAY.
        if (array_is_list($json)) {
            return $json;
        }
        if ($preferKey !== '' && isset($json[$preferKey]) && is_array($json[$preferKey])) {
            return array_values($json[$preferKey]);
        }
        // WIDGETY V3: the collection is a TOP-LEVEL key named after the resource
        // (e.g. "operators", "ports", "ships", "holidays") sitting alongside
        // `_links`, `count`, `total`. Return the first top-level list we find.
        foreach ($json as $k => $v) {
            if ($k === '_links' || $k === '_embedded') {
                continue;
            }
            if (is_array($v) && array_is_list($v) && $v !== []) {
                return $v;
            }
        }
        return [];
    }
}

if (!function_exists('_widgety_paginate')) {
    /**
     * WALK ALL PAGES OF A HAL COLLECTION VIA `_links.next.href`, BOUNDED BY
     * $maxPages FOR SAFETY. RETURNS ['items'=>array, 'pages'=>int, 'error'=>string].
     */
    function _widgety_paginate(array $cfg, string $path, array $query = [], string $collectionKey = '', int $maxPages = 200): array
    {
        $items = [];
        $pages = 0;
        $res   = _widgety_request($cfg, $path, $query);

        while (true) {
            if ($res['http_code'] !== 200 || !is_array($res['json'])) {
                return ['items' => $items, 'pages' => $pages, 'error' => _widgety_error_message($res)];
            }
            $pages++;
            foreach (_widgety_collection($res['json'], $collectionKey) as $row) {
                $items[] = $row;
            }
            $next = $res['json']['_links']['next']['href'] ?? '';
            if (!$next || $pages >= $maxPages) {
                break;
            }
            $res = _widgety_request($cfg, (string) $next); // NEXT LINK CARRIES ITS OWN AUTH
        }

        return ['items' => $items, 'pages' => $pages, 'error' => ''];
    }
}

if (!function_exists('_widgety_create_tables')) {
    /** IDEMPOTENTLY CREATE THE WIDGETY CONTENT TABLES ON THE MODULE DB CONNECTION. */
    function _widgety_create_tables($mdb): void
    {
        $mdb->query("CREATE TABLE IF NOT EXISTS `widgety_operators` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `widgety_id` varchar(64) NOT NULL,
            `name` varchar(255) DEFAULT NULL,
            `slug` varchar(255) DEFAULT NULL,
            `market` varchar(16) DEFAULT NULL,
            `logo` varchar(500) DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `widgety_id` (`widgety_id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `widgety_ships` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `widgety_id` varchar(64) NOT NULL,
            `operator_id` varchar(64) DEFAULT NULL,
            `name` varchar(255) DEFAULT NULL,
            `slug` varchar(255) DEFAULT NULL,
            `rating` varchar(16) DEFAULT NULL,
            `images` longtext DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `widgety_id` (`widgety_id`),
            KEY `operator_id` (`operator_id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `widgety_ports` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `widgety_id` varchar(64) NOT NULL,
            `name` varchar(255) DEFAULT NULL,
            `country` varchar(128) DEFAULT NULL,
            `region` varchar(128) DEFAULT NULL,
            `latitude` varchar(32) DEFAULT NULL,
            `longitude` varchar(32) DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `widgety_id` (`widgety_id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `widgety_cruises` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `widgety_id` varchar(64) NOT NULL,
            `operator_id` varchar(64) DEFAULT NULL,
            `ship_id` varchar(64) DEFAULT NULL,
            `name` varchar(500) DEFAULT NULL,
            `slug` varchar(255) DEFAULT NULL,
            `holiday_type` varchar(32) DEFAULT NULL,
            `nights` int(11) DEFAULT 0,
            `market` varchar(16) DEFAULT NULL,
            `description` longtext DEFAULT NULL,
            `highlights` longtext DEFAULT NULL,
            `whats_included` longtext DEFAULT NULL,
            `images` longtext DEFAULT NULL,
            `embark_port` varchar(255) DEFAULT NULL,
            `disembark_port` varchar(255) DEFAULT NULL,
            `itinerary` longtext DEFAULT NULL,
            `tags` longtext DEFAULT NULL,
            `lead_price` decimal(12,2) DEFAULT NULL,
            `lead_currency` varchar(8) DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `imported_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `widgety_id` (`widgety_id`),
            KEY `operator_id` (`operator_id`),
            KEY `ship_id` (`ship_id`),
            KEY `market` (`market`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `widgety_departures` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `widgety_id` varchar(64) NOT NULL,
            `cruise_id` varchar(64) NOT NULL,
            `depart_date` date DEFAULT NULL,
            `return_date` date DEFAULT NULL,
            `currency` varchar(8) DEFAULT NULL,
            `lead_price` decimal(12,2) DEFAULT NULL,
            `pricing` longtext DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `widgety_id` (`widgety_id`),
            KEY `cruise_id` (`cruise_id`),
            KEY `depart_date` (`depart_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `sync_type` varchar(30) DEFAULT NULL,
            `items` int(11) DEFAULT 0,
            `pages` int(11) DEFAULT 0,
            `message` varchar(500) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Resumable-import cursor (e.g. next holidays page to fetch).
        $mdb->query("CREATE TABLE IF NOT EXISTS `sync_state` (
            `key` varchar(64) NOT NULL,
            `value` text DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Columns added once the V3 holiday detail shape was confirmed. Added on
        // existing installs too, so the listing can search without parsing `raw`.
        _widgety_ensure_columns($mdb, 'widgety_cruises', [
            'operator_name'    => 'varchar(255) DEFAULT NULL',
            'ship_name'        => 'varchar(255) DEFAULT NULL',
            'duration_days'    => 'int(11) DEFAULT 0',
            'rating'           => 'varchar(32) DEFAULT NULL',
            'regions'          => 'varchar(500) DEFAULT NULL',
            'countries'        => 'varchar(500) DEFAULT NULL',
            'first_departure'  => 'date DEFAULT NULL',
            'last_departure'   => 'date DEFAULT NULL',
            'departures_count' => 'int(11) DEFAULT 0',
            'detail_synced_at' => 'datetime DEFAULT NULL',
        ]);
        _widgety_ensure_columns($mdb, 'widgety_departures', [
            'ship_id'        => 'varchar(128) DEFAULT NULL',
            'ship_name'      => 'varchar(255) DEFAULT NULL',
            'availability'   => 'varchar(32) DEFAULT NULL',
            'embark_port'    => 'varchar(255) DEFAULT NULL',
            'disembark_port' => 'varchar(255) DEFAULT NULL',
        ]);
    }
}

if (!function_exists('_widgety_ensure_columns')) {
    /** ADD ANY MISSING COLUMNS TO AN EXISTING TABLE (idempotent). */
    function _widgety_ensure_columns($mdb, string $table, array $columns): void
    {
        $have = [];
        $rows = $mdb->query('SHOW COLUMNS FROM `' . $table . '`');
        foreach ($rows ? $rows->fetchAll(\PDO::FETCH_ASSOC) : [] as $r) {
            $have[strtolower((string) $r['Field'])] = true;
        }
        foreach ($columns as $name => $def) {
            if (!isset($have[strtolower($name)])) {
                $mdb->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $def);
            }
        }
    }
}

if (!function_exists('_widgety_state')) {
    /** READ (value=null) OR WRITE A SYNC-STATE VALUE. */
    function _widgety_state($mdb, string $key, ?string $value = null): ?string
    {
        if ($value === null) {
            $v = $mdb->get('sync_state', 'value', ['key' => $key]);
            return $v === false || $v === null ? null : (string) $v;
        }
        if ($mdb->has('sync_state', ['key' => $key])) {
            $mdb->update('sync_state', ['value' => $value], ['key' => $key]);
        } else {
            $mdb->insert('sync_state', ['key' => $key, 'value' => $value]);
        }
        return $value;
    }
}

if (!function_exists('_widgety_db')) {
    /**
     * WIDGETY CONTENT DATABASE CONNECTION.
     *
     * THE CONTENT TABLES LIVE IN THE MODULE'S OWN DATABASE — CONFIGURED ON THE
     * `modules` ROW (host / database / username / password), NOT THE MAIN v10 DB.
     * TABLES ARE AUTO-CREATED ON FIRST USE. THROWS IF NOT CONFIGURED.
     */
    function _widgety_db($db)
    {
        static $conn = null;
        if ($conn instanceof \Medoo\Medoo) {
            return $conn;
        }

        $m = $db->get('modules', ['host', 'database', 'username', 'password'],
            ['name' => 'widgety', 'type' => 'cruises']);
        if (!$m || empty($m['host']) || empty($m['database'])) {
            throw new Exception('Widgety: module database is not configured. '
                . 'Set Host / Database / Username / Password for the Widgety module '
                . 'under Settings → Modules and enable Import Database.');
        }

        $conn = new \Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $m['host'],
            'database'  => $m['database'],
            'username'  => $m['username'],
            'password'  => $m['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        _widgety_create_tables($conn);
        return $conn;
    }
}
