<?php

if (!function_exists('_airalo_root')) {
    function _airalo_root()
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('_airalo_cfg')) {
    function _airalo_cfg($db)
    {
        $module = null;
        try {
            $module = $db->get('modules', '*', ['name' => 'airalo', 'type' => 'esim']);
            if (!$module) {
                $module = $db->get('modules', '*', ['name' => 'airalo']);
            }
        } catch (Throwable $e) {
            return [];
        }

        if (!is_array($module)) {
            return [];
        }

        // Normalize runtime environment from modules.dev_mode when explicit env is not stored.
        if (empty($module['env'])) {
            $module['env'] = (!empty($module['dev_mode']) && (string)$module['dev_mode'] === '1') ? 'sandbox' : 'production';
        }

        return $module;
    }
}

if (!function_exists('_airalo_env')) {
    function _airalo_env($env)
    {
        $env = strtolower(trim((string) $env));
        return in_array($env, ['production', 'prod', 'live'], true) ? 'production' : 'sandbox';
    }
}

if (!function_exists('_airalo_base_url')) {
    function _airalo_base_url($env = 'sandbox')
    {
        // Airalo docs expose the same partner API host for the REST flow.
        return 'https://partners-api.airalo.com';
    }
}

if (!function_exists('_airalo_cache_dir')) {
    function _airalo_cache_dir()
    {
        $dir = _airalo_root() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'airalo';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('_airalo_cache_file')) {
    function _airalo_cache_file($cfg, $env)
    {
        $parts = [
            (string) ($cfg['c1'] ?? ''),
            (string) ($cfg['c2'] ?? ''),
            (string) $env,
        ];
        return _airalo_cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', implode('|', $parts)) . '.json';
    }
}

if (!function_exists('_airalo_read_json')) {
    function _airalo_read_json($raw)
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('_airalo_request')) {
    function _airalo_request($method, $path, array $opts = [])
    {
        $env = _airalo_env($opts['env'] ?? 'sandbox');
        $baseUrl = _airalo_base_url($env);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($opts['query']) && is_array($opts['query'])) {
            $query = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($opts['query']));
            if ($query !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
            }
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: PHPTRAVELS-Airalo/1.0',
        ];

        if (!empty($opts['token'])) {
            $headers[] = 'Authorization: Bearer ' . $opts['token'];
        }

        if (!empty($opts['accept_language'])) {
            $headers[] = 'Accept-Language: ' . $opts['accept_language'];
        }

        if (!empty($opts['headers']) && is_array($opts['headers'])) {
            $headers = array_merge($headers, $opts['headers']);
        }

        $ch = curl_init($url);
        $payload = null;

        if (!empty($opts['form']) && is_array($opts['form'])) {
            // Airalo token/order endpoints expect urlencoded form fields, not multipart bodies.
            $payload = http_build_query($opts['form']);
            $hasContentType = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Content-Type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } elseif (array_key_exists('json', $opts)) {
            $payload = json_encode($opts['json']);
            $headers[] = 'Content-Type: application/json';
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? 10),
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        if ($payload !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $curlOptions);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno || $raw === false) {
            return [
                'ok' => false,
                'status' => 0,
                'url' => $url,
                'raw' => '',
                'data' => null,
                'headers' => '',
                'error' => $error ?: 'cURL error ' . $errno,
            ];
        }

        $headerText = substr($raw, 0, $headerSize);
        $body = trim(substr($raw, $headerSize));
        $data = _airalo_read_json($body);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'url' => $url,
            'raw' => $body,
            'data' => $data,
            'headers' => $headerText,
            'error' => $data['meta']['message'] ?? $data['message'] ?? $data['error'] ?? $data['reason'] ?? ('HTTP ' . $status),
        ];
    }
}

