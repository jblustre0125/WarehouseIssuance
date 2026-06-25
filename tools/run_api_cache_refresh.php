<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$route = trim((string)($argv[1] ?? ''));
$role = trim((string)($argv[2] ?? 'admin'));
$username = trim((string)($argv[3] ?? 'cache_sync'));
$userId = (int)($argv[4] ?? 0);

$allowedRoutes = [
    'api/get_open_itr_requests.php',
    'api/get_open_issue_requests.php',
    'api/stocks/list.php',
    'api/picker/open_purchase_orders.php',
    'api/requestor/list_sap_inventory_transfers.php',
    'api/requestor/list_requests.php',
];

$parts = parse_url($route);
$path = str_replace('\\', '/', ltrim((string)($parts['path'] ?? ''), '/'));

if (!in_array($path, $allowedRoutes, true)) {
    fwrite(STDERR, "Route is not allowed: {$route}\n");
    exit(2);
}

parse_str((string)($parts['query'] ?? ''), $_GET);
$_GET['refresh'] = '1';

$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . $path;
$_SERVER['PHP_SELF'] = '/' . $path;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['user'] = [
    'id' => $userId,
    'user_id' => $userId,
    'UserID' => $userId,
    'username' => $username,
    'Username' => $username,
    'full_name' => 'SAP Cache Sync',
    'role' => $role,
    'RoleName' => $role,
    'receiver_area' => '',
];

require dirname(__DIR__) . '/' . $path;

?>
