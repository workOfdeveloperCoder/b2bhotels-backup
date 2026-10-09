<?php
// ============================================================================
// WIDGETY — FRONTEND CATALOG (read-only)
// Search / detail / pricing helpers for the customer-facing cruises pages.
// Reads the imported content DB (widgety_cruises + widgety_departures).
//
// Widgety V3 returns one "holiday" per dated sailing, so the same trip shows
// up many times. Sailings are grouped into one cruise card by a stable key:
// operator + name + nights + embark port (see WGC_GROUP_SQL).
// ============================================================================
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/api.php';

if (!defined('WGC_GROUP_SQL')) {
    define('WGC_GROUP_SQL', "LEFT(MD5(CONCAT_WS('|', c.operator_id, LOWER(c.name), c.nights, LOWER(c.embark_port))), 12)");
}

if (!function_exists('_wgc_db')) {
    /** Content DB connection, or null when the module is not set up yet. */
    function _wgc_db($db)
    {
        try {
            return _widgety_db($db);
        } catch (\Throwable $e) {
            error_log('WIDGETY CATALOG | ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('_wgc_rows')) {
    function _wgc_rows($mdb, string $sql, array $params = []): array
    {
        $stmt = $mdb->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('_wgc_currency')) {
    /** Currency the visitor is browsing in (session), else the system default. */
    function _wgc_currency($db): string
    {
        static $cur = null;
        if ($cur !== null) {
            return $cur;
        }
        $cur = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')));
        if ($cur === '') {
            $cur = (string) ($db->get('currencies', 'name', ['default' => '1']) ?: 'USD');
        }
        return $cur;
    }
}

if (!function_exists('_wgc_default_currency')) {
    /** System default currency — bookings are stored in it (like tours). */
    function _wgc_default_currency($db): string
    {
        static $cur = null;
        return $cur ??= (string) ($db->get('currencies', 'name', ['default' => '1']) ?: 'USD');
    }
}

if (!function_exists('_wgc_price')) {
    /** Supplier price → marked-up price in the visitor's currency. */
    function _wgc_price($db, $amount, string $from, ?string $to = null): float
    {
        $amount = (float) $amount;
        if ($amount <= 0) {
            return 0.0;
        }
        $to = $to ?: _wgc_currency($db);
        return (float) (MARKUP($amount, 'cruises', $db, $from ?: 'USD', $to)['price'] ?? $amount);
    }
}

if (!function_exists('_wgc_money')) {
    function _wgc_money($amount, ?string $cur = null): string
    {
        return ($cur ?? '') . ' ' . number_format((float) $amount, 0);
    }
}

if (!function_exists('_wgc_img')) {
    /** Widgety asset URLs contain raw spaces — encode them for <img src>. */
    function _wgc_img(?string $url): string
    {
        $url = trim((string) $url);
        return $url === '' ? '' : str_replace(' ', '%20', $url);
    }
}

if (!function_exists('_wgc_html')) {
    /** Keep Widgety's basic formatting, drop anything else (scripts, styles, attributes). */
    function _wgc_html(?string $html): string
    {
        $html = strip_tags((string) $html, '<p><br><ul><ol><li><b><strong><i><em>');
        $html = preg_replace('/<(\w+)\s[^>]*>/', '<$1>', $html);
        $html = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $html);
        return trim((string) $html);
    }
}

if (!function_exists('_wgc_slug')) {
    function _wgc_slug(string $s): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
        return $s !== '' ? substr($s, 0, 80) : 'cruise';
    }
}

if (!function_exists('_wgc_url')) {
    function _wgc_url(string $key, string $name, array $query = []): string
    {
        $q = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== 'any');
        return root . 'cruise/' . $key . '/' . _wgc_slug($name) . ($q ? '?' . http_build_query($q) : '');
    }
}

if (!function_exists('_wgc_media')) {
    /**
     * Ship + operator facts and images from the imported raw JSON.
     * Returns ['ships' => [slug|lower-name => info], 'operators' => [id => info]].
     */
    function _wgc_media($mdb): array
    {
        static $media = null;
        if ($media !== null) {
            return $media;
        }
        $media = ['ships' => [], 'operators' => []];
        foreach (_wgc_rows($mdb, 'SELECT raw FROM widgety_ships') as $r) {
            $j = json_decode((string) $r['raw'], true);
            if (!is_array($j)) {
                continue;
            }
            $info = [
                'name'     => (string) ($j['title'] ?? ''),
                'cover'    => _wgc_img($j['cover_image_href'] ?? ''),
                'logo'     => _wgc_img($j['profile_image_href'] ?? ''),
                'teaser'   => _wgc_html($j['teaser'] ?? ($j['introduction'] ?? '')),
                'class'    => (string) ($j['ship_class'] ?? ''),
                'style'    => (string) ($j['style'] ?? ''),
                'facts'    => is_array($j['ship_facts'] ?? null) ? $j['ship_facts'] : [],
                'dining'   => array_slice(array_column((array) ($j['dining_options'] ?? []), 'name'), 0, 8),
                'cabins'   => array_slice((array) ($j['accomodation_types'] ?? []), 0, 12),
            ];
            $slug = basename((string) parse_url((string) ($j['href'] ?? ''), PHP_URL_PATH), '.json');
            if ($slug !== '') {
                $media['ships'][$slug] = $info;
            }
            if ($info['name'] !== '') {
                $media['ships'][strtolower($info['name'])] = $info;
            }
        }
        foreach (_wgc_rows($mdb, 'SELECT widgety_id, raw FROM widgety_operators') as $r) {
            $j = json_decode((string) $r['raw'], true) ?: [];
            $media['operators'][(string) $r['widgety_id']] = [
                'name'  => (string) ($j['title'] ?? ''),
                'cover' => _wgc_img($j['cover_image_href'] ?? ''),
                'logo'  => _wgc_img($j['profile_image_href'] ?? ''),
                'about' => _wgc_html($j['description'] ?? ''),
            ];
        }
        return $media;
    }
}

if (!function_exists('_wgc_ship')) {
    function _wgc_ship($mdb, ?string $shipId, ?string $shipName): array
    {
        $m = _wgc_media($mdb)['ships'];
        return $m[(string) $shipId] ?? $m[strtolower((string) $shipName)] ?? [];
    }
}

if (!function_exists('_wgc_image')) {
    /** Best picture for a cruise: ship cover → operator cover → placeholder. */
    function _wgc_image($mdb, array $row): string
    {
        $ship = _wgc_ship($mdb, $row['ship_id'] ?? '', $row['ship_name'] ?? '');
        if (!empty($ship['cover'])) {
            return $ship['cover'];
        }
        $op = _wgc_media($mdb)['operators'][$row['operator_id'] ?? ''] ?? [];
        return ($op['cover'] ?? '') ?: root . 'uploads/no_img.jpg';
    }
}

if (!function_exists('_wgc_lengths')) {
    function _wgc_lengths(): array
    {
        return [
            '1-5'   => [1, 5, '1 - 5 nights'],
            '6-9'   => [6, 9, '6 - 9 nights'],
            '10-14' => [10, 14, '10 - 14 nights'],
            '15+'   => [15, 999, '15+ nights'],
        ];
    }
}

if (!function_exists('_wgc_options')) {
    /** Search form choices built from what is actually in the catalogue. */
    function _wgc_options($db): array
    {
        $out = ['destinations' => [], 'operators' => [], 'months' => [], 'ports' => []];
        $mdb = _wgc_db($db);
        if (!$mdb) {
            return $out;
        }
        try {
            $rows = _wgc_rows($mdb, "SELECT c.regions, c.countries, c.operator_id, c.operator_name, c.embark_port,
                    DATE_FORMAT(d.depart_date, '%Y-%m') ym
                FROM widgety_cruises c JOIN widgety_departures d ON d.cruise_id = c.widgety_id
                WHERE d.depart_date >= CURDATE() + INTERVAL 3 DAY");
        } catch (\Throwable $e) {
            error_log('WIDGETY CATALOG | options: ' . $e->getMessage());
            return $out;
        }
        $dest = [];
        foreach ($rows as $r) {
            foreach (array_filter(array_map('trim', explode(',', (string) $r['regions']))) as $reg) {
                $dest[$reg] = ($dest[$reg] ?? 0) + 1;
            }
            if ($r['operator_id'] !== '') {
                $out['operators'][$r['operator_id']] = $r['operator_name'] ?: $r['operator_id'];
            }
            if ($r['ym']) {
                $out['months'][$r['ym']] = date('F Y', strtotime($r['ym'] . '-01'));
            }
            if (trim((string) $r['embark_port']) !== '') {
                $out['ports'][trim($r['embark_port'])] = true;
            }
        }
        arsort($dest);
        $out['destinations'] = array_keys($dest);
        asort($out['operators']);
        ksort($out['months']);
        $out['ports'] = array_keys($out['ports']);
        sort($out['ports']);
        return $out;
    }
}

if (!function_exists('_wgc_filters')) {
    /** Normalise search input (GET) into a filter array. */
    function _wgc_filters(array $in): array
    {
        $len = (string) ($in['length'] ?? 'any');
        $sort = (string) ($in['sort'] ?? 'date');
        $adults = max(1, min(4, (int) ($in['adults'] ?? 2)));
        $children = max(0, min(4 - $adults, (int) ($in['children'] ?? 0)));
        return [
            'destination' => trim(substr((string) ($in['destination'] ?? ''), 0, 80)),
            'month'       => preg_match('/^\d{4}-\d{2}$/', (string) ($in['month'] ?? '')) ? $in['month'] : '',
            'line'        => preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($in['line'] ?? ''))),
            'port'        => trim(substr((string) ($in['port'] ?? ''), 0, 80)),
            'length'      => isset(_wgc_lengths()[$len]) ? $len : 'any',
            'q'           => trim(substr((string) ($in['q'] ?? ''), 0, 80)),
            'sort'        => in_array($sort, ['date', 'price', 'price_desc', 'nights'], true) ? $sort : 'date',
            'adults'      => $adults,
            'children'    => $children,
        ];
    }
}

