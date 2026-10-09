<?php

// Start output buffering to prevent any unwanted output
ob_start();

// Use the main database connection from config.php
global $router, $db;

// Function to get Hotelston database connection
function getHotelstonDb() {
    global $db;
    
    // Get module configuration
    $module = $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelston',
        'type' => 'stays'
    ]);
    
    // If no separate database configured, use main database
    if (!$module || empty($module['host']) || empty($module['database'])) {
        return $db;
    }
    
    try {
        // Create separate database connection for Hotelston
        $dsn = "mysql:host={$module['host']};dbname={$module['database']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, $module['username'], $module['password'] ?? '', $options);
        
        // Use Medoo with the separate connection
        return new Medoo\Medoo([
            'type' => 'mysql',
            'pdo' => $pdo
        ]);
        
    } catch (Exception $e) {
        error_log("Hotelston DB connection failed: " . $e->getMessage() . ". Falling back to main database.");
        return $db;
    }
}

// Validate credentials endpoint
$router->post('hotels/hotelston/validate', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $email = $_POST['c1'] ?? '';
        $password = $_POST['c2'] ?? '';
        $profile = $_POST['c3'] ?? '0';
        $environment = $_POST['env'] ?? 'production';
        
        if (empty($email) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required'
            ]);
            exit;
        }
        
        // Test with a simple SOAP call
        $wsdl_url = $environment === 'test' 
            ? 'https://dev.hotelston.com/ws/StaticDataServiceV2?wsdl'
            : 'https://www.hotelston.com/ws/StaticDataServiceV2?wsdl';
            
        $soap_options = [
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
            'exceptions' => true,
            'trace' => true,
            'connection_timeout' => 10,
            'cache_wsdl' => WSDL_CACHE_NONE
        ];
        
        $client = new \SoapClient($wsdl_url, $soap_options);
        
        $soap_request = [
            "loginDetails" => [
                "email" => $email,
                "password" => $password,
                "profile" => $profile
            ],
            "hotelId" => ['55341196']
        ];
        
        $api_response = $client->getHotelDetails($soap_request);
        
        if (isset($api_response->return)) {
            echo json_encode([
                'success' => true,
                'message' => 'Credentials are valid and have API access'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'API responded but returned no data - possibly invalid credentials'
            ]);
        }
        
    } catch (\SoapFault $e) {
        $fault_string = $e->faultstring ?? $e->getMessage();
        $detail = $e->detail ?? null;
        
        // Check for specific error about web services not enabled
        if ($detail && (strpos(json_encode($detail), 'Web services are not enabled') !== false)) {
            echo json_encode([
                'success' => false,
                'message' => 'Web services are not enabled for your Hotelston account. Please contact api@hotelston.com to enable Static Data Service API access. Your account currently only has Booking Service access.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'SOAP Error: ' . $fault_string
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
});

// Get import statistics
$router->get('hotels/hotelston/stats', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    try {
        $stats = [
            'total_hotels' => $hotelstonDb->count('hotelston_hotels'),
            'total_destinations' => $hotelstonDb->count('hotelston_destinations'),
            'total_countries' => $hotelstonDb->count('hotelston_countries'),
            'last_import' => $hotelstonDb->get('hotelston_import_log', ['started_at', 'status'], [
                'ORDER' => ['id' => 'DESC']
            ])
        ];

        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
});

// Start content import
$router->post('hotels/hotelston/content_import', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    try {
        $mode = $_POST['mode'] ?? 'update';
        $email = $_POST['c1'] ?? '';
        $password = $_POST['c2'] ?? '';
        $profile = $_POST['c3'] ?? '0';
        $environment = $_POST['env'] ?? 'production';

        if (empty($email) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required'
            ]);
            exit;
        }

        // Create database schema if it doesn't exist
        try {
            createHotelstonSchema($hotelstonDb);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create database schema: ' . $e->getMessage() . '. Please ensure the database exists and has proper permissions.'
            ]);
            exit;
        }

        // If fresh mode, truncate all tables
        if ($mode === 'fresh') {
            try {
                truncateHotelstonTables($hotelstonDb);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to truncate tables: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        // Store import state in database
        $importState = [
            'mode' => $mode,
            'email' => $email,
            'password' => $password,
            'profile' => $profile,
            'environment' => $environment,
            'current_chunk' => 0,
            'total_chunks' => 1, // Will process sample hotels (can be updated later for full API)
            'processed' => 0,
            'total' => 15, // Sample hotel count
            'start_time' => time(),
            'logs' => json_encode([])
        ];

        $hotelstonDb->insert('hotelston_import_log', [
            'mode' => $mode,
            'status' => 'in_progress',
            'import_state' => json_encode($importState),
            'started_at' => $hotelstonDb->raw('NOW()')
        ]);
        
        $importId = $hotelstonDb->id();

        echo json_encode([
            'success' => true,
            'message' => 'Import initialized successfully',
            'import_id' => $importId
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});

// Progress endpoint
$router->get('hotels/hotelston/progress', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    $importLog = $hotelstonDb->get('hotelston_import_log', '*', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if (!$importLog) {
        echo json_encode([
            'success' => false,
            'error' => 'No import in progress'
        ]);
        exit;
    }

    $import = json_decode($importLog['import_state'], true);
    if (!$import) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid import state'
        ]);
        exit;
    }

    $progress = ($import['processed'] / max($import['total'], 1)) * 100;
    $elapsed = time() - $import['start_time'];
    $rate = $elapsed > 0 ? $import['processed'] / $elapsed : 0;
    $remaining = $rate > 0 ? ($import['total'] - $import['processed']) / $rate : 0;

    echo json_encode([
        'success' => true,
        'status' => $importLog['status'],
        'current_operation' => $import['current_operation'] ?? 'Processing...',
        'progress' => round($progress, 2),
        'processed' => $import['processed'],
        'total' => $import['total'],
        'chunk' => $import['current_chunk'],
        'total_chunks' => $import['total_chunks'],
        'elapsed_time' => $elapsed,
        'estimated_remaining' => round($remaining),
        'logs' => json_decode($import['logs'], true) ?? []
    ]);

    exit;
});

