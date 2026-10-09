<?php
// FILE: app/routes/api/users/changePasswordRoutes.php
// USERS CHANGE PASSWORD API (authenticated) — mobile app

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ==================================================
// CHANGE PASSWORD (logged-in user)
// POST /api/users/change-password
// body: { current_password, new_password, confirm_password }
// ==================================================
$router->post('/api/users/change-password', function () use ($db) {

    header('Content-Type: application/json');

    // ---- AUTH ----
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData || empty($tokenData['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }

    $user_id = $tokenData['user_id']; // users.user_id

    // ---- INPUT ----
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON payload'
        ]);
        exit;
    }

    $currentPassword = trim($data['current_password'] ?? $data['old_password'] ?? '');
    $newPassword     = trim($data['new_password'] ?? $data['password'] ?? '');
    $confirmPassword = trim($data['confirm_password'] ?? $data['new_password_confirmation'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Current password, new password and confirm password are required'
        ]);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'New password and confirm password do not match'
        ]);
        exit;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'New password must be at least 6 characters'
        ]);
        exit;
    }

    if ($newPassword === $currentPassword) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'New password must be different from the current password'
        ]);
        exit;
    }

    // ---- FETCH USER ----
    $user = $db->get('users', ['id', 'user_id', 'email', 'first_name', 'last_name', 'password'], ['user_id' => $user_id]);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        exit;
    }

    if (!password_verify($currentPassword, $user['password'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Current password is incorrect'
        ]);
        exit;
    }

    // ---- UPDATE ----
    try {
        $updated = $db->update('users', [
            'password'       => password_hash($newPassword, PASSWORD_DEFAULT),
            'login_attempts' => 0,
            'locked_until'   => null,
            'updated_at'     => date('Y-m-d H:i:s')
        ], ['user_id' => $user_id]);

        if ($updated === false) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to update password'
            ]);
            exit;
        }

        if (function_exists('logUserActivity')) {
            logUserActivity($db, $user['id'], 'password_change', 'Password changed via API');
        }

        if (function_exists('triggerWebhook')) {
            triggerWebhook('users/password-change', 'password.changed', [
                'user_id'    => $user['user_id'],
                'email'      => $user['email'],
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'timestamp'  => date('Y-m-d H:i:s')
            ]);
        }

        // Notify the user by email (best effort, reuse the password_reset template)
        if (function_exists('renderEmailTemplate') && function_exists('SENDEMAIL')) {
            $template = $db->get('notification_templates', '*', ['name' => 'password_reset', 'type' => 'email', 'status' => 1]);
            if ($template) {
                $rendered = renderEmailTemplate($template, $user);
                SENDEMAIL($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $rendered['subject'], $rendered['body_html']);
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Password changed successfully'
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update password'
        ]);
        exit;
    }
});