if (!function_exists('_wgc_search')) {
    /**
     * Grouped search. Each result = one trip with its upcoming sailings:
     * ['key','name','operator_id','operator_name','ships','nights','embark','disembark',
     *  'regions','countries','holiday_type','currency','lead'(float|null),'sailings'=>[...],'image','url']
     */
    function _wgc_search($db, array $f): array
    {
        $mdb = _wgc_db($db);
        if (!$mdb) {
            return [];
        }
        $where = ['d.depart_date >= CURDATE() + INTERVAL 3 DAY'];
        $p = [];
        if ($f['destination'] !== '') {
            $where[] = '(c.regions LIKE :dest OR c.countries LIKE :dest2 OR c.name LIKE :dest3)';
            $p[':dest'] = $p[':dest2'] = $p[':dest3'] = '%' . $f['destination'] . '%';
        }
        if ($f['month'] !== '') {
            $where[] = "DATE_FORMAT(d.depart_date, '%Y-%m') = :ym";
            $p[':ym'] = $f['month'];
        }
        if ($f['line'] !== '') {
            $where[] = 'c.operator_id = :op';
            $p[':op'] = $f['line'];
        }
        if ($f['port'] !== '') {
            $where[] = 'd.embark_port LIKE :port';
            $p[':port'] = '%' . $f['port'] . '%';
        }
        if ($f['q'] !== '') {
            $where[] = '(c.name LIKE :q OR d.ship_name LIKE :q2)';
            $p[':q'] = $p[':q2'] = '%' . $f['q'] . '%';
        }
        if ($f['length'] !== 'any') {
            [$min, $max] = _wgc_lengths()[$f['length']];
            $where[] = 'c.nights BETWEEN ' . (int) $min . ' AND ' . (int) $max;
        }

        try {
            $rows = _wgc_rows($mdb, 'SELECT ' . WGC_GROUP_SQL . " gk, c.widgety_id cruise_ref, c.operator_id, c.operator_name,
                    c.name, c.nights, c.holiday_type, c.regions, c.countries,
                    d.widgety_id date_ref, d.depart_date, d.return_date, d.lead_price, d.currency, d.ship_id, d.ship_name,
                    d.availability, d.embark_port, d.disembark_port
                FROM widgety_cruises c JOIN widgety_departures d ON d.cruise_id = c.widgety_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY d.depart_date ASC LIMIT 5000', $p);
        } catch (\Throwable $e) {
            error_log('WIDGETY CATALOG | search: ' . $e->getMessage());
            return [];
        }

        $groups = [];
        foreach ($rows as $r) {
            $k = $r['gk'];
            if (!isset($groups[$k])) {
                $groups[$k] = [
                    'key'           => $k,
                    'name'          => $r['name'],
                    'operator_id'   => $r['operator_id'],
                    'operator_name' => $r['operator_name'],
                    'ships'         => [],
                    'nights'        => (int) $r['nights'],
                    'embark'        => $r['embark_port'],
                    'disembark'     => $r['disembark_port'],
                    'regions'       => $r['regions'],
                    'countries'     => $r['countries'],
                    'holiday_type'  => $r['holiday_type'],
                    'currency'      => $r['currency'] ?: 'USD',
                    'lead'          => null,
                    'sailings'      => [],
                    'image'         => _wgc_image($mdb, $r),
                ];
            }
            $g = &$groups[$k];
            if ($r['ship_name'] !== '' && !in_array($r['ship_name'], $g['ships'], true)) {
                $g['ships'][] = $r['ship_name'];
            }
            $lead = (float) $r['lead_price'];
            if ($lead > 0 && ($g['lead'] === null || $lead < $g['lead'])) {
                $g['lead'] = $lead;
                $g['currency'] = $r['currency'] ?: $g['currency'];
            }
            $g['sailings'][] = [
                'date_ref' => $r['date_ref'],
                'date'     => $r['depart_date'],
                'return'   => $r['return_date'],
                'lead'     => $lead > 0 ? $lead : null,
                'currency' => $r['currency'] ?: 'USD',
                'ship'     => $r['ship_name'],
            ];
            unset($g);
        }

        $groups = array_values($groups);
        $byLead = fn($g) => $g['lead'] ?? PHP_FLOAT_MAX;
        usort($groups, match ($f['sort']) {
            'price'      => fn($a, $b) => $byLead($a) <=> $byLead($b),
            'price_desc' => fn($a, $b) => ($b['lead'] ?? 0) <=> ($a['lead'] ?? 0),
            'nights'     => fn($a, $b) => $a['nights'] <=> $b['nights'],
            default      => fn($a, $b) => $a['sailings'][0]['date'] <=> $b['sailings'][0]['date'],
        });
        return $groups;
    }
}

if (!function_exists('_wgc_cabins')) {
    /**
     * Flatten a sailing's fares into bookable cabins (supplier currency, per person).
     * Key = "<fare index>-<grade code>" so the same grade under two fares stays distinct.
     */
    function _wgc_cabins(?string $pricingJson): array
    {
        $cabins = [];
        foreach ((array) json_decode((string) $pricingJson, true) as $fi => $fare) {
            foreach ((array) ($fare['prices'] ?? []) as $gi => $pr) {
                $num = fn($k) => max(0, (float) ($pr[$k] ?? 0));
                $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($pr['grade_code'] ?? '')) ?: ('G' . $gi);
                $cabins[$fi . '-' . $code] = [
                    'key'          => $fi . '-' . $code,
                    'fare'         => (string) ($fare['name'] ?? ''),
                    'name'         => (string) ($pr['grade_name'] ?? $code),
                    'code'         => $code,
                    'room_type'    => strtolower((string) ($pr['room_type'] ?? '')),
                    'double'       => $num('double_price_pp'),
                    'single'       => $num('single_price_pp'),
                    'triple'       => $num('triple_price_pp'),
                    'quad'         => $num('quad_price_pp'),
                    'child'        => $num('child_price'),
                    'availability' => strtolower((string) ($pr['availability'] ?? '')),
                ];
            }
        }
        return $cabins;
    }
}

