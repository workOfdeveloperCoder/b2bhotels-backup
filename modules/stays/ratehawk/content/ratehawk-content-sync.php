<?php
// ============================================================================
// RATEHAWK CONTENT API SYNC — engine, JSON endpoint, and admin UI in one file
// ============================================================================
// Replaces the manual 40GB db.jsonl bulk dump with the RateHawk Content API:
//
//   1. hotel_ids_by_filter  -> discover hids changed since last run (updated_since)
//   2. hotel_content_by_ids -> pull content in batches of 100, upsert hotels
//   3. hotel_reviews_by_ids -> pull reviews, compute guest rating
//
// Plus separate, less-frequent reference-data pulls (all b2b): hotel/static
// (amenity/bedding/meal/socket/tax labels), hotel/region/dump, hotel/poi/dump.
//
// This one file plays two roles depending on how it's reached:
//   - Included by app/views/admin/settings/modules-settings.php (Content Sync
//     tab) -> renders the admin UI below.
//   - Requested directly by that UI's own JS, e.g.
//     POST .../modules/stays/ratehawk/content/ratehawk-content-sync.php?action=run
//     -> serves JSON (actions: discover, sync_content, sync_reviews, static,
//        regions, pois, run, status, reset).
//
// Content API rules honoured here: never called alongside search/hotelpage,
// batches of <= 100 ids, QPM-friendly pacing, offline/admin use only.
// No cron — sync is triggered manually from the admin UI only, by design.
// ============================================================================

require_once __DIR__ . '/hotel-schema.php';

class RatehawkContentSync
{
    /** @var \Medoo\Medoo */
    private $db;
    private $keyId;
    private $apiKey;
    private $baseUrl;      // .../api/content/v1
    private $b2bUrl;       // .../api/b2b/v3  (static / region / poi dumps live here)
    private $language;
    private $isSandbox;
    private $logDir;
    private $logEnabled;

    const CONTENT_BATCH = 100;   // hard API limit
    const REVIEW_BATCH  = 100;   // hard API limit
    const MAX_ATTEMPTS  = 4;     // give up on a hid after this many failures

    // Sandbox only serves these country ids for hotel_ids_by_filter.
    const SANDBOX_COUNTRIES = [59, 153, 189, 201];

    public function __construct($db, array $creds)
    {
        $this->db        = $db;
        $this->keyId     = trim($creds['key_id'] ?? '');
        $this->apiKey    = trim($creds['api_key'] ?? '');
        $this->language  = $creds['language'] ?? 'en';
        $this->baseUrl   = rtrim($creds['base_url'] ?? '', '/');
        $this->b2bUrl    = preg_replace('#/api/content/v1/?$#', '/api/b2b/v3', $this->baseUrl);
        $this->isSandbox = (stripos($this->baseUrl, 'sandbox') !== false);
        $this->logDir    = $creds['log_dir'] ?? (__DIR__ . '/../logs');
        $this->logEnabled = !empty($creds['log_enabled']);

        if ($this->keyId === '' || $this->apiKey === '' || $this->baseUrl === '') {
            throw new \RuntimeException('RateHawk Content API credentials are not configured (key id / api key / base url).');
        }
    }

    /**
     * Derive the Content API base from the b2b base URL stored in `modules.c4`.
     * The Content API is served from the ratehawk.com host family, so a worldota.net
     * b2b host is translated (sandbox/prod prefix preserved).
     *   https://api-sandbox.worldota.net/api/b2b/v3 -> https://api-sandbox.ratehawk.com/api/content/v1
     */
    public static function contentBaseFromB2B($c4)
    {
        $c4 = trim((string)$c4);
        if ($c4 === '') {
            return 'https://api.ratehawk.com/api/content/v1';
        }
        $parts  = parse_url($c4);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? 'api.ratehawk.com';

        if (stripos($host, 'worldota.net') !== false) {
            $host = (stripos($host, 'sandbox') !== false)
                ? 'api-sandbox.ratehawk.com'
                : 'api.ratehawk.com';
        }

        return $scheme . '://' . $host . '/api/content/v1';
    }

    // ------------------------------------------------------------------ HTTP --

    /** Content API call (api/content/v1). */
    private function call($method, $endpoint, $body = null)
    {
        return $this->request($this->baseUrl . '/' . ltrim($endpoint, '/'), $method, $body, $endpoint);
    }

    /** B2B API call (api/b2b/v3) — used for hotel/static, region/dump, poi/dump. */
    private function callB2B($method, $endpoint, $body = null)
    {
        return $this->request($this->b2bUrl . '/' . ltrim($endpoint, '/'), $method, $body, $endpoint);
    }

