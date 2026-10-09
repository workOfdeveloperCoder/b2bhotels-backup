<?php

// Set proper headers for JSON response
global $router;

$router->post('flights/tbo/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'tbo',
            'service' => 'flights',
            'provider' => 'TBO Air API',
            'api_version' => 'v1',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'REST'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting TBO Air API credential validation';

        // Get credentials from POST data
        $username = trim($_POST['c1'] ?? '');     // TBO Username
        $password = trim($_POST['c2'] ?? '');     // TBO Password
        $environment = trim($_POST['env'] ?? 'production');
        $user_ip = trim($_POST['user_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';
        $response['debug']['validation_steps'][] = "[ENV] Environment: " . strtoupper($environment);
        $response['debug']['validation_steps'][] = "[IP] User IP Address: {$user_ip}";

        // Validate required credentials
        if (empty($username) || empty($password)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($username)) $missing_fields[] = 'Username (c1)';
            if (empty($password)) $missing_fields[] = 'Password (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($username === 'test' || $password === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with TBO';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual TBO API credentials.',
                'provider_details' => [
                    'provider' => 'TBO Air API',
                    'environment' => $environment,
                    'documentation' => 'https://tboapi.tboair.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your TBO Username',
                    'c2' => 'Your TBO Password',
                    'env' => 'dev or production'
                ],
                'troubleshooting' => [
                    'Replace "test" values with real TBO credentials',
                    'Contact TBO to get API credentials',
                    'Ensure your account is active',
                    'Verify you have API access enabled'
                ]
            ];

            // Calculate response time
            $end_time = microtime(true);
            $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
            $response['debug']['validation_steps'][] = "[TIME] Response time: {$response['metadata']['response_time_ms']}ms";

            http_response_code(400);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $response['debug']['validation_steps'][] = '[OK] Required credentials provided';
        $response['debug']['validation_steps'][] = '[KEY] Username: ' . $username;
        $response['debug']['validation_steps'][] = '[KEY] Password: ' . str_repeat('*', strlen($password)) . ' (length: ' . strlen($password) . ')';

        // Determine API endpoint based on environment
        if ($environment == 'dev') {
            $auth_url = 'https://xmloutapi.tboair.com/';
            $response['debug']['validation_steps'][] = '[ENV] Using DEVELOPMENT environment';
        } else {
            $auth_url = 'https://searchapi.tboair.com/';
            $response['debug']['validation_steps'][] = '[ENV] Using PRODUCTION environment';
        }

        $auth_endpoint = $auth_url . 'API/V1/Authenticate/ValidateAgency';
        $response['debug']['endpoint_used'] = $auth_endpoint;
        $response['debug']['validation_steps'][] = '[API] Authentication endpoint: ' . $auth_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to TBO Air API...';

        // Prepare authentication request
        $auth_params = [
            'UserName' => $username,
            'Password' => $password,
            'BookingMode' => 'API',
            'IPAddress' => $user_ip,
        ];

        $response['debug']['validation_steps'][] = '[REQUEST] Sending ValidateAgency request...';
        $response['debug']['api_request'] = json_encode($auth_params, JSON_PRETTY_PRINT);

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $auth_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($auth_params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        // Execute the request
        $api_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);


        $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: {$connect_time}s, total: {$total_time}s)";

        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network error occurred: ' . $curl_error;
            throw new Exception('Network Error: ' . $curl_error);
        }

        // Store response for debugging (first 2000 chars)
        $response['debug']['api_response'] = substr($api_response, 0, 2000) . (strlen($api_response) > 2000 ? '...' : '');

        // Try to parse JSON response
        $api_data = json_decode($api_response, true);

        // Parse API response
        if ($http_code === 200 && $api_data) {
            // Check for TokenId which indicates successful authentication
            if (isset($api_data['TokenId']) && !empty($api_data['TokenId'])) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! TokenId received';
                $response['debug']['validation_steps'][] = '[TOKEN] TokenId: ' . substr($api_data['TokenId'], 0, 20) . '...';
                
                if (isset($api_data['TrackingId'])) {
                    $response['debug']['validation_steps'][] = '[TRACKING] TrackingId: ' . $api_data['TrackingId'];
                }

                // Success response
                $response['success'] = true;
                $response['message'] = 'TBO Air API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'Username/Password',
                        'username' => $username,
                        'token_id' => substr($api_data['TokenId'], 0, 20) . '...',
                        'tracking_id' => $api_data['TrackingId'] ?? 'N/A',
                        'booking_mode' => 'API'
                    ],
                    'api_details' => [
                        'provider' => 'TBO Air API',
                        'api_version' => 'v1',
                        'environment' => $environment,
                        'endpoint' => $auth_url,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Flight Search (Oneway & Round-trip)',
                            'Fare Quote',
                            'Fare Rules',
                            'Flight Booking',
                            'Ticket Generation (LCC & Non-LCC)',
                            'Multi-city Search',
                            'GDS Integration'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'validate_agency',
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'token_generated' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ],
                    'agent_info' => [
                        'member_id' => $api_data['MemberId'] ?? 'N/A',
                        'agency' => $api_data['Agency'] ?? 'N/A',
                        'agent_id' => $api_data['AgentId'] ?? 'N/A'
                    ]
                ];

            } elseif (isset($api_data['Error']) || isset($api_data['Errors'])) {
                // TBO returned error in response
                $error_data = $api_data['Error'] ?? $api_data['Errors'][0] ?? [];
                $error_message = $error_data['ErrorMessage'] ?? $error_data['Message'] ?? 'Authentication failed';
                $error_code = $error_data['ErrorCode'] ?? 'unknown';

                $response['debug']['validation_steps'][] = "[ERROR] TBO API Error: {$error_message} (Code: {$error_code})";

                throw new Exception("TBO Authentication Failed: {$error_message}");

            } else {
                $response['debug']['validation_steps'][] = '[WARNING] API responded but no TokenId found';
                throw new Exception('API responded but authentication failed - no TokenId received');
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            // Try to extract error from JSON response
            $error_message = 'Unknown error';
            $error_code = 'http_' . $http_code;

            if ($api_data) {
                if (isset($api_data['Error'])) {
                    $error_obj = $api_data['Error'];
                    $error_message = $error_obj['ErrorMessage'] ?? $error_obj['Message'] ?? 'Authentication failed';
                    $error_code = $error_obj['ErrorCode'] ?? $error_code;
                } elseif (isset($api_data['Errors']) && is_array($api_data['Errors']) && count($api_data['Errors']) > 0) {
                    $error_obj = $api_data['Errors'][0];
                    $error_message = $error_obj['ErrorMessage'] ?? $error_obj['Message'] ?? 'Authentication failed';
                    $error_code = $error_obj['ErrorCode'] ?? $error_code;
                } elseif (isset($api_data['message'])) {
                    $error_message = $api_data['message'];
                }
                $response['debug']['validation_steps'][] = '[ERROR] TBO Error: ' . $error_message;
            }

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => $error_code,
                'error_description' => "HTTP {$http_code} error from TBO API: {$error_message}",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $auth_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add API error details if available
            if (isset($api_data) && $api_data) {
                $error_details['api_error'] = $api_data;
                $response['debug']['validation_steps'][] = '[INFO] API error details added to error response';
            }

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid API request';
                    $error_details['solutions'] = [
                        'Check if your TBO Username is correct',
                        'Verify your TBO Password',
                        'Ensure there are no extra spaces in credentials',
                        'Verify your IP address is whitelisted with TBO'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid Credentials';
                    $error_details['solutions'] = [
                        'Verify your TBO Username and Password are correct',
                        'Check if your TBO account is active',
                        'Ensure you have API access enabled',
                        'Contact TBO support to verify your credentials',
                        'Confirm your IP address is whitelisted'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - API access denied';
                    $error_details['solutions'] = [
                        'Your account may not have API access',
                        'Contact TBO to enable API access',
                        'Verify your account is in good standing',
                        'Check if your IP is whitelisted with TBO'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded API rate limits',
                        'Wait a few minutes and try again',
                        'Contact TBO about rate limit increases'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - TBO API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check TBO API status',
                        'Try switching between dev/production environments'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify your credentials are correct',
                        'Try again in a few minutes',
                        'Contact TBO API support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from TBO API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid TBO API credentials',
                    'Check your TBO Username and Password',
                    'Verify your IP address is whitelisted with TBO',
                    'Ensure your account has API access enabled',
                    'Try switching between dev and production environments',
                    'Contact TBO support if issues persist'
                ],
                'environments' => [
                    'dev' => 'https://xmloutapi.tboair.com/',
                    'production' => 'https://searchapi.tboair.com/'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'TBO Air API credential validation failed';
        }
    }

    // Calculate final response time
    $end_time = microtime(true);
    $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
    $response['debug']['validation_steps'][] = "[TIME] Total processing time: {$response['metadata']['response_time_ms']}ms";

    // Set appropriate HTTP status code
    http_response_code($response['success'] ? 200 : 400);

    // Return optimized JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} // End of processCredentials function

    processCredentials();

});
