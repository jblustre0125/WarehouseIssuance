<?php
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);

if (!function_exists('issuer_wants_json_response')) {
    function issuer_wants_json_response()
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return (($_POST['ajax'] ?? '') === '1') ||
            strpos($accept, 'application/json') !== false ||
            $requestedWith === 'xmlhttprequest';
    }
}

$items = json_decode($_POST['batch_items'] ?? '[]', true);
if (!is_array($items) || count($items) === 0) {
    if (issuer_wants_json_response()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'No items to save.'
        ]);
        exit;
    }

    app_error('No items to save.', 400);
}
$conn = get_whpokayoke_connection();
$erp = get_erp_connection();
$u = current_user();
$itrNumbers = [];
foreach ($items as $item) {
    $lineItr = trim($item['itr_number'] ?? '');
    if ($lineItr !== '') $itrNumbers[$lineItr] = true;
}
$itrNumber = count($itrNumbers) === 1 ? array_key_first($itrNumbers) : (count($itrNumbers) > 1 ? 'MULTIPLE' : '');
$traceNo = 'TRC-' . date('Ymd-His') . '-' . random_int(100, 999);

function whp_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

$headerCols = ['TraceNo', 'ITRNumber', 'Status', 'CreatedByUserID', 'CreatedByUsername', 'DeviceHostname', 'DeviceIPAddress'];
$headerParams = [$traceNo, $itrNumber, 'ISSUED', $u['id'], $u['username'], client_hostname(), client_ip()];
$firstDocEntry = trim($items[0]['itr_doc_entry'] ?? '');
$firstDocNum = trim($items[0]['itr_doc_num'] ?? ($items[0]['itr_number'] ?? ''));
$firstRequestId = '';
foreach ($items as $item) {
    $candidateRequestId = trim((string)($item['request_id'] ?? ''));
    if ($candidateRequestId !== '') {
        $firstRequestId = $candidateRequestId;
        break;
    }
}
if ($firstDocEntry !== '' && whp_has_column($conn, 'RawmatTraceHeader', 'SAP_IT_DocEntry')) {
    $headerCols[] = 'SAP_IT_DocEntry';
    $headerParams[] = $firstDocEntry;
}
if ($firstDocNum !== '' && whp_has_column($conn, 'RawmatTraceHeader', 'SAP_IT_DocNum')) {
    $headerCols[] = 'SAP_IT_DocNum';
    $headerParams[] = $firstDocNum;
}
if ($firstRequestId !== '' && whp_has_column($conn, 'RawmatTraceHeader', 'DestinationArea')) {
    $requestArea = fetch_one(
        $conn,
        'SELECT DestinationArea FROM WarehouseIssueRequestHeader WHERE RequestID = ?',
        [$firstRequestId]
    );
    $destinationArea = trim((string)($requestArea['DestinationArea'] ?? ''));

    if ($destinationArea !== '') {
        $headerCols[] = 'DestinationArea';
        $headerParams[] = $destinationArea;
    }
}
$sqlHeader = 'INSERT INTO RawmatTraceHeader (' . implode(', ', $headerCols) . ') VALUES (' . implode(', ', array_fill(0, count($headerCols), '?')) . ')';
$stmt = sqlsrv_query($conn, $sqlHeader, $headerParams);
if ($stmt === false) app_error(sqlsrv_fail_message());
$row = fetch_one($conn, 'SELECT TraceID FROM RawmatTraceHeader WHERE TraceNo = ?', [$traceNo]);
$traceId = (int)$row['TraceID'];
$saved = []; $failed = [];
$affectedRequestIds = [];

