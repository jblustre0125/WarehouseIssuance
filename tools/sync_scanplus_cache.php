<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/scanplus_lookup.php';

$lookbackDays = max(1, min(180, (int)($argv[1] ?? 45)));
$chunkSize = max(20, min(250, (int)($argv[2] ?? 100)));
$maxRefs = max(100, min(20000, (int)($argv[3] ?? 5000)));

$logDir = $projectRoot . '/storage/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    throw new RuntimeException('Unable to create log directory: ' . $logDir);
}

$lockPath = $logDir . '/scanplus_cache_sync.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    throw new RuntimeException('Unable to open lock file: ' . $lockPath);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " Another ScanPlus cache sync is already running.\n";
    exit(0);
}

function sync_log(string $message): void
{
    echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
}

function sync_fetch_all($conn, string $sql, array $params = []): array
{
    $rows = fetch_all($conn, $sql, $params);
    return is_array($rows) ? $rows : [];
}

function sync_exec($conn, string $sql, array $params = []): void
{
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        if (!$stmt->execute($params)) {
            $error = $stmt->errorInfo();
            throw new RuntimeException((string)($error[2] ?? 'PDO execution failed.'));
        }
        return;
    }

    if (function_exists('sqlsrv_query')) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors(SQLSRV_ERR_ALL);
            throw new RuntimeException(json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        sqlsrv_free_stmt($stmt);
        return;
    }

    /* Project fallback: fetch_all executes parameterized non-SELECT statements too. */
    fetch_all($conn, $sql, $params);
}

function sync_has_table($conn, string $table): bool
{
    return !empty(sync_fetch_all(
        $conn,
        "SELECT TOP 1 1 AS Found FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ?",
        [$table]
    ));
}

function sync_has_column($conn, string $table, string $column): bool
{
    return !empty(sync_fetch_all(
        $conn,
        "SELECT TOP 1 1 AS Found FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    ));
}

function sync_normalize_lot($value): string
{
    $lot = strtoupper(trim((string)$value));
    $lot = preg_replace('/[^A-Z0-9]/', '', $lot) ?? '';
    if ($lot !== '' && ctype_digit($lot)) {
        $lot = ltrim($lot, '0');
        return $lot === '' ? '0' : $lot;
    }
    return $lot;
}

function sync_ref_key(array $ref): string
{
    $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

    if ($requestLineId > 0) {
        return 'request-line|' . $requestLineId . '|'
            . (int)$ref['doc_entry'] . '|'
            . (($ref['line_num'] === null || $ref['line_num'] === '') ? '-1' : (int)$ref['line_num']) . '|'
            . strtoupper(trim((string)$ref['item_code'])) . '|'
            . sync_normalize_lot($ref['lot_no'] ?? '');
    }

    return (int)$ref['doc_entry'] . '|'
        . (($ref['line_num'] === null || $ref['line_num'] === '') ? '-1' : (int)$ref['line_num']) . '|'
        . strtoupper(trim((string)$ref['item_code'])) . '|'
        . sync_normalize_lot($ref['lot_no'] ?? '');
}

function sync_add_refs(array &$refs, array &$seen, array $rows): void
{
    foreach ($rows as $row) {
        $docEntry = (int)($row['SAP_IT_DocEntry'] ?? $row['ITRDocEntry'] ?? 0);
        $lineRaw = $row['SAP_IT_LineNum'] ?? $row['ITRLineNum'] ?? null;
        $lineNum = ($lineRaw === null || trim((string)$lineRaw) === '') ? null : (int)$lineRaw;
        $itemCode = trim((string)($row['ItemCode'] ?? ''));
        $lotNo = trim((string)($row['LotNo'] ?? ''));

        if ($docEntry <= 0 || $itemCode === '' || scanplus_key($docEntry, $lineNum, $itemCode) === '') {
            continue;
        }

        $ref = [
            'doc_entry' => $docEntry,
            'line_num' => $lineNum,
            'item_code' => $itemCode,
            'lot_no' => $lotNo,
        ];

        /*
         * Carry the local "ground truth" issuance fields when the source query
         * provided them (WarehouseIssueRequestLines), so the sync can verify
         * the SAP-derived cache data against what was actually issued locally
         * instead of trusting the ERP query result blindly.
         */
        if (array_key_exists('IssuedQty', $row)) {
            $ref['local_issued_qty'] = is_numeric($row['IssuedQty']) ? (float)$row['IssuedQty'] : null;
        }
        if (array_key_exists('RequestLineID', $row)) {
            $ref['local_request_line_id'] = (int)($row['RequestLineID'] ?? 0);
        } elseif (array_key_exists('IssueRequestLineID', $row)) {
            $ref['local_request_line_id'] = (int)($row['IssueRequestLineID'] ?? 0);
        }
        if (array_key_exists('RequestNo', $row)) {
            $ref['local_request_no'] = trim((string)($row['RequestNo'] ?? ''));
        }
        if (array_key_exists('RequestedAt', $row)) {
            $ref['local_requested_at'] = $row['RequestedAt'];
        }
        if (array_key_exists('WarehouseLotNo', $row)) {
            $ref['local_warehouse_lot_no'] = trim((string)($row['WarehouseLotNo'] ?? ''));
        }
        if (array_key_exists('Status', $row)) {
            $ref['local_status'] = trim((string)($row['Status'] ?? ''));
        }

        $key = sync_ref_key($ref);

        if (isset($seen[$key])) {
            $existingIndex = $seen[$key];
            foreach (['local_issued_qty', 'local_request_line_id', 'local_request_no', 'local_requested_at', 'local_warehouse_lot_no', 'local_status'] as $field) {
                if (!array_key_exists($field, $refs[$existingIndex]) && array_key_exists($field, $ref)) {
                    $refs[$existingIndex][$field] = $ref[$field];
                }
            }
            continue;
        }

        $seen[$key] = count($refs);
        $refs[] = $ref;
    }
}

function sync_begin_log($conn): ?int
{
    if (!sync_has_table($conn, 'SapCacheSyncLog')) {
        return null;
    }

    $row = sync_fetch_all(
        $conn,
        "INSERT INTO dbo.SapCacheSyncLog (ScopeName, StartedAt, Status, Message, RowCount)
         OUTPUT INSERTED.SyncID
         VALUES ('RAWMAT_SCANPLUS', GETDATE(), 'RUNNING', 'Scheduled cache refresh started.', 0)"
    );
    return isset($row[0]['SyncID']) ? (int)$row[0]['SyncID'] : null;
}

function sync_finish_log($conn, ?int $syncId, string $status, string $message, int $rowCount): void
{
    if ($syncId === null) {
        return;
    }
    sync_exec(
        $conn,
        'UPDATE dbo.SapCacheSyncLog SET FinishedAt = GETDATE(), Status = ?, Message = ?, RowCount = ? WHERE SyncID = ?',
        [$status, mb_substr($message, 0, 1000), $rowCount, $syncId]
    );
}

function sync_ensure_request_line_receive_cache($conn): bool
{
    sync_exec(
        $conn,
        "IF OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveCache', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.WarehouseIssueRequestLineReceiveCache (
                RequestLineID INT NOT NULL PRIMARY KEY,
                RequestNo NVARCHAR(80) NULL,
                SAP_IT_DocEntry INT NOT NULL,
                SAP_IT_LineNum INT NULL,
                ItemCode NVARCHAR(50) NOT NULL,
                LotNo NVARCHAR(80) NULL,
                WarehouseLotNo NVARCHAR(80) NULL,
                ReceivedLotNo NVARCHAR(80) NULL,
                ScanStatus NVARCHAR(50) NULL,
                MatchStatus NVARCHAR(50) NOT NULL DEFAULT 'NOT_CONFIRMED',
                IsCurrentMatch BIT NOT NULL DEFAULT 0,
                RawReceivedQty DECIMAL(18,3) NULL,
                ReceivedQty DECIMAL(18,3) NULL,
                BarcodeUser NVARCHAR(120) NULL,
                ReceivedAt DATETIME NULL,
                LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
            );
         END"
    );

    foreach ([
        'RequestNo NVARCHAR(80) NULL',
        'WarehouseLotNo NVARCHAR(80) NULL',
        'ReceivedLotNo NVARCHAR(80) NULL',
        'ScanStatus NVARCHAR(50) NULL',
        "MatchStatus NVARCHAR(50) NOT NULL CONSTRAINT DF_WIRLC_MatchStatus DEFAULT 'NOT_CONFIRMED'",
        'IsCurrentMatch BIT NOT NULL CONSTRAINT DF_WIRLC_IsCurrentMatch DEFAULT 0',
        'RawReceivedQty DECIMAL(18,3) NULL',
        'ReceivedQty DECIMAL(18,3) NULL',
        'BarcodeUser NVARCHAR(120) NULL',
        'ReceivedAt DATETIME NULL',
        'LastSyncedAt DATETIME NOT NULL CONSTRAINT DF_WIRLC_LastSyncedAt DEFAULT GETDATE()',
    ] as $definition) {
        $column = trim(strtok($definition, ' '));

        if ($column !== '' && !sync_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', $column)) {
            sync_exec($conn, "ALTER TABLE dbo.WarehouseIssueRequestLineReceiveCache ADD {$definition}");
        }
    }

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'IX_WIRLC_SapLookup'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveCache')
         )
         BEGIN
            CREATE INDEX IX_WIRLC_SapLookup
            ON dbo.WarehouseIssueRequestLineReceiveCache(SAP_IT_DocEntry, SAP_IT_LineNum, ItemCode, LotNo, WarehouseLotNo);
         END"
    );

    return sync_has_table($conn, 'WarehouseIssueRequestLineReceiveCache');
}

