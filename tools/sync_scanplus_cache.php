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
        /* RequestLineID is the local ground-truth identity. */
        return 'request-line|' . $requestLineId;
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
        if (array_key_exists('LatestIssuedAt', $row)) {
            $ref['local_issued_at'] = $row['LatestIssuedAt'];
        } elseif (array_key_exists('IssuedAt', $row)) {
            $ref['local_issued_at'] = $row['IssuedAt'];
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
            $isCanonicalRequestLineRow = array_key_exists('RequestLineID', $row);

            /*
             * The WarehouseIssueRequestLines row is authoritative for the local
             * request identity and GRPO/WH lot. Transaction/trace rows only fill
             * gaps such as the earliest IssuedAt timestamp.
             */
            if ($isCanonicalRequestLineRow) {
                $refs[$existingIndex]['doc_entry'] = $docEntry;
                $refs[$existingIndex]['line_num'] = $lineNum;
                $refs[$existingIndex]['item_code'] = $itemCode;

                if ($lotNo !== '') {
                    $refs[$existingIndex]['lot_no'] = $lotNo;
                }
            }

            foreach (['local_issued_qty', 'local_request_line_id', 'local_request_no', 'local_requested_at', 'local_issued_at', 'local_warehouse_lot_no', 'local_status'] as $field) {
                if (!array_key_exists($field, $ref)) {
                    continue;
                }

                $incomingValue = $ref[$field];
                $incomingIsBlank = $incomingValue === null
                    || (is_string($incomingValue) && trim($incomingValue) === '');

                if ($isCanonicalRequestLineRow
                    && $incomingIsBlank
                    && in_array($field, ['local_issued_at', 'local_warehouse_lot_no'], true)
                    && array_key_exists($field, $refs[$existingIndex])) {
                    continue;
                }

                if ($isCanonicalRequestLineRow || !array_key_exists($field, $refs[$existingIndex])) {
                    $refs[$existingIndex][$field] = $incomingValue;
                }
            }
            continue;
        }

        $seen[$key] = count($refs);
        $refs[] = $ref;
    }
}

/**
 * Rebuild one authoritative reference per local RequestLineID.
 *
 * IssuanceTransactions may contain many rows for one request line. Building
 * refs from those rows first can leave duplicate or incomplete in-memory
 * references. This pass reloads the canonical request-line fields and the
 * latest actual issuance time directly from WHPOKAYOKE, then collapses every
 * local request line to exactly one reference before allocation sorting.
 */
function sync_canonicalize_request_line_refs($conn, array $refs): array
{
    if (empty($refs)
        || !sync_has_table($conn, 'WarehouseIssueRequestHeader')
        || !sync_has_table($conn, 'WarehouseIssueRequestLines')) {
        return $refs;
    }

    $requestLineIds = [];

    foreach ($refs as $ref) {
        $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

        if ($requestLineId > 0) {
            $requestLineIds[$requestLineId] = true;
        }
    }

    if (empty($requestLineIds)) {
        return $refs;
    }

    $issuedQtyExpr = sync_has_column($conn, 'WarehouseIssueRequestLines', 'IssuedQty')
        ? 'L.IssuedQty'
        : 'CAST(NULL AS DECIMAL(18,3))';
    $warehouseLotExpr = sync_has_column($conn, 'WarehouseIssueRequestLines', 'WarehouseLotNo')
        ? 'L.WarehouseLotNo'
        : 'CAST(NULL AS NVARCHAR(100))';
    $statusExpr = sync_has_column($conn, 'WarehouseIssueRequestLines', 'Status')
        ? 'L.Status'
        : 'CAST(NULL AS NVARCHAR(50))';

    $issueApply = '';
    $latestIssuedAtExpr = 'CAST(NULL AS DATETIME)';

    if (sync_has_table($conn, 'IssuanceTransactions')
        && sync_has_column($conn, 'IssuanceTransactions', 'IssueRequestLineID')
        && sync_has_column($conn, 'IssuanceTransactions', 'IssuedAt')) {
        $issueItemCondition = sync_has_column($conn, 'IssuanceTransactions', 'ItemCode')
            ? 'AND (ITX.ItemCode = L.ItemCode OR ITX.ItemCode IS NULL)'
            : '';
        $issueQtyCondition = sync_has_column($conn, 'IssuanceTransactions', 'Quantity')
            ? 'AND ISNULL(TRY_CONVERT(DECIMAL(18,3), ITX.Quantity), 0) > 0'
            : '';

        $issueApply = "
            OUTER APPLY
            (
                SELECT MAX(ITX.IssuedAt) AS LatestIssuedAt
                FROM dbo.IssuanceTransactions ITX
                WHERE ITX.IssueRequestLineID = L.RequestLineID
                  {$issueItemCondition}
                  {$issueQtyCondition}
            ) IX";
        $latestIssuedAtExpr = 'IX.LatestIssuedAt';
    }

    $metadataById = [];
    $ids = array_keys($requestLineIds);

    foreach (array_chunk($ids, 500) as $idChunk) {
        $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
        $rows = sync_fetch_all(
            $conn,
            "SELECT
                L.RequestLineID,
                H.RequestNo,
                H.RequestedAt,
                COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry) AS SAP_IT_DocEntry,
                L.SAP_IT_LineNum,
                L.ItemCode,
                L.LotNo,
                {$issuedQtyExpr} AS IssuedQty,
                {$warehouseLotExpr} AS WarehouseLotNo,
                {$statusExpr} AS Status,
                {$latestIssuedAtExpr} AS LatestIssuedAt
             FROM dbo.WarehouseIssueRequestLines L
             INNER JOIN dbo.WarehouseIssueRequestHeader H
                ON H.RequestID = L.RequestID
             {$issueApply}
             WHERE L.RequestLineID IN ({$placeholders})",
            array_values($idChunk)
        );

        foreach ($rows as $row) {
            $requestLineId = (int)($row['RequestLineID'] ?? 0);

            if ($requestLineId <= 0) {
                continue;
            }

            $metadataById[$requestLineId] = [
                'doc_entry' => (int)($row['SAP_IT_DocEntry'] ?? 0),
                'line_num' => ($row['SAP_IT_LineNum'] === null || trim((string)$row['SAP_IT_LineNum']) === '')
                    ? null
                    : (int)$row['SAP_IT_LineNum'],
                'item_code' => trim((string)($row['ItemCode'] ?? '')),
                'lot_no' => trim((string)($row['LotNo'] ?? '')),
                'local_issued_qty' => is_numeric($row['IssuedQty'] ?? null)
                    ? (float)$row['IssuedQty']
                    : null,
                'local_request_line_id' => $requestLineId,
                'local_request_no' => trim((string)($row['RequestNo'] ?? '')),
                'local_requested_at' => $row['RequestedAt'] ?? null,
                'local_issued_at' => $row['LatestIssuedAt'] ?? null,
                'local_warehouse_lot_no' => trim((string)($row['WarehouseLotNo'] ?? '')),
                'local_status' => trim((string)($row['Status'] ?? '')),
            ];
        }
    }

    $result = [];
    $addedRequestLineIds = [];
    $seenOtherRefs = [];

    foreach ($refs as $ref) {
        $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

        if ($requestLineId > 0) {
            if (isset($addedRequestLineIds[$requestLineId])) {
                continue;
            }

            $canonical = $metadataById[$requestLineId] ?? $ref;

            if ((int)($canonical['doc_entry'] ?? 0) <= 0
                || trim((string)($canonical['item_code'] ?? '')) === '') {
                continue;
            }

            $addedRequestLineIds[$requestLineId] = true;
            $result[] = $canonical;
            continue;
        }

        $otherKey = sync_ref_key($ref);

        if ($otherKey === '' || isset($seenOtherRefs[$otherKey])) {
            continue;
        }

        $seenOtherRefs[$otherKey] = true;
        $result[] = $ref;
    }

    return $result;
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

function sync_datetime_sort_key($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return trim((string)$value);
}

function sync_ref_allocation_sort_key(array $ref): string
{
    /*
     * Allocation is based on the SAP/GRPO lot stored in the request line.
     * WarehouseLotNo is an internal warehouse tracking lot and must not split
     * or redirect a SAP receipt allocation.
     */
    return (int)$ref['doc_entry'] . '|'
        . (($ref['line_num'] === null || $ref['line_num'] === '') ? '-1' : (int)$ref['line_num']) . '|'
        . strtoupper(trim((string)$ref['item_code'])) . '|'
        . sync_normalize_lot($ref['lot_no'] ?? '');
}

function sync_scan_allocation_key(array $ref, ?array $scan, string $fallbackKey): string
{
    $baseKey = scanplus_key(
        $ref['doc_entry'] ?? 0,
        $ref['line_num'] ?? null,
        $ref['item_code'] ?? ''
    );

    if ($baseKey === '') {
        return $fallbackKey;
    }

    $lotNo = '';

    if (is_array($scan)) {
        $lotNo = trim((string)($scan['received_lot_no'] ?? $scan['lot_no'] ?? ''));
    }

    if ($lotNo === '') {
        $lotNo = trim((string)($ref['lot_no'] ?? ''));
    }

    $normalizedLot = sync_normalize_lot($lotNo);

    return $normalizedLot !== ''
        ? $baseKey . '|LOT|' . $normalizedLot
        : $baseKey . '|NOLOT';
}

function sync_allocate_request_line_scan(array $ref, ?array $scan, string $allocationKey, array &$allocationByScanKey): ?array
{
    if ((int)($ref['local_request_line_id'] ?? 0) <= 0 || !is_array($scan)) {
        return $scan;
    }

    $lineScan = $scan;
    $rawQty = is_numeric($scan['received_qty'] ?? null)
        ? max(0.0, (float)$scan['received_qty'])
        : 0.0;

    /* Keep the unallocated SAP quantity for audit/debugging. */
    $lineScan['raw_received_qty'] = $rawQty;

    $hasKnownIssuedQty = array_key_exists('local_issued_qty', $ref)
        && is_numeric($ref['local_issued_qty']);
    $issuedQty = $hasKnownIssuedQty
        ? max(0.0, (float)$ref['local_issued_qty'])
        : null;

    /*
     * A local request line with a known IssuedQty of zero must never borrow a
     * receipt from another request that happens to use the same ITR/item/lot.
     * Writing this status also clears any old incorrect allocation on the next
     * scheduled synchronization.
     */
    if ($hasKnownIssuedQty && $issuedQty <= 0) {
        $lineScan['received_qty'] = 0.0;
        $lineScan['scan_status'] = 'NOT_ISSUED_REQUEST_LINE';
        return $lineScan;
    }

    /*
     * A delayed SAP scan may happen long after issuance, but it must never be
     * assigned to a request line issued after the SAP receipt timestamp.
     */
    $localIssuedAtText = sync_datetime_sort_key($ref['local_issued_at'] ?? '');
    $receivedAtText = sync_datetime_sort_key($scan['received_at'] ?? '');

    if ($localIssuedAtText !== ''
        && $receivedAtText !== ''
        && strcmp($localIssuedAtText, $receivedAtText) > 0) {
        $lineScan['received_qty'] = 0.0;
        $lineScan['scan_status'] = 'ISSUED_AFTER_SAP_RECEIPT';
        return $lineScan;
    }

    $preStatus = strtoupper(trim((string)($scan['scan_status'] ?? '')));

    if (in_array($preStatus, [
        'LOT_REQUIRED_FOR_ALLOCATION',
        'AMBIGUOUS_REQUEST_MATCH'
    ], true)) {
        $lineScan['received_qty'] = 0.0;
        return $lineScan;
    }

    if ($rawQty <= 0 || $allocationKey === '') {
        return $lineScan;
    }

    if (!isset($allocationByScanKey[$allocationKey])) {
        $allocationByScanKey[$allocationKey] = [
            'total' => $rawQty,
            'used' => 0.0,
        ];
    } elseif ($rawQty > (float)$allocationByScanKey[$allocationKey]['total']) {
        $allocationByScanKey[$allocationKey]['total'] = $rawQty;
    }

    $allocationQty = $hasKnownIssuedQty
        ? $issuedQty
        : $rawQty;
    $remainingQty = max(
        0.0,
        (float)$allocationByScanKey[$allocationKey]['total'] -
        (float)$allocationByScanKey[$allocationKey]['used']
    );
    $allocatedQty = min($allocationQty, $remainingQty);

    $allocationByScanKey[$allocationKey]['used'] =
        (float)$allocationByScanKey[$allocationKey]['used'] + $allocatedQty;

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

    $rawReceivedQty = is_array($scan) && is_numeric($scan['raw_received_qty'] ?? $scan['received_qty'] ?? null)
        ? (float)($scan['raw_received_qty'] ?? $scan['received_qty'])
        : null;
    $allocatedReceivedQty = is_array($scan) && is_numeric($scan['received_qty'] ?? null)
        ? (float)$scan['received_qty']
        : null;
    $receivedAt = is_array($scan) ? ($scan['received_at'] ?? null) : null;
    $scanStatus = strtoupper(trim((string)($scan['scan_status'] ?? '')));
    $isBlockedStatus = in_array($scanStatus, [
        'NOT_ISSUED_REQUEST_LINE',
        'NOT_ALLOCATED_TO_REQUEST_LINE',
        'LOT_REQUIRED_FOR_ALLOCATION',
        'AMBIGUOUS_REQUEST_MATCH'
    ], true);
    $isCurrentMatch = !$isBlockedStatus
        && $allocatedReceivedQty !== null
        && $allocatedReceivedQty > 0;

    $matchStatus = 'NOT_CONFIRMED';

    if ($isCurrentMatch) {
        $matchStatus = (string)($scan['scan_status'] ?? 'RECEIVED');
    } elseif (is_array($scan)) {
        $matchStatus = (string)($scan['scan_status'] ?? 'NOT_RECEIVED_IN_SAP_CACHE');
    }

    $receivedQty = $isCurrentMatch ? $allocatedReceivedQty : null;
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

function sync_monthly_itr_group_key($docEntry, $lineNum, $itemCode, $lotNo): string
{
    $baseKey = scanplus_key($docEntry, $lineNum, $itemCode);

    if ($baseKey === '') {
        return '';
    }

    $normalizedLot = sync_normalize_lot($lotNo);

    return $normalizedLot !== ''
        ? $baseKey . '|LOT|' . $normalizedLot
        : $baseKey . '|NOLOT';
}

function sync_datetime_timestamp($value): int
{
    if ($value instanceof DateTimeInterface) {
        return $value->getTimestamp();
    }

    $text = trim((string)$value);

    if ($text === '') {
        return 0;
    }

    $timestamp = strtotime($text);

    return $timestamp === false ? 0 : $timestamp;
}

/**
 * Read individual SAP Inventory Transfer rows instead of the old aggregated
 * ITR/item/lot total. This lets the synchronization match several SAP
 * postings to one local RequestLineID while keeping the monthly ITR as the
 * validation boundary.
 */
function sync_lookup_transfer_rows_by_itr_lines($erp, array $refs): array
{
    $tuples = [];

    foreach ($refs as $ref) {
        $key = scanplus_key(
            $ref['doc_entry'] ?? 0,
            $ref['line_num'] ?? null,
            $ref['item_code'] ?? ''
        );

        if ($key === '') {
            continue;
        }

        $tuples[$key] = [
            (int)$ref['doc_entry'],
            (int)$ref['line_num'],
            trim((string)$ref['item_code']),
        ];
    }

    if (empty($tuples)
        || !scanplus_has_table($erp, 'OWTR')
        || !scanplus_has_table($erp, 'WTR1')
        || !scanplus_has_table($erp, 'WTQ1')
        || !scanplus_has_column($erp, 'WTR1', 'BaseType')
        || !scanplus_has_column($erp, 'WTR1', 'BaseEntry')
        || !scanplus_has_column($erp, 'WTR1', 'BaseLine')) {
        return [];
    }

    $hasCanceled = scanplus_has_column($erp, 'OWTR', 'CANCELED');
    $hasUserSign = scanplus_has_column($erp, 'OWTR', 'UserSign');
    $hasBarcodeUser = scanplus_has_column($erp, 'OWTR', 'U_BarcodeUser');
    $hasScanDateTime = scanplus_has_column($erp, 'OWTR', 'U_ScanDateTime');
    $hasScanTime = scanplus_has_column($erp, 'OWTR', 'U_ScanTime');
    $hasCreateDate = scanplus_has_column($erp, 'OWTR', 'CreateDate');
    $hasCreateTS = scanplus_has_column($erp, 'OWTR', 'CreateTS');
    $hasDocDate = scanplus_has_column($erp, 'OWTR', 'DocDate');
    $hasWtrWarehouse = scanplus_has_column($erp, 'WTR1', 'WhsCode');
    $hasItrQty = scanplus_has_column($erp, 'WTQ1', 'Quantity');
    $hasItrOpenQty = scanplus_has_column($erp, 'WTQ1', 'OpenQty');

    $hasInventoryLogBatchJoin =
        $hasWtrWarehouse
        && scanplus_has_table($erp, 'OITL')
        && scanplus_has_table($erp, 'ITL1')
        && scanplus_has_table($erp, 'OBTN')
        && scanplus_has_column($erp, 'OITL', 'LogEntry')
        && scanplus_has_column($erp, 'OITL', 'DocType')
        && scanplus_has_column($erp, 'OITL', 'DocEntry')
        && scanplus_has_column($erp, 'OITL', 'DocLine')
        && scanplus_has_column($erp, 'OITL', 'LocCode')
        && scanplus_has_column($erp, 'ITL1', 'LogEntry')
        && scanplus_has_column($erp, 'ITL1', 'ItemCode')
        && scanplus_has_column($erp, 'ITL1', 'SysNumber')
        && scanplus_has_column($erp, 'ITL1', 'Quantity')
        && scanplus_has_column($erp, 'OBTN', 'ItemCode')
        && scanplus_has_column($erp, 'OBTN', 'SysNumber')
        && scanplus_has_column($erp, 'OBTN', 'DistNumber');

    $hasBatchJoin =
        !$hasInventoryLogBatchJoin
        && $hasWtrWarehouse
        && scanplus_has_base_table($erp, 'IBT1')
        && scanplus_has_column($erp, 'IBT1', 'BaseType')
        && scanplus_has_column($erp, 'IBT1', 'BaseEntry')
        && scanplus_has_column($erp, 'IBT1', 'BaseLinNum')
        && scanplus_has_column($erp, 'IBT1', 'ItemCode')
        && scanplus_has_column($erp, 'IBT1', 'BatchNum')
        && scanplus_has_column($erp, 'IBT1', 'Quantity')
        && scanplus_has_column($erp, 'IBT1', 'WhsCode');

    $scanDateExpr = $hasScanDateTime
        ? 'T.U_ScanDateTime'
        : ($hasCreateDate ? 'T.CreateDate' : ($hasDocDate ? 'T.DocDate' : 'CAST(NULL AS DATETIME)'));
    $scanTimeExpr = $hasScanTime
        ? 'T.U_ScanTime'
        : ($hasCreateTS ? 'T.CreateTS' : 'CAST(NULL AS INT)');
    $itrQtyExpr = $hasItrQty ? 'R.Quantity' : 'CAST(NULL AS DECIMAL(18,3))';
    $itrOpenQtyExpr = $hasItrOpenQty ? 'R.OpenQty' : 'CAST(NULL AS DECIMAL(18,3))';

    $userJoin = '';
    $scannedByParts = [];

    if ($hasBarcodeUser) {
        $scannedByParts[] = "NULLIF(CAST(T.U_BarcodeUser AS NVARCHAR(120)), '')";
    }

    if ($hasUserSign) {
        $hasOusr = scanplus_has_table($erp, 'OUSR')
            && scanplus_has_column($erp, 'OUSR', 'USERID');

        if ($hasOusr) {
            $nameParts = [];

            if (scanplus_has_column($erp, 'OUSR', 'USER_CODE')) {
                $nameParts[] = "NULLIF(CAST(U1.USER_CODE AS NVARCHAR(120)), '')";
            }

            if (scanplus_has_column($erp, 'OUSR', 'U_NAME')) {
                $nameParts[] = "NULLIF(CAST(U1.U_NAME AS NVARCHAR(120)), '')";
            }

            $nameParts[] = 'CAST(T.UserSign AS NVARCHAR(120))';
            $userJoin = 'LEFT JOIN OUSR U1 ON U1.USERID = T.UserSign';
            $scannedByParts[] = 'COALESCE(' . implode(', ', $nameParts) . ')';
        } else {
            $scannedByParts[] = 'CAST(T.UserSign AS NVARCHAR(120))';
        }
    }

    $scannedByExpr = !empty($scannedByParts)
        ? 'COALESCE(' . implode(', ', $scannedByParts) . ')'
        : "CAST('' AS NVARCHAR(120))";

    if ($hasInventoryLogBatchJoin) {
        $lotSelect = "COALESCE(BT.DistNumber, '') AS ReceivedLotNo,
            ABS(ISNULL(BL.Quantity, 0)) AS ReceivedQty";
        $lotJoin = "LEFT JOIN OITL IL
            ON IL.DocType = 67
           AND IL.DocEntry = T.DocEntry
           AND IL.DocLine = L.LineNum
           AND IL.LocCode = L.WhsCode
        LEFT JOIN ITL1 BL
            ON BL.LogEntry = IL.LogEntry
           AND BL.ItemCode = L.ItemCode
        LEFT JOIN OBTN BT
            ON BT.ItemCode = BL.ItemCode
           AND BT.SysNumber = BL.SysNumber";
    } elseif ($hasBatchJoin) {
        $lotSelect = "COALESCE(B.BatchNum, '') AS ReceivedLotNo,
            ABS(ISNULL(B.Quantity, 0)) AS ReceivedQty";
        $lotJoin = "LEFT JOIN IBT1 B
            ON B.BaseType = 67
           AND B.BaseEntry = T.DocEntry
           AND B.BaseLinNum = L.LineNum
           AND B.ItemCode = L.ItemCode
           AND B.WhsCode = L.WhsCode";
    } else {
        $lotSelect = "CAST('' AS NVARCHAR(80)) AS ReceivedLotNo,
            ABS(ISNULL(L.Quantity, 0)) AS ReceivedQty";
        $lotJoin = '';
    }

    $cancelCondition = $hasCanceled ? "AND ISNULL(T.CANCELED, 'N') = 'N'" : '';
    $rawRows = [];

    foreach (array_chunk(array_values($tuples), 5) as $tupleChunk) {
        $refRows = [];
        $params = [];

        foreach ($tupleChunk as $tuple) {
            $refRows[] = 'SELECT ? AS DocEntry, ? AS LineNum, ? AS ItemCode';
            array_push($params, $tuple[0], $tuple[1], $tuple[2]);
        }

        $rows = sync_fetch_all(
            $erp,
            "WITH Ref AS (
                " . implode("\nUNION ALL\n", $refRows) . "
             )
             SELECT
                R.DocEntry AS ITRDocEntry,
                R.LineNum AS ITRLineNum,
                R.ItemCode,
                {$itrQtyExpr} AS ITRRequestedQty,
                {$itrOpenQtyExpr} AS ITROpenQty,
                T.DocEntry AS TransferDocEntry,
                T.DocNum AS TransferDocNum,
                L.LineNum AS TransferLineNum,
                {$scanDateExpr} AS ScanDate,
                {$scanTimeExpr} AS ScanTime,
                {$scannedByExpr} AS BarcodeUser,
                {$lotSelect}
             FROM Ref
             INNER JOIN WTQ1 R
                ON R.DocEntry = Ref.DocEntry
               AND R.LineNum = Ref.LineNum
               AND R.ItemCode = Ref.ItemCode
             INNER JOIN WTR1 L
                ON L.BaseType = 1250000001
               AND L.BaseEntry = R.DocEntry
               AND L.BaseLine = R.LineNum
               AND L.ItemCode = R.ItemCode
             INNER JOIN OWTR T
                ON T.DocEntry = L.DocEntry
               {$cancelCondition}
             {$lotJoin}
             {$userJoin}
             OPTION (MAXDOP 1, RECOMPILE)",
            $params
        );

        foreach ($rows as $row) {
            $rawRows[] = $row;
        }
    }

    $result = [];

    foreach ($rawRows as $row) {
        $transferDocEntry = (int)($row['TransferDocEntry'] ?? 0);
        $transferLineNum = (int)($row['TransferLineNum'] ?? 0);
        $itemCode = trim((string)($row['ItemCode'] ?? ''));
        $lotNo = trim((string)($row['ReceivedLotNo'] ?? ''));
        $qty = is_numeric($row['ReceivedQty'] ?? null)
            ? abs((float)$row['ReceivedQty'])
            : 0.0;

        if ($transferDocEntry <= 0 || $itemCode === '' || $qty <= 0) {
            continue;
        }

        $key = $transferDocEntry . '|'
            . $transferLineNum . '|'
            . strtoupper($itemCode) . '|'
            . sync_normalize_lot($lotNo);
        $receivedAt = scanplus_datetime_text(
            $row['ScanDate'] ?? '',
            $row['ScanTime'] ?? null
        );

        if (!isset($result[$key])) {
            $result[$key] = [
                'transfer_doc_entry' => $transferDocEntry,
                'transfer_doc_num' => isset($row['TransferDocNum']) ? (int)$row['TransferDocNum'] : null,
                'transfer_line_num' => $transferLineNum,
                'doc_entry' => (int)($row['ITRDocEntry'] ?? 0),
                'line_num' => (int)($row['ITRLineNum'] ?? 0),
                'item_code' => $itemCode,
                'received_lot_no' => $lotNo,
                'received_qty' => 0.0,
                'barcode_user' => trim((string)($row['BarcodeUser'] ?? '')),
                'received_at' => $receivedAt,
                'itr_requested_qty' => is_numeric($row['ITRRequestedQty'] ?? null)
                    ? (float)$row['ITRRequestedQty']
                    : null,
                'itr_open_qty' => is_numeric($row['ITROpenQty'] ?? null)
                    ? (float)$row['ITROpenQty']
                    : null,
            ];
        }

        $result[$key]['received_qty'] += $qty;

        if ($receivedAt !== ''
            && strcmp($receivedAt, (string)$result[$key]['received_at']) > 0) {
            $result[$key]['received_at'] = $receivedAt;
            $result[$key]['barcode_user'] = trim((string)($row['BarcodeUser'] ?? ''));
        }
    }

    return array_values($result);
}

