<?php
// FILE: app/routes/api/users/favouritesRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// FAVOURITES API (JWT & Bearer Auth)
// ====================================

$apiFavHandler = function () use ($db) {
    header('Content-Type: application/json');

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        if (!empty($_SESSION['user_id'])) {
            $user_id = (string)$_SESSION['user_id'];
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
            exit;
        }
    } else {
        require_once 'app/lib/jwt.php';
        $tokenData = JWT::verify($matches[1]);
        if (!$tokenData || empty($tokenData['user_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
            exit;
        }
        $user_id = (string)$tokenData['user_id'];
    }

    $favRows = $db->select('favorites', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC']
    ]) ?: [];

    if (empty($favRows)) {
        $favRows = $db->select('favourites', '*', [
            'user_id' => $user_id,
            'ORDER' => ['id' => 'DESC']
        ]) ?: [];
    }

    $data = [];
    foreach ($favRows as $f) {
        $parsed = json_decode((string)($f['data'] ?? ''), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $data[] = [
            'id' => (string)($f['id'] ?? ''),
            'module' => $parsed['module'] ?? ($f['module'] ?? 'stays'),
            'title' => $parsed['title'] ?? ($f['title'] ?? ''),
            'subtitle' => $parsed['subtitle'] ?? ($f['subtitle'] ?? ''),
            'location' => $parsed['location'] ?? ($f['location'] ?? ''),
            'price' => $parsed['price'] ?? ($f['price'] ?? ''),
            'rating' => $parsed['rating'] ?? null,
            'reviewsCount' => $parsed['reviewsCount'] ?? null,
            'image' => $parsed['image'] ?? '',
            'href' => $parsed['href'] ?? '',
            'item_id' => $parsed['item_id'] ?? ($f['item_id'] ?? ''),
            'date' => $parsed['addedAt'] ?? ($f['created_at'] ?? '')
        ];
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Favourites retrieved',
        'data' => $data
    ]);
    exit;
};

$router->get('/api/users/favourites', $apiFavHandler);
$router->post('/api/users/favourites', $apiFavHandler);