function sync_date_key($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $text = trim((string)$value);

    if ($text === '') {
        return '';
    }

    $timestamp = strtotime($text);

    return $timestamp === false ? '' : date('Y-m-d', $timestamp);
}

function sync_datetime_sort_key($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return trim((string)$value);
}

function sync_ref_allocation_sort_key(array $ref): string
{
    return (int)$ref['doc_entry'] . '|'
        . (($ref['line_num'] === null || $ref['line_num'] === '') ? '-1' : (int)$ref['line_num']) . '|'
        . strtoupper(trim((string)$ref['item_code'])) . '|'
        . sync_normalize_lot(($ref['local_warehouse_lot_no'] ?? '') !== '' ? $ref['local_warehouse_lot_no'] : ($ref['lot_no'] ?? ''));
}

function sync_allocate_request_line_scan(array $ref, ?array $scan, string $allocationKey, array &$remainingByScanKey): ?array
{
    if ((int)($ref['local_request_line_id'] ?? 0) <= 0 || !is_array($scan)) {
        return $scan;
    }

    $rawQty = is_numeric($scan['received_qty'] ?? null)
        ? (float)$scan['received_qty']
        : 0.0;

    if ($rawQty <= 0 || $allocationKey === '') {
        return $scan;
    }

    if (!array_key_exists($allocationKey, $remainingByScanKey)) {
        $remainingByScanKey[$allocationKey] = $rawQty;
    }

    $requestedQty = is_numeric($ref['local_issued_qty'] ?? null)
        ? max(0.0, (float)$ref['local_issued_qty'])
        : $rawQty;
    $allocatedQty = min($requestedQty > 0 ? $requestedQty : $rawQty, max(0.0, $remainingByScanKey[$allocationKey]));
    $remainingByScanKey[$allocationKey] = max(0.0, $remainingByScanKey[$allocationKey] - $allocatedQty);

    $lineScan = $scan;
    $lineScan['received_qty'] = $allocatedQty;

    if ($allocatedQty <= 0) {
        $lineScan['scan_status'] = 'NOT_ALLOCATED_TO_REQUEST_LINE';
    }

    return $lineScan;
}

function sync_upsert_request_line_receive_cache($conn, array $ref, ?array $scan): void
{
    $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

    if ($requestLineId <= 0 || !sync_has_table($conn, 'WarehouseIssueRequestLineReceiveCache')) {
        return;
    }

    $rawReceivedQty = is_array($scan) && is_numeric($scan['received_qty'] ?? null)
        ? (float)$scan['received_qty']
        : null;
    $receivedAt = is_array($scan) ? ($scan['received_at'] ?? null) : null;
    $requestedDate = sync_date_key($ref['local_requested_at'] ?? '');
    $receivedDate = sync_date_key($receivedAt);
    $isOldReceive = $rawReceivedQty !== null
        && $rawReceivedQty > 0
        && $requestedDate !== ''
        && $receivedDate !== ''
        && $receivedDate < $requestedDate;
    $isCurrentMatch = $rawReceivedQty !== null && $rawReceivedQty > 0 && !$isOldReceive;

    $matchStatus = 'NOT_CONFIRMED';

    if ($isOldReceive) {
        $matchStatus = 'OLD_CACHE_RECEIVE';
    } elseif ($isCurrentMatch) {
        $matchStatus = (string)($scan['scan_status'] ?? 'RECEIVED');
    } elseif (is_array($scan)) {
        $matchStatus = (string)($scan['scan_status'] ?? 'NOT_RECEIVED_IN_SAP_CACHE');
    }

    $receivedQty = $isCurrentMatch ? $rawReceivedQty : null;
    $receivedLotNo = is_array($scan) ? ($scan['received_lot_no'] ?? $scan['lot_no'] ?? null) : null;
    $barcodeUser = is_array($scan) ? ($scan['barcode_user'] ?? null) : null;

    sync_exec(
        $conn,
        "MERGE dbo.WarehouseIssueRequestLineReceiveCache WITH (HOLDLOCK) AS T
         USING
         (
            SELECT
                ? AS RequestLineID,
                ? AS RequestNo,
                ? AS SAP_IT_DocEntry,
                ? AS SAP_IT_LineNum,
                ? AS ItemCode,
                ? AS LotNo,
                ? AS WarehouseLotNo
         ) AS S
         ON T.RequestLineID = S.RequestLineID
         WHEN MATCHED THEN UPDATE SET
            RequestNo = S.RequestNo,
            SAP_IT_DocEntry = S.SAP_IT_DocEntry,
            SAP_IT_LineNum = S.SAP_IT_LineNum,
            ItemCode = S.ItemCode,
            LotNo = S.LotNo,
            WarehouseLotNo = S.WarehouseLotNo,
            ReceivedLotNo = ?,
            ScanStatus = ?,
            MatchStatus = ?,
            IsCurrentMatch = ?,
            RawReceivedQty = ?,
            ReceivedQty = ?,
            BarcodeUser = ?,
            ReceivedAt = ?,
            LastSyncedAt = GETDATE()
         WHEN NOT MATCHED THEN INSERT
         (
            RequestLineID,
            RequestNo,
            SAP_IT_DocEntry,
            SAP_IT_LineNum,
            ItemCode,
            LotNo,
            WarehouseLotNo,
            ReceivedLotNo,
            ScanStatus,
            MatchStatus,
            IsCurrentMatch,
            RawReceivedQty,
            ReceivedQty,
            BarcodeUser,
            ReceivedAt,
            LastSyncedAt
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE());",
        [
            $requestLineId,
            $ref['local_request_no'] ?? null,
            $ref['doc_entry'],
            $ref['line_num'],
            $ref['item_code'],
            $ref['lot_no'] ?? null,
            $ref['local_warehouse_lot_no'] ?? null,
            $receivedLotNo,
            is_array($scan) ? ($scan['scan_status'] ?? null) : null,
            $matchStatus,
            $isCurrentMatch ? 1 : 0,
            $rawReceivedQty,
            $receivedQty,
            $barcodeUser,
            $receivedAt,
            $requestLineId,
            $ref['local_request_no'] ?? null,
            $ref['doc_entry'],
            $ref['line_num'],
            $ref['item_code'],
            $ref['lot_no'] ?? null,
            $ref['local_warehouse_lot_no'] ?? null,
            $receivedLotNo,
            is_array($scan) ? ($scan['scan_status'] ?? null) : null,
            $matchStatus,
            $isCurrentMatch ? 1 : 0,
            $rawReceivedQty,
            $receivedQty,
            $barcodeUser,
            $receivedAt,
        ]
    );
}

function sync_upsert_cache($conn, array $ref, ?array $scan): void
{
    $hasScan = $scan !== null;
    $scanStatus = $scan['scan_status'] ?? ($scan ? 'SAP PARTIAL' : 'NOT RECEIVED IN SAP');
    $receivedQty = $scan['received_qty'] ?? null;
    $barcodeUser = $scan['barcode_user'] ?? null;
    $receivedAt = $scan['received_at'] ?? null;
    $receivedLotNo = $scan['received_lot_no'] ?? $scan['lot_no'] ?? null;

    /*
     * Only overwrite an existing cache row when this pass actually found scan
     * data, or when the existing row never had real received data to begin
     * with. Otherwise a ref that momentarily fails to match in the ERP query
     * (chunking, timing, format mismatch, etc.) would blank out previously
     * confirmed "SAP_RECEIVED"/"CLOSED" data on every later sync run.
     */
    sync_exec(
        $conn,
        "MERGE dbo.RawmatTraceScanPlusCache WITH (HOLDLOCK) AS T
         USING
         (
            SELECT
                ? AS SAP_IT_DocEntry,
                ? AS SAP_IT_LineNum,
                ? AS ItemCode,
                ? AS LotNo
         ) AS S
         ON T.SAP_IT_DocEntry = S.SAP_IT_DocEntry
            AND ISNULL(T.SAP_IT_LineNum, -1) = ISNULL(S.SAP_IT_LineNum, -1)
            AND T.ItemCode = S.ItemCode
            AND UPPER(LTRIM(RTRIM(ISNULL(T.LotNo, N'')))) = UPPER(LTRIM(RTRIM(ISNULL(S.LotNo, N''))))
         WHEN MATCHED AND (? = 1 OR ISNULL(T.ReceivedQty, 0) <= 0) THEN UPDATE SET
            ReceivedLotNo = ?,
            ScanStatus = ?,
            ReceivedQty = ?,
            BarcodeUser = ?,
            ReceivedAt = ?,
            LastSyncedAt = GETDATE()
         WHEN NOT MATCHED THEN INSERT
         (
            SAP_IT_DocEntry,
            SAP_IT_LineNum,
            ItemCode,
            LotNo,
            ReceivedLotNo,
            ScanStatus,
            ReceivedQty,
            BarcodeUser,
            ReceivedAt,
            LastSyncedAt
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE());",
        [
            $ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no'],
            $hasScan ? 1 : 0,
            $receivedLotNo, $scanStatus, $receivedQty, $barcodeUser, $receivedAt,
            $ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no'],
            $receivedLotNo, $scanStatus, $receivedQty, $barcodeUser, $receivedAt,
        ]
    );
}

function sync_scan_is_received(?array $scan): bool
{
    if (!is_array($scan)) {
        return false;
    }

    if ((float)($scan['received_qty'] ?? 0) > 0) {
        return true;
    }

    $status = strtoupper(trim((string)($scan['scan_status'] ?? '')));

    return in_array($status, ['SAP_RECEIVED', 'SAP PARTIAL', 'RECEIVED', 'CLOSED', 'COMPLETED', 'MATCHED'], true);
}

$whp = null;
$syncId = null;
$updated = 0;
$matched = 0;
$missing = 0;
$verifyChecked = 0;
$verifyMissingInSap = 0;
$verifyQtyMismatch = 0;
$verifyLotMismatch = 0;
$verifyIssues = [];

