<?php
// ============================================================================
// WIDGETY — CONTENT IMPORT ROUTES + ENGINE
// ----------------------------------------------------------------------------
// Imports the Widgety catalogue (operators -> ships -> ports -> holidays +
// departures) into the module's content DB (see api.php _widgety_db).
//   GET  cruises/widgety/stats           -> row counts + last sync
//   POST cruises/widgety/content_import  -> { action: all|operators|ships|ports|holidays }
//
// HAL pagination is followed via `_links.next` (_widgety_paginate). Every row
// keeps the full raw JSON in `raw`, so even if a mapped field name differs the
// data is never lost and mappers can be refined without re-fetching blindly.
//
// NOTE: field mappings below are PROVISIONAL until verified against live V3
// responses (trial key was rate-limited during build). The id + name/date
// extractors try several documented key spellings and fall back to `raw`.
// ============================================================================
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../api.php';

if (!function_exists('_widgety_item_id')) {
    /** RESOLVE A STABLE WIDGETY ID FROM AN ITEM (id field, else self-link tail). */
    function _widgety_item_id(array $item): string
    {
        foreach (['id', 'Id', 'ID', 'unlocode', 'code', 'reference', 'uuid', 'permalink', 'slug'] as $k) {
            if (isset($item[$k]) && is_scalar($item[$k]) && (string) $item[$k] !== '') {
                return (string) $item[$k];
            }
        }
        $self = $item['_links']['self']['href'] ?? '';
        if (is_string($self) && $self !== '') {
            $path = parse_url($self, PHP_URL_PATH) ?: $self;
            $tail = basename(preg_replace('/\.json$/i', '', $path));
            if ($tail !== '') {
                return $tail;
            }
        }
        return '';
    }
}

if (!function_exists('_widgety_pick')) {
    /** FIRST NON-EMPTY SCALAR VALUE AMONG THE GIVEN KEYS. */
    function _widgety_pick(array $item, array $keys, $default = null)
    {
        foreach ($keys as $k) {
            if (isset($item[$k]) && is_scalar($item[$k]) && (string) $item[$k] !== '') {
                return $item[$k];
            }
        }
        return $default;
    }
}

if (!function_exists('_widgety_json')) {
    /** ENCODE A NESTED VALUE FOR STORAGE, OR NULL WHEN EMPTY. */
    function _widgety_json($value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('_widgety_upsert')) {
    /** UPSERT BY widgety_id: UPDATE WHEN PRESENT, ELSE INSERT. RETURNS 'insert'|'update'|'skip'. */
    function _widgety_upsert($mdb, string $table, string $widgetyId, array $data): string
    {
        if ($widgetyId === '') {
            return 'skip';
        }
        $exists = $mdb->get($table, ['id'], ['widgety_id' => $widgetyId]);
        if ($exists) {
            $mdb->update($table, $data, ['widgety_id' => $widgetyId]);
            return 'update';
        }
        $mdb->insert($table, array_merge(['widgety_id' => $widgetyId], $data));
        return 'insert';
    }
}

if (!function_exists('_widgety_map_operator')) {
    function _widgety_map_operator(array $it, string $market): array
    {
        return [
            'name'   => (string) _widgety_pick($it, ['name', 'title', 'Name'], ''),
            'slug'   => (string) _widgety_pick($it, ['slug', 'permalink'], ''),
            'market' => $market,
            'logo'   => (string) _widgety_pick($it, ['profile_image_href', 'cover_image_href', 'logo', 'logo_url', 'image', 'image_url'], ''),
            'raw'    => _widgety_json($it),
        ];
    }
}

if (!function_exists('_widgety_map_ship')) {
    function _widgety_map_ship(array $it): array
    {
        return [
            'operator_id' => (string) _widgety_pick($it, ['operator_id', 'cruise_line_id', 'line_id'], ''),
            'name'        => (string) _widgety_pick($it, ['name', 'title', 'Name'], ''),
            'slug'        => (string) _widgety_pick($it, ['slug', 'permalink'], ''),
            'rating'      => (string) _widgety_pick($it, ['rating', 'star_rating', 'stars'], ''),
            'images'      => _widgety_json($it['images'] ?? ($it['gallery'] ?? null)),
            'raw'         => _widgety_json($it),
        ];
    }
}

if (!function_exists('_widgety_map_port')) {
    function _widgety_map_port(array $it): array
    {
        return [
            'name'      => (string) _widgety_pick($it, ['name', 'title', 'Name'], ''),
            'country'   => (string) _widgety_pick($it, ['country', 'country_name'], ''),
            'region'    => (string) _widgety_pick($it, ['region', 'area'], ''),
            'latitude'  => (string) _widgety_pick($it, ['latitude', 'lat'], ''),
            'longitude' => (string) _widgety_pick($it, ['longitude', 'lng', 'lon'], ''),
            'raw'       => _widgety_json($it),
        ];
    }
}

if (!function_exists('_widgety_url_slug')) {
    /** "https://…/api/ships/ms-viva-two.json" → "ms-viva-two". */
    function _widgety_url_slug($url): string
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        return (string) preg_replace('/\.json$/i', '', basename($path));
    }
}