if (!function_exists('_airalo_token')) {
    function _airalo_token($db, $force = false, $envOverride = null)
    {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($envOverride ?? ($cfg['env'] ?? 'sandbox'));
        $cacheFile = _airalo_cache_file($cfg, $env);

        if (!$force && is_file($cacheFile)) {
            $cached = _airalo_read_json(@file_get_contents($cacheFile));
            if (!empty($cached['access_token']) && !empty($cached['expires_at']) && time() < (int) $cached['expires_at']) {
                return [
                    'ok' => true,
                    'token' => $cached['access_token'],
                    'expires_in' => max(0, (int) $cached['expires_at'] - time()),
                    'cached' => true,
                    'env' => $env,
                ];
            }
        }

        $clientId = trim((string) ($cfg['c1'] ?? ''));
        $clientSecret = trim((string) ($cfg['c2'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return [
                'ok' => false,
                'error' => 'Airalo client_id/client_secret are missing in the modules table.',
                'env' => $env,
            ];
        }

        $tokenRes = _airalo_request('POST', '/v2/token', [
            'env' => $env,
            'form' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ],
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'timeout' => 30,
        ]);

        if (!$tokenRes['ok'] || empty($tokenRes['data']['data']['access_token'])) {
            return [
                'ok' => false,
                'error' => $tokenRes['error'] ?: 'Failed to obtain Airalo access token',
                'status' => $tokenRes['status'],
                'response' => $tokenRes['data'],
                'env' => $env,
            ];
        }

        $payload = $tokenRes['data']['data'];
        $expiresIn = (int) ($payload['expires_in'] ?? 86400);
        $cache = [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => time() + max(300, $expiresIn - 60),
        ];
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES));

        return [
            'ok' => true,
            'token' => $cache['access_token'],
            'expires_in' => $expiresIn,
            'cached' => false,
            'env' => $env,
        ];
    }
}

if (!function_exists('_airalo_request_with_token')) {
    function _airalo_request_with_token($db, $method, $path, array $opts = [])
    {
        $tokenRes = _airalo_token($db, !empty($opts['refresh_token']), $opts['env'] ?? null);
        if (empty($tokenRes['ok'])) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => '',
                'error' => $tokenRes['error'] ?? 'Unable to obtain Airalo token',
                'token' => null,
            ];
        }

        $opts['token'] = $tokenRes['token'];
        $opts['env'] = $tokenRes['env'] ?? ($opts['env'] ?? 'sandbox');
        $res = _airalo_request($method, $path, $opts);
        $res['token'] = $tokenRes['token'];
        return $res;
    }
}

