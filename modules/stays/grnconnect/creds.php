<?php
// ============================================================================
// GRNCONNECT API CREDENTIAL VALIDATOR
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/creds
// Validates the GRNConnect API key + base URL by issuing a lightweight
// availability probe. A valid key returns a normal response or a "soft" error
// (no availability / no hotels); an invalid key returns 401 / error 1102.
//
// Loaded by modules/index.php to register the route. Also executable when
// included by app/routes/globalRoutes.php on live hosts that skip modules/.htaccess.
// ============================================================================

if (!function_exists('grnconnect_handle_creds')) {
    function grnconnect_handle_creds()
    {
        $start_time = microtime(true);

        $response = [
            'success'  => false,
            'message'  => '',
            'data'     => null,
            'metadata' => [
                'module'           => 'grnconnect',
                'service'          => 'hotels',
                'provider'         => 'GRNConnect API',
                'api_version'      => 'v3',
                'timestamp'        => date('c'),
                'response_time_ms' => 0,
            ],
            'debug' => [
                'validation_steps' => [],
                'endpoint_used'    => null,
                'request_method'   => 'POST',
            ],
        ];

        try {
            require_once __DIR__ . '/api.php';

            $response['debug']['validation_steps'][] = '[START] Starting GRNConnect credential validation';

            // Support both form-data and JSON payloads
            $raw_body = file_get_contents('php://input');
            $decoded  = json_decode($raw_body, true);
            $payload  = is_array($decoded) ? array_merge($decoded, $_POST) : $_POST;

            $apiKey    = isset($payload['c1']) ? trim($payload['c1']) : '';
            $baseUrl   = isset($payload['c2']) ? rtrim(trim($payload['c2']), '/') : '';
            $staticUrl = isset($payload['c3']) ? rtrim(trim($payload['c3']), '/') : '';

            $missing = [];
            if ($apiKey === '')  $missing[] = 'API Key (c1)';
            if ($baseUrl === '') $missing[] = 'Base URL (c2)';
            if (!empty($missing)) {
                throw new Exception('Missing required credentials: ' . implode(', ', $missing));
            }

            $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($apiKey, 0, 4) . '...' . substr($apiKey, -4);
            $response['debug']['validation_steps'][] = '[ENV] Base URL: ' . $baseUrl;

            $client = new GRNConnectClient($apiKey, $baseUrl, $staticUrl, ['request_timeout' => 25]);

            // Probe availability with a dummy hotel code and near-future dates.
            $probe = [
                'hotel_codes'        => ['1848138'],
                'checkin'            => date('Y-m-d', strtotime('+14 days')),
                'checkout'           => date('Y-m-d', strtotime('+15 days')),
                'client_nationality' => 'US',
                'currency'           => 'USD',
                'rates'              => 'concise',
                'rooms'              => [['adults' => 2]],
            ];

            $response['debug']['endpoint_used'] = $baseUrl . '/api/v3/hotels/availability';
            $response['debug']['validation_steps'][] = '[REQUEST] Probing /api/v3/hotels/availability';

            $res = $client->availability($probe, 25);

            $httpCode  = (int)$res['http_code'];
            $errorCode = (string)$res['error_code'];

            // Auth failures surface as 401 or GRN error code 1102.
            $authFailed = ($httpCode === 401) || ($errorCode === '1102') ||
                          (stripos((string)$res['error'], 'invalid api key') !== false);

            if ($authFailed) {
                $response['debug']['validation_steps'][] = '[ERROR] Authentication failed (' . ($errorCode ?: $httpCode) . ')';
                $response['data'] = [
                    'error_type'        => 'authentication_error',
                    'error_code'        => $errorCode ?: ('http_' . $httpCode),
                    'error_description' => $res['error'] ?: 'Invalid API key',
                    'troubleshooting'   => [
                        'Verify the API Key provided by GRNConnect',
                        'Confirm the Base URL matches your environment (sandbox vs production)',
                        'Ensure your GRNConnect account is active',
                    ],
                ];
                throw new Exception('GRNConnect authentication failed: ' . ($res['error'] ?: ('HTTP ' . $httpCode)));
            }

            // Hard transport / server failures are not proof of valid credentials.
            if ($httpCode === 0 || $httpCode >= 500) {
                throw new Exception(
                    'GRNConnect connection failed'
                    . ($res['error'] ? (': ' . $res['error']) : (' (HTTP ' . $httpCode . ')'))
                );
            }

            // Success (normal response) OR a soft error such as "no availability"
            // both prove the key is accepted by GRNConnect.
            $response['success'] = true;
            $response['message'] = 'GRNConnect API credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication'    => [
                    'status'    => 'authenticated',
                    'auth_type' => 'API Key (api-key header)',
                ],
                'api_details' => [
                    'provider'           => 'GRNConnect',
                    'api_version'        => 'v3',
                    'endpoint'           => $baseUrl,
                    'static_endpoint'    => $client->getStaticBaseUrl(),
                    'response_time'      => $res['duration_ms'] . 'ms',
                    'supported_services' => [
                        'Hotel Search', 'Hotel Details', 'Availability & Rates',
                        'Booking Creation', 'Booking Cancellation',
                    ],
                ],
                'test_result' => [
                    'test_type'         => 'auth_validation',
                    'test_endpoint'     => '/api/v3/hotels/availability',
                    'api_responsive'    => true,
                    'credentials_valid' => true,
                ],
            ];
            $response['debug']['validation_steps'][] = '[SUCCESS] Credentials accepted by GRNConnect';

        } catch (Exception $e) {
            $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();
            if (!isset($response['data']) || $response['data'] === null) {
                $response['data'] = [
                    'error_type'        => 'validation_error',
                    'error_code'        => 'credential_validation_failed',
                    'error_description' => $e->getMessage(),
                ];
            }
            if (empty($response['message'])) {
                $response['message'] = 'GRNConnect API credential validation failed';
            }
        }

        $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($response['success'] ? 200 : 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Modules API gateway registration
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    $router->post('/stays/grnconnect/creds', function () {
        grnconnect_handle_creds();
    });
}

// Direct include from main-app globalRoutes fallback (live hosts).
// Do not run during modules/index.php supplier bootstrap, and do not run when
// this file is pulled in via grnconnect/index.php (dispatch will call the route).
if (empty($GLOBALS['__MODULES_BOOTSTRAP__'])) {
    $reqUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $reqMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($reqMethod === 'POST' && preg_match('#/modules/stays/grnconnect/creds(?:\.php)?(?:\?|$)#', $reqUri)) {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $fromGrnIndex = false;
        foreach ($bt as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : '';
            if ($file !== '' && substr($file, -strlen('/grnconnect/index.php')) === '/grnconnect/index.php') {
                $fromGrnIndex = true;
                break;
            }
        }
        if (!$fromGrnIndex) {
            grnconnect_handle_creds();
        }
    }
}