if (!function_exists('_wgc_cabin_open')) {
    function _wgc_cabin_open(array $cabin): bool
    {
        return $cabin['double'] > 0
            && !in_array($cabin['availability'], ['sold_out', 'soldout', 'unavailable', 'closed', 'waitlist'], true);
    }
}

if (!function_exists('_wgc_quote_lines')) {
    /**
     * Per-guest supplier prices for one cabin (same rule on detail + booking):
     * 1 adult pays the single rate (or 2 × double if none), adults 1-2 pay double,
     * the 3rd/4th guest pays the triple/quad rate, children their own rate when given.
     * Returns [['label','pp'], ...] — empty when the cabin is not sellable.
     */
    function _wgc_quote_lines(array $cabin, int $adults, int $children): array
    {
        if (!_wgc_cabin_open($cabin)) {
            return [];
        }
        $d = $cabin['double'];
        $lines = [];
        if ($adults === 1) {
            $lines[] = ['label' => 'Adult 1 (single occupancy)', 'pp' => $cabin['single'] > 0 ? $cabin['single'] : $d * 2];
        } else {
            for ($i = 1; $i <= $adults; $i++) {
                $pp = $i <= 2 ? $d : ($i === 3 ? ($cabin['triple'] ?: $d) : ($cabin['quad'] ?: $cabin['triple'] ?: $d));
                $lines[] = ['label' => 'Adult ' . $i, 'pp' => $pp];
            }
        }
        for ($i = 1; $i <= $children; $i++) {
            $pos = $adults + $i;
            $shared = $pos === 3 ? ($cabin['triple'] ?: $d) : ($cabin['quad'] ?: $cabin['triple'] ?: $d);
            $lines[] = ['label' => 'Child ' . $i, 'pp' => $cabin['child'] > 0 ? $cabin['child'] : ($pos <= 2 ? $d : $shared)];
        }
        return $lines;
    }
}