if (!function_exists('_widgety_ymd')) {
    /** "2026-12-21T00:00:00.000Z" → "2026-12-21" (null when empty/invalid). */
    function _widgety_ymd($iso): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $iso, $m) ? $m[1] : null;
    }
}

if (!function_exists('_widgety_min_positive')) {
    /** Smallest positive number among the values (Widgety uses "0.0" for "not sold"). */
    function _widgety_min_positive(array $values): ?float
    {
        $min = null;
        foreach ($values as $v) {
            if (is_numeric($v) && (float) $v > 0 && ($min === null || (float) $v < $min)) {
                $min = (float) $v;
            }
        }
        return $min;
    }
}

if (!function_exists('_widgety_departure_lead')) {
    /**
     * Lowest per-person price for one sailing: cabin grades first
     * (pricing[].prices[].*_price_pp), else the headline "from" prices.
     */
    function _widgety_departure_lead(array $date): ?float
    {
        $pp = [];
        foreach ((array) ($date['pricing'] ?? []) as $plan) {
            foreach ((array) ($plan['prices'] ?? []) as $grade) {
                $pp[] = $grade['double_price_pp'] ?? null;
                $pp[] = $grade['single_price_pp'] ?? null;
            }
        }
        $lead = _widgety_min_positive($pp);
        if ($lead !== null) {
            return $lead;
        }
        $headline = [];
        foreach ((array) ($date['headline_prices']['cruise'] ?? []) as $occupancy) {
            foreach ((array) $occupancy as $v) {
                $headline[] = $v;
            }
        }
        return _widgety_min_positive($headline);
    }
}