/**
 * Allocate individual SAP transfer quantities to separate local request lines.
 *
 * Boundary: monthly SAP ITR DocEntry + line + item + normalized SAP/GRPO lot.
 * Selection: the nearest actual local issuance at or before the SAP transfer.
 * Several SAP transfers may accumulate into the same RequestLineID. The amount
 * assigned to a request line never exceeds its IssuedQty; the unfilled balance
 * remains pending for the next SAP scan.
 */
function sync_allocate_monthly_itr_transfers(array $refs, array $transferRows): array
{
    $refsByGroup = [];
    $baseGroupsWithBlankLot = [];

    foreach ($refs as $ref) {
        $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

        if ($requestLineId <= 0) {
            continue;
        }

        $groupKey = sync_monthly_itr_group_key(
            $ref['doc_entry'] ?? 0,
            $ref['line_num'] ?? null,
            $ref['item_code'] ?? '',
            $ref['lot_no'] ?? ''
        );

        if ($groupKey === '') {
            continue;
        }

        $refsByGroup[$groupKey][] = $ref;

        if (sync_normalize_lot($ref['lot_no'] ?? '') === '') {
            $baseKey = scanplus_key(
                $ref['doc_entry'] ?? 0,
                $ref['line_num'] ?? null,
                $ref['item_code'] ?? ''
            );
            $baseGroupsWithBlankLot[$baseKey][] = $groupKey;
        }
    }

    $transfersByGroup = [];
    $groupScans = [];

    foreach ($transferRows as $transfer) {
        $groupKey = sync_monthly_itr_group_key(
            $transfer['doc_entry'] ?? 0,
            $transfer['line_num'] ?? null,
            $transfer['item_code'] ?? '',
            $transfer['received_lot_no'] ?? ''
        );

        if ($groupKey === '') {
            continue;
        }

        if (!isset($refsByGroup[$groupKey])) {
            $baseKey = scanplus_key(
                $transfer['doc_entry'] ?? 0,
                $transfer['line_num'] ?? null,
                $transfer['item_code'] ?? ''
            );
            $blankGroups = array_values(array_unique($baseGroupsWithBlankLot[$baseKey] ?? []));

            if (count($blankGroups) === 1) {
                $groupKey = $blankGroups[0];
            }
        }

        $transfersByGroup[$groupKey][] = $transfer;

        if (!isset($groupScans[$groupKey])) {
            $groupScans[$groupKey] = [
                'received_qty' => 0.0,
                'received_lot_no' => trim((string)($transfer['received_lot_no'] ?? '')),
                'barcode_user' => trim((string)($transfer['barcode_user'] ?? '')),
                'received_at' => trim((string)($transfer['received_at'] ?? '')),
                'scan_status' => 'SAP_RECEIVED',
                'itr_requested_qty' => $transfer['itr_requested_qty'] ?? null,
                'itr_open_qty' => $transfer['itr_open_qty'] ?? null,
                'transfer_count' => 0,
            ];
        }

        $groupScans[$groupKey]['received_qty'] += max(
            0.0,
            (float)($transfer['received_qty'] ?? 0)
        );
        $groupScans[$groupKey]['transfer_count']++;

        $receivedAt = trim((string)($transfer['received_at'] ?? ''));

        if ($receivedAt !== ''
            && strcmp($receivedAt, (string)$groupScans[$groupKey]['received_at']) >= 0) {
            $groupScans[$groupKey]['received_at'] = $receivedAt;
            $groupScans[$groupKey]['barcode_user'] = trim((string)($transfer['barcode_user'] ?? ''));
        }
    }

    $lineAllocations = [];
    $unallocatedByGroup = [];

    foreach ($refsByGroup as $groupKey => $groupRefs) {
        $groupTransfers = $transfersByGroup[$groupKey] ?? [];

        usort($groupTransfers, static function (array $a, array $b): int {
            $timeCompare = sync_datetime_timestamp($a['received_at'] ?? '')
                <=> sync_datetime_timestamp($b['received_at'] ?? '');

            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            $docCompare = (int)($a['transfer_doc_entry'] ?? 0)
                <=> (int)($b['transfer_doc_entry'] ?? 0);

            if ($docCompare !== 0) {
                return $docCompare;
            }

            return (int)($a['transfer_line_num'] ?? 0)
                <=> (int)($b['transfer_line_num'] ?? 0);
        });

        foreach ($groupRefs as $ref) {
            $requestLineId = (int)($ref['local_request_line_id'] ?? 0);
            $lineAllocations[$requestLineId] = [
                'qty' => 0.0,
                'received_at' => '',
                'barcode_user' => '',
                'received_lot_no' => '',
                'transfer_doc_entries' => [],
            ];
        }

        foreach ($groupTransfers as $transfer) {
            $remainingTransferQty = max(0.0, (float)($transfer['received_qty'] ?? 0));
            $transferTimestamp = sync_datetime_timestamp($transfer['received_at'] ?? '');

            while ($remainingTransferQty > 0.0005) {
                $candidates = [];

                foreach ($groupRefs as $ref) {
                    $requestLineId = (int)($ref['local_request_line_id'] ?? 0);
                    $issuedQty = is_numeric($ref['local_issued_qty'] ?? null)
                        ? max(0.0, (float)$ref['local_issued_qty'])
                        : 0.0;
                    $alreadyAllocated = (float)($lineAllocations[$requestLineId]['qty'] ?? 0);
                    $requestRemaining = max(0.0, $issuedQty - $alreadyAllocated);

                    if ($requestLineId <= 0 || $requestRemaining <= 0.0005) {
                        continue;
                    }

                    $eventAt = $ref['local_issued_at']
                        ?? $ref['local_requested_at']
                        ?? '';
                    $eventTimestamp = sync_datetime_timestamp($eventAt);

                    if ($transferTimestamp > 0
                        && $eventTimestamp > 0
                        && $eventTimestamp > $transferTimestamp) {
                        continue;
                    }

                    $candidates[] = [
                        'ref' => $ref,
                        'event_timestamp' => $eventTimestamp,
                        'remaining' => $requestRemaining,
                    ];
                }

                if (empty($candidates)) {
                    break;
                }

                usort($candidates, static function (array $a, array $b) use ($remainingTransferQty): int {
                    $timeCompare = (int)$b['event_timestamp'] <=> (int)$a['event_timestamp'];

                    if ($timeCompare !== 0) {
                        return $timeCompare;
                    }

                    $aExact = abs((float)$a['remaining'] - $remainingTransferQty) <= 0.0005 ? 1 : 0;
                    $bExact = abs((float)$b['remaining'] - $remainingTransferQty) <= 0.0005 ? 1 : 0;

                    if ($aExact !== $bExact) {
                        return $bExact <=> $aExact;
                    }

                    return (int)($b['ref']['local_request_line_id'] ?? 0)
                        <=> (int)($a['ref']['local_request_line_id'] ?? 0);
                });

                $selected = $candidates[0];
                $selectedRef = $selected['ref'];
                $requestLineId = (int)$selectedRef['local_request_line_id'];
                $allocatedQty = min(
                    $remainingTransferQty,
                    (float)$selected['remaining']
                );

                $lineAllocations[$requestLineId]['qty'] += $allocatedQty;
                $lineAllocations[$requestLineId]['received_lot_no'] = trim(
                    (string)($transfer['received_lot_no'] ?? '')
                );
                $lineAllocations[$requestLineId]['transfer_doc_entries'][
                    (int)($transfer['transfer_doc_entry'] ?? 0)
                ] = true;

                $receivedAt = trim((string)($transfer['received_at'] ?? ''));

                if ($receivedAt !== ''
                    && strcmp($receivedAt, (string)$lineAllocations[$requestLineId]['received_at']) >= 0) {
                    $lineAllocations[$requestLineId]['received_at'] = $receivedAt;
                    $lineAllocations[$requestLineId]['barcode_user'] = trim(
                        (string)($transfer['barcode_user'] ?? '')
                    );
                }

                $remainingTransferQty -= $allocatedQty;
            }

            if ($remainingTransferQty > 0.0005) {
                $unallocatedByGroup[$groupKey] =
                    (float)($unallocatedByGroup[$groupKey] ?? 0) + $remainingTransferQty;
            }
        }
    }

    return [
        'line_allocations' => $lineAllocations,
        'group_scans' => $groupScans,
        'unallocated_by_group' => $unallocatedByGroup,
    ];
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
        $txIssuedQtyExpr = sync_has_column($whp, 'IssuanceTransactions', 'Quantity')
            ? 'IT.Quantity'
            : 'CAST(NULL AS DECIMAL(18,3))';
        $txIssuedAtExpr = sync_has_column($whp, 'IssuanceTransactions', 'IssuedAt')
            ? 'IT.IssuedAt'
            : 'CAST(NULL AS DATETIME)';
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
                {$txIssuedQtyExpr} AS IssuedQty,
                {$txIssuedAtExpr} AS IssuedAt,
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
         * Keep every local request line in the synchronization set. Issued
         * lines can receive an allocation; zero-issued lines are deliberately
         * written as NOT_ISSUED_REQUEST_LINE so a previously wrong allocation
         * is cleared. No scan is assigned by request date alone.
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
        $requestIssueApply = '';
        $requestIssuedAtExpr = 'CAST(NULL AS DATETIME)';

        if (sync_has_table($whp, 'IssuanceTransactions')
            && sync_has_column($whp, 'IssuanceTransactions', 'IssueRequestLineID')
            && sync_has_column($whp, 'IssuanceTransactions', 'IssuedAt')) {
            $requestIssueItemCondition = sync_has_column($whp, 'IssuanceTransactions', 'ItemCode')
                ? 'AND (ITX.ItemCode = L.ItemCode OR ITX.ItemCode IS NULL)'
                : '';
            $requestIssueQtyCondition = sync_has_column($whp, 'IssuanceTransactions', 'Quantity')
                ? 'AND ISNULL(TRY_CONVERT(DECIMAL(18,3), ITX.Quantity), 0) > 0'
                : '';
            $requestIssueApply = "
             OUTER APPLY
             (
                SELECT
                    MAX(ITX.IssuedAt) AS LatestIssuedAt
                FROM dbo.IssuanceTransactions ITX
                WHERE ITX.IssueRequestLineID = L.RequestLineID
                  {$requestIssueItemCondition}
                  {$requestIssueQtyCondition}
             ) IX";
            $requestIssuedAtExpr = 'IX.LatestIssuedAt';
        }

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
                {$requestIssuedAtExpr} AS LatestIssuedAt,
                {$reqLineWarehouseLotExpr} AS WarehouseLotNo,
                {$reqLineStatusExpr} AS Status
             FROM dbo.WarehouseIssueRequestHeader H
             INNER JOIN dbo.WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
             {$requestIssueApply}
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

    $refs = sync_canonicalize_request_line_refs($whp, $refs);

    if (empty($refs)) {
        throw new RuntimeException('No canonical local request-line references remained after enrichment.');
    }

    sync_log('Canonical references after request-line enrichment: ' . count($refs));

    usort($refs, static function (array $a, array $b): int {
        $keyCompare = strcmp(
            sync_ref_allocation_sort_key($a),
            sync_ref_allocation_sort_key($b)
        );

        if ($keyCompare !== 0) {
            return $keyCompare;
        }

        /*
         * For a delayed receipt, allocate to the request line with the latest
         * actual issuance event first. RequestedAt is only a fallback when the
         * line has no linked IssuanceTransactions record.
         */
        $aEventAt = sync_datetime_sort_key(
            $a['local_issued_at'] ?? $a['local_requested_at'] ?? ''
        );
        $bEventAt = sync_datetime_sort_key(
            $b['local_issued_at'] ?? $b['local_requested_at'] ?? ''
        );

        $timeCompare = strcmp($bEventAt, $aEventAt);

        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return (int)($b['local_request_line_id'] ?? 0)
            <=> (int)($a['local_request_line_id'] ?? 0);
    });

    sync_log('Unique local references found: ' . count($refs));
    $erp = get_erp_connection();
    if (!$erp) {
        throw new RuntimeException('Unable to connect to the SAP/ERP database from the scheduled task account.');
    }

    /*
     * Read every SAP Inventory Transfer separately. The monthly ITR line/item/
     * GRPO lot is the validation boundary, while RequestLineID remains the
     * local allocation target. Multiple SAP transfers can therefore accumulate
     * into one request line without being merged into a different request.
     */
    $transferLookupStarted = microtime(true);
    $transferRows = sync_lookup_transfer_rows_by_itr_lines($erp, $refs);
    sync_log('Individual SAP transfer rows found: ' . count($transferRows)
        . ', lookup=' . round(microtime(true) - $transferLookupStarted, 3) . ' sec');

    $allocationResult = sync_allocate_monthly_itr_transfers($refs, $transferRows);
    $lineAllocations = $allocationResult['line_allocations'] ?? [];
    $groupScans = $allocationResult['group_scans'] ?? [];
    $unallocatedByGroup = $allocationResult['unallocated_by_group'] ?? [];

    foreach ($refs as $ref) {
        $requestLineId = (int)($ref['local_request_line_id'] ?? 0);
        $groupKey = sync_monthly_itr_group_key(
            $ref['doc_entry'] ?? 0,
            $ref['line_num'] ?? null,
            $ref['item_code'] ?? '',
            $ref['lot_no'] ?? ''
        );
        $groupScan = $groupScans[$groupKey] ?? null;

        /* Keep the raw aggregate cache for diagnostics and existing reports. */
        sync_upsert_cache($whp, $ref, is_array($groupScan) ? $groupScan : null);

        if ($requestLineId <= 0) {
            $updated++;
            is_array($groupScan) ? $matched++ : $missing++;
            continue;
        }

        $issuedQty = is_numeric($ref['local_issued_qty'] ?? null)
            ? max(0.0, (float)$ref['local_issued_qty'])
            : 0.0;
        $allocation = $lineAllocations[$requestLineId] ?? [
            'qty' => 0.0,
            'received_at' => '',
            'barcode_user' => '',
            'received_lot_no' => '',
            'transfer_doc_entries' => [],
        ];
        $allocatedQty = max(0.0, (float)($allocation['qty'] ?? 0));
        $lineScan = null;

        if ($issuedQty <= 0) {
            $lineScan = [
                'raw_received_qty' => is_array($groupScan)
                    ? (float)($groupScan['received_qty'] ?? 0)
                    : 0.0,
                'received_qty' => 0.0,
                'received_lot_no' => is_array($groupScan)
                    ? (string)($groupScan['received_lot_no'] ?? '')
                    : '',
                'barcode_user' => '',
                'received_at' => is_array($groupScan)
                    ? (string)($groupScan['received_at'] ?? '')
                    : '',
                'scan_status' => 'NOT_ISSUED_REQUEST_LINE',
            ];
        } elseif (!is_array($groupScan)) {
            $lineScan = [
                'raw_received_qty' => 0.0,
                'received_qty' => 0.0,
                'received_lot_no' => '',
                'barcode_user' => '',
                'received_at' => '',
                'scan_status' => 'NOT_RECEIVED_IN_SAP_CACHE',
            ];
        } elseif ($allocatedQty <= 0.0005) {
            $lineScan = [
                'raw_received_qty' => (float)($groupScan['received_qty'] ?? 0),
                'received_qty' => 0.0,
                'received_lot_no' => (string)($groupScan['received_lot_no'] ?? ''),
                'barcode_user' => '',
                'received_at' => (string)($groupScan['received_at'] ?? ''),
                'scan_status' => 'NOT_ALLOCATED_TO_REQUEST_LINE',
            ];
        } else {
            $lineScan = [
                'raw_received_qty' => (float)($groupScan['received_qty'] ?? 0),
                'received_qty' => min($allocatedQty, $issuedQty),
                'received_lot_no' => (string)(
                    $allocation['received_lot_no']
                    ?? $groupScan['received_lot_no']
                    ?? ''
                ),
                'barcode_user' => (string)($allocation['barcode_user'] ?? ''),
                'received_at' => (string)($allocation['received_at'] ?? ''),
                'scan_status' => $allocatedQty + 0.0005 >= $issuedQty
                    ? 'SAP_RECEIVED'
                    : 'SAP_PARTIAL',
            ];
        }

        sync_upsert_request_line_receive_cache($whp, $ref, $lineScan);
        $updated++;
        is_array($groupScan) ? $matched++ : $missing++;

        if ($issuedQty > 0) {
            $verifyChecked++;

            if (!is_array($groupScan)) {
                $verifyMissingInSap++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'MISSING_IN_SAP doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty
                    );
                }
            } elseif ($allocatedQty > 0 && abs($allocatedQty - $issuedQty) > 0.001) {
                $verifyQtyMismatch++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'PARTIAL_REQUEST_ALLOCATION doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f allocatedQty=%.3f remainingQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $allocatedQty,
                        max(0.0, $issuedQty - $allocatedQty)
                    );
                }
            }

            $localLot = trim((string)($ref['lot_no'] ?? ''));
            $sapLot = is_array($groupScan)
                ? trim((string)($groupScan['received_lot_no'] ?? ''))
                : '';

            if ($localLot !== ''
                && $sapLot !== ''
                && sync_normalize_lot($localLot) !== sync_normalize_lot($sapLot)) {
                $verifyLotMismatch++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'LOT_MISMATCH doc=%d line=%s item=%s requestLine=%d localLot=%s sapLot=%s',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $localLot,
                        $sapLot
                    );
                }
            }
        }
    }

    foreach ($groupScans as $groupKey => $groupScan) {
        $unallocatedQty = (float)($unallocatedByGroup[$groupKey] ?? 0);
        $itrRequestedQty = $groupScan['itr_requested_qty'] ?? null;
        $monthlyRemaining = is_numeric($itrRequestedQty)
            ? max(0.0, (float)$itrRequestedQty - (float)($groupScan['received_qty'] ?? 0))
            : null;

        sync_log(sprintf(
            'Monthly ITR group %s: transfers=%d, sap_received=%.3f, unallocated=%.3f%s',
            $groupKey,
            (int)($groupScan['transfer_count'] ?? 0),
            (float)($groupScan['received_qty'] ?? 0),
            $unallocatedQty,
            $monthlyRemaining !== null
                ? ', monthly_itr_remaining=' . number_format($monthlyRemaining, 3, '.', '')
                : ''
        ));
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
