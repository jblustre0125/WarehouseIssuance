<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_once __DIR__ . '/../../includes/scanplus_lookup.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);

function report_date_value($name, $default = '')
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function report_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function excel_cell($value)
{
    return htmlspecialchars(report_cell($value), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$dateFrom = report_date_value('date_from', $today);
$dateTo = report_date_value('date_to', $today);
$export = strtolower(trim((string)($_GET['export'] ?? ''))) === 'excel';
$q = trim((string)($_GET['q'] ?? ''));

$pageSize = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$u = current_user();
$currentRole = strtolower($u['role'] ?? '');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$conn = get_whpokayoke_connection();

function issuer_report_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

$traceHasReceiveStatus = issuer_report_has_column($conn, 'RawmatTraceLines', 'VerificationStatus');
$traceHasReceivedLot = issuer_report_has_column($conn, 'RawmatTraceLines', 'ReceivedLotNo');
$traceHasReceivedQty = issuer_report_has_column($conn, 'RawmatTraceLines', 'ReceivedQty');
$traceHasReceivedBy = issuer_report_has_column($conn, 'RawmatTraceLines', 'ReceivedByUsername');
$traceHasReceivedAt = issuer_report_has_column($conn, 'RawmatTraceLines', 'ReceivedAt');
$traceHasReceivedScanAt = issuer_report_has_column($conn, 'RawmatTraceLines', 'ReceivedScanAt');
$traceHasWarehouseLotNo = issuer_report_has_column($conn, 'RawmatTraceLines', 'WarehouseLotNo');
$traceHasIssueRequestLineId = issuer_report_has_column($conn, 'RawmatTraceLines', 'IssueRequestLineID');

$localReceiveStatusExpr = $traceHasReceiveStatus ? 'TL.VerificationStatus' : "CAST('' AS NVARCHAR(80))";
$localReceivedLotExpr = $traceHasReceivedLot ? 'TL.ReceivedLotNo' : "CAST('' AS NVARCHAR(80))";
$localReceivedQtyExpr = $traceHasReceivedQty ? 'TL.ReceivedQty' : 'CAST(NULL AS DECIMAL(18,3))';
$localScannedByExpr = $traceHasReceivedBy ? 'TL.ReceivedByUsername' : "CAST('' AS NVARCHAR(120))";
if ($traceHasReceivedScanAt && $traceHasReceivedAt) {
    $localReceivedAtExpr = 'COALESCE(TL.ReceivedScanAt, TL.ReceivedAt)';
} elseif ($traceHasReceivedScanAt) {
    $localReceivedAtExpr = 'TL.ReceivedScanAt';
} elseif ($traceHasReceivedAt) {
    $localReceivedAtExpr = 'TL.ReceivedAt';
} else {
    $localReceivedAtExpr = 'CAST(NULL AS DATETIME)';
}

$localWarehouseLotMatchSql = $traceHasWarehouseLotNo
    ? "
                OR (
                    LEN(LTRIM(RTRIM(ISNULL(TL.WarehouseLotNo, N'')))) > 0
                    AND LEN(LTRIM(RTRIM(ISNULL(IT.WarehouseLotNo, N'')))) > 0
                    AND LTRIM(RTRIM(TL.WarehouseLotNo)) = LTRIM(RTRIM(IT.WarehouseLotNo))
                )"
    : '';

// Prefer an exact RequestLineID match when both local tables support it.
// Only fall back to TraceNo + ItemCode + lot for legacy records without RequestLineID.
$localReceiverRequestMatchSql = $traceHasIssueRequestLineId
    ? "
          AND (
                (
                    IT.IssueRequestLineID IS NOT NULL
                    AND TL.IssueRequestLineID = IT.IssueRequestLineID
                )
                OR (
                    IT.IssueRequestLineID IS NULL
                    AND TH.TraceNo = IT.TraceNo
                )
          )"
    : "
          AND TH.TraceNo = IT.TraceNo";

$localReceiverOrderSql = $traceHasIssueRequestLineId
    ? "CASE WHEN IT.IssueRequestLineID IS NOT NULL AND TL.IssueRequestLineID = IT.IssueRequestLineID THEN 0 ELSE 1 END,"
    : '';

$localReceiverApply = "
    OUTER APPLY (
        SELECT TOP 1
            {$localReceiveStatusExpr} AS LocalReceiveStatus,
            {$localReceivedLotExpr} AS LocalReceivedLotNo,
            {$localReceivedQtyExpr} AS LocalReceivedQty,
            {$localScannedByExpr} AS LocalScannedBy,
            {$localReceivedAtExpr} AS LocalReceivedAt
        FROM RawmatTraceLines TL
        INNER JOIN RawmatTraceHeader TH ON TH.TraceID = TL.TraceID
        WHERE TL.ItemCode = IT.ItemCode
          {$localReceiverRequestMatchSql}
          AND (
                ISNULL(TL.LotNo, NCHAR(0)) = ISNULL(IT.LotNo, NCHAR(0))
                OR LEN(LTRIM(RTRIM(ISNULL(TL.LotNo, NCHAR(0))))) = 0
                OR LEN(LTRIM(RTRIM(ISNULL(IT.LotNo, NCHAR(0))))) = 0
                {$localWarehouseLotMatchSql}
          )
        ORDER BY {$localReceiverOrderSql} TL.TraceLineID DESC
    ) LocalRx";

// WH Lot No was added after the original report. Detect the actual DB column name
// so older environments do not throw a 500 error if the column is not present yet.
$warehouseLotColumn = '';
foreach (['WarehouseLotNo', 'WHLotNo', 'WarehouseLot', 'WhLotNo'] as $candidateColumn) {
    try {
        $checkRows = fetch_all(
            $conn,
            "SELECT COL_LENGTH('dbo.IssuanceTransactions', '" . str_replace("'", "''", $candidateColumn) . "') AS ColumnLength",
            []
        );

        if ((int)($checkRows[0]['ColumnLength'] ?? 0) > 0) {
            $warehouseLotColumn = $candidateColumn;
            break;
        }
    } catch (Throwable $e) {
        $warehouseLotColumn = '';
    }
}

$warehouseLotSelect = $warehouseLotColumn !== ''
    ? 'IT.[' . str_replace(']', ']]', $warehouseLotColumn) . '] AS WarehouseLotNo'
    : "CAST('' AS NVARCHAR(100)) AS WarehouseLotNo";
$requestLineIdSelect = issuer_report_has_column(
    $conn,
    'IssuanceTransactions',
    'IssueRequestLineID'
)
    ? 'IT.IssueRequestLineID'
    : 'CAST(NULL AS INT) AS IssueRequestLineID';
$requestIdSelect = issuer_report_has_column(
    $conn,
    'IssuanceTransactions',
    'IssueRequestID'
)
    ? 'IT.IssueRequestID'
    : 'CAST(NULL AS INT) AS IssueRequestID';
$warehouseLotSearchSql = $warehouseLotColumn !== ''
    ? "
        OR IT.[" . str_replace(']', ']]', $warehouseLotColumn) . "] LIKE ?"
    : '';

$where = [
    'IT.IssuedAt >= ?',
    'IT.IssuedAt < DATEADD(day, 1, ?)'
];

$params = [
    $dateFrom,
    $dateTo
];

if (!in_array($currentRole, [ROLE_ADMIN, ROLE_WAREHOUSE], true)) {
    $where[] = 'IT.IssuedByUsername = ?';
    $params[] = $u['username'] ?? '';
}

if ($q !== '') {
    $where[] = '(
        IT.TraceNo LIKE ?
        OR IT.ItemCode LIKE ?
        OR IT.PartName LIKE ?
        OR IT.LotNo LIKE ?' . $warehouseLotSearchSql . '
        OR IT.ITRNumber LIKE ?
        OR IT.IssuedByUsername LIKE ?
    )';

    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);

    if ($warehouseLotColumn !== '') {
        $params[] = $like;
    }

    array_push($params, $like, $like);
}

$whereSql = implode(' AND ', $where);

$countSql = '
    SELECT COUNT(*) AS TotalRows
    FROM IssuanceTransactions IT
    WHERE ' . $whereSql . '
';
$countRows = fetch_all($conn, $countSql, $params);
$totalRows = (int)($countRows[0]['TotalRows'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$itSourceSelect = '
            IT.TransactionID,
            IT.TraceNo,
            IT.ItemCode,
            IT.PartName,
            IT.Quantity,
            IT.LotNo,
            IT.ITRNumber,
            IT.ITRDocEntry,
            IT.ITRLineNum,
            ' . $requestLineIdSelect . ',
            ' . $requestIdSelect . ',
            IT.IssuedByUsername,
            IT.IssuedAt,
            ' . $warehouseLotSelect;

$itSourceSql = $export
    ? '(
        SELECT
            ' . $itSourceSelect . '
        FROM IssuanceTransactions IT
        WHERE ' . $whereSql . '
    ) IT'
    : '(
        SELECT
            ' . $itSourceSelect . '
        FROM IssuanceTransactions IT
        WHERE ' . $whereSql . '
        ORDER BY IT.IssuedAt DESC, IT.TransactionID DESC
        OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$pageSize . ' ROWS ONLY
    ) IT';

