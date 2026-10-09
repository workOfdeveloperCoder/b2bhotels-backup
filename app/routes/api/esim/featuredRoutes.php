<?php
// ============================================================================
// FEATURED eSIM API
// ============================================================================

@$SECURE or die('Access Denied!');

// Supplier helpers (facts for the cards). Loaded with care: an update that misses
// this file must not break the page; the cards then show the country only.
$__airaloApi = dirname(__DIR__, 4) . '/modules/esim/airalo/api.php';
if (is_file($__airaloApi)) { require_once $__airaloApi; }

$router->get('/api/esim/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        // Fetch eSIM/Airalo module status to verify enabled
        $airaloModule = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$airaloModule) {
            echo json_encode([
                'success' => true,
                'message' => 'eSIM module is not enabled',
                'data' => [
                    'featured' => [],
                    'currency' => 'USD'
                ]
            ]);
            exit;
        }

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // Featured countries: the Featured switch of the admin Airalo countries table
        // (Modules > Airalo > Database); only enabled countries can be featured.
        $featuredRules = $db->select('airalo_countries', [
            'id',
            'iso(country)',
            'nicename(country_name)',
        ], [
            'status' => 1,
            'featured' => 1,
            'ORDER' => ['nicename' => 'ASC'],
            'LIMIT' => 12,
        ]);

        $featuredItems = [];

        // ========================================
        // FACTS FOR THE CARDS: NETWORK, DATA, DAYS, LOWEST PRICE
        // ========================================
        $featuredIsos = [];
        foreach ((array) $featuredRules as $rule) {
            $iso = strtoupper((string) ($rule['country'] ?? ''));
            if ($iso !== '' && count($featuredIsos) < 12 && !in_array($iso, $featuredIsos, true)) {
                $featuredIsos[] = $iso;
            }
        }

        $summaries = [];
        if ($featuredIsos && function_exists('_airalo_featured_summaries')) {
            try {
                $summaries = _airalo_featured_summaries($db, $featuredIsos);
            } catch (Throwable $e) {
                $summaries = [];
            }
        }

        // Commission per country: the rule for the kind of plan (local or global), else the rule for all
        // (the same choice the packages page makes).
        $commission = [];
        if ($summaries) {
            $rows = $db->select('airalo_packages', ['country', 'package_type', 'commission_type', 'value'], [
                'country' => array_keys($summaries),
                'status' => 1,
                'ORDER' => ['id' => 'ASC'],
            ]);
            foreach ((array) $rows as $row) {
                $iso = strtoupper((string) $row['country']);
                $kind = strtolower((string) $row['package_type']);
                if (!isset($commission[$iso][$kind])) {
                    $commission[$iso][$kind] = $row;
                }
            }
        }

        // Prices: supplier currency -> display currency (a currency the site does not have falls back to the default)
        $moduleCurrency = strtoupper((string) ($airaloModule['currency'] ?? 'USD'));
        $defaultCurrency = strtoupper((string) ($db->get('currencies', 'name', ['default' => '1']) ?: 'USD'));
        if (!preg_match('/^[A-Z]{3}$/', $displayCurrency) || !$db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => '1'])) {
            $displayCurrency = $defaultCurrency;
        }

        foreach ((array) $featuredRules as $rule) {
            if (count($featuredItems) >= 12) {
                break;
            }

            $iso = strtoupper((string) ($rule['country'] ?? ''));
            if ($iso === '') {
                continue;
            }

            $type = 'all';
            $countryName = (string) ($rule['country_name'] ?? $iso);
            $title = $countryName . ' eSIM';

            // Flags location logic
            $flagUrl = root . 'assets/img/flags/' . strtolower($iso) . '.svg';

            $featuredItems[] = [
                'id' => (int) ($rule['id'] ?? 0),
                'title' => $title,
                'country_iso' => $iso,
                'country_name' => $countryName,
                'package_type' => $type,
                'flag' => $flagUrl,
                'url' => root . 'esim/' . (int) $airaloModule['id'] . '/' . strtolower($iso) . '/' . $type . '/',
            ];

            // facts of the country, when the supplier has plans for it
            if (!empty($summaries[$iso])) {
                $sum = $summaries[$iso];
                $kind = (string) ($sum['price_type'] ?? 'local');
                $ruleFor = $commission[$iso][$kind] ?? $commission[$iso]['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
                $base = (float) $sum['price'];
                $markup = strtolower((string) $ruleFor['commission_type']) === 'percentage'
                    ? $base * (float) $ruleFor['value'] / 100
                    : (float) $ruleFor['value'];
                $price = function_exists('convertCurrencyAmount')
                    ? convertCurrencyAmount($db, $base + $markup, $moduleCurrency, $displayCurrency)
                    : round($base + $markup, 2);

                $featuredItems[count($featuredItems) - 1] += [
                    'price' => number_format(max(0, (float) $price), 2, '.', ','),
                    'currency' => $displayCurrency,
                    'network' => (string) ($sum['network'] ?? ''),
                    'mb_min' => (int) ($sum['mb_min'] ?? 0),
                    'mb_max' => (int) ($sum['mb_max'] ?? 0),
                    'unlimited' => !empty($sum['unlimited']),
                    'days_min' => (int) ($sum['days_min'] ?? 0),
                    'days_max' => (int) ($sum['days_max'] ?? 0),
                    'plans' => (int) ($sum['count'] ?? 0),
                ];
            }        }

        echo json_encode([
            'success' => true,
            'message' => 'Featured eSIMs fetched successfully',
            'data' => [
                'featured' => $featuredItems,
                'currency' => $displayCurrency,
                'countries' => (int) $db->count('airalo_countries', ['status' => 1]),
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch featured eSIMs: ' . $e->getMessage()
        ]);
        exit;
    }
});

