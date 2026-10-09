<?php

// Global helper functions for all modules

if (!function_exists('REQUIRED')) {
    function REQUIRED($keys,$src=null) {
        $src = $src ?? $_POST;
        foreach((array)$keys as $k){
            if(!isset($src[$k]) || trim($src[$k])===""){
                echo "$k : param missing"; http_response_code(400); exit;
            }
        }
        return $src;
    }
}

if (!function_exists('timetodate')) {
    function timetodate($departure, $arrival) {
        $datetime1 = new DateTime($departure);
        $datetime2 = new DateTime($arrival);
        $interval = $datetime1->diff($datetime2);
        return $interval->format('%h:%i');
    }
}

if (!function_exists('dd')) {
    function dd($data) {
        echo "<pre>";
        print_r($data);
        echo "</pre>";
        die;
    }
}

if (!function_exists('supplier_timeout_config')) {
    function supplier_timeout_config() {
        return [
            'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
            'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
        ];
    }
}

if (!function_exists('MARKUP')) {
    function MARKUP($price, $module, $db, $fromCurrency = null, $toCurrency = null) {
        static $markupUserCache = null;
        static $markupCurrencyCache = null;
        static $markupDefaultCurrency = null;

        $isAgent = false;
        $customMarkup = false;
        $userMarkupValue = 0;
        $userMarkupType = 'percentage';

        $userId = $_SESSION['user_id'] ?? null;
        $sessionRole = $_SESSION['user_role'] ?? null;

        // JWT verification fallback for Mobile/API requests
        if (empty($userId) || empty($sessionRole)) {
            if (!class_exists('JWT')) {
                $jwtPath = dirname(__DIR__) . '/app/lib/jwt.php';
                if (file_exists($jwtPath)) {
                    require_once $jwtPath;
                }
            }
            if (class_exists('JWT')) {
                $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
                $headersLower = [];
                foreach ($allHeaders as $k => $v) {
                    $headersLower[strtolower($k)] = $v;
                }
                foreach ($_SERVER as $k => $v) {
                    if (str_starts_with($k, 'HTTP_')) {
                        $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                        $headersLower[$hKey] = $v;
                    }
                }
                if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                }
                
                // 1. Standard Authorization Header
                $authHeader = $headersLower['authorization'] ?? '';

                $token = '';
                if (!empty($authHeader)) {
                    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $jwtMatches)) {
                        $token = $jwtMatches[1];
                    } else {
                        $token = trim($authHeader);
                    }
                }

                // 2. Custom headers fallback
                if (empty($token)) {
                    $token = $headersLower['token']
                          ?? $headersLower['jwt']
                          ?? $headersLower['x-access-token']
                          ?? '';
                }

                // 3. Request body / GET fallback
                if (empty($token)) {
                    $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                          ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                          ?? '';
                }

                if (!empty($token)) {
                    try {
                        $tokenData = JWT::verify($token);
                        if (!$tokenData && method_exists('JWT', 'decode')) {
                            $tokenData = JWT::decode($token, false);
                        }
                        if ($tokenData && !empty($tokenData['user_id'])) {
                            $userId = $tokenData['user_id'];
                            if (!empty($tokenData['role'])) {
                                $sessionRole = $tokenData['role'];
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail
                    }
                }
            }
        }

        // Fetch user data for role identification & custom user/agent markup
        if ($userId) {
            $cacheKey = (string)$userId;
            if ($markupUserCache === null || !array_key_exists($cacheKey, $markupUserCache)) {
                if ($markupUserCache === null) {
                    $markupUserCache = [];
                }
                $whereClause = ['user_id' => (string)$userId];
                if (is_numeric($userId)) {
                    $whereClause = [
                        'OR' => [
                            'user_id' => (string)$userId,
                            'id'      => (int)$userId
                        ]
                    ];
                }
                $markupUserCache[$cacheKey] = $db->get('users', ['role', 'apply_markup', 'markup_type', 'markup_value'], $whereClause) ?: null;
            }
            $userData = $markupUserCache[$cacheKey];

            if ($userData) {
                if (!empty($userData['role'])) {
                    $sessionRole = $userData['role'];
                }
                if (strtolower($sessionRole ?? '') === 'agent') {
                    $isAgent = true;
                }
                if (($userData['apply_markup'] ?? 'global') === 'custom') {
                    $customMarkup = true;
                    $userMarkupValue = floatval($userData['markup_value'] ?? 0);
                    $userMarkupType = $userData['markup_type'] ?? 'percentage';
                }
            }
        }
        
        // Request-level currency cache (rooms can call MARKUP dozens of times)
        if ($markupCurrencyCache === null) {
            $markupCurrencyCache = [];
            $rows = $db->select('currencies', ['name', 'rate', 'default', 'status']) ?: [];
            foreach ($rows as $row) {
                $name = strtoupper(trim((string)($row['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                $markupCurrencyCache[$name] = [
                    'rate' => floatval($row['rate'] ?? 0),
                    'status' => (string)($row['status'] ?? ''),
                    'default' => !empty($row['default']),
                ];
                if (!empty($row['default'])) {
                    $markupDefaultCurrency = [
                        'name' => $name,
                        'rate' => floatval($row['rate'] ?? 0),
                    ];
                }
            }
        }

        $getCurrencyRate = function ($code) use (&$markupCurrencyCache) {
            $code = strtoupper(trim((string)$code));
            if ($code === '' || !isset($markupCurrencyCache[$code])) {
                return null;
            }
            $rate = $markupCurrencyCache[$code]['rate'] ?? 0;
            return $rate > 0 ? $rate : null;
        };
        
        // If $module is an array, use it directly (module data passed in)
        // Otherwise, query database by module type
        if (is_array($module)) {
            $moduleData = $module;
        } else {
            $moduleData = $db->get('modules', ['markup_b2b', 'markup_b2c', 'markup_type_b2b', 'markup_type_b2c'], ['type' => $module, 'status' => '1']);
        }
        
        if (!$moduleData) {
            $convertedPrice = $price;
            
            if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
                $fromRate = $getCurrencyRate($fromCurrency);
                $toRate = $getCurrencyRate($toCurrency);
                
                if ($fromRate && $toRate) {
                    $convertedPrice = ($price / $fromRate) * $toRate;
                    $convertedPrice = round($convertedPrice, 2);
                }
            }
            
            return [
                'price' => $convertedPrice,
                'markup' => 0,
                'markup_percentage' => 0,
                'markup_type' => 'percentage',
                'markup_value' => 0,
                'base_price' => round($price, 2),
                'converted_base_price' => $convertedPrice
            ];
        }
        
        // Markup logic: Custom Overrides Platform
        // If Custom Markup is enabled for the agent, we use THAT and THAT ONLY.
        // If not, we use the Platform (B2B/B2C) markup.
        
        $finalMarkupValue = 0;
        $finalMarkupType = 'percentage';
        
        if ($customMarkup) {
            $finalMarkupValue = $userMarkupValue;
            $finalMarkupType = $userMarkupType;
        } else {
            $finalMarkupValue = $isAgent ? floatval($moduleData['markup_b2b'] ?? 0) : floatval($moduleData['markup_b2c'] ?? 0);
            $finalMarkupType = $isAgent ? ($moduleData['markup_type_b2b'] ?? 'percentage') : ($moduleData['markup_type_b2c'] ?? 'percentage');
        }
        
        // Apply the chosen markup
        // STEP 1: Apply markup to ORIGINAL currency price first
        $markupAmount = 0;
        $markupPercentage = 0;
        $priceWithMarkup = $price;
        
        if ($finalMarkupType === 'fixed') {
            // Fixed markup is always in DEFAULT currency (e.g. USD)
            // We must convert it to the Item's Currency ($fromCurrency) if they differ
            
            $markupValueInItemCurrency = $finalMarkupValue;
            
            if ($fromCurrency) {
                $defaultCurrency = $markupDefaultCurrency;
                
                if ($defaultCurrency && $defaultCurrency['name'] !== strtoupper(trim((string)$fromCurrency))) {
                    // We need to convert the Markup Value (Default Currency) -> Item Currency ($fromCurrency)
                    // Formula: (Value / DefaultRate) * ItemRate
                    $itemCurrencyRate = $getCurrencyRate($fromCurrency);
                    
                    if ($itemCurrencyRate && !empty($defaultCurrency['rate'])) {
                         $markupValueInItemCurrency = ($finalMarkupValue / $defaultCurrency['rate']) * $itemCurrencyRate;
                    }
                }
            }

            $markupAmount = $markupValueInItemCurrency;
            // Calculate percentage for reference (markup / base * 100)
            $markupPercentage = $price > 0 ? round(($markupAmount / $price) * 100, 2) : 0;
            $priceWithMarkup = $price + $markupAmount;
            
        } else {
            // Percentage
            $markupAmount = ($price * $finalMarkupValue) / 100;
            $markupPercentage = $finalMarkupValue;
            $priceWithMarkup = $price + $markupAmount;
        }
        
        $markupValue = $finalMarkupValue;
        $markupType = $finalMarkupType;

        
        // STEP 2: Convert the marked-up price to target currency
        $convertedPrice = $priceWithMarkup;
        $convertedBasePrice = $price;
        
        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            $fromRate = $getCurrencyRate($fromCurrency);
            $toRate = $getCurrencyRate($toCurrency);
            
            if ($fromRate && $toRate) {
                $convertedPrice = ($priceWithMarkup / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);
                
                $convertedBasePrice = ($price / $fromRate) * $toRate;
                $convertedBasePrice = round($convertedBasePrice, 2);
                
                // Convert markup amount to target currency
                $markupAmount = ($markupAmount / $fromRate) * $toRate;
                $markupAmount = round($markupAmount, 2);
            }
        }
        
        $finalPrice = $convertedPrice;
        
        return [
            'price' => round($finalPrice, 2),
            'markup' => round($markupAmount, 2),
            'markup_percentage' => $markupPercentage,
            'markup_type' => $markupType,
            'markup_value' => $markupValue,
            'base_price' => round($price, 2),
            'converted_base_price' => round($convertedBasePrice, 2)
        ];
    }
}