$sql = '
    SELECT
        IT.TransactionID,
        IT.TraceNo,
        IT.ItemCode,
        IT.PartName,
        Req.RequestedQty,
        Req.IssuedQty AS RequestLineIssuedQty,
        Req.RequestNo,
        Req.RequestLineID,
        Req.RequestedByUsername,
        Req.RequestHeaderStatus,
        Req.RequestLineStatus,
        LocalRx.LocalReceiveStatus,
        LocalRx.LocalReceivedLotNo,
        LocalRx.LocalReceivedQty,
        LocalRx.LocalScannedBy,
        LocalRx.LocalReceivedAt,
        IT.Quantity,
        IT.LotNo,
        IT.WarehouseLotNo,
        IT.ITRNumber,
        IT.ITRDocEntry,
        IT.ITRLineNum,
        IT.IssuedByUsername,
        IT.IssuedAt
    FROM ' . $itSourceSql . '
    OUTER APPLY (
        SELECT TOP 1
            H.RequestNo,
            L.RequestLineID,
            H.RequestedByUsername,
            H.Status AS RequestHeaderStatus,
            L.Status AS RequestLineStatus,
            L.RequestedQty,
            L.IssuedQty
        FROM WarehouseIssueRequestHeader H
        INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
        WHERE
            (
                (
                    IT.IssueRequestLineID IS NOT NULL
                    AND L.RequestLineID = IT.IssueRequestLineID
                )
                OR (
                    IT.IssueRequestID IS NOT NULL
                    AND H.RequestID = IT.IssueRequestID
                )
                OR (
                    H.RequestedAt <= IT.IssuedAt
                    AND (
                        H.IssuedTraceNo = IT.TraceNo
                        OR (
                            ISNULL(COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry), 0) = ISNULL(IT.ITRDocEntry, 0)
                            AND L.SAP_IT_LineNum = IT.ITRLineNum
                        )
                    )
                )
            )
            AND L.ItemCode = IT.ItemCode
            AND (
                (
                    IT.IssueRequestLineID IS NOT NULL
                    AND L.RequestLineID = IT.IssueRequestLineID
                )
                OR (
                    IT.IssueRequestID IS NOT NULL
                    AND H.RequestID = IT.IssueRequestID
                )
                OR ISNULL(L.LotNo, NCHAR(0)) = ISNULL(IT.LotNo, NCHAR(0))
                OR LEN(LTRIM(RTRIM(ISNULL(L.LotNo, NCHAR(0))))) = 0
                OR LEN(LTRIM(RTRIM(ISNULL(IT.LotNo, NCHAR(0))))) = 0
            )
        ORDER BY
            CASE
                WHEN IT.IssueRequestLineID IS NOT NULL AND L.RequestLineID = IT.IssueRequestLineID THEN 0
                WHEN IT.IssueRequestID IS NOT NULL AND H.RequestID = IT.IssueRequestID THEN 1
                ELSE 1
            END,
            CASE WHEN H.IssuedTraceNo = IT.TraceNo THEN 0 ELSE 1 END,
            H.RequestedAt DESC,
            L.RequestLineID DESC
    ) Req
    ' . $localReceiverApply . '
    ORDER BY IT.IssuedAt DESC, IT.TransactionID DESC
';

$rows = fetch_all($conn, $sql, $params);

function enrich_issuer_scan_rows_with_scanplus(&$rows, $whpConn, $allowLiveRefresh = false)
{
    if (empty($rows)) {
        return;
    }

    $scanRefs = [];
    $seenRefs = [];

    foreach ($rows as $row) {
        $candidateLots = [
            $row['LotNo'] ?? '',
            $row['WarehouseLotNo'] ?? ''
        ];

        foreach ($candidateLots as $candidateLot) {
            $candidateLot = trim((string)$candidateLot);

            if ($candidateLot === '') {
                continue;
            }

            $ref = [
                'doc_entry' => $row['ITRDocEntry'] ?? 0,
                'line_num' => $row['ITRLineNum'] ?? null,
                'item_code' => $row['ItemCode'] ?? '',
                'lot_no' => $candidateLot
            ];

            $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);

            if ($scanKey === '') {
                continue;
            }

            $dedupeKey = $scanKey . '|' . strtoupper($candidateLot);

            if (isset($seenRefs[$dedupeKey])) {
                continue;
            }

            $seenRefs[$dedupeKey] = true;
            $scanRefs[] = $ref;
        }

        $baseRef = [
            'doc_entry' => $row['ITRDocEntry'] ?? 0,
            'line_num' => $row['ITRLineNum'] ?? null,
            'item_code' => $row['ItemCode'] ?? '',
            'lot_no' => ''
        ];

        $scanKey = scanplus_key($baseRef['doc_entry'], $baseRef['line_num'], $baseRef['item_code']);

        if ($scanKey === '') {
            continue;
        }

        $dedupeKey = $scanKey . '|';

        if (isset($seenRefs[$dedupeKey])) {
            continue;
        }

        $seenRefs[$dedupeKey] = true;
        $scanRefs[] = $baseRef;
    }

    $hasScanRefs = false;

    foreach ($scanRefs as $ref) {
        if (scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']) !== '') {
            $hasScanRefs = true;
            break;
        }
    }

    $cache = $hasScanRefs ? scanplus_cache_read($whpConn, $scanRefs) : ['rows' => [], 'fresh_keys' => []];
    $scanplusRows = $cache['rows'];
    $freshKeys = $cache['fresh_keys'];
    $refsToRefresh = [];
    $scanHasReceivedData = static function ($scan): bool {
        if (!is_array($scan)) {
            return false;
        }

        if (trim((string)($scan['scan_status'] ?? '')) !== '') {
            return true;
        }

        if (trim((string)($scan['barcode_user'] ?? '')) !== '') {
            return true;
        }

        if (trim((string)($scan['received_at'] ?? '')) !== '') {
            return true;
        }

        $qty = trim((string)($scan['received_qty'] ?? ''));
        return $qty !== '' && is_numeric($qty) && (float)$qty > 0;
    };

    foreach ($scanRefs as $ref) {
        $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
        $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
        $targetKey = $scanLotKey !== '' ? $scanLotKey : $scanKey;
        $cachedScan = $scanLotKey !== ''
            ? ($scanplusRows[$scanLotKey] ?? null)
            : ($scanKey !== '' ? ($scanplusRows[$scanKey] ?? null) : null);

        if ($targetKey !== '' && (!isset($freshKeys[$targetKey]) || !$scanHasReceivedData($cachedScan))) {
            $refsToRefresh[] = $ref;
        }
    }

    /*
     * Do not perform a live OWTR/WTR1 refresh here. Receiving is now sourced by
     * the scheduled FT_INVT 01 -> CNC synchronization. This report only consumes
     * the local caches so a missing SAP posting cannot hide a real ScanPlus scan.
     */
    if ($allowLiveRefresh && !empty($refsToRefresh)) {
        // Intentionally left cache-only. Run tools/sync_scanplus_cache.php to refresh.
    }

    $findScanForRow = static function (array $row) use (&$scanplusRows) {
        $scanKey = scanplus_key($row['ITRDocEntry'] ?? 0, $row['ITRLineNum'] ?? null, $row['ItemCode'] ?? '');

        foreach ([$row['LotNo'] ?? '', $row['WarehouseLotNo'] ?? ''] as $candidateLot) {
            $scanLotKey = scanplus_lot_key(
                $row['ITRDocEntry'] ?? 0,
                $row['ITRLineNum'] ?? null,
                $row['ItemCode'] ?? '',
                $candidateLot
            );

            if ($scanLotKey !== '' && isset($scanplusRows[$scanLotKey])) {
                return $scanplusRows[$scanLotKey];
            }
        }

        return $scanKey !== '' ? ($scanplusRows[$scanKey] ?? null) : null;
    };

    foreach ($rows as &$row) {
        $scan = $findScanForRow($row);

        $row['ScanStatus'] = $scan['scan_status'] ?? '';
        $row['ReceivedLotNo'] = $scan['received_lot_no'] ?? '';
        $row['ReceivedQty'] = $scan['received_qty'] ?? '';
        $row['BarcodeUser'] = $scan['barcode_user'] ?? '';
        $row['ReceivedAt'] = $scan['received_at'] ?? '';
    }
    unset($row);
}

enrich_issuer_scan_rows_with_scanplus($rows, $conn, false);