if (!function_exists('_airalo_packages_many')) {
    /**
     * Answers of /v2/packages for several questions at once.
     *
     *   $queries  [key => ['limit' => 24, 'page' => 1, 'filter[country]' => 'PK', 'filter[type]' => 'local'], ...]
     *   returns   [key => ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string, 'cached' => bool]]
     *
     * An answer is kept in app/cache/airalo/pk_{env}_{hash}.json for $ttl seconds.
     * Questions without a fresh answer are asked together (in parallel), so ten
     * countries cost about as much time as one. When the supplier fails, an older
     * answer is used if there is one: a page with yesterday's plans is better than
     * an error.
     */
    function _airalo_packages_many($db, array $queries, $ttl = 600, $timeout = 45)
    {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($cfg['env'] ?? 'sandbox');
        $dir = _airalo_cache_dir();
        $now = time();
        $out = [];
        $ask = [];

        foreach ($queries as $key => $query) {
            ksort($query);
            $file = $dir . DIRECTORY_SEPARATOR . 'pk_' . $env . '_' . sha1(json_encode($query)) . '.json';
            $kept = is_file($file) ? _airalo_read_json(@file_get_contents($file)) : null;
            if (is_array($kept) && isset($kept['data']) && ($now - (int) ($kept['at'] ?? 0)) <= $ttl) {
                $out[$key] = ['ok' => true, 'status' => 200, 'data' => $kept['data'], 'error' => '', 'cached' => true];
                continue;
            }
            $ask[$key] = ['query' => $query, 'file' => $file, 'old' => is_array($kept) ? ($kept['data'] ?? null) : null];
        }

        if ($ask) {
            $token = _airalo_token($db, false, $env);
            $handles = [];
            $multi = null;

            if (!empty($token['ok']) && function_exists('curl_multi_init')) {
                $multi = curl_multi_init();
                foreach ($ask as $key => $item) {
                    $url = rtrim(_airalo_base_url($env), '/') . '/v2/packages?'
                         . str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($item['query']));
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Accept: application/json',
                            'User-Agent: PHPTRAVELS-Airalo/1.0',
                            'Authorization: Bearer ' . $token['token'],
                        ],
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => (int) $timeout,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0,
                    ]);
                    curl_multi_add_handle($multi, $ch);
                    $handles[$key] = $ch;
                }
                do {
                    $state = curl_multi_exec($multi, $running);
                    if ($running) {
                        curl_multi_select($multi, 1.0);
                    }
                } while ($running && $state === CURLM_OK);
            }

            foreach ($ask as $key => $item) {
                $status = 0;
                $data = null;
                $error = $token['error'] ?? 'The eSIM supplier could not be reached';
                if (isset($handles[$key])) {
                    $status = (int) curl_getinfo($handles[$key], CURLINFO_HTTP_CODE);
                    $body = curl_multi_getcontent($handles[$key]);
                    $curlError = curl_error($handles[$key]);
                    curl_multi_remove_handle($multi, $handles[$key]);
                    curl_close($handles[$key]);
                    $data = _airalo_read_json($body);
                    $error = $curlError ?: ($data['meta']['message'] ?? $data['message'] ?? $data['error'] ?? ('HTTP ' . $status));
                }

                if ($status >= 200 && $status < 300 && is_array($data)) {
                    $tmp = $item['file'] . '.' . uniqid('', true) . '.tmp';
                    if (@file_put_contents($tmp, json_encode(['at' => $now, 'data' => $data], JSON_UNESCAPED_SLASHES)) !== false) {
                        if (!@rename($tmp, $item['file'])) {
                            @copy($tmp, $item['file']);
                            @unlink($tmp);
                        }
                    }
                    $out[$key] = ['ok' => true, 'status' => $status, 'data' => $data, 'error' => '', 'cached' => false];
                } elseif ($item['old'] !== null) {
                    $out[$key] = ['ok' => true, 'status' => 200, 'data' => $item['old'], 'error' => '', 'cached' => true];
                } else {
                    $out[$key] = ['ok' => false, 'status' => $status, 'data' => $data, 'error' => (string) $error, 'cached' => false];
                }
            }
            if ($multi) {
                curl_multi_close($multi);
            }

            // now and then: throw away answers nobody has asked for in two days
            if (mt_rand(1, 40) === 1) {
                foreach ((array) glob($dir . DIRECTORY_SEPARATOR . 'pk_*.json') as $old) {
                    if (@filemtime($old) < $now - 172800) {
                        @unlink($old);
                    }
                }
            }
        }

        return $out;
    }
}

if (!function_exists('_airalo_global_for_country')) {
    /**
     * From the answer with all global and regional plans: the ones that can be
     * used in the given country. A group of plans counts when one of its
     * operators covers the country (the same rule the plans page has always used).
     */
    function _airalo_global_for_country($response, $iso)
    {
        $iso = strtoupper((string) $iso);
        $kept = [];
        foreach ((array) ($response['data'] ?? []) as $group) {
            foreach ((array) ($group['operators'] ?? []) as $operator) {
                foreach ((array) ($operator['countries'] ?? []) as $country) {
                    $code = $country['code'] ?? $country['country_code'] ?? '';
                    if ($code !== '' && strcasecmp((string) $code, $iso) === 0) {
                        $kept[] = $group;
                        continue 3;
                    }
                }
            }
        }
        return ['data' => $kept];
    }
}