$lineHasSapDocEntry = whp_has_column($conn, 'RawmatTraceLines', 'SAP_IT_DocEntry');
$lineHasSapDocNum = whp_has_column($conn, 'RawmatTraceLines', 'SAP_IT_DocNum');
$lineHasSapLineNum = whp_has_column($conn, 'RawmatTraceLines', 'SAP_IT_LineNum');
$lineHasRequestId = whp_has_column($conn, 'RawmatTraceLines', 'IssueRequestID');
$lineHasRequestLineId = whp_has_column($conn, 'RawmatTraceLines', 'IssueRequestLineID');
$lineHasVerificationStatus = whp_has_column($conn, 'RawmatTraceLines', 'VerificationStatus');
$lineHasWarehouseLot = whp_has_column($conn, 'RawmatTraceLines', 'WarehouseLotNo');
$txHasItrDocEntry = whp_has_column($conn, 'IssuanceTransactions', 'ITRDocEntry');
$txHasItrLineNum = whp_has_column($conn, 'IssuanceTransactions', 'ITRLineNum');
$txHasRequestId = whp_has_column($conn, 'IssuanceTransactions', 'IssueRequestID');
$txHasRequestLineId = whp_has_column($conn, 'IssuanceTransactions', 'IssueRequestLineID');
$txHasWarehouseLot = whp_has_column($conn, 'IssuanceTransactions', 'WarehouseLotNo');
$requestLineHasWarehouseLot = whp_has_column($conn, 'WarehouseIssueRequestLines', 'WarehouseLotNo');