/**
 * CURRENCY CONVERSION FUNCTION
 * Converts price from one currency to another without markup
 * 
 * @param float $price - Original price
 * @param object $db - Database connection
 * @param string $fromCurrency - Source currency code
 * @param string $toCurrency - Target currency code
 * @return array - Converted price with details
 */
if (!function_exists('CURRENCY_CONVERT')) {
    function CURRENCY_CONVERT($price, $db, $fromCurrency = null, $toCurrency = null) {
        // Default values
        $convertedPrice = $price;
        $conversionRate = 1.0;
        $isConverted = false;
        
        // Check if conversion is needed
        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
            $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);
            
            if ($fromRate && $toRate && $fromRate > 0) {
                $convertedPrice = ($price / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);
                $conversionRate = $toRate / $fromRate;
                $isConverted = true;
            }
        }
        
        return [
            'price' => $convertedPrice,
            'converted' => $isConverted,
            'conversion_rate' => round($conversionRate, 4),
            'original_price' => round($price, 2),
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency
        ];
    }
}

// ============================================================================
// API LOGGING HELPER FUNCTION - LOG API PAYLOAD & RESPONSE
// ============================================================================
function logApiCall($apiType, $payload, $response, $httpCode, $path = '', $type = '') {
    try {
        // Create logs directory if it doesn't exist
        $logsDir = empty($path) ? __DIR__ . '/logs' : $path;
        
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');
        
        // Add prefix for type if provided
        $filePrefix = !empty($type) ? $type . '_' : '';
        
        // ============================================
        // LOG REQUEST PAYLOAD - OVERWRITE MODE
        // ============================================
        $requestLogFile = $logsDir . '/' . $filePrefix . 'requests_' . $currentDate . '.json';
        
        $requestLogEntry = [
            'Time' => $currentTime,
            'Date' => $currentDate,
            'Api_type' => $apiType,
            'Data' => $payload
        ];
        
        // Write request log (overwrite, not append)
        file_put_contents(
            $requestLogFile, 
            json_encode([$requestLogEntry], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 
            LOCK_EX
        );
        
        // ============================================
        // LOG RESPONSE - OVERWRITE MODE
        // ============================================
        $responseLogFile = $logsDir . '/' . $filePrefix . 'responses_' . $currentDate . '.json';
        
        $responseLogEntry = [
            'Time' => $currentTime,
            'Date' => $currentDate,
            'Api_type' => $apiType,
            'Data' => [
                'http_code' => $httpCode,
                'response' => $response
            ]
        ];
        
        // Write response log (overwrite, not append)
        file_put_contents(
            $responseLogFile, 
            json_encode([$responseLogEntry], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 
            LOCK_EX
        );
        
    } catch (Exception $e) {
        error_log('API Logging failed: ' . $e->getMessage());
    }
}

function log_setting($db,$module){
    $setting = $db->get('modules','logging_enabled',['name' => $module]);
    return $setting;
}

// ============================================================================
// IMAGE CACHING FUNCTIONS
// ============================================================================

/**
 * Download and cache Stuba images locally
 * @param string $imageUrl - Original Stuba image URL
 * @param string $hotelId - Hotel ID for folder organization
 * @param string $city - City name for cache management
 * @return string - Local cached image URL or fallback
 */
function cacheImage($imageUrl, $hotelId, $city = '') {
    // Create base images directory
    $baseDir = __DIR__ . "/stays/stuba/images";
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }
    
    // Create hotel-specific directory
    $hotelDir = $baseDir . "/" . $hotelId;
    if (!is_dir($hotelDir)) {
        mkdir($hotelDir, 0777, true);
    }
    
    // Generate filename from URL
    $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    
    // If no extension, default to jpg
    if (empty($extension)) {
        $filename .= '.jpg';
    }
    
    $localPath = $hotelDir . "/" . $filename;
    $localUrl = root . "stays/stuba/images/" . $hotelId . "/" . $filename;
    
    // Check if image already exists
    if (file_exists($localPath)) {
        return $localUrl;
    }

    // Download image
    try {
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200 && $imageData !== false) {
            file_put_contents($localPath, $imageData);
            return $localUrl;
        }
    } catch (Exception $e) {
        // Log error but continue
        $logDir = __DIR__ . "/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents("logs/IMAGE_CACHE_ERROR.log", date('Y-m-d H:i:s') . " - " . $imageUrl . " - " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    // Return original URL if download fails
    return $imageUrl;
}

/**
 * Clear cached images for hotels not in current city
 * @param string $currentCity - City name to keep
 * @param object $stubaDb - Medoo database connection
 */
function clearOtherCityImages($currentCity, $stubaDb) {
    $baseDir = __DIR__ . "/images";
    
    if (!is_dir($baseDir)) {
        return;
    }
    
    // Get all hotel IDs for current city
    $formatted = ucwords(str_replace('-', ' ', $currentCity));
    
    try {
        $currentCityHotels = $stubaDb->select('stuba_hotels', 'hotel_id', [
            'city[~]' => $formatted
        ]);
        
        // Get all directories in images folder
        $directories = glob($baseDir . '/*', GLOB_ONLYDIR);
        
        foreach ($directories as $dir) {
            $hotelId = basename($dir);
            
            // If this hotel is not in current city, delete its images
            if (!in_array($hotelId, $currentCityHotels)) {
                deleteDirectory($dir);
            }
        }
    } catch (Exception $e) {
        error_log('Error clearing city images: ' . $e->getMessage());
    }
}

/**
 * Recursively delete directory
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Check if ancillaries are enabled for a specific module
 */
if (!function_exists('isAncillariesEnabled')) {
    function isAncillariesEnabled($module_name, $db) {
        $status = $db->get('modules', 'ancillaries_enabled', ['name' => $module_name]);
        return ($status == 1);
    }
}

/**
 * Check if EMD is enabled for a specific module
 */
if (!function_exists('isEmdEnabled')) {
    function isEmdEnabled($module_name, $db) {
        $status = $db->get('modules', 'emd_enabled', ['name' => $module_name]);
        return ($status == 1);
    }
}

// Phone helpers for supplier modules (issue/booking APIs)
if (!function_exists('getPhoneCode')) {
    function getPhoneCode($isoOrCode, $db)
    {
        if (empty($isoOrCode)) {
            return '';
        }
        $clean = preg_replace('/[^\d]/', '', $isoOrCode);
        if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
            $country = $db->get('countries', ['phonecode'], ['iso' => strtoupper($isoOrCode)]);
            return preg_replace('/[^\d]/', '', $country['phonecode'] ?? '');
        }
        return !empty($clean) ? $clean : '';
    }
}