if (!function_exists('_airalo_summarise_packages')) {
    /**
     * The facts a featured card shows for one country, from one or more
     * /v2/packages answers: lowest price, smallest and largest data plan, shortest
     * and longest validity, best network. Prices stay as the supplier sends them
     * (no commission, no conversion); 'price_type' says which kind of plan has the
     * lowest price, because commission can differ between local and global plans.
     * With $iso, only the networks of that country are read (a global plan lists
     * the networks of every country it covers).
     */
    function _airalo_summarise_packages(array $responses, $iso = '')
    {
        $iso = strtoupper((string) $iso);
        $sum = ['count' => 0, 'price' => 0.0, 'price_type' => 'local', 'mb_min' => 0, 'mb_max' => 0,
                'unlimited' => false, 'days_min' => 0, 'days_max' => 0, 'network' => ''];
        $rank = ['3G' => 1, 'LTE' => 2, '4G' => 2, '5G' => 3];
        $best = 0;

        foreach ($responses as $response) {
            foreach ((array) ($response['data'] ?? []) as $country) {
                foreach ((array) ($country['operators'] ?? []) as $operator) {
                    $kind = strtolower((string) ($operator['type'] ?? 'local'));
                    foreach ((array) ($operator['coverages'] ?? []) as $coverage) {
                        $place = strtoupper((string) ($coverage['code'] ?? $coverage['name'] ?? ''));
                        if ($iso !== '' && $place !== '' && $place !== $iso) {
                            continue;
                        }
                        foreach ((array) ($coverage['networks'] ?? []) as $network) {
                            foreach ((array) ($network['types'] ?? []) as $type) {
                                $type = strtoupper(trim((string) $type));
                                if (($rank[$type] ?? 0) > $best) {
                                    $best = $rank[$type];
                                    $sum['network'] = $type === 'LTE' ? '4G' : $type;
                                }
                            }
                        }
                    }
                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $price = 0.0;
                        foreach (['price', 'net_price', 'retail_price', 'sale_price'] as $k) {
                            if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                                $price = (float) $pkg[$k];
                                break;
                            }
                        }
                        if ($price <= 0) {
                            continue;
                        }
                        $sum['count']++;
                        if ($sum['price'] <= 0 || $price < $sum['price']) {
                            $sum['price'] = $price;
                            $sum['price_type'] = $kind === 'global' ? 'global' : 'local';
                        }
                        if (!empty($pkg['is_unlimited'])) {
                            $sum['unlimited'] = true;
                        } elseif (!empty($pkg['amount']) && is_numeric($pkg['amount'])) {
                            $mb = (int) $pkg['amount'];
                            $sum['mb_min'] = $sum['mb_min'] > 0 ? min($sum['mb_min'], $mb) : $mb;
                            $sum['mb_max'] = max($sum['mb_max'], $mb);
                        }
                        if (!empty($pkg['day']) && is_numeric($pkg['day'])) {
                            $day = (int) $pkg['day'];
                            $sum['days_min'] = $sum['days_min'] > 0 ? min($sum['days_min'], $day) : $day;
                            $sum['days_max'] = max($sum['days_max'], $day);
                        }
                    }
                }
            }
        }
        return $sum;
    }
}