function enrich_issuer_rows_with_request_line_receive_cache(&$rows, $conn)
{
    if (empty($rows) || !issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'RequestLineID')) {
        return;
    }

    $matchStatusSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'MatchStatus')
        ? 'MatchStatus'
        : "CAST('' AS NVARCHAR(50)) AS MatchStatus";
    $receivedLotSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'ReceivedLotNo')
        ? 'ReceivedLotNo'
        : "CAST('' AS NVARCHAR(80)) AS ReceivedLotNo";
    $scanStatusSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'ScanStatus')
        ? 'ScanStatus'
        : "CAST('' AS NVARCHAR(50)) AS ScanStatus";
    $receivedQtySelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'ReceivedQty')
        ? 'ReceivedQty'
        : 'CAST(NULL AS DECIMAL(18, 3)) AS ReceivedQty';
    $barcodeUserSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'BarcodeUser')
        ? 'BarcodeUser'
        : "CAST('' AS NVARCHAR(120)) AS BarcodeUser";
    $receivedAtSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'ReceivedAt')
        ? 'ReceivedAt'
        : 'CAST(NULL AS DATETIME) AS ReceivedAt';
    $isCurrentMatchSelect = issuer_report_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'IsCurrentMatch')
        ? 'IsCurrentMatch'
        : 'CAST(0 AS BIT) AS IsCurrentMatch';

    $ids = [];

    foreach ($rows as $row) {
        $id = (int)($row['RequestLineID'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    if (empty($ids)) {
        return;
    }

    $mappedByLine = [];

    foreach (array_chunk(array_keys($ids), 300) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $mappedRows = fetch_all(
            $conn,
            "SELECT
                RequestLineID,
                {$matchStatusSelect},
                {$scanStatusSelect},
                {$receivedLotSelect},
                {$receivedQtySelect},
                {$barcodeUserSelect},
                {$receivedAtSelect},
                {$isCurrentMatchSelect}
             FROM dbo.WarehouseIssueRequestLineReceiveCache
             WHERE RequestLineID IN ({$placeholders})",
            $chunk
        );

        foreach ($mappedRows as $mappedRow) {
            $mappedByLine[(int)$mappedRow['RequestLineID']] = $mappedRow;
        }
    }

    /*
     * Build the full issuance history for every request line shown on the page.
     * This is intentionally NOT limited to the paginated rows.  A consolidated
     * ScanPlus receipt must be consumed once, FIFO, across every issuance
     * transaction that belongs to the same RequestLineID.
     */
    $transactionsByLine = [];

    if (issuer_report_has_column($conn, 'IssuanceTransactions', 'IssueRequestLineID')) {
        foreach (array_chunk(array_keys($ids), 300) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $txRows = fetch_all(
                $conn,
                "SELECT
                    TransactionID,
                    IssueRequestLineID,
                    TRY_CONVERT(DECIMAL(18, 3), Quantity) AS Quantity,
                    IssuedAt
                 FROM dbo.IssuanceTransactions
                 WHERE IssueRequestLineID IN ({$placeholders})
                 ORDER BY IssueRequestLineID, IssuedAt, TransactionID",
                $chunk
            );

            foreach ($txRows as $tx) {
                $lineId = (int)($tx['IssueRequestLineID'] ?? 0);
                if ($lineId <= 0) {
                    continue;
                }
                $transactionsByLine[$lineId][] = $tx;
            }
        }
    }

    $allocatedByTransaction = [];

    foreach ($mappedByLine as $lineId => $mapped) {
        if ((int)($mapped['IsCurrentMatch'] ?? 0) !== 1) {
            continue;
        }

        $receivedQty = is_numeric($mapped['ReceivedQty'] ?? null)
            ? max(0.0, (float)$mapped['ReceivedQty'])
            : 0.0;

        if ($receivedQty <= 0 || empty($transactionsByLine[$lineId])) {
            continue;
        }

        $receivedAtTs = null;
        $receivedAtText = trim(report_cell($mapped['ReceivedAt'] ?? ''));
        if ($receivedAtText !== '' && strpos($receivedAtText, '1900-01-01') !== 0) {
            $ts = strtotime($receivedAtText);
            $receivedAtTs = $ts === false ? null : $ts;
        }

        $remaining = $receivedQty;

        foreach ($transactionsByLine[$lineId] as $tx) {
            $transactionId = (int)($tx['TransactionID'] ?? 0);
            $issueQty = is_numeric($tx['Quantity'] ?? null)
                ? max(0.0, (float)$tx['Quantity'])
                : 0.0;

            if ($transactionId <= 0 || $issueQty <= 0) {
                continue;
            }

            // A receipt cannot satisfy an issuance transaction created after it.
            if ($receivedAtTs !== null) {
                $issuedAtText = trim(report_cell($tx['IssuedAt'] ?? ''));
                $issuedTs = $issuedAtText !== '' ? strtotime($issuedAtText) : false;
                if ($issuedTs !== false && $issuedTs > $receivedAtTs) {
                    $allocatedByTransaction[$transactionId] = 0.0;
                    continue;
                }
            }

            $allocated = min($issueQty, max(0.0, $remaining));
            $allocatedByTransaction[$transactionId] = $allocated;
            $remaining -= $allocated;

            if ($remaining <= 0.0005) {
                $remaining = 0.0;
            }
        }
    }

    foreach ($rows as &$row) {
        $id = (int)($row['RequestLineID'] ?? 0);
        $transactionId = (int)($row['TransactionID'] ?? 0);

        $row['RequestLineCacheFound'] = 0;
        $row['RequestLineCacheCurrent'] = 0;
        $row['RequestLineAllocatedReceivedQty'] = '';

        if ($id <= 0) {
            continue;
        }

        /*
         * A known RequestLineID must use the request-specific allocation.
         * Never borrow the aggregate ITR/item/lot cache for another request.
         */
        if (!isset($mappedByLine[$id])) {
            $row['CacheMatchStatus'] = 'REQUEST_LINE_CACHE_MISSING';
            $row['ScanStatus'] = '';
            $row['ReceivedLotNo'] = '';
            $row['ReceivedQty'] = '';
            $row['BarcodeUser'] = '';
            $row['ReceivedAt'] = '';
            continue;
        }

        $mapped = $mappedByLine[$id];
        $row['RequestLineCacheFound'] = 1;
        $row['CacheMatchStatus'] = $mapped['MatchStatus'] ?? '';

        /*
         * V6 keeps the real ScanPlus receiver separately from SAP receipt status.
         * This allows Received By to show FT_INVT.CreatedBy even for
         * SCANNED_NOT_POSTED / GROUP_PARTIAL_POSTED rows without pretending
         * that their quantity was successfully posted in SAP.
         */
        $cacheBarcodeUser = trim((string)($mapped['BarcodeUser'] ?? ''));
        if (strcasecmp($cacheBarcodeUser, 'SAP OWTR') === 0) {
            $cacheBarcodeUser = '';
        }
        $row['CacheBarcodeUser'] = $cacheBarcodeUser;

        $mappedCacheStatus = strtoupper(trim((string)($row['CacheMatchStatus'] ?? '')));
        $isMismatchEvidence = in_array($mappedCacheStatus, ['LOT_MISMATCH', 'PARTIAL_LOT_MISMATCH'], true);

        if ((int)($mapped['IsCurrentMatch'] ?? 0) !== 1) {
            $row['ScanStatus'] = '';
            $row['ReceivedLotNo'] = $isMismatchEvidence ? ($mapped['ReceivedLotNo'] ?? '') : '';
            $row['ReceivedQty'] = '';
            $row['BarcodeUser'] = $isMismatchEvidence ? $cacheBarcodeUser : '';
            $row['ReceivedAt'] = $isMismatchEvidence ? ($mapped['ReceivedAt'] ?? '') : '';
            continue;
        }

        $row['RequestLineCacheCurrent'] = 1;

        $allocatedQty = $transactionId > 0
            ? (float)($allocatedByTransaction[$transactionId] ?? 0)
            : 0.0;

        $row['RequestLineAllocatedReceivedQty'] = $allocatedQty;

        if ($allocatedQty <= 0.0005) {
            // This individual issuance transaction has not yet been consumed.
            $row['ScanStatus'] = '';
            $row['ReceivedLotNo'] = '';
            $row['ReceivedQty'] = '';
            $row['BarcodeUser'] = '';
            $row['ReceivedAt'] = '';
            continue;
        }

        $issueQty = is_numeric($row['Quantity'] ?? null) ? (float)$row['Quantity'] : 0.0;
        $mappedScanStatus = trim((string)($mapped['ScanStatus'] ?? ''));
        $row['ScanStatus'] = $mappedScanStatus !== ''
            ? $mappedScanStatus
            : (($issueQty > 0 && $allocatedQty + 0.0005 >= $issueQty)
                ? 'SCANPLUS_RECEIVED'
                : 'SCANPLUS_PARTIAL');
        $row['ReceivedLotNo'] = $mapped['ReceivedLotNo'] ?? '';
        $row['ReceivedQty'] = rtrim(rtrim(number_format($allocatedQty, 3, '.', ''), '0'), '.');
        $row['BarcodeUser'] = $mapped['BarcodeUser'] ?? '';
        $row['ReceivedAt'] = $mapped['ReceivedAt'] ?? '';
    }
    unset($row);
}

enrich_issuer_rows_with_request_line_receive_cache($rows, $conn);

function issuer_report_valid_datetime($value): bool
{
    $dateValue = trim(report_cell($value));
    return $dateValue !== '' && strpos($dateValue, '1900-01-01') !== 0;
}

function issuer_report_datetime_timestamp($value): ?int
{
    if (!issuer_report_valid_datetime($value)) {
        return null;
    }

    $timestamp = strtotime(report_cell($value));
    return $timestamp === false ? null : $timestamp;
}

function issuer_report_scanplus_before_issue(array $row): bool
{
    $receivedAt = issuer_report_datetime_timestamp($row['ReceivedAt'] ?? '');
    $issuedAt = issuer_report_datetime_timestamp($row['IssuedAt'] ?? '');

    if ($receivedAt === null || $issuedAt === null) {
        return false;
    }

    return $receivedAt < $issuedAt;
}

function issuer_report_received_status($status): bool
{
    $status = strtoupper(trim((string)$status));
    return in_array($status, [
        'SAP_POSTED',
        'SAP_POSTED_PARTIAL',
        'SCANPLUS_RECEIVED', // legacy compatibility
        'SCANPLUS_PARTIAL',  // legacy compatibility
        'SAP_RECEIVED',      // legacy compatibility
        'SAP PARTIAL',       // legacy compatibility
        'RECEIVED',
        'CLOSED',
        'COMPLETED',
        'MATCHED'
    ], true);
}

function issuer_report_cache_status(array $row): string
{
    return strtoupper(trim((string)($row['CacheMatchStatus'] ?? '')));
}

function issuer_report_actual_issue_exists(array $row): bool
{
    /*
     * The actual issuer transaction is more authoritative than an older
     * NOT_ISSUED_REQUEST_LINE cache result. The scheduled receive-cache sync
     * can run before the issuer finishes a new issuance, so that status can be
     * temporarily stale until the next synchronization.
     */
    $issuedQty = is_numeric($row['Quantity'] ?? null)
        ? (float)$row['Quantity']
        : 0.0;

    if ($issuedQty > 0.0005) {
        return true;
    }

    $issuedAt = trim((string)($row['IssuedAt'] ?? ''));

    return $issuedAt !== '' && issuer_report_valid_datetime($issuedAt);
}

function issuer_report_cache_blocks_received(array $row): bool
{
    $status = issuer_report_cache_status($row);

    /*
     * Do not let a stale pre-issuance cache result override the current
     * issuance transaction. If this row now has an issued quantity or a valid
     * IssuedAt timestamp, verification should continue as PENDING RECEIVE (or
     * another current receive state) rather than showing NOT ISSUED.
     */
    if (
        $status === 'NOT_ISSUED_REQUEST_LINE' &&
        issuer_report_actual_issue_exists($row)
    ) {
        return false;
    }

    return in_array($status, [
        'GROUP_PARTIAL_POSTED',
        'UNALLOCATED_SCANPLUS_SCAN',
        'UNALLOCATED_DAILY_SCAN',
        'UNVERIFIED_DATE',
        'PENDING_RECEIVE',
        'NOT_RECEIVED_IN_SCANPLUS',
        'NOT_ISSUED_REQUEST_LINE'
    ], true);
}

function issuer_report_has_authoritative_line_allocation(array $row): bool
{
    return (int)($row['RequestLineCacheFound'] ?? 0) === 1
        && (int)($row['RequestLineCacheCurrent'] ?? 0) === 1;
}

function issuer_row_is_received($row): bool
{
    /*
     * A ScanPlus scan alone is not a receipt.
     * The row becomes received only when an actual positive quantity has been
     * confirmed by the authoritative SAP/request-line allocation or by the
     * legacy local receive source.
     */
    if (issuer_report_cache_blocks_received($row)) {
        return false;
    }

    if (issuer_report_has_authoritative_line_allocation($row)) {
        return is_numeric($row['ReceivedQty'] ?? null)
            && (float)$row['ReceivedQty'] > 0.0005;
    }

    $localQty = $row['LocalReceivedQty'] ?? null;
    $sapQty = $row['ReceivedQty'] ?? null;

    return issuer_report_received_status($row['RequestHeaderStatus'] ?? '') ||
        issuer_report_received_status($row['RequestLineStatus'] ?? '') ||
        issuer_report_received_status($row['LocalReceiveStatus'] ?? '') ||
        issuer_report_received_status($row['ScanStatus'] ?? '') ||
        (is_numeric($localQty) && (float)$localQty > 0.0005) ||
        (is_numeric($sapQty) && (float)$sapQty > 0.0005);
}


function issuer_cap_received_qty($sapQty, $row)
{
    $qty = (float)($sapQty ?? 0);

    if ($qty <= 0) {
        return '';
    }

    $limits = [];

    foreach (['Quantity', 'RequestedQty'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field]) && (float)$row[$field] > 0) {
            $limits[] = (float)$row[$field];
        }
    }

    if (!empty($limits)) {
        $qty = min($qty, min($limits));
    }

    return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
}