// ============================================================================
// eSIM CATALOG API
// Every country eSIM plans are sold for, with the facts of its card: lowest
// price in the visitor's currency, network, number of plans, region.
// ============================================================================

if (!function_exists('esimRegionOf')) {
    // Part of the world of a country: middle_east, africa, europe, americas, asia_pacific
    function esimRegionOf($iso)
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $regions = [
                'middle_east' => 'AE SA QA KW BH OM YE IQ IR SY JO LB IL PS',
                'africa' => 'DZ AO BJ BW BF BI CM CV CF TD KM CG CD CI DJ EG GQ ER SZ ET GA GM GH GN GW KE LS LR LY MG MW ML MR MU YT MA MZ NA NE NG RE RW SH ST SN SC SL SO ZA SS SD TZ TG TN UG EH ZM ZW',
                'europe' => 'AL AD AM AT AZ BY BE BA BG HR CY CZ DK EE FO FI FR GE DE GI GR GG HU IS IE IM IT JE XK LV LI LT LU MT MD MC ME NL MK NO PL PT RO RU SM RS SK SI ES SJ SE CH TR UA GB VA AX',
                'americas' => 'AI AG AR AW BS BB BZ BM BO BQ BR VG CA KY CL CO CR CU CW DM DO EC SV FK GF GL GD GP GT GY HT HN JM MQ MX MS NI PA PY PE PR BL KN LC MF PM VC SX SR TT TC US UY VE VI',
            ];
            foreach ($regions as $region => $codes) {
                foreach (explode(' ', $codes) as $code) {
                    $map[$code] = $region;
                }
            }
        }
        return $map[strtoupper((string) $iso)] ?? 'asia_pacific';
    }
}

$router->get('/api/esim/catalog', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        $airaloModule = $db->get('modules', '*', ['name' => 'airalo', 'type' => 'esim', 'status' => 1]);
        if (!$airaloModule || !function_exists('_airalo_catalog')) {
            echo json_encode(['success' => true, 'message' => 'eSIM module is not enabled', 'data' => ['countries' => [], 'currency' => 'USD']]);
            exit;
        }

        $catalog = _airalo_catalog($db);

        // countries the admin has switched on, by name
        $names = [];
        foreach ((array) $db->select('airalo_countries', ['iso', 'nicename'], ['status' => 1, 'ORDER' => ['nicename' => 'ASC']]) as $row) {
            $names[strtoupper((string) $row['iso'])] = (string) $row['nicename'];
        }

        // commission rules and featured marks of all countries, in one question
        $commission = [];
        $featured = [];
        foreach ((array) $db->select('airalo_packages', ['country', 'package_type', 'commission_type', 'value', 'featured'], ['status' => 1, 'ORDER' => ['id' => 'ASC']]) as $row) {
            $iso = strtoupper((string) $row['country']);
            $kind = strtolower((string) $row['package_type']);
            if (!isset($commission[$iso][$kind])) {
                $commission[$iso][$kind] = $row;
            }
            if (!empty($row['featured'])) {
                $featured[$iso] = true;
            }
        }

        $moduleCurrency = strtoupper((string) ($airaloModule['currency'] ?? 'USD'));
        $defaultCurrency = strtoupper((string) ($db->get('currencies', 'name', ['default' => '1']) ?: 'USD'));
        $displayCurrency = strtoupper(trim((string) ($_GET['currency'] ?? ($_SESSION['app_currency'] ?? ''))));
        if (!preg_match('/^[A-Z]{3}$/', $displayCurrency) || !$db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => '1'])) {
            $displayCurrency = $defaultCurrency;
        }
        $rate = function_exists('getCurrencyConversionRate') ? (float) getCurrencyConversionRate($db, $moduleCurrency, $displayCurrency) : 1.0;

        $countries = [];
        foreach ($names as $iso => $name) {
            if (empty($catalog[$iso]['count'])) {
                continue;   // no plans for this country: nothing to sell
            }
            $sum = $catalog[$iso];
            $kind = (string) ($sum['price_type'] ?? 'local');
            $rule = $commission[$iso][$kind] ?? $commission[$iso]['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
            $base = (float) $sum['price'];
            $markup = strtolower((string) $rule['commission_type']) === 'percentage'
                ? $base * (float) $rule['value'] / 100
                : (float) $rule['value'];

            $countries[] = [
                'id' => $iso,
                'country_iso' => $iso,
                'country_name' => $name,
                'title' => $name . ' eSIM',
                'region' => esimRegionOf($iso),
                'featured' => !empty($featured[$iso]),
                'flag' => root . 'assets/img/flags/' . strtolower($iso) . '.svg',
                'image' => (string) ($sum['image'] ?? ''),
                'url' => root . 'esim/' . (int) $airaloModule['id'] . '/' . strtolower($iso) . '/all/',
                'price' => number_format(max(0, round(($base + $markup) * $rate, 2)), 2, '.', ','),
                'price_value' => round(($base + $markup) * $rate, 2),
                'currency' => $displayCurrency,
                'network' => (string) ($sum['network'] ?? ''),
                'mb_min' => (int) ($sum['mb_min'] ?? 0),
                'mb_max' => (int) ($sum['mb_max'] ?? 0),
                'unlimited' => !empty($sum['unlimited']),
                'days_min' => (int) ($sum['days_min'] ?? 0),
                'days_max' => (int) ($sum['days_max'] ?? 0),
                'plans' => (int) ($sum['count'] ?? 0),
                'recharge' => !empty($sum['recharge']),
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => 'eSIM catalog fetched successfully',
            'data' => ['countries' => $countries, 'currency' => $displayCurrency],
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch the eSIM catalog']);
        exit;
    }
});
