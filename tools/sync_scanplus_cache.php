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


/**
 * Store the exact SAP Inventory Transfer source assigned to each local
 * RequestLineID. One local request line may receive several SAP transfers.
 */
function sync_ensure_request_line_receive_allocation($conn): bool
{
    sync_exec(
        $conn,
        "IF OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveAllocation', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.WarehouseIssueRequestLineReceiveAllocation (
                AllocationID BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                RequestLineID INT NOT NULL,
                RequestNo NVARCHAR(80) NULL,
                SAPTransferDocEntry INT NOT NULL,
                SAPTransferDocNum INT NULL,
                SAPTransferLineNum INT NOT NULL,
                SAP_IT_DocEntry INT NOT NULL,
                SAP_IT_LineNum INT NULL,
                ItemCode NVARCHAR(50) NOT NULL,
                GRPOLotNo NVARCHAR(80) NOT NULL,
                ReceivedLotNo NVARCHAR(80) NULL,
                AllocatedQty DECIMAL(18,3) NOT NULL,
                ReceivedAt DATETIME NULL,
                BarcodeUser NVARCHAR(120) NULL,
                MatchMethod NVARCHAR(80) NOT NULL,
                LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
            );
         END"
    );

    /*
     * One FT_INVT source row may be split across several local request lines
     * when one same-day ScanPlus receipt covers several issuance transactions.
     * Therefore uniqueness is PER REQUEST LINE, not global.
     */
    sync_exec(
        $conn,
        "IF EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'UX_WIRLA_SourceGlobal'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveAllocation')
         )
         BEGIN
            DROP INDEX UX_WIRLA_SourceGlobal
            ON dbo.WarehouseIssueRequestLineReceiveAllocation;
         END"
    );

    sync_exec(
        $conn,
        "IF OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveAllocation', 'U') IS NOT NULL
         BEGIN
            ;WITH DuplicateSource AS
            (
                SELECT
                    AllocationID,
                    ROW_NUMBER() OVER
                    (
                        PARTITION BY
                            RequestLineID,
                            SAPTransferDocEntry,
                            SAPTransferLineNum,
                            ItemCode,
                            GRPOLotNo,
                            ReceivedLotNo
                        ORDER BY LastSyncedAt DESC, AllocationID DESC
                    ) AS RowNo
                FROM dbo.WarehouseIssueRequestLineReceiveAllocation
            )
            DELETE FROM DuplicateSource WHERE RowNo > 1;
         END"
    );

    /* Rebuild the per-request source index so ReceivedLotNo is part of identity. */
    sync_exec(
        $conn,
        "IF EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'UX_WIRLA_SourcePerRequest'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveAllocation')
         )
         BEGIN
            DROP INDEX UX_WIRLA_SourcePerRequest
            ON dbo.WarehouseIssueRequestLineReceiveAllocation;
         END
         CREATE UNIQUE INDEX UX_WIRLA_SourcePerRequest
         ON dbo.WarehouseIssueRequestLineReceiveAllocation
         (
            RequestLineID,
            SAPTransferDocEntry,
            SAPTransferLineNum,
            ItemCode,
            GRPOLotNo,
            ReceivedLotNo
         );"
    );

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'IX_WIRLA_GrpoSourceLookup' 
              AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLineReceiveAllocation')
         )
         BEGIN
            CREATE INDEX IX_WIRLA_GrpoSourceLookup
            ON dbo.WarehouseIssueRequestLineReceiveAllocation
            (
                SAP_IT_DocEntry,
                SAP_IT_LineNum,
                ItemCode,
                GRPOLotNo,
                ReceivedAt
            );
         END"
    );

    return sync_has_table($conn, 'WarehouseIssueRequestLineReceiveAllocation');
}

function sync_clear_request_line_receive_allocations($conn, array $refs): void
{
    if (!sync_has_table($conn, 'WarehouseIssueRequestLineReceiveAllocation')) {
        return;
    }

    $requestLineIds = [];

    foreach ($refs as $ref) {
        $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

        if ($requestLineId > 0) {
            $requestLineIds[$requestLineId] = true;
        }
    }

    foreach (array_chunk(array_keys($requestLineIds), 500) as $idChunk) {
        if (empty($idChunk)) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
        sync_exec(
            $conn,
            "DELETE FROM dbo.WarehouseIssueRequestLineReceiveAllocation
             WHERE RequestLineID IN ({$placeholders})",
            array_values($idChunk)
        );
    }
}