    private function request($url, $method, $body, $logKey)
    {
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->apiKey),
                'User-Agent: PHPTravelsPartner/1.0 (php-curl)',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);

        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $this->writeLog($logKey, ['url' => $url, 'method' => $method, 'body' => $body], $raw === false ? $curlErr : $raw, $status);

        if ($raw === false) {
            throw new \RuntimeException("RateHawk API network error calling {$logKey}: {$curlErr}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("RateHawk API returned non-JSON from {$logKey} (HTTP {$status})");
        }
        if (($decoded['status'] ?? '') !== 'ok' || !empty($decoded['error'])) {
            $err = $decoded['error'] ?? ('HTTP ' . $status);
            $dbg = json_encode($decoded['debug']['validation_error'] ?? $decoded['debug'] ?? []);
            throw new \RuntimeException("RateHawk API error from {$logKey}: {$err} {$dbg}");
        }
        return $decoded['data'] ?? [];
    }

    /**
     * Write request/response JSON logs in the same format the rest of the
     * stays modules use (modules/helpers.php::logApiCall), into
     * modules/stays/ratehawk/logs/. Gated by modules.logging_enabled.
     */
    private function writeLog($endpoint, $payload, $response, $httpCode)
    {
        if (!$this->logEnabled) {
            return;
        }
        try {
            if (!function_exists('logApiCall')) {
                $helpers = __DIR__ . '/../../../helpers.php';
                if (is_file($helpers)) {
                    require_once $helpers;
                }
            }
            if (!function_exists('logApiCall')) {
                return;
            }

            $slug = trim(preg_replace('#[^a-z0-9]+#i', '_', trim($endpoint, '/')), '_');
            $labels = [
                'hotel_ids_by_filter'    => 'Ratehawk_ContentDiscover',
                'hotel_content_by_ids'   => 'Ratehawk_ContentHotels',
                'hotel_reviews_by_ids'   => 'Ratehawk_ContentReviews',
                'hotel_static'           => 'Ratehawk_ContentStatic',
                'hotel_region_dump'      => 'Ratehawk_ContentRegionDump',
                'hotel_poi_dump'         => 'Ratehawk_ContentPoiDump',
            ];
            $type = $labels[$slug] ?? ('Ratehawk_Content_' . $slug);

            $decoded = json_decode((string) $response, true);
            logApiCall('ratehawk/' . $slug, $payload, $decoded !== null ? $decoded : $response, $httpCode, $this->logDir, $type);
        } catch (\Throwable $e) {
            error_log('RateHawk Content API log write failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- schema --

    public function ensureTables()
    {
        // Hotel table + up-to-date columns (shared with the dump importer).
        try {
            $this->db->query(rh_hotel_table_ddl('IF NOT EXISTS ratehawk_hotels'));
        } catch (\Throwable $e) {
            error_log('RateHawk Content API: hotels table create: ' . $e->getMessage());
        }
        rh_ensure_hotel_columns($this->db);

        // Dropped: filter_values labels fully overlapped ratehawk_static (which also
        // has locales) and nothing else in the dict ever had a real consumer.
        $this->db->query("DROP TABLE IF EXISTS ratehawk_content_dict");

        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_content_queue (
            hid               BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            hotel_id          VARCHAR(100) NULL,
            needs_content     TINYINT(1) NOT NULL DEFAULT 1,
            needs_reviews     TINYINT(1) NOT NULL DEFAULT 1,
            attempts          INT NOT NULL DEFAULT 0,
            last_error        TEXT NULL,
            content_synced_at TIMESTAMP NULL,
            reviews_synced_at TIMESTAMP NULL,
            queued_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_needs_content (needs_content, attempts),
            INDEX idx_needs_reviews (needs_reviews, attempts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_reviews (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            hid            BIGINT UNSIGNED NOT NULL,
            hotel_id       VARCHAR(100) NULL,
            review_uid     VARCHAR(120) NOT NULL,
            rating         DECIMAL(4,2) NULL,
            review_plus    TEXT NULL,
            review_minus   TEXT NULL,
            author         VARCHAR(191) NULL,
            traveller_type VARCHAR(64) NULL,
            trip_type      VARCHAR(64) NULL,
            room_name      VARCHAR(255) NULL,
            adults         INT NULL,
            children       INT NULL,
            review_date    DATE NULL,
            nights         INT NULL,
            detailed       JSON NULL,
            images         JSON NULL,
            raw            JSON NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_review (hid, review_uid),
            INDEX idx_hid (hid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'trip_type' => "VARCHAR(64) NULL",
            'room_name'  => "VARCHAR(255) NULL",
            'adults'     => "INT NULL",
            'children'   => "INT NULL",
        ] as $col => $def) {
            try {
                if (!$this->db->query("SHOW COLUMNS FROM ratehawk_reviews LIKE '{$col}'")->fetch()) {
                    $this->db->query("ALTER TABLE ratehawk_reviews ADD COLUMN {$col} {$def}");
                }
            } catch (\Throwable $e) {
                error_log("RateHawk reviews: add column {$col}: " . $e->getMessage());
            }
        }

        // hotel/static dictionaries: beddings, meals, room_amenities, serp_filters,
        // socket_types, taxes, amenity_translations. `lkey` scopes amenity_translations
        // by property kind (APARTMENT / HOTEL / ...); empty for the simple lists.
        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_static (
            category   VARCHAR(32) NOT NULL,
            name       VARCHAR(96) NOT NULL,
            lkey       VARCHAR(32) NOT NULL DEFAULT '',
            label_en   VARCHAR(255) NULL,
            locales    JSON NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (category, name, lkey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // region/dump: one row per ETG region (city / region / airport / ...).
        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_regions (
            id            BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            type          VARCHAR(40) NULL,
            name_en       VARCHAR(255) NULL,
            names         JSON NULL,
            country_code  VARCHAR(10) NULL,
            country_name_en VARCHAR(255) NULL,
            country_names JSON NULL,
            iata          VARCHAR(10) NULL,
            latitude      DECIMAL(10,7) NULL,
            longitude     DECIMAL(10,7) NULL,
            hotel_count   INT NOT NULL DEFAULT 0,
            hids          JSON NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name_en),
            INDEX idx_country (country_code),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // poi/dump: nearby points of interest per hotel.
        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_pois (
            hid        BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            hotel_id   VARCHAR(100) NULL,
            pois       JSON NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_content_sync (
            id                 INT NOT NULL PRIMARY KEY,
            last_discover_at   TIMESTAMP NULL,
            last_updated_since  VARCHAR(32) NULL,
            last_static_at     TIMESTAMP NULL,
            last_regions_at    TIMESTAMP NULL,
            last_poi_at        TIMESTAMP NULL,
            discovered_total   INT NOT NULL DEFAULT 0,
            content_done       INT NOT NULL DEFAULT 0,
            reviews_done       INT NOT NULL DEFAULT 0,
            status             VARCHAR(20) NOT NULL DEFAULT 'idle',
            last_error         TEXT NULL,
            updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            if ($this->db->query("SHOW COLUMNS FROM ratehawk_content_sync LIKE 'last_dictionaries_at'")->fetch()) {
                $this->db->query("ALTER TABLE ratehawk_content_sync DROP COLUMN last_dictionaries_at");
            }
        } catch (\Throwable $e) {
            error_log('RateHawk sync-state: drop last_dictionaries_at: ' . $e->getMessage());
        }

        foreach (['last_static_at', 'last_regions_at', 'last_poi_at'] as $col) {
            try {
                if (!$this->db->query("SHOW COLUMNS FROM ratehawk_content_sync LIKE '{$col}'")->fetch()) {
                    $this->db->query("ALTER TABLE ratehawk_content_sync ADD COLUMN {$col} TIMESTAMP NULL");
                }
            } catch (\Throwable $e) {
                error_log("RateHawk sync-state: add column {$col}: " . $e->getMessage());
            }
        }

        // Admin knobs kept on the same single state row (id = 1):
        //  - auto_sync_enabled     : 1 = the admin panel may auto-refresh static
        //                            data once every 24h (there is no cron).
        //  - last_auto_run_at      : timestamp of the last auto refresh (24h guard).
        //  - price_change_threshold: max % the supplier net may rise at prebook
        //                            before the customer must be told (ETG 3.1).
        //  - booking_cutoff_minutes: how long we keep polling booking/finish/status
        //                            after a successful booking/finish response
        //                            before treating the order as failed (ETG 5.2).
        $extraCols = [
            'auto_sync_enabled'      => "TINYINT(1) NOT NULL DEFAULT 0",
            'last_auto_run_at'       => "TIMESTAMP NULL",
            'price_change_threshold' => "DECIMAL(5,2) NOT NULL DEFAULT 2.00",
            'booking_cutoff_minutes' => "SMALLINT NOT NULL DEFAULT 15",
        ];
        foreach ($extraCols as $col => $ddl) {
            try {
                if (!$this->db->query("SHOW COLUMNS FROM ratehawk_content_sync LIKE '{$col}'")->fetch()) {
                    $this->db->query("ALTER TABLE ratehawk_content_sync ADD COLUMN {$col} {$ddl}");
                }
            } catch (\Throwable $e) {
                error_log("RateHawk sync-state: add column {$col}: " . $e->getMessage());
            }
        }

        if (!$this->db->get('ratehawk_content_sync', 'id', ['id' => 1])) {
            $this->db->insert('ratehawk_content_sync', ['id' => 1, 'status' => 'idle']);
        }
    }

    private function state()
    {
        return $this->db->get('ratehawk_content_sync', '*', ['id' => 1]) ?: [];
    }

    private function setState(array $patch)
    {
        $this->db->update('ratehawk_content_sync', $patch, ['id' => 1]);
    }

    // ------------------------------------------------ 1. hotel/static decoder --

    /**
     * b2b GET hotel/static -> ratehawk_static. This is the decoder for the coded
     * values stored on hotels/rooms: room amenity slugs, socket letters, bedding
     * and meal names, tax names, serp filter labels, plus per-kind amenity
     * translations. Refresh ~monthly.
     */
    public function loadStaticData()
    {
        $this->ensureTables();
        $data = $this->callB2B('GET', 'hotel/static/');

        $rows = 0;
        $put = function ($category, $name, $labelEn, $locales) use (&$rows) {
            if ($name === null || $name === '') return;
            $this->db->query(
                "INSERT INTO ratehawk_static (category, name, lkey, label_en, locales)
                 VALUES (:c, :n, '', :l, :loc)
                 ON DUPLICATE KEY UPDATE label_en = VALUES(label_en), locales = VALUES(locales)",
                [':c' => $category, ':n' => (string)$name, ':l' => $labelEn, ':loc' => json_encode($locales ?: null)]
            );
            $rows++;
        };

        foreach (['beddings', 'meals', 'room_amenities', 'serp_filters', 'socket_types', 'taxes'] as $cat) {
            foreach (($data[$cat] ?? []) as $item) {
                if (!is_array($item)) continue;
                $locale = $item['locale'] ?? [];
                $put($cat, $item['name'] ?? null, $locale['en'] ?? null, $locale);
            }
        }

        // amenity_translations: { slug, kind, name: [{lang,value}], amenities: [{name: [{lang,value}]}] }
        // The outer slug+kind is just a grouping label (e.g. has_parking/APARTMENT -> "Parking").
        // The real translation dictionary is the NESTED `amenities` array: every specific
        // amenity phrase RateHawk uses in amenity_groups (e.g. "24-hour reception",
        // "Free off-site parking") with its translation into every language. Without this,
        // only the group label was translatable, not the actual amenity strings hotels use.
        $amenityPhrases = 0;
        foreach (($data['amenity_translations'] ?? []) as $item) {
            if (!is_array($item) || empty($item['slug'])) continue;
            $locales = [];
            foreach (($item['name'] ?? []) as $n) {
                if (isset($n['lang'], $n['value'])) $locales[$n['lang']] = $n['value'];
            }
            $kind = mb_substr((string)($item['kind'] ?? ''), 0, 32);
            $this->db->query(
                "INSERT INTO ratehawk_static (category, name, lkey, label_en, locales)
                 VALUES ('amenity_translation', :n, :k, :l, :loc)
                 ON DUPLICATE KEY UPDATE label_en = VALUES(label_en), locales = VALUES(locales)",
                [':n' => (string)$item['slug'], ':k' => $kind, ':l' => $locales['en'] ?? null, ':loc' => json_encode($locales ?: null)]
            );
            $rows++;

            foreach (($item['amenities'] ?? []) as $phrase) {
                if (!is_array($phrase)) continue;
                $phraseLocales = [];
                foreach (($phrase['name'] ?? []) as $n) {
                    if (isset($n['lang'], $n['value'])) $phraseLocales[$n['lang']] = $n['value'];
                }
                $en = $phraseLocales['en'] ?? null;
                if (!$en) continue; // no English key to hang the translation on

                $this->db->query(
                    "INSERT INTO ratehawk_static (category, name, lkey, label_en, locales)
                     VALUES ('hotel_amenity', :n, '', :l, :loc)
                     ON DUPLICATE KEY UPDATE locales = VALUES(locales)",
                    [':n' => mb_substr($en, 0, 96), ':l' => mb_substr($en, 0, 255), ':loc' => json_encode($phraseLocales)]
                );
                $rows++;
                $amenityPhrases++;
            }
        }

        $this->setState(['last_static_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
        return [
            'rows' => $rows,
            'amenity_phrases' => $amenityPhrases,
            'categories' => ['beddings', 'meals', 'room_amenities', 'serp_filters', 'socket_types', 'taxes', 'amenity_translation', 'hotel_amenity'],
        ];
    }

    // ----------------------------------------------------- 2. discover --

    /**
     * @param array  $filters  serp_filter[], kind[], star_rating[], country[], supplier_type, preferable, top
     * @param string|null $updatedSince  "Y-m-d H:i:s"; null = use stored watermark; "" = full catalogue
     */
    public function discover(array $filters = [], $updatedSince = null)
    {
        $this->ensureTables();
        $state = $this->state();

        if ($updatedSince === null) {
            $updatedSince = $state['last_updated_since'] ?? '';
        }

        $countries = array_values(array_filter(array_map('intval', (array)($filters['country'] ?? []))));
        if ($this->isSandbox) {
            $countries = $countries
                ? array_values(array_intersect($countries, self::SANDBOX_COUNTRIES))
                : self::SANDBOX_COUNTRIES;
        }

        // hotel_ids_by_filter returns a plain id list with no paging; to keep each
        // response bounded we page by country when a set is given, else one call.
        $callGroups = $countries ? array_chunk($countries, 1) : [[]];

        $callStartedAt = gmdate('Y-m-d H:i:s');
        $seenHids = [];
        $queued   = 0;

        foreach ($callGroups as $group) {
            $body = [];
            foreach (['serp_filter', 'kind', 'star_rating'] as $k) {
                if (!empty($filters[$k])) $body[$k] = array_values((array)$filters[$k]);
            }
            foreach (['supplier_type', 'preferable', 'top'] as $k) {
                if (isset($filters[$k]) && $filters[$k] !== '') $body[$k] = $filters[$k];
            }
            if ($group) $body['country'] = $group;
            if ($updatedSince !== '' && $updatedSince !== null) $body['updated_since'] = $updatedSince;

            $data = $this->call('POST', 'hotel_ids_by_filter/', $body);
            $hids = $data['hids'] ?? [];
            $ids  = $data['ids'] ?? [];

            foreach ($hids as $i => $hid) {
                $hid = (int)$hid;
                if ($hid <= 0 || isset($seenHids[$hid])) continue;
                $seenHids[$hid] = true;

                $this->db->query(
                    "INSERT INTO ratehawk_content_queue (hid, hotel_id, needs_content, needs_reviews, attempts, last_error)
                     VALUES (:hid, :hotel_id, 1, 1, 0, NULL)
                     ON DUPLICATE KEY UPDATE
                        hotel_id      = COALESCE(VALUES(hotel_id), hotel_id),
                        needs_content = 1,
                        needs_reviews = 1,
                        attempts      = 0,
                        last_error    = NULL",
                    [':hid' => $hid, ':hotel_id' => isset($ids[$i]) ? (string)$ids[$i] : null]
                );
                $queued++;
            }
        }

        $this->setState([
            'last_discover_at'   => date('Y-m-d H:i:s'),
            'last_updated_since' => $callStartedAt,   // next run picks up from here
            'discovered_total'   => (int)($state['discovered_total'] ?? 0) + $queued,
            'status'             => 'running',
            'last_error'         => null,
        ]);

        return [
            'queued'             => $queued,
            'unique_hids'        => count($seenHids),
            'updated_since_used' => ($updatedSince === '' ? '(full catalogue)' : $updatedSince),
            'next_updated_since' => $callStartedAt,
        ];
    }

    // ------------------------------------------------------- 3. sync content --

    public function syncContent($maxBatches = 1)
    {
        $this->ensureTables();
        $totalUpserted = $totalDeleted = $totalFailed = 0;
        $batches = 0;

        for ($b = 0; $b < $maxBatches; $b++) {
            $rows = $this->db->select('ratehawk_content_queue', ['hid', 'hotel_id'], [
                'needs_content' => 1,
                'attempts[<]'   => self::MAX_ATTEMPTS,
                'ORDER'         => ['attempts' => 'ASC', 'queued_at' => 'ASC'],
                'LIMIT'         => self::CONTENT_BATCH,
            ]);
            if (empty($rows)) break;
            $batches++;

            $hids = array_map(function ($r) { return (int)$r['hid']; }, $rows);

            try {
                $data = $this->call('POST', 'hotel_content_by_ids', [
                    'hids'     => $hids,
                    'language' => $this->language,
                ]);
            } catch (\Throwable $e) {
                $this->db->update('ratehawk_content_queue', [
                    'attempts[+]' => 1,
                    'last_error'  => mb_substr($e->getMessage(), 0, 2000),
                ], ['hid' => $hids]);
                $this->setState(['last_error' => mb_substr($e->getMessage(), 0, 2000)]);
                $totalFailed += count($hids);
                break;
            }

            $returned = [];
            foreach ((is_array($data) ? $data : []) as $item) {
                if (!is_array($item) || !isset($item['hid'])) continue;
                $hid = (int)$item['hid'];
                $returned[$hid] = true;

                if (!empty($item['deleted'])) {
                    $this->db->delete('ratehawk_hotels', ['hid' => $hid]);
                    $this->db->delete('ratehawk_content_queue', ['hid' => $hid]);
                    $this->db->delete('ratehawk_reviews', ['hid' => $hid]);
                    $totalDeleted++;
                    continue;
                }

                $row = rh_map_hotel_row($item, 'api');
                if (empty($row['hotel_id'])) $row['hotel_id'] = (string)$hid;

                $existing = $this->db->get('ratehawk_hotels', 'id', ['hid' => $hid])
                    ?: $this->db->get('ratehawk_hotels', 'id', ['hotel_id' => $row['hotel_id']]);

                if ($existing) {
                    unset($row['rating']); // never let content sync clobber the reviews rating
                    $this->db->update('ratehawk_hotels', $row, ['id' => $existing]);
                } else {
                    $this->db->insert('ratehawk_hotels', $row);
                }

                $this->db->update('ratehawk_content_queue', [
                    'needs_content'     => 0,
                    'content_synced_at' => date('Y-m-d H:i:s'),
                    'hotel_id'          => $row['hotel_id'],
                    'last_error'        => null,
                ], ['hid' => $hid]);
                $totalUpserted++;
            }

            // hids the API did not return this round -> count an attempt
            $missing = array_values(array_diff($hids, array_keys($returned)));
            if ($missing) {
                $this->db->update('ratehawk_content_queue', [
                    'attempts[+]' => 1,
                    'last_error'  => 'not returned by hotel_content_by_ids',
                ], ['hid' => $missing]);
                $totalFailed += count($missing);
            }
        }

        $state = $this->state();
        $this->setState(['content_done' => (int)($state['content_done'] ?? 0) + $totalUpserted]);

        return [
            'batches'  => $batches,
            'upserted' => $totalUpserted,
            'deleted'  => $totalDeleted,
            'failed'   => $totalFailed,
            'pending'  => (int)$this->db->count('ratehawk_content_queue', ['needs_content' => 1, 'attempts[<]' => self::MAX_ATTEMPTS]),
        ];
    }

    // ------------------------------------------------------- 4. sync reviews --

    public function syncReviews($maxBatches = 1)
    {
        $this->ensureTables();
        $totalReviews = $totalHotels = $totalFailed = 0;
        $batches = 0;

        for ($b = 0; $b < $maxBatches; $b++) {
            $rows = $this->db->select('ratehawk_content_queue', ['hid', 'hotel_id'], [
                'needs_reviews' => 1,
                'needs_content' => 0,           // content first
                'attempts[<]'   => self::MAX_ATTEMPTS,
                'ORDER'         => ['attempts' => 'ASC', 'queued_at' => 'ASC'],
                'LIMIT'         => self::REVIEW_BATCH,
            ]);
            if (empty($rows)) break;
            $batches++;

            $hids   = array_map(function ($r) { return (int)$r['hid']; }, $rows);
            $hidMap = [];
            foreach ($rows as $r) $hidMap[(int)$r['hid']] = $r['hotel_id'];

            try {
                $data = $this->call('POST', 'hotel_reviews_by_ids/', [
                    'hids'     => $hids,
                    'language' => $this->language,
                ]);
            } catch (\Throwable $e) {
                $this->db->update('ratehawk_content_queue', [
                    'attempts[+]' => 1,
                    'last_error'  => mb_substr($e->getMessage(), 0, 2000),
                ], ['hid' => $hids]);
                $totalFailed += count($hids);
                break;
            }

            foreach ((is_array($data) ? $data : []) as $item) {
                if (!is_array($item) || !isset($item['hid'])) continue;
                $hid      = (int)$item['hid'];
                $hotelId  = $item['id'] ?? ($hidMap[$hid] ?? (string)$hid);
                $reviews  = $item['reviews'] ?? $item['review'] ?? [];
                if (!is_array($reviews)) $reviews = [];

                foreach ($reviews as $idx => $rv) {
                    if (!is_array($rv)) continue;
                    $this->saveReview($hid, $hotelId, $rv, $idx);
                    $totalReviews++;
                }

                // Recompute the hotel's guest score from everything we have stored.
                $aggStmt = $this->db->query(
                    "SELECT COUNT(*) c, AVG(rating) a FROM ratehawk_reviews WHERE hid = :hid AND rating IS NOT NULL",
                    [':hid' => $hid]
                );
                $agg = $aggStmt ? $aggStmt->fetch(\PDO::FETCH_ASSOC) : null;

                if ($agg && (int)$agg['c'] > 0) {
                    $this->db->update('ratehawk_hotels', [
                        'rating'       => round((float)$agg['a'], 2),
                        'rating_count' => (int)$agg['c'],
                    ], ['hid' => $hid]);
                }

                $this->db->update('ratehawk_content_queue', [
                    'needs_reviews'     => 0,
                    'reviews_synced_at' => date('Y-m-d H:i:s'),
                    'last_error'        => null,
                ], ['hid' => $hid]);
                $totalHotels++;
            }
        }

        $state = $this->state();
        $this->setState(['reviews_done' => (int)($state['reviews_done'] ?? 0) + $totalHotels]);

        return [
            'batches'       => $batches,
            'reviews_saved' => $totalReviews,
            'hotels'        => $totalHotels,
            'failed'        => $totalFailed,
            'pending'       => (int)$this->db->count('ratehawk_content_queue', ['needs_reviews' => 1, 'needs_content' => 0, 'attempts[<]' => self::MAX_ATTEMPTS]),
        ];
    }

    /**
     * Upsert one review row. Shared by the API sync and (potential) reviews-dump
     * import. Handles both the hotel_reviews_by_ids item shape and the
     * reviews-dump review object shape (same fields).
     */
    private function saveReview($hid, $hotelId, array $rv, $idx = 0)
    {
        $uid = (string)($rv['id'] ?? $rv['review_id'] ?? md5(json_encode($rv) . $idx));
        $this->db->query(
            "INSERT INTO ratehawk_reviews
                (hid, hotel_id, review_uid, rating, review_plus, review_minus, author,
                 traveller_type, trip_type, room_name, adults, children, review_date, nights, detailed, images, raw)
             VALUES (:hid,:hotel_id,:uid,:rating,:plus,:minus,:author,:ttype,:trip,:room,:adults,:children,:rdate,:nights,:detailed,:images,:raw)
             ON DUPLICATE KEY UPDATE
                rating=VALUES(rating), review_plus=VALUES(review_plus), review_minus=VALUES(review_minus),
                author=VALUES(author), traveller_type=VALUES(traveller_type), trip_type=VALUES(trip_type),
                room_name=VALUES(room_name), adults=VALUES(adults), children=VALUES(children),
                review_date=VALUES(review_date), nights=VALUES(nights), detailed=VALUES(detailed),
                images=VALUES(images), raw=VALUES(raw)",
            [
                ':hid'      => (int)$hid,
                ':hotel_id' => mb_substr((string)$hotelId, 0, 100),
                ':uid'      => mb_substr($uid, 0, 120),
                ':rating'   => $this->reviewScore($rv),
                ':plus'     => $rv['review_plus'] ?? ($rv['plus'] ?? null),
                ':minus'    => $rv['review_minus'] ?? ($rv['minus'] ?? null),
                ':author'   => mb_substr((string)($rv['author'] ?? $rv['name'] ?? ''), 0, 191) ?: null,
                ':ttype'    => mb_substr((string)($rv['traveller_type'] ?? $rv['travel_type'] ?? ''), 0, 64) ?: null,
                ':trip'     => mb_substr((string)($rv['trip_type'] ?? ''), 0, 64) ?: null,
                ':room'     => mb_substr((string)($rv['room_name'] ?? ''), 0, 255) ?: null,
                ':adults'   => isset($rv['adults']) && is_numeric($rv['adults']) ? (int)$rv['adults'] : null,
                ':children' => isset($rv['children']) && is_numeric($rv['children']) ? (int)$rv['children'] : null,
                ':rdate'    => $this->reviewDate($rv),
                ':nights'   => isset($rv['nights']) && is_numeric($rv['nights']) ? (int)$rv['nights'] : null,
                ':detailed' => json_encode($rv['detailed'] ?? $rv['detailed_ratings'] ?? $rv['rating_struct'] ?? null),
                ':images'   => json_encode($rv['images'] ?? []),
                ':raw'      => json_encode($rv),
            ]
        );
    }

    private function reviewScore(array $rv)
    {
        foreach (['rating', 'score', 'rate', 'mark', 'total'] as $k) {
            if (isset($rv[$k]) && is_numeric($rv[$k])) {
                return round((float)$rv[$k], 2);
            }
        }
        return null;
    }

    private function reviewDate(array $rv)
    {
        $v = $rv['created'] ?? $rv['review_date'] ?? $rv['date'] ?? $rv['created_at'] ?? null;
        if (!$v) return null;
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // -------------------------------------------------- 5. region / poi dump --

    /**
     * b2b POST hotel/region/dump -> download the Zstd archive -> ratehawk_regions.
     * Zstd cannot be decompressed in pure PHP; if the `zstd` binary is not on
     * PATH the compressed file is kept and the caller is told to decompress it
     * manually, then re-run with local_path pointing at the .jsonl.
     */
    public function syncRegionDump($localPath = null)
    {
        $this->ensureTables();
        $jsonl = $this->resolveDump('hotel/region/dump/', ['language' => $this->language], 'region_dump', $localPath);
        if (!empty($jsonl['manual'])) return $jsonl;

        $count = 0;
        $this->streamJsonObjects($jsonl['path'], function ($r) use (&$count) {
            if (!is_array($r) || !isset($r['id'])) return;
            $names   = is_array($r['name'] ?? null) ? $r['name'] : [];
            $cnames  = is_array($r['country_name'] ?? null) ? $r['country_name'] : [];
            $center  = is_array($r['center'] ?? null) ? $r['center'] : [];
            $hids    = is_array($r['hids'] ?? null) ? array_values(array_filter(array_map('intval', $r['hids']))) : [];
            $this->db->query(
                "INSERT INTO ratehawk_regions
                    (id, type, name_en, names, country_code, country_name_en, country_names, iata, latitude, longitude, hotel_count, hids)
                 VALUES (:id,:type,:nen,:names,:cc,:cnen,:cnames,:iata,:lat,:lng,:hc,:hids)
                 ON DUPLICATE KEY UPDATE
                    type=VALUES(type), name_en=VALUES(name_en), names=VALUES(names), country_code=VALUES(country_code),
                    country_name_en=VALUES(country_name_en), country_names=VALUES(country_names), iata=VALUES(iata),
                    latitude=VALUES(latitude), longitude=VALUES(longitude), hotel_count=VALUES(hotel_count), hids=VALUES(hids)",
                [
                    ':id'    => (int)$r['id'],
                    ':type'  => mb_substr((string)($r['type'] ?? ''), 0, 40) ?: null,
                    ':nen'   => mb_substr((string)($names['en'] ?? ''), 0, 255) ?: null,
                    ':names' => json_encode($names ?: null),
                    ':cc'    => mb_substr((string)($r['country_code'] ?? ''), 0, 10) ?: null,
                    ':cnen'  => mb_substr((string)($cnames['en'] ?? ''), 0, 255) ?: null,
                    ':cnames'=> json_encode($cnames ?: null),
                    ':iata'  => mb_substr((string)($r['iata'] ?? ''), 0, 10) ?: null,
                    ':lat'   => isset($center['latitude']) && is_numeric($center['latitude']) ? (float)$center['latitude'] : null,
                    ':lng'   => isset($center['longitude']) && is_numeric($center['longitude']) ? (float)$center['longitude'] : null,
                    ':hc'    => count($hids),
                    ':hids'  => json_encode($hids),
                ]
            );
            $count++;
        });

        if (!$localPath) @unlink($jsonl['path']);
        $this->setState(['last_regions_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
        return ['imported' => $count];
    }

    /**
     * b2b POST hotel/poi/dump -> nearby points of interest per hotel -> ratehawk_pois.
     */
    public function syncPoiDump($localPath = null)
    {
        $this->ensureTables();
        $jsonl = $this->resolveDump('hotel/poi/dump', ['language' => $this->language], 'poi_dump', $localPath);
        if (!empty($jsonl['manual'])) return $jsonl;

        $count = 0;
        $this->streamJsonObjects($jsonl['path'], function ($r) use (&$count) {
            if (!is_array($r) || !isset($r['hid'])) return;
            $this->db->query(
                "INSERT INTO ratehawk_pois (hid, hotel_id, pois) VALUES (:hid,:hotel_id,:pois)
                 ON DUPLICATE KEY UPDATE hotel_id=VALUES(hotel_id), pois=VALUES(pois)",
                [
                    ':hid'      => (int)$r['hid'],
                    ':hotel_id' => mb_substr((string)($r['id'] ?? ''), 0, 100) ?: null,
                    ':pois'     => json_encode($r['pois'] ?? []),
                ]
            );
            $count++;
        });

        if (!$localPath) @unlink($jsonl['path']);
        $this->setState(['last_poi_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
        return ['imported' => $count];
    }

    /**
     * Get a dump download link, fetch the archive into content/, and return a
     * path to a readable JSONL file. Handles .zst (needs `zstd` binary) and .gz.
     */
    private function resolveDump($endpoint, array $body, $stem, $localPath)
    {
        $dir = __DIR__;
        if ($localPath) {
            $p = $localPath;
            if (!is_file($p)) $p = $dir . '/' . basename($localPath);
            if (!is_file($p)) {
                throw new \RuntimeException("Local dump file not found: {$localPath}");
            }
            return ['path' => $p];
        }

        $data = $this->callB2B('POST', $endpoint, $body);
        $url  = $data['url'] ?? '';
        if ($url === '') {
            throw new \RuntimeException("No download url returned by {$endpoint}");
        }

        $archive = $dir . '/' . $stem . (preg_match('#\.jsonl\.zst#i', $url) ? '.jsonl.zst'
                    : (preg_match('#\.gz(\?|$)#i', $url) ? '.jsonl.gz' : '.jsonl.zst'));

        $fh = fopen($archive, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 1800,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'PHPTravelsPartner/1.0 (php-curl)',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);
        $ok       = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dlErr    = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        clearstatcache(true, $archive);
        if (!$ok || $httpCode >= 400 || !filesize($archive)) {
            @unlink($archive);
            throw new \RuntimeException("Failed to download dump archive (HTTP {$httpCode}" . ($dlErr ? ", {$dlErr}" : '') . "). URL host: " . parse_url($url, PHP_URL_HOST));
        }

        $jsonl = $dir . '/' . $stem . '.jsonl';

        if (substr($archive, -3) === '.gz') {
            $in = gzopen($archive, 'rb');
            $out = fopen($jsonl, 'w');
            while (!gzeof($in)) fwrite($out, gzread($in, 1 << 20));
            gzclose($in);
            fclose($out);
            @unlink($archive);
            return ['path' => $jsonl, 'last_update' => $data['last_update'] ?? null];
        }

        // .zst — need the zstd binary
        $zstd = $this->findZstd();
        if (!$zstd) {
            return [
                'manual'     => true,
                'archive'    => $archive,
                'last_update'=> $data['last_update'] ?? null,
                'message'    => "Downloaded {$stem}. The zstd binary was not found on PATH — decompress it manually: "
                              . "zstd -d " . basename($archive) . " -o {$stem}.jsonl  (in " . $dir . "), then re-run this action.",
            ];
        }
        @exec(escapeshellcmd($zstd) . ' -d -f ' . escapeshellarg($archive) . ' -o ' . escapeshellarg($jsonl) . ' 2>&1', $o, $rc);
        if ($rc !== 0 || !is_file($jsonl)) {
            throw new \RuntimeException('zstd decompression failed: ' . implode(' ', (array)$o));
        }
        @unlink($archive);
        return ['path' => $jsonl, 'last_update' => $data['last_update'] ?? null];
    }

    private function findZstd()
    {
        foreach (['/usr/local/bin/zstd', '/usr/bin/zstd', '/opt/homebrew/bin/zstd', 'zstd'] as $c) {
            @exec(escapeshellarg($c) . ' --version 2>/dev/null', $o, $rc);
            if ($rc === 0) return $c;
        }
        return null;
    }

    /**
     * Stream a file that is either JSONL (one object per line) or a single JSON
     * array of objects, calling $cb for each object. Memory-safe for JSONL.
     */
    private function streamJsonObjects($path, callable $cb)
    {
        $fh = fopen($path, 'r');
        if (!$fh) throw new \RuntimeException("Cannot open {$path}");

        $first = fgets($fh);
        $trim  = ltrim((string)$first);

        // Whole-file JSON array fallback (small dumps / POI).
        if ($trim !== '' && $trim[0] === '[' && strpos($trim, '}') === false) {
            fclose($fh);
            $all = json_decode(file_get_contents($path), true);
            foreach ((is_array($all) ? $all : []) as $obj) $cb($obj);
            return;
        }

        rewind($fh);
        while (($line = fgets($fh)) !== false) {
            $line = trim($line, " \t\r\n,");
            if ($line === '' || $line === '[' || $line === ']') continue;
            $obj = json_decode($line, true);
            if (is_array($obj)) $cb($obj);
        }
        fclose($fh);
    }

    // --------------------------------------------------------- orchestration --

    /**
     * Drains the queue within a time budget. Called repeatedly by the admin
     * UI's "Run Sync" button loop. Discovery is NOT run here unless $discover
     * is set.
     */
    public function run($budgetSeconds = 25, $discover = false, array $discoverFilters = [])
    {
        $this->ensureTables();
        $started = microtime(true);
        $out = ['discover' => null, 'content' => [], 'reviews' => []];

        if ($discover) {
            $out['discover'] = $this->discover($discoverFilters, null);
        }

        while (microtime(true) - $started < $budgetSeconds) {
            $c = $this->syncContent(1);
            $out['content'][] = $c;
            if ($c['batches'] === 0) break;
            if (microtime(true) - $started >= $budgetSeconds) break;
        }
        while (microtime(true) - $started < $budgetSeconds) {
            $r = $this->syncReviews(1);
            $out['reviews'][] = $r;
            if ($r['batches'] === 0) break;
        }

        $st = $this->status();
        if ($st['content_pending'] === 0 && $st['reviews_pending'] === 0) {
            $this->setState(['status' => 'idle']);
        }
        $out['status'] = $st;
        $out['elapsed'] = round(microtime(true) - $started, 1);
        return $out;
    }

    public function status()
    {
        $this->ensureTables();
        $state = $this->state();
        return [
            'status'              => $state['status'] ?? 'idle',
            'last_discover_at'    => $state['last_discover_at'] ?? null,
            'last_updated_since'  => $state['last_updated_since'] ?? null,
            'discovered_total'    => (int)($state['discovered_total'] ?? 0),
            'content_done'        => (int)($state['content_done'] ?? 0),
            'reviews_done'        => (int)($state['reviews_done'] ?? 0),
            'last_error'          => $state['last_error'] ?? null,
            'queue_total'         => (int)$this->db->count('ratehawk_content_queue', []),
            'content_pending'     => (int)$this->db->count('ratehawk_content_queue', ['needs_content' => 1, 'attempts[<]' => self::MAX_ATTEMPTS]),
            'reviews_pending'     => (int)$this->db->count('ratehawk_content_queue', ['needs_reviews' => 1, 'needs_content' => 0, 'attempts[<]' => self::MAX_ATTEMPTS]),
            'stuck'               => (int)$this->db->count('ratehawk_content_queue', ['attempts[>=]' => self::MAX_ATTEMPTS]),
            'hotels_total'        => (int)$this->db->count('ratehawk_hotels', []),
            'reviews_total'       => (int)$this->db->count('ratehawk_reviews', []),
            'static_total'        => (int)$this->db->count('ratehawk_static', []),
            'regions_total'       => (int)$this->db->count('ratehawk_regions', []),
            'pois_total'          => (int)$this->db->count('ratehawk_pois', []),
            'last_static_at'      => $state['last_static_at'] ?? null,
            'last_regions_at'     => $state['last_regions_at'] ?? null,
            'last_poi_at'         => $state['last_poi_at'] ?? null,
        ];
    }

    // ---------------------------------------------------- admin settings ------

    /** Current admin knobs (auto-sync toggle + price-change threshold). */
    public function getSettings()
    {
        $this->ensureTables();
        $s = $this->state();
        $def = function_exists('rh_default_settings') ? rh_default_settings()
            : ['price_change_threshold' => 2.0, 'booking_cutoff_minutes' => 15];
        return [
            'auto_sync_enabled'      => (int)($s['auto_sync_enabled'] ?? 0) === 1,
            'last_auto_run_at'       => $s['last_auto_run_at'] ?? null,
            'price_change_threshold' => (float)($s['price_change_threshold'] ?? $def['price_change_threshold']),
            'booking_cutoff_minutes' => (int)($s['booking_cutoff_minutes'] ?? $def['booking_cutoff_minutes']),
            'next_auto_due'          => $this->nextAutoDue($s),
        ];
    }

    /** Persist admin knobs. Threshold is clamped to 0..100. */
    public function saveSettings(array $in)
    {
        $this->ensureTables();
        $patch = [];
        if (array_key_exists('auto_sync_enabled', $in)) {
            $patch['auto_sync_enabled'] = !empty($in['auto_sync_enabled']) ? 1 : 0;
        }
        if (array_key_exists('price_change_threshold', $in) && is_numeric($in['price_change_threshold'])) {
            $patch['price_change_threshold'] = max(0, min(100, round((float)$in['price_change_threshold'], 2)));
        }
        if (array_key_exists('booking_cutoff_minutes', $in) && is_numeric($in['booking_cutoff_minutes'])) {
            $patch['booking_cutoff_minutes'] = max(1, min(120, (int)$in['booking_cutoff_minutes']));
        }
        if ($patch) {
            $this->setState($patch);
        }
        return $this->getSettings();
    }

    private function nextAutoDue(array $s)
    {
        if ((int)($s['auto_sync_enabled'] ?? 0) !== 1) return null;
        $last = $s['last_auto_run_at'] ?? null;
        if (!$last) return 'now';
        $due = strtotime($last) + 86400;
        return $due <= time() ? 'now' : date('Y-m-d H:i:s', $due);
    }

    /**
     * The 24h auto-refresh. Driven by the daily cron route
     * (GET /ratehawk_content_sync) and, as a fallback, by the admin panel on load.
     *
     *  - auto_sync_enabled = 0  → does nothing ("disabled"): no cron work happens.
     *  - last auto run < 24h ago → does nothing ("within_24h"): ETG asks that the
     *    Content API is not pulled more than once per 24h.
     *  - otherwise → stamps last_auto_run_at FIRST (so a second caller can't
     *    double-fire), then discovers changed hotels and drains the queue until
     *    empty or $maxSeconds elapses.
     */
    public function autoCheck($maxSeconds = 280)
    {
        $this->ensureTables();
        $s = $this->state();

        if ((int)($s['auto_sync_enabled'] ?? 0) !== 1) {
            return ['ran' => false, 'reason' => 'disabled'];
        }
        $last = $s['last_auto_run_at'] ?? null;
        if ($last && (strtotime($last) + 86400) > time()) {
            return ['ran' => false, 'reason' => 'within_24h', 'next_auto_due' => $this->nextAutoDue($s)];
        }

        // Claim this run before doing any work.
        $this->setState(['last_auto_run_at' => date('Y-m-d H:i:s')]);

        $maxSeconds = max(20, (int) $maxSeconds);
        $started = time();
        try {
            // First tick discovers changed hotels + starts draining.
            $this->run(min(30, $maxSeconds), true, []);

            // Keep draining the queue until empty or out of time.
            while (time() - $started < $maxSeconds) {
                $st = $this->status();
                if ((int)($st['content_pending'] ?? 0) === 0 && (int)($st['reviews_pending'] ?? 0) === 0) {
                    break;
                }
                $this->run(30, false, []);
            }

            return ['ran' => true, 'elapsed' => time() - $started, 'status' => $this->status()];
        } catch (\Throwable $e) {
            $this->setState(['last_error' => 'auto: ' . $e->getMessage()]);
            return ['ran' => true, 'error' => $e->getMessage()];
        }
    }

    public function reset($hard = false)
    {
        $this->ensureTables();
        $this->db->query("DELETE FROM ratehawk_content_queue");
        $this->setState([
            'status' => 'idle', 'last_error' => null,
            'last_discover_at' => null, 'last_updated_since' => null,
            'discovered_total' => 0, 'content_done' => 0, 'reviews_done' => 0,
        ]);
        if ($hard) {
            $this->db->query("DELETE FROM ratehawk_reviews");
        }
        return ['reset' => true, 'hard' => $hard];
    }
}

// ============================================================================
// JSON ENDPOINT — only when this file is requested directly (not included)
// ============================================================================
// Actions (POST or GET `action`): discover, sync_content, sync_reviews,
// static, regions, pois, run, status, reset. See class methods above for
// what each one does.
if (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {

    @error_reporting(0);
    @ini_set('display_errors', 0);
    while (@ob_get_level()) @ob_end_clean();

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    set_time_limit(300);
    ini_set('memory_limit', '1G');

    try {
        $env = parse_ini_file(__DIR__ . '/../../../../.env');
        require_once __DIR__ . '/../../../../vendor/autoload.php';

        $mainDb = new \Medoo\Medoo([
            'type'      => $env['DB_TYPE'] ?? 'mysql',
            'host'      => $env['DB_HOST'] ?? 'localhost',
            'database'  => $env['DB_DATABASE'],
            'username'  => $env['DB_USERNAME'],
            'password'  => $env['DB_PASSWORD'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $module = $mainDb->get('modules', ['host', 'database', 'username', 'password', 'c1', 'c3', 'c4', 'currency', 'logging_enabled'], [
            'name' => 'ratehawk',
            'type' => 'stays',
        ]);
        if (!$module) {
            die(json_encode(['success' => false, 'error' => 'RateHawk module row not found']));
        }

        $db = new \Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $module['host'] ?: ($env['DB_HOST'] ?? 'localhost'),
            'database'  => $module['database'] ?: $env['DB_DATABASE'],
            'username'  => $module['username'] ?: $env['DB_USERNAME'],
            'password'  => $module['password'] ?: $env['DB_PASSWORD'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $engine = new RatehawkContentSync($db, [
            'key_id'      => $module['c1'] ?? '',
            'api_key'     => $module['c3'] ?? '',
            'base_url'    => RatehawkContentSync::contentBaseFromB2B($module['c4'] ?? ''),
            'language'    => 'en',
            'log_enabled' => (string)($module['logging_enabled'] ?? '') === '1',
            'log_dir'     => __DIR__ . '/../logs',
        ]);

        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $req    = array_merge($body, $_GET, $_POST);
        $action = $req['action'] ?? 'status';

        $arr = function ($v) {
            if (is_array($v)) return $v;
            if ($v === null || $v === '') return [];
            return array_map('trim', explode(',', (string)$v));
        };

        switch ($action) {
            case 'discover':
                $filters = [
                    'serp_filter'   => $arr($req['serp_filter'] ?? []),
                    'kind'          => $arr($req['kind'] ?? []),
                    'star_rating'   => array_map('intval', $arr($req['star_rating'] ?? [])),
                    'country'       => array_map('intval', $arr($req['country'] ?? [])),
                    'supplier_type' => $req['supplier_type'] ?? '',
                    'preferable'    => isset($req['preferable']) ? filter_var($req['preferable'], FILTER_VALIDATE_BOOLEAN) : '',
                    'top'           => isset($req['top']) ? filter_var($req['top'], FILTER_VALIDATE_BOOLEAN) : '',
                ];
                $updatedSince = array_key_exists('updated_since', $req) ? (string)$req['updated_since'] : null;
                echo json_encode(['success' => true, 'data' => $engine->discover($filters, $updatedSince)]);
                break;

            case 'sync_content':
                echo json_encode(['success' => true, 'data' => $engine->syncContent(max(1, (int)($req['batches'] ?? 1)))]);
                break;

            case 'sync_reviews':
                echo json_encode(['success' => true, 'data' => $engine->syncReviews(max(1, (int)($req['batches'] ?? 1)))]);
                break;

            case 'static':
                echo json_encode(['success' => true, 'data' => $engine->loadStaticData()]);
                break;

            case 'regions':
                echo json_encode(['success' => true, 'data' => $engine->syncRegionDump($req['local_path'] ?? null)]);
                break;

            case 'pois':
                echo json_encode(['success' => true, 'data' => $engine->syncPoiDump($req['local_path'] ?? null)]);
                break;

            case 'run':
                $out = $engine->run(
                    max(5, min(120, (int)($req['budget'] ?? 25))),
                    !empty($req['discover']),
                    ['country' => array_map('intval', $arr($req['country'] ?? []))]
                );
                echo json_encode(['success' => true, 'data' => $out]);
                break;

            case 'settings':
                if (isset($req['auto_sync_enabled']) || isset($req['price_change_threshold']) || isset($req['booking_cutoff_minutes'])) {
                    $save = [];
                    if (isset($req['auto_sync_enabled'])) {
                        $save['auto_sync_enabled'] = filter_var($req['auto_sync_enabled'], FILTER_VALIDATE_BOOLEAN);
                    }
                    if (isset($req['price_change_threshold'])) {
                        $save['price_change_threshold'] = $req['price_change_threshold'];
                    }
                    if (isset($req['booking_cutoff_minutes'])) {
                        $save['booking_cutoff_minutes'] = $req['booking_cutoff_minutes'];
                    }
                    echo json_encode(['success' => true, 'data' => $engine->saveSettings($save)]);
                } else {
                    echo json_encode(['success' => true, 'data' => $engine->getSettings()]);
                }
                break;

            case 'autocheck':
                echo json_encode(['success' => true, 'data' => $engine->autoCheck(
                    max(20, min(600, (int)($req['budget'] ?? 280)))
                )]);
                break;

            case 'reset':
                echo json_encode(['success' => true, 'data' => $engine->reset(!empty($req['hard']))]);
                break;

            case 'status':
            default:
                echo json_encode(['success' => true, 'data' => $engine->status()]);
                break;
        }
    } catch (\Throwable $e) {
        http_response_code(200);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit; // never fall through to the admin UI markup below
}

// ============================================================================
// ADMIN UI — rendered only when this file is included from the Content Sync
// tab (app/views/admin/settings/modules-settings.php)
// ============================================================================

// Base path: prefer the app-wide `root` constant, fall back to $root or '/'.
$caRoot = defined('root') ? root : ($root ?? '/');
$caRoot = rtrim((string) $caRoot, '/');
?>

<!-- ============================================================= -->
<!-- RATEHAWK CONTENT API SYNC — admin panel                        -->
<!-- Incremental static-content sync (replaces the bulk db.jsonl).  -->
<!-- ============================================================= -->

<div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-4">
    <div class="flex items-start gap-3">
        <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
        <div class="flex-1 text-sm text-blue-900">
            <p class="font-bold mb-2 text-base">How this works</p>
            <p class="text-xs text-blue-800">
                Pulls hotel content directly from the RateHawk Content API in three steps —
                <code class="bg-white/60 px-1 rounded text-xs">hotel_ids_by_filter</code> →
                <code class="bg-white/60 px-1 rounded text-xs">hotel_content_by_ids</code> →
                <code class="bg-white/60 px-1 rounded text-xs">hotel_reviews_by_ids</code>.
                It keeps a <strong>watermark</strong> of the last run, so each sync only fetches hotels that changed since. No 40&nbsp;GB download.
            </p>
            <div class="flex items-start gap-2 text-xs mt-2">
                <span class="text-amber-600">⚠</span>
                <div><span class="font-medium">Note:</span> run this manually whenever you like — it should not run at the same time as live search / hotelpage traffic. ETG asks that content is refreshed no more than once every 24&nbsp;hours.</div>
            </div>
        </div>
    </div>
</div>

<!-- Settings: 24h auto-refresh cron + prebook price-change threshold -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">schedule</span>
                <h3 class="text-sm font-semibold text-gray-900">Auto-refresh static data every 24 hours</h3>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <span class="text-xs text-gray-600" id="caAutoSyncLabel">Off</span>
                <input type="checkbox" id="caAutoSync" value="1"
                    onchange="caSaveSettings(event)"
                    class="w-10 h-5 appearance-none bg-gray-300 rounded-full relative cursor-pointer transition-colors checked:bg-blue-600
                            before:content-[''] before:absolute before:w-4 before:h-4 before:rounded-full before:bg-white before:top-0.5 before:left-0.5
                            before:transition-transform checked:before:translate-x-5">
            </label>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                <div class="text-sm text-blue-900">
                    <p class="font-bold mb-2 text-base">How the 24-hour refresh works</p>
                    <div class="space-y-1 text-xs text-blue-800">
                        <div class="flex items-start gap-2">
                            <span class="text-green-600">✓</span>
                            <div><span class="font-medium">On:</span> the daily cron below discovers changed hotels and refreshes their content once every 24&nbsp;hours. Opening this page also triggers it if a day has passed.</div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="text-gray-500">✗</span>
                            <div><span class="font-medium">Off:</span> nothing runs automatically — the cron endpoint just returns "skipped". Update only with the buttons below.</div>
                        </div>
                    </div>
                    <p class="text-xs mt-3 mb-1 text-blue-800 font-medium">Add this line to the server crontab (safe to leave even when the toggle is off):</p>
                    <code class="block bg-white/70 rounded px-2 py-1.5 text-xs text-gray-800 break-all">0 4 * * * curl -s "<?= $caRoot ?>/ratehawk_content_sync" &gt; /dev/null 2&gt;&amp;1</code>
                    <p class="text-xs mt-2 text-blue-700" id="caAutoNext"></p>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-200 pt-4 grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-800 mb-1">Acceptable price change at prebook (%)</label>
                <input type="number" id="caPriceThreshold" min="0" max="100" step="1" class="input" style="max-width:110px">
                <p class="text-xs text-gray-400 mt-1.5">
                    If the hotel's net price rises by up to this % between the guest picking a room and the
                    booking being placed, it is accepted automatically. Above it, the booking is held and the
                    guest is shown a "price changed" notice on the invoice.
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-800 mb-1">Booking cut-off (minutes)</label>
                <input type="number" id="caBookingCutoff" min="1" max="120" step="1" class="input" style="max-width:110px">
                <p class="text-xs text-gray-400 mt-1.5">
                    How long we keep checking with RateHawk for confirmation after the booking is submitted,
                    counted from the successful <code class="bg-gray-100 px-1 rounded">booking/finish</code> response.
                    After this, an unconfirmed booking is marked failed. Set to the value agreed with ETG during certification.
                </p>
            </div>
            <div class="sm:col-span-2">
                <button type="button" id="caBtnSaveSettings" onclick="caSaveSettings(event)" class="btn light">
                    <span class="material-symbols-outlined text-sm">save</span> Save
                </button>
                <span id="caSettingsSaved" class="text-xs text-green-600 ml-2 hidden">Saved</span>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">sync</span>
            <h3 class="text-sm font-semibold text-gray-900">Content API Sync</h3>
        </div>
        <span id="caStatus" class="text-xs text-gray-500 bg-blue-100 px-2 py-1 rounded-md font-medium">loading…</span>
    </div>

    <div class="p-4 space-y-4">

        <!-- Stat tiles — only what needs a glance: total, what's left, what's broken -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Hotels in DB</p>
                <p id="caHotels" class="text-lg font-bold text-gray-900">0</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Content pending</p>
                <p id="caContentPending" class="text-lg font-bold text-gray-900">0</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Reviews pending</p>
                <p id="caReviewsPending" class="text-lg font-bold text-gray-900">0</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-500">Stuck (max retries)</p>
                <p id="caStuck" class="text-lg font-bold text-gray-900">0</p>
            </div>
        </div>

        <!-- Progress bar (queue drain) -->
        <div id="caProgressWrap" class="hidden space-y-1">
            <div class="flex justify-between text-xs text-gray-600">
                <span>Queue drain</span>
                <span id="caProgressPct">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div id="caProgressBar" class="bg-blue-600 h-full transition-all" style="width:0%"></div>
            </div>
        </div>

        <!-- Watermark / last-run info -->
        <div class="text-xs text-gray-500 grid sm:grid-cols-2 gap-1">
            <div>Last discover: <span id="caLastDiscover" class="font-mono text-gray-700">—</span></div>
            <div>Watermark: <span id="caWatermark" class="font-mono text-gray-700">—</span></div>
        </div>

        <!-- Discovery filters -->
        <details class="border border-gray-200 rounded-lg">
            <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-700 bg-gray-50 rounded-lg">
                Discovery filters (optional)
            </summary>
            <div class="p-3 grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country IDs — comma separated</label>
                    <input id="caCountry" type="text" placeholder="59, 153, 189, 201" class="input w-full">
                    <p class="text-xs text-gray-400 mt-0.5">Sandbox only allows 59 / 153 / 189 / 201. Blank = all.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">updated_since — leave blank for full catalogue</label>
                    <input id="caUpdatedSince" type="text" placeholder="2026-01-01 00:00:00" class="input w-full">
                    <p class="text-xs text-gray-400 mt-0.5">Blank on first run, then the sync tracks its own watermark.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Property kinds — comma separated</label>
                    <input id="caKind" type="text" placeholder="Hotel, Hostel, Apartment" class="input w-full">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">SERP filters — comma separated</label>
                    <input id="caSerp" type="text" placeholder="has_breakfast, has_pool" class="input w-full">
                </div>
            </div>
        </details>

        <!-- Activity log -->
        <div id="caLog" class="hidden font-mono text-xs bg-gray-900 rounded-lg p-3 h-40 overflow-y-auto" style="background:#0d1117"></div>

        <!-- Actions -->
        <div class="flex flex-wrap gap-3">
            <button type="button" id="caBtnDiscover" onclick="caDiscover(event)" class="btn light">
                <span class="material-symbols-outlined text-sm">travel_explore</span> 1. Discover Updated
            </button>
            <button type="button" id="caBtnRun" onclick="caRun(event)" class="btn">
                <span class="material-symbols-outlined text-sm">play_arrow</span> 2. Run Sync
            </button>
            <button type="button" id="caBtnStop" onclick="caStop(event)" class="btn light hidden">
                <span class="material-symbols-outlined text-sm">stop</span> Stop
            </button>
            <button type="button" id="caBtnReset" onclick="caReset(event)" class="btn light ml-auto">
                <span class="material-symbols-outlined text-sm">refresh</span> Reset Queue
            </button>
        </div>

        <!-- Reference data (heavier, run less often) -->
        <div class="border-t border-gray-200 pt-4">
            <p class="text-xs font-semibold text-gray-500 mb-2">Reference data</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <button type="button" onclick="caStatic(event)" class="btn light justify-between">
                    <span class="flex items-center gap-2"><span class="material-symbols-outlined text-sm">translate</span> Load hotel/static</span>
                    <span class="text-gray-400" id="caStaticCount"></span>
                </button>
                <button type="button" onclick="caRegions(event)" class="btn light justify-between">
                    <span class="flex items-center gap-2"><span class="material-symbols-outlined text-sm">map</span> Sync region dump</span>
                    <span class="text-gray-400" id="caRegionsCount"></span>
                </button>
                <button type="button" onclick="caPois(event)" class="btn light justify-between">
                    <span class="flex items-center gap-2"><span class="material-symbols-outlined text-sm">pin_drop</span> Sync POI dump</span>
                    <span class="text-gray-400" id="caPoisCount"></span>
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-2">Region &amp; POI dumps are Zstd archives — needs the <code>zstd</code> binary on the server, otherwise you'll be told to decompress manually.</p>
        </div>
    </div>
</div>

<script>
// ================================================================
// RATEHAWK CONTENT API SYNC — admin panel
// ================================================================
(function () {
    const CA = {
        endpoint: '<?= $caRoot ?>/modules/stays/ratehawk/content/ratehawk-content-sync.php',
        running: false,
        poll: null,
    };

    const $ = id => document.getElementById(id);

    function log(kind, msg) {
        const box = $('caLog');
        box.classList.remove('hidden');
        const colors = { info: '#9ca3af', success: '#34d399', warn: '#fbbf24', error: '#f87171' };
        const line = document.createElement('div');
        line.style.color = colors[kind] || '#9ca3af';
        line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
    }

    async function call(params, timeout) {
        const res = await fetch(CA.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString(),
            signal: AbortSignal.timeout(timeout || 180000),
        });
        return res.json();
    }

    const csv = v => (v || '').split(',').map(s => s.trim()).filter(Boolean).join(',');

    function render(s) {
        if (!s) return;
        $('caStatus').textContent = s.status || 'idle';
        $('caStatus').className = 'text-xs px-2 py-1 rounded-md font-medium ' +
            (s.status === 'running' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 bg-gray-100');
        $('caContentPending').textContent = (s.content_pending || 0).toLocaleString();
        $('caReviewsPending').textContent = (s.reviews_pending || 0).toLocaleString();
        $('caStuck').textContent = (s.stuck || 0).toLocaleString();
        $('caHotels').textContent = (s.hotels_total || 0).toLocaleString();
        $('caStaticCount').textContent = '(' + (s.static_total || 0).toLocaleString() + ' loaded)';
        $('caRegionsCount').textContent = '(' + (s.regions_total || 0).toLocaleString() + ' loaded)';
        $('caPoisCount').textContent = '(' + (s.pois_total || 0).toLocaleString() + ' loaded)';
        $('caLastDiscover').textContent = s.last_discover_at || '—';
        $('caWatermark').textContent = s.last_updated_since || '—';

        const total = (s.queue_total || 0);
        const pending = (s.content_pending || 0) + (s.reviews_pending || 0);
        if (total > 0) {
            const done = Math.max(0, total * 2 - pending);
            const pct = Math.min(100, Math.round((done / (total * 2)) * 100));
            $('caProgressWrap').classList.remove('hidden');
            $('caProgressBar').style.width = pct + '%';
            $('caProgressPct').textContent = pct + '%';
        } else {
            $('caProgressWrap').classList.add('hidden');
        }
    }

    async function refresh() {
        try {
            const r = await call({ action: 'status' }, 30000);
            if (r.success) render(r.data);
        } catch (e) { /* ignore */ }
    }

    async function simpleAction(action, label, verb, timeout) {
        log('info', verb);
        try {
            const r = await call({ action }, timeout || 300000);
            if (r.success) {
                if (r.data && r.data.manual) log('warn', r.data.message);
                else log('success', label + ': ' + JSON.stringify(r.data));
            } else {
                log('error', r.error || 'failed');
            }
        } catch (err) { log('error', err.message); }
        refresh();
    }

    window.caStatic  = e => { e && e.preventDefault(); simpleAction('static',  'hotel/static', 'GET hotel/static — loading bedding / meal / room-amenity / socket / tax labels…'); };
    window.caRegions = e => { e && e.preventDefault(); simpleAction('regions', 'region dump', 'POST hotel/region/dump — downloading + importing regions… (may take a while)', 600000); };
    window.caPois    = e => { e && e.preventDefault(); simpleAction('pois',    'POI dump',    'POST hotel/poi/dump — downloading + importing points of interest…', 600000); };

    window.caDiscover = async function (e) {
        e && e.preventDefault();
        const params = { action: 'discover' };
        const country = csv($('caCountry').value);
        const kind = csv($('caKind').value);
        const serp = csv($('caSerp').value);
        const since = $('caUpdatedSince').value.trim();
        if (country) params.country = country;
        if (kind) params.kind = kind;
        if (serp) params.serp_filter = serp;
        if ($('caUpdatedSince').value !== '') params.updated_since = since;
        log('info', 'POST hotel_ids_by_filter — discovering changed hotels…');
        try {
            const r = await call(params);
            if (r.success) {
                log('success', 'Discovered ' + r.data.queued + ' hotels → queue (unique hids: ' + r.data.unique_hids + ')');
                log('info', 'Watermark advanced to ' + r.data.next_updated_since);
            } else { log('error', r.error || 'failed'); }
        } catch (err) { log('error', err.message); }
        refresh();
    };

    window.caRun = async function (e) {
        e && e.preventDefault();
        if (CA.running) return;
        CA.running = true;
        $('caBtnRun').classList.add('hidden');
        $('caBtnStop').classList.remove('hidden');
        log('info', 'Sync loop started (40s ticks)…');

        while (CA.running) {
            let r;
            try {
                r = await call({ action: 'run', budget: 40, country: csv($('caCountry').value) });
            } catch (err) {
                log('error', err.message);
                await new Promise(x => setTimeout(x, 4000));
                continue;
            }
            if (!r.success) { log('error', r.error || 'run failed'); break; }

            const st = r.data.status || {};
            render(st);
            const c = (r.data.content || []).reduce((a, b) => a + (b.upserted || 0), 0);
            const del = (r.data.content || []).reduce((a, b) => a + (b.deleted || 0), 0);
            const rv = (r.data.reviews || []).reduce((a, b) => a + (b.hotels || 0), 0);
            log('success', 'tick +' + c + ' hotels' + (del ? ' (−' + del + ' closed)' : '') +
                ', +' + rv + ' review sets · pending ' + (st.content_pending || 0) + '/' + (st.reviews_pending || 0));

            if ((st.content_pending || 0) === 0 && (st.reviews_pending || 0) === 0) {
                log('success', '✅ Queue drained.');
                break;
            }
            await new Promise(x => setTimeout(x, 500));
        }
        CA.running = false;
        $('caBtnRun').classList.remove('hidden');
        $('caBtnStop').classList.add('hidden');
    };

    window.caStop = function (e) { e && e.preventDefault(); CA.running = false; log('warn', 'Stopping after current tick…'); };

    // ---- Settings (auto-refresh toggle + price-change threshold) -------------
    function renderSettings(s) {
        if (!s) return;
        $('caAutoSync').checked = !!s.auto_sync_enabled;
        $('caAutoSyncLabel').textContent = s.auto_sync_enabled ? 'On' : 'Off';
        $('caPriceThreshold').value = (s.price_change_threshold != null ? s.price_change_threshold : 2);
        if ($('caBookingCutoff')) $('caBookingCutoff').value = (s.booking_cutoff_minutes != null ? s.booking_cutoff_minutes : 15);
        $('caAutoNext').textContent = s.auto_sync_enabled
            ? ('Next auto refresh due: ' + (s.next_auto_due === 'now' ? 'now (on the next cron run or page load)' : (s.next_auto_due || '—')))
            : '';
    }

    async function caLoadSettings() {
        try {
            const r = await call({ action: 'settings' }, 20000);
            if (r.success) renderSettings(r.data);
        } catch (e) { /* ignore */ }
    }

    window.caSaveSettings = async function (e) {
        e && e.preventDefault();
        const params = {
            action: 'settings',
            auto_sync_enabled: $('caAutoSync').checked ? '1' : '0',
            price_change_threshold: $('caPriceThreshold').value || '2',
            booking_cutoff_minutes: ($('caBookingCutoff') && $('caBookingCutoff').value) || '15',
        };
        try {
            const r = await call(params, 20000);
            if (r.success) {
                renderSettings(r.data);
                const tag = $('caSettingsSaved');
                tag.classList.remove('hidden');
                setTimeout(() => tag.classList.add('hidden'), 2000);
                log('success', 'Settings saved.');
            } else {
                log('error', r.error || 'save failed');
            }
        } catch (err) { log('error', err.message); }
    };

    // Fallback trigger for the 24h auto-refresh when opening this page (the daily
    // cron is the primary driver). The 24h guard lives server-side in autoCheck().
    async function caAutoCheck() {
        try {
            const r = await call({ action: 'autocheck', budget: 40 }, 90000);
            if (r.success && r.data && r.data.ran) {
                log('success', 'Auto refresh ran (24h due). ' +
                    (r.data.error ? ('error: ' + r.data.error) : 'Static data updated.'));
                refresh();
                caLoadSettings();
            }
        } catch (e) { /* ignore — the manual buttons still work */ }
    }

    window.caReset = async function (e) {
        e && e.preventDefault();
        if (!confirm('Clear the sync queue? Stored hotels and reviews are kept.')) return;
        try {
            const r = await call({ action: 'reset' });
            log(r.success ? 'warn' : 'error', r.success ? 'Queue cleared, watermark reset.' : (r.error || 'failed'));
        } catch (err) { log('error', err.message); }
        refresh();
    };

    // Only poll while this tab is visible.
    document.addEventListener('DOMContentLoaded', function () {
        refresh();
        caLoadSettings();
        caAutoCheck();
        CA.poll = setInterval(function () {
            const panel = document.getElementById('panel-contentsync');
            if (panel && !panel.classList.contains('hidden')) refresh();
        }, 3000);
    });
})();
</script>
