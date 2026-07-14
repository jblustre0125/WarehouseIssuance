<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function issuer_no_stock_json($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function issuer_no_stock_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
        [$table]
    );
}

function issuer_no_stock_ensure_table($conn)
{
    if (issuer_no_stock_has_table($conn, 'IssuerNoStockReturns')) {
        return true;
    }

    $sql = "
        CREATE TABLE dbo.IssuerNoStockReturns (
            ReturnID INT IDENTITY(1,1) PRIMARY KEY,
            RequestID INT NOT NULL,
            RequestLineID INT NOT NULL,
            RequestNo NVARCHAR(80) NULL,
            ITRNumber NVARCHAR(80) NULL,
            SAP_IT_DocEntry INT NULL,
            SAP_IT_DocNum INT NULL,
            SAP_IT_LineNum INT NULL,
            ItemCode NVARCHAR(50) NOT NULL,
            PartName NVARCHAR(255) NULL,
            RequestedQty DECIMAL(18,3) NOT NULL DEFAULT 0,
            IssuedQty DECIMAL(18,3) NOT NULL DEFAULT 0,
            RemainingQty DECIMAL(18,3) NOT NULL DEFAULT 0,
            StockWhsCode NVARCHAR(20) NULL,
            StockQty DECIMAL(18,3) NULL,
            ReturnReason NVARCHAR(255) NULL,
            ReturnedByUserID INT NULL,
            ReturnedByUsername NVARCHAR(60) NULL,
            ReturnedAt DATETIME NOT NULL DEFAULT GETDATE(),
            DeviceHostname NVARCHAR(120) NULL,
            DeviceIPAddress NVARCHAR(45) NULL
        )
    ";

    return sqlsrv_query($conn, $sql) !== false;
}

function issuer_no_stock_recompute_header($conn, $requestId)
{
    $summary = fetch_one(
        $conn,
        "SELECT
             COUNT(*) AS TotalLines,
             SUM(CASE WHEN Status IN ('OPEN','PARTIAL') AND RequestedQty > ISNULL(IssuedQty, 0) THEN 1 ELSE 0 END) AS ActiveLines,
             SUM(CASE WHEN RequestedQty <= ISNULL(IssuedQty, 0) THEN 1 ELSE 0 END) AS FullyIssuedLines,
             SUM(CASE WHEN ISNULL(IssuedQty, 0) > 0 THEN 1 ELSE 0 END) AS LinesWithIssue,
             SUM(CASE WHEN Status = 'RETURNED_NO_STOCK' THEN 1 ELSE 0 END) AS ReturnedNoStockLines
         FROM WarehouseIssueRequestLines
         WHERE RequestID = ?",
        [$requestId]
    );

    $totalLines = (int)($summary['TotalLines'] ?? 0);
    $activeLines = (int)($summary['ActiveLines'] ?? 0);
    $fullyIssuedLines = (int)($summary['FullyIssuedLines'] ?? 0);
    $linesWithIssue = (int)($summary['LinesWithIssue'] ?? 0);
    $returnedLines = (int)($summary['ReturnedNoStockLines'] ?? 0);

    if ($totalLines > 0 && $fullyIssuedLines >= $totalLines) {
        $status = 'ISSUED';
    } elseif ($activeLines > 0 && $linesWithIssue > 0) {
        $status = 'PARTIAL';
    } elseif ($activeLines > 0) {
        $status = 'OPEN';
    } elseif ($returnedLines > 0 && $linesWithIssue === 0) {
        $status = 'RETURNED_NO_STOCK';
    } elseif ($returnedLines > 0) {
        $status = 'PARTIAL';
    } else {
        $status = 'OPEN';
    }

    $ok = sqlsrv_query(
        $conn,
        "UPDATE WarehouseIssueRequestHeader SET Status = ? WHERE RequestID = ?",
        [$status, $requestId]
    );

    return $ok !== false ? $status : false;
}