if (!function_exists('resolveCountryIso')) {
    function resolveCountryIso($isoOrCode, $db)
    {
        if (empty($isoOrCode)) {
            return '';
        }
        $isoOrCode = trim((string)$isoOrCode);
        if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
            return strtoupper($isoOrCode);
        }
        $clean = preg_replace('/[^\d]/', '', $isoOrCode);
        if ($clean === '') {
            return '';
        }
        $country = $db->get('countries', ['iso'], ['phonecode' => $clean]);
        if (!empty($country['iso'])) {
            return strtoupper($country['iso']);
        }
        $country = $db->get('countries', ['iso'], ['phonecode' => ltrim($clean, '0')]);
        return !empty($country['iso']) ? strtoupper($country['iso']) : '';
    }
}

if (!function_exists('buildE164PhoneNumber')) {
    function buildE164PhoneNumber($phone, $countryIso, $db)
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return '';
        }
        if (strpos($phone, '+') === 0) {
            return '+' . preg_replace('/[^\d]/', '', substr($phone, 1));
        }
        $countryIso  = resolveCountryIso($countryIso, $db) ?: 'US';
        $callingCode = getPhoneCode($countryIso, $db);
        $digits      = preg_replace('/[^\d]/', '', $phone);
        $digits      = ltrim($digits, '0');
        if ($callingCode !== '' && strpos($digits, $callingCode) === 0) {
            return '+' . $digits;
        }
        return '+' . $callingCode . $digits;
    }
}