// Cancel import
$router->post('hotels/hotelston/cancel', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    $importLog = $hotelstonDb->get('hotelston_import_log', 'id', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if ($importLog) {
        $hotelstonDb->update('hotelston_import_log', [
            'status' => 'cancelled',
            'updated_at' => $hotelstonDb->raw('NOW()')
        ], ['id' => $importLog]);
    }

    echo json_encode(['success' => true]);
    exit;
});

// Process chunk endpoint
$router->post('hotels/hotelston/process', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    try {
        ob_clean();
        header('Content-Type: application/json');

        $importLog = $hotelstonDb->get('hotelston_import_log', '*', [
            'status' => 'in_progress',
            'ORDER' => ['id' => 'DESC']
        ]);

        if (!$importLog) {
            echo json_encode([
                'success' => false,
                'error' => 'No import session found'
            ]);
            exit;
        }

        $import = json_decode($importLog['import_state'], true);
        if (!$import) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid import state'
            ]);
            exit;
        }
        
        try {
            $currentChunk = $import['current_chunk'];
            
            $import['current_operation'] = "Processing chunk " . ($currentChunk + 1);
            $logs = json_decode($import['logs'], true) ?? [];
            if ($currentChunk % 10 == 0) {
                $logs[] = "[" . date('H:i:s') . "] Processing chunk " . ($currentChunk + 1);
            }
            $import['logs'] = json_encode($logs);
            
            $hotelstonDb->update('hotelston_import_log', [
                'import_state' => json_encode($import),
                'updated_at' => $hotelstonDb->raw('NOW()')
            ], ['id' => $importLog['id']]);
            
            // Import chunk
            $result = importHotelstonChunk(
                $hotelstonDb,
                $import['email'],
                $import['password'],
                $import['profile'],
                $currentChunk,
                $import['mode'],
                $import['environment']
            );
            
            if (!$result) {
                echo json_encode([
                    'success' => false,
                    'completed' => true,
                    'error' => 'Import chunk function returned null/false'
                ]);
                exit;
            }
            
            if ($result['success']) {
                $import['current_chunk']++;
                $import['processed'] += $result['records_processed'];
                if ($import['current_chunk'] % 10 == 0 || $result['is_last_chunk']) {
                    $logs[] = "[" . date('H:i:s') . "] Processed " . $result['records_processed'] . " records (Total: " . $import['processed'] . ")";
                }
                $import['logs'] = json_encode($logs);
                
                $isComplete = $result['is_last_chunk'] || $import['current_chunk'] >= $import['total_chunks'];
                
                if ($isComplete) {
                    $import['current_operation'] = 'Import completed successfully';
                    
                    $hotelstonDb->update('hotelston_import_log', [
                        'status' => 'completed',
                        'hotels_imported' => $import['processed'],
                        'completed_at' => $hotelstonDb->raw('NOW()'),
                        'updated_at' => $hotelstonDb->raw('NOW()'),
                        'import_state' => json_encode($import)
                    ], ['id' => $importLog['id']]);
                    
                    echo json_encode([
                        'success' => true,
                        'completed' => true,
                        'message' => 'Import completed successfully',
                        'total_processed' => $import['processed']
                    ]);
                } else {
                    $hotelstonDb->update('hotelston_import_log', [
                        'import_state' => json_encode($import),
                        'updated_at' => $hotelstonDb->raw('NOW()')
                    ], ['id' => $importLog['id']]);
                    
                    echo json_encode([
                        'success' => true,
                        'completed' => false,
                        'continue' => true,
                        'message' => 'Chunk processed, continuing...'
                    ]);
                }
            } else {
                // STOP IMPORT IMMEDIATELY ON ERROR
                $import['current_operation'] = 'Import failed: ' . $result['error'];
                $logs[] = "[" . date('H:i:s') . "] [ERROR] Fatal error: " . $result['error'];
                $import['logs'] = json_encode($logs);
                
                $hotelstonDb->update('hotelston_import_log', [
                    'status' => 'failed',
                    'error_message' => $result['error'],
                    'import_state' => json_encode($import),
                    'updated_at' => $hotelstonDb->raw('NOW()')
                ], ['id' => $importLog['id']]);
                
                echo json_encode([
                    'success' => false,
                    'completed' => true,
                    'error' => $result['error']
                ]);
                exit;
            }
            
        } catch (Exception $e) {
            if (isset($importLog['id'])) {
                $hotelstonDb->update('hotelston_import_log', [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => $hotelstonDb->raw('NOW()')
                ], ['id' => $importLog['id']]);
            }
            
            echo json_encode([
                'success' => false,
                'completed' => true,
                'error' => $e->getMessage()
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'completed' => true,
            'error' => 'Fatal error: ' . $e->getMessage()
        ]);
        exit;
    }
    
    exit;
});