foreach ($items as $item) {
    $itemCode = trim($item['item_code'] ?? ''); $qty = trim($item['quantity'] ?? ''); $lot = trim($item['lot_no'] ?? '');
    $warehouseLot = trim((string)($item['warehouse_lot_no'] ?? ''));
    $lineItrNumber = trim($item['itr_number'] ?? '');
    $itrDocEntry = trim($item['itr_doc_entry'] ?? '');
    $itrDocNum = trim($item['itr_doc_num'] ?? $lineItrNumber);
    $itrLineNum = trim($item['itr_line_num'] ?? '');
    $requestId = trim($item['request_id'] ?? '');
    $requestLineId = trim($item['request_line_id'] ?? '');
    $method = strtoupper(trim($item['entry_method'] ?? 'SCAN')); $reason = trim($item['manual_reason'] ?? '');
    if ($itemCode === '' || $qty === '' || $lot === '') { $failed[] = ['item'=>$item,'reason'=>'Missing item, qty, or GRPO lot']; continue; }
    // WH Lot No is optional. Do not block issuance when blank.
    if (!is_numeric($qty) || (float)$qty <= 0) { $failed[] = ['item'=>$item,'reason'=>'Quantity must be greater than zero']; continue; }
    if ($method === 'MANUAL' && $reason === '') { $failed[] = ['item'=>$item,'reason'=>'Manual entry requires reason']; continue; }
    // Resolve SAP item by ItemCode, OITM.CodeBars, or OBCD.BcdCode.
    $part = fetch_one($erp, "SELECT TOP 1 ItemCode, ItemName FROM OITM WHERE LTRIM(RTRIM(ItemCode)) = LTRIM(RTRIM(?))", [$itemCode]);
    if (!$part) {
        $hasCodeBars = fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OITM' AND COLUMN_NAME = 'CodeBars'");
        if ($hasCodeBars) {
            $part = fetch_one($erp, "SELECT TOP 1 ItemCode, ItemName FROM OITM WHERE LTRIM(RTRIM(CodeBars)) = LTRIM(RTRIM(?))", [$itemCode]);
        }
    }
    if (!$part) {
        $hasOBCD = fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OBCD'");
        if ($hasOBCD) {
            $part = fetch_one($erp, "SELECT TOP 1 I.ItemCode, I.ItemName FROM OBCD B INNER JOIN OITM I ON I.ItemCode = B.ItemCode WHERE LTRIM(RTRIM(B.BcdCode)) = LTRIM(RTRIM(?))", [$itemCode]);
        }
    }
    if (!$part) {
        // Your current QR may contain a code that is part of OITM.ItemName/description, not the SAP ItemCode.
        $like = '%' . str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $itemCode) . '%';
        $stmtFind = sqlsrv_query($erp, "SELECT TOP 2 ItemCode, ItemName FROM OITM WHERE ItemName LIKE ? ORDER BY ItemCode", [$like]);
        $matches = [];
        if ($stmtFind !== false) {
            while ($m = sqlsrv_fetch_array($stmtFind, SQLSRV_FETCH_ASSOC)) $matches[] = $m;
        }
        if (count($matches) === 1) {
            $part = $matches[0];
        } elseif (count($matches) > 1) {
            $failed[] = ['item'=>$item,'reason'=>'More than one SAP item description contains the scanned code. Use SAP ItemCode/barcode or make description code unique.'];
            continue;
        }
    }
    if (!$part) { $failed[] = ['item'=>$item,'reason'=>'Item not found in SAP B1 ItemCode, barcode fields, or ItemName/description.']; continue; }
    $itemCode = $part['ItemCode'];
    $partName = $part['ItemName'];
    $lineCols = ['TraceID', 'ItemCode', 'PartName', 'LotNo', 'IssuedQty', 'EntryMethod', 'ManualReason', 'IssuedByUsername'];
    $params = [$traceId, $itemCode, $partName, $lot, $qty, $method, $reason, $u['username']];
    if ($lineHasSapDocEntry && $itrDocEntry !== '') { $lineCols[] = 'SAP_IT_DocEntry'; $params[] = $itrDocEntry; }
    if ($lineHasSapDocNum && $itrDocNum !== '') { $lineCols[] = 'SAP_IT_DocNum'; $params[] = $itrDocNum; }
    if ($lineHasSapLineNum && $itrLineNum !== '') { $lineCols[] = 'SAP_IT_LineNum'; $params[] = $itrLineNum; }
    if ($lineHasRequestId && $requestId !== '') { $lineCols[] = 'IssueRequestID'; $params[] = $requestId; }
    if ($lineHasRequestLineId && $requestLineId !== '') { $lineCols[] = 'IssueRequestLineID'; $params[] = $requestLineId; }
    if ($lineHasWarehouseLot) { $lineCols[] = 'WarehouseLotNo'; $params[] = $warehouseLot; }
    if ($lineHasVerificationStatus) { $lineCols[] = 'VerificationStatus'; $params[] = 'ISSUED'; }
    $lineSql = 'INSERT INTO RawmatTraceLines (' . implode(', ', $lineCols) . ') VALUES (' . implode(', ', array_fill(0, count($lineCols), '?')) . ')';
    $ok = sqlsrv_query($conn, $lineSql, $params);
    if ($ok === false) { $failed[] = ['item'=>$item,'reason'=>sqlsrv_fail_message()]; continue; }
    $txCols = ['TraceNo', 'ItemCode', 'PartName', 'Quantity', 'LotNo', 'ITRNumber', 'IssuedByUserID', 'IssuedByUsername', 'DeviceHostname', 'DeviceIPAddress'];
    $txParams = [$traceNo,$itemCode,$partName,$qty,$lot,$lineItrNumber,$u['id'],$u['username'],client_hostname(),client_ip()];
    if ($txHasItrDocEntry && $itrDocEntry !== '') { $txCols[] = 'ITRDocEntry'; $txParams[] = $itrDocEntry; }
    if ($txHasItrLineNum && $itrLineNum !== '') { $txCols[] = 'ITRLineNum'; $txParams[] = $itrLineNum; }
    if ($txHasRequestId && $requestId !== '') { $txCols[] = 'IssueRequestID'; $txParams[] = $requestId; }
    if ($txHasRequestLineId && $requestLineId !== '') { $txCols[] = 'IssueRequestLineID'; $txParams[] = $requestLineId; }
    if ($txHasWarehouseLot) { $txCols[] = 'WarehouseLotNo'; $txParams[] = $warehouseLot; }
    sqlsrv_query($conn, 'INSERT INTO IssuanceTransactions (' . implode(', ', $txCols) . ') VALUES (' . implode(', ', array_fill(0, count($txCols), '?')) . ')', $txParams);
    if ($requestLineId !== '') {
        $issueQty = (float)$qty;

        $requestUpdateSql = "UPDATE WarehouseIssueRequestLines
             SET IssuedQty = CASE
                     WHEN ISNULL(IssuedQty, 0) + ? >= RequestedQty THEN RequestedQty
                     ELSE ISNULL(IssuedQty, 0) + ?
                 END,
                 LotNo = COALESCE(NULLIF(LotNo, ''), ?),";

        $requestUpdateParams = [$issueQty, $issueQty, $lot];

        if ($requestLineHasWarehouseLot && $warehouseLot !== '') {
            $requestUpdateSql .= "
                 WarehouseLotNo = COALESCE(NULLIF(WarehouseLotNo, ''), ?),";
            $requestUpdateParams[] = $warehouseLot;
        }

        $requestUpdateSql .= "
                 Status = CASE
                     WHEN ISNULL(IssuedQty, 0) + ? >= RequestedQty THEN 'ISSUED'
                     WHEN ISNULL(IssuedQty, 0) + ? > 0 THEN 'PARTIAL'
                     ELSE 'OPEN'
                 END
             WHERE RequestLineID = ?";

        $requestUpdateParams[] = $issueQty;
        $requestUpdateParams[] = $issueQty;
        $requestUpdateParams[] = $requestLineId;

        $requestUpdateStmt = sqlsrv_query($conn, $requestUpdateSql, $requestUpdateParams);

        if ($requestUpdateStmt === false) {
            $failed[] = ['item' => $item, 'reason' => 'Unable to update request issued quantity: ' . sqlsrv_fail_message()];
            continue;
        }

        $rowsAffected = sqlsrv_rows_affected($requestUpdateStmt);

        if ($rowsAffected === false || $rowsAffected < 1) {
            $failed[] = ['item' => $item, 'reason' => 'Request line was not updated. RequestLineID ' . $requestLineId . ' was not found.'];
            continue;
        }

        if ($requestId !== '') {
            $affectedRequestIds[(int)$requestId] = true;
        }
    }
    $item['item_code'] = $itemCode; $item['part_name'] = $partName; $item['warehouse_lot_no'] = $warehouseLot; $saved[] = $item;
}

