<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_PICKER, ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method Not Allowed.']);
    exit;
}
echo json_encode(['ok' => true, 'tablet_bluetooth' => true, 'confirmation' => 'confirmed_print_v1']);