function sync_replace_request_line_receive_allocations(
    $conn,
    array $ref,
    array $sourceTransfers
): void {
    $requestLineId = (int)($ref['local_request_line_id'] ?? 0);

    if ($requestLineId <= 0
        || !sync_has_table($conn, 'WarehouseIssueRequestLineReceiveAllocation')) {
        return;
    }

    foreach ($sourceTransfers as $source) {
        $allocatedQty = is_numeric($source['allocated_qty'] ?? null)
            ? max(0.0, (float)$source['allocated_qty'])
            : 0.0;
        $grpoLot = trim((string)($source['issued_lot_no'] ?? $ref['lot_no'] ?? ''));

        if ($allocatedQty <= 0.0005
            || (int)($source['transfer_doc_entry'] ?? 0) <= 0
            || $grpoLot === '') {
            continue;
        }

        sync_exec(
            $conn,
            "INSERT INTO dbo.WarehouseIssueRequestLineReceiveAllocation
            (
                RequestLineID,
                RequestNo,
                SAPTransferDocEntry,
                SAPTransferDocNum,
                SAPTransferLineNum,
                SAP_IT_DocEntry,
                SAP_IT_LineNum,
                ItemCode,
                GRPOLotNo,
                ReceivedLotNo,
                AllocatedQty,
                ReceivedAt,
                BarcodeUser,
                MatchMethod,
                LastSyncedAt
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())",
            [
                $requestLineId,
                $ref['local_request_no'] ?? null,
                (int)$source['transfer_doc_entry'],
                isset($source['transfer_doc_num']) ? (int)$source['transfer_doc_num'] : null,
                (int)($source['transfer_line_num'] ?? 0),
                (int)($ref['doc_entry'] ?? 0),
                $ref['line_num'] ?? null,
                trim((string)($ref['item_code'] ?? '')),
                $grpoLot,
                trim((string)($source['received_lot_no'] ?? '')),
                $allocatedQty,
                $source['received_at'] ?? null,
                $source['barcode_user'] ?? null,
                trim((string)($source['match_method'] ?? 'ITR_LINE_ITEM_GRPO_LOT_MATCH')),
            ]
        );
    }
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

    /*
     * GRPO/SAP lot is mandatory for automatic request allocation. The same
     * monthly ITR line can be requested many times, so DocEntry/Line/Item
     * without the GRPO lot is not a safe identity.
     */
    if ($normalizedLot === '') {
        return '';
    }

    return $baseKey . '|LOT|' . $normalizedLot;
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
     * A delayed ScanPlus receive scan may happen long after issuance, but it must never be
     * assigned to a request line issued after the ScanPlus receipt timestamp.
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
        'GRPO_LOT_REQUIRED',
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
    $receivedAtText = trim((string)$receivedAt);
    if ($receivedAtText === '' || str_starts_with($receivedAtText, '1900-01-01')) {
        $receivedAt = null;
    }

    $scanStatus = strtoupper(trim((string)($scan['scan_status'] ?? '')));
    $isBlockedStatus = in_array($scanStatus, [
        'NOT_ISSUED_REQUEST_LINE',
        'NOT_ALLOCATED_TO_REQUEST_LINE',
        'LOT_REQUIRED_FOR_ALLOCATION',
        'GRPO_LOT_REQUIRED',
        'AMBIGUOUS_REQUEST_MATCH',
        'NOT_RECEIVED_IN_SCANPLUS',
        'SCANPLUS_SCANNED_NOT_POSTED',
        'UNALLOCATED_DAILY_SCAN',
        'UNVERIFIED_DATE',
        'GROUP_PARTIAL_POSTED'
    ], true);
    $isCurrentMatch = !$isBlockedStatus
        && $allocatedReceivedQty !== null
        && $allocatedReceivedQty > 0;

    $matchStatus = 'NOT_CONFIRMED';

    if (is_array($scan) && trim((string)($scan['match_status'] ?? '')) !== '') {
        $matchStatus = (string)$scan['match_status'];
    } elseif ($isCurrentMatch) {
        $matchStatus = (string)($scan['scan_status'] ?? 'RECEIVED');
    } elseif (is_array($scan)) {
        $matchStatus = (string)($scan['scan_status'] ?? 'NOT_RECEIVED_IN_SCANPLUS');
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

    /* Exact GRPO/SAP lot is required for automatic source matching. */
    if ($normalizedLot === '') {
        return '';
    }

    return $baseKey . '|LOT|' . $normalizedLot;
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

function sync_datetime_date_key($value): string
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

function sync_transfer_candidate_score(array $candidate): array
{
    return [
        (int)($candidate['linked_match'] ?? 0),
        (int)($candidate['same_issued_date'] ?? 0),
        (int)($candidate['same_requested_date'] ?? 0),
        (int)($candidate['exact_qty'] ?? 0),
        (int)($candidate['event_timestamp'] ?? 0),
        -round((float)($candidate['slack'] ?? 0), 3),
    ];
}

function sync_compare_transfer_candidate_scores(array $a, array $b): int
{
    $aScore = sync_transfer_candidate_score($a);
    $bScore = sync_transfer_candidate_score($b);

    foreach ($aScore as $index => $aValue) {
        $comparison = $aValue <=> $bScore[$index];

        if ($comparison !== 0) {
            return -$comparison;
        }
    }

    return (int)($a['request_line_id'] ?? 0)
        <=> (int)($b['request_line_id'] ?? 0);
}

function sync_apply_transfer_allocation(
    array &$lineAllocations,
    int $requestLineId,
    array $transfer,
    string $matchMethod
): void {
    $allocatedQty = max(0.0, (float)($transfer['received_qty'] ?? 0));

    if ($requestLineId <= 0 || $allocatedQty <= 0.0005) {
        return;
    }

    $lineAllocations[$requestLineId]['qty'] += $allocatedQty;
    $lineAllocations[$requestLineId]['received_lot_no'] = trim(
        (string)($transfer['received_lot_no'] ?? '')
    );

    $transferDocEntry = (int)($transfer['transfer_doc_entry'] ?? 0);
    $transferLineNum = (int)($transfer['transfer_line_num'] ?? 0);
    $transferSourceKey = $transferDocEntry . '|'
        . $transferLineNum . '|'
        . strtoupper(trim((string)($transfer['item_code'] ?? ''))) . '|'
        . sync_normalize_lot($transfer['received_lot_no'] ?? '');

    $lineAllocations[$requestLineId]['transfer_doc_entries'][$transferDocEntry] = true;
    $lineAllocations[$requestLineId]['source_transfers'][$transferSourceKey] = [
        'transfer_doc_entry' => $transferDocEntry,
        'transfer_doc_num' => $transfer['transfer_doc_num'] ?? null,
        'transfer_line_num' => $transferLineNum,
        'item_code' => trim((string)($transfer['item_code'] ?? '')),
        'received_lot_no' => trim((string)($transfer['received_lot_no'] ?? '')),
        'allocated_qty' => $allocatedQty,
        'received_at' => trim((string)($transfer['received_at'] ?? '')),
        'barcode_user' => trim((string)($transfer['barcode_user'] ?? '')),
        'match_method' => $matchMethod,
    ];

    $receivedAt = trim((string)($transfer['received_at'] ?? ''));

    if ($receivedAt !== ''
        && strcmp($receivedAt, (string)$lineAllocations[$requestLineId]['received_at']) >= 0) {
        $lineAllocations[$requestLineId]['received_at'] = $receivedAt;
        $lineAllocations[$requestLineId]['barcode_user'] = trim(
            (string)($transfer['barcode_user'] ?? '')
        );
    }
}

function sync_allocate_transfer_rows_by_daily_match(
    array $documentRows,
    array $refsByGroup,
    array &$lineAllocations
): array {
    $plannedQtyByLine = [];
    $assignments = [];
    $unassignedRows = [];

    foreach ($documentRows as $transferIndex => $transfer) {
        $groupKey = sync_monthly_itr_group_key(
            $transfer['doc_entry'] ?? 0,
            $transfer['line_num'] ?? null,
            $transfer['item_code'] ?? '',
            $transfer['received_lot_no'] ?? ''
        );
        $transferQty = max(0.0, (float)($transfer['received_qty'] ?? 0));
        $transferTimestamp = sync_datetime_timestamp($transfer['received_at'] ?? '');
        $transferDateKey = sync_datetime_date_key($transfer['received_at'] ?? '');
        $linkedRequestLineId = (int)($transfer['linked_request_line_id'] ?? 0);
        $candidates = [];

        foreach (($refsByGroup[$groupKey] ?? []) as $requestLineId => $ref) {
            $requestLineId = (int)$requestLineId;

            if ($linkedRequestLineId > 0 && $requestLineId !== $linkedRequestLineId) {
                continue;
            }

            $issuedQty = is_numeric($ref['local_issued_qty'] ?? null)
                ? max(0.0, (float)$ref['local_issued_qty'])
                : 0.0;
            $alreadyAllocated = (float)($lineAllocations[$requestLineId]['qty'] ?? 0);
            $plannedQty = (float)($plannedQtyByLine[$requestLineId] ?? 0);
            $remaining = max(0.0, $issuedQty - $alreadyAllocated - $plannedQty);
            $eventAt = $ref['local_issued_at']
                ?? $ref['local_requested_at']
                ?? '';
            $eventTimestamp = sync_datetime_timestamp($eventAt);

            if ($remaining + 0.0005 < $transferQty) {
                continue;
            }

            if ($transferTimestamp > 0
                && $eventTimestamp > 0
                && $eventTimestamp > $transferTimestamp) {
                continue;
            }

            $issuedDateKey = sync_datetime_date_key($ref['local_issued_at'] ?? '');
            $requestedDateKey = sync_datetime_date_key($ref['local_requested_at'] ?? '');
            $slack = max(0.0, $remaining - $transferQty);

            $candidates[] = [
                'request_line_id' => $requestLineId,
                'linked_match' => $linkedRequestLineId > 0 && $requestLineId === $linkedRequestLineId ? 1 : 0,
                'same_issued_date' => $transferDateKey !== ''
                    && $issuedDateKey !== ''
                    && $transferDateKey === $issuedDateKey ? 1 : 0,
                'same_requested_date' => $transferDateKey !== ''
                    && $requestedDateKey !== ''
                    && $transferDateKey === $requestedDateKey ? 1 : 0,
                'exact_qty' => abs($remaining - $transferQty) <= 0.0005 ? 1 : 0,
                'event_timestamp' => $eventTimestamp,
                'slack' => $slack,
            ];
        }

        if (empty($candidates)) {
            $unassignedRows[$transferIndex] = [
                'transfer' => $transfer,
                'candidate_line_ids' => [],
            ];
            continue;
        }

        usort($candidates, 'sync_compare_transfer_candidate_scores');

        $topScore = sync_transfer_candidate_score($candidates[0]);
        $topCandidates = array_filter(
            $candidates,
            static fn(array $candidate): bool =>
                sync_transfer_candidate_score($candidate) === $topScore
        );

        if (count($topCandidates) !== 1) {
            $unassignedRows[$transferIndex] = [
                'transfer' => $transfer,
                'candidate_line_ids' => array_map(
                    static fn(array $candidate): int => (int)$candidate['request_line_id'],
                    $topCandidates
                ),
            ];
            continue;
        }

        $selected = array_values($topCandidates)[0];
        $requestLineId = (int)$selected['request_line_id'];

        $plannedQtyByLine[$requestLineId] =
            (float)($plannedQtyByLine[$requestLineId] ?? 0) + $transferQty;

        $assignments[$transferIndex] = [
            'request_line_id' => $requestLineId,
            'transfer' => $transfer,
        ];
    }

    foreach ($assignments as $assignment) {
        sync_apply_transfer_allocation(
            $lineAllocations,
            (int)$assignment['request_line_id'],
            $assignment['transfer'],
            'SCANPLUS_FT_INVT_LINE_DAILY_MATCH'
        );
    }

    return [
        'assigned_count' => count($assignments),
        'unassigned_rows' => $unassignedRows,
    ];
}

/**
 * Read individual ScanPlus receiving rows directly from FTSIBARCODEDB.dbo.FT_INVT.
 *
 * Only physical receiving transactions from warehouse 01 to warehouse CNC are
 * considered. These rows prove that ScanPlus scanned/staged the movement, but they
 * do NOT by themselves prove that SAP successfully posted an Inventory Transfer.
 *
 * The returned array keeps the field names expected by the existing allocation
 * engine so the request-line/lot/time protections do not need to be rewritten.
 */
function sync_lookup_transfer_rows_by_itr_lines($erp, array $refs, int $lookbackDays = 45): array
{
    $tuples = [];

    foreach ($refs as $ref) {
        $docEntry = (int)($ref['doc_entry'] ?? 0);
        $lineRaw = $ref['line_num'] ?? null;
        $itemCode = trim((string)($ref['item_code'] ?? ''));

        if ($docEntry <= 0
            || $lineRaw === null
            || trim((string)$lineRaw) === ''
            || $itemCode === '') {
            continue;
        }

        $lineNum = (int)$lineRaw;
        $key = scanplus_key($docEntry, $lineNum, $itemCode);

        if ($key === '') {
            continue;
        }

        $tuples[$key] = [$docEntry, $lineNum, $itemCode];
    }

    if (empty($tuples)) {
        return [];
    }

    $lookbackDays = max(1, min(180, $lookbackDays));
    $rawRows = [];

    /*
     * Use small tuple chunks so SQL Server does not receive an excessively large
     * parameter list. FT_INVT is filtered first by Object, 01 -> CNC and scan date.
     */
    foreach (array_chunk(array_values($tuples), 20) as $tupleChunk) {
        $refRows = [];
        $params = [];

        foreach ($tupleChunk as $tuple) {
            $refRows[] = 'SELECT ? AS DocEntry, ? AS LineNum, ? AS ItemCode';
            array_push($params, $tuple[0], $tuple[1], $tuple[2]);
        }

        $params[] = $lookbackDays;

        $rows = sync_fetch_all(
            $erp,
            "WITH Ref AS (
                " . implode("\nUNION ALL\n", $refRows) . "
             )
             SELECT
                Ref.DocEntry AS ITRDocEntry,
                Ref.LineNum AS ITRLineNum,
                Ref.ItemCode,

                F.DocEntry AS TransferDocEntry,
                CAST(NULL AS INT) AS TransferDocNum,
                F.LineId AS TransferLineNum,
                CAST(NULL AS INT) AS LinkedRequestLineID,

                F.DocNum AS SourceDocumentKey,
                F.U_ScanDateTime AS ReceivedAt,
                CAST(F.CreatedBy AS NVARCHAR(120)) AS BarcodeUser,

                COALESCE(
                    NULLIF(LTRIM(RTRIM(CAST(F.U_BatchNo AS NVARCHAR(80)))), ''),
                    NULLIF(LTRIM(RTRIM(CAST(F.U_BSNo AS NVARCHAR(80)))), ''),
                    ''
                ) AS ReceivedLotNo,

                ABS(ISNULL(TRY_CONVERT(DECIMAL(18, 3), F.U_Quantity), 0)) AS ReceivedQty,

                TRY_CONVERT(DECIMAL(18, 3), Q.Quantity) AS ITRRequestedQty,
                TRY_CONVERT(DECIMAL(18, 3), Q.OpenQty) AS ITROpenQty

             FROM Ref

             INNER JOIN [FTSIBARCODEDB].[dbo].[FT_INVT] F
                ON TRY_CONVERT(INT, F.U_BaseDocNo) = Ref.DocEntry
               AND TRY_CONVERT(INT, F.U_BaseLine) = Ref.LineNum
               AND F.U_ItemCode COLLATE DATABASE_DEFAULT
                   = Ref.ItemCode COLLATE DATABASE_DEFAULT

             LEFT JOIN WTQ1 Q
                ON Q.DocEntry = Ref.DocEntry
               AND Q.LineNum = Ref.LineNum
               AND Q.ItemCode = Ref.ItemCode

             WHERE
                F.Object COLLATE DATABASE_DEFAULT = 'OWTR'
                AND F.U_FromWhsCode COLLATE DATABASE_DEFAULT = '01'
                AND F.U_WhsCode COLLATE DATABASE_DEFAULT = 'CNC'
                AND F.U_ScanDateTime IS NOT NULL
                AND F.U_ScanDateTime >= DATEADD(DAY, -?, GETDATE())

             ORDER BY
                F.U_ScanDateTime,
                F.DocNum,
                F.LineId

             OPTION (MAXDOP 1, RECOMPILE)",
            $params
        );

        foreach ($rows as $row) {
            $rawRows[] = $row;
        }
    }

    $result = [];

    foreach ($rawRows as $row) {
        /*
         * FT_INVT.DocEntry is the unique row identity. FT_INVT.DocNum is the
         * ScanPlus document identity shared by all rows scanned together.
         */
        $transferDocEntry = (int)($row['TransferDocEntry'] ?? 0);
        $transferLineNum = (int)($row['TransferLineNum'] ?? 0);
        $sourceDocumentKey = trim((string)($row['SourceDocumentKey'] ?? ''));
        $itemCode = trim((string)($row['ItemCode'] ?? ''));
        $lotNo = trim((string)($row['ReceivedLotNo'] ?? ''));
        $qty = is_numeric($row['ReceivedQty'] ?? null)
            ? abs((float)$row['ReceivedQty'])
            : 0.0;
        $receivedAt = sync_datetime_sort_key($row['ReceivedAt'] ?? '');

        if ($transferDocEntry <= 0
            || $sourceDocumentKey === ''
            || $itemCode === ''
            || $qty <= 0.0005
            || $receivedAt === '') {
            continue;
        }

        $key = $transferDocEntry . '|'
            . $transferLineNum . '|'
            . strtoupper($itemCode) . '|'
            . sync_normalize_lot($lotNo);

        if (!isset($result[$key])) {
            $result[$key] = [
                /*
                 * Compatibility names: downstream tables still call these
                 * SAPTransfer... columns, but the source is now FT_INVT.
                 */
                'transfer_doc_entry' => $transferDocEntry,
                'transfer_doc_num' => null,
                'transfer_line_num' => $transferLineNum,
                'source_document_key' => $sourceDocumentKey,
                'linked_request_line_id' => null,
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
            && strcmp($receivedAt, (string)$result[$key]['received_at']) >= 0) {
            $result[$key]['received_at'] = $receivedAt;
            $result[$key]['barcode_user'] = trim((string)($row['BarcodeUser'] ?? ''));
        }
    }

    return array_values($result);
}


/**
 * Read actual SAP Inventory Transfer postings for the same monthly ITR lines.
 *
 * This is the authoritative posted layer. A ScanPlus FT_INVT row is only staging
 * evidence; MATCHED/PARTIAL/LOT_MISMATCH statuses must be based on OWTR/WTR1 +
 * the destination-side IBT1 batch rows from the same calendar day.
 */
function sync_lookup_sap_posted_rows_by_itr_lines($erp, array $refs, int $lookbackDays = 45): array
{
    /*
     * V5 optimization:
     * - Build the exact recent issuance keys in PHP.
     * - Query SAP by recent OWTR date + BaseEntry only (usually one monthly ITR).
     * - Filter BaseLine + ItemCode against the recent issuance keys in PHP.
     *
     * This avoids hundreds of expensive dynamic UNION queries against WTR1/IBT1.
     */
    $validKeys = [];
    $docEntries = [];

    foreach ($refs as $ref) {
        $docEntry = (int)($ref['doc_entry'] ?? 0);
        $lineRaw = $ref['line_num'] ?? null;
        $itemCode = trim((string)($ref['item_code'] ?? ''));

        if ($docEntry <= 0 || $lineRaw === null || trim((string)$lineRaw) === '' || $itemCode === '') {
            continue;
        }

        $lineNum = (int)$lineRaw;
        $key = scanplus_key($docEntry, $lineNum, $itemCode);
        if ($key !== '') {
            $validKeys[$key] = true;
            $docEntries[$docEntry] = true;
        }
    }

    if (empty($validKeys) || empty($docEntries)) {
        return [];
    }

    $lookbackDays = max(1, min(180, $lookbackDays));
    $result = [];
    $docEntryChunks = array_chunk(array_keys($docEntries), 25);
    $chunkTotal = count($docEntryChunks);

    foreach ($docEntryChunks as $chunkIndex => $docChunk) {
        $placeholders = implode(',', array_fill(0, count($docChunk), '?'));
        $params = array_values($docChunk);
        $params[] = $lookbackDays;

        sync_log(sprintf(
            'SAP posting lookup chunk %d/%d: ITR DocEntries=%s',
            $chunkIndex + 1,
            $chunkTotal,
            implode(',', $docChunk)
        ));

        $rows = sync_fetch_all(
            $erp,
            "SELECT
                L.BaseEntry AS ITRDocEntry,
                L.BaseLine AS ITRLineNum,
                L.ItemCode,
                H.DocEntry AS TransferDocEntry,
                H.DocNum AS TransferDocNum,
                L.LineNum AS TransferLineNum,
                CAST(H.DocEntry AS NVARCHAR(100)) AS SourceDocumentKey,

                CASE
                    WHEN H.U_ScanDateTime IS NOT NULL THEN
                        DATEADD(
                            MINUTE,
                            ((ISNULL(TRY_CONVERT(INT, H.U_ScanTime), 0) / 100) * 60)
                              + (ISNULL(TRY_CONVERT(INT, H.U_ScanTime), 0) % 100),
                            CAST(CAST(H.U_ScanDateTime AS DATE) AS DATETIME)
                        )
                    ELSE
                        DATEADD(
                            SECOND,
                            ((ISNULL(H.CreateTS, 0) / 10000) * 3600)
                              + (((ISNULL(H.CreateTS, 0) / 100) % 100) * 60)
                              + (ISNULL(H.CreateTS, 0) % 100),
                            CAST(CAST(COALESCE(H.CreateDate, H.DocDate) AS DATE) AS DATETIME)
                        )
                END AS ReceivedAt,

                CAST('SAP OWTR' AS NVARCHAR(120)) AS BarcodeUser,
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(B.BatchNum AS NVARCHAR(80)))), ''), '') AS ReceivedLotNo,
                COALESCE(
                    SUM(CASE WHEN B.BatchNum IS NOT NULL THEN ABS(TRY_CONVERT(DECIMAL(18,3), B.Quantity)) END),
                    ABS(TRY_CONVERT(DECIMAL(18,3), L.Quantity)),
                    0
                ) AS ReceivedQty

             FROM OWTR H
             INNER JOIN WTR1 L
                ON L.DocEntry = H.DocEntry
             LEFT JOIN IBT1 B
                ON B.BaseType = 67
               AND B.BaseEntry = L.DocEntry
               AND B.BaseLinNum = L.LineNum
               AND B.ItemCode = L.ItemCode
               AND B.WhsCode = L.WhsCode

             WHERE ISNULL(H.CANCELED, 'N') = 'N'
               AND L.FromWhsCod = '01'
               AND L.WhsCode = 'CNC'
               AND L.BaseEntry IN ({$placeholders})
               AND COALESCE(H.U_ScanDateTime, H.DocDate, H.CreateDate) >= DATEADD(DAY, -?, CAST(GETDATE() AS DATE))

             GROUP BY
                L.BaseEntry,
                L.BaseLine,
                L.ItemCode,
                H.DocEntry,
                H.DocNum,
                L.LineNum,
                H.U_ScanDateTime,
                H.U_ScanTime,
                H.CreateDate,
                H.DocDate,
                H.CreateTS,
                L.Quantity,
                B.BatchNum

             ORDER BY
                ReceivedAt,
                H.DocEntry,
                L.LineNum,
                B.BatchNum
             OPTION (MAXDOP 1, RECOMPILE)",
            $params
        );

        foreach ($rows as $row) {
            $docEntry = (int)($row['ITRDocEntry'] ?? 0);
            $lineNum = (int)($row['ITRLineNum'] ?? 0);
            $itemCode = trim((string)($row['ItemCode'] ?? ''));
            $key = scanplus_key($docEntry, $lineNum, $itemCode);

            if ($key === '' || !isset($validKeys[$key])) {
                continue;
            }

            $qty = is_numeric($row['ReceivedQty'] ?? null) ? abs((float)$row['ReceivedQty']) : 0.0;
            $receivedAt = sync_datetime_sort_key($row['ReceivedAt'] ?? '');
            if ($qty <= 0.0005 || $receivedAt === '') {
                continue;
            }

            $result[] = [
                'transfer_doc_entry' => (int)($row['TransferDocEntry'] ?? 0),
                'transfer_doc_num' => isset($row['TransferDocNum']) ? (int)$row['TransferDocNum'] : null,
                'transfer_line_num' => (int)($row['TransferLineNum'] ?? 0),
                'source_document_key' => trim((string)($row['SourceDocumentKey'] ?? '')),
                'linked_request_line_id' => null,
                'doc_entry' => $docEntry,
                'line_num' => $lineNum,
                'item_code' => $itemCode,
                'received_lot_no' => trim((string)($row['ReceivedLotNo'] ?? '')),
                'received_qty' => $qty,
                'barcode_user' => 'SAP OWTR',
                'received_at' => $receivedAt,
                'itr_requested_qty' => null,
                'itr_open_qty' => null,
            ];
        }
    }

    return $result;
}