if (!function_exists('_wgc_quote')) {
    /**
     * Price one cabin on one sailing for the party, in $currency (markup + tax applied).
     * Returns null when the cabin is unknown / not sellable (price on request, sold out).
     */
    function _wgc_quote($db, array $sailing, string $cabinKey, int $adults, int $children, string $currency): ?array
    {
        $cabin = $sailing['cabins'][$cabinKey] ?? null;
        $lines = $cabin ? _wgc_quote_lines($cabin, $adults, $children) : [];
        if (!$lines) {
            return null;
        }
        $supCur = $sailing['currency'] ?: 'USD';
        $supplierTotal = round(array_sum(array_column($lines, 'pp')), 2);
        $m = MARKUP($supplierTotal, 'cruises', $db, $supCur, $currency);
        $subtotal = round((float) $m['price'], 2);
        $base = round((float) ($m['converted_base_price'] ?? $subtotal), 2);
        $tax = calculateTax($subtotal, 'cruises', $db);
        $taxAmount = round((float) ($tax['tax_amount'] ?? 0), 2);
        foreach ($lines as &$l) {
            $l['amount'] = $supplierTotal > 0 ? round($l['pp'] / $supplierTotal * $subtotal, 2) : 0;
        }
        unset($l);
        return [
            'cabin'             => $cabin,
            'lines'             => $lines,
            'supplier_total'    => $supplierTotal,
            'supplier_currency' => $supCur,
            'currency'          => $currency,
            'base'              => $base,
            'subtotal'          => $subtotal,
            'markup'            => round($subtotal - $base, 2),
            'tax'               => $taxAmount,
            'tax_type'          => $tax['tax_type'] ?? 'percentage',
            'total'             => round($subtotal + $taxAmount, 2),
        ];
    }
}