try {
    if (!function_exists('scanplus_lookup_by_itr_lines')) {
        throw new RuntimeException('scanplus_lookup_by_itr_lines() is not available in includes/scanplus_lookup.php.');
    }

    $whp = get_whpokayoke_connection();
    if (!$whp) {
        throw new RuntimeException('Unable to connect to the WH PokaYoke database.');
    }
    if (!sync_has_table($whp, 'RawmatTraceScanPlusCache')) {
        throw new RuntimeException('dbo.RawmatTraceScanPlusCache does not exist. Run scanplus_cache_fix.sql first.');
    }
    sync_ensure_request_line_receive_cache($whp);

    $syncId = sync_begin_log($whp);
    sync_log("Starting ScanPlus cache refresh. Lookback={$lookbackDays} days, chunk={$chunkSize}, maxRefs={$maxRefs}.");

    $refs = [];
    $seen = [];

    if (sync_has_table($whp, 'IssuanceTransactions')
        && sync_has_column($whp, 'IssuanceTransactions', 'ITRDocEntry')
        && sync_has_column($whp, 'IssuanceTransactions', 'ItemCode')) {
        $dateColumn = sync_has_column($whp, 'IssuanceTransactions', 'IssuedAt') ? 'IssuedAt' : null;
        $lineExpr = sync_has_column($whp, 'IssuanceTransactions', 'ITRLineNum') ? 'IT.ITRLineNum' : 'NULL';
        $lotExpr = sync_has_column($whp, 'IssuanceTransactions', 'LotNo') ? 'IT.LotNo' : "N''";
        $warehouseLotExpr = sync_has_column($whp, 'IssuanceTransactions', 'WarehouseLotNo')
            ? 'IT.WarehouseLotNo'
            : 'CAST(NULL AS NVARCHAR(100))';
        $txHasRequestLineId = sync_has_column($whp, 'IssuanceTransactions', 'IssueRequestLineID')
            && sync_has_table($whp, 'WarehouseIssueRequestLines')
            && sync_has_table($whp, 'WarehouseIssueRequestHeader');
        $requestLineSelect = $txHasRequestLineId
            ? 'IT.IssueRequestLineID AS IssueRequestLineID, H.RequestNo, H.RequestedAt'
            : 'CAST(NULL AS INT) AS IssueRequestLineID, CAST(NULL AS NVARCHAR(80)) AS RequestNo, CAST(NULL AS DATETIME) AS RequestedAt';
        $requestLineJoin = $txHasRequestLineId
            ? 'LEFT JOIN dbo.WarehouseIssueRequestLines RL ON RL.RequestLineID = IT.IssueRequestLineID
               LEFT JOIN dbo.WarehouseIssueRequestHeader H ON H.RequestID = RL.RequestID'
            : '';
        $whereDate = $dateColumn ? "AND {$dateColumn} >= DATEADD(DAY, -?, GETDATE())" : '';
        $params = $dateColumn ? [$lookbackDays] : [];
        sync_add_refs($refs, $seen, sync_fetch_all(
            $whp,
            "SELECT TOP {$maxRefs}
                IT.ITRDocEntry AS SAP_IT_DocEntry,
                {$lineExpr} AS SAP_IT_LineNum,
                IT.ItemCode,
                {$lotExpr} AS LotNo,
                {$warehouseLotExpr} AS WarehouseLotNo,
                {$requestLineSelect}
             FROM dbo.IssuanceTransactions IT
             {$requestLineJoin}
             WHERE ISNULL(IT.ITRDocEntry, 0) > 0
               AND NULLIF(LTRIM(RTRIM(IT.ItemCode)), '') IS NOT NULL
               {$whereDate}
             ORDER BY IT." . ($dateColumn ?: 'ITRDocEntry') . ' DESC',
            $params
        ));
    }

    if (count($refs) < $maxRefs
        && sync_has_table($whp, 'WarehouseIssueRequestHeader')
        && sync_has_table($whp, 'WarehouseIssueRequestLines')) {
        $remaining = $maxRefs - count($refs);

        /*
         * This is the local "ground truth" table for verification, so it is
         * intentionally NOT restricted to the recent lookback window and NOT
         * filtered by Status — every line (issued or still open) is eligible,
         * capped only by $maxRefs for volume control. IssuedQty/WarehouseLotNo/
         * Status are pulled along so the sync can cross-check the SAP cache
         * result against what was actually issued locally.
         */
        $reqLineIssuedQtyExpr = sync_has_column($whp, 'WarehouseIssueRequestLines', 'IssuedQty')
            ? 'L.IssuedQty'
            : 'CAST(NULL AS DECIMAL(18,3))';
        $reqLineWarehouseLotExpr = sync_has_column($whp, 'WarehouseIssueRequestLines', 'WarehouseLotNo')
            ? 'L.WarehouseLotNo'
            : 'CAST(NULL AS NVARCHAR(100))';
        $reqLineStatusExpr = sync_has_column($whp, 'WarehouseIssueRequestLines', 'Status')
            ? 'L.Status'
            : 'CAST(NULL AS NVARCHAR(50))';

        sync_add_refs($refs, $seen, sync_fetch_all(
            $whp,
            "SELECT TOP {$remaining}
                L.RequestLineID,
                H.RequestNo,
                H.RequestedAt,
                COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry) AS SAP_IT_DocEntry,
                L.SAP_IT_LineNum,
                L.ItemCode,
                L.LotNo,
                {$reqLineIssuedQtyExpr} AS IssuedQty,
                {$reqLineWarehouseLotExpr} AS WarehouseLotNo,
                {$reqLineStatusExpr} AS Status
             FROM dbo.WarehouseIssueRequestHeader H
             INNER JOIN dbo.WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
             WHERE ISNULL(COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry), 0) > 0
               AND NULLIF(LTRIM(RTRIM(L.ItemCode)), '') IS NOT NULL
             ORDER BY H.RequestedAt DESC, L.RequestLineID DESC",
            []
        ));
    }

    if (count($refs) < $maxRefs
        && sync_has_table($whp, 'RawmatTraceHeader')
        && sync_has_table($whp, 'RawmatTraceLines')) {
        $remaining = $maxRefs - count($refs);
        $traceRequestLineExpr = sync_has_column($whp, 'RawmatTraceLines', 'IssueRequestLineID')
            ? 'L.IssueRequestLineID'
            : 'CAST(NULL AS INT)';
        sync_add_refs($refs, $seen, sync_fetch_all(
            $whp,
            "SELECT TOP {$remaining}
                {$traceRequestLineExpr} AS IssueRequestLineID,
                COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry) AS SAP_IT_DocEntry,
                L.SAP_IT_LineNum,
                L.ItemCode,
                L.LotNo
             FROM dbo.RawmatTraceHeader H
             INNER JOIN dbo.RawmatTraceLines L ON L.TraceID = H.TraceID
             WHERE H.CreatedAt >= DATEADD(DAY, -?, GETDATE())
               AND ISNULL(COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry), 0) > 0
               AND NULLIF(LTRIM(RTRIM(L.ItemCode)), '') IS NOT NULL
             ORDER BY H.CreatedAt DESC, L.TraceLineID DESC",
            [$lookbackDays]
        ));
    }

    if (empty($refs)) {
        throw new RuntimeException('No local ITR/item references were found for the selected lookback period.');
    }

    usort($refs, static function (array $a, array $b): int {
        $keyCompare = strcmp(sync_ref_allocation_sort_key($a), sync_ref_allocation_sort_key($b));

        if ($keyCompare !== 0) {
            return $keyCompare;
        }

        return strcmp(sync_datetime_sort_key($a['local_requested_at'] ?? ''), sync_datetime_sort_key($b['local_requested_at'] ?? ''));
    });

    sync_log('Unique local references found: ' . count($refs));
    $erp = get_erp_connection();
    if (!$erp) {
        throw new RuntimeException('Unable to connect to the SAP/ERP database from the scheduled task account.');
    }

    $remainingByScanKey = [];

    foreach (array_chunk($refs, $chunkSize) as $chunkIndex => $chunk) {
        $started = microtime(true);
        $scanRows = scanplus_lookup_by_itr_lines($erp, $chunk);
        if (!is_array($scanRows)) {
            $scanRows = [];
        }

        foreach ($chunk as $ref) {
            $baseKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
            $lotCandidates = [];
            $selectedScanKey = '';

            foreach ([$ref['lot_no'] ?? '', $ref['local_warehouse_lot_no'] ?? ''] as $candidateLot) {
                $candidateLot = trim((string)$candidateLot);

                if ($candidateLot === '') {
                    continue;
                }

                $normalizedCandidate = sync_normalize_lot($candidateLot);
                $lotCandidates[$normalizedCandidate !== '' ? $normalizedCandidate : $candidateLot] = $candidateLot;
            }

            /* Never use an all-lot aggregate when the local row has a specific lot. */
            if (!empty($lotCandidates)) {
                $scan = null;

                foreach ($lotCandidates as $candidateLot) {
                    $lotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $candidateLot);

                    if ($lotKey !== '' && isset($scanRows[$lotKey])) {
                        $scan = $scanRows[$lotKey];
                        $selectedScanKey = $lotKey;
                        break;
                    }
                }
            } else {
                $scan = $scanRows[$baseKey] ?? null;
                $selectedScanKey = $baseKey;
            }

            sync_upsert_cache($whp, $ref, is_array($scan) ? $scan : null);
            $lineScan = sync_allocate_request_line_scan(
                $ref,
                is_array($scan) ? $scan : null,
                $selectedScanKey,
                $remainingByScanKey
            );
            sync_upsert_request_line_receive_cache($whp, $ref, $lineScan);
            $updated++;
            if (is_array($scan)) {
                $matched++;
            } else {
                $missing++;
            }

            /*
             * Verify against the local "ground truth": if this ref actually has
             * an IssuedQty from WarehouseIssueRequestLines, cross-check the SAP
             * cache result instead of trusting it blindly.
             */
            $localIssuedQty = $ref['local_issued_qty'] ?? null;

            if ($localIssuedQty !== null && $localIssuedQty > 0) {
                $verifyChecked++;

                if (!sync_scan_is_received($scan)) {
                    $verifyMissingInSap++;
                    if (count($verifyIssues) < 200) {
                        $verifyIssues[] = sprintf(
                            'MISSING_IN_SAP doc=%d line=%s item=%s localIssuedQty=%.3f',
                            $ref['doc_entry'],
                            $ref['line_num'] ?? '-',
                            $ref['item_code'],
                            $localIssuedQty
                        );
                    }
                } else {
                    $scanQty = (float)($scan['received_qty'] ?? 0);
                    if (abs($scanQty - $localIssuedQty) > 0.001) {
                        $verifyQtyMismatch++;
                        if (count($verifyIssues) < 200) {
                            $verifyIssues[] = sprintf(
                                'QTY_MISMATCH doc=%d line=%s item=%s localIssuedQty=%.3f sapReceivedQty=%.3f',
                                $ref['doc_entry'],
                                $ref['line_num'] ?? '-',
                                $ref['item_code'],
                                $localIssuedQty,
                                $scanQty
                            );
                        }
                    }

                    $localLot = trim((string)($ref['local_warehouse_lot_no'] ?? '')) !== ''
                        ? $ref['local_warehouse_lot_no']
                        : ($ref['lot_no'] ?? '');
                    $sapLot = (string)($scan['received_lot_no'] ?? '');

                    if (trim((string)$localLot) !== ''
                        && trim($sapLot) !== ''
                        && sync_normalize_lot($localLot) !== sync_normalize_lot($sapLot)
                    ) {
                        $verifyLotMismatch++;
                        if (count($verifyIssues) < 200) {
                            $verifyIssues[] = sprintf(
                                'LOT_MISMATCH doc=%d line=%s item=%s localLot=%s sapLot=%s',
                                $ref['doc_entry'],
                                $ref['line_num'] ?? '-',
                                $ref['item_code'],
                                $localLot,
                                $sapLot
                            );
                        }
                    }
                }
            }
        }

        sync_log('Chunk ' . ($chunkIndex + 1) . ': rows=' . count($chunk)
            . ', lookup=' . round(microtime(true) - $started, 3) . ' sec');
    }

    sync_log("Verification against issued lines: checked={$verifyChecked}, missing_in_sap={$verifyMissingInSap}, "
        . "qty_mismatch={$verifyQtyMismatch}, lot_mismatch={$verifyLotMismatch}.");

    foreach ($verifyIssues as $verifyIssueLine) {
        sync_log('  ' . $verifyIssueLine);
    }

    $message = "Completed. Updated={$updated}, matched={$matched}, not_received_or_unmatched={$missing}. "
        . "Verified={$verifyChecked}, missing_in_sap={$verifyMissingInSap}, qty_mismatch={$verifyQtyMismatch}, "
        . "lot_mismatch={$verifyLotMismatch}.";
    sync_finish_log($whp, $syncId, 'SUCCESS', $message, $updated);
    sync_log($message);
    exit(0);
} catch (Throwable $e) {
    $message = $e->getMessage();
    if ($whp) {
        try {
            sync_finish_log($whp, $syncId, 'FAILED', $message, $updated);
        } catch (Throwable $ignored) {
        }
    }
    fwrite(STDERR, date('Y-m-d H:i:s') . ' FAILED: ' . $message . PHP_EOL);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