if (!function_exists('_airalo_featured_summaries')) {
    /**
     * Facts for the featured cards of the given countries: [ISO => summary].
     * A card tells about the local plans of its country. A country without local
     * plans is described by the global plans that cover it.
     *
     * The facts are kept in app/cache/airalo/featured_{env}.json for $ttl seconds.
     * A country the supplier has no plans for is not in the result.
     */
    function _airalo_featured_summaries($db, array $isos, $ttl = 43200, $timeout = 20)
    {
        $isos = array_values(array_unique(array_filter(array_map(static function ($iso) {
            $iso = strtoupper(trim((string) $iso));
            return preg_match('/^[A-Z]{2}$/', $iso) ? $iso : '';
        }, $isos))));
        if (!$isos) {
            return [];
        }

        $cfg  = _airalo_cfg($db);
        $env  = _airalo_env($cfg['env'] ?? 'sandbox');
        $file = _airalo_cache_dir() . DIRECTORY_SEPARATOR . 'featured_' . $env . '.json';
        $cache = is_file($file) ? (_airalo_read_json(@file_get_contents($file)) ?: []) : [];
        $now = time();

        $ask = [];
        foreach ($isos as $iso) {
            if (empty($cache[$iso]['at']) || ($now - (int) $cache[$iso]['at']) > $ttl || !isset($cache[$iso]['price_type'])) {
                $ask[] = $iso;
            }
        }

        if ($ask) {
            $queries = ['global' => ['limit' => 200, 'page' => 1, 'filter[type]' => 'global']];
            foreach ($ask as $iso) {
                $queries[$iso] = ['limit' => 50, 'page' => 1, 'filter[country]' => $iso, 'filter[type]' => 'local'];
            }
            $answers = _airalo_packages_many($db, $queries, $ttl, $timeout);
            $global = !empty($answers['global']['ok']) ? (array) $answers['global']['data'] : null;

            foreach ($ask as $iso) {
                $facts = null;
                if (!empty($answers[$iso]['ok'])) {
                    $facts = _airalo_summarise_packages([(array) $answers[$iso]['data']], $iso);
                }
                if (($facts === null || empty($facts['count'])) && $global !== null) {
                    $facts = _airalo_summarise_packages([_airalo_global_for_country($global, $iso)], $iso);
                }
                if ($facts !== null) {
                    $cache[$iso] = $facts + ['at' => $now];
                } elseif (empty($cache[$iso])) {
                    // no answer and nothing known: ask again in ten minutes, not on every page view
                    $cache[$iso] = ['count' => 0, 'price_type' => 'local', 'at' => $now - (int) $ttl + 600];
                }
            }

            $tmp = $file . '.' . uniqid('', true) . '.tmp';
            if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false) {
                if (!@rename($tmp, $file)) {
                    @copy($tmp, $file);
                    @unlink($tmp);
                }
            }
        }

        $out = [];
        foreach ($isos as $iso) {
            if (!empty($cache[$iso]['count'])) {
                $out[$iso] = $cache[$iso];
            }
        }
        return $out;
    }
}

