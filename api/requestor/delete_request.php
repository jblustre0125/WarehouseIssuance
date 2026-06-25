<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function requestor_delete_json($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);

if ($requestId <= 0) {
    requestor_delete_json(['ok' => false, 'message' => 'Missing request ID.'], 400);
}

$conn = get_whpokayoke_connection();
$u = current_user();
$role = strtolower($u['role'] ?? '');

$params = [$requestId];
$ownerWhere = '';

if ($role !== ROLE_ADMIN) {
    $ownerWhere = ' AND (RequestedByUserID = ? OR RequestedByUsername = ?)';
    $params[] = (int)($u['id'] ?? 0);
    $params[] = (string)($u['username'] ?? '');
}

$header = fetch_one(
    $conn,
    "SELECT RequestID, RequestNo, Status
     FROM WarehouseIssueRequestHeader
     WHERE RequestID = ? {$ownerWhere}",
    $params
);

if (!$header) {
    requestor_delete_json(['ok' => false, 'message' => 'Request was not found or you do not have permission to delete it.'], 404);
}

$issued = fetch_one(
    $conn,
    'SELECT COUNT(*) AS IssuedCount
     FROM WarehouseIssueRequestLines
     WHERE RequestID = ?
       AND ISNULL(IssuedQty, 0) > 0',
    [$requestId]
);

if ((int)($issued['IssuedCount'] ?? 0) > 0) {
    requestor_delete_json(['ok' => false, 'message' => 'This request already has issued quantity and cannot be deleted.'], 409);
}

if (!sqlsrv_begin_transaction($conn)) {
    requestor_delete_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$lineOk = sqlsrv_query(
    $conn,
    "UPDATE WarehouseIssueRequestLines
     SET Status = 'CANCELLED'
     WHERE RequestID = ?",
    [$requestId]
);

$headerOk = sqlsrv_query(
    $conn,
    "UPDATE WarehouseIssueRequestHeader
     SET Status = 'CANCELLED',
         ClosedAt = GETDATE()
     WHERE RequestID = ?",
    [$requestId]
);

if ($lineOk === false || $headerOk === false) {
    sqlsrv_rollback($conn);
    requestor_delete_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

sqlsrv_commit($conn);

requestor_delete_json([
    'ok' => true,
    'message' => 'Request cancelled successfully.',
    'request_no' => (string)$header['RequestNo']
]);
?>
