<?php
// ============================================================================
// FILE: app/routes/stays/destination-suggestion.php
// ============================================================================

// ========================================================= hotels-destination-suggestion
// Searches both locations (cities) AND hotels (stays table + API module tables)
$router->post('/hotels-destination-suggestion', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    // CORS
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (str_starts_with($origin, 'http://localhost') || str_starts_with($origin, 'http://127.0.0.1')) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (empty($query) || strlen($query) < 2) {
        echo json_encode([]);
        exit(0);
    }

    // Words of the query, matched in any order. A guest typing "dubai airport"
    // means "Dubai International Airport", which contains both words but not
    // that exact phrase, so phrase matching returned nothing. Every word must
    // still appear, so results stay specific; a one-word query matches exactly
    // as it did before.
    $lower = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $queryWords = array_values(array_unique(array_filter(
        preg_split('/\s+/u', $lower) ?: [],
        static function ($word) {
            return $word !== '' && (function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word)) >= 2;
        }
    )));
    $queryWords = array_slice($queryWords ?: [$lower], 0, 5);
    $multiWord = count($queryWords) > 1;

    // SQL for "every word matches" against one column, with the user's own %
    // and _ escaped so they match literally.
    //
    // With several words, each must START a word in the name: "dubai inte"
    // offers International and Intercontinental, but not "Fountain" or
    // "Mina", which a plain contains-match pulled in for short tokens. A single
    // word keeps the original contains-match so existing results are unchanged.
    //
    // Returns a Medoo where fragment. Each word is its own "AND #wordN" group, and
    // Medoo ORs the patterns listed under one [~] key. Patterns always carry an
    // explicit %, so Medoo uses them as written rather than wrapping them again.
    $wordCondition = static function (string $column, array $words, bool $wordStart): array {
        $where = [];
        foreach (array_values($words) as $i => $word) {
            $escaped = addcslashes($word, '%_\\');
            $where['AND #word' . $i] = [
                $column . '[~]' => $wordStart
                    ? [$escaped . '%', '% ' . $escaped . '%', '%-' . $escaped . '%', '%(' . $escaped . '%']
                    : ['%' . $escaped . '%'],
            ];
        }
        return $where;
    };

    // Rows whose name starts with what was typed come first, then everything
    // else, each group A–Z, up to $limit rows. Medoo's ORDER can only sort by a
    // column, not by "name LIKE 'x%'", so this runs as two plain selects —
    // starts-with, then the rest to fill the remaining slots — which returns
    // exactly the rows and order a single prefix-ranked query would.
    $prefixFirst = static function (callable $select, string $column, array $where, string $prefix, int $limit): array {
        $first = $select(array_merge($where, [
            'AND #prefix' => [$column . '[~]' => $prefix],
            'ORDER' => [$column => 'ASC'],
            'LIMIT' => $limit,
        ])) ?: [];

        if (count($first) >= $limit) {
            return $first;
        }

        $rest = $select(array_merge($where, [
            'AND #prefix' => [$column . '[!~]' => $prefix],
            'ORDER' => [$column => 'ASC'],
            'LIMIT' => $limit - count($first),
        ])) ?: [];

        return array_merge($first, $rest);
    };

    $hotelResults    = [];
    $locationResults = [];
    // Supplier areas: real Hotelbeds Atlas destinations and zones. The app's own
    // `locations` table only knows city names it was seeded with, so a guest looking
    // for "Maldives" was offered a handful of small islands and never the Maldives
    // destination itself or its atolls — the places the supplier actually sells by.
    $areaResults     = [];

    // ============================================================================
    // API MODULE TABLE MAPPING
    // Har module ke liye table aur columns define karo
    // ============================================================================
    $apiModuleTables = [
        'hotelbeds' => [
            'table'    => 'hotelbeds_hotels',
            'id_col'   => 'hotel_code',
            'name_col' => 'name',
            'city_col' => 'city',
            // Atlas hierarchy: country → destination → zone → hotel. Optional per
            // module; a module without these keys simply contributes hotels only.
            'destination_table' => 'hotelbeds_destinations',
            'zone_table'        => 'hotelbeds_zones',
            'country_table'     => 'hotelbeds_countries',
        ],
        // Future modules yahan add ho sakte hain:
        // 'juniper' => [
        //     'table'    => 'juniper_hotels',
        //     'id_col'   => 'hotel_id',
        //     'name_col' => 'hotel_name',
        //     'city_col' => 'city_name',
        // ],
    ];

    // ============================================================================
    // SEARCH 1: HOTELS FROM STAYS TABLE (local hotels)
    // ============================================================================
    try {
        $hotels = $db->select(
            'stays',
            ['id', 'name', 'location'],
            [
                'name[~]' => '%' . $query . '%',
                'ORDER'   => ['name' => 'ASC'],
                'LIMIT'   => 10
            ]
        );

        if ($hotels && is_array($hotels)) {
            foreach ($hotels as $hotel) {
                $hotelResults[] = [
                    'id'          => $hotel['id'],
                    'type'        => 'hotel',
                    'lc'          => 'hotel',
                    'name'        => $hotel['name'],
                    'airportname' => $hotel['name'],
                    'cityname'    => $hotel['location'] ?? '',
                    'countryname' => '',
                    'supplier'    => 'hotels',
                    'is_hotel'    => true
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Stays hotels search error: ' . $e->getMessage());
    }

    // ============================================================================
    // SEARCH 2: LOCATIONS (CITIES)
    // ============================================================================
    try {
        $locationWhere = [];
        foreach ($queryWords as $i => $word) {
            // Medoo needs a distinct key per OR group; the #comment makes it unique.
            $locationWhere['OR #word' . $i] = [
                'city[~]'    => $word,
                'country[~]' => $word,
            ];
        }

        $locations = $db->select(
            'locations',
            ['id', 'country', 'country_code', 'city', 'latitude', 'longitude'],
            [
                'AND' => $locationWhere,
                'status' => 1,
                'ORDER'  => [
                    'country' => 'ASC',
                    'city'    => 'ASC'
                ],
                'LIMIT'  => 10
            ]
        );

        if ($locations && is_array($locations)) {
            foreach ($locations as $location) {
                $locationResults[] = [
                    'id'          => $location['id'],
                    'type'        => 'location',
                    'lc'          => 'city',
                    'name'        => $location['city'],
                    'airportname' => $location['city'],
                    'cityname'    => $location['city'],
                    'countryname' => $location['country'],
                    'countrycode' => $location['country_code'],
                    'is_hotel'    => false
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Locations search error: ' . $e->getMessage());
    }

    // ============================================================================
    // SEARCH 3: HOTELS FROM ACTIVE API MODULES
    // Active modules modules table se Medoo ke through fetch hote hain
    // ============================================================================
    try {
        $activeModules = $db->select(
            'modules',
            ['name', 'host', 'database', 'username', 'password'],
            [
                'name'   => array_keys($apiModuleTables),
                'type'   => 'stays',
                'status' => 1
            ]
        );

        if ($activeModules && is_array($activeModules)) {
            foreach ($activeModules as $module) {
                $moduleName = $module['name'] ?? '';

                // Sirf wo modules jinke liye table mapping defined hai
                if (!isset($apiModuleTables[$moduleName])) {
                    continue;
                }

                if (empty($module['host']) || empty($module['database']) || empty($module['username'])) {
                    error_log(ucfirst($moduleName) . ' search skipped: database configuration is incomplete');
                    continue;
                }

                $tableConfig = $apiModuleTables[$moduleName];
                $table       = $tableConfig['table'];
                $idCol       = $tableConfig['id_col'];
                $nameCol     = $tableConfig['name_col'];
                $cityCol     = $tableConfig['city_col'];

                try {
                    // Short connect timeout — without this, a slow/unreachable Hotelbeds
                    // DB host blocks autocomplete for ~30s (default MySQL TCP wait).
                    $dsn = sprintf(
                        'mysql:host=%s;dbname=%s;charset=utf8mb4',
                        $module['host'],
                        $module['database']
                    );
                    $modulePdo = new \PDO($dsn, $module['username'], $module['password'] ?? '', [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_TIMEOUT => 3,
                    ]);
                    $moduleDb = new \Medoo\Medoo([
                        'type' => 'mysql',
                        'pdo' => $modulePdo,
                    ]);

                    $moduleHotels = $moduleDb->select(
                        $table,
                        [$idCol, $nameCol, $cityCol],
                        [
                            $nameCol . '[~]' => $query . '%',
                            'ORDER'          => [$nameCol => 'ASC'],
                            'LIMIT'          => 10
                        ]
                    );

                    if ($moduleHotels && is_array($moduleHotels)) {
                        foreach ($moduleHotels as $hotel) {
                            $hotelResults[] = [
                                'id'          => $hotel[$idCol],
                                'type'        => 'hotel',
                                'lc'          => 'hotel',
                                'name'        => $hotel[$nameCol],
                                'airportname' => $hotel[$nameCol],
                                'cityname'    => $hotel[$cityCol] ?? '',
                                'countryname' => '',
                                'supplier'    => $moduleName,
                                'is_hotel'    => true
                            ];
                        }
                    }
                    // Multi-word queries also look inside hotel names ("dubai airport" ->
                    // "Holiday Inn Express Dubai Airport"). A contains-match over the whole
                    // hotels table is a full scan of several GB per keystroke, so it is
                    // never run unscoped: words that name a destination narrow the search
                    // through the indexed destination_code, and only the remaining words
                    // have to appear in the hotel name.
                    if ($multiWord && !empty($tableConfig['destination_table']) && count($hotelResults) < 10) {
                        // Which run of words is a destination name? Every contiguous
                        // 1–3 word run is checked by exact name, so "abu dhabi airport"
                        // finds Abu Dhabi and "dubai al" finds Dubai. An exact IN() list
                        // uses the name index and cannot be crowded out the way a
                        // LIMITed prefix scan was (dozens of "Al…" destinations hid Dubai).
                        $runs = [];
                        $wordCount = count($queryWords);
                        for ($len = 1; $len <= min(3, $wordCount); $len++) {
                            for ($i = 0; $i + $len <= $wordCount; $i++) {
                                $runs[] = implode(' ', array_slice($queryWords, $i, $len));
                            }
                        }
                        $runs = array_values(array_unique($runs));

                        $candidateRows = $moduleDb->select(
                            $tableConfig['destination_table'],
                            ['code', 'name'],
                            ['name' => $runs]
                        ) ?: [];

                        $scopeCodes = [];
                        $placeWords = [];
                        foreach ($candidateRows as $dRow) {
                            $dName = function_exists('mb_strtolower')
                                ? mb_strtolower(trim((string) $dRow['name']), 'UTF-8')
                                : strtolower(trim((string) $dRow['name']));
                            if ($dName === '') {
                                continue;
                            }
                            $scopeCodes[] = strtoupper(trim((string) $dRow['code']));
                            foreach (preg_split('/\s+/u', $dName) ?: [] as $w) {
                                $placeWords[$w] = true;
                            }
                        }
                        $nameWords = array_values(array_filter($queryWords, static function ($word) use ($placeWords) {
                            return !isset($placeWords[$word]);
                        }));
                        $scopeCodes = array_values(array_unique(array_filter($scopeCodes)));

                        if ($scopeCodes && $nameWords) {
                            $scopedHotels = $moduleDb->select(
                                $table,
                                [$idCol, $nameCol, $cityCol],
                                array_merge(
                                    ['destination_code' => $scopeCodes],
                                    $wordCondition($nameCol, $nameWords, true),
                                    ['ORDER' => [$nameCol => 'ASC'], 'LIMIT' => 10]
                                )
                            ) ?: [];

                            $seenHotels = [];
                            foreach ($hotelResults as $existing) {
                                $seenHotels[(string) $existing['id']] = true;
                            }
                            foreach ($scopedHotels as $hotel) {
                                if (count($hotelResults) >= 10 || isset($seenHotels[(string) $hotel[$idCol]])) {
                                    continue;
                                }
                                $seenHotels[(string) $hotel[$idCol]] = true;
                                $hotelResults[] = [
                                    'id'          => $hotel[$idCol],
                                    'type'        => 'hotel',
                                    'lc'          => 'hotel',
                                    'name'        => $hotel[$nameCol],
                                    'airportname' => $hotel[$nameCol],
                                    'cityname'    => $hotel[$cityCol] ?? '',
                                    'countryname' => '',
                                    'supplier'    => $moduleName,
                                    'is_hotel'    => true
                                ];
                            }
                        }
                    }

                    // ====================================================================
                    // SUPPLIER AREAS — Atlas destinations and zones
                    // Matched three ways, because a guest types whichever name they
                    // know: the destination ("Dubai"), a zone inside it ("South Male
                    // Atoll"), or the country ("Maldives" → its destination and every
                    // atoll under it, which is what hotelbeds.com itself lists).
                    // ====================================================================
                    if (!empty($tableConfig['destination_table'])) {
                        // Prefix for ranking, contains for reach: a guest who types
                        // "male atoll" still finds "South Male Atoll", while "dubai"
                        // still lists Dubai before anything merely containing it.
                        // Safe as a scan — the Atlas name tables are small next to the
                        // hotels table, which is never searched this way.
                        $like = $query . '%';
                        $anywhere = '%' . $query . '%';
                        $areas = [];

                        // Countries whose name the guest typed — used to pull the whole
                        // country's inventory in, not just literal name matches.
                        $countryCodes = [];
                        if (!empty($tableConfig['country_table'])) {
                            $cStmt = $modulePdo->prepare(
                                'SELECT code, name FROM ' . $tableConfig['country_table']
                                . ' WHERE name LIKE :like ORDER BY CHAR_LENGTH(name) ASC LIMIT 3'
                            );
                            $cStmt->execute([':like' => $like]);
                            foreach ($cStmt->fetchAll() as $cRow) {
                                $cCode = strtoupper(trim((string) ($cRow['code'] ?? '')));
                                if ($cCode !== '') {
                                    $countryCodes[] = $cCode;
                                }
                            }
                        }

                        // Name matches the words, OR the guest typed the destination code,
                        // OR the destination is in a country whose name they typed.
                        $destinationMatch = [
                            'AND #words' => $wordCondition('name', $queryWords, $multiWord),
                            'code' => strtoupper($query),
                        ];
                        if ($countryCodes) {
                            $destinationMatch['country_code'] = $countryCodes;
                        }

                        $destinationRows = $prefixFirst(
                            static function (array $where) use ($moduleDb, $tableConfig) {
                                return $moduleDb->select(
                                    $tableConfig['destination_table'],
                                    ['code', 'name', 'country_code'],
                                    $where
                                );
                            },
                            'name',
                            ['OR #match' => $destinationMatch, 'name[!]' => ''],
                            $like,
                            12
                        );
                        foreach ($destinationRows as $row) {
                            $code = strtoupper(trim((string) ($row['code'] ?? '')));
                            if ($code === '') {
                                continue;
                            }
                            $areas['D|' . $code] = [
                                'kind' => 'destination',
                                'code' => $code,
                                'destination_code' => $code,
                                'name' => (string) $row['name'],
                                'parent' => '',
                                'country_code' => strtoupper(trim((string) ($row['country_code'] ?? ''))),
                            ];
                        }

                        if (!empty($tableConfig['zone_table'])) {
                            // Zone name matches the words, OR the zone is in a country
                            // whose name was typed.
                            $zoneWhere = $countryCodes
                                ? ['OR #match' => [
                                    'AND #words' => $wordCondition('z.name', $queryWords, $multiWord),
                                    'z.country_code' => $countryCodes,
                                ]]
                                : $wordCondition('z.name', $queryWords, $multiWord);

                            $zoneRows = $prefixFirst(
                                static function (array $where) use ($moduleDb, $tableConfig) {
                                    return $moduleDb->select(
                                        $tableConfig['zone_table'] . ' (z)',
                                        ['[>]' . $tableConfig['destination_table'] . ' (d)' => ['z.destination_code' => 'code']],
                                        ['z.code', 'z.destination_code', 'z.country_code', 'z.name', 'd.name(destination_name)'],
                                        $where
                                    );
                                },
                                'z.name',
                                array_merge($zoneWhere, ['z.name[!]' => '']),
                                $like,
                                25
                            );
                            foreach ($zoneRows as $row) {
                                $zDest = strtoupper(trim((string) ($row['destination_code'] ?? '')));
                                $zCode = strtoupper(trim((string) ($row['code'] ?? '')));
                                if ($zDest === '' || $zCode === '') {
                                    continue;
                                }
                                $areas['Z|' . $zDest . '|' . $zCode] = [
                                    'kind' => 'zone',
                                    'code' => $zCode,
                                    'destination_code' => $zDest,
                                    'name' => (string) $row['name'],
                                    'parent' => (string) ($row['destination_name'] ?? ''),
                                    'country_code' => strtoupper(trim((string) ($row['country_code'] ?? ''))),
                                ];
                            }
                        }

                        // Inventory sizes, in one grouped pass. Narrowed by the indexed
                        // destination_code, so it stays cheap enough for a keystroke.
                        $countsByDest = [];
                        $countsByZone = [];
                        $lookupDestCodes = array_values(array_unique(array_map(static function ($area) {
                            return $area['destination_code'];
                        }, $areas)));

                        if (!empty($lookupDestCodes)) {
                            $placeholders = implode(',', array_fill(0, count($lookupDestCodes), '?'));
                            $countStmt = $modulePdo->prepare(
                                'SELECT destination_code, zone_code, COUNT(*) AS total FROM ' . $table
                                . ' WHERE destination_code IN (' . $placeholders . ')'
                                . ' GROUP BY destination_code, zone_code'
                            );
                            $countStmt->execute($lookupDestCodes);
                            foreach ($countStmt->fetchAll() as $cRow) {
                                $cDest = strtoupper(trim((string) ($cRow['destination_code'] ?? '')));
                                $cZone = strtoupper(trim((string) ($cRow['zone_code'] ?? '')));
                                $cTotal = (int) ($cRow['total'] ?? 0);
                                $countsByDest[$cDest] = ($countsByDest[$cDest] ?? 0) + $cTotal;
                                $countsByZone[$cDest . '|' . $cZone] = $cTotal;
                            }
                        }

                        // Country names for the label ("South Male Atoll, Maldives").
                        $countryNames = [];
                        $areaCountryCodes = array_values(array_filter(array_unique(array_map(static function ($area) {
                            return $area['country_code'];
                        }, $areas))));
                        if (!empty($areaCountryCodes) && !empty($tableConfig['country_table'])) {
                            $placeholders = implode(',', array_fill(0, count($areaCountryCodes), '?'));
                            $nameStmt = $modulePdo->prepare(
                                'SELECT code, name FROM ' . $tableConfig['country_table']
                                . ' WHERE code IN (' . $placeholders . ')'
                            );
                            $nameStmt->execute($areaCountryCodes);
                            foreach ($nameStmt->fetchAll() as $nRow) {
                                $countryNames[strtoupper(trim((string) $nRow['code']))] = (string) $nRow['name'];
                            }
                        }

                        foreach ($areas as $area) {
                            $destTotal = $countsByDest[$area['destination_code']] ?? 0;
                            $count = $area['kind'] === 'zone'
                                ? ($countsByZone[$area['destination_code'] . '|' . $area['code']] ?? 0)
                                : $destTotal;

                            // An area we hold no hotels for is a dead end for the guest.
                            if ($count <= 0) {
                                continue;
                            }

                            $parent = trim($area['parent']);
                            $sameNameAsDestination = $parent !== '' && strcasecmp($parent, $area['name']) === 0;

                            // Atlas often carries a "main" zone named after its own
                            // destination — Sharjah/Sharjah, Dubai/Dubai. Offering it
                            // beside the destination is only worth it when it actually
                            // narrows something: the Dubai zone is 484 of Dubai's 840
                            // hotels (the city core, without Deira, Jumeirah, Marina),
                            // while the Sharjah zone holds all 80 of Sharjah's. When the
                            // zone covers the whole destination the two rows are the same
                            // search with two labels, so drop the duplicate.
                            if (
                                $area['kind'] === 'zone'
                                && $sameNameAsDestination
                                && isset($areas['D|' . $area['destination_code']])
                                && $count >= $destTotal
                            ) {
                                continue;
                            }

                            if ($sameNameAsDestination) {
                                $parent = '';
                            }

                            $areaResults[] = [
                                'id'          => $moduleName . ':' . $area['kind'] . ':'
                                    . $area['destination_code'] . ':' . $area['code'],
                                'type'        => 'location',
                                'lc'          => $area['kind'] === 'zone' ? 'area' : 'city',
                                'name'        => $area['name'],
                                'airportname' => $area['name'],
                                'cityname'    => $parent,
                                'countryname' => $countryNames[$area['country_code']] ?? '',
                                'countrycode' => $area['country_code'],
                                'hotel_count' => $count,
                                // Exact Atlas coordinates of the pick. Sent back on
                                // search so the supplier is queried by code instead of
                                // re-guessing from the name — the only way a zone named
                                // after its destination ("Dubai" the area vs "Dubai" the
                                // destination) can resolve to two different searches,
                                // since both slug to the same URL.
                                'destination_code' => $area['destination_code'],
                                'zone_code'   => $area['kind'] === 'zone' ? $area['code'] : '',
                                'supplier'    => $moduleName,
                                'is_hotel'    => false,
                            ];
                        }
                    }
                } catch (Exception $moduleEx) {
                    error_log('Module DB search error [' . $moduleName . ']: ' . $moduleEx->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log('Active modules fetch error: ' . $e->getMessage());
    }

    // ============================================================================
    // GRNConnect catalog cities and hotels (content database on the module row).
    // A city pick carries city_code so search can query that destination.
    // ============================================================================
    try {
        $grnModule = $db->get('modules', ['host', 'database', 'username', 'password', 'status', 'active'], [
            'name' => 'grnconnect',
            'type' => 'stays',
            'status' => 1,
            'active' => 1,
        ]);
        if ($grnModule && !empty($grnModule['database'])) {
            require_once dirname(__DIR__, 3) . '/modules/stays/grnconnect/content-db.php';
            $contentDb = function_exists('grnconnect_content_db') ? grnconnect_content_db($grnModule) : null;
            if ($contentDb && isset($contentDb->pdo)) {
                $pdo = $contentDb->pdo;
                $prefix = addcslashes($query, '%_\\') . '%';
                $cities = function_exists('grnconnect_autocomplete_cities')
                    ? grnconnect_autocomplete_cities($pdo, $query, $prefix, 12)
                    : [];
                foreach ($cities as $city) {
                    $cityCode = trim((string)($city['city_code'] ?? ''));
                    if ($cityCode === '') {
                        continue;
                    }
                    $label = $city['search_name'] ?: ($city['name'] ?? $cityCode);
                    $areaResults[] = [
                        'id' => $cityCode,
                        'type' => 'city',
                        'lc' => 'city',
                        'name' => $label,
                        'airportname' => $label,
                        'cityname' => $label,
                        'countryname' => (string)($city['country_code'] ?? ''),
                        'countrycode' => (string)($city['country_code'] ?? ''),
                        'destination_code' => $cityCode,
                        'city_code' => $cityCode,
                        'supplier' => 'grnconnect',
                        'hotel_count' => 0,
                        'is_hotel' => false,
                    ];
                }

                $hotelStmt = $pdo->prepare(
                    'SELECT hotel_code, name, address, city_code, country_code
                     FROM grnconnect_hotels
                     WHERE name LIKE ?
                     ORDER BY name ASC
                     LIMIT 8'
                );
                $hotelStmt->execute([$prefix]);
                foreach ($hotelStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $hotel) {
                    $hotelResults[] = [
                        'id' => $hotel['hotel_code'],
                        'type' => 'hotel',
                        'lc' => 'hotel',
                        'name' => $hotel['name'],
                        'airportname' => $hotel['name'],
                        'cityname' => $hotel['address'] ?? ($hotel['city_code'] ?? ''),
                        'countryname' => $hotel['country_code'] ?? '',
                        'countrycode' => $hotel['country_code'] ?? '',
                        'hotel_code' => $hotel['hotel_code'],
                        'city_code' => $hotel['city_code'] ?? '',
                        'destination_code' => $hotel['city_code'] ?? '',
                        'supplier' => 'grnconnect',
                        'is_hotel' => true,
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('GRNConnect autocomplete error: ' . $e->getMessage());
    }

    // ============================================================================
    // MERGE RESULTS: SUPPLIER AREAS, THEN OWN CITIES, THEN HOTELS
    // Areas lead and are ordered by inventory, so the place with the most hotels is
    // the first thing offered — the same ordering hotelbeds.com uses, and the one
    // that puts "Maldives, 752 hotels" above a single-property island.
    // ============================================================================
    // Ranked the way hotelbeds.com ranks its own picker: the place whose name IS
    // what the guest typed comes first, then names that start with it, then the
    // rest — inventory only orders rows within the same tier. Pure inventory order
    // put "Islamabad-Northern Region" (71 hotels, a region spanning Lahore,
    // Peshawar and Murree) above the "Islamabad" zone (35) for a guest who typed
    // exactly "Islamabad", so the first tap returned Lahore hotels.
    $matchTier = static function (string $name) use ($query): int {
        $name = trim($name);
        if (strcasecmp($name, $query) === 0) {
            return 0;
        }
        if (stripos($name, $query) === 0) {
            return 1;
        }
        return 2;
    };
    usort($areaResults, static function ($a, $b) use ($matchTier) {
        $tierA = $matchTier((string) $a['name']);
        $tierB = $matchTier((string) $b['name']);
        if ($tierA !== $tierB) {
            return $tierA <=> $tierB;
        }
        if ($a['hotel_count'] !== $b['hotel_count']) {
            return $b['hotel_count'] <=> $a['hotel_count'];
        }

        return strcasecmp($a['name'], $b['name']);
    });
    $areaResults = array_slice($areaResults, 0, 10);

    // A city the supplier already offers as an area is the same place, but the area
    // row carries the country and real inventory — so keep that one and drop the
    // duplicate rather than listing "Dubai" twice.
    $areaNames = [];
    foreach ($areaResults as $area) {
        $areaNames[strtolower(trim($area['name']))] = true;
    }
    $locationResults = array_values(array_filter($locationResults, static function ($location) use ($areaNames) {
        return !isset($areaNames[strtolower(trim((string) ($location['name'] ?? '')))]);
    }));

    $formattedResults = array_merge($areaResults, $locationResults, $hotelResults);

    // ============================================================================
    // WEBHOOK: Destination Searched
    // ============================================================================
    triggerWebhook('stays/destination-suggestion', 'stays.destination.searched', [
        'query'         => $query,
        'results_count' => count($formattedResults),
        'hotels_found'  => count($hotelResults),
        'cities_found'  => count($locationResults),
        'areas_found'   => count($areaResults),
        'timestamp'     => date('Y-m-d H:i:s'),
        'user_id'       => $_SESSION['user_id'] ?? null,
        'session_id'    => session_id()
    ]);

    echo json_encode($formattedResults);
    exit(0);
});


// ========================================================= stays/destination-context
// Remembers the country of the destination the guest picked from the suggestions.
//
// City names are not unique worldwide — Bali is an island in Indonesia and a town
// in Rajasthan, Syracuse is in both Italy and the USA — so the supplier lookup
// needs to know which one was meant. Keeping it in the session rather than in the
// URL leaves the SEO path untouched (/stays/bali/25-11-2026/...).
//
// The country is stored against the destination slug it was chosen for, so it can
// never leak into a later search for a different city.
$router->post('/stays/destination-context', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $destination = strtolower(trim((string) ($input['destination'] ?? '')));
    $country = strtoupper(trim((string) ($input['country'] ?? '')));
    $destinationCode = strtoupper(trim((string) ($input['destination_code'] ?? '')));
    $zoneCode = strtoupper(trim((string) ($input['zone_code'] ?? '')));

    // Atlas codes are only meaningful together with the slug they were picked for.
    // Always clear the previous pair first so a stale code can never be applied to a
    // different destination.
    unset($_SESSION['hotel_destination_code'], $_SESSION['hotel_destination_zone']);

    if ($destination === '' || !preg_match('/^[A-Z]{2,3}$/', $country)) {
        // Nothing usable — forget any previous pick so it cannot be misapplied.
        unset($_SESSION['hotel_destination_country'], $_SESSION['hotel_destination_country_for']);
        echo json_encode(['success' => true, 'stored' => false]);
        exit;
    }

    $_SESSION['hotel_destination_country'] = $country;
    $_SESSION['hotel_destination_country_for'] = $destination;

    // A zone code without its destination code is meaningless — zone codes repeat
    // across destinations — so both are stored, or neither.
    if (preg_match('/^[A-Z0-9]{2,32}$/', $destinationCode)) {
        $_SESSION['hotel_destination_code'] = $destinationCode;
        if (preg_match('/^[A-Z0-9]{1,10}$/', $zoneCode)) {
            $_SESSION['hotel_destination_zone'] = $zoneCode;
        }
    }

    echo json_encode(['success' => true, 'stored' => true]);
    exit;
});
