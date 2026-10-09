<?php
// ============================================================================
// NEWSLETTER
// POST /newsletter/subscribe — the sign-up form of the website footer.
// Body (JSON or form): email, website (honeypot, must stay empty).
// Answers JSON: { status: "success" | "error", message }.
// Subscribers are listed in Admin > Users > Newsletter (admin/newsletterRoutes.php).
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/newsletter/subscribe', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function ($ok, $message) {
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => (string) $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    // only the website's own pages may post here
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '' || parse_url($referer, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
        http_response_code(403);
        $reply(false, 'Forbidden');
    }

    // a bot fills the hidden field: answer as if it worked
    if (trim((string) ($input['website'] ?? '')) !== '') {
        $reply(true, T::newsletter_thanks);
    }

    // at most 5 sign-ups per visitor every 10 minutes
    $now = time();
    $_SESSION['newsletter_tries'] = array_values(array_filter((array) ($_SESSION['newsletter_tries'] ?? []), fn($t) => $t > $now - 600));
    if (count($_SESSION['newsletter_tries']) >= 5) {
        http_response_code(429);
        $reply(false, T::newsletter_invalid);
    }
    $_SESSION['newsletter_tries'][] = $now;

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reply(false, T::newsletter_invalid);
    }

    if (!ensureNewsletterTable($db)) {
        http_response_code(500);
        $reply(false, T::newsletter_invalid);
    }

    $existing = $db->get('newsletter_subscribers', ['id', 'status'], ['email' => $email]);
    if ($existing) {
        if ((int) $existing['status'] === 1) {
            $reply(true, T::newsletter_already);
        }
        $db->update('newsletter_subscribers', ['status' => 1], ['id' => $existing['id']]);
        $reply(true, T::newsletter_thanks);
    }

    $db->insert('newsletter_subscribers', [
        'email' => $email,
        'language' => substr((string) ($_SESSION['app_language'] ?? 'en'), 0, 10),
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'status' => 1,
    ]);
    $reply(true, T::newsletter_thanks);
});
