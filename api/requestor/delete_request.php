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

if (!sqlsrv_begin_transaction($conn)) {
    requestor_delete_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$lineOk = sqlsrv_query(
    $conn,
    "UPDATE WarehouseIssueRequestLines
     SET Status = 'CANCELLED'
     WHERE RequestID = ?
       AND ISNULL(IssuedQty, 0) <= 0
       AND Status <> 'CANCELLED'",
    [$requestId]
);

$summary = fetch_one(
    $conn,
    "SELECT
         SUM(CASE WHEN Status IN ('OPEN','PARTIAL','RETURNED_NO_STOCK') AND RequestedQty > ISNULL(IssuedQty, 0) THEN 1 ELSE 0 END) AS ActiveLines,
         SUM(CASE WHEN ISNULL(IssuedQty, 0) > 0 THEN 1 ELSE 0 END) AS IssuedLines,
         SUM(CASE WHEN Status <> 'CANCELLED' THEN 1 ELSE 0 END) AS RemainingLines
     FROM WarehouseIssueRequestLines
     WHERE RequestID = ?",
    [$requestId]
);

$activeLines = (int)($summary['ActiveLines'] ?? 0);
$issuedLines = (int)($summary['IssuedLines'] ?? 0);
$remainingLines = (int)($summary['RemainingLines'] ?? 0);
$newStatus = $remainingLines === 0
    ? 'CANCELLED'
    : ($activeLines > 0 && $issuedLines > 0 ? 'PARTIAL' : 'ISSUED');

$headerOk = sqlsrv_query(
    $conn,
    "UPDATE WarehouseIssueRequestHeader
     SET Status = ?,
         ClosedAt = CASE WHEN ? IN ('CANCELLED', 'ISSUED') THEN GETDATE() ELSE NULL END
     WHERE RequestID = ?",
    [$newStatus, $newStatus, $requestId]
);

if ($lineOk === false || $headerOk === false) {
    sqlsrv_rollback($conn);
    requestor_delete_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

sqlsrv_commit($conn);

requestor_delete_json([
    'ok' => true,
    'message' => $remainingLines > 0
        ? 'Unissued returned lines were cancelled. Issued lines were preserved.'
        : 'Request cancelled successfully.',
    'request_no' => (string)$header['RequestNo']
]);
?>