if (!function_exists('_wgc_group')) {
    /**
     * One trip with every upcoming sailing (and its cabins).
     * Returns null when the key is unknown or has no future sailings.
     */
    function _wgc_group($db, string $key): ?array
    {
        $mdb = _wgc_db($db);
        if (!$mdb || !preg_match('/^[a-f0-9]{12}$/', $key)) {
            return null;
        }
        try {
            $rows = _wgc_rows($mdb, "SELECT c.widgety_id cruise_ref, c.operator_id, c.operator_name, c.name, c.nights,
                    c.duration_days, c.holiday_type, c.rating, c.regions, c.countries, c.description, c.highlights,
                    c.whats_included, c.raw cruise_raw,
                    d.widgety_id date_ref, d.depart_date, d.return_date, d.lead_price, d.currency, d.pricing,
                    d.ship_id, d.ship_name, d.availability, d.embark_port, d.disembark_port
                FROM widgety_cruises c JOIN widgety_departures d ON d.cruise_id = c.widgety_id
                WHERE " . WGC_GROUP_SQL . " = :k AND d.depart_date >= CURDATE() + INTERVAL 3 DAY
                ORDER BY d.depart_date ASC", [':k' => $key]);
        } catch (\Throwable $e) {
            error_log('WIDGETY CATALOG | group: ' . $e->getMessage());
            return null;
        }
        if (!$rows) {
            return null;
        }
        $first = $rows[0];
        $raw = json_decode((string) $first['cruise_raw'], true) ?: [];
        $group = [
            'key'           => $key,
            'name'          => $first['name'],
            'operator_id'   => $first['operator_id'],
            'operator_name' => $first['operator_name'],
            'operator'      => _wgc_media($mdb)['operators'][$first['operator_id']] ?? [],
            'nights'        => (int) $first['nights'],
            'days'          => (int) $first['duration_days'],
            'holiday_type'  => $first['holiday_type'],
            'rating'        => $first['rating'],
            'regions'       => array_values(array_filter(array_map('trim', explode(',', (string) $first['regions'])))),
            'countries'     => array_values(array_filter(array_map('trim', explode(',', (string) $first['countries'])))),
            'embark'        => $first['embark_port'],
            'disembark'     => $first['disembark_port'],
            'description'   => _wgc_html($first['description']),
            'highlights'    => _wgc_html(is_string($raw['highlights'] ?? null) ? $raw['highlights'] : ''),
            'included'      => _wgc_html(is_string($raw['whats_included'] ?? null) ? $raw['whats_included'] : ''),
            'image'         => _wgc_image($mdb, $first),
            'ship'          => _wgc_ship($mdb, $first['ship_id'], $first['ship_name']),
            'sailings'      => [],
        ];
        foreach ($rows as $r) {
            $group['sailings'][] = [
                'date_ref'     => $r['date_ref'],
                'cruise_ref'   => $r['cruise_ref'],
                'date'         => $r['depart_date'],
                'return'       => $r['return_date'],
                'lead'         => (float) $r['lead_price'] > 0 ? (float) $r['lead_price'] : null,
                'currency'     => $r['currency'] ?: 'USD',
                'ship_id'      => $r['ship_id'],
                'ship'         => $r['ship_name'],
                'availability' => $r['availability'],
                'embark'       => $r['embark_port'],
                'disembark'    => $r['disembark_port'],
                'cabins'       => _wgc_cabins($r['pricing']),
            ];
        }
        return $group;
    }
}

if (!function_exists('_wgc_sailing')) {
    /** One sailing (by date_ref) with its trip — used by booking. */
    function _wgc_sailing($db, string $dateRef): ?array
    {
        $mdb = _wgc_db($db);
        if (!$mdb || !preg_match('/^[A-Za-z0-9_\-]{3,64}$/', $dateRef)) {
            return null;
        }
        $key = _wgc_rows($mdb, 'SELECT ' . WGC_GROUP_SQL . ' gk FROM widgety_cruises c
            JOIN widgety_departures d ON d.cruise_id = c.widgety_id WHERE d.widgety_id = :r', [':r' => $dateRef])[0]['gk'] ?? null;
        $group = $key ? _wgc_group($db, $key) : null;
        if (!$group) {
            return null;
        }
        foreach ($group['sailings'] as $s) {
            if ($s['date_ref'] === $dateRef) {
                $group['sailing'] = $s;
                $group['ship'] = _wgc_ship($mdb, $s['ship_id'], $s['ship']) ?: $group['ship'];
                return $group;
            }
        }
        return null;
    }
}