if (!function_exists('isValidE164Phone')) {
    function isValidE164Phone($e164)
    {
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            return false;
        }
        $digits = substr($e164, 1);
        if (preg_match('/^(\d)\1{6,}$/', $digits)) {
            return false;
        }
        $fakePatterns = ['1234567890', '123456789', '0123456789', '0000000000', '1111111111'];
        foreach ($fakePatterns as $pattern) {
            if (strpos($digits, $pattern) !== false) {
                return false;
            }
        }
        if (strpos($e164, '+1') === 0 && strlen($digits) === 11) {
            $area     = substr($digits, 1, 3);
            $exchange = substr($digits, 4, 3);
            if ($area[0] === '0' || $area[0] === '1' || $exchange[0] === '0' || $exchange[0] === '1') {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('validatePhoneForCountry')) {
    function validatePhoneForCountry($phone, $countryIso, $db)
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return false;
        }
        if (substr_count($phone, '+') > 1) {
            return false;
        }
        if (($plusPos = strpos($phone, '+')) !== false && $plusPos > 0) {
            return false;
        }
        if (preg_match('/\+\d+\s+\+/', $phone)) {
            return false;
        }
        if (preg_match('/^\+\d+\s+\(/', $phone)) {
            return false;
        }
        $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
        $expectedCode = getPhoneCode($countryIso, $db);
        if ($expectedCode === '') {
            return false;
        }
        $e164 = buildE164PhoneNumber($phone, $countryIso, $db);
        if (!isValidE164Phone($e164)) {
            return false;
        }
        $digits = substr($e164, 1);
        if (strpos($digits, $expectedCode) !== 0) {
            return false;
        }
        $national    = substr($digits, strlen($expectedCode));
        $nationalLen = strlen($national);
        if ($nationalLen < 7 || $nationalLen > 12) {
            return false;
        }
        if ($expectedCode === '1' && $nationalLen !== 10) {
            return false;
        }
        if ($expectedCode === '254' && $nationalLen !== 9) {
            return false;
        }
        if ($expectedCode === '92' && ($nationalLen < 10 || $nationalLen > 11)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('normalizePhoneForStorage')) {
    function normalizePhoneForStorage($phone, $countryIso, $db)
    {
        $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
        $expectedCode = getPhoneCode($countryIso, $db);
        $e164         = buildE164PhoneNumber($phone, $countryIso, $db);
        $digits       = substr($e164, 1);
        if ($expectedCode !== '' && strpos($digits, $expectedCode) === 0) {
            return substr($digits, strlen($expectedCode));
        }
        return preg_replace('/[^\d]/', '', $phone);
    }
}

/**
 * Read settings.app_settings safely on older installs that may lack the column.
 * Auto-adds the column once when missing so Modules API does not fatal.
 */
if (!function_exists('getModulesAppSettings')) {
    function getModulesAppSettings($db): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $read = function () use ($db) {
            $settingsData = $db->get('settings', 'app_settings');
            return json_decode($settingsData ?: '{}', true) ?: [];
        };

        try {
            $cached = $read();
            return $cached;
        } catch (\Throwable $e) {
            // Older DBs may not have app_settings yet (added by later app updates).
            try {
                $columns = [];
                $columnRows = $db->query('SHOW COLUMNS FROM `settings`');
                if ($columnRows) {
                    foreach ($columnRows as $row) {
                        if (!empty($row['Field'])) {
                            $columns[] = $row['Field'];
                        }
                    }
                }

                if (!in_array('app_settings', $columns, true)) {
                    $db->query('ALTER TABLE `settings` ADD COLUMN `app_settings` LONGTEXT NULL');
                }

                // Migrate legacy typo column if present
                if (in_array('app_settions', $columns, true)) {
                    $db->query("UPDATE `settings` SET `app_settings` = CASE WHEN (`app_settings` IS NULL OR TRIM(`app_settings`) = '') AND `app_settions` IS NOT NULL AND TRIM(`app_settions`) <> '' THEN `app_settions` ELSE `app_settings` END");
                }

                $cached = $read();
                return $cached;
            } catch (\Throwable $e2) {
                error_log('[modules] getModulesAppSettings failed: ' . $e2->getMessage());
                $cached = [];
                return $cached;
            }
        }
    }
}

/**
 * Verify API Key for external clients / mobile apps, bypassing for own web client.
 */
if (!function_exists('verifyApiKey')) {
    function verifyApiKey($db) {
        $appSettings = getModulesAppSettings($db);
        $serverApiKey = $appSettings['api_key'] ?? '';

        if (empty($serverApiKey)) {
            return; // No API key configured on server, permit access
        }

        // Start session if not already active to read session flag
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Secure check: If the request is from our own web client session, bypass verification
        $isOwnWeb = !empty($_SESSION['is_web_client']);

        if (!$isOwnWeb) {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
            $clientApiKey = $normalizedHeaders['x-api-key'] ?? '';

            if (empty($clientApiKey)) {
                foreach ($_SERVER as $key => $val) {
                    $cleanKey = strtoupper($key);
                    if ($cleanKey === 'HTTP_X_API_KEY' || 
                        $cleanKey === 'REDIRECT_HTTP_X_API_KEY' || 
                        $cleanKey === 'HTTP_X_APIKEY' || 
                        $cleanKey === 'REDIRECT_HTTP_X_APIKEY') {
                        $clientApiKey = $val;
                        break;
                    }
                }
            }
            if (empty($clientApiKey)) {
                $clientApiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
            }

            if (empty($clientApiKey) || $clientApiKey !== $serverApiKey) {
                header('Content-Type: application/json');
                http_response_code(426);
                echo json_encode([
                    'status' => 'error',
                    'error_code' => 'APP_INVALID_KEY',
                    'message' => 'App invalid key: Invalid or missing API Key'
                ]);
                exit;
            }
        }
    }
}