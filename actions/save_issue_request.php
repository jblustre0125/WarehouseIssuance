<?php
require_once __DIR__ . '/../includes/auth.php';

require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function save_issue_json($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function save_issue_has_column($conn, $table, $column)
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

function save_issue_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_NAME = ?",
        [$table]
    );
}

function save_issue_trim($value)
{
    return trim((string)($value ?? ''));
}

function save_issue_date_ok($value)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

$neededDate = save_issue_trim($_POST['needed_date'] ?? '');
$remarks = save_issue_trim($_POST['remarks'] ?? '');
$itrNumber = save_issue_trim($_POST['itr_number'] ?? '');
$itrDocEntry = (int)($_POST['itr_doc_entry'] ?? 0);
$items = json_decode($_POST['batch_items'] ?? '[]', true);

if ($neededDate === '' || !save_issue_date_ok($neededDate)) {
    save_issue_json([
        'ok' => false,
        'message' => 'Needed date is required.'
    ], 400);
}

if ($itrNumber === '') {
    save_issue_json([
        'ok' => false,
        'message' => 'Missing ITR number.'
    ], 400);
}

if ($itrDocEntry <= 0) {
    save_issue_json([
        'ok' => false,
        'message' => 'Missing SAP ITR document entry.'
    ], 400);
}

if (!is_array($items) || count($items) === 0) {
    save_issue_json([
        'ok' => false,
        'message' => 'Enter quantity for at least one line.'
    ], 400);
}

$conn = get_whpokayoke_connection();
$erp = get_erp_connection();
$user = current_user();
$role = strtolower($user['role'] ?? '');

if (!save_issue_has_table($conn, 'WarehouseIssueRequestHeader') || !save_issue_has_table($conn, 'WarehouseIssueRequestLines')) {
    save_issue_json([
        'ok' => false,
        'message' => 'Warehouse issue request tables were not found.'
    ], 500);
}

if (!save_issue_has_table($erp, 'WTQ1')) {
    save_issue_json([
        'ok' => false,
        'message' => 'SAP ITR line table WTQ1 was not found.'
    ], 500);
}

$validItems = [];
$seenKeys = [];

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        continue;
    }

    $docEntry = (int)($item['doc_entry'] ?? $itrDocEntry);
    $docNum = save_issue_trim($item['doc_num'] ?? $itrNumber);
    $lineNum = isset($item['line_num']) ? (int)$item['line_num'] : -1;
    $itemCode = save_issue_trim($item['item_code'] ?? '');
    $partName = save_issue_trim($item['part_name'] ?? '');
    $qtyRaw = save_issue_trim($item['request_qty'] ?? '');
    $lotNo = save_issue_trim($item['lot_no'] ?? '');

    if ($docEntry <= 0) {
        $docEntry = $itrDocEntry;
    }

    if ($docNum === '') {
        $docNum = $itrNumber;
    }

    if ($lineNum < 0 || $itemCode === '') {
        save_issue_json([
            'ok' => false,
            'message' => 'One request line is missing SAP line number or item code.'
        ], 400);
    }

    if ($qtyRaw === '' || !is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
        continue;
    }

    $qty = (float)$qtyRaw;
    $key = $docEntry . '|' . $lineNum . '|' . strtoupper($itemCode);

    if (isset($seenKeys[$key])) {
        save_issue_json([
            'ok' => false,
            'message' => 'Duplicate line found for item ' . $itemCode . ' line ' . $lineNum . '.'
        ], 400);
    }

    $seenKeys[$key] = true;

    $validItems[] = [
        'doc_entry' => $docEntry,
        'doc_num' => $docNum,
        'line_num' => $lineNum,
        'item_code' => $itemCode,
        'part_name' => $partName,
        'lot_no' => $lotNo,
        'request_qty' => $qty
    ];
}

if (count($validItems) === 0) {
    save_issue_json([
        'ok' => false,
        'message' => 'Enter quantity for at least one valid line.'
    ], 400);
}

/*
    IMPORTANT FIX:
    Use SAP WTQ1.Quantity as the requestable quantity.

    Your SAP screen shows the real ITR request quantity in the Quantity column.
    Do not use WTQ1.OpenQty here, because in your case it caused values like 5999 / 1 behavior.
*/
$sapQtyColumn = 'Quantity';