function issuer_report_qty_variance($issuedQty, $receivedQty)
{
    $receivedText = trim((string)($receivedQty ?? ''));

    if (!is_numeric($issuedQty) || $receivedText === '' || !is_numeric($receivedText)) {
        return '';
    }

    $variance = (float)$issuedQty - (float)$receivedText;
    return rtrim(rtrim(number_format($variance, 3, '.', ''), '0'), '.');
}

function issuer_report_lot_tokens($value): array
{
    $tokens = [];

    foreach (preg_split('/\s*,\s*/', trim((string)($value ?? ''))) ?: [] as $token) {
        $token = strtoupper(trim($token));

        if ($token === '') {
            continue;
        }

        if (preg_match('/^\d+$/', $token)) {
            $token = ltrim($token, '0');
            $token = $token === '' ? '0' : $token;
        }

        $tokens[] = $token;
    }

    return array_values(array_unique($tokens));
}

function issuer_report_lot_matches_any($receivedLot, array $issuedLots): bool
{
    $receivedTokens = issuer_report_lot_tokens($receivedLot);

    if (empty($receivedTokens)) {
        return false;
    }

    foreach ($issuedLots as $issuedLot) {
        foreach (issuer_report_lot_tokens($issuedLot) as $issuedToken) {
            if (in_array($issuedToken, $receivedTokens, true)) {
                return true;
            }
        }
    }

    return false;
}

function issuer_report_effective_received_qty(array $row): float
{
    /*
     * Return the same quantity that the report is allowed to display.
     * Scanner/user/date metadata must never make a row look received by itself.
     */
    if (!issuer_row_is_received($row)) {
        return 0.0;
    }

    if (issuer_report_has_authoritative_line_allocation($row)) {
        $qty = $row['ReceivedQty'] ?? null;
        return is_numeric($qty) && (float)$qty > 0.0005
            ? (float)$qty
            : 0.0;
    }

    $localQty = $row['LocalReceivedQty'] ?? null;
    if (is_numeric($localQty) && (float)$localQty > 0.0005) {
        $capped = issuer_cap_received_qty($localQty, $row);
        return is_numeric($capped) ? (float)$capped : 0.0;
    }

    $qty = issuer_cap_received_qty($row['ReceivedQty'] ?? 0, $row);
    return is_numeric($qty) ? (float)$qty : 0.0;
}

function report_received_value($row, $field)
{
    $effectiveReceivedQty = issuer_report_effective_received_qty($row);

    /*
     * Received Qty is the gate for every receive-related display field.
     * If there is no positive received quantity, Received By, Received Lot and
     * Received At must also stay blank. This prevents ScanPlus staging metadata
     * from looking like a completed receipt.
     */
    if ($field === 'ReceivedQty') {
        if ($effectiveReceivedQty <= 0.0005) {
            return '';
        }

        return rtrim(
            rtrim(number_format($effectiveReceivedQty, 3, '.', ''), '0'),
            '.'
        );
    }

    if ($effectiveReceivedQty <= 0.0005) {
        return '';
    }

    if ($field === 'BarcodeUser') {
        $candidates = [
            $row['CacheBarcodeUser'] ?? '',
            $row['BarcodeUser'] ?? '',
            $row['LocalScannedBy'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);

            if (
                $candidate !== '' &&
                strcasecmp($candidate, 'SAP OWTR') !== 0
            ) {
                return $candidate;
            }
        }

        return '';
    }

    /*
     * When a request-line FIFO allocation exists, it is authoritative.
     * Local receive metadata must not overwrite it.
     */
    if (issuer_report_has_authoritative_line_allocation($row)) {
        if ($field === 'ReceivedLotNo') {
            return trim((string)($row['ReceivedLotNo'] ?? ''));
        }

        if ($field === 'ReceivedAt') {
            $dateValue = $row['ReceivedAt'] ?? '';
            return issuer_report_valid_datetime($dateValue)
                ? report_cell($dateValue)
                : '';
        }

        return $row[$field] ?? '';
    }

    if ($field === 'ReceivedAt') {
        $localDate = $row['LocalReceivedAt'] ?? '';

        if (issuer_report_valid_datetime($localDate)) {
            return report_cell($localDate);
        }

        $dateValue = $row[$field] ?? '';
        return issuer_report_valid_datetime($dateValue)
            ? report_cell($dateValue)
            : '';
    }

    if ($field === 'ReceivedLotNo') {
        $localLot = trim((string)($row['LocalReceivedLotNo'] ?? ''));

        if ($localLot !== '') {
            return $localLot;
        }

        return trim((string)($row[$field] ?? ''));
    }

    return $row[$field] ?? '';
}

function issuer_report_receive_verification(array $row): array
{
    $cacheStatus = issuer_report_cache_status($row);

    /*
     * Warehouse receive verification is now based on the scheduled ScanPlus
     * FT_INVT allocation written to WarehouseIssueRequestLineReceiveCache.
     * SAP posting is diagnostic only and must not downgrade a valid ScanPlus
     * receipt to pending.
     */
    if ($cacheStatus === 'NOT_ISSUED_REQUEST_LINE') {
        if (!issuer_report_actual_issue_exists($row)) {
            return [
                'status' => 'NOT_ISSUED',
                'note' => 'No issued quantity is available for receive verification.'
            ];
        }

        $cacheStatus = '';
    }

    if (in_array($cacheStatus, ['UNALLOCATED_SCANPLUS_SCAN', 'UNALLOCATED_DAILY_SCAN'], true)) {
        return [
            'status' => 'PENDING_RECEIVE',
            'note' => 'ScanPlus activity exists for this ITR line/item, but no receipt was safely allocated to this issuance transaction.'
        ];
    }

    if ($cacheStatus === 'UNVERIFIED_DATE') {
        return [
            'status' => 'UNVERIFIED_DATE',
            'note' => 'A ScanPlus allocation exists but the receive date is not valid enough for request-line verification.'
        ];
    }

    if (in_array($cacheStatus, ['PENDING_RECEIVE', 'NOT_RECEIVED_IN_SCANPLUS'], true)) {
        return [
            'status' => 'PENDING_RECEIVE',
            'note' => 'No matching ScanPlus FT_INVT receipt has been allocated to this issuance transaction yet.'
        ];
    }

    /*
     * Lot mismatch can be a real verification result even when the matched
     * quantity is intentionally not treated as a clean received quantity.
     * Honor the explicit sync status before the positive-quantity fallback.
     */
    if (in_array($cacheStatus, ['LOT_MISMATCH', 'PARTIAL_LOT_MISMATCH'], true)) {
        $issuedLotText = implode(' / ', array_filter([
            trim((string)($row['LotNo'] ?? '')),
            trim((string)($row['WarehouseLotNo'] ?? ''))
        ], static function ($lot) {
            return $lot !== '';
        }));
        $receivedLot = trim((string)(
            $row['DisplayReceivedLotNo']
            ?? $row['ReceivedLotNo']
            ?? $row['CacheReceivedLotNo']
            ?? ''
        ));

        $noteParts = [
            'Issued qty: ' . report_cell($row['Quantity'] ?? ''),
            'ScanPlus raw qty: ' . report_cell($row['RawReceivedQty'] ?? $row['ReceivedQty'] ?? '')
        ];

        if ($issuedLotText !== '' || $receivedLot !== '') {
            $noteParts[] = 'Issued lot: ' . $issuedLotText;
            $noteParts[] = 'Received lot: ' . $receivedLot;
        }

        return [
            'status' => $cacheStatus,
            'note' => implode(' | ', $noteParts)
        ];
    }

    if (!issuer_row_is_received($row)) {
        return [
            'status' => 'PENDING_RECEIVE',
            'note' => 'No positive ScanPlus received quantity has been allocated to this issuance transaction yet.'
        ];
    }

    $issuedQty = is_numeric($row['Quantity'] ?? null) ? (float)$row['Quantity'] : null;
    $receivedText = trim((string)($row['DisplayReceivedQty'] ?? ''));
    $receivedQty = is_numeric($receivedText) ? (float)$receivedText : null;
    $tolerance = 0.0005;

    if ($issuedQty === null || $receivedQty === null || $receivedQty <= $tolerance) {
        return [
            'status' => 'PENDING_RECEIVE',
            'note' => 'No positive ScanPlus received quantity has been allocated to this issuance transaction yet.'
        ];
    }

    $receivedLot = trim((string)($row['DisplayReceivedLotNo'] ?? ''));
    $issuedLots = array_filter([
        trim((string)($row['LotNo'] ?? '')),
        trim((string)($row['WarehouseLotNo'] ?? ''))
    ], static function ($lot) {
        return $lot !== '';
    });

    $hasComparableLot = $receivedLot !== '' && !empty($issuedLots);
    $lotMatches = $hasComparableLot && issuer_report_lot_matches_any($receivedLot, $issuedLots);
    $qtyMatches = abs($issuedQty - $receivedQty) <= $tolerance;
    $isPartial = $receivedQty > $tolerance && $receivedQty < ($issuedQty - $tolerance);

    /* Prefer the explicit status written by the ScanPlus sync. */
    if ($cacheStatus === 'LOT_MISMATCH') {
        $status = 'LOT_MISMATCH';
    } elseif ($cacheStatus === 'PARTIAL_LOT_MISMATCH') {
        $status = 'PARTIAL_LOT_MISMATCH';
    } elseif ($cacheStatus === 'PARTIAL') {
        $status = 'PARTIAL';
    } elseif ($cacheStatus === 'MATCHED') {
        $status = 'MATCHED';
    } elseif ($isPartial) {
        $status = (!$hasComparableLot || $lotMatches)
            ? 'PARTIAL'
            : 'PARTIAL_LOT_MISMATCH';
    } elseif ($qtyMatches && $lotMatches) {
        $status = 'MATCHED';
    } elseif ($qtyMatches && !$hasComparableLot) {
        $status = 'QTY_MATCH';
    } elseif ($qtyMatches) {
        $status = 'LOT_MISMATCH';
    } elseif ($lotMatches) {
        $status = 'QTY_VARIANCE';
    } else {
        $status = $hasComparableLot ? 'LOT_AND_QTY_VARIANCE' : 'QTY_VARIANCE';
    }

    $issuedLotText = implode(' / ', $issuedLots);
    $noteParts = [
        'Issued qty: ' . report_cell($row['Quantity'] ?? ''),
        'ScanPlus received qty: ' . report_cell($row['DisplayReceivedQty'] ?? '')
    ];

    if ($issuedLotText !== '' || $receivedLot !== '') {
        $noteParts[] = 'Issued lot: ' . $issuedLotText;
        $noteParts[] = 'Received lot: ' . $receivedLot;
    }

    return [
        'status' => $status,
        'note' => implode(' | ', $noteParts)
    ];
}