function sync_refs_from_issue_rows(array $issueRows): array
{
    $refs = [];
    $seen = [];

    foreach ($issueRows as $row) {
        $docEntry = (int)($row['ITRDocEntry'] ?? 0);
        $lineRaw = $row['ITRLineNum'] ?? null;
        $itemCode = trim((string)($row['ItemCode'] ?? ''));

        if ($docEntry <= 0 || $lineRaw === null || trim((string)$lineRaw) === '' || $itemCode === '') {
            continue;
        }

        $lineNum = (int)$lineRaw;
        $key = scanplus_key($docEntry, $lineNum, $itemCode);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $refs[] = [
            'doc_entry' => $docEntry,
            'line_num' => $lineNum,
            'item_code' => $itemCode,
        ];
    }

    return $refs;
}

/**
 * Allocate individual ScanPlus FT_INVT receipt quantities to separate local request lines.
 *
 * Boundary: exact monthly SAP ITR DocEntry + line + item + normalized GRPO lot.
 * Selection: match the complete SAP Inventory Transfer document to one local
 * request by its item, ITR line, GRPO lot, and quantity signature. The transfer
 * document is never distributed to whichever request happens to be newest.
 * Every assigned ScanPlus receipt row is persisted as an auditable source record.
 * Several ScanPlus receipt rows may accumulate into the same RequestLineID. The amount
 * assigned to a request line never exceeds its IssuedQty; the unfilled balance
 * remains pending for the next SAP scan.
 */
function sync_allocate_monthly_itr_transfers(array $refs, array $transferRows): array
{
    $refsByGroup = [];
    $requests = [];
    $requestKeyByLineId = [];

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

        $requestNo = trim((string)($ref['local_request_no'] ?? ''));
        $requestKey = $requestNo !== ''
            ? 'REQUEST|' . strtoupper($requestNo)
            : 'REQUEST_LINE|' . $requestLineId;

        $refsByGroup[$groupKey][$requestLineId] = $ref;
        $requestKeyByLineId[$requestLineId] = $requestKey;

        if (!isset($requests[$requestKey])) {
            $requests[$requestKey] = [
                'request_no' => $requestNo,
                'lines_by_group' => [],
                'line_ids' => [],
            ];
        }

        $requests[$requestKey]['lines_by_group'][$groupKey][$requestLineId] = $ref;
        $requests[$requestKey]['line_ids'][$requestLineId] = true;
    }

    $transfersByGroup = [];
    $groupScans = [];
    $transferDocuments = [];

    foreach ($transferRows as $transfer) {
        $groupKey = sync_monthly_itr_group_key(
            $transfer['doc_entry'] ?? 0,
            $transfer['line_num'] ?? null,
            $transfer['item_code'] ?? '',
            $transfer['received_lot_no'] ?? ''
        );

        if ($groupKey === '' || !isset($refsByGroup[$groupKey])) {
            continue;
        }

        $transferQty = max(0.0, (float)($transfer['received_qty'] ?? 0));

        if ($transferQty <= 0.0005) {
            continue;
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

        $groupScans[$groupKey]['received_qty'] += $transferQty;
        $groupScans[$groupKey]['transfer_count']++;

        $receivedAt = trim((string)($transfer['received_at'] ?? ''));

        if ($receivedAt !== ''
            && strcmp($receivedAt, (string)$groupScans[$groupKey]['received_at']) >= 0) {
            $groupScans[$groupKey]['received_at'] = $receivedAt;
            $groupScans[$groupKey]['barcode_user'] = trim((string)($transfer['barcode_user'] ?? ''));
        }

        $transferDocEntry = (int)($transfer['transfer_doc_entry'] ?? 0);
        $sourceDocumentKey = trim((string)($transfer['source_document_key'] ?? ''));

        /*
         * FT_INVT has one DocEntry per row, while DocNum identifies the complete
         * ScanPlus receiving document. Group by DocNum so all lines scanned in
         * the same 01 -> CNC transaction are evaluated as one document.
         */
        if ($sourceDocumentKey !== '') {
            $transferDocKey = 'SCANPLUS|' . strtoupper($sourceDocumentKey);
        } else {
            $transferDocKey = $transferDocEntry > 0
                ? 'DOC|' . $transferDocEntry
                : 'FALLBACK|'
                    . (int)($transfer['transfer_doc_num'] ?? 0) . '|'
                    . $receivedAt;
        }

        if (!isset($transferDocuments[$transferDocKey])) {
            $transferDocuments[$transferDocKey] = [
                'transfer_doc_entry' => $transferDocEntry,
                'transfer_doc_num' => isset($transfer['transfer_doc_num'])
                    ? (int)$transfer['transfer_doc_num']
                    : null,
                'source_document_key' => $sourceDocumentKey,
                'received_at' => $receivedAt,
                'rows' => [],
            ];
        }

        $transferDocuments[$transferDocKey]['rows'][] = $transfer;

        if ($receivedAt !== ''
            && strcmp($receivedAt, (string)$transferDocuments[$transferDocKey]['received_at']) > 0) {
            $transferDocuments[$transferDocKey]['received_at'] = $receivedAt;
        }
    }

    uasort($transferDocuments, static function (array $a, array $b): int {
        $timeCompare = sync_datetime_timestamp($a['received_at'] ?? '')
            <=> sync_datetime_timestamp($b['received_at'] ?? '');

        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return (int)($a['transfer_doc_entry'] ?? 0)
            <=> (int)($b['transfer_doc_entry'] ?? 0);
    });

    $lineAllocations = [];

    foreach ($refsByGroup as $groupRefs) {
        foreach ($groupRefs as $requestLineId => $ref) {
            $lineAllocations[(int)$requestLineId] = [
                'qty' => 0.0,
                'received_at' => '',
                'barcode_user' => '',
                'received_lot_no' => '',
                'transfer_doc_entries' => [],
                'source_transfers' => [],
            ];
        }
    }

    $unallocatedByGroup = [];
    $ambiguousByGroup = [];
    $ambiguousTransfers = [];
    $markAmbiguousTransfers = static function (
        array $unassignedRows,
        array $transferDocument,
        string $reason
    ) use (&$ambiguousByGroup, &$unallocatedByGroup, &$ambiguousTransfers): void {
        $candidateLineIds = [];
        $rows = [];

        foreach ($unassignedRows as $unassignedRow) {
            $transfer = $unassignedRow['transfer'] ?? null;

            if (!is_array($transfer)) {
                continue;
            }

            $rows[] = $transfer;

            foreach (($unassignedRow['candidate_line_ids'] ?? []) as $candidateLineId) {
                $candidateLineIds[(int)$candidateLineId] = true;
            }

            $groupKey = sync_monthly_itr_group_key(
                $transfer['doc_entry'] ?? 0,
                $transfer['line_num'] ?? null,
                $transfer['item_code'] ?? '',
                $transfer['received_lot_no'] ?? ''
            );
            $qty = max(0.0, (float)($transfer['received_qty'] ?? 0));
            $ambiguousByGroup[$groupKey] =
                (float)($ambiguousByGroup[$groupKey] ?? 0) + $qty;
            $unallocatedByGroup[$groupKey] =
                (float)($unallocatedByGroup[$groupKey] ?? 0) + $qty;
        }

        if (empty($rows)) {
            return;
        }

        $ambiguousTransfers[] = [
            'group_key' => 'TRANSFER_DOCUMENT',
            'transfer_doc_entry' => (int)($transferDocument['transfer_doc_entry'] ?? 0),
            'transfer_doc_num' => $transferDocument['transfer_doc_num'] ?? null,
            'transfer_line_num' => -1,
            'received_qty' => array_sum(array_map(
                static fn(array $row): float => max(0.0, (float)($row['received_qty'] ?? 0)),
                $rows
            )),
            'received_at' => (string)($transferDocument['received_at'] ?? ''),
            'candidate_request_line_ids' => array_keys($candidateLineIds),
            'reason' => $reason,
        ];
    };

    foreach ($transferDocuments as $transferDocument) {
        $documentRows = $transferDocument['rows'] ?? [];

        usort($documentRows, static function (array $a, array $b): int {
            $lineCompare = (int)($a['transfer_line_num'] ?? 0)
                <=> (int)($b['transfer_line_num'] ?? 0);

            if ($lineCompare !== 0) {
                return $lineCompare;
            }

            $itemCompare = strcmp(
                strtoupper(trim((string)($a['item_code'] ?? ''))),
                strtoupper(trim((string)($b['item_code'] ?? '')))
            );

            if ($itemCompare !== 0) {
                return $itemCompare;
            }

            return strcmp(
                sync_normalize_lot($a['received_lot_no'] ?? ''),
                sync_normalize_lot($b['received_lot_no'] ?? '')
            );
        });

        if (empty($documentRows)) {
            continue;
        }

        $linkedRequestKeys = [];

        foreach ($documentRows as $transfer) {
            $linkedRequestLineId = (int)($transfer['linked_request_line_id'] ?? 0);

            if ($linkedRequestLineId > 0
                && isset($requestKeyByLineId[$linkedRequestLineId])) {
                $linkedRequestKeys[$requestKeyByLineId[$linkedRequestLineId]] = true;
            }
        }

        if (count($linkedRequestKeys) > 1) {
            $fallbackResult = sync_allocate_transfer_rows_by_daily_match(
                $documentRows,
                $refsByGroup,
                $lineAllocations
            );
            $markAmbiguousTransfers(
                $fallbackResult['unassigned_rows'] ?? [],
                $transferDocument,
                'SCANPLUS_DOCUMENT_LINKS_MULTIPLE_LOCAL_REQUESTS'
            );
            continue;
        }

        $candidateRequestKeys = !empty($linkedRequestKeys)
            ? array_keys($linkedRequestKeys)
            : array_keys($requests);
        $candidatePlans = [];

        foreach ($candidateRequestKeys as $requestKey) {
            if (!isset($requests[$requestKey])) {
                continue;
            }

            $request = $requests[$requestKey];
            $assignments = [];
            $plannedQtyByLine = [];
            $exactQtyMatches = 0;
            $totalSlack = 0.0;
            $sameIssuedDateMatches = 0;
            $sameRequestedDateMatches = 0;
            $matchedGroups = [];
            $valid = true;

            foreach ($documentRows as $transferIndex => $transfer) {
                $groupKey = sync_monthly_itr_group_key(
                    $transfer['doc_entry'] ?? 0,
                    $transfer['line_num'] ?? null,
                    $transfer['item_code'] ?? '',
                    $transfer['received_lot_no'] ?? ''
                );
                $transferQty = max(0.0, (float)($transfer['received_qty'] ?? 0));
                $transferTimestamp = sync_datetime_timestamp($transfer['received_at'] ?? '');
                $transferDateKey = sync_datetime_date_key($transfer['received_at'] ?? '');
                $linkedRequestLineId = (int)($transfer['linked_request_line_id'] ?? 0);
                $requestLines = $request['lines_by_group'][$groupKey] ?? [];
                $eligibleLines = [];

                foreach ($requestLines as $requestLineId => $ref) {
                    $requestLineId = (int)$requestLineId;

                    if ($linkedRequestLineId > 0 && $requestLineId !== $linkedRequestLineId) {
                        continue;
                    }

                    $issuedQty = is_numeric($ref['local_issued_qty'] ?? null)
                        ? max(0.0, (float)$ref['local_issued_qty'])
                        : 0.0;
                    $alreadyAllocated = (float)($lineAllocations[$requestLineId]['qty'] ?? 0);
                    $plannedQty = (float)($plannedQtyByLine[$requestLineId] ?? 0);
                    $remaining = max(0.0, $issuedQty - $alreadyAllocated - $plannedQty);
                    $eventAt = $ref['local_issued_at']
                        ?? $ref['local_requested_at']
                        ?? '';
                    $eventTimestamp = sync_datetime_timestamp($eventAt);
                    $issuedDateKey = sync_datetime_date_key($ref['local_issued_at'] ?? '');
                    $requestedDateKey = sync_datetime_date_key($ref['local_requested_at'] ?? '');

                    if ($remaining + 0.0005 < $transferQty) {
                        continue;
                    }

                    if ($transferTimestamp > 0
                        && $eventTimestamp > 0
                        && $eventTimestamp > $transferTimestamp) {
                        continue;
                    }

                    $eligibleLines[$requestLineId] = [
                        'ref' => $ref,
                        'remaining' => $remaining,
                        'event_timestamp' => $eventTimestamp,
                        'same_issued_date' => $transferDateKey !== ''
                            && $issuedDateKey !== ''
                            && $transferDateKey === $issuedDateKey,
                        'same_requested_date' => $transferDateKey !== ''
                            && $requestedDateKey !== ''
                            && $transferDateKey === $requestedDateKey,
                    ];
                }

                if (empty($eligibleLines)) {
                    $valid = false;
                    break;
                }

                $selectedLine = null;

                if ($linkedRequestLineId > 0
                    && isset($eligibleLines[$linkedRequestLineId])) {
                    $selectedLine = $eligibleLines[$linkedRequestLineId];
                } elseif (count($eligibleLines) === 1) {
                    $selectedLine = array_values($eligibleLines)[0];
                } else {
                    $sameIssuedDateLines = array_filter(
                        $eligibleLines,
                        static fn(array $line): bool => (bool)$line['same_issued_date']
                    );

                    if (count($sameIssuedDateLines) === 1) {
                        $selectedLine = array_values($sameIssuedDateLines)[0];
                    }

                    $sameRequestedDateLines = $selectedLine === null
                        ? array_filter(
                            $eligibleLines,
                            static fn(array $line): bool => (bool)$line['same_requested_date']
                        )
                        : [];

                    if ($selectedLine === null && count($sameRequestedDateLines) === 1) {
                        $selectedLine = array_values($sameRequestedDateLines)[0];
                    }

                    $exactLines = $selectedLine === null ? array_filter(
                        $eligibleLines,
                        static fn(array $line): bool =>
                            abs((float)$line['remaining'] - $transferQty) <= 0.0005
                    ) : [];

                    if ($selectedLine === null && count($exactLines) === 1) {
                        $selectedLine = array_values($exactLines)[0];
                    }
                }

                if ($selectedLine === null) {
                    $valid = false;
                    break;
                }

                $selectedLineId = (int)($selectedLine['ref']['local_request_line_id'] ?? 0);

                if ($selectedLineId <= 0) {
                    $valid = false;
                    break;
                }

                $plannedQtyByLine[$selectedLineId] =
                    (float)($plannedQtyByLine[$selectedLineId] ?? 0) + $transferQty;

                $assignments[$transferIndex] = [
                    'request_line_id' => $selectedLineId,
                    'ref' => $selectedLine['ref'],
                    'remaining' => (float)$selectedLine['remaining'],
                    'group_key' => $groupKey,
                ];
                $matchedGroups[$groupKey] = true;
                $totalSlack += max(0.0, (float)$selectedLine['remaining'] - $transferQty);

                if (abs((float)$selectedLine['remaining'] - $transferQty) <= 0.0005) {
                    $exactQtyMatches++;
                }

                if ((bool)($selectedLine['same_issued_date'] ?? false)) {
                    $sameIssuedDateMatches++;
                }

                if ((bool)($selectedLine['same_requested_date'] ?? false)) {
                    $sameRequestedDateMatches++;
                }
            }

            if (!$valid || count($assignments) !== count($documentRows)) {
                continue;
            }

            $pendingGroups = [];

            foreach ($request['lines_by_group'] as $groupKey => $requestLines) {
                foreach ($requestLines as $requestLineId => $ref) {
                    $issuedQty = is_numeric($ref['local_issued_qty'] ?? null)
                        ? max(0.0, (float)$ref['local_issued_qty'])
                        : 0.0;
                    $alreadyAllocated = (float)($lineAllocations[(int)$requestLineId]['qty'] ?? 0);

                    if ($issuedQty - $alreadyAllocated > 0.0005) {
                        $pendingGroups[$groupKey] = true;
                        break;
                    }
                }
            }

            $documentGroups = [];

            foreach ($documentRows as $transfer) {
                $documentGroupKey = sync_monthly_itr_group_key(
                    $transfer['doc_entry'] ?? 0,
                    $transfer['line_num'] ?? null,
                    $transfer['item_code'] ?? '',
                    $transfer['received_lot_no'] ?? ''
                );

                if ($documentGroupKey !== '') {
                    $documentGroups[$documentGroupKey] = true;
                }
            }

            $exactDocumentSet = count($pendingGroups) === count($documentGroups)
                && empty(array_diff_key($pendingGroups, $documentGroups))
                && empty(array_diff_key($documentGroups, $pendingGroups));
            $extraPendingGroups = max(0, count($pendingGroups) - count($documentGroups));
            $linkedPriority = !empty($linkedRequestKeys) ? 1 : 0;

            $candidatePlans[$requestKey] = [
                'request_key' => $requestKey,
                'request_no' => (string)($request['request_no'] ?? ''),
                'assignments' => $assignments,
                'linked_priority' => $linkedPriority,
                'exact_document_set' => $exactDocumentSet ? 1 : 0,
                'same_issued_date_matches' => $sameIssuedDateMatches,
                'same_requested_date_matches' => $sameRequestedDateMatches,
                'exact_qty_matches' => $exactQtyMatches,
                'extra_pending_groups' => $extraPendingGroups,
                'total_slack' => $totalSlack,
            ];
        }

        if (empty($candidatePlans)) {
            $fallbackResult = sync_allocate_transfer_rows_by_daily_match(
                $documentRows,
                $refsByGroup,
                $lineAllocations
            );
            $markAmbiguousTransfers(
                $fallbackResult['unassigned_rows'] ?? [],
                $transferDocument,
                'NO_SINGLE_REQUEST_COVERS_ALL_SCANPLUS_DOCUMENT_LINES'
            );
            continue;
        }

        uasort($candidatePlans, static function (array $a, array $b): int {
            foreach ([
                ['linked_priority', true],
                ['exact_document_set', true],
                ['same_issued_date_matches', true],
                ['same_requested_date_matches', true],
                ['exact_qty_matches', true],
            ] as [$field, $descending]) {
                $comparison = (int)$a[$field] <=> (int)$b[$field];

                if ($comparison !== 0) {
                    return $descending ? -$comparison : $comparison;
                }
            }

            $extraComparison = (int)$a['extra_pending_groups']
                <=> (int)$b['extra_pending_groups'];

            if ($extraComparison !== 0) {
                return $extraComparison;
            }

            $slackComparison = (float)$a['total_slack']
                <=> (float)$b['total_slack'];

            if (abs($slackComparison) > 0) {
                return $slackComparison;
            }

            return strcmp((string)$a['request_key'], (string)$b['request_key']);
        });

        $rankedPlans = array_values($candidatePlans);
        $bestPlan = $rankedPlans[0];
        $bestScore = [
            (int)$bestPlan['linked_priority'],
            (int)$bestPlan['exact_document_set'],
            (int)$bestPlan['same_issued_date_matches'],
            (int)$bestPlan['same_requested_date_matches'],
            (int)$bestPlan['exact_qty_matches'],
            (int)$bestPlan['extra_pending_groups'],
            round((float)$bestPlan['total_slack'], 3),
        ];
        $equallyBestPlans = array_filter(
            $rankedPlans,
            static function (array $plan) use ($bestScore): bool {
                return [
                    (int)$plan['linked_priority'],
                    (int)$plan['exact_document_set'],
                    (int)$plan['same_issued_date_matches'],
                    (int)$plan['same_requested_date_matches'],
                    (int)$plan['exact_qty_matches'],
                    (int)$plan['extra_pending_groups'],
                    round((float)$plan['total_slack'], 3),
                ] === $bestScore;
            }
        );

        if (count($equallyBestPlans) !== 1) {
            $fallbackResult = sync_allocate_transfer_rows_by_daily_match(
                $documentRows,
                $refsByGroup,
                $lineAllocations
            );
            $markAmbiguousTransfers(
                $fallbackResult['unassigned_rows'] ?? [],
                $transferDocument,
                'MULTIPLE_REQUESTS_HAVE_THE_SAME_SCANPLUS_DOCUMENT_SIGNATURE'
            );
            continue;
        }

        $selectedPlan = array_values($equallyBestPlans)[0];
        $matchMethod = (int)$selectedPlan['linked_priority'] === 1
            ? 'SAP_UDF_REQUEST_LINE_ID'
            : ((int)$selectedPlan['exact_document_set'] === 1
                ? 'SCANPLUS_DOCUMENT_EXACT_REQUEST_SIGNATURE'
                : 'SCANPLUS_DOCUMENT_BEST_REQUEST_SIGNATURE');

        foreach ($documentRows as $transferIndex => $transfer) {
            if (!isset($selectedPlan['assignments'][$transferIndex])) {
                continue;
            }

            $assignment = $selectedPlan['assignments'][$transferIndex];
            $requestLineId = (int)$assignment['request_line_id'];
            $allocatedQty = max(0.0, (float)($transfer['received_qty'] ?? 0));

            if ($requestLineId <= 0 || $allocatedQty <= 0.0005) {
                continue;
            }

            $lineAllocations[$requestLineId]['qty'] += $allocatedQty;
            $lineAllocations[$requestLineId]['received_lot_no'] = trim(
                (string)($transfer['received_lot_no'] ?? '')
            );

            $transferDocEntry = (int)($transfer['transfer_doc_entry'] ?? 0);
            $transferLineNum = (int)($transfer['transfer_line_num'] ?? 0);
            $transferSourceKey = $transferDocEntry . '|'
                . $transferLineNum . '|'
                . strtoupper(trim((string)($transfer['item_code'] ?? ''))) . '|'
                . sync_normalize_lot($transfer['received_lot_no'] ?? '');

            $lineAllocations[$requestLineId]['transfer_doc_entries'][$transferDocEntry] = true;
            $lineAllocations[$requestLineId]['source_transfers'][$transferSourceKey] = [
                'transfer_doc_entry' => $transferDocEntry,
                'transfer_doc_num' => $transfer['transfer_doc_num'] ?? null,
                'transfer_line_num' => $transferLineNum,
                'item_code' => trim((string)($transfer['item_code'] ?? '')),
                'received_lot_no' => trim((string)($transfer['received_lot_no'] ?? '')),
                'allocated_qty' => $allocatedQty,
                'received_at' => trim((string)($transfer['received_at'] ?? '')),
                'barcode_user' => trim((string)($transfer['barcode_user'] ?? '')),
                'match_method' => $matchMethod,
            ];

            $receivedAt = trim((string)($transfer['received_at'] ?? ''));

            if ($receivedAt !== ''
                && strcmp($receivedAt, (string)$lineAllocations[$requestLineId]['received_at']) >= 0) {
                $lineAllocations[$requestLineId]['received_at'] = $receivedAt;
                $lineAllocations[$requestLineId]['barcode_user'] = trim(
                    (string)($transfer['barcode_user'] ?? '')
                );
            }
        }
    }

    return [
        'line_allocations' => $lineAllocations,
        'group_scans' => $groupScans,
        'unallocated_by_group' => $unallocatedByGroup,
        'ambiguous_by_group' => $ambiguousByGroup,
        'ambiguous_transfers' => $ambiguousTransfers,
    ];
}


