<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function requestor_update_json($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function requestor_update_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

$requestId = (int)($_POST['request_id'] ?? 0);
$neededDate = trim((string)($_POST['needed_date'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$items = json_decode($_POST['batch_items'] ?? '[]', true);

if ($requestId <= 0 || $neededDate === '') {
    requestor_update_json(['ok' => false, 'message' => 'Request ID and needed date are required.'], 400);
}

if (!is_array($items) || count($items) === 0) {
    requestor_update_json(['ok' => false, 'message' => 'Enter quantity for at least one line.'], 400);
}

$conn = get_whpokayoke_connection();
$erp = get_erp_connection();
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
    "SELECT RequestID, RequestNo, ITRNumber, SAP_IT_DocEntry, Status
     FROM WarehouseIssueRequestHeader
     WHERE RequestID = ? {$ownerWhere}
       AND Status IN ('OPEN','PARTIAL','RETURNED_NO_STOCK')",
    $params
);

if (!$header) {
    requestor_update_json(['ok' => false, 'message' => 'Request was not found or is no longer editable.'], 404);
}

$existingRows = fetch_all(
    $conn,
    'SELECT RequestLineID, SAP_IT_DocEntry, SAP_IT_DocNum, SAP_IT_LineNum, ItemCode, PartName, RequestedQty, ISNULL(IssuedQty, 0) AS IssuedQty, Status
     FROM WarehouseIssueRequestLines
     WHERE RequestID = ?
       AND Status <> ?',
    [$requestId, 'CANCELLED']
);

$existing = [];
$hasIssued = false;

foreach ($existingRows as $row) {
    $lineId = (int)$row['RequestLineID'];
    $existing[$lineId] = $row;

    if ((float)$row['IssuedQty'] > 0 || !in_array(strtoupper((string)$row['Status']), ['OPEN', 'RETURNED_NO_STOCK'], true)) {
        $hasIssued = true;
    }
}

if ($hasIssued) {
    requestor_update_json(['ok' => false, 'message' => 'This request already has issued or partially issued lines and cannot be edited here.'], 409);
}

$validItems = [];
$seen = [];

foreach ($items as $item) {
    $lineId = (int)($item['request_line_id'] ?? 0);
    $qty = trim((string)($item['request_qty'] ?? $item['requested_qty'] ?? ''));

    if ($lineId <= 0 || !isset($existing[$lineId])) {
        requestor_update_json(['ok' => false, 'message' => 'One request line is invalid or no longer belongs to this request.'], 400);
    }

    if ($qty === '' || !is_numeric($qty) || (float)$qty <= 0) {
        continue;
    }

    $seen[$lineId] = true;
    $validItems[$lineId] = [
        'request_line_id' => $lineId,
        'request_qty' => (float)$qty,
        'row' => $existing[$lineId]
    ];
}

if (count($validItems) === 0) {
    requestor_update_json(['ok' => false, 'message' => 'Enter quantity for at least one valid line.'], 400);
}

$sapOpenQtyColumn = requestor_update_has_column($erp, 'WTQ1', 'OpenQty') ? 'OpenQty' : 'Quantity';

foreach ($validItems as $line) {
    $row = $line['row'];
    $itemCode = trim((string)$row['ItemCode']);
    $docEntry = (int)$row['SAP_IT_DocEntry'];
    $docNum = trim((string)$row['SAP_IT_DocNum']);
    $lineNum = (int)$row['SAP_IT_LineNum'];

    $sapLine = fetch_one(
        $erp,
        "SELECT TOP 1 {$sapOpenQtyColumn} AS OpenQty
         FROM WTQ1
         WHERE DocEntry = ?
           AND LineNum = ?
           AND ItemCode = ?",
        [$docEntry, $lineNum, $itemCode]
    );

    if (!$sapLine) {
        requestor_update_json(['ok' => false, 'message' => 'SAP ITR line was not found for item ' . $itemCode . '.'], 400);
    }

    $otherRequested = fetch_one(
        $conn,
        "SELECT ISNULL(SUM(L.RequestedQty), 0) AS RequestedQty
         FROM WarehouseIssueRequestHeader H
         INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
         WHERE L.SAP_IT_DocNum = ?
           AND L.SAP_IT_LineNum = ?
           AND L.ItemCode = ?
           AND H.Status <> 'CANCELLED'
           AND L.Status NOT IN ('CANCELLED', 'RETURNED_NO_STOCK')
           AND L.RequestID <> ?",
        [$docNum, $lineNum, $itemCode, $requestId]
    );

    $remaining = max(0, (float)$sapLine['OpenQty'] - (float)($otherRequested['RequestedQty'] ?? 0));

    if ((float)$line['request_qty'] > $remaining) {
        requestor_update_json([
            'ok' => false,
            'message' => 'Requested quantity for ' . $itemCode . ' exceeds remaining requestable quantity. Remaining: ' . $remaining . '.'
        ], 400);
    }
}

if (!sqlsrv_begin_transaction($conn)) {
    requestor_update_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$headerOk = sqlsrv_query(
    $conn,
    'UPDATE WarehouseIssueRequestHeader
     SET NeededDate = ?,
         Remarks = ?,
         Status = ?
     WHERE RequestID = ?',
    [$neededDate, $remarks, 'OPEN', $requestId]
);

if ($headerOk === false) {
    sqlsrv_rollback($conn);
    requestor_update_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$saved = 0;

foreach ($existing as $lineId => $row) {
    if (isset($validItems[$lineId])) {
        $ok = sqlsrv_query(
            $conn,
            "UPDATE WarehouseIssueRequestLines
             SET RequestedQty = ?,
                 Status = 'OPEN'
             WHERE RequestLineID = ?
               AND RequestID = ?",
            [$validItems[$lineId]['request_qty'], $lineId, $requestId]
        );

        if ($ok === false) {
            sqlsrv_rollback($conn);
            requestor_update_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
        }

        $saved++;
    } else {
        $ok = sqlsrv_query(
            $conn,
            "UPDATE WarehouseIssueRequestLines
             SET Status = 'CANCELLED'
             WHERE RequestLineID = ?
               AND RequestID = ?",
            [$lineId, $requestId]
        );

        if ($ok === false) {
            sqlsrv_rollback($conn);
            requestor_update_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
        }
    }
}

sqlsrv_commit($conn);

requestor_update_json([
    'ok' => true,
    'message' => 'Request updated successfully.',
    'request_no' => (string)$header['RequestNo'],
    'saved_lines' => $saved
]);
?>