if (!function_exists('_widgety_map_holiday')) {
    /**
     * Map a V3 holiday DETAIL (holidays/<ref>.json) to one cruise row plus its
     * sailings. Returns ['cruise' => array, 'departures' => [date_ref => row]].
     */
    function _widgety_map_holiday(array $h, string $market, string $currency): array
    {
        $departures = [];
        $leads = [];
        $first = null;
        foreach ((array) ($h['operating_seasons'] ?? []) as $season) {
            foreach ((array) ($season['dates'] ?? []) as $d) {
                $ref = (string) ($d['date_ref'] ?? '');
                if ($ref === '') {
                    continue;
                }
                $lead = _widgety_departure_lead($d);
                if ($lead !== null) {
                    $leads[] = $lead;
                }
                $first = $first ?? $d;
                $departures[$ref] = [
                    'depart_date'    => _widgety_ymd($d['date_from'] ?? null),
                    'return_date'    => _widgety_ymd($d['date_to'] ?? null),
                    'currency'       => $currency,
                    'lead_price'     => $lead,
                    'pricing'        => _widgety_json($d['pricing'] ?? null),
                    'ship_id'        => _widgety_url_slug($d['ship'] ?? ''),
                    'ship_name'      => (string) ($d['ship_title'] ?? ''),
                    'availability'   => (string) ($d['availability_string'] ?? ''),
                    'embark_port'    => (string) ($d['starts_at']['name'] ?? ''),
                    'disembark_port' => (string) ($d['ends_at']['name'] ?? ''),
                    'raw'            => _widgety_json($d),
                ];
            }
        }

        $dates = array_filter(array_column($departures, 'depart_date'));
        $nights = (int) ($h['cruise_nights'] ?? 0);
        if ($nights <= 0 && (int) ($h['duration_days'] ?? 0) > 0) {
            $nights = (int) $h['duration_days'] - 1;
        }

        $cruise = [
            'operator_id'      => _widgety_url_slug($h['operator'] ?? ''),
            'operator_name'    => (string) ($h['operator_title'] ?? ''),
            'ship_id'          => _widgety_url_slug($first['ship'] ?? ''),
            'ship_name'        => (string) ($first['ship_title'] ?? ''),
            'name'             => (string) ($h['name'] ?? ''),
            'holiday_type'     => (string) (($h['holiday_type'][0] ?? '') ?: 'cruise'),
            'nights'           => $nights,
            'duration_days'    => (int) ($h['duration_days'] ?? 0),
            'rating'           => (string) ($h['rating'] ?? ''),
            'market'           => $market,
            'description'      => (string) ($h['description'] ?? ''),
            'highlights'       => _widgety_json($h['highlights'] ?? null),
            'whats_included'   => _widgety_json($h['whats_included'] ?? null),
            'images'           => _widgety_json($h['images'] ?? null),
            'embark_port'      => (string) ($first['starts_at']['name'] ?? ''),
            'disembark_port'   => (string) ($first['ends_at']['name'] ?? ''),
            'regions'          => substr(implode(', ', (array) ($h['regions'] ?? [])), 0, 500),
            'countries'        => substr(implode(', ', (array) ($h['countries'] ?? [])), 0, 500),
            'tags'             => _widgety_json(array_filter([
                'themes'     => $h['themes'] ?? [],
                'tags'       => $h['tags'] ?? [],
                'activities' => $h['activities'] ?? [],
            ])),
            'lead_price'       => _widgety_min_positive($leads),
            'lead_currency'    => $currency,
            'first_departure'  => $dates ? min($dates) : null,
            'last_departure'   => $dates ? max($dates) : null,
            'departures_count' => count($departures),
            'detail_synced_at' => date('Y-m-d H:i:s'),
            'raw'              => _widgety_json($h),
        ];

        return ['cruise' => $cruise, 'departures' => $departures];
    }
}

if (!function_exists('_widgety_save_holiday')) {
    /**
     * Upsert one holiday + all its sailings, and drop sailings Widgety no longer
     * lists for it. Returns the number of sailings saved.
     */
    function _widgety_save_holiday($mdb, string $ref, array $mapped): int
    {
        _widgety_upsert($mdb, 'widgety_cruises', $ref, $mapped['cruise']);
        $kept = [];
        foreach ($mapped['departures'] as $dateRef => $row) {
            _widgety_upsert($mdb, 'widgety_departures', (string) $dateRef, array_merge(['cruise_id' => $ref], $row));
            $kept[] = (string) $dateRef;
        }
        $stale = ['cruise_id' => $ref];
        if ($kept) {
            $stale['widgety_id[!]'] = $kept;
        }
        $mdb->delete('widgety_departures', $stale);
        return count($kept);
    }
}

