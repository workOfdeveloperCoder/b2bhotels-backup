<?php
/**
 * CRON JOB: RateHawk Content (static hotel data) 24h Sync
 * FILE: app/routes/crons/ratehawkContentSyncRoutes.php
 *
 * HOW IT WORKS:
 * -------------
 * 1. Only does anything when the admin has turned ON
 *    "Auto-refresh static data every 24 hours" in the RateHawk module's
 *    Content Sync tab. If it is OFF, this endpoint no-ops (skipped: disabled).
 * 2. Also skips if a refresh already ran in the last 24h (ETG asks that the
 *    Content API is not pulled more than once per 24h).
 * 3. Otherwise it discovers hotels changed since the last watermark and drains
 *    the sync queue (hotel_content_by_ids + hotel_reviews_by_ids).
 *
 * It simply forwards to the module's own self-contained sync endpoint
 * (action=autocheck), which holds all the logic and the 24h guard.
 *
 * CRON SETUP (run daily on the server — safe to leave in the crontab even when
 * the toggle is off, it just returns "skipped"):
 *   0 4 * * * curl -s "https://yourdomain.com/ratehawk_content_sync" > /dev/null 2>&1
 *
 * ROUTE: GET /ratehawk_content_sync
 */

@$SECURE or die('Access Denied!');

$router->get('/ratehawk_content_sync', function () use ($SECURE, $db) {

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $module = $db->get('modules', ['id'], ['name' => 'ratehawk', 'type' => 'stays']);
    if (!$module) {
        echo json_encode(['success' => false, 'message' => 'RateHawk stays module not found']);
        exit;
    }

    // Forward to the module's self-dispatching sync endpoint. All the work,
    // the enable check and the 24h guard live there (autoCheck()).
    $endpoint = rtrim(root, '/') . '/modules/stays/ratehawk/content/ratehawk-content-sync.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['action' => 'autocheck', 'budget' => 280, 'cron' => 1]),
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $inner = json_decode((string) $raw, true);

    echo json_encode([
        'success' => $code === 200 && is_array($inner) && !empty($inner['success']),
        'run_at'  => date('Y-m-d H:i:s'),
        'result'  => $inner['data'] ?? null,
        'error'   => $err ?: ($inner['error'] ?? null),
    ]);
    exit;
});