foreach ($validItems as $line) {
    $sapLine = fetch_one(
        $erp,
        "SELECT TOP 1
            {$sapQtyColumn} AS RequestableQty,
            ItemCode,
            LineNum
         FROM WTQ1
         WHERE DocEntry = ?
           AND LineNum = ?
           AND ItemCode = ?",
        [
            $line['doc_entry'],
            $line['line_num'],
            $line['item_code']
        ]
    );

    if (!$sapLine) {
        save_issue_json([
            'ok' => false,
            'message' => 'SAP ITR line was not found for item ' . $line['item_code'] . '.'
        ], 400);
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
           AND L.Status NOT IN ('CANCELLED', 'RETURNED_NO_STOCK')",
        [
            $line['doc_num'],
            $line['line_num'],
            $line['item_code']
        ]
    );

    $requestableQty = (float)($sapLine['RequestableQty'] ?? 0);
    $alreadyRequestedQty = (float)($otherRequested['RequestedQty'] ?? 0);
    $remaining = max(0, $requestableQty - $alreadyRequestedQty);

    if ((float)$line['request_qty'] > $remaining) {
        save_issue_json([
            'ok' => false,
            'message' => 'Requested quantity for ' . $line['item_code'] . ' exceeds remaining requestable quantity. Remaining: ' . $remaining . '.'
        ], 400);
    }
}

$requestNo = 'REQ-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

$requestedByUserId = (int)($user['id'] ?? $user['user_id'] ?? $user['UserID'] ?? 0);
$requestedByUsername = save_issue_trim($user['username'] ?? $user['Username'] ?? '');

if ($requestedByUsername === '') {
    $requestedByUsername = save_issue_trim($user['full_name'] ?? $user['FullName'] ?? 'unknown');
}

if (!sqlsrv_begin_transaction($conn)) {
    save_issue_json([
        'ok' => false,
        'message' => sqlsrv_fail_message()
    ], 500);
}

$headerSql = "
    INSERT INTO WarehouseIssueRequestHeader
        (
            RequestNo,
            ITRNumber,
            SAP_IT_DocEntry,
            NeededDate,
            Status,
            Remarks,
            RequestedByUserID,
            RequestedByUsername,
            RequestedAt
        )
    OUTPUT INSERTED.RequestID
    VALUES
        (?, ?, ?, ?, 'OPEN', ?, ?, ?, GETDATE())
";

$headerStmt = sqlsrv_query(
    $conn,
    $headerSql,
    [
        $requestNo,
        $itrNumber,
        $itrDocEntry,
        $neededDate,
        $remarks,
        $requestedByUserId,
        $requestedByUsername
    ]
);

if ($headerStmt === false) {
    sqlsrv_rollback($conn);
    save_issue_json([
        'ok' => false,
        'message' => sqlsrv_fail_message()
    ], 500);
}

$headerRow = sqlsrv_fetch_array($headerStmt, SQLSRV_FETCH_ASSOC);
$requestId = (int)($headerRow['RequestID'] ?? 0);

if ($requestId <= 0) {
    sqlsrv_rollback($conn);
    save_issue_json([
        'ok' => false,
        'message' => 'Unable to create request header.'
    ], 500);
}

$savedLines = 0;
$totalQty = 0.0;

foreach ($validItems as $line) {
    $lineSql = "
        INSERT INTO WarehouseIssueRequestLines
            (
                RequestID,
                SAP_IT_DocEntry,
                SAP_IT_DocNum,
                SAP_IT_LineNum,
                ItemCode,
                PartName,
                RequestedQty,
                IssuedQty,
                LotNo,
                Status
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, 0, ?, 'OPEN')
    ";

    $lineStmt = sqlsrv_query(
        $conn,
        $lineSql,
        [
            $requestId,
            $line['doc_entry'],
            $line['doc_num'],
            $line['line_num'],
            $line['item_code'],
            $line['part_name'],
            $line['request_qty'],
            $line['lot_no']
        ]
    );

    if ($lineStmt === false) {
        sqlsrv_rollback($conn);
        save_issue_json([
            'ok' => false,
            'message' => sqlsrv_fail_message()
        ], 500);
    }

    $savedLines++;
    $totalQty += (float)$line['request_qty'];
}

if ($savedLines <= 0) {
    sqlsrv_rollback($conn);
    save_issue_json([
        'ok' => false,
        'message' => 'No request lines were saved.'
    ], 400);
}

sqlsrv_commit($conn);

save_issue_json([
    'ok' => true,
    'message' => 'Issue request saved successfully.',
    'request_id' => $requestId,
    'request_no' => $requestNo,
    'itr_number' => $itrNumber,
    'needed_date' => $neededDate,
    'saved_lines' => $savedLines,
    'total_qty' => $totalQty
]);
?>
