<?php
// ============================================================================
// RATEHAWK HOTEL CONTENT SCHEMA — single source of truth
// ============================================================================
// Shared by:
//   - content/import-handler.php   (legacy bulk JSONL dump import)
//   - content/ratehawk-content-sync.php   (Content API incremental sync)
//
// The dump record and the `hotel_content_by_ids` response use the same field
// names, so one mapper serves both.
// ============================================================================

if (!function_exists('rh_hotel_table_ddl')) {

    /**
     * Default values for the admin knobs stored on the ratehawk_content_sync
     * row (id = 1). ONE place — used as the fallback when the settings row can't
     * be read, and as the `??` default in RatehawkContentSync::getSettings().
     * The matching SQL column DEFAULTs live in the ALTER block of ensureTables().
     *   price_change_threshold : max % supplier net may rise at prebook (ETG 3.1)
     *   booking_cutoff_minutes : how long to poll finish/status after a successful
     *                            booking/finish response before failing (ETG 5.2)
     */
    function rh_default_settings()
    {
        return [
            'price_change_threshold' => 2.0,
            'booking_cutoff_minutes' => 15,
        ];
    }

    /**
     * Full CREATE TABLE for ratehawk_hotels.
     * Pass 'ratehawk_hotels' or 'IF NOT EXISTS ratehawk_hotels'.
     */
    function rh_hotel_table_ddl($tableName)
    {
        return "CREATE TABLE {$tableName} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id VARCHAR(100) UNIQUE NOT NULL,
            hid BIGINT UNSIGNED NULL,
            name VARCHAR(500) NOT NULL,
            location VARCHAR(500),
            address TEXT,
            postal_code VARCHAR(30),
            city VARCHAR(200),
            country VARCHAR(200),
            country_code VARCHAR(10),
            region_id VARCHAR(100),
            region_type VARCHAR(50),
            region_iata VARCHAR(10),
            latitude DECIMAL(10, 7),
            longitude DECIMAL(10, 7),
            rating DECIMAL(4, 2),
            rating_count INT NOT NULL DEFAULT 0,
            star_rating INT,
            kind VARCHAR(100),
            hotel_chain VARCHAR(255),
            giata_code VARCHAR(50),
            distance_center INT NULL,
            description TEXT,
            description_struct JSON,
            phone VARCHAR(100),
            email VARCHAR(200),
            website VARCHAR(500),
            check_in_time VARCHAR(20),
            check_in_time_end VARCHAR(20),
            check_out_time VARCHAR(20),
            front_desk_time_start VARCHAR(20),
            front_desk_time_end VARCHAR(20),
            is_gender_specification_required TINYINT(1) NOT NULL DEFAULT 0,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            amenities JSON,
            amenity_groups JSON,
            images JSON,
            images_ext JSON,
            rooms JSON,
            serp_filters JSON,
            metapolicy_extra_info TEXT,
            metapolicy_struct JSON,
            policy_struct JSON,
            payment_methods JSON,
            keys_pickup JSON,
            star_certificate JSON,
            facts JSON,
            content_source VARCHAR(20) NULL,
            content_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hotel_id (hotel_id),
            INDEX idx_hid (hid),
            INDEX idx_location (latitude, longitude),
            INDEX idx_city (city),
            INDEX idx_rating (rating),
            INDEX idx_giata (giata_code),
            INDEX idx_active (is_closed, deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Bring an already-existing ratehawk_hotels table up to the current schema.
     * Safe to call on every run — only adds what is missing.
     */
    function rh_ensure_hotel_columns($dbRef)
    {
        $columns = [
            'hid'                              => "BIGINT UNSIGNED NULL",
            'postal_code'                      => "VARCHAR(30) NULL",
            'region_type'                      => "VARCHAR(50) NULL",
            'region_iata'                      => "VARCHAR(10) NULL",
            'rating_count'                     => "INT NOT NULL DEFAULT 0",
            'hotel_chain'                      => "VARCHAR(255) NULL",
            'giata_code'                       => "VARCHAR(50) NULL",
            'distance_center'                  => "INT NULL",
            'description_struct'               => "JSON NULL",
            'check_in_time_end'                => "VARCHAR(20) NULL",
            'front_desk_time_start'            => "VARCHAR(20) NULL",
            'front_desk_time_end'              => "VARCHAR(20) NULL",
            'is_gender_specification_required' => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_closed'                        => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted'                          => "TINYINT(1) NOT NULL DEFAULT 0",
            'serp_filters'                     => "JSON NULL",
            'amenity_groups'                   => "JSON NULL",
            'images_ext'                       => "JSON NULL",
            'policy_struct'                    => "JSON NULL",
            'keys_pickup'                      => "JSON NULL",
            'star_certificate'                 => "JSON NULL",
            'content_source'                   => "VARCHAR(20) NULL",
            'content_synced_at'                => "TIMESTAMP NULL",
        ];

        foreach ($columns as $column => $definition) {
            try {
                $exists = $dbRef->query("SHOW COLUMNS FROM ratehawk_hotels LIKE '{$column}'")->fetch();
                if (!$exists) {
                    $dbRef->query("ALTER TABLE ratehawk_hotels ADD COLUMN {$column} {$definition}");
                }
            } catch (\Throwable $e) {
                error_log("RateHawk schema: add column {$column} failed: " . $e->getMessage());
            }
        }

        // Guest ratings from the reviews sync are on a 0-10 scale; DECIMAL(3,2)
        // cannot hold 10.00. Widen an older column in place.
        try {
            $ratingCol = $dbRef->query("SHOW COLUMNS FROM ratehawk_hotels LIKE 'rating'")->fetch();
            if ($ratingCol && stripos($ratingCol['Type'] ?? '', 'decimal(3,2)') !== false) {
                $dbRef->query("ALTER TABLE ratehawk_hotels MODIFY COLUMN rating DECIMAL(4,2)");
            }
        } catch (\Throwable $e) {
            error_log("RateHawk schema: widen rating failed: " . $e->getMessage());
        }

        $indexes = [
            'idx_hid'    => 'hid',
            'idx_giata'  => 'giata_code',
            'idx_active' => 'is_closed, deleted',
        ];
        foreach ($indexes as $idxName => $idxCols) {
            try {
                $idx = $dbRef->query("SHOW INDEX FROM ratehawk_hotels WHERE Key_name = '{$idxName}'")->fetch();
                if (!$idx) {
                    $dbRef->query("ALTER TABLE ratehawk_hotels ADD INDEX {$idxName} ({$idxCols})");
                }
            } catch (\Throwable $e) {
                error_log("RateHawk schema: add index {$idxName} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Map one raw hotel record (dump line OR hotel_content_by_ids item) to a
     * ratehawk_hotels row. $source is 'dump' or 'api' — stored for traceability.
     */
    function rh_map_hotel_row(array $hotel, $source = 'dump')
    {
        // Full description: keep every titled section, not just the first block.
        $descParts  = [];
        $descStruct = is_array($hotel['description_struct'] ?? null) ? $hotel['description_struct'] : [];
        foreach ($descStruct as $section) {
            $title = trim((string)($section['title'] ?? ''));
            $paras = $section['paragraphs'] ?? [];
            if (!is_array($paras) || !$paras) continue;
            $body = implode("\n\n", $paras);
            $descParts[] = $title !== '' ? ($title . "\n" . $body) : $body;
        }
        $description = implode("\n\n", $descParts);

        // Flatten amenity_groups -> unique amenity-name list (what details.php reads).
        $amenityNames  = [];
        $amenityGroups = is_array($hotel['amenity_groups'] ?? null) ? $hotel['amenity_groups'] : [];
        foreach ($amenityGroups as $g) {
            foreach (($g['amenities'] ?? []) as $a)          $amenityNames[] = $a;
            foreach (($g['non_free_amenities'] ?? []) as $a) $amenityNames[] = $a;
        }
        $amenityNames = array_values(array_unique(array_filter($amenityNames, 'is_string')));

        // The bulk dump gives a plain `images` array AND `images_ext`. The Content
        // API's hotel_content_by_ids only ever returns `images_ext` — no `images`
        // key at all. Without this fallback, every API-synced hotel stored an empty
        // images array (and showed no photos) even though images_ext had them.
        $imagesExt = is_array($hotel['images_ext'] ?? null) ? $hotel['images_ext'] : [];
        $images    = is_array($hotel['images'] ?? null) ? $hotel['images'] : [];
        if (empty($images) && !empty($imagesExt)) {
            $images = array_values(array_filter(array_map(function ($e) {
                return is_array($e) ? ($e['url'] ?? null) : null;
            }, $imagesExt)));
        }

        $region = is_array($hotel['region'] ?? null) ? $hotel['region'] : [];

        $row = [
            'hotel_id'       => $hotel['id'] ?? ($hotel['hid'] ?? null),
            'hid'            => isset($hotel['hid']) ? (int)$hotel['hid'] : null,
            'name'           => mb_substr($hotel['name'] ?? '', 0, 500),
            'location'       => mb_substr($hotel['location'] ?? '', 0, 500),
            'address'        => $hotel['address'] ?? '',
            'postal_code'    => mb_substr((string)($hotel['postal_code'] ?? ''), 0, 30),
            'city'           => mb_substr($region['name'] ?? '', 0, 200),
            'country'        => mb_substr($region['country_name'] ?? '', 0, 200),
            'country_code'   => mb_substr($region['country_code'] ?? '', 0, 10),
            'region_id'      => mb_substr((string)($region['id'] ?? ''), 0, 100),
            'region_type'    => mb_substr($region['type'] ?? '', 0, 50),
            'region_iata'    => mb_substr($region['iata'] ?? '', 0, 10),
            'latitude'       => $hotel['latitude'] ?? null,
            'longitude'      => $hotel['longitude'] ?? null,
            'star_rating'    => $hotel['star_rating'] ?? null,
            'kind'           => mb_substr($hotel['kind'] ?? '', 0, 100),
            'hotel_chain'    => mb_substr((string)($hotel['hotel_chain'] ?? ''), 0, 255),
            'giata_code'     => mb_substr((string)($hotel['giata_code'] ?? ''), 0, 50),
            'distance_center'=> isset($hotel['distance_center']) && is_numeric($hotel['distance_center'])
                                    ? (int)round($hotel['distance_center']) : null,
            'description'    => $description,
            'description_struct' => json_encode($descStruct),
            'phone'          => mb_substr($hotel['phone'] ?? '', 0, 100),
            'email'          => mb_substr($hotel['email'] ?? '', 0, 200),
            'website'        => mb_substr($hotel['homepage'] ?? '', 0, 500),
            'check_in_time'      => mb_substr($hotel['check_in_time'] ?? '', 0, 20),
            'check_in_time_end'  => mb_substr($hotel['check_in_time_end'] ?? '', 0, 20),
            'check_out_time'     => mb_substr($hotel['check_out_time'] ?? '', 0, 20),
            'front_desk_time_start' => mb_substr($hotel['front_desk_time_start'] ?? '', 0, 20),
            'front_desk_time_end'   => mb_substr($hotel['front_desk_time_end'] ?? '', 0, 20),
            'is_gender_specification_required' => !empty($hotel['is_gender_specification_required']) ? 1 : 0,
            'is_closed'      => !empty($hotel['is_closed']) ? 1 : 0,
            'deleted'        => 0,
            'amenities'      => json_encode($amenityNames),
            'amenity_groups' => json_encode($amenityGroups),
            'images'         => json_encode($images),
            'images_ext'     => json_encode($imagesExt),
            'rooms'          => json_encode($hotel['room_groups'] ?? []),
            'serp_filters'   => json_encode($hotel['serp_filters'] ?? []),
            'metapolicy_extra_info' => $hotel['metapolicy_extra_info'] ?? '',
            'metapolicy_struct'     => json_encode($hotel['metapolicy_struct'] ?? []),
            'policy_struct'  => json_encode($hotel['policy_struct'] ?? []),
            'payment_methods'=> json_encode($hotel['payment_methods'] ?? []),
            'keys_pickup'    => json_encode($hotel['keys_pickup'] ?? []),
            'star_certificate' => json_encode($hotel['star_certificate'] ?? null),
            'facts'          => json_encode($hotel['facts'] ?? []),
            'content_source' => $source === 'api' ? 'api' : 'dump',
            'content_synced_at' => date('Y-m-d H:i:s'),
        ];

        // The dump carries a guest score; the API does not. Only set it when present
        // so an API sync never wipes a rating the reviews sync computed.
        if (isset($hotel['rating']) && is_numeric($hotel['rating'])) {
            $row['rating'] = (float)$hotel['rating'];
        }

        return $row;
    }

    /**
     * Human label for a coded static value (room amenity slug, socket letter,
     * bedding/meal name, serp filter, tax) using the ratehawk_static table
     * populated by the Content API sync's loadStaticData(). Falls back to a
     * prettified slug when the dictionary is missing or has no entry.
     *
     * @param \Medoo\Medoo $db
     */
    function rh_static_label($db, $category, $code, $lang = 'en')
    {
        static $cache = [];
        $code = (string) $code;
        if ($code === '') return '';
        $ck = $category . '|' . $lang;

        if (!isset($cache[$ck])) {
            $cache[$ck] = [];
            try {
                $rows = $db->select('ratehawk_static', ['name', 'label_en', 'locales'], ['category' => $category]);
                foreach (($rows ?: []) as $r) {
                    $loc = is_array($r['locales']) ? $r['locales'] : (json_decode($r['locales'] ?? '[]', true) ?: []);
                    $cache[$ck][$r['name']] = $loc[$lang] ?? ($r['label_en'] ?? null);
                }
            } catch (\Throwable $e) {
                // table not created yet — fall through to prettified slug
            }
        }

        if (!empty($cache[$ck][$code])) return $cache[$ck][$code];
        return ucfirst(str_replace(['-', '_'], ' ', $code));
    }

    /**
     * Translate a plain-English amenity phrase (as it appears verbatim in
     * amenity_groups.amenities on a hotel, e.g. "24-hour reception",
     * "Free off-site parking") into another language, using the
     * `hotel_amenity` rows loadStaticData() extracts from hotel/static's
     * amenity_translations[].amenities[]. English in, English back if there's
     * no translation or the table isn't loaded yet — never returns empty.
     *
     * @param \Medoo\Medoo $db
     */
    function rh_translate_amenity($db, $englishPhrase, $lang = 'en')
    {
        static $cache = [];
        $englishPhrase = trim((string) $englishPhrase);
        if ($englishPhrase === '' || $lang === 'en') return $englishPhrase;

        if (!isset($cache[$lang])) {
            $cache[$lang] = [];
            try {
                $rows = $db->select('ratehawk_static', ['name', 'locales'], ['category' => 'hotel_amenity']);
                foreach (($rows ?: []) as $r) {
                    $loc = is_array($r['locales']) ? $r['locales'] : (json_decode($r['locales'] ?? '[]', true) ?: []);
                    if (!empty($loc[$lang])) $cache[$lang][$r['name']] = $loc[$lang];
                }
            } catch (\Throwable $e) {
                // table not created yet — fall through to the English phrase
            }
        }

        return $cache[$lang][$englishPhrase] ?? $englishPhrase;
    }

    /**
     * ETG Integration Requirements 3.7 — cancellation_penalties.policies can
     * have up to three time layers (free -> partial penalty -> full penalty).
     * ETG always returns these times in UTC+0, so every formatted string here
     * says "UTC" explicitly, per their 23.09.2026 request.
     *
     * @param array $cancellationPenalties rate.payment_options.cancellation_penalties
     * @param string $currency display currency code for the amount text
     * @return array{
     *   free_cancellation_before: ?string,
     *   free_cancellation_before_label: ?string,
     *   has_free_cancellation: bool,
     *   layers: array<int,array{from:?string,to:?string,from_label:?string,to_label:?string,amount:float,is_free:bool}>,
     *   summary: array<int,string>,
     *   text: string
     * }
     */
    function rh_format_utc_datetime($isoDateTime)
    {
        if (empty($isoDateTime)) {
            return null;
        }
        try {
            $dt = new \DateTime($isoDateTime, new \DateTimeZone('UTC'));
            return $dt->format('d M Y, H:i') . ' UTC';
        } catch (\Throwable $e) {
            return rtrim((string) $isoDateTime) . ' UTC';
        }
    }

    function rh_parse_cancellation_policy($cancellationPenalties, $currency = '')
    {
        $cancellationPenalties = is_array($cancellationPenalties) ? $cancellationPenalties : [];
        $policies = is_array($cancellationPenalties['policies'] ?? null) ? $cancellationPenalties['policies'] : [];
        $freeCancellationBefore = $cancellationPenalties['free_cancellation_before'] ?? null;

        $layers = [];
        foreach ($policies as $p) {
            if (!is_array($p)) {
                continue;
            }
            $from = $p['start_at'] ?? null;
            $to   = $p['end_at'] ?? null;
            $amountShow = round((float) ($p['amount_show'] ?? 0), 2);
            $layers[] = [
                'from'       => $from,
                'to'         => $to,
                'from_label' => rh_format_utc_datetime($from),
                'to_label'   => rh_format_utc_datetime($to),
                'amount'     => $amountShow,
                'is_free'    => $amountShow <= 0,
            ];
        }

        $summary = [];
        foreach ($layers as $l) {
            if ($l['is_free']) {
                $summary[] = $l['to_label']
                    ? "Free cancellation until {$l['to_label']}"
                    : 'Free cancellation';
                continue;
            }
            $amountText = number_format($l['amount'], 2) . ($currency ? " {$currency}" : '');
            if ($l['to_label']) {
                // partial-penalty window between two dates
                $range = $l['from_label'] ? "{$l['from_label']} – {$l['to_label']}" : "until {$l['to_label']}";
                $summary[] = "{$range}: cancellation fee {$amountText}";
            } elseif ($l['from_label']) {
                // final layer, no upper bound = full penalty from here on
                $summary[] = "After {$l['from_label']}: full cancellation fee {$amountText}";
            } else {
                // no start or end bound at all = the fee applies for the whole
                // stay, from the moment of booking — i.e. non-refundable.
                $summary[] = "Non-refundable — cancellation fee {$amountText} applies immediately.";
            }
        }
        if (empty($summary)) {
            $summary[] = empty($freeCancellationBefore)
                ? 'Non-refundable — cancellation fee applies immediately (no free cancellation window).'
                : '';
        }

        return [
            'free_cancellation_before'       => $freeCancellationBefore,
            'free_cancellation_before_label' => rh_format_utc_datetime($freeCancellationBefore),
            'has_free_cancellation'          => !empty($freeCancellationBefore),
            'layers'                         => $layers,
            'summary'                        => array_values(array_filter($summary)),
            'text'                           => implode(' • ', array_values(array_filter($summary))),
        ];
    }

    /**
     * ETG Integration Requirements 3.5 — taxes/fees with included_by_supplier
     * = false are NOT part of the room price and must be shown to the guest
     * separately (search results, booking page, confirmation). Taxes with
     * included_by_supplier = true are already inside amount/show_amount and
     * are not returned here.
     *
     * @param array $paymentType one entry of rate.payment_options.payment_types
     * @return array<int,array{name:string,amount:float,currency_code:string}>
     */
    function rh_extract_excluded_taxes($paymentType)
    {
        $taxes = $paymentType['tax_data']['taxes'] ?? null;
        if (!is_array($taxes)) {
            return [];
        }

        $out = [];
        foreach ($taxes as $tax) {
            if (!is_array($tax) || !empty($tax['included_by_supplier'])) {
                continue;
            }
            $amount = (float) ($tax['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $out[] = [
                'name'          => (string) ($tax['name'] ?? 'tax'),
                'amount'        => round($amount, 2),
                'currency_code' => strtoupper((string) ($tax['currency_code'] ?? '')),
            ];
        }
        return $out;
    }
}