if (!function_exists('_widgety_import_holidays_page')) {
    /**
     * Import ONE page of the holidays list (25 cruises): fetch each cruise's
     * detail and save it with its sailings. Paced to stay under Widgety's rate
     * limit; cruises synced in the last $freshHours are skipped unless $force,
     * so an interrupted import resumes cheaply.
     *
     * Returns ['page','last_page','next_page'(int|null),'seen','saved',
     *          'departures','skipped','errors'(list),'error'(string)].
     */
    function _widgety_import_holidays_page(array $cfg, $mdb, int $page, bool $force = false, int $freshHours = 20): array
    {
        $out = ['page' => $page, 'last_page' => $page, 'next_page' => null, 'seen' => 0, 'saved' => 0,
                'departures' => 0, 'skipped' => 0, 'errors' => [], 'error' => ''];

        $list = _widgety_request($cfg, WIDGETY_EP_HOLIDAYS, ['market' => $cfg['market'], 'page' => $page]);
        if ($list['http_code'] !== 200 || !is_array($list['json'])) {
            $out['error'] = _widgety_error_message($list, 'Could not load the holidays list.');
            return $out;
        }
        $json = $list['json'];
        if (preg_match('/[?&]page=(\d+)/', (string) ($json['_links']['last']['href'] ?? ''), $m)) {
            $out['last_page'] = (int) $m[1];
        }
        $out['next_page'] = !empty($json['_links']['next']['href']) ? $page + 1 : null;

        $cutoff = date('Y-m-d H:i:s', time() - $freshHours * 3600);
        foreach ((array) ($json['holidays'] ?? []) as $item) {
            $ref = (string) ($item['holiday_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $out['seen']++;

            // Operator filter (c5): only import the listed operators.
            if ($cfg['operators'] && !in_array(_widgety_url_slug($item['operator'] ?? ''), $cfg['operators'], true)) {
                $out['skipped']++;
                continue;
            }
            if (!$force && $mdb->has('widgety_cruises', ['widgety_id' => $ref, 'detail_synced_at[>]' => $cutoff])) {
                $out['skipped']++;
                continue;
            }

            usleep(250000); // pace detail calls — bursts get the IP throttled
            $detail = _widgety_request($cfg, WIDGETY_EP_HOLIDAYS . '/' . rawurlencode($ref), ['market' => $cfg['market']]);
            if ($detail['http_code'] !== 200 || !is_array($detail['json'])) {
                $out['errors'][] = $ref . ': ' . _widgety_error_message($detail);
                continue;
            }
            $out['departures'] += _widgety_save_holiday(
                $mdb, $ref, _widgety_map_holiday($detail['json'], $cfg['market'], $cfg['currency'])
            );
            $out['saved']++;
        }
        return $out;
    }
}

if (!function_exists('_widgety_import_resource')) {
    /**
     * IMPORT ONE TOP-LEVEL RESOURCE. RETURNS ['seen'=>int,'saved'=>int,'pages'=>int,'error'=>string].
     * $resource: 'operators'|'ships'|'ports'. (Cruises/holidays are paged —
     * see _widgety_import_holidays_page.)
     */
    function _widgety_import_resource(array $cfg, $mdb, string $resource): array
    {
        $market = $cfg['market'];
        $map = [
            'operators' => [WIDGETY_EP_OPERATORS, 'widgety_operators', fn($it) => _widgety_map_operator($it, $market)],
            'ships'     => [WIDGETY_EP_SHIPS,     'widgety_ships',     fn($it) => _widgety_map_ship($it)],
            'ports'     => [WIDGETY_EP_PORTS,     'widgety_ports',     fn($it) => _widgety_map_port($it)],
        ];
        if (!isset($map[$resource])) {
            return ['seen' => 0, 'saved' => 0, 'pages' => 0, 'error' => "Unknown resource {$resource}"];
        }
        [$endpoint, $table, $mapper] = $map[$resource];

        // ALL ENDPOINTS ARE MARKET-SCOPED FOR THE TRIAL. The collection is a
        // top-level key named after the resource (operators/ships/ports).
        $query = ['market' => $market];
        $page  = _widgety_paginate($cfg, $endpoint, $query, $resource);
        if ($page['error'] !== '') {
            return ['seen' => 0, 'saved' => 0, 'pages' => $page['pages'], 'error' => $page['error']];
        }

        $saved = 0;
        foreach ($page['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $res = _widgety_upsert($mdb, $table, _widgety_item_id($it), $mapper($it));
            if ($res !== 'skip') {
                $saved++;
            }
        }

        return ['seen' => count($page['items']), 'saved' => $saved, 'pages' => $page['pages'], 'error' => ''];
    }
}

// ============================================================================
// STATS — ROW COUNTS + LAST SYNC
// ============================================================================
$router->get('cruises/widgety/stats', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }
    try {
        $cfg = _widgety_cfg($db);
        $mdb = _widgety_db($db);
        echo json_encode([
            'success' => true,
            'stats' => [
                'operators'  => (int) $mdb->count('widgety_operators'),
                'ships'      => (int) $mdb->count('widgety_ships'),
                'ports'      => (int) $mdb->count('widgety_ports'),
                'cruises'    => (int) $mdb->count('widgety_cruises'),
                'departures' => (int) $mdb->count('widgety_departures'),
            ],
            'last_sync' => $mdb->get('sync_log', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]),
            'market'    => $cfg['market'],
        ]);
    } catch (\Throwable $e) {
        error_log('WIDGETY STATS | ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ============================================================================
// CONTENT IMPORT — action = all | operators | ships | ports | holidays
//   all      → operators, ships, ports (the admin screen then runs holidays)
//   holidays → ONE page of cruises per call. Pass page=N, or omit it to resume
//              from the saved cursor; force=1 re-fetches recently synced cruises.
//              Response carries next_page / last_page / done for the UI loop.
// ============================================================================
$router->post('cruises/widgety/content_import', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    // Admin only — an import makes thousands of Widgety calls on our credentials.
    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }

    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    try {
        $cfg = _widgety_cfg($db);
        if ($cfg['app_id'] === '' || $cfg['token'] === '') {
            throw new Exception('Configure the Widgety APP ID and Token first.');
        }
        $mdb = _widgety_db($db);

        $action = strtolower(trim((string) ($_POST['action'] ?? 'all')));

        if ($action === 'holidays') {
            $page = (int) ($_POST['page'] ?? 0);
            if ($page < 1) {
                $page = max(1, (int) (_widgety_state($mdb, 'holidays_next_page') ?? 1));
            }
            $force = ($_POST['force'] ?? '') === '1';
            $r = _widgety_import_holidays_page($cfg, $mdb, $page, $force);

            // Save the cursor: next page, or back to 1 once the last page is done.
            if ($r['error'] === '') {
                _widgety_state($mdb, 'holidays_next_page', (string) ($r['next_page'] ?? 1));
            }
            $mdb->insert('sync_log', [
                'sync_type' => 'holidays',
                'items'     => $r['saved'],
                'pages'     => 1,
                'message'   => substr($r['error'] !== '' ? $r['error'] : ('page ' . $page . '/' . $r['last_page']
                    . ($r['errors'] ? ' · ' . count($r['errors']) . ' failed' : '')), 0, 500),
            ]);

            echo json_encode([
                'success'    => $r['error'] === '',
                'message'    => $r['error'] !== '' ? $r['error']
                    : "Cruises page {$page}/{$r['last_page']}: saved {$r['saved']}, skipped {$r['skipped']}, sailings {$r['departures']}.",
                'page'       => $r['page'],
                'last_page'  => $r['last_page'],
                'next_page'  => $r['next_page'],
                'done'       => $r['error'] === '' && $r['next_page'] === null,
                'saved'      => $r['saved'],
                'skipped'    => $r['skipped'],
                'departures' => $r['departures'],
                'errors'     => array_slice($r['errors'], 0, 10),
            ]);
            exit;
        }

        $resources = $action === 'all'
            ? ['operators', 'ships', 'ports']
            : [$action];

        $results = [];
        $totalSaved = 0;
        $firstError = '';
        foreach ($resources as $resource) {
            $r = _widgety_import_resource($cfg, $mdb, $resource);
            $results[$resource] = $r;
            $totalSaved += $r['saved'];
            if ($r['error'] !== '' && $firstError === '') {
                $firstError = $resource . ': ' . $r['error'];
            }
        }

        $mdb->insert('sync_log', [
            'sync_type' => $action,
            'items'     => $totalSaved,
            'pages'     => array_sum(array_map(fn($r) => $r['pages'], $results)),
            'message'   => $firstError !== '' ? substr($firstError, 0, 500) : 'OK',
        ]);

        echo json_encode([
            'success' => $firstError === '',
            'message' => $firstError !== '' ? $firstError : 'Import complete.',
            'saved'   => $totalSaved,
            'results' => $results,
        ]);
    } catch (\Throwable $e) {
        error_log('WIDGETY IMPORT | ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