// Helper function to import hotels chunk
function importHotelstonChunk($db, $email, $password, $profile, $chunkNumber, $mode, $environment = 'production') {
    try {
        $wsdl_url = $environment === 'test' 
            ? 'https://dev.hotelston.com/ws/StaticDataServiceV2?wsdl'
            : 'https://www.hotelston.com/ws/StaticDataServiceV2?wsdl';
        
        $endpoint_url = $environment === 'test'
            ? 'http://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap11Endpoint'
            : 'http://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap11Endpoint';
            
        $soap_options = [
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
            'exceptions' => true,
            'stream_context' => stream_context_create([
                'http' => [
                    'header' => [
                        'Content-Type: text/xml; charset=UTF-8',
                        'Accept-Encoding: gzip, deflate'
                    ]
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]),
            'trace' => true,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_NONE
        ];
        
        $client = new \SoapClient($wsdl_url, $soap_options);
        $client->__setLocation($endpoint_url);
        
        // Hotelston uses getDestinations to get hotel IDs by destination
        // For now, use a sample set of hotel IDs for testing
        // In production, you would call getDestinations first, then getHotelDetails
        
        // Sample hotel IDs for testing (these are known valid IDs)
        $sampleHotelIds = [
            '55341196', '55341197', '55341198', '55341199', '55341200',
            '55341201', '55341202', '55341203', '55341204', '55341205',
            '55341206', '55341207', '55341208', '55341209', '55341210'
        ];
        
        // In a real implementation, get hotel IDs from destinations API
        // For this chunk, we'll process a subset of sample IDs
        $startIdx = $chunkNumber * 15;
        $hotelIdsToProcess = array_slice($sampleHotelIds, $startIdx, 15);
        
        if (empty($hotelIdsToProcess)) {
            return [
                'success' => true,
                'records_processed' => 0,
                'is_last_chunk' => true
            ];
        }
        
        // Get hotel details
        $soap_request = [
            "loginDetails" => [
                "email" => $email,
                "password" => $password,
                "profile" => $profile
            ],
            "hotelId" => $hotelIdsToProcess
        ];
        
        
        $api_response = $client->getHotelDetails($soap_request);
        
        error_log("Hotelston SOAP Response type: " . gettype($api_response));
        
        if (!isset($api_response->return)) {
            // Check if it's an error response
            if (isset($api_response->success) && $api_response->success === false) {
                $errorMsg = 'API Error: ';
                if (isset($api_response->error->message)) {
                    $errorMsg .= $api_response->error->message;
                    
                    // Check for specific error
                    if (strpos($api_response->error->message, 'Web services are not enabled') !== false) {
                        $errorMsg = 'Static Data Service is not enabled for your account. Please contact api@hotelston.com to request access. Your account currently only has Booking Service access.';
                    }
                }
                return [
                    'success' => false,
                    'error' => $errorMsg
                ];
            }
            
            // Log the full response for debugging
            return [
                'success' => false,
                'error' => 'No hotel data returned from API. Response: ' . json_encode($api_response)
            ];
        }
        
        $hotels = is_array($api_response->return) ? $api_response->return : [$api_response->return];
        $recordsProcessed = 0;
        
        foreach ($hotels as $hotel) {
            // Parse hotel data from SOAP response
            $hotelData = [
                'hotel_id' => $hotel->id ?? $hotel->hotelId ?? '',
                'name' => $hotel->name ?? '',
                'address' => ($hotel->address ?? '') . ' ' . ($hotel->address2 ?? ''),
                'city' => $hotel->city ?? '',
                'country' => $hotel->country ?? '',
                'postal_code' => $hotel->zipCode ?? $hotel->postalCode ?? '',
                'latitude' => $hotel->latitude ?? null,
                'longitude' => $hotel->longitude ?? null,
                'rating' => $hotel->stars ?? $hotel->rating ?? 0,
                'description' => $hotel->description ?? $hotel->shortDescription ?? '',
                'updated_at' => $db->raw('NOW()')
            ];
            
            // Skip if no hotel_id
            if (empty($hotelData['hotel_id'])) {
                continue;
            }
            
            if ($mode === 'fresh') {
                try {
                    $db->insert('hotelston_hotels', $hotelData);
                    $recordsProcessed++;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        error_log("Hotelston insert error: " . $e->getMessage());
                        throw $e;
                    }
                }
            } else {
                $existing = $db->get('hotelston_hotels', 'id', ['hotel_id' => $hotelData['hotel_id']]);
                
                if ($existing) {
                    $db->update('hotelston_hotels', $hotelData, ['hotel_id' => $hotelData['hotel_id']]);
                } else {
                    $hotelData['created_at'] = $db->raw('NOW()');
                    $db->insert('hotelston_hotels', $hotelData);
                }
                $recordsProcessed++;
            }
        }
        
        // Check if this is the last chunk (no more hotels to process)
        $isLastChunk = count($hotelIdsToProcess) < 15 || $startIdx + 15 >= count($sampleHotelIds);
        
        return [
            'success' => true,
            'records_processed' => $recordsProcessed,
            'is_last_chunk' => $isLastChunk
        ];
        
    } catch (\SoapFault $e) {
        error_log("Hotelston SOAP Fault: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'SOAP Error: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        error_log("Hotelston import error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Helper function to create database schema
function createHotelstonSchema($db) {
    // Hotels table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_hotels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_id VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        address VARCHAR(255),
        city VARCHAR(100),
        country VARCHAR(100),
        postal_code VARCHAR(20),
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        rating DECIMAL(2, 1),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_id (hotel_id),
        INDEX idx_city (city),
        INDEX idx_country (country)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Countries table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_countries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Destinations table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_destinations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        country_code VARCHAR(10),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_country (country_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Import log table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_import_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mode ENUM('fresh', 'update') NOT NULL,
        status ENUM('in_progress', 'completed', 'failed', 'cancelled') DEFAULT 'in_progress',
        hotels_imported INT DEFAULT 0,
        destinations_imported INT DEFAULT 0,
        countries_imported INT DEFAULT 0,
        import_state TEXT,
        error_message TEXT,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Helper function to truncate tables
function truncateHotelstonTables($db) {
    $tables = [
        'hotelston_hotels',
        'hotelston_countries',
        'hotelston_destinations'
    ];

    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        $db->query("TRUNCATE TABLE $table");
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
}