<?php
/**
 * RATEHAWK BACKGROUND WORKER
 * =====================================
 * This script runs in the background via CLI. It polls the RateHawk WorldOta API
 * to check the status of a pending/processing booking, and updates the database
 * once booking is confirmed or failed.
 *
 * It prevents duplicate execution using a file locking mechanism.
 */

// 1. Security Check: CLI execution only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden - CLI execution only\n");
}

// 2. Validate input arguments
$invoiceId = $argv[1] ?? '';
if (empty($invoiceId)) {
    error_log("RateHawk Background Worker: Missing invoice_id parameter.");
    exit(1);
}

// Set script run timeout limit (10 minutes)
set_time_limit(600);

// 3. Establish Lock File to prevent parallel duplicate check loops for the same booking
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$lockFile = $logDir . '/lock_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $invoiceId) . '.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp) {
    error_log("RateHawk Background Worker: Cannot create lock file: " . $lockFile);
    exit(1);
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another worker is already checking this booking
    error_log("RateHawk Background Worker: Another worker is already active for Invoice: " . $invoiceId);
    fclose($lockFp);
    exit(0);
}

// Define logging helper
$logMsg = function ($msg) use ($invoiceId, $logDir) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] [Invoice: {$invoiceId}] {$msg}\n";
    @file_put_contents($logDir . '/background_worker.log', $line, FILE_APPEND);
    error_log("RateHawk Background Worker: Invoice {$invoiceId} - " . $msg);
};

$logMsg("Acquired lock and started worker process.");

// 4. Load application environment and bootstrap Medoo
$envPath = dirname(dirname(dirname(__DIR__))) . '/.env';
if (!file_exists($envPath)) {
    $logMsg("ERROR: .env file not found at " . $envPath);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}

$env = parse_ini_file($envPath);
$autoloadPath = dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $logMsg("ERROR: Composer autoloader not found at " . $autoloadPath);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}
require_once $autoloadPath;

use Medoo\Medoo;

$dbHost = $env['DB_HOST'] ?? 'localhost';
try {
    $db = new Medoo([
        'type'     => $env['DB_TYPE'] ?? 'mysql',
        'host'     => $dbHost,
        'database' => $env['DB_DATABASE'],
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
        'charset'  => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
} catch (Exception $e) {
    // If connection failed and host was 'localhost', try '127.0.0.1' (TCP/IP bypasses socket on CLI)
    if ($dbHost === 'localhost') {
        try {
            $logMsg("Database connection to 'localhost' failed. Retrying with '127.0.0.1'...");
            $db = new Medoo([
                'type'     => $env['DB_TYPE'] ?? 'mysql',
                'host'     => '127.0.0.1',
                'database' => $env['DB_DATABASE'],
                'username' => $env['DB_USERNAME'],
                'password' => $env['DB_PASSWORD'],
                'charset'  => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
        } catch (Exception $e2) {
            $logMsg("ERROR: Database connection failed (retry): " . $e2->getMessage());
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
            exit(1);
        }
    } else {
        $logMsg("ERROR: Database connection failed: " . $e->getMessage());
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($lockFile);
        exit(1);
    }
}

// 5. Retrieve booking and credentials
$booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'ratehawk']);
if (!$booking) {
    $logMsg("ERROR: Booking not found in database.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}

if ($booking['booking_status'] === 'confirmed') {
    $logMsg("Booking is already confirmed. Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(0);
}

if ($booking['booking_status'] === 'failed') {
    $logMsg("Booking is already marked as failed. Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(0);
}

$bookingData = json_decode($booking['booking_data'] ?? '{}', true);
if (!is_array($bookingData)) $bookingData = [];

$partnerOrderId = $bookingData['ratehawk_partner_order_id'] ?? $booking['pnr'] ?? '';
if (empty($partnerOrderId)) {
    $logMsg("ERROR: Missing RateHawk partner_order_id for this booking.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}

$moduleData = $db->get('modules', '*', ['name' => 'ratehawk']);
if (!$moduleData) {
    $logMsg("ERROR: RateHawk module configuration not found in DB.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}

$credentials = json_decode($moduleData['credentials'] ?? '{}', true);
if (!is_array($credentials)) $credentials = [];

$keyId = $credentials['key_id'] ?? $moduleData['c1'] ?? '';
$apiKey = $credentials['api_key'] ?? $moduleData['c3'] ?? '';
if (empty($keyId) || empty($apiKey)) {
    $logMsg("ERROR: RateHawk API credentials not configured.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(1);
}

$apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
if (strpos($apiBaseUrl, 'http') !== 0) {
    $devMode = strtolower($moduleData['dev_mode'] ?? '0');
    $isTestEnvironment = in_array($devMode, ['1', 'test', 'on']);
    $apiBaseUrl = $isTestEnvironment ? 'https://api-sandbox.worldota.net' : 'https://api.worldota.net';
    $logMsg("Configured API URL '{$moduleData['c4']}' is invalid. Using fallback URL: {$apiBaseUrl}");
}
if (stripos($apiBaseUrl, '/api/b2b/v3') === false) $apiBaseUrl .= '/api/b2b/v3';

// 6. Polling loop
$status = 'processing';
$percent = null;
$maxAttempts = 120; // 120 * 5s = 600s = 10 minutes max
$attempt = 0;

$logMsg("Polling status for Partner Order ID: " . $partnerOrderId);

while ($attempt < $maxAttempts) {
    $attempt++;

    // Reload booking status to see if it was updated by user/admin manual trigger
    $current = $db->get('bookings', ['booking_status'], ['invoice_id' => $invoiceId]);
    if ($current && in_array($current['booking_status'], ['confirmed', 'failed', 'cancelled'])) {
        $logMsg("Booking resolved externally to status '{$current['booking_status']}'. Exiting worker.");
        break;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiBaseUrl . '/hotel/order/booking/finish/status/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $keyId . ':' . $apiKey,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['partner_order_id' => $partnerOrderId]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!empty($curlError)) {
        $logMsg("Attempt {$attempt}: cURL connection error: " . $curlError);
        sleep(5);
        continue;
    }

    $decoded = json_decode($raw, true);
    if ($code !== 200 || !is_array($decoded)) {
        $logMsg("Attempt {$attempt}: API returned HTTP code {$code} or invalid payload: " . substr((string)$raw, 0, 150));
        sleep(5);
        continue;
    }

    $status = $decoded['status'] ?? 'processing';
    $statusData = $decoded['data'] ?? [];
    $percent = $statusData['percent'] ?? null;

    $logMsg("Attempt {$attempt}: Current API status: '{$status}', percent: '{$percent}%'");

    // Write periodic trace logs
    @file_put_contents(
        $logDir . "/background_status_trace_{$invoiceId}.json",
        json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'attempt' => $attempt,
            'response' => $decoded
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if ($status === 'ok') {
        $reference = $statusData['reference']
            ?? $statusData['hotel_confirmation_code']
            ?? $statusData['booking_id']
            ?? $statusData['partner_order_id']
            ?? $partnerOrderId;

        $latestBooking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        $latestBookingData = json_decode($latestBooking['booking_data'] ?? '{}', true);
        if (!is_array($latestBookingData)) $latestBookingData = [];

        $latestBookingData['ratehawk_last_status'] = $decoded;
        $latestBookingData['ratehawk_last_status_checked_at'] = date('Y-m-d H:i:s');
        $latestBookingData['ratehawk_background_worker_finished'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status' => 'confirmed',
            'pnr'            => $reference,
            'booking_data'   => json_encode($latestBookingData),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $logMsg("SUCCESS: Booking confirmed with PNR: {$reference}");
        break;
    }

    if ($status === 'error') {
        $error = $decoded['error'] ?? 'unknown_error';
        $validation = $decoded['debug']['validation_error'] ?? '';

        $latestBooking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        $latestBookingData = json_decode($latestBooking['booking_data'] ?? '{}', true);
        if (!is_array($latestBookingData)) $latestBookingData = [];

        $latestBookingData['ratehawk_last_status'] = $decoded;
        $latestBookingData['ratehawk_last_status_checked_at'] = date('Y-m-d H:i:s');
        $latestBookingData['ratehawk_background_worker_finished'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status' => 'failed',
            'booking_data'   => json_encode($latestBookingData),
            'error_response' => json_encode($decoded),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $logMsg("FAILED: Booking rejected with error: {$error} ({$validation})");
        break;
    }

    if ($status === '3ds') {
        $latestBooking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        $latestBookingData = json_decode($latestBooking['booking_data'] ?? '{}', true);
        if (!is_array($latestBookingData)) $latestBookingData = [];

        $latestBookingData['ratehawk_last_status'] = $decoded;
        $latestBookingData['ratehawk_last_status_checked_at'] = date('Y-m-d H:i:s');
        $latestBookingData['ratehawk_background_worker_finished'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status' => 'failed',
            'booking_data'   => json_encode($latestBookingData),
            'error_response' => json_encode([
                'error' => '3ds_required',
                'message' => '3D Secure authentication required. Cannot auto-complete.'
            ]),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $logMsg("FAILED: Booking aborted because 3D Secure is required.");
        break;
    }

    // Still processing - update database JSON metadata so customer frontend sees real-time status/progress percent
    $latestBooking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    $latestBookingData = json_decode($latestBooking['booking_data'] ?? '{}', true);
    if (!is_array($latestBookingData)) $latestBookingData = [];

    $latestBookingData['ratehawk_last_status'] = $decoded;
    $latestBookingData['ratehawk_last_status_checked_at'] = date('Y-m-d H:i:s');

    $db->update('bookings', [
        'booking_data' => json_encode($latestBookingData),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], ['invoice_id' => $invoiceId]);

    sleep(5);
}

if ($attempt >= $maxAttempts) {
    $logMsg("TIMEOUT: Background polling loop exceeded maximum time limit (10 minutes) without status resolution.");
}

// 7. Cleanup Lock File
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
$logMsg("Worker shut down and lock released.");
