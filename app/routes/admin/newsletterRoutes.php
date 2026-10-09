<?php
// ============================================================================
// ADMIN / NEWSLETTER
// admin/newsletter         the subscribers of the footer sign-up form
// admin/newsletter/export  the active ones as a CSV file (email, language, date)
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get(admin . '/newsletter', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureNewsletterTable($db);

    $title = T::newsletter_subscribers;
    $description = '';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once 'app/views/admin/newsletter.php';
    require_once views . 'includes/footer.php';
});

$router->get(admin . '/newsletter/export', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureNewsletterTable($db);

    $rows = $db->select('newsletter_subscribers', ['email', 'language', 'created_at'], [
        'status' => 1,
        'ORDER' => ['id' => 'ASC'],
    ]) ?: [];

    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="newsletter-subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'language', 'subscribed_at']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['email'], $row['language'], $row['created_at']]);
    }
    fclose($out);
    exit;
});