function issuer_report_verification_label($status): string
{
    $status = strtoupper(trim((string)$status));

    $labels = [
        'MATCHED' => 'MATCHED',
        'LOT_MISMATCH' => 'LOT MISMATCH',
        'PARTIAL' => 'PARTIAL',
        'PARTIAL_LOT_MISMATCH' => 'PARTIAL + LOT MISMATCH',
        'PARTIAL_POSTED' => 'PARTIAL', // legacy cache compatibility
        'PARTIAL_POSTED_LOT_MISMATCH' => 'PARTIAL + LOT MISMATCH', // legacy
        'GROUP_PARTIAL_POSTED' => 'PENDING RECEIVE', // legacy
        'SCANNED_NOT_POSTED' => 'RECEIVED - SAP PENDING', // legacy
        'UNALLOCATED_SCANPLUS_SCAN' => 'PENDING RECEIVE',
        'UNALLOCATED_DAILY_SCAN' => 'PENDING RECEIVE',
        'UNVERIFIED_DATE' => 'UNVERIFIED DATE',
        'NOT_ISSUED' => 'NOT ISSUED',
        'PENDING_RECEIVE' => 'PENDING RECEIVE',
        'QTY_VARIANCE' => 'QTY VARIANCE',
        'LOT_AND_QTY_VARIANCE' => 'LOT + QTY VARIANCE',
    ];

    if ($status === '') {
        return 'PENDING RECEIVE';
    }

    return $labels[$status] ?? str_replace('_', ' ', $status);
}

// Show received values when the request-line cache confirms a ScanPlus FT_INVT allocation.
// SAP posting is retained only as a separate audit/diagnostic source.
foreach ($rows as &$issuerReportRow) {
    if (issuer_report_scanplus_before_issue($issuerReportRow)) {
        $issuerReportRow['ScanStatus'] = '';
        $issuerReportRow['ReceivedLotNo'] = '';
        $issuerReportRow['ReceivedQty'] = '';
        $issuerReportRow['BarcodeUser'] = '';
        $issuerReportRow['ReceivedAt'] = '';
    }

    $issuerReportRow['IssueStatus'] = 'ISSUED';
    $issuerReportRow['DisplayReceivedQty'] = report_received_value($issuerReportRow, 'ReceivedQty');
    $issuerReportRow['DisplayReceivedLotNo'] = report_received_value($issuerReportRow, 'ReceivedLotNo');
    $issuerReportRow['QtyVariance'] = issuer_report_qty_variance($issuerReportRow['Quantity'] ?? '', $issuerReportRow['DisplayReceivedQty']);
    $verification = issuer_report_receive_verification($issuerReportRow);
    $issuerReportRow['ReceiveVerification'] = $verification['status'];
    $issuerReportRow['ReceiveVerificationLabel'] = issuer_report_verification_label($verification['status']);
    $issuerReportRow['ReceiveVerificationNote'] = $verification['note'];
    $issuerReportRow['DisplayBarcodeUser'] = report_received_value($issuerReportRow, 'BarcodeUser');
    $issuerReportRow['DisplayReceivedAt'] = report_received_value($issuerReportRow, 'ReceivedAt');
}
unset($issuerReportRow);

$noStockRows = [];
$noStockTotalRows = 0;

if (issuer_report_has_column($conn, 'IssuerNoStockReturns', 'ReturnID')) {
    $noStockWhere = [
        'R.ReturnedAt >= ?',
        'R.ReturnedAt < DATEADD(day, 1, ?)'
    ];
    $noStockParams = [
        $dateFrom,
        $dateTo
    ];

    if (!in_array($currentRole, [ROLE_ADMIN, ROLE_WAREHOUSE], true)) {
        $noStockWhere[] = 'R.ReturnedByUsername = ?';
        $noStockParams[] = $u['username'] ?? '';
    }

    if ($q !== '') {
        $noStockWhere[] = '(
            R.RequestNo LIKE ?
            OR R.ITRNumber LIKE ?
            OR R.ItemCode LIKE ?
            OR R.PartName LIKE ?
            OR R.ReturnedByUsername LIKE ?
            OR R.ReturnReason LIKE ?
        )';

        $like = '%' . $q . '%';
        array_push($noStockParams, $like, $like, $like, $like, $like, $like);
    }

    $noStockWhereSql = implode(' AND ', $noStockWhere);
    $noStockCountRows = fetch_all(
        $conn,
        'SELECT COUNT(*) AS TotalRows FROM IssuerNoStockReturns R WHERE ' . $noStockWhereSql,
        $noStockParams
    );
    $noStockTotalRows = (int)($noStockCountRows[0]['TotalRows'] ?? 0);
    $noStockTopSql = $export ? '' : 'TOP 200';

    $noStockRows = fetch_all(
        $conn,
        "SELECT {$noStockTopSql}
            R.ReturnID,
            R.RequestNo,
            R.ITRNumber,
            R.SAP_IT_DocEntry,
            R.SAP_IT_DocNum,
            R.SAP_IT_LineNum,
            R.ItemCode,
            R.PartName,
            R.RequestedQty,
            R.IssuedQty,
            R.RemainingQty,
            R.StockWhsCode,
            R.StockQty,
            R.ReturnReason,
            R.ReturnedByUsername,
            R.ReturnedAt
         FROM IssuerNoStockReturns R
         WHERE {$noStockWhereSql}
         ORDER BY R.ReturnedAt DESC, R.ReturnID DESC",
        $noStockParams
    );
}

$baseQuery = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

if ($q !== '') {
    $baseQuery['q'] = $q;
}

function issuer_scan_report_url($query)
{
    return 'pages/issuer/issuer_scan_report.php?' . http_build_query($query);
}

$columns = [
    'Request No',
    'Part No',
    'Part Name',
    'Req Qty',
    'Iss Qty',
    'Received Qty',
    'Variance',
    'Received Lot',
    'GRPO Lot No',
    'WH Lot No',
    'ITR/IT',
    'Iss By',
    'Issue Status',
    'Receive Status',
    'Received By',
    'Received At',
    'Issued At'
];

$noStockColumns = [
    'Request No',
    'Part No',
    'Part Name',
    'Req Qty',
    'Issued Qty',
    'Remaining Qty',
    'WH Stock',
    'Stock WH',
    'ITR/IT',
    'Line',
    'Returned By',
    'Issue Status',
    'Reason',
    'Returned At'
];