foreach (array_keys($affectedRequestIds) as $affectedRequestId) {
    $summary = fetch_one(
        $conn,
        "SELECT
             COUNT(*) AS TotalLines,
             SUM(CASE WHEN RequestedQty <= ISNULL(IssuedQty, 0) THEN 1 ELSE 0 END) AS FullyIssuedLines,
             SUM(CASE WHEN ISNULL(IssuedQty, 0) > 0 THEN 1 ELSE 0 END) AS LinesWithIssue
         FROM WarehouseIssueRequestLines
         WHERE RequestID = ?",
        [$affectedRequestId]
    );

    $totalLines = (int)($summary['TotalLines'] ?? 0);
    $fullyIssuedLines = (int)($summary['FullyIssuedLines'] ?? 0);
    $linesWithIssue = (int)($summary['LinesWithIssue'] ?? 0);

    if ($totalLines > 0 && $fullyIssuedLines >= $totalLines) {
        $newRequestStatus = 'ISSUED';
    } elseif ($linesWithIssue > 0) {
        $newRequestStatus = 'PARTIAL';
    } else {
        $newRequestStatus = 'OPEN';
    }

    $headerUpdate = sqlsrv_query(
        $conn,
        "UPDATE WarehouseIssueRequestHeader
         SET Status = ?, IssuedTraceNo = ?
         WHERE RequestID = ?",
        [$newRequestStatus, $traceNo, $affectedRequestId]
    );

    if ($headerUpdate === false) {
        $failed[] = [
            'item' => ['request_id' => $affectedRequestId],
            'reason' => 'Unable to update request header status: ' . sqlsrv_fail_message()
        ];
    }
}

$pageTitle = 'Issuance Saved';
$backUrl = 'pages/issuer/issuer.php';

if (issuer_wants_json_response()) {
    $ok = count($saved) > 0 && count($failed) === 0;
    if (!$ok) {
        http_response_code(count($saved) > 0 ? 207 : 400);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $ok
            ? 'Issuance saved. Trace ' . $traceNo . ' generated.'
            : 'Issuance saved with errors.',
        'trace_no' => $traceNo,
        'trace_id' => $traceId,
        'saved_count' => count($saved),
        'failed_count' => count($failed),
        'saved' => $saved,
        'failed' => $failed
    ]);
    exit;
}

include __DIR__ . '/../pages/results/save_issue_result.php';
?>