/**
 * Exact TransactionID -> FT_INVT allocation audit table.
 * A request line can contain several issuance transactions and several lots.
 */
function sync_ensure_issue_transaction_receive_allocation($conn): bool
{
    sync_exec(
        $conn,
        "IF OBJECT_ID('dbo.WarehouseIssueTransactionReceiveAllocation', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.WarehouseIssueTransactionReceiveAllocation (
                AllocationID BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                TransactionID BIGINT NOT NULL,
                RequestLineID INT NULL,
                ITRDocEntry INT NOT NULL,
                ITRLineNum INT NULL,
                ItemCode NVARCHAR(50) NOT NULL,
                IssuedLotNo NVARCHAR(80) NULL,
                ReceivedLotNo NVARCHAR(80) NULL,
                AllocatedQty DECIMAL(18,3) NOT NULL,
                LotMatch BIT NOT NULL,
                FTDocEntry INT NOT NULL,
                FTLineId INT NOT NULL,
                SourceDocumentKey NVARCHAR(100) NULL,
                ReceivedAt DATETIME NULL,
                BarcodeUser NVARCHAR(120) NULL,
                MatchMethod NVARCHAR(80) NOT NULL,
                LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
            );
         END"
    );

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1 FROM sys.indexes
            WHERE name = 'UX_WITRA_TransactionSource'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueTransactionReceiveAllocation')
         )
         BEGIN
            CREATE UNIQUE INDEX UX_WITRA_TransactionSource
            ON dbo.WarehouseIssueTransactionReceiveAllocation
            (TransactionID, FTDocEntry, FTLineId, ReceivedLotNo);
         END"
    );

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1 FROM sys.indexes
            WHERE name = 'IX_WITRA_RequestLine'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueTransactionReceiveAllocation')
         )
         BEGIN
            CREATE INDEX IX_WITRA_RequestLine
            ON dbo.WarehouseIssueTransactionReceiveAllocation
            (RequestLineID, TransactionID, ReceivedAt);
         END"
    );

    return sync_has_table($conn, 'WarehouseIssueTransactionReceiveAllocation');
}

function sync_load_issue_transactions_for_daily_allocation($conn, array $refs, int $lookbackDays): array
{
    if (!sync_has_table($conn, 'IssuanceTransactions')
        || !sync_has_column($conn, 'IssuanceTransactions', 'TransactionID')
        || !sync_has_column($conn, 'IssuanceTransactions', 'ITRDocEntry')
        || !sync_has_column($conn, 'IssuanceTransactions', 'ITRLineNum')
        || !sync_has_column($conn, 'IssuanceTransactions', 'ItemCode')
        || !sync_has_column($conn, 'IssuanceTransactions', 'Quantity')
        || !sync_has_column($conn, 'IssuanceTransactions', 'IssuedAt')) {
        return [];
    }

    $tuples = [];

    foreach ($refs as $ref) {
        $docEntry = (int)($ref['doc_entry'] ?? 0);
        $lineRaw = $ref['line_num'] ?? null;
        $itemCode = trim((string)($ref['item_code'] ?? ''));

        if ($docEntry <= 0 || $lineRaw === null || trim((string)$lineRaw) === '' || $itemCode === '') {
            continue;
        }

        $lineNum = (int)$lineRaw;
        $key = scanplus_key($docEntry, $lineNum, $itemCode);
        if ($key !== '') {
            $tuples[$key] = [$docEntry, $lineNum, $itemCode];
        }
    }

    if (empty($tuples)) {
        return [];
    }

    $hasRequestLineId = sync_has_column($conn, 'IssuanceTransactions', 'IssueRequestLineID');
    $hasLot = sync_has_column($conn, 'IssuanceTransactions', 'LotNo');
    $requestLineExpr = $hasRequestLineId ? 'IT.IssueRequestLineID' : 'CAST(NULL AS INT)';
    $lotExpr = $hasLot ? 'IT.LotNo' : "CAST('' AS NVARCHAR(80))";
    $rows = [];

    foreach (array_chunk(array_values($tuples), 40) as $tupleChunk) {
        $parts = [];
        $params = [];

        foreach ($tupleChunk as $tuple) {
            $parts[] = '(IT.ITRDocEntry = ? AND IT.ITRLineNum = ? AND IT.ItemCode = ?)';
            array_push($params, $tuple[0], $tuple[1], $tuple[2]);
        }

        $params[] = max(1, min(180, $lookbackDays));

        $chunkRows = sync_fetch_all(
            $conn,
            "SELECT
                IT.TransactionID,
                {$requestLineExpr} AS RequestLineID,
                IT.ITRDocEntry,
                IT.ITRLineNum,
                IT.ItemCode,
                {$lotExpr} AS LotNo,
                TRY_CONVERT(DECIMAL(18,3), IT.Quantity) AS Quantity,
                IT.IssuedAt
             FROM dbo.IssuanceTransactions IT
             WHERE (" . implode(' OR ', $parts) . ")
               AND IT.IssuedAt >= DATEADD(DAY, -?, GETDATE())
               AND ISNULL(TRY_CONVERT(DECIMAL(18,3), IT.Quantity), 0) > 0
             ORDER BY IT.IssuedAt, IT.TransactionID",
            $params
        );

        foreach ($chunkRows as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function sync_daily_base_key($dateKey, $docEntry, $lineNum, $itemCode): string
{
    $dateKey = trim((string)$dateKey);
    $itemCode = strtoupper(trim((string)$itemCode));

    if ($dateKey === '' || (int)$docEntry <= 0 || $itemCode === '') {
        return '';
    }

    return $dateKey . '|'
        . (int)$docEntry . '|'
        . (($lineNum === null || $lineNum === '') ? '-1' : (int)$lineNum) . '|'
        . $itemCode;
}

function sync_daily_lot_key(string $baseKey, $lotNo): string
{
    return $baseKey === '' ? '' : $baseKey . '|LOT|' . sync_normalize_lot($lotNo);
}

function sync_build_raw_group_scans(array $transferRows): array
{
    $groupScans = [];

    foreach ($transferRows as $transfer) {
        $groupKey = sync_monthly_itr_group_key(
            $transfer['doc_entry'] ?? 0,
            $transfer['line_num'] ?? null,
            $transfer['item_code'] ?? '',
            $transfer['received_lot_no'] ?? ''
        );
        $qty = max(0.0, (float)($transfer['received_qty'] ?? 0));

        if ($groupKey === '' || $qty <= 0.0005) {
            continue;
        }

        if (!isset($groupScans[$groupKey])) {
            $groupScans[$groupKey] = [
                'received_qty' => 0.0,
                'received_lot_no' => trim((string)($transfer['received_lot_no'] ?? '')),
                'barcode_user' => trim((string)($transfer['barcode_user'] ?? '')),
                'received_at' => trim((string)($transfer['received_at'] ?? '')),
                'scan_status' => 'SCANPLUS_RECEIVED',
                'itr_requested_qty' => $transfer['itr_requested_qty'] ?? null,
                'itr_open_qty' => $transfer['itr_open_qty'] ?? null,
                'transfer_count' => 0,
            ];
        }

        $groupScans[$groupKey]['received_qty'] += $qty;
        $groupScans[$groupKey]['transfer_count']++;
        $receivedAt = trim((string)($transfer['received_at'] ?? ''));

        if ($receivedAt !== '' && strcmp($receivedAt, (string)$groupScans[$groupKey]['received_at']) >= 0) {
            $groupScans[$groupKey]['received_at'] = $receivedAt;
            $groupScans[$groupKey]['barcode_user'] = trim((string)($transfer['barcode_user'] ?? ''));
        }
    }

    return $groupScans;
}

function sync_add_daily_allocation_chunk(
    array &$lineAllocations,
    array &$transactionAllocations,
    array &$issueState,
    array &$receiptState,
    int $issueIndex,
    int $receiptIndex,
    float $qty,
    bool $lotMatch,
    string $matchPrefix = 'SAME_DAY'
): void {
    if ($qty <= 0.0005) {
        return;
    }

    $issue = $issueState[$issueIndex]['row'];
    $receipt = $receiptState[$receiptIndex]['row'];
    $requestLineId = (int)($issue['RequestLineID'] ?? 0);
    $transactionId = (int)($issue['TransactionID'] ?? 0);
    $issuedLot = trim((string)($issue['LotNo'] ?? ''));
    $receivedLot = trim((string)($receipt['received_lot_no'] ?? ''));
    $receivedAt = trim((string)($receipt['received_at'] ?? ''));

    if ($requestLineId > 0) {
        if (!isset($lineAllocations[$requestLineId])) {
            $lineAllocations[$requestLineId] = [
                'issued_qty' => 0.0,
                'qty' => 0.0,
                'exact_lot_qty' => 0.0,
                'lot_mismatch_qty' => 0.0,
                'raw_daily_received_qty' => 0.0,
                'received_at' => '',
                'barcode_user' => '',
                'received_lots' => [],
                'source_transfers' => [],
                'daily_base_keys' => [],
            ];
        }

        $lineAllocations[$requestLineId]['qty'] += $qty;
        $lineAllocations[$requestLineId][$lotMatch ? 'exact_lot_qty' : 'lot_mismatch_qty'] += $qty;

        if ($receivedLot !== '') {
            $lotKey = sync_normalize_lot($receivedLot);
            $lineAllocations[$requestLineId]['received_lots'][$lotKey !== '' ? $lotKey : $receivedLot] = $receivedLot;
        }

        if ($receivedAt !== '' && strcmp($receivedAt, (string)$lineAllocations[$requestLineId]['received_at']) >= 0) {
            $lineAllocations[$requestLineId]['received_at'] = $receivedAt;
            $lineAllocations[$requestLineId]['barcode_user'] = trim((string)($receipt['barcode_user'] ?? ''));
        }

        $sourceKey = (int)($receipt['transfer_doc_entry'] ?? 0) . '|'
            . (int)($receipt['transfer_line_num'] ?? 0) . '|'
            . strtoupper(trim((string)($receipt['item_code'] ?? ''))) . '|'
            . sync_normalize_lot($receivedLot) . '|ISSUELOT|' . sync_normalize_lot($issuedLot);

        if (!isset($lineAllocations[$requestLineId]['source_transfers'][$sourceKey])) {
            $lineAllocations[$requestLineId]['source_transfers'][$sourceKey] = [
                'transfer_doc_entry' => (int)($receipt['transfer_doc_entry'] ?? 0),
                'transfer_doc_num' => $receipt['transfer_doc_num'] ?? null,
                'transfer_line_num' => (int)($receipt['transfer_line_num'] ?? 0),
                'item_code' => trim((string)($receipt['item_code'] ?? '')),
                'issued_lot_no' => $issuedLot,
                'received_lot_no' => $receivedLot,
                'allocated_qty' => 0.0,
                'received_at' => $receivedAt,
                'barcode_user' => trim((string)($receipt['barcode_user'] ?? '')),
                'match_method' => $lotMatch ? $matchPrefix . '_EXACT_LOT_FIFO' : $matchPrefix . '_LOT_MISMATCH_FIFO',
            ];
        }

        $lineAllocations[$requestLineId]['source_transfers'][$sourceKey]['allocated_qty'] += $qty;
    }

    $transactionAllocations[] = [
        'transaction_id' => $transactionId,
        'request_line_id' => $requestLineId > 0 ? $requestLineId : null,
        'itr_doc_entry' => (int)($issue['ITRDocEntry'] ?? 0),
        'itr_line_num' => $issue['ITRLineNum'] ?? null,
        'item_code' => trim((string)($issue['ItemCode'] ?? '')),
        'issued_lot_no' => $issuedLot,
        'received_lot_no' => $receivedLot,
        'allocated_qty' => $qty,
        'lot_match' => $lotMatch ? 1 : 0,
        'ft_doc_entry' => (int)($receipt['transfer_doc_entry'] ?? 0),
        'ft_line_id' => (int)($receipt['transfer_line_num'] ?? 0),
        'source_document_key' => trim((string)($receipt['source_document_key'] ?? '')),
        'received_at' => $receivedAt,
        'barcode_user' => trim((string)($receipt['barcode_user'] ?? '')),
        'match_method' => $lotMatch ? $matchPrefix . '_EXACT_LOT_FIFO' : $matchPrefix . '_LOT_MISMATCH_FIFO',
    ];

    $issueState[$issueIndex]['remaining'] = max(0.0, $issueState[$issueIndex]['remaining'] - $qty);
    $receiptState[$receiptIndex]['remaining'] = max(0.0, $receiptState[$receiptIndex]['remaining'] - $qty);
}

/**
 * Business rule:
 *   1) Same calendar date is mandatory.
 *   2) Same ITR DocEntry + line + item is mandatory.
 *   3) Exact lot is allocated first.
 *   4) Remaining same-day quantity is allocated as LOT_MISMATCH.
 *   5) Nothing crosses to another date even if the monthly ITR/lot is reused.
 */
function sync_allocate_daily_issue_receipts(array $issueRows, array $transferRows, string $matchPrefix = 'SAME_DAY'): array
{
    $issueState = [];
    $receiptState = [];
    $issuesByBase = [];
    $issuesByLot = [];
    $receiptsByBase = [];
    $receiptsByLot = [];
    $lineAllocations = [];
    $transactionAllocations = [];
    $dailyTotals = [];

    foreach ($issueRows as $row) {
        $qty = max(0.0, (float)($row['Quantity'] ?? 0));
        $dateKey = sync_datetime_date_key($row['IssuedAt'] ?? '');
        $baseKey = sync_daily_base_key($dateKey, $row['ITRDocEntry'] ?? 0, $row['ITRLineNum'] ?? null, $row['ItemCode'] ?? '');

        if ($qty <= 0.0005 || $baseKey === '') {
            continue;
        }

        $index = count($issueState);
        $issueState[] = ['row' => $row, 'remaining' => $qty];
        $issuesByBase[$baseKey][] = $index;
        $issuesByLot[sync_daily_lot_key($baseKey, $row['LotNo'] ?? '')][] = $index;
        $dailyTotals[$baseKey]['issued'] = (float)($dailyTotals[$baseKey]['issued'] ?? 0) + $qty;
        $dailyTotals[$baseKey]['received'] = (float)($dailyTotals[$baseKey]['received'] ?? 0);

        $requestLineId = (int)($row['RequestLineID'] ?? 0);
        if ($requestLineId > 0) {
            if (!isset($lineAllocations[$requestLineId])) {
                $lineAllocations[$requestLineId] = [
                    'issued_qty' => 0.0,
                    'qty' => 0.0,
                    'exact_lot_qty' => 0.0,
                    'lot_mismatch_qty' => 0.0,
                    'raw_daily_received_qty' => 0.0,
                    'received_at' => '',
                    'barcode_user' => '',
                    'received_lots' => [],
                    'source_transfers' => [],
                    'daily_base_keys' => [],
                ];
            }
            $lineAllocations[$requestLineId]['issued_qty'] += $qty;
            $lineAllocations[$requestLineId]['daily_base_keys'][$baseKey] = true;
        }
    }

    foreach ($transferRows as $row) {
        $qty = max(0.0, (float)($row['received_qty'] ?? 0));
        $dateKey = sync_datetime_date_key($row['received_at'] ?? '');
        $baseKey = sync_daily_base_key($dateKey, $row['doc_entry'] ?? 0, $row['line_num'] ?? null, $row['item_code'] ?? '');

        if ($qty <= 0.0005 || $baseKey === '') {
            continue;
        }

        $index = count($receiptState);
        $receiptState[] = ['row' => $row, 'remaining' => $qty];
        $receiptsByBase[$baseKey][] = $index;
        $receiptsByLot[sync_daily_lot_key($baseKey, $row['received_lot_no'] ?? '')][] = $index;
        $dailyTotals[$baseKey]['issued'] = (float)($dailyTotals[$baseKey]['issued'] ?? 0);
        $dailyTotals[$baseKey]['received'] = (float)($dailyTotals[$baseKey]['received'] ?? 0) + $qty;
    }

    $sortIssueIndices = static function (array &$indices) use (&$issueState): void {
        usort($indices, static function (int $a, int $b) use (&$issueState): int {
            $aTime = sync_datetime_timestamp($issueState[$a]['row']['IssuedAt'] ?? '');
            $bTime = sync_datetime_timestamp($issueState[$b]['row']['IssuedAt'] ?? '');
            if ($aTime !== $bTime) {
                return $aTime <=> $bTime;
            }
            return (int)($issueState[$a]['row']['TransactionID'] ?? 0)
                <=> (int)($issueState[$b]['row']['TransactionID'] ?? 0);
        });
    };

    $sortReceiptIndices = static function (array &$indices) use (&$receiptState): void {
        usort($indices, static function (int $a, int $b) use (&$receiptState): int {
            $aTime = sync_datetime_timestamp($receiptState[$a]['row']['received_at'] ?? '');
            $bTime = sync_datetime_timestamp($receiptState[$b]['row']['received_at'] ?? '');
            if ($aTime !== $bTime) {
                return $aTime <=> $bTime;
            }
            $docCompare = (int)($receiptState[$a]['row']['transfer_doc_entry'] ?? 0)
                <=> (int)($receiptState[$b]['row']['transfer_doc_entry'] ?? 0);
            if ($docCompare !== 0) {
                return $docCompare;
            }
            return (int)($receiptState[$a]['row']['transfer_line_num'] ?? 0)
                <=> (int)($receiptState[$b]['row']['transfer_line_num'] ?? 0);
        });
    };

    /* Pass 1: exact lot. */
    foreach ($issuesByLot as $lotKey => $issueIndices) {
        $receiptIndices = $receiptsByLot[$lotKey] ?? [];
        if (empty($receiptIndices)) {
            continue;
        }

        $sortIssueIndices($issueIndices);
        $sortReceiptIndices($receiptIndices);
        $i = 0;
        $r = 0;

        while ($i < count($issueIndices) && $r < count($receiptIndices)) {
            $ii = $issueIndices[$i];
            $ri = $receiptIndices[$r];

            if ($issueState[$ii]['remaining'] <= 0.0005) {
                $i++;
                continue;
            }
            if ($receiptState[$ri]['remaining'] <= 0.0005) {
                $r++;
                continue;
            }

            $qty = min($issueState[$ii]['remaining'], $receiptState[$ri]['remaining']);
            sync_add_daily_allocation_chunk($lineAllocations, $transactionAllocations, $issueState, $receiptState, $ii, $ri, $qty, true, $matchPrefix);
        }
    }

    /* Pass 2: same-day quantity is present, but under another lot. */
    foreach ($issuesByBase as $baseKey => $issueIndices) {
        $receiptIndices = $receiptsByBase[$baseKey] ?? [];
        if (empty($receiptIndices)) {
            continue;
        }

        $issueIndices = array_values(array_filter($issueIndices, static fn(int $idx): bool => $issueState[$idx]['remaining'] > 0.0005));
        $receiptIndices = array_values(array_filter($receiptIndices, static fn(int $idx): bool => $receiptState[$idx]['remaining'] > 0.0005));

        if (empty($issueIndices) || empty($receiptIndices)) {
            continue;
        }

        $sortIssueIndices($issueIndices);
        $sortReceiptIndices($receiptIndices);
        $i = 0;
        $r = 0;

        while ($i < count($issueIndices) && $r < count($receiptIndices)) {
            $ii = $issueIndices[$i];
            $ri = $receiptIndices[$r];

            if ($issueState[$ii]['remaining'] <= 0.0005) {
                $i++;
                continue;
            }
            if ($receiptState[$ri]['remaining'] <= 0.0005) {
                $r++;
                continue;
            }

            $qty = min($issueState[$ii]['remaining'], $receiptState[$ri]['remaining']);
            $issueLot = sync_normalize_lot($issueState[$ii]['row']['LotNo'] ?? '');
            $receiptLot = sync_normalize_lot($receiptState[$ri]['row']['received_lot_no'] ?? '');
            $lotMatch = $issueLot !== '' && $issueLot === $receiptLot;
            sync_add_daily_allocation_chunk($lineAllocations, $transactionAllocations, $issueState, $receiptState, $ii, $ri, $qty, $lotMatch, $matchPrefix);
        }
    }

    foreach ($lineAllocations as &$allocation) {
        $rawDaily = 0.0;
        foreach (array_keys($allocation['daily_base_keys'] ?? []) as $baseKey) {
            $rawDaily += (float)($dailyTotals[$baseKey]['received'] ?? 0);
        }
        $allocation['raw_daily_received_qty'] = $rawDaily;
        $allocation['received_lot_no'] = implode(', ', array_values($allocation['received_lots'] ?? []));
        $allocation['daily_base_keys'] = array_values(array_keys($allocation['daily_base_keys'] ?? []));
        unset($allocation['received_lots']);
    }
    unset($allocation);

    $unallocatedReceipts = [];
    foreach ($receiptState as $state) {
        if ($state['remaining'] > 0.0005) {
            $row = $state['row'];
            $row['unallocated_qty'] = $state['remaining'];
            $unallocatedReceipts[] = $row;
        }
    }

    $pendingIssues = [];
    foreach ($issueState as $state) {
        if ($state['remaining'] > 0.0005) {
            $row = $state['row'];
            $row['pending_qty'] = $state['remaining'];
            $pendingIssues[] = $row;
        }
    }

    return [
        'line_allocations' => $lineAllocations,
        'transaction_allocations' => $transactionAllocations,
        'daily_totals' => $dailyTotals,
        'unallocated_receipts' => $unallocatedReceipts,
        'pending_issues' => $pendingIssues,
    ];
}

function sync_replace_issue_transaction_receive_allocations($conn, array $issueRows, array $allocations): void
{
    if (!sync_has_table($conn, 'WarehouseIssueTransactionReceiveAllocation')) {
        return;
    }

    $transactionIds = [];
    foreach ($issueRows as $row) {
        $transactionId = (int)($row['TransactionID'] ?? 0);
        if ($transactionId > 0) {
            $transactionIds[$transactionId] = true;
        }
    }

    foreach (array_chunk(array_keys($transactionIds), 500) as $idChunk) {
        if (empty($idChunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
        sync_exec($conn, "DELETE FROM dbo.WarehouseIssueTransactionReceiveAllocation WHERE TransactionID IN ({$placeholders})", array_values($idChunk));
    }

    foreach ($allocations as $a) {
        if ((int)($a['transaction_id'] ?? 0) <= 0 || (float)($a['allocated_qty'] ?? 0) <= 0.0005) {
            continue;
        }

        sync_exec(
            $conn,
            "INSERT INTO dbo.WarehouseIssueTransactionReceiveAllocation
            (
                TransactionID, RequestLineID, ITRDocEntry, ITRLineNum, ItemCode,
                IssuedLotNo, ReceivedLotNo, AllocatedQty, LotMatch,
                FTDocEntry, FTLineId, SourceDocumentKey, ReceivedAt,
                BarcodeUser, MatchMethod, LastSyncedAt
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())",
            [
                (int)$a['transaction_id'],
                $a['request_line_id'] ?? null,
                (int)$a['itr_doc_entry'],
                $a['itr_line_num'] ?? null,
                $a['item_code'],
                $a['issued_lot_no'] ?? null,
                $a['received_lot_no'] ?? null,
                (float)$a['allocated_qty'],
                (int)($a['lot_match'] ?? 0),
                (int)$a['ft_doc_entry'],
                (int)$a['ft_line_id'],
                $a['source_document_key'] ?? null,
                $a['received_at'] ?? null,
                $a['barcode_user'] ?? null,
                $a['match_method'] ?? 'SAME_DAY_FIFO',
            ]
        );
    }
}


/**
 * Exact TransactionID -> SAP OWTR allocation audit table.
 * V6 persists the actual SAP posting FIFO separately from FT_INVT staging and preserves the real ScanPlus receiver.
 */
function sync_ensure_issue_transaction_sap_posting_allocation($conn): bool
{
    sync_exec(
        $conn,
        "IF OBJECT_ID('dbo.WarehouseIssueTransactionSapPostingAllocation', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.WarehouseIssueTransactionSapPostingAllocation (
                AllocationID BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                TransactionID BIGINT NOT NULL,
                RequestLineID INT NULL,
                ITRDocEntry INT NOT NULL,
                ITRLineNum INT NULL,
                ItemCode NVARCHAR(50) NOT NULL,
                IssuedLotNo NVARCHAR(80) NULL,
                SAPBatchNo NVARCHAR(80) NULL,
                AllocatedQty DECIMAL(18,3) NOT NULL,
                LotMatch BIT NOT NULL,
                SAPTransferDocEntry INT NOT NULL,
                SAPTransferLineNum INT NOT NULL,
                SourceDocumentKey NVARCHAR(100) NULL,
                PostedAt DATETIME NULL,
                MatchMethod NVARCHAR(80) NOT NULL,
                LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
            );
         END"
    );

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1 FROM sys.indexes
            WHERE name = 'UX_WITSAPA_TransactionSource'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueTransactionSapPostingAllocation')
         )
         BEGIN
            CREATE UNIQUE INDEX UX_WITSAPA_TransactionSource
            ON dbo.WarehouseIssueTransactionSapPostingAllocation
            (TransactionID, SAPTransferDocEntry, SAPTransferLineNum, SAPBatchNo);
         END"
    );

    sync_exec(
        $conn,
        "IF NOT EXISTS (
            SELECT 1 FROM sys.indexes
            WHERE name = 'IX_WITSAPA_RequestLine'
              AND object_id = OBJECT_ID('dbo.WarehouseIssueTransactionSapPostingAllocation')
         )
         BEGIN
            CREATE INDEX IX_WITSAPA_RequestLine
            ON dbo.WarehouseIssueTransactionSapPostingAllocation
            (RequestLineID, TransactionID, PostedAt);
         END"
    );

    return sync_has_table($conn, 'WarehouseIssueTransactionSapPostingAllocation');
}

function sync_replace_issue_transaction_sap_posting_allocations($conn, array $issueRows, array $allocations): void
{
    if (!sync_has_table($conn, 'WarehouseIssueTransactionSapPostingAllocation')) {
        return;
    }

    $transactionIds = [];
    foreach ($issueRows as $row) {
        $transactionId = (int)($row['TransactionID'] ?? 0);
        if ($transactionId > 0) {
            $transactionIds[$transactionId] = true;
        }
    }

    foreach (array_chunk(array_keys($transactionIds), 500) as $idChunk) {
        if (empty($idChunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
        sync_exec(
            $conn,
            "DELETE FROM dbo.WarehouseIssueTransactionSapPostingAllocation WHERE TransactionID IN ({$placeholders})",
            array_values($idChunk)
        );
    }

    foreach ($allocations as $a) {
        if ((int)($a['transaction_id'] ?? 0) <= 0 || (float)($a['allocated_qty'] ?? 0) <= 0.0005) {
            continue;
        }

        sync_exec(
            $conn,
            "INSERT INTO dbo.WarehouseIssueTransactionSapPostingAllocation
            (
                TransactionID, RequestLineID, ITRDocEntry, ITRLineNum, ItemCode,
                IssuedLotNo, SAPBatchNo, AllocatedQty, LotMatch,
                SAPTransferDocEntry, SAPTransferLineNum, SourceDocumentKey,
                PostedAt, MatchMethod, LastSyncedAt
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())",
            [
                (int)$a['transaction_id'],
                $a['request_line_id'] ?? null,
                (int)$a['itr_doc_entry'],
                $a['itr_line_num'] ?? null,
                $a['item_code'],
                $a['issued_lot_no'] ?? null,
                $a['received_lot_no'] ?? null,
                (float)$a['allocated_qty'],
                (int)($a['lot_match'] ?? 0),
                (int)($a['ft_doc_entry'] ?? 0),
                (int)($a['ft_line_id'] ?? 0),
                $a['source_document_key'] ?? null,
                $a['received_at'] ?? null,
                $a['match_method'] ?? 'SAP_SAME_DAY_FIFO',
            ]
        );
    }
}

$whp = null;
$syncId = null;
$updated = 0;
$matched = 0;
$missing = 0;
$verifyChecked = 0;
$verifyMissingInScanPlus = 0;
$verifyScannedNotPosted = 0;
$verifyGroupPartialPosted = 0;
$verifyUnallocatedDailyScan = 0;
$verifyUnverifiedDate = 0;
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
    sync_ensure_request_line_receive_allocation($whp);
    sync_ensure_issue_transaction_receive_allocation($whp);
    sync_ensure_issue_transaction_sap_posting_allocation($whp);

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

        $requestScopeSql = $requestIssueApply !== ''
            ? 'AND (H.RequestedAt >= DATEADD(DAY, -?, GETDATE()) OR IX.LatestIssuedAt >= DATEADD(DAY, -?, GETDATE()))'
            : 'AND H.RequestedAt >= DATEADD(DAY, -?, GETDATE())';
        $requestScopeParams = $requestIssueApply !== ''
            ? [$lookbackDays, $lookbackDays]
            : [$lookbackDays];

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
               {$requestScopeSql}
             ORDER BY H.RequestedAt DESC, L.RequestLineID DESC",
            $requestScopeParams
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
     * Read every ScanPlus FT_INVT 01 -> CNC receive row separately. The monthly
     * ITR line/item/GRPO lot is the validation boundary, while RequestLineID
     * remains the local allocation target. Multiple ScanPlus receipt rows can
     * therefore accumulate into one request line without borrowing from another
     * request that reused the same monthly ITR.
     */
    $transferLookupStarted = microtime(true);
    $transferRows = sync_lookup_transfer_rows_by_itr_lines($erp, $refs, $lookbackDays);
    sync_log('Individual ScanPlus FT_INVT receipt rows found: ' . count($transferRows)
        . ', lookup=' . round(microtime(true) - $transferLookupStarted, 3) . ' sec');
    /*
     * Business rule: reconcile against what was issued on the SAME calendar day.
     * The monthly ITR and lot may be reused while balance remains, so a ScanPlus
     * receipt must never borrow an issuance from a different date.
     */
    $issueRows = sync_load_issue_transactions_for_daily_allocation($whp, $refs, $lookbackDays);
    sync_log('Local issuance transactions for daily allocation: ' . count($issueRows));

    /*
     * Layer 1: ScanPlus staging allocation. This answers "was it scanned?" only.
     */
    $scanAllocationResult = sync_allocate_daily_issue_receipts($issueRows, $transferRows, 'SCANPLUS_SAME_DAY');
    $scanLineAllocations = $scanAllocationResult['line_allocations'] ?? [];
    $transactionAllocations = $scanAllocationResult['transaction_allocations'] ?? [];
    $dailyTotals = $scanAllocationResult['daily_totals'] ?? [];
    $unallocatedReceipts = $scanAllocationResult['unallocated_receipts'] ?? [];
    $pendingIssues = $scanAllocationResult['pending_issues'] ?? [];

    /*
     * Layer 2: actual SAP posting allocation. Only OWTR/WTR1/IBT1 can make a
     * request line MATCHED/PARTIAL/LOT_MISMATCH in the user-facing cache.
     */
    $sapLookupRefs = sync_refs_from_issue_rows($issueRows);
    sync_log('SAP verification references from recent issuance: ' . count($sapLookupRefs));

    $sapLookupStarted = microtime(true);
    $sapPostedRows = sync_lookup_sap_posted_rows_by_itr_lines($erp, $sapLookupRefs, $lookbackDays);
    sync_log('Actual SAP OWTR/WTR1 posted batch rows found: ' . count($sapPostedRows)
        . ', lookup=' . round(microtime(true) - $sapLookupStarted, 3) . ' sec');

    $sapAllocationResult = sync_allocate_daily_issue_receipts($issueRows, $sapPostedRows, 'SAP_SAME_DAY');
    $sapLineAllocations = $sapAllocationResult['line_allocations'] ?? [];
    $sapTransactionAllocations = $sapAllocationResult['transaction_allocations'] ?? [];
    $sapDailyTotals = $sapAllocationResult['daily_totals'] ?? [];
    $sapUnallocatedReceipts = $sapAllocationResult['unallocated_receipts'] ?? [];

    /* Monthly FT_INVT raw cache remains diagnostic only. */
    $groupScans = sync_build_raw_group_scans($transferRows);
    $unallocatedByGroup = [];
    $ambiguousByGroup = [];
    $ambiguousTransfers = [];

    sync_clear_request_line_receive_allocations($whp, $refs);
    /* Keep separate transaction-level audits for staging and actual SAP posting. */
    sync_replace_issue_transaction_receive_allocations($whp, $issueRows, $transactionAllocations);
    sync_replace_issue_transaction_sap_posting_allocations($whp, $issueRows, $sapTransactionAllocations);

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

        $scanAllocation = $scanLineAllocations[$requestLineId] ?? [
            'issued_qty' => 0.0,
            'qty' => 0.0,
            'exact_lot_qty' => 0.0,
            'lot_mismatch_qty' => 0.0,
            'raw_daily_received_qty' => 0.0,
            'received_at' => '',
            'barcode_user' => '',
            'received_lot_no' => '',
            'source_transfers' => [],
            'daily_base_keys' => [],
        ];
        $sapAllocation = $sapLineAllocations[$requestLineId] ?? [
            'issued_qty' => 0.0,
            'qty' => 0.0,
            'exact_lot_qty' => 0.0,
            'lot_mismatch_qty' => 0.0,
            'raw_daily_received_qty' => 0.0,
            'received_at' => '',
            'barcode_user' => '',
            'received_lot_no' => '',
            'source_transfers' => [],
            'daily_base_keys' => [],
        ];

        /* Actual IssuanceTransactions are authoritative for the daily quantity. */
        $issuedQty = max(
            0.0,
            (float)($scanAllocation['issued_qty'] ?? $sapAllocation['issued_qty'] ?? 0)
        );
        if ($issuedQty <= 0 && is_numeric($ref['local_issued_qty'] ?? null)) {
            $issuedQty = max(0.0, (float)$ref['local_issued_qty']);
        }

        $scanAllocatedQty = max(0.0, (float)($scanAllocation['qty'] ?? 0));
        $rawDailyScannedQty = max(0.0, (float)($scanAllocation['raw_daily_received_qty'] ?? 0));
        $sapAllocatedQty = max(0.0, (float)($sapAllocation['qty'] ?? 0));
        $sapMismatchQty = max(0.0, (float)($sapAllocation['lot_mismatch_qty'] ?? 0));
        $rawDailySapQty = max(0.0, (float)($sapAllocation['raw_daily_received_qty'] ?? 0));

        /*
         * V5: keep group totals for diagnostics only. Cache quantities are the
         * FIFO quantities actually allocated to this RequestLineID, never the
         * full same-day ITR/item total repeated across several request lines.
         */
        $dailyBaseKeys = array_values(array_unique(array_merge(
            is_array($scanAllocation['daily_base_keys'] ?? null) ? $scanAllocation['daily_base_keys'] : [],
            is_array($sapAllocation['daily_base_keys'] ?? null) ? $sapAllocation['daily_base_keys'] : []
        )));
        $groupScanQty = 0.0;
        $groupSapQty = 0.0;
        foreach ($dailyBaseKeys as $dailyBaseKey) {
            $groupScanQty += (float)($dailyTotals[$dailyBaseKey]['received'] ?? 0);
            $groupSapQty += (float)($sapDailyTotals[$dailyBaseKey]['received'] ?? 0);
        }

        $scanReceivedAt = trim((string)($scanAllocation['received_at'] ?? ''));
        $scanDateValid = $scanReceivedAt !== ''
            && !str_starts_with($scanReceivedAt, '1900-01-01')
            && sync_datetime_date_key($scanReceivedAt) !== '';

        $lineScan = null;

        if ($issuedQty <= 0) {
            $lineScan = [
                'raw_received_qty' => 0.0,
                'received_qty' => 0.0,
                'received_lot_no' => '',
                'barcode_user' => '',
                'received_at' => null,
                'scan_status' => 'NOT_ISSUED_REQUEST_LINE',
                'match_status' => 'NOT_ISSUED_REQUEST_LINE',
            ];
        } elseif ($sapAllocatedQty > 0.0005) {
            $isSapFull = $sapAllocatedQty + 0.0005 >= $issuedQty;
            $hasSapLotMismatch = $sapMismatchQty > 0.0005;

            if ($isSapFull && $hasSapLotMismatch) {
                $matchStatus = 'LOT_MISMATCH';
            } elseif (!$isSapFull && $hasSapLotMismatch) {
                $matchStatus = 'PARTIAL_POSTED_LOT_MISMATCH';
            } elseif ($isSapFull) {
                $matchStatus = 'MATCHED';
            } else {
                $matchStatus = 'PARTIAL_POSTED';
            }

            /*
             * V6: Received By must be the actual ScanPlus operator (FT_INVT.CreatedBy),
             * never the synthetic source label "SAP OWTR".  SAP still remains the
             * authoritative source for posted quantity/lot/date.
             */
            $receivedByUser = trim((string)($scanAllocation['barcode_user'] ?? ''));

            $lineScan = [
                /* RawReceivedQty = ScanPlus quantity FIFO-allocated to this line only. */
                'raw_received_qty' => min($scanAllocatedQty, $issuedQty),
                'received_qty' => min($sapAllocatedQty, $issuedQty),
                'received_lot_no' => (string)($sapAllocation['received_lot_no'] ?? ''),
                'barcode_user' => $receivedByUser,
                'received_at' => (string)($sapAllocation['received_at'] ?? ''),
                'scan_status' => $isSapFull ? 'SAP_POSTED' : 'SAP_POSTED_PARTIAL',
                'match_status' => $matchStatus,
            ];
        } elseif ($scanAllocatedQty > 0.0005) {
            if (!$scanDateValid) {
                $lineScan = [
                    'raw_received_qty' => min($scanAllocatedQty, $issuedQty),
                    'received_qty' => 0.0,
                    'received_lot_no' => (string)($scanAllocation['received_lot_no'] ?? ''),
                    'barcode_user' => (string)($scanAllocation['barcode_user'] ?? ''),
                    'received_at' => null,
                    'scan_status' => 'UNVERIFIED_DATE',
                    'match_status' => 'UNVERIFIED_DATE',
                ];
            } elseif ($groupSapQty > 0.0005) {
                /*
                 * Same-day SAP posting exists for this ITR line/item, but FIFO
                 * allocated it to other issuance transactions/request lines.
                 * Do not falsely copy that SAP quantity onto this line.
                 */
                $lineScan = [
                    'raw_received_qty' => min($scanAllocatedQty, $issuedQty),
                    'received_qty' => 0.0,
                    'received_lot_no' => (string)($scanAllocation['received_lot_no'] ?? ''),
                    'barcode_user' => (string)($scanAllocation['barcode_user'] ?? ''),
                    'received_at' => $scanReceivedAt,
                    'scan_status' => 'GROUP_PARTIAL_POSTED',
                    'match_status' => 'GROUP_PARTIAL_POSTED',
                ];
            } else {
                /* Scanned/staged in ScanPlus, but no same-day OWTR/WTR1 posting exists. */
                $lineScan = [
                    'raw_received_qty' => min($scanAllocatedQty, $issuedQty),
                    'received_qty' => 0.0,
                    'received_lot_no' => (string)($scanAllocation['received_lot_no'] ?? ''),
                    'barcode_user' => (string)($scanAllocation['barcode_user'] ?? ''),
                    'received_at' => $scanReceivedAt,
                    'scan_status' => 'SCANPLUS_SCANNED_NOT_POSTED',
                    'match_status' => 'SCANNED_NOT_POSTED',
                ];
            }
        } elseif ($groupScanQty > 0.0005 || $rawDailyScannedQty > 0.0005) {
            /* Same-day ScanPlus activity exists, but none was FIFO-allocated to this request line. */
            $lineScan = [
                'raw_received_qty' => 0.0,
                'received_qty' => 0.0,
                'received_lot_no' => '',
                'barcode_user' => '',
                'received_at' => null,
                'scan_status' => 'UNALLOCATED_DAILY_SCAN',
                'match_status' => 'UNALLOCATED_DAILY_SCAN',
            ];
        } else {
            $lineScan = [
                'raw_received_qty' => 0.0,
                'received_qty' => 0.0,
                'received_lot_no' => '',
                'barcode_user' => '',
                'received_at' => null,
                'scan_status' => 'NOT_RECEIVED_IN_SCANPLUS',
                'match_status' => 'PENDING_RECEIVE',
            ];
        }

        sync_upsert_request_line_receive_cache($whp, $ref, $lineScan);
        /* The request-line source table now stores actual SAP OWTR sources only. */
        sync_replace_request_line_receive_allocations(
            $whp,
            $ref,
            array_values($sapAllocation['source_transfers'] ?? [])
        );
        $updated++;
        $sapAllocatedQty > 0.0005 ? $matched++ : $missing++;

        if ($issuedQty > 0) {
            $verifyChecked++;

            if ($scanAllocatedQty <= 0.0005 && $groupScanQty <= 0.0005) {
                $verifyMissingInScanPlus++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'MISSING_IN_SCANPLUS doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty
                    );
                }
            } elseif ($scanAllocatedQty <= 0.0005 && $groupScanQty > 0.0005) {
                $verifyUnallocatedDailyScan++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'UNALLOCATED_DAILY_SCAN doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f groupScanQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $groupScanQty
                    );
                }
            } elseif (!$scanDateValid) {
                $verifyUnverifiedDate++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'UNVERIFIED_DATE doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f scanplusQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $scanAllocatedQty
                    );
                }
            } elseif ($sapAllocatedQty <= 0.0005 && $groupSapQty > 0.0005) {
                $verifyGroupPartialPosted++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'GROUP_PARTIAL_POSTED doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f scanplusQty=%.3f groupSapQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $scanAllocatedQty,
                        $groupSapQty
                    );
                }
            } elseif ($sapAllocatedQty <= 0.0005) {
                $verifyScannedNotPosted++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'SCANNED_NOT_POSTED doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f scanplusQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $scanAllocatedQty
                    );
                }
            } elseif (abs($sapAllocatedQty - $issuedQty) > 0.001) {
                $verifyQtyMismatch++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'PARTIAL_SAP_POSTING doc=%d line=%s item=%s requestLine=%d issuedQty=%.3f sapPostedQty=%.3f remainingQty=%.3f',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $issuedQty,
                        $sapAllocatedQty,
                        max(0.0, $issuedQty - $sapAllocatedQty)
                    );
                }
            }

            if ($sapMismatchQty > 0.0005) {
                $verifyLotMismatch++;

                if (count($verifyIssues) < 200) {
                    $verifyIssues[] = sprintf(
                        'SAP_LOT_MISMATCH_DAILY doc=%d line=%s item=%s requestLine=%d mismatchQty=%.3f sapLots=%s',
                        $ref['doc_entry'],
                        $ref['line_num'] ?? '-',
                        $ref['item_code'],
                        $requestLineId,
                        $sapMismatchQty,
                        (string)($sapAllocation['received_lot_no'] ?? '')
                    );
                }
            }
        }
    }

    foreach ($dailyTotals as $dailyKey => $dailyTotal) {
        sync_log(sprintf(
            'Daily reconciliation %s: issued=%.3f, scanplus_received=%.3f, variance=%.3f',
            $dailyKey,
            (float)($dailyTotal['issued'] ?? 0),
            (float)($dailyTotal['received'] ?? 0),
            (float)($dailyTotal['issued'] ?? 0) - (float)($dailyTotal['received'] ?? 0)
        ));
    }

    foreach ($sapDailyTotals as $dailyKey => $dailyTotal) {
        sync_log(sprintf(
            'SAP daily posting %s: issued=%.3f, sap_posted=%.3f, variance=%.3f',
            $dailyKey,
            (float)($dailyTotal['issued'] ?? 0),
            (float)($dailyTotal['received'] ?? 0),
            (float)($dailyTotal['issued'] ?? 0) - (float)($dailyTotal['received'] ?? 0)
        ));
    }

    foreach ($sapUnallocatedReceipts as $receipt) {
        sync_log(sprintf(
            'UNALLOCATED_SAP_POSTING doc=%d line=%s item=%s lot=%s qty=%.3f posted_at=%s',
            (int)($receipt['doc_entry'] ?? 0),
            $receipt['line_num'] ?? '-',
            (string)($receipt['item_code'] ?? ''),
            (string)($receipt['received_lot_no'] ?? ''),
            (float)($receipt['unallocated_qty'] ?? 0),
            sync_datetime_sort_key($receipt['received_at'] ?? '')
        ));
    }

    foreach ($unallocatedReceipts as $receipt) {
        sync_log(sprintf(
            'UNALLOCATED_SAME_DAY_RECEIPT doc=%d line=%s item=%s lot=%s qty=%.3f received_at=%s',
            (int)($receipt['doc_entry'] ?? 0),
            $receipt['line_num'] ?? '-',
            (string)($receipt['item_code'] ?? ''),
            (string)($receipt['received_lot_no'] ?? ''),
            (float)($receipt['unallocated_qty'] ?? 0),
            sync_datetime_sort_key($receipt['received_at'] ?? '')
        ));
    }

    foreach ($pendingIssues as $issue) {
        sync_log(sprintf(
            'PENDING_SAME_DAY_ISSUE tx=%d requestLine=%d doc=%d line=%s item=%s lot=%s qty=%.3f issued_at=%s',
            (int)($issue['TransactionID'] ?? 0),
            (int)($issue['RequestLineID'] ?? 0),
            (int)($issue['ITRDocEntry'] ?? 0),
            $issue['ITRLineNum'] ?? '-',
            (string)($issue['ItemCode'] ?? ''),
            (string)($issue['LotNo'] ?? ''),
            (float)($issue['pending_qty'] ?? 0),
            sync_datetime_sort_key($issue['IssuedAt'] ?? '')
        ));
    }

    foreach ($ambiguousTransfers as $ambiguousTransfer) {
        sync_log(sprintf(
            'AMBIGUOUS_TRANSFER doc=%d line=%d qty=%.3f received_at=%s reason=%s candidates=%s group=%s',
            (int)($ambiguousTransfer['transfer_doc_entry'] ?? 0),
            (int)($ambiguousTransfer['transfer_line_num'] ?? 0),
            (float)($ambiguousTransfer['received_qty'] ?? 0),
            sync_datetime_sort_key($ambiguousTransfer['received_at'] ?? ''),
            (string)($ambiguousTransfer['reason'] ?? ''),
            implode(',', array_map('strval', $ambiguousTransfer['candidate_request_line_ids'] ?? [])),
            (string)($ambiguousTransfer['group_key'] ?? '')
        ));
    }

    foreach ($groupScans as $groupKey => $groupScan) {
        $unallocatedQty = (float)($unallocatedByGroup[$groupKey] ?? 0);
        $itrRequestedQty = $groupScan['itr_requested_qty'] ?? null;
        $monthlyRemaining = is_numeric($itrRequestedQty)
            ? max(0.0, (float)$itrRequestedQty - (float)($groupScan['received_qty'] ?? 0))
            : null;

        sync_log(sprintf(
            'Monthly ITR group %s: transfers=%d, scanplus_received=%.3f, unallocated=%.3f, ambiguous=%.3f%s',
            $groupKey,
            (int)($groupScan['transfer_count'] ?? 0),
            (float)($groupScan['received_qty'] ?? 0),
            $unallocatedQty,
            (float)($ambiguousByGroup[$groupKey] ?? 0),
            $monthlyRemaining !== null
                ? ', monthly_itr_remaining=' . number_format($monthlyRemaining, 3, '.', '')
                : ''
        ));
    }

    sync_log("Verification against issued lines: checked={$verifyChecked}, missing_in_scanplus={$verifyMissingInScanPlus}, "
        . "scanned_not_posted={$verifyScannedNotPosted}, group_partial_posted={$verifyGroupPartialPosted}, "
        . "unallocated_daily_scan={$verifyUnallocatedDailyScan}, unverified_date={$verifyUnverifiedDate}, "
        . "qty_mismatch={$verifyQtyMismatch}, lot_mismatch={$verifyLotMismatch}.");

    foreach ($verifyIssues as $verifyIssueLine) {
        sync_log('  ' . $verifyIssueLine);
    }

    $message = "Completed. Updated={$updated}, sap_posted_or_matched={$matched}, not_posted_or_unmatched={$missing}. "
        . "Verified={$verifyChecked}, missing_in_scanplus={$verifyMissingInScanPlus}, scanned_not_posted={$verifyScannedNotPosted}, "
        . "group_partial_posted={$verifyGroupPartialPosted}, unallocated_daily_scan={$verifyUnallocatedDailyScan}, "
        . "unverified_date={$verifyUnverifiedDate}, qty_mismatch={$verifyQtyMismatch}, lot_mismatch={$verifyLotMismatch}.";
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