function excel_xml_cell($value, $styleId = 'Text')
{
    $text = htmlspecialchars(report_cell($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    return '<Cell ss:StyleID="' . $styleId . '"><Data ss:Type="String">' . $text . '</Data></Cell>';
}

function excel_xml_row(array $values, $styleId = 'Text')
{
    $cells = array_map(
        static function ($value) use ($styleId) {
            return excel_xml_cell($value, $styleId);
        },
        $values
    );

    return '<Row>' . implode('', $cells) . '</Row>';
}

if ($export) {
    $filename = 'issuer_scans_' . $dateFrom . '_to_' . $dateTo . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo '<?xml version="1.0"?>' . "\n";
echo '
<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:html="http://www.w3.org/TR/REC-html40">
    <?php
    /*
     * Emit SpreadsheetML style tags from PHP strings instead of writing literal
     * <Style> tags in the PHP/HTML source. VS Code treats XML <Style> tags as
     * HTML <style> elements and incorrectly parses their contents as CSS.
     */
    echo '<Styles>' . "\n";
    echo '  <Style ss:ID="Text"><Alignment ss:Vertical="Center"/><NumberFormat ss:Format="@"/></Style>' . "\n";
    echo '  <Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/><NumberFormat ss:Format="@"/></Style>' . "\n";
    echo '</Styles>' . "\n";
    ?>
    <Worksheet ss:Name="Issuer Scans">
        <Table>
            <?= excel_xml_row($columns, 'Header') . "\n" ?>
            <?php if (empty($rows)): ?>
            <?= excel_xml_row(['No records found.']) . "\n" ?>
            <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <?= excel_xml_row([
                        $r['RequestNo'] ?? '',
                        $r['ItemCode'] ?? '',
                        $r['PartName'] ?? '',
                        $r['RequestedQty'] ?? '',
                        $r['Quantity'] ?? '',
                        $r['DisplayReceivedQty'] ?? '',
                        $r['QtyVariance'] ?? '',
                        $r['DisplayReceivedLotNo'] ?? '',
                        $r['LotNo'] ?? '',
                        $r['WarehouseLotNo'] ?? '',
                        $r['ITRNumber'] ?? '',
                        $r['IssuedByUsername'] ?? '',
                        $r['IssueStatus'] ?? 'ISSUED',
                        $r['ReceiveVerificationLabel'] ?? ($r['ReceiveVerification'] ?? ''),
                        $r['DisplayBarcodeUser'] ?? '',
                        $r['DisplayReceivedAt'] ?? '',
                        $r['IssuedAt'] ?? ''
                    ]) . "\n" ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </Table>
    </Worksheet>
    <Worksheet ss:Name="No Stocks Item">
        <Table>
            <?= excel_xml_row($noStockColumns, 'Header') . "\n" ?>
            <?php if (empty($noStockRows)): ?>
            <?= excel_xml_row(['No no-stock items found.']) . "\n" ?>
            <?php else: ?>
            <?php foreach ($noStockRows as $r): ?>
            <?= excel_xml_row([
                        $r['RequestNo'] ?? '',
                        $r['ItemCode'] ?? '',
                        $r['PartName'] ?? '',
                        $r['RequestedQty'] ?? '',
                        $r['IssuedQty'] ?? '',
                        $r['RemainingQty'] ?? '',
                        $r['StockQty'] ?? '',
                        $r['StockWhsCode'] ?? '',
                        $r['ITRNumber'] ?? '',
                        $r['SAP_IT_LineNum'] ?? '',
                        $r['ReturnedByUsername'] ?? '',
                        'RETURNED_NO_STOCK',
                        $r['ReturnReason'] ?? '',
                        $r['ReturnedAt'] ?? ''
                    ]) . "\n" ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </Table>
    </Worksheet>
</Workbook>
<?php
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <title>Issuer Scan Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
    :root {
        --sidebar-width: 250px;
        --body-bg: #f4f7fb;
        --border-soft: #e5eaf2;
        --text-dark: #1f2937;
        --text-muted: #6b7280;
    }

    body {
        min-height: 100vh;
        margin: 0;
        background: var(--body-bg);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
    }

    .app-layout {
        display: flex;
        min-height: 100vh;
    }

    .main-content {
        width: calc(100% - var(--sidebar-width));
        margin-left: var(--sidebar-width);
        padding: 20px;
        overflow-x: hidden;
    }

    .mobile-topbar,
    .sidebar-backdrop {
        display: none;
    }

    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .page-title {
        margin: 0 0 4px;
        color: var(--text-dark);
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .page-subtitle {
        max-width: 940px;
        color: var(--text-muted);
        font-size: 14px;
        line-height: 1.5;
    }

    .content-card {
        overflow: hidden;
        background: #fff;
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
    }

    .content-card-header {
        padding: 16px 18px;
        background: #fff;
        border-bottom: 1px solid var(--border-soft);
    }

    .content-card-title {
        margin: 0;
        color: var(--text-dark);
        font-size: 16px;
        font-weight: 800;
    }

    .content-card-subtitle {
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 13px;
    }

    .content-card-body {
        padding: 18px;
    }

    .filter-box {
        padding: 14px;
        margin-bottom: 14px;
        background: #f8fafc;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
    }

    .form-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 13px;
        font-weight: 700;
    }

    .form-control,
    .form-select {
        min-height: 42px;
        background: #fff;
        border: 1px solid #d9e2ef;
        border-radius: 10px;
        font-size: 14px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13, 110, 253, .12);
    }

    .btn {
        border-radius: 10px;
        font-weight: 700;
    }

    .report-table-wrap {
        max-height: 68vh;
        overflow: auto;
        background: #fff;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
    }

    .report-table {
        width: 100%;
        min-width: 1560px;
        margin-bottom: 0;
        table-layout: auto;
        font-size: 11px;
    }

    .report-table thead th {
        position: sticky;
        top: 0;
        z-index: 5;
        padding: 9px 7px;
        background: #f1f5f9;
        color: #334155;
        border-bottom: 1px solid #cbd5e1;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .report-table td {
        padding: 8px 7px;
        color: #111827;
        vertical-align: middle;
        white-space: nowrap;
    }

    .report-table tbody tr:hover {
        background: #f8fbff;
    }

    .col-trace {
        min-width: 125px;
    }

    .col-item {
        min-width: 115px;
    }

    .col-part {
        min-width: 220px;
        max-width: 300px;
        white-space: normal !important;
        line-height: 1.3;
    }

    .col-qty,
    .col-variance,
    .col-stock {
        min-width: 80px;
        text-align: right;
    }

    .col-lot,
    .col-received-lot,
    .col-wh-lot {
        min-width: 115px;
    }

    .col-itr {
        min-width: 100px;
    }

    .col-user {
        min-width: 115px;
    }

    .col-status {
        min-width: 110px;
    }

    .col-verification {
        min-width: 135px;
    }

    .col-date {
        min-width: 155px;
    }

    .request-verify-link {
        border: 0;
        padding: 0;
        background: transparent;
        color: #0f6fd6;
        font: inherit;
        font-weight: 700;
        text-align: left;
        text-decoration: underline;
        cursor: pointer;
    }

    .request-verify-link:hover,
    .request-verify-link:focus {
        color: #0a58ca;
    }

    .empty-row {
        padding: 32px !important;
        color: #6b7280 !important;
        text-align: center;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 180px;
        padding: 4px 8px;
        overflow: hidden;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 800;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .status-open,
    .status-pending,
    .status-pending_receive,
    .status-unallocated_scanplus_scan,
    .status-unallocated_daily_scan,
    .status-unverified_date,
    .status-returned_no_stock {
        background: #fef3c7;
        color: #92400e;
    }

    .status-issued {
        background: #ffedd5;
        color: #9a3412;
    }

    .status-scanplus_partial,
    .status-partial,
    .status-partial_received,
    .status-partial_posted {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .status-scanplus_received,
    .status-received,
    .status-closed,
    .status-completed,
    .status-matched,
    .status-verified {
        background: #dcfce7;
        color: #166534;
    }

    .status-cancelled,
    .status-rejected,
    .status-lot_mismatch,
    .status-partial_lot_mismatch,
    .status-partial_posted_lot_mismatch,
    .status-qty_variance,
    .status-lot_and_qty_variance {
        background: #fee2e2;
        color: #991b1b;
    }

    .status-scanned_not_posted,
    .status-group_partial_posted {
        background: #f3f4f6;
        color: #4b5563;
    }

    .verify-summary,
    .verify-overall {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }

    .verify-overall {
        margin-bottom: 12px;
    }

    .verify-summary .status-pill,
    .verify-overall .status-pill {
        max-width: none;
    }

    .verify-table-wrap {
        max-height: 58vh;
        overflow: auto;
        border: 1px solid #d8e0eb;
        border-radius: 10px;
    }

    .verify-table {
        margin-bottom: 0;
        font-size: 12px;
    }

    .verify-table th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
        white-space: nowrap;
    }

    .verify-table td {
        vertical-align: middle;
    }

    .source-transfer-cell {
        min-width: 220px;
        max-width: 420px;
        white-space: normal;
        line-height: 1.35;
    }

    .pagination {
        margin-bottom: 0;
    }

    .page-link {
        min-width: 38px;
        text-align: center;
    }

    @media (max-width: 900px) {
        .main-content {
            width: 100%;
            margin-left: 0;
            padding: 14px;
        }

        .mobile-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            margin-bottom: 14px;
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 12px;
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1029;
            display: none;
            background: rgba(15, 23, 42, .45);
        }

        .sidebar-backdrop.show {
            display: block;
        }

        .page-header {
            flex-direction: column;
        }

        .report-table {
            min-width: 1560px;
            font-size: 11px;
        }

        .report-table thead th {
            font-size: 9px;
        }
    }
    </style>
</head>

<body>
    <header class="sap-shellbar">
        <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">&#9776;</button>
        <div class="shell-logo" aria-hidden="true">
            <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
        </div>
        <div class="shell-title-wrap">
            <div class="shell-title">NBC Rawmats Traceability</div>
            <div class="shell-subtitle">Issuer reporting</div>
        </div>
    </header>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="app-layout">

        <?php app_sidebar('issuer_report'); ?>

        <main class="main-content">

            <div class="mobile-topbar">
                <strong>Issuer Scan Report</strong>
                <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                    Menu
                </button>
            </div>

            <div class="page-header">
                <div>
                    <h4 class="page-title">Issuer Scan Report</h4>
                    <div class="page-subtitle">
                        Issued transaction history with ScanPlus staging verification. Receiving status is based on
                        FT_INVT allocation; SAP posting remains an audit check.
                    </div>
                </div>

                <span class="badge bg-primary rounded-pill px-3 py-2">
                    <?= number_format($totalRows) ?> line(s)
                </span>
            </div>

            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">Report Filters</h5>
                    <div class="content-card-subtitle">
                        Filter issued transactions and export the result to Excel.
                    </div>
                </div>

                <div class="content-card-body">

                    <form class="filter-box" method="get">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label" for="date_from">Date From</label>
                                <input class="form-control" type="date" id="date_from" name="date_from"
                                    value="<?= h($dateFrom) ?>">
                            </div>

                            <div class="col-sm-6 col-md-3">
                                <label class="form-label" for="date_to">Date To</label>
                                <input class="form-control" type="date" id="date_to" name="date_to"
                                    value="<?= h($dateTo) ?>">
                            </div>

                            <div class="col-sm-6 col-md-3 d-grid">
                                <button class="btn btn-primary" type="submit">
                                    Filter
                                </button>
                            </div>

                            <div class="col-sm-6 col-md-3 d-grid">
                                <a class="btn btn-success"
                                    href="<?= h(issuer_scan_report_url($baseQuery + ['export' => 'excel'])) ?>">
                                    Export Excel
                                </a>
                            </div>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-12">
                                <label class="form-label" for="searchReport">Search Item / Report</label>
                                <input class="form-control form-control-sm" type="search" id="searchReport" name="q"
                                    value="<?= h($q) ?>"
                                    placeholder="Search SAP code, part name, GRPO lot, WH lot, ITR, issuer...">
                                <div class="form-text">
                                    Use SAP ItemCode or Part Name to search items. Press Enter or click Filter to search
                                    all records.
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="report-table-wrap">
                        <table class="table table-bordered table-striped align-middle report-table" id="reportTable">
                            <thead>
                                <tr>
                                    <th class="col-trace">Request No</th>
                                    <th class="col-item">Part No</th>
                                    <th class="col-part">Part Name</th>
                                    <th class="col-qty">Req Qty</th>
                                    <th class="col-qty">Iss Qty</th>
                                    <th class="col-qty">Received Qty</th>
                                    <th class="col-variance">Variance</th>
                                    <th class="col-received-lot">Received Lot</th>
                                    <th class="col-lot">GRPO Lot No</th>
                                    <th class="col-wh-lot">WH Lot No</th>
                                    <th class="col-itr">ITR/IT</th>
                                    <th class="col-user">Iss By</th>
                                    <th class="col-status">Issue Status</th>
                                    <th class="col-verification">Receive Status</th>
                                    <th class="col-user">Received By</th>
                                    <th class="col-date">Received At</th>
                                    <th class="col-date">Issued At</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="<?= count($columns) ?>" class="empty-row">
                                        No records found for the selected date range.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                <?php
                                        $scanStatus = strtolower((string)($r['IssueStatus'] ?? 'ISSUED'));
                                        $requestNo = trim((string)($r['RequestNo'] ?? ''));
                                    ?>
                                <tr>
                                    <td class="col-trace" title="<?= h($requestNo) ?>">
                                        <?php if ($requestNo !== ''): ?>
                                        <button class="request-verify-link" type="button"
                                            data-request-no="<?= h($requestNo) ?>"
                                            title="Open receive verification for <?= h($requestNo) ?>">
                                            <?= h($requestNo) ?>
                                        </button>
                                        <?php endif; ?>
                                    </td>

                                    <td class="col-item" title="<?= h(report_cell($r['ItemCode'] ?? '')) ?>">
                                        <?= h(report_cell($r['ItemCode'] ?? '')) ?>
                                    </td>

                                    <td class="col-part" title="<?= h(report_cell($r['PartName'] ?? '')) ?>">
                                        <?= h(report_cell($r['PartName'] ?? '')) ?>
                                    </td>

                                    <td class="col-qty" title="<?= h(report_cell($r['RequestedQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['RequestedQty'] ?? '')) ?>
                                    </td>

                                    <td class="col-qty" title="<?= h(report_cell($r['Quantity'] ?? '')) ?>">
                                        <?= h(report_cell($r['Quantity'] ?? '')) ?>
                                    </td>

                                    <td class="col-qty"
                                        title="Received qty: <?= h(report_cell($r['DisplayReceivedQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['DisplayReceivedQty'] ?? '')) ?>
                                    </td>

                                    <td class="col-variance"
                                        title="Issued minus received: <?= h(report_cell($r['QtyVariance'] ?? '')) ?>">
                                        <?= h(report_cell($r['QtyVariance'] ?? '')) ?>
                                    </td>

                                    <td class="col-received-lot"
                                        title="<?= h(report_cell($r['DisplayReceivedLotNo'] ?? '')) ?>">
                                        <?= h(report_cell($r['DisplayReceivedLotNo'] ?? '')) ?>
                                    </td>

                                    <td class="col-lot" title="<?= h(report_cell($r['LotNo'] ?? '')) ?>">
                                        <?= h(report_cell($r['LotNo'] ?? '')) ?>
                                    </td>

                                    <td class="col-wh-lot" title="<?= h(report_cell($r['WarehouseLotNo'] ?? '')) ?>">
                                        <?= h(report_cell($r['WarehouseLotNo'] ?? '')) ?>
                                    </td>

                                    <td class="col-itr" title="<?= h(report_cell($r['ITRNumber'] ?? '')) ?>">
                                        <?= h(report_cell($r['ITRNumber'] ?? '')) ?>
                                    </td>

                                    <td class="col-user" title="<?= h(report_cell($r['IssuedByUsername'] ?? '')) ?>">
                                        <?= h(report_cell($r['IssuedByUsername'] ?? '')) ?>
                                    </td>

                                    <td class="col-status" title="<?= h(report_cell($r['IssueStatus'] ?? 'ISSUED')) ?>">
                                        <span class="status-pill status-issued">
                                            <?= h(report_cell($r['IssueStatus'] ?? 'ISSUED')) ?>
                                        </span>
                                    </td>

                                    <td class="col-verification"
                                        title="<?= h(report_cell($r['ReceiveVerificationNote'] ?? '')) ?>">
                                        <span
                                            class="status-pill status-<?= h(strtolower((string)($r['ReceiveVerification'] ?? 'pending_receive'))) ?>">
                                            <?= h(report_cell($r['ReceiveVerificationLabel'] ?? ($r['ReceiveVerification'] ?? 'PENDING RECEIVE'))) ?>
                                        </span>
                                    </td>

                                    <td class="col-user"
                                        title="ScanPlus receiver: <?= h(report_cell($r['DisplayBarcodeUser'] ?? '')) ?>">
                                        <?= h(report_cell($r['DisplayBarcodeUser'] ?? '')) ?>
                                    </td>

                                    <td class="col-date" title="<?= h(report_cell($r['DisplayReceivedAt'] ?? '')) ?>">
                                        <?= h(report_cell($r['DisplayReceivedAt'] ?? '')) ?>
                                    </td>

                                    <td class="col-date" title="<?= h(report_cell($r['IssuedAt'] ?? '')) ?>">
                                        <?= h(report_cell($r['IssuedAt'] ?? '')) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="small text-muted mt-2">
                        Showing page <?= number_format($page) ?> of <?= number_format($totalPages) ?>.
                        Issue Status and Receive Status are shown side by side for easier checking. Receiver quantity
                        uses the request-specific ScanPlus verification cache when available.
                    </div>

                    <?php if (!$export && $totalPages > 1): ?>
                    <nav class="mt-3" aria-label="Issuer scan report pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= h(issuer_scan_report_url($baseQuery + ['page' => max(1, $page - 1)])) ?>">Previous</a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="<?= h(issuer_scan_report_url($baseQuery + ['page' => $p])) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= h(issuer_scan_report_url($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>

                </div>
            </div>

            <div class="content-card mt-3">
                <div class="content-card-header">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h5 class="content-card-title">No Stocks Item</h5>
                            <div class="content-card-subtitle">
                                Request lines returned by issuer because warehouse stock was unavailable.
                            </div>
                        </div>
                        <span class="badge bg-warning text-dark rounded-pill px-3 py-2">
                            <?= number_format($noStockTotalRows) ?> line(s)
                        </span>
                    </div>
                </div>

                <div class="content-card-body">
                    <div class="report-table-wrap">
                        <table class="table table-bordered table-striped align-middle report-table"
                            id="noStockReportTable">
                            <thead>
                                <tr>
                                    <th class="col-trace">Request No</th>
                                    <th class="col-item">Part No</th>
                                    <th class="col-part">Part Name</th>
                                    <th class="col-qty">Req Qty</th>
                                    <th class="col-qty">Issued Qty</th>
                                    <th class="col-qty">Remaining Qty</th>
                                    <th class="col-stock">WH Stock</th>
                                    <th class="col-lot">Stock WH</th>
                                    <th class="col-itr">ITR/IT</th>
                                    <th class="col-itr">Line</th>
                                    <th class="col-user">Returned By</th>
                                    <th class="col-status">Issue Status</th>
                                    <th class="col-part">Reason</th>
                                    <th class="col-date">Returned At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($noStockRows)): ?>
                                <tr>
                                    <td colspan="<?= count($noStockColumns) ?>" class="empty-row">
                                        No no-stock items found for the selected date range.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($noStockRows as $r): ?>
                                <tr>
                                    <?php $noStockRequestNo = trim((string)($r['RequestNo'] ?? '')); ?>
                                    <td class="col-trace" title="<?= h($noStockRequestNo) ?>">
                                        <?php if ($noStockRequestNo !== ''): ?>
                                        <button class="request-verify-link" type="button"
                                            data-request-no="<?= h($noStockRequestNo) ?>"
                                            title="Open receive verification for <?= h($noStockRequestNo) ?>">
                                            <?= h($noStockRequestNo) ?>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-item" title="<?= h(report_cell($r['ItemCode'] ?? '')) ?>">
                                        <?= h(report_cell($r['ItemCode'] ?? '')) ?>
                                    </td>
                                    <td class="col-part" title="<?= h(report_cell($r['PartName'] ?? '')) ?>">
                                        <?= h(report_cell($r['PartName'] ?? '')) ?>
                                    </td>
                                    <td class="col-qty" title="<?= h(report_cell($r['RequestedQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['RequestedQty'] ?? '')) ?>
                                    </td>
                                    <td class="col-qty" title="<?= h(report_cell($r['IssuedQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['IssuedQty'] ?? '')) ?>
                                    </td>
                                    <td class="col-qty" title="<?= h(report_cell($r['RemainingQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['RemainingQty'] ?? '')) ?>
                                    </td>
                                    <td class="col-stock" title="<?= h(report_cell($r['StockQty'] ?? '')) ?>">
                                        <?= h(report_cell($r['StockQty'] ?? '')) ?>
                                    </td>
                                    <td class="col-lot" title="<?= h(report_cell($r['StockWhsCode'] ?? '')) ?>">
                                        <?= h(report_cell($r['StockWhsCode'] ?? '')) ?>
                                    </td>
                                    <td class="col-itr" title="<?= h(report_cell($r['ITRNumber'] ?? '')) ?>">
                                        <?= h(report_cell($r['ITRNumber'] ?? '')) ?>
                                    </td>
                                    <td class="col-itr" title="<?= h(report_cell($r['SAP_IT_LineNum'] ?? '')) ?>">
                                        <?= h(report_cell($r['SAP_IT_LineNum'] ?? '')) ?>
                                    </td>
                                    <td class="col-user" title="<?= h(report_cell($r['ReturnedByUsername'] ?? '')) ?>">
                                        <?= h(report_cell($r['ReturnedByUsername'] ?? '')) ?>
                                    </td>
                                    <td class="col-status" title="RETURNED_NO_STOCK">
                                        <span class="status-pill status-returned_no_stock">RETURNED_NO_STOCK</span>
                                    </td>
                                    <td class="col-part" title="<?= h(report_cell($r['ReturnReason'] ?? '')) ?>">
                                        <?= h(report_cell($r['ReturnReason'] ?? '')) ?>
                                    </td>
                                    <td class="col-date" title="<?= h(report_cell($r['ReturnedAt'] ?? '')) ?>">
                                        <?= h(report_cell($r['ReturnedAt'] ?? '')) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="small text-muted mt-2">
                        Export Excel includes this data in a separate No Stocks Item worksheet.
                        <?php if (!$export && $noStockTotalRows > count($noStockRows)): ?>
                        Showing latest <?= number_format(count($noStockRows)) ?> of
                        <?= number_format($noStockTotalRows) ?> returned no-stock line(s).
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <div class="modal fade" id="receiveVerifyModal" tabindex="-1" aria-labelledby="receiveVerifyTitle"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="receiveVerifyTitle">Receive Verification</h5>
                        <div class="text-muted small" id="receiveVerifySubtitle">Local ScanPlus / SAP verification</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div id="receiveVerifyStatus" class="alert alert-light border">
                        Select a request to verify receive status.
                    </div>

                    <div class="verify-overall" id="receiveVerifyOverall"></div>
                    <div class="verify-summary mb-3" id="receiveVerifySummary"></div>

                    <div class="verify-table-wrap d-none" id="receiveVerifyTableWrap">
                        <table class="table table-sm table-striped table-bordered verify-table">
                            <thead>
                                <tr>
                                    <th>Result</th>
                                    <th>Item</th>
                                    <th>Part Name</th>
                                    <th class="text-end">Issued</th>
                                    <th>Issued At</th>
                                    <th class="text-end">Received</th>
                                    <th>GRPO Lot</th>
                                    <th>WH Lot</th>
                                    <th>Received Lot</th>
                                    <th>SAP / ScanPlus Source</th>
                                    <th>Received By</th>
                                    <th>Received At</th>
                                    <th>Cache Synced</th>
                                </tr>
                            </thead>
                            <tbody id="receiveVerifyRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    const searchInput = document.getElementById('searchReport');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase();

            document.querySelectorAll('#reportTable tbody tr, #noStockReportTable tbody tr').forEach(function(
                row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.add('show');
            sidebarBackdrop.classList.add('show');
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarBackdrop.classList.remove('show');
        });
    }

    const receiveVerifyModalEl = document.getElementById('receiveVerifyModal');
    const receiveVerifyModal = receiveVerifyModalEl ?
        bootstrap.Modal.getOrCreateInstance(receiveVerifyModalEl) :
        null;

    function verifyEscape(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [char];
        });
    }

    function verifyNumber(value) {
        const number = Number(value || 0);
        if (!Number.isFinite(number)) {
            return '';
        }
        return String(parseFloat(number.toFixed(3)));
    }

    function verifyStatusClass(status) {
        return String(status || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function verifySetLoading(requestNo) {
        document.getElementById('receiveVerifyTitle').textContent = 'Receive Verification';
        document.getElementById('receiveVerifySubtitle').textContent =
            requestNo + ' | checking received / partial status...';
        document.getElementById('receiveVerifyStatus').className = 'alert alert-light border';
        document.getElementById('receiveVerifyStatus').textContent =
            'Loading request status from the local verification cache...';
        document.getElementById('receiveVerifyOverall').innerHTML = '';
        document.getElementById('receiveVerifySummary').innerHTML = '';
        document.getElementById('receiveVerifyRows').innerHTML = '';
        document.getElementById('receiveVerifyTableWrap').classList.add('d-none');
    }

    function verifyOverallStatus(summary) {
        const received = Number(summary?.received || 0);
        const partial = Number(summary?.partial_received || 0);
        const lotMismatch = Number(summary?.lot_mismatch || 0);
        const partialLotMismatch = Number(summary?.partial_lot_mismatch || 0);
        const issued = Number(summary?.issued || 0);
        const mismatch = lotMismatch + partialLotMismatch;
        const total = received + partial + mismatch + issued;

        let label = 'PENDING RECEIVE';
        let cls = 'pending_receive';

        if (total > 0 && received === total) {
            label = 'RECEIVED';
            cls = 'received';
        } else if (mismatch > 0) {
            label = partialLotMismatch > 0 || received > 0 || partial > 0 ?
                'PARTIAL + LOT MISMATCH' :
                'LOT MISMATCH';
            cls = partialLotMismatch > 0 ? 'partial_lot_mismatch' : 'lot_mismatch';
        } else if (received > 0 || partial > 0) {
            label = 'PARTIAL RECEIVED';
            cls = 'partial_received';
        } else if (issued > 0) {
            label = 'ISSUED / PENDING RECEIVE';
            cls = 'issued';
        }

        return '<span class="fw-semibold">Overall:</span>' +
            '<span class="status-pill status-' + cls + '">' +
            verifyEscape(label) + '</span>';
    }

    function verifyRenderSummary(summary) {
        const labels = {
            received: 'Received',
            partial_received: 'Partial',
            lot_mismatch: 'Lot mismatch',
            partial_lot_mismatch: 'Partial + lot mismatch',
            issued: 'Issued / Pending'
        };

        return Object.keys(labels).map(function(key) {
            const count = Number(summary?. [key] || 0);
            const cls = verifyStatusClass(key);
            return '<span class="status-pill status-' + cls + '">' +
                verifyEscape(labels[key] + ': ' + count) +
                '</span>';
        }).join('');
    }

    function verifyRenderRows(lines) {
        if (!Array.isArray(lines) || lines.length === 0) {
            return '<tr><td colspan="13" class="empty-row">No lines found.</td></tr>';
        }

        return lines.map(function(line) {
            let status = String(line.verification_status || '').trim();
            const issuedQty = Number(line.issued_qty || 0);
            const normalizedStatus = status
                .toUpperCase()
                .replace(/[_-]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            // Do not show stale NOT ISSUED when an actual issued quantity exists.
            if (
                Number.isFinite(issuedQty) &&
                issuedQty > 0 &&
                (normalizedStatus === 'NOT ISSUED' || normalizedStatus === 'NOT ISSUED REQUEST LINE')
            ) {
                status = 'ISSUED';
            }

            const statusClass = verifyStatusClass(status);
            const receivedQty = Number(line.cache_received_qty || 0);
            const rawReceivedQty = Number(line.cache_raw_received_qty || 0);
            const isReceivedStatus =
                status === 'RECEIVED' ||
                status === 'PARTIAL_RECEIVED' ||
                status === 'PARTIAL RECEIVED';
            const isLotMismatchStatus =
                status === 'LOT_MISMATCH' ||
                status === 'PARTIAL_LOT_MISMATCH' ||
                status === 'LOT MISMATCH' ||
                status === 'PARTIAL + LOT MISMATCH';
            const hasActualReceipt =
                isReceivedStatus &&
                Number.isFinite(receivedQty) &&
                receivedQty > 0;
            const hasMismatchEvidence =
                isLotMismatchStatus &&
                Number.isFinite(rawReceivedQty) &&
                rawReceivedQty > 0;

            const receivedLot = (hasActualReceipt || hasMismatchEvidence) ?
                (line.cache_received_lot_no || line.cache_lot_no || '') :
                '';
            const sourceTransfer = (hasActualReceipt || hasMismatchEvidence) ?
                (line.source_transfer_details || '') :
                '';
            const receivedBy = (hasActualReceipt || hasMismatchEvidence) ?
                (line.cache_received_by || '') :
                '';
            const receivedAt = (hasActualReceipt || hasMismatchEvidence) ?
                (line.cache_received_at || '') :
                '';
            const qtyDisplay = hasActualReceipt ?
                verifyNumber(receivedQty) :
                (hasMismatchEvidence ? verifyNumber(rawReceivedQty) : '');

            return '<tr>' +
                '<td><span class="status-pill status-' + statusClass + '">' +
                verifyEscape(status) +
                '</span></td>' +
                '<td>' + verifyEscape(line.item_code) + '</td>' +
                '<td>' + verifyEscape(line.part_name) + '</td>' +
                '<td class="text-end">' + verifyEscape(verifyNumber(line.issued_qty)) + '</td>' +
                '<td>' + verifyEscape(line.issued_at || '') + '</td>' +
                '<td class="text-end">' + verifyEscape(qtyDisplay) + '</td>' +
                '<td>' + verifyEscape(line.lot_no) + '</td>' +
                '<td>' + verifyEscape(line.warehouse_lot_no) + '</td>' +
                '<td>' + verifyEscape(receivedLot) + '</td>' +
                '<td class="source-transfer-cell">' + verifyEscape(sourceTransfer) + '</td>' +
                '<td>' + verifyEscape(receivedBy) + '</td>' +
                '<td>' + verifyEscape(receivedAt) + '</td>' +
                '<td>' + verifyEscape(line.cache_last_synced_at) + '</td>' +
                '</tr>';
        }).join('');
    }

    async function openReceiveVerification(requestNo) {
        if (!receiveVerifyModal || !requestNo) {
            return;
        }

        verifySetLoading(requestNo);
        receiveVerifyModal.show();

        try {
            const response = await fetch(
                'api/requestor/verify_receive.php?request_no=' + encodeURIComponent(requestNo), {
                    cache: 'no-store'
                }
            );
            const data = await response.json();

            if (!data.ok) {
                document.getElementById('receiveVerifyStatus').className = 'alert alert-warning';
                document.getElementById('receiveVerifyStatus').textContent =
                    data.message || 'Unable to verify receive status.';
                return;
            }

            document.getElementById('receiveVerifyTitle').textContent = 'Receive Verification';
            document.getElementById('receiveVerifySubtitle').textContent =
                data.request_no + ' | ITR ' + (data.itr_number || '-') +
                ' | Cache ' + (data.latest_scanplus_cache_sync || 'not synced');
            document.getElementById('receiveVerifyStatus').className = 'alert alert-info';
            document.getElementById('receiveVerifyStatus').textContent =
                data.source || 'Checked local ScanPlus / SAP verification cache.';
            document.getElementById('receiveVerifyOverall').innerHTML =
                verifyOverallStatus(data.summary || {});
            document.getElementById('receiveVerifySummary').innerHTML =
                verifyRenderSummary(data.summary || {});
            document.getElementById('receiveVerifyRows').innerHTML =
                verifyRenderRows(data.lines || []);
            document.getElementById('receiveVerifyTableWrap').classList.remove('d-none');
        } catch (error) {
            document.getElementById('receiveVerifyStatus').className = 'alert alert-danger';
            document.getElementById('receiveVerifyStatus').textContent =
                'Unable to load receive verification.';
            console.error(error);
        }
    }

    document.querySelectorAll('.request-verify-link').forEach(function(button) {
        button.addEventListener('click', function() {
            openReceiveVerification(button.dataset.requestNo || '');
        });
    });
    </script>

</body>

</html>