$requestLineId = (int)($_POST['request_line_id'] ?? 0);
$stockWhsCode = trim((string)($_POST['stock_whs_code'] ?? '01'));
$postedStockQty = trim((string)($_POST['stock_qty'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? 'No stock in warehouse'));

if ($requestLineId <= 0) {
    issuer_no_stock_json(['ok' => false, 'message' => 'Request line is required.'], 400);
}

if ($stockWhsCode === '') {
    $stockWhsCode = '01';
}

if ($reason === '') {
    $reason = 'No stock in warehouse';
}

$conn = get_whpokayoke_connection();
$erp = get_erp_connection();
$u = current_user();

if (!issuer_no_stock_ensure_table($conn)) {
    issuer_no_stock_json(['ok' => false, 'message' => 'Unable to prepare no-stock return table: ' . sqlsrv_fail_message()], 500);
}

$line = fetch_one(
    $conn,
    "SELECT
        H.RequestID,
        H.RequestNo,
        H.ITRNumber,
        H.SAP_IT_DocEntry AS HeaderDocEntry,
        H.SAP_IT_DocNum AS HeaderDocNum,
        H.Status AS HeaderStatus,
        L.RequestLineID,
        L.SAP_IT_DocEntry,
        L.SAP_IT_DocNum,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        ISNULL(L.IssuedQty, 0) AS IssuedQty,
        L.Status AS LineStatus
     FROM WarehouseIssueRequestLines L
     INNER JOIN WarehouseIssueRequestHeader H ON H.RequestID = L.RequestID
     WHERE L.RequestLineID = ?",
    [$requestLineId]
);

if (!$line) {
    issuer_no_stock_json(['ok' => false, 'message' => 'Request line was not found.'], 404);
}

$lineStatus = strtoupper((string)($line['LineStatus'] ?? ''));
$headerStatus = strtoupper((string)($line['HeaderStatus'] ?? ''));
$requestedQty = (float)($line['RequestedQty'] ?? 0);
$issuedQty = (float)($line['IssuedQty'] ?? 0);
$remainingQty = max(0, $requestedQty - $issuedQty);

if (!in_array($headerStatus, ['OPEN', 'PARTIAL'], true) || !in_array($lineStatus, ['OPEN', 'PARTIAL'], true) || $remainingQty <= 0.0005) {
    issuer_no_stock_json(['ok' => false, 'message' => 'Only open or partial request lines with remaining quantity can be returned.'], 409);
}

$stockQty = null;

try {
    $hasOitw = fetch_one(
        $erp,
        "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OITW'"
    );

    if ($hasOitw) {
        $stockRow = fetch_one(
            $erp,
            "SELECT ISNULL(OnHand, 0) AS StockQty
             FROM OITW
             WHERE ItemCode = ?
               AND WhsCode = ?",
            [(string)$line['ItemCode'], $stockWhsCode]
        );

        if ($stockRow) {
            $stockQty = (float)($stockRow['StockQty'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $stockQty = null;
}

if ($stockQty === null && $postedStockQty !== '' && is_numeric($postedStockQty)) {
    $stockQty = (float)$postedStockQty;
}

if ($stockQty !== null && $stockQty > 0.0005) {
    issuer_no_stock_json([
        'ok' => false,
        'message' => 'This item still has stock in ' . $stockWhsCode . ' (' . $stockQty . '). Refresh the request before returning it.'
    ], 409);
}

if (!sqlsrv_begin_transaction($conn)) {
    issuer_no_stock_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$auditOk = sqlsrv_query(
    $conn,
    "INSERT INTO IssuerNoStockReturns
        (
            RequestID,
            RequestLineID,
            RequestNo,
            ITRNumber,
            SAP_IT_DocEntry,
            SAP_IT_DocNum,
            SAP_IT_LineNum,
            ItemCode,
            PartName,
            RequestedQty,
            IssuedQty,
            RemainingQty,
            StockWhsCode,
            StockQty,
            ReturnReason,
            ReturnedByUserID,
            ReturnedByUsername,
            ReturnedAt,
            DeviceHostname,
            DeviceIPAddress
        )
     VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?)",
    [
        (int)$line['RequestID'],
        (int)$line['RequestLineID'],
        (string)$line['RequestNo'],
        (string)$line['ITRNumber'],
        $line['SAP_IT_DocEntry'] !== null ? (int)$line['SAP_IT_DocEntry'] : ($line['HeaderDocEntry'] !== null ? (int)$line['HeaderDocEntry'] : null),
        $line['SAP_IT_DocNum'] !== null ? (int)$line['SAP_IT_DocNum'] : ($line['HeaderDocNum'] !== null ? (int)$line['HeaderDocNum'] : null),
        $line['SAP_IT_LineNum'] !== null ? (int)$line['SAP_IT_LineNum'] : null,
        (string)$line['ItemCode'],
        (string)$line['PartName'],
        $requestedQty,
        $issuedQty,
        $remainingQty,
        $stockWhsCode,
        $stockQty,
        $reason,
        (int)($u['id'] ?? 0),
        (string)($u['username'] ?? ''),
        client_hostname(),
        client_ip()
    ]
);

if ($auditOk === false) {
    sqlsrv_rollback($conn);
    issuer_no_stock_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

$lineOk = sqlsrv_query(
    $conn,
    "UPDATE WarehouseIssueRequestLines
     SET Status = 'RETURNED_NO_STOCK'
     WHERE RequestLineID = ?
       AND Status IN ('OPEN','PARTIAL')",
    [$requestLineId]
);

if ($lineOk === false || sqlsrv_rows_affected($lineOk) < 1) {
    sqlsrv_rollback($conn);
    issuer_no_stock_json(['ok' => false, 'message' => 'Request line could not be returned. It may have changed already.'], 409);
}

$newHeaderStatus = issuer_no_stock_recompute_header($conn, (int)$line['RequestID']);

if ($newHeaderStatus === false) {
    sqlsrv_rollback($conn);
    issuer_no_stock_json(['ok' => false, 'message' => sqlsrv_fail_message()], 500);
}

sqlsrv_commit($conn);

issuer_no_stock_json([
    'ok' => true,
    'message' => 'No-stock item returned to requestor.',
    'request_id' => (int)$line['RequestID'],
    'request_line_id' => $requestLineId,
    'header_status' => $newHeaderStatus
]);
?>
