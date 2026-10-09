<?php
// ============================================================================
// GRNCONNECT API CLIENT - REUSABLE SERVICE CLASS
// ============================================================================
// Handles authentication (api-key header), base URL config, request building,
// response handling, error mapping, logging and timeout handling for the
// GRNConnect Hotel API v3.
//
// Booking / availability endpoints : c2 (Base URL) e.g. https://api-sandbox.grnconnect.com
// Static content endpoints (CDN)   : c3 (Static URL) default https://cdn-api.grnconnect.com
// Authentication                   : c1 (API Key) sent as "api-key" header
// ============================================================================

if (!class_exists('GRNConnectClient')) {

    class GRNConnectClient
    {
        /** @var string */
        private $apiKey;
        /** @var string */
        private $baseUrl;
        /** @var string */
        private $staticBaseUrl;
        /** @var int */
        private $connectTimeout;
        /** @var int */
        private $requestTimeout;
        /** @var bool */
        private $logging;

        // Static assets version. 2.0 = new numeric codes that match the
        // hotel_code / city_code returned by the availability endpoint.
        const STATIC_VERSION = '2.0';

        public function __construct($apiKey, $baseUrl, $staticBaseUrl = '', array $options = [])
        {
            $this->apiKey        = trim((string)$apiKey);
            $this->baseUrl       = rtrim(trim((string)$baseUrl), '/');
            $staticBaseUrl       = trim((string)$staticBaseUrl);
            $this->staticBaseUrl = $staticBaseUrl !== '' ? rtrim($staticBaseUrl, '/') : 'https://cdn-api.grnconnect.com';

            $this->connectTimeout = (int)($options['connect_timeout'] ?? (defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10));
            $this->requestTimeout = (int)($options['request_timeout'] ?? (defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30));
            $this->logging        = array_key_exists('logging', $options) ? (bool)$options['logging'] : true;
        }

        /**
         * Build a client instance from a `modules` row.
         *
         * @param array $module modules table row for grnconnect
         */
        public static function fromModule(array $module, array $options = [])
        {
            $apiKey    = $module['c1'] ?? '';
            $baseUrl   = $module['c2'] ?? '';
            $staticUrl = $module['c3'] ?? '';

            if (!isset($options['logging'])) {
                $options['logging'] = !isset($module['logging_enabled']) || (int)$module['logging_enabled'] === 1;
            }

            return new self($apiKey, $baseUrl, $staticUrl, $options);
        }

        public function hasCredentials()
        {
            return $this->apiKey !== '' && $this->baseUrl !== '';
        }

        public function getBaseUrl()
        {
            return $this->baseUrl;
        }

        public function getStaticBaseUrl()
        {
            return $this->staticBaseUrl;
        }

        // --------------------------------------------------------------------
        // CORE REQUEST BUILDER
        // --------------------------------------------------------------------
        /**
         * Perform an HTTP request against the GRNConnect API.
         *
         * @param string $method GET|POST|DELETE|PUT
         * @param string $path   e.g. api/v3/hotels/availability
         * @param array  $opts   json(array), query(array), static(bool),
         *                        timeout(int), category(string), action(string)
         * @return array {success, http_code, data, raw, error, error_code, duration_ms}
         */
        public function request($method, $path, array $opts = [])
        {
            $prepared = $this->prepareRequest($method, $path, $opts);
            if ($prepared === null) {
                return [
                    'success' => false, 'http_code' => 0, 'data' => null, 'raw' => null,
                    'error' => 'Invalid request (missing base URL or path)', 'error_code' => '',
                    'duration_ms' => 0,
                ];
            }

            $raw      = curl_exec($prepared['ch']);
            $httpCode = (int)curl_getinfo($prepared['ch'], CURLINFO_HTTP_CODE);
            $curlErrno = (int)curl_errno($prepared['ch']);
            $curlErr  = curl_error($prepared['ch']);
            $duration = (int)round((microtime(true) - $prepared['start']) * 1000);
            curl_close($prepared['ch']);

            return $this->finalizeResponse(
                $raw,
                $httpCode,
                $curlErr,
                $duration,
                $prepared['opts'],
                $prepared['url'],
                $prepared['method'],
                $curlErrno
            );
        }

        // --------------------------------------------------------------------
        // API OPERATIONS
        // --------------------------------------------------------------------

        /** Hotel search / availability. rates = concise|comprehensive */
        public function availability(array $payload, $timeout = null)
        {
            return $this->request('POST', 'api/v3/hotels/availability', [
                'json'     => $payload,
                'timeout'  => $timeout,
                'category' => 'search',
                'action'   => ($payload['rates'] ?? 'concise'),
            ]);
        }

        /**
         * Fire multiple availability requests concurrently (curl_multi).
         * Used for GRN-recommended parallel batches of ≤100 hotel_codes.
         *
         * @param array $payloads list of availability JSON bodies
         * @param int|null $timeout per-request timeout seconds
         * @return array list of request() result arrays (same order as $payloads)
         */
        public function availabilityParallel(array $payloads, $timeout = null)
        {
            $jobs = [];
            foreach ($payloads as $payload) {
                if (!is_array($payload)) {
                    continue;
                }
                $jobs[] = [
                    'method' => 'POST',
                    'path'   => 'api/v3/hotels/availability',
                    'opts'   => [
                        'json'     => $payload,
                        'timeout'  => $timeout,
                        'category' => 'search',
                        'action'   => ($payload['rates'] ?? 'concise'),
                    ],
                ];
            }
            $results = $this->requestMulti($jobs);

            // Some shared hosts break curl_multi (HTTP 0 / empty body) while
            // sequential curl_exec still works. Fall back once if every job failed that way.
            if (count($results) > 1 && $this->allTransportFailures($results)) {
                $fallback = [];
                foreach ($jobs as $job) {
                    $fallback[] = $this->request(
                        $job['method'] ?? 'POST',
                        $job['path'] ?? '',
                        is_array($job['opts'] ?? null) ? $job['opts'] : []
                    );
                }
                return $fallback;
            }

            return $results;
        }

        /**
         * Re-fetch full search results by search_id (no hcode).
         * Call while more_results === true until it becomes false.
         */
        public function refetchBySearchId($searchId, $rates = 'concise')
        {
            return $this->request('GET', 'api/v3/hotels/availability/' . rawurlencode($searchId), [
                'query'    => ['rates' => $rates ?: 'concise'],
                'category' => 'search',
                'action'   => 'refetch_by_search_id',
            ]);
        }

        /**
         * Parallel re-fetch by search_id for unfinished batches.
         *
         * @param array $searchIds list of search_id strings
         * @param string $rates concise|comprehensive
         * @return array list of request() results (same order)
         */
        public function refetchBySearchIdParallel(array $searchIds, $rates = 'concise')
        {
            $jobs = [];
            foreach ($searchIds as $searchId) {
                $searchId = trim((string)$searchId);
                if ($searchId === '') {
                    continue;
                }
                $jobs[] = [
                    'method' => 'GET',
                    'path'   => 'api/v3/hotels/availability/' . rawurlencode($searchId),
                    'opts'   => [
                        'query'    => ['rates' => $rates ?: 'concise'],
                        'category' => 'search',
                        'action'   => 'refetch_by_search_id',
                    ],
                    '_search_id' => $searchId,
                ];
            }
            $results = $this->requestMulti($jobs);
            if (count($results) > 1 && $this->allTransportFailures($results)) {
                $fallback = [];
                foreach ($jobs as $job) {
                    $fallback[] = $this->request(
                        $job['method'] ?? 'GET',
                        $job['path'] ?? '',
                        is_array($job['opts'] ?? null) ? $job['opts'] : []
                    );
                }
                return $fallback;
            }
            return $results;
        }

        /** Re-fetch a hotel after concise search. Pass bundled=true for multi-room bundles. */
        public function refetch($searchId, $hcode, $bundled = false)
        {
            $query = ['hcode' => $hcode];
            if ($bundled) {
                $query['bundled'] = 'true';
            }

            return $this->request('GET', 'api/v3/hotels/availability/' . rawurlencode($searchId), [
                'query'    => $query,
                'category' => 'search',
                'action'   => 'refetch',
            ]);
        }

        /**
         * Execute multiple GRN HTTP requests concurrently.
         *
         * @param array $jobs each: method, path, opts (+ optional passthrough keys)
         * @return array results aligned with $jobs
         */
        public function requestMulti(array $jobs)
        {
            if (empty($jobs)) {
                return [];
            }

            // Single job — reuse sequential path (logging identical)
            if (count($jobs) === 1) {
                $job = $jobs[0];
                $res = $this->request(
                    $job['method'] ?? 'GET',
                    $job['path'] ?? '',
                    is_array($job['opts'] ?? null) ? $job['opts'] : []
                );
                return [$res];
            }

            $mh = curl_multi_init();
            if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
                @curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 10);
            }

            $handles = [];
            $meta    = [];

            foreach ($jobs as $i => $job) {
                $prepared = $this->prepareRequest(
                    $job['method'] ?? 'GET',
                    $job['path'] ?? '',
                    is_array($job['opts'] ?? null) ? $job['opts'] : []
                );
                if ($prepared === null) {
                    $handles[$i] = null;
                    $meta[$i] = null;
                    continue;
                }
                $handles[$i] = $prepared['ch'];
                $meta[$i] = $prepared;
                curl_multi_add_handle($mh, $prepared['ch']);
            }

            $running = null;
            do {
                do {
                    $status = curl_multi_exec($mh, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($running) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running && $status === CURLM_OK);

            // Collect per-handle result codes (curl_error is often empty on multi).
            $multiErrors = [];
            while ($infoMsg = curl_multi_info_read($mh)) {
                if (!is_array($infoMsg) || empty($infoMsg['handle'])) {
                    continue;
                }
                $resultCode = (int)($infoMsg['result'] ?? 0);
                if ($resultCode !== CURLE_OK) {
                    $multiErrors[(int)$infoMsg['handle']] = [
                        'errno' => $resultCode,
                        'error' => function_exists('curl_strerror')
                            ? curl_strerror($resultCode)
                            : ('curl multi error #' . $resultCode),
                    ];
                }
            }

            $results = [];
            foreach ($handles as $i => $ch) {
                if ($ch === null || !isset($meta[$i])) {
                    $results[$i] = [
                        'success' => false, 'http_code' => 0, 'data' => null,
                        'raw' => null, 'error' => 'Invalid request job', 'error_code' => '',
                        'duration_ms' => 0,
                    ];
                    continue;
                }

                $info = $meta[$i];
                $raw  = curl_multi_getcontent($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErrno = (int)curl_errno($ch);
                $curlErr  = curl_error($ch);
                $handleKey = (int)$ch;
                if ($curlErr === '' && isset($multiErrors[$handleKey])) {
                    $curlErrno = (int)$multiErrors[$handleKey]['errno'];
                    $curlErr = (string)$multiErrors[$handleKey]['error'];
                }
                $duration = (int)round((microtime(true) - $info['start']) * 1000);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $results[$i] = $this->finalizeResponse(
                    $raw,
                    $httpCode,
                    $curlErr,
                    $duration,
                    $info['opts'],
                    $info['url'],
                    $info['method'],
                    $curlErrno
                );
            }

            curl_multi_close($mh);
            ksort($results);
            return array_values($results);
        }

        /**
         * Build a curl handle + metadata for request / requestMulti.
         * @return array{ch:resource,url:string,method:string,opts:array,start:float}|null
         */
        private function prepareRequest($method, $path, array $opts = [])
        {
            $useStatic = !empty($opts['static']);
            $base      = $useStatic ? $this->staticBaseUrl : $this->baseUrl;
            if ($base === '' || $path === '') {
                return null;
            }

            $url = $base . '/' . ltrim($path, '/');
            if (!empty($opts['query']) && is_array($opts['query'])) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($opts['query']);
            }

            $method  = strtoupper($method);
            $timeout = (int)($opts['timeout'] ?? $this->requestTimeout);

            $headers = [
                'api-key: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
                'api-version: v3',
            ];

            $body = null;
            if (isset($opts['json'])) {
                $body = json_encode($opts['json'], JSON_UNESCAPED_SLASHES);
            }

            $ch = curl_init();
            $curlOpts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => $timeout,
                // Shared hosts often lack CA bundles / break on IPv6 or HTTP/2.
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING       => '',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ];
            if (defined('CURL_HTTP_VERSION_1_1')) {
                $curlOpts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
            }
            if (defined('CURL_IPRESOLVE_V4')) {
                $curlOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }

            // Honour outbound proxy if the host injects one (cPanel / corporate).
            $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy');
            if (is_string($proxy) && $proxy !== '') {
                $curlOpts[CURLOPT_PROXY] = $proxy;
            }

            if ($method === 'POST') {
                $curlOpts[CURLOPT_POST] = true;
                if ($body !== null) {
                    $curlOpts[CURLOPT_POSTFIELDS] = $body;
                }
            } elseif ($method === 'GET') {
                $curlOpts[CURLOPT_HTTPGET] = true;
            } else {
                $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($body !== null && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $curlOpts[CURLOPT_POSTFIELDS] = $body;
                }
            }

            curl_setopt_array($ch, $curlOpts);

            return [
                'ch'     => $ch,
                'url'    => $url,
                'method' => $method,
                'opts'   => $opts,
                'start'  => microtime(true),
            ];
        }

        /** Parse raw HTTP response into the standard result array + log. */
        private function finalizeResponse($raw, $httpCode, $curlErr, $duration, array $opts, $url, $method, $curlErrno = 0)
        {
            $result = [
                'success'     => false,
                'http_code'   => $httpCode,
                'data'        => null,
                'raw'         => $raw,
                'error'       => '',
                'error_code'  => '',
                'duration_ms' => $duration,
                'curl_errno'  => (int)$curlErrno,
            ];

            $this->log($opts['category'] ?? 'api', $opts['action'] ?? $method, [
                'url'     => $url,
                'method'  => $method,
                'headers' => ['api-key: ***', 'Content-Type: application/json', 'Accept: application/json'],
                'body'    => $opts['json'] ?? null,
            ], $raw, $httpCode, $duration, (int)$curlErrno, (string)$curlErr);

            if ($curlErr !== '' || (int)$curlErrno !== 0) {
                $errno = (int)$curlErrno;
                $result['error'] = 'Connection error'
                    . ($errno ? " (#{$errno})" : '')
                    . ': '
                    . ($curlErr !== '' ? $curlErr : 'HTTP transport failed');
                $result['error_code'] = $errno ? (string)$errno : 'curl';
                return $result;
            }

            $decoded = json_decode((string)$raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $snippet = trim(strip_tags((string)$raw));
                if (strlen($snippet) > 300) {
                    $snippet = substr($snippet, 0, 300) . '…';
                }
                if ((int)$httpCode === 0 && ($raw === false || $raw === null || $raw === '')) {
                    $result['error'] = 'HTTP 0: empty response from GRNConnect (outbound HTTPS blocked, DNS, or SSL on host)';
                    $result['error_code'] = '0';
                    return $result;
                }
                $result['error']      = 'HTTP ' . $httpCode . ($snippet !== '' ? ': ' . $snippet : ' (non-JSON response)');
                $result['error_code'] = (string)$httpCode;
                return $result;
            }

            $result['data'] = $decoded;

            $apiError = $this->extractError($decoded);
            if ($apiError !== null) {
                $result['error']      = $apiError['message'];
                $result['error_code'] = $apiError['code'];
                return $result;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $result['error']      = 'HTTP ' . $httpCode . ' from GRNConnect API';
                $result['error_code'] = (string)$httpCode;
                return $result;
            }

            $result['success'] = true;
            return $result;
        }

        /** True when every result failed at transport layer (no HTTP response). */
        private function allTransportFailures(array $results)
        {
            if (empty($results)) {
                return false;
            }
            foreach ($results as $res) {
                if (!is_array($res)) {
                    return false;
                }
                if (!empty($res['success'])) {
                    return false;
                }
                $code = (int)($res['http_code'] ?? 0);
                if ($code > 0) {
                    return false;
                }
            }
            return true;
        }

        /** Re-check a selected rate before booking. */
        public function recheck($searchId, $rateKey, $groupCode)
        {
            return $this->request('POST', 'api/v3/hotels/availability/' . rawurlencode($searchId) . '/rates/', [
                'query'    => ['action' => 'recheck'],
                'json'     => ['rate_key' => $rateKey, 'group_code' => $groupCode],
                'category' => 'recheck',
                'action'   => 'recheck',
            ]);
        }

        /** Create a booking. */
        public function book(array $payload)
        {
            return $this->request('POST', 'api/v3/hotels/bookings', [
                'json'     => $payload,
                'timeout'  => max($this->requestTimeout, 45),
                'category' => 'issue',
                'action'   => 'booking',
            ]);
        }

        /** Fetch a booking by GRN reference. */
        public function getBooking($reference, $type = 'GRN')
        {
            return $this->request('GET', 'api/v3/hotels/bookings/' . rawurlencode($reference), [
                'query'    => ['type' => $type],
                'category' => 'issue',
                'action'   => 'fetch_booking',
            ]);
        }

        /** Fetch a booking by agent reference. */
        public function getBookingByAgentRef($agentRef)
        {
            return $this->request('GET', 'api/v3/hotels/bookings/by_reference/' . rawurlencode($agentRef), [
                'query'    => ['type' => 'agent'],
                'category' => 'issue',
                'action'   => 'fetch_booking_agent',
            ]);
        }

        /**
         * Cancel a booking by reference.
         *
         * A JSON body is always sent because the endpoint receives
         * "Content-Type: application/json"; some GRNConnect nodes return a
         * generic 500 ("Something went wrong") when the DELETE body is empty.
         * Payload keys (both optional per docs): comments (string), reason (int).
         */
        public function cancel($reference, array $payload = [])
        {
            if (empty($payload)) {
                $payload = ['comments' => 'Cancelled via API', 'reason' => 13]; // 13 = Others
            }
            
            return $this->request('DELETE', 'api/v3/hotels/bookings/' . rawurlencode($reference), [
                'json'     => $payload,
                'category' => 'cancel',
                'action'   => 'cancel',
            ]);
        }

        // --------------------------------------------------------------------
        // STATIC CONTENT OPERATIONS (CDN)
        // --------------------------------------------------------------------

        public function countries()
        {
            return $this->request('GET', 'api/v3/countries', [
                'static' => true, 'category' => 'content', 'action' => 'countries', 'timeout' => 120,
            ]);
        }

        public function cities($countryCode = '')
        {
            $query = ['version' => self::STATIC_VERSION];
            if ($countryCode !== '') {
                $query['country'] = $countryCode;
            }
            return $this->request('GET', 'api/v3/cities/', [
                'static' => true, 'query' => $query, 'category' => 'content', 'action' => 'cities', 'timeout' => 180,
            ]);
        }

        public function hotelsByCity($cityCode)
        {
            return $this->request('GET', 'api/v3/hotels/', [
                'static' => true,
                'query'  => ['city' => $cityCode, 'version' => self::STATIC_VERSION],
                'category' => 'content', 'action' => 'hotels_by_city', 'timeout' => 300,
            ]);
        }

        public function hotelsByCountry($countryCode)
        {
            return $this->request('GET', 'api/v3/hotels/', [
                'static' => true,
                'query'  => ['country' => $countryCode, 'version' => self::STATIC_VERSION],
                'category' => 'content', 'action' => 'hotels_by_country', 'timeout' => 300,
            ]);
        }

        public function hotel($hotelCode)
        {
            return $this->request('GET', 'api/v3/hotels', [
                'static' => true,
                'query'  => ['hcode' => $hotelCode, 'version' => self::STATIC_VERSION],
                'category' => 'content', 'action' => 'hotel_detail', 'timeout' => 60,
            ]);
        }

        public function hotelImages($hotelCode)
        {
            return $this->request('GET', "api/v3/hotels/{$hotelCode}/images", [
                'static' => true,
                'query' => ['version' => self::STATIC_VERSION],
                'category' => 'content', 'action' => 'hotel_images', 'timeout' => 60,
            ]);
        }

        // --------------------------------------------------------------------
        // HELPERS
        // --------------------------------------------------------------------

        /**
         * Detect a GRNConnect API error inside a decoded response body.
         * Codes 1501 (no availability) / 1502 (no hotels) are treated as
         * "empty result" and NOT surfaced as errors here so callers can
         * return empty result sets gracefully.
         */
        private function extractError($decoded)
        {
            if (!is_array($decoded)) {
                return null;
            }

            // 1501/1502 = no availability/hotels; 5137 = no images for hotel
            $softCodes = ['1501', '1502', '5137'];

            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                // Collect codes + messages across ALL errors so the caller sees
                // the real reason. GRNConnect uses several shapes:
                //   {code, message}, {code, detail}, {code, messages:[...]}
                $codes    = [];
                $messages = [];
                foreach ($decoded['errors'] as $err) {
                    if (!is_array($err)) {
                        if (is_string($err) && $err !== '') $messages[] = $err;
                        continue;
                    }
                    $c = (string)($err['code'] ?? '');
                    if ($c !== '' && !in_array($c, $softCodes, true)) {
                        $codes[] = $c;
                    }
                    if (!empty($err['messages']) && is_array($err['messages'])) {
                        foreach ($err['messages'] as $m) {
                            if ($m !== '' && $m !== null) $messages[] = (string)$m;
                        }
                    } elseif (!empty($err['message'])) {
                        $messages[] = (string)$err['message'];
                    } elseif (!empty($err['detail'])) {
                        $messages[] = (string)$err['detail'];
                    }
                }

                $firstCode = (string)($decoded['errors'][0]['code'] ?? '');
                // If every error is a "soft" no-availability code, treat as empty.
                if (empty($codes) && in_array($firstCode, $softCodes, true)) {
                    return null;
                }

                return [
                    'code'    => $codes ? implode(',', $codes) : ($firstCode ?: 'error'),
                    'message' => $messages ? implode('; ', $messages) : 'GRNConnect API error',
                ];
            }

            if (isset($decoded['error']) && !empty($decoded['error'])) {
                if (is_array($decoded['error'])) {
                    $code = (string)($decoded['error']['code'] ?? '');
                    if (in_array($code, $softCodes, true)) {
                        return null;
                    }
                    return [
                        'code'    => $code ?: 'error',
                        'message' => (string)($decoded['error']['message'] ?? 'GRNConnect API error'),
                    ];
                }
                return ['code' => 'error', 'message' => (string)$decoded['error']];
            }

            return null;
        }

        private function log($category, $action, $request, $rawResponse, $httpCode, $durationMs, $curlErrno = 0, $curlError = '')
        {
            if (!$this->logging) {
                return;
            }
            if (function_exists('grnconnect_log')) {
                grnconnect_log($category, $action, $request, $rawResponse, '', [
                    'url'         => $request['url'] ?? '',
                    'method'      => $request['method'] ?? '',
                    'headers'     => $request['headers'] ?? [],
                    'http_code'   => $httpCode,
                    'duration_ms' => $durationMs,
                    'curl_errno'  => (int)$curlErrno,
                    'curl_error'  => (string)$curlError,
                ]);
            }
        }
    }
}
