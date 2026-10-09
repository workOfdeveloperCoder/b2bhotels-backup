<?php
// FILE: app/routes/users/favouritesRoutes.php
// User favourites routes (Page view + AJAX actions)

@$SECURE or die('Access Denied!');

// Helper to format favourite row for frontend consumption
if (!function_exists('format_v10_favourite_row')) {
    function format_v10_favourite_row(array $row): array {
        $data = json_decode((string)($row['data'] ?? ''), true);
        if (!is_array($data)) {
            $data = [];
        }
        $data['id'] = (string)($row['id'] ?? '');
        $data['module'] = strtolower(trim((string)($data['module'] ?? $row['module'] ?? 'stays')));
        $data['title'] = trim((string)($data['title'] ?? ''));
        $data['subtitle'] = trim((string)($data['subtitle'] ?? ''));
        $data['location'] = trim((string)($data['location'] ?? ''));
        $data['price'] = trim((string)($data['price'] ?? ''));
        $data['rating'] = isset($data['rating']) && $data['rating'] !== '' ? (float)$data['rating'] : null;
        $data['reviewsCount'] = isset($data['reviewsCount']) && $data['reviewsCount'] !== '' ? (int)$data['reviewsCount'] : null;
        $data['image'] = trim((string)($data['image'] ?? ''));
        $data['href'] = trim((string)($data['href'] ?? ''));
        $data['addedAt'] = $data['addedAt'] ?? ($row['created_at'] ?? date('Y-m-d H:i:s'));
        return $data;
    }
}

// Helper to get authenticated user ID from session or Bearer token
if (!function_exists('get_fav_auth_user_id')) {
    function get_fav_auth_user_id(): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            return (string)$_SESSION['user_id'];
        }

        // Check Bearer Token
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            if (file_exists('app/lib/jwt.php')) {
                require_once 'app/lib/jwt.php';
                if (class_exists('JWT')) {
                    $tokenData = JWT::verify($matches[1]);
                    if ($tokenData && !empty($tokenData['user_id'])) {
                        return (string)$tokenData['user_id'];
                    }
                }
            }
        }
        return null;
    }
}

// ============================================================================
// 1. MAIN FAVOURITES PAGE VIEW (GET /favourites & GET /favorites)
// ============================================================================
$favouritesPageHandler = function () use ($SECURE, $db) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Check agent verification if needed
    if (function_exists('CHECK_AGENT')) {
        CHECK_AGENT($db);
    }

    $user_id = (string)$_SESSION['user_id'];

    // Get user data for sidebar
    $user = $db->get('users', '*', ['user_id' => $user_id]);
    if (!$user) {
        $user = $db->get('users', '*', ['id' => (int)$user_id]);
    }

    $totalBookings = $db->count('bookings', ['user_id' => $user_id]);

    $dashboardData = [
        'user_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'user_email' => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role' => $user['role'] ?? 'customer',
        'total_bookings' => $totalBookings,
    ];

    // Fetch user's favourites from favorites table (and fallback to favourites table if needed)
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

    $favourites = array_map('format_v10_favourite_row', $favRows);

    // Compute module counts
    $counts = ['all' => count($favourites)];
    foreach ($favourites as $f) {
        $mod = $f['module'] ?: 'stays';
        $counts[$mod] = ($counts[$mod] ?? 0) + 1;
    }

    // Enabled modules
    $enabledModules = $db->select('modules', 'type', ['status' => 1]) ?: ['stays', 'flights', 'tours', 'cars', 'visa'];

    // Page metadata
    $title = T::favourites ?? "Favourites";
    $description = "Your saved listings and wishlists";
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once views . "auth/favourites.php";
    require_once views . "includes/footer.php";
};

$router->get('/favourites', $favouritesPageHandler);
$router->get('/favorites', function () use ($favouritesPageHandler) {
    $favouritesPageHandler();
});