if (!function_exists('_airalo_country_packages')) {
    /**
     * The plans of one country as a customer sees them: supplier price plus our
     * commission, in the currency of the visitor.
     *
     *   $type      'all' (local + global), 'local', 'global'
     *   $currency  wanted display currency; empty = session currency, else the site default
     *   returns    ['success' => bool, 'message' => string, 'packages' => [...],
     *               'currency' => ..., 'base_currency' => ..., 'module_currency' => ...]
     *
     * Each plan also carries '_net': the supplier price in the display currency.
     * Keys that start with "_" are for the server only and must not be sent to a browser.
     */
    function _airalo_country_packages($db, array $module, $country, $type = 'all', $currency = '', $page = 1)
    {
        $country = strtoupper(trim((string) $country));
        $type = strtolower(trim((string) $type));
        $page = max(1, (int) $page);
        $fail = static function ($message) {
            return ['success' => false, 'message' => (string) $message, 'packages' => []];
        };
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return $fail('Country not found');
        }

        // ---- ask the supplier (answers are kept for ten minutes)
        $queries = [];
        if ($type === '' || $type === 'all' || $type === 'global') {
            $queries['global'] = ['limit' => 200, 'page' => 1, 'filter[type]' => 'global'];
        }
        if ($type !== 'global') {
            $queries['local'] = ['limit' => 24, 'page' => $page, 'filter[country]' => $country,
                                 'filter[type]' => ($type === '' || $type === 'all') ? 'local' : $type];
        }
        $answers = _airalo_packages_many($db, $queries, 600, 45);

        $groups = [];
        $failed = '';
        foreach (['local', 'global'] as $part) {
            if (!isset($answers[$part])) {
                continue;
            }
            if (empty($answers[$part]['ok'])) {
                $failed = (string) ($answers[$part]['error'] ?: 'The eSIM supplier did not answer');
                continue;
            }
            $data = (array) $answers[$part]['data'];
            if ($part === 'global') {
                $data = _airalo_global_for_country($data, $country);
            }
            $groups = array_merge($groups, (array) ($data['data'] ?? []));
        }
        if (!$groups && $failed !== '' && count($answers) === count(array_filter($answers, static fn($a) => empty($a['ok'])))) {
            return $fail($failed);
        }

        // ---- commission rules of the country, by kind of plan
        $rules = [];
        foreach ((array) $db->select('airalo_packages', '*', ['country' => $country, 'status' => 1]) as $rule) {
            $kind = strtolower((string) ($rule['package_type'] ?? 'all'));
            if (in_array($kind, ['all', 'global', 'local'], true) && !isset($rules[$kind])) {
                $rules[$kind] = $rule;
            }
        }

        // ---- currencies: supplier -> display (session), falling back to the site default
        $moduleCurrency = strtoupper((string) ($module['currency'] ?? 'USD'));
        $baseCurrency = strtoupper((string) ($db->get('currencies', 'name', ['default' => '1']) ?: 'USD'));
        $display = strtoupper(trim((string) $currency));
        if ($display === '') {
            $display = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')));
        }
        if ($display === '' || !preg_match('/^[A-Z]{3}$/', $display) || !$db->get('currencies', 'name', ['name' => $display, 'status' => '1'])) {
            $display = $baseCurrency;
        }
        $convert = static function ($amount) use ($db, $moduleCurrency, $display) {
            return function_exists('convertCurrencyAmount')
                ? (float) convertCurrencyAmount($db, $amount, $moduleCurrency, $display)
                : round(max(0, (float) $amount), 2);
        };

        $packages = [];
        foreach ($groups as $group) {
            $groupTitle = (string) ($group['title'] ?? $country);
            foreach ((array) ($group['operators'] ?? []) as $operator) {
                $kind = strtolower((string) ($operator['type'] ?? 'local'));
                foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                    $net = 0.0;
                    foreach (['price', 'net_price', 'retail_price', 'sale_price', 'amount'] as $k) {
                        if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                            $net = (float) $pkg[$k];
                            break;
                        }
                    }

                    $duration = 'N/A';
                    foreach (['day', 'validity', 'duration', 'duration_days'] as $k) {
                        if (!empty($pkg[$k])) {
                            $duration = is_numeric($pkg[$k])
                                ? ((int) $pkg[$k]) . ' day' . ((int) $pkg[$k] === 1 ? '' : 's')
                                : (string) $pkg[$k];
                            break;
                        }
                    }

                    $data = 'N/A';
                    foreach (['data', 'data_limit', 'gb'] as $k) {
                        if (!empty($pkg[$k])) {
                            $data = (string) $pkg[$k];
                            break;
                        }
                    }

                    $rule = $rules[$kind] ?? $rules['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
                    $markup = strtolower((string) ($rule['commission_type'] ?? 'fixed')) === 'percentage'
                        ? $net * (float) ($rule['value'] ?? 0) / 100
                        : (float) ($rule['value'] ?? 1);

                    $packages[] = [
                        'id' => (string) ($pkg['id'] ?? uniqid('pkg_', true)),
                        'title' => (string) ($pkg['title'] ?? 'Package'),
                        'country' => $groupTitle,
                        'data_limit' => $data,
                        'duration' => $duration,
                        'days' => (int) ($pkg['day'] ?? 0),
                        'unlimited' => !empty($pkg['is_unlimited']),
                        'operator' => (string) ($operator['title'] ?? ''),
                        'price' => round(max(0, $convert($net + $markup)), 2),
                        'currency' => $display,
                        'module_currency' => $moduleCurrency,
                        'base_currency' => $baseCurrency,
                        'package_type' => $kind,
                        '_net' => round(max(0, $convert($net)), 2),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'message' => $packages ? '' : 'No packages found for this country and type.',
            'packages' => $packages,
            'currency' => $display,
            'base_currency' => $baseCurrency,
            'module_currency' => $moduleCurrency,
        ];
    }
}

if (!function_exists('_airalo_catalog')) {
    /**
     * Facts of every country the supplier has local plans for: [ISO => facts].
     * facts = what _airalo_summarise_packages() gives, plus 'title', 'image'
     * (picture of the plan card) and 'recharge' (a plan can be topped up).
     *
     * The supplier sends the whole catalog in one answer of several megabytes, so
     * only the facts are kept (app/cache/airalo/catalog_{env}.json, a few kilobytes),
     * for $ttl seconds. When the supplier fails, the last known catalog is used.
     */
    function _airalo_catalog($db, $ttl = 43200)
    {
        $cfg  = _airalo_cfg($db);
        $env  = _airalo_env($cfg['env'] ?? 'sandbox');
        $file = _airalo_cache_dir() . DIRECTORY_SEPARATOR . 'catalog_' . $env . '.json';
        $kept = is_file($file) ? _airalo_read_json(@file_get_contents($file)) : null;
        $now  = time();

        if (is_array($kept) && !empty($kept['countries']) && ($now - (int) ($kept['at'] ?? 0)) <= $ttl) {
            return (array) $kept['countries'];
        }
        // asked a moment ago without luck: do not ask again on every page view
        if (is_array($kept) && ($now - (int) ($kept['tried'] ?? 0)) < 600) {
            return (array) ($kept['countries'] ?? []);
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(90);

        $countries = [];
        $page = 1;
        $failed = false;
        do {
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env' => $env,
                'query' => ['limit' => 300, 'page' => $page, 'filter[type]' => 'local'],
                'timeout' => 60,
            ]);
            if (empty($res['ok']) || empty($res['data']['data'])) {
                $failed = $page === 1;
                break;
            }
            foreach ((array) $res['data']['data'] as $country) {
                $iso = strtoupper((string) ($country['country_code'] ?? ''));
                if (!preg_match('/^[A-Z]{2}$/', $iso)) {
                    continue;
                }
                $facts = _airalo_summarise_packages([['data' => [$country]]], $iso);
                if (empty($facts['count'])) {
                    continue;
                }
                $recharge = false;
                foreach ((array) ($country['operators'] ?? []) as $operator) {
                    if (!empty($operator['rechargeability'])) {
                        $recharge = true;
                    }
                }
                $countries[$iso] = $facts + [
                    'title' => (string) ($country['title'] ?? $iso),
                    'image' => (string) ($country['image']['url'] ?? ''),
                    'recharge' => $recharge,
                ];
            }
            $last = (int) ($res['data']['meta']['last_page'] ?? 1);
            unset($res);
            $page++;
        } while ($page <= $last && $page <= 5);

        if ($failed || !$countries) {
            $old = is_array($kept) ? (array) ($kept['countries'] ?? []) : [];
            @file_put_contents($file, json_encode(['at' => (int) ($kept['at'] ?? 0), 'tried' => $now, 'countries' => $old], JSON_UNESCAPED_SLASHES));
            return $old;
        }

        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['at' => $now, 'tried' => $now, 'countries' => $countries], JSON_UNESCAPED_SLASHES)) !== false) {
            if (!@rename($tmp, $file)) {
                @copy($tmp, $file);
                @unlink($tmp);
            }
        }
        return $countries;
    }
}

if (!function_exists('_airalo_is_admin')) {
    // An administrator is logged in, in this browser session.
    function _airalo_is_admin()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        return !empty($_SESSION['user_id']) && strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin';
    }
}

if (!function_exists('_airalo_deny')) {
    // Ends the request: the caller may not use this endpoint.
    function _airalo_deny($message = 'You are not allowed to do this.')
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => false,
            'success' => false,
            'message' => $message,
            'response_error' => $message,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('_airalo_admin_only')) {
    // For endpoints that spend money at the supplier or show what only staff may see.
    function _airalo_admin_only()
    {
        if (!_airalo_is_admin()) {
            _airalo_deny('Administrator login is required.');
        }
    }
}