// ============================================================================
// 2. REMOVE FAVOURITE (POST /favourites/remove & POST /api/favourites/remove)
// ============================================================================
$removeFavHandler = function () use ($db) {
    header('Content-Type: application/json');
    $user_id = get_fav_auth_user_id();
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Item ID is required']);
        exit;
    }

    // Delete from favorites and favourites tables
    $deleted = false;
    if ($db->has('favorites', ['id' => $id, 'user_id' => $user_id])) {
        $db->delete('favorites', ['id' => $id, 'user_id' => $user_id]);
        $deleted = true;
    }
    if ($db->has('favourites', ['id' => $id, 'user_id' => $user_id])) {
        $db->delete('favourites', ['id' => $id, 'user_id' => $user_id]);
        $deleted = true;
    }

    if ($deleted) {
        echo json_encode(['status' => 'success', 'message' => 'Favourite removed successfully']);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Favourite not found']);
    }
    exit;
};

$router->post('/favourites/remove', $removeFavHandler);
$router->post('/favorites/remove', $removeFavHandler);
$router->post('/api/favourites/remove', $removeFavHandler);
$router->delete('/profile/favourites/(\d+)', function ($id) use ($db) {
    header('Content-Type: application/json');
    $user_id = get_fav_auth_user_id();
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }
    $idInt = (int)$id;
    $db->delete('favorites', ['id' => $idInt, 'user_id' => $user_id]);
    $db->delete('favourites', ['id' => $idInt, 'user_id' => $user_id]);
    echo json_encode(['status' => 'success', 'message' => 'Favourite removed']);
    exit;
});

// ============================================================================
// 3. CLEAR ALL FAVOURITES (POST /favourites/clear & POST /api/favourites/clear)
// ============================================================================
$clearFavHandler = function () use ($db) {
    header('Content-Type: application/json');
    $user_id = get_fav_auth_user_id();
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    $db->delete('favorites', ['user_id' => $user_id]);
    $db->delete('favourites', ['user_id' => $user_id]);

    echo json_encode(['status' => 'success', 'message' => 'All favourites cleared successfully']);
    exit;
};

$router->post('/favourites/clear', $clearFavHandler);
$router->post('/favorites/clear', $clearFavHandler);
$router->post('/api/favourites/clear', $clearFavHandler);
$router->delete('/profile/favourites', $clearFavHandler);

// ============================================================================
// 4. ADD TO FAVOURITES (POST /favourites/add & POST /api/favourites/add)
// ============================================================================
$addFavHandler = function () use ($db) {
    header('Content-Type: application/json');
    $user_id = get_fav_auth_user_id();
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $module = trim((string)($input['module'] ?? 'stays'));
    $title = trim((string)($input['title'] ?? ''));
    $price = trim((string)($input['price'] ?? ''));
    $href = trim((string)($input['href'] ?? ''));

    if (empty($title)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Title is required']);
        exit;
    }

    $dataToSave = [
        'module' => $module,
        'title' => $title,
        'subtitle' => trim((string)($input['subtitle'] ?? '')),
        'location' => trim((string)($input['location'] ?? '')),
        'price' => $price,
        'rating' => isset($input['rating']) && $input['rating'] !== '' ? (float)$input['rating'] : null,
        'reviewsCount' => isset($input['reviewsCount']) && $input['reviewsCount'] !== '' ? (int)$input['reviewsCount'] : null,
        'image' => trim((string)($input['image'] ?? '')),
        'href' => $href,
        'addedAt' => date('Y-m-d H:i:s'),
    ];

    $now = date('Y-m-d H:i:s');
    $db->insert('favorites', [
        'user_id' => $user_id,
        'data' => json_encode($dataToSave, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $now,
    ]);

    $newId = (int)$db->id();
    $dataToSave['id'] = (string)$newId;

    echo json_encode([
        'status' => 'success',
        'message' => 'Favourite added successfully',
        'data' => $dataToSave
    ]);
    exit;
};

$router->post('/favourites/add', $addFavHandler);
$router->post('/favorites/add', $addFavHandler);
$router->post('/api/favourites/add', $addFavHandler);
$router->post('/profile/favourites', $addFavHandler);

// ============================================================================
// 5. GET FAVOURITES JSON API (GET /api/users/favourites & GET /profile/favourites)
// ============================================================================
$getFavApiHandler = function () use ($db) {
    header('Content-Type: application/json');
    $user_id = get_fav_auth_user_id();
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
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

    $data = array_map('format_v10_favourite_row', $favRows);

    echo json_encode([
        'status' => 'success',
        'message' => 'Favourites retrieved',
        'data' => $data
    ]);
    exit;
};

$router->get('/api/users/favourites', $getFavApiHandler);
$router->get('/profile/favourites', $getFavApiHandler);
