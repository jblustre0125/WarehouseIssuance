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

// Local receiver confirmation is the safest sign that the receiver process has run.
// SAP/ScanPlus can sometimes return transfer data before local receiving is finalized.
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
        WHERE TH.TraceNo = IT.TraceNo
          AND TL.ItemCode = IT.ItemCode
          AND (
                ISNULL(TL.LotNo, NCHAR(0)) = ISNULL(IT.LotNo, NCHAR(0))
                OR LEN(LTRIM(RTRIM(ISNULL(TL.LotNo, NCHAR(0))))) = 0
                OR LEN(LTRIM(RTRIM(ISNULL(IT.LotNo, NCHAR(0))))) = 0
                {$localWarehouseLotMatchSql}
          )
        ORDER BY TL.TraceLineID DESC
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
            IT.IssuedByUsername,
            IT.DeviceHostname,
            IT.DeviceIPAddress,
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
        IT.DeviceHostname,
        IT.DeviceIPAddress,
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
                H.IssuedTraceNo = IT.TraceNo
                OR (
                    L.SAP_IT_DocEntry = IT.ITRDocEntry
                    AND L.SAP_IT_LineNum = IT.ITRLineNum
                )
            )
            AND L.ItemCode = IT.ItemCode
            AND (
                ISNULL(L.LotNo, NCHAR(0)) = ISNULL(IT.LotNo, NCHAR(0))
                OR LEN(LTRIM(RTRIM(ISNULL(L.LotNo, NCHAR(0))))) = 0
                OR LEN(LTRIM(RTRIM(ISNULL(IT.LotNo, NCHAR(0))))) = 0
            )
        ORDER BY
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

    if ($allowLiveRefresh && !empty($refsToRefresh) && sap_cache_live_queries_enabled()) {
        $freshScanplusRows = scanplus_lookup_by_itr_lines(get_erp_connection(), $refsToRefresh);

        foreach ($refsToRefresh as $ref) {
            $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
            $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
            $scan = $scanLotKey !== ''
                ? ($freshScanplusRows[$scanLotKey] ?? null)
                : ($scanKey !== '' ? ($freshScanplusRows[$scanKey] ?? null) : null);

            scanplus_cache_write($whpConn, $ref, $scan);

            if ($scanLotKey !== '') {
                $scanplusRows[$scanLotKey] = $scan ?? [];
            }

            if ($scanKey !== '') {
                $scanplusRows[$scanKey] = $scan ?? [];
            }
        }
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
                {$receivedAtSelect}
             FROM dbo.WarehouseIssueRequestLineReceiveCache
             WHERE RequestLineID IN ({$placeholders})
               AND ISNULL(IsCurrentMatch, 0) = 1",
            $chunk
        );

        foreach ($mappedRows as $mappedRow) {
            $mappedByLine[(int)$mappedRow['RequestLineID']] = $mappedRow;
        }
    }

    foreach ($rows as &$row) {
        $id = (int)($row['RequestLineID'] ?? 0);

        if ($id <= 0 || !isset($mappedByLine[$id])) {
            continue;
        }

        $mapped = $mappedByLine[$id];
        $row['CacheMatchStatus'] = $mapped['MatchStatus'] ?? $row['CacheMatchStatus'] ?? '';
        $row['ScanStatus'] = $mapped['ScanStatus'] ?? $row['ScanStatus'] ?? '';
        $row['ReceivedLotNo'] = $mapped['ReceivedLotNo'] ?? $row['ReceivedLotNo'] ?? '';
        $row['ReceivedQty'] = $mapped['ReceivedQty'] ?? $row['ReceivedQty'] ?? '';
        $row['BarcodeUser'] = $mapped['BarcodeUser'] ?? $row['BarcodeUser'] ?? '';
        $row['ReceivedAt'] = $mapped['ReceivedAt'] ?? $row['ReceivedAt'] ?? '';
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

    return date('Y-m-d', $receivedAt) < date('Y-m-d', $issuedAt);
}

function issuer_report_received_status($status): bool
{
    $status = strtoupper(trim((string)$status));
    return in_array($status, ['RECEIVED', 'CLOSED', 'COMPLETED', 'MATCHED'], true);
}

function issuer_row_is_received($row): bool
{
    return issuer_report_received_status($row['RequestHeaderStatus'] ?? '') ||
        issuer_report_received_status($row['RequestLineStatus'] ?? '') ||
        issuer_report_received_status($row['LocalReceiveStatus'] ?? '') ||
        issuer_report_received_status($row['ScanStatus'] ?? '') ||
        ((float)($row['LocalReceivedQty'] ?? 0) > 0) ||
        ((float)($row['ReceivedQty'] ?? 0) > 0) ||
        trim((string)($row['LocalScannedBy'] ?? '')) !== '' ||
        issuer_report_valid_datetime($row['LocalReceivedAt'] ?? '') ||
        issuer_report_valid_datetime($row['ReceivedAt'] ?? '');
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

function report_received_value($row, $field)
{
    if (!issuer_row_is_received($row)) {
        return '';
    }

    if ($field === 'BarcodeUser') {
        $local = trim((string)($row['LocalScannedBy'] ?? ''));
        if ($local !== '') {
            return $local;
        }
    }

    if ($field === 'ReceivedAt') {
        $localDate = $row['LocalReceivedAt'] ?? '';
        if (issuer_report_valid_datetime($localDate)) {
            return report_cell($localDate);
        }

        $dateValue = $row[$field] ?? '';
        return issuer_report_valid_datetime($dateValue) ? report_cell($dateValue) : '';
    }

    if ($field === 'ReceivedLotNo') {
        $localLot = trim((string)($row['LocalReceivedLotNo'] ?? ''));

        if ($localLot !== '') {
            return $localLot;
        }

        return trim((string)($row[$field] ?? ''));
    }

    if ($field === 'ReceivedQty') {
        $localQty = $row['LocalReceivedQty'] ?? '';

        if (trim((string)$localQty) !== '' && is_numeric($localQty) && (float)$localQty > 0) {
            return issuer_cap_received_qty($localQty, $row);
        }

        return issuer_cap_received_qty($row[$field] ?? 0, $row);
    }

    return $row[$field] ?? '';
}

function issuer_report_receive_verification(array $row): array
{
    if (!issuer_row_is_received($row)) {
        return [
            'status' => 'PENDING_RECEIVE',
            'note' => 'No requestor receipt has been confirmed yet.'
        ];
    }

    $issuedQty = is_numeric($row['Quantity'] ?? null) ? (float)$row['Quantity'] : null;
    $receivedText = trim((string)($row['DisplayReceivedQty'] ?? ''));
    $receivedQty = is_numeric($receivedText) ? (float)$receivedText : null;
    $qtyMatches = $issuedQty !== null && $receivedQty !== null && abs($issuedQty - $receivedQty) <= 0.0005;

    $receivedLot = trim((string)($row['DisplayReceivedLotNo'] ?? ''));
    $issuedLots = array_filter([
        trim((string)($row['LotNo'] ?? '')),
        trim((string)($row['WarehouseLotNo'] ?? ''))
    ], static function ($lot) {
        return $lot !== '';
    });

    $hasComparableLot = $receivedLot !== '' && !empty($issuedLots);
    $lotMatches = $hasComparableLot && issuer_report_lot_matches_any($receivedLot, $issuedLots);

    if ($qtyMatches && $lotMatches) {
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
        'Received qty: ' . report_cell($row['DisplayReceivedQty'] ?? '')
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

// Show received values only when receiving is confirmed locally or SAP returns a real
// receive timestamp/status. Do not show SAP_RECEIVED rows with the placeholder 1900 date.
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
            R.DeviceHostname,
            R.DeviceIPAddress,
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
    'Trace No',
    'Part No',
    'Part Name',
    'Req Qty',
    'Iss Qty',
    'Received Qty',
    'Variance',
    'Received Lot',
    'Verification',
    'GRPO Lot No',
    'WH Lot No',
    'ITR/IT',
    'Iss By',
    'Issue Status',
    'Received By',
    'Received At',
    'Hostname',
    'IP Address',
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
    'Hostname',
    'IP Address',
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
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook
    xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:html="http://www.w3.org/TR/REC-html40">
    <Styles>
        <Style ss:ID="Text"><Alignment ss:Vertical="Center"/><NumberFormat ss:Format="@"/></Style>
        <Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/><NumberFormat ss:Format="@"/></Style>
    </Styles>
    <Worksheet ss:Name="Issuer Scans">
        <Table>
            <?= excel_xml_row($columns, 'Header') . "\n" ?>
            <?php if (empty($rows)): ?>
                <?= excel_xml_row(['No records found.']) . "\n" ?>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?= excel_xml_row([
                        $r['TraceNo'] ?? '',
                        $r['ItemCode'] ?? '',
                        $r['PartName'] ?? '',
                        $r['RequestedQty'] ?? '',
                        $r['Quantity'] ?? '',
                        $r['DisplayReceivedQty'] ?? '',
                        $r['QtyVariance'] ?? '',
                        $r['DisplayReceivedLotNo'] ?? '',
                        $r['ReceiveVerification'] ?? '',
                        $r['LotNo'] ?? '',
                        $r['WarehouseLotNo'] ?? '',
                        $r['ITRNumber'] ?? '',
                        $r['IssuedByUsername'] ?? '',
                        $r['IssueStatus'] ?? 'ISSUED',
                        $r['DisplayBarcodeUser'] ?? '',
                        $r['DisplayReceivedAt'] ?? '',
                        $r['DeviceHostname'] ?? '',
                        $r['DeviceIPAddress'] ?? '',
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
                        $r['DeviceHostname'] ?? '',
                        $r['DeviceIPAddress'] ?? '',
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
            --sidebar-bg: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-active: #2563eb;
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

        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: #ffffff;
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1030;
            display: flex;
            flex-direction: column;
            box-shadow: 8px 0 30px rgba(15, 23, 42, 0.12);
        }

        .sidebar-brand {
            padding: 20px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .sidebar-title {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sidebar-subtitle {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-menu {
            padding: 14px 10px;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-section {
            color: #6b7280;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 12px 12px 6px;
        }

        .sidebar-link {
            color: #d1d5db;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 11px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .sidebar-link.active {
            background: var(--sidebar-active);
            color: #ffffff;
        }

        .sidebar-icon {
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-footer {
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-box {
            background: rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 11px;
            margin-bottom: 10px;
        }

        .user-name {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 12px;
            color: #9ca3af;
            text-transform: uppercase;
        }

        .logout-link {
            display: block;
            text-align: center;
            color: #fecaca;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 10px;
            border-radius: 10px;
        }

        .logout-link:hover {
            background: rgba(239, 68, 68, 0.14);
            color: #ffffff;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 18px;
            overflow-x: hidden;
        }

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .page-title {
            color: var(--text-dark);
            font-weight: 800;
            margin-bottom: 4px;
            letter-spacing: -0.03em;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        .content-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .content-card-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-soft);
            background: #ffffff;
        }

        .content-card-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
        }

        .content-card-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .content-card-body {
            padding: 18px;
        }

        .filter-box {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-control {
            border-radius: 11px;
            border: 1px solid #d9e2ef;
            min-height: 42px;
            font-size: 14px;
            background-color: #ffffff;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .report-table-wrap {
            max-height: 68vh;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .report-table {
            width: 100%;
            min-width: 1720px;
            table-layout: fixed;
            font-size: 10px;
            margin-bottom: 0;
        }

        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 8px 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table td {
            padding: 7px 5px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-trace { width: 9%; white-space: nowrap; }
        .col-item { width: 8%; white-space: nowrap; }
        .col-part { width: 14%; white-space: normal; line-height: 1.25; }
        .col-qty { width: 5%; text-align: right; white-space: nowrap; }
        .col-variance { width: 5%; text-align: right; white-space: nowrap; }
        .col-stock { width: 6%; text-align: right; white-space: nowrap; }
        .col-lot { width: 7%; white-space: nowrap; }
        .col-received-lot { width: 7%; white-space: nowrap; }
        .col-wh-lot { width: 7%; white-space: nowrap; }
        .col-itr { width: 6%; white-space: nowrap; }
        .col-user { width: 8%; white-space: nowrap; }
        .col-status { width: 7%; white-space: nowrap; }
        .col-verification { width: 9%; white-space: nowrap; }
        .col-host { width: 8%; white-space: nowrap; }
        .col-ip { width: 8%; white-space: nowrap; }
        .col-date { width: 10%; white-space: nowrap; }

        .empty-row {
            padding: 34px !important;
            text-align: center;
            color: #6b7280 !important;
        }

        .status-pill {
            display: inline-flex;
            max-width: 100%;
            align-items: center;
            justify-content: center;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-open,
        .status-pending,
        .status-pending_receive,
        .status-qty_match,
        .status-returned_no_stock {
            background: #fef3c7;
            color: #92400e;
        }

        .status-issued,
        .status-sap_received,
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
        .status-qty_variance,
        .status-lot_and_qty_variance {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 1300px) {
            .report-table {
                font-size: 10px;
            }

            .report-table thead th {
                font-size: 8px;
                padding: 7px 4px;
            }

            .report-table td {
                padding: 6px 4px;
            }

            .status-pill {
                font-size: 7.5px;
                padding: 3px 5px;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.2s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 14px;
            }

            .mobile-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #ffffff;
                border: 1px solid var(--border-soft);
                border-radius: 14px;
                padding: 12px 14px;
                margin-bottom: 14px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            }

            .sidebar-backdrop {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.45);
                z-index: 1029;
            }

            .sidebar-backdrop.show {
                display: block;
            }

            .page-header {
                flex-direction: column;
            }

            .report-table-wrap {
                overflow: auto;
            }

            .report-table {
                min-width: 1750px;
                table-layout: auto;
                font-size: 12px;
            }

            .report-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .report-table td {
                padding: 7px 6px;
                white-space: nowrap;
            }

            .col-trace,
            .col-item,
            .col-part,
            .col-qty,
            .col-stock,
            .col-lot,
            .col-wh-lot,
            .col-itr,
            .col-user,
            .col-status,
            .col-host,
            .col-ip,
            .col-date {
                width: auto;
                min-width: 100px;
            }

            .col-part {
                min-width: 240px;
            }

            .col-lot,
            .col-wh-lot {
                min-width: 150px;
            }

            .col-date {
                min-width: 160px;
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
                    Issued transaction history by date range. Issue status remains ISSUED; receiver details are shown separately when available.
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
                            <input
                                class="form-control"
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= h($dateFrom) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label" for="date_to">Date To</label>
                            <input
                                class="form-control"
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= h($dateTo) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <button class="btn btn-primary" type="submit">
                                Filter
                            </button>
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <a class="btn btn-success" href="<?= h(issuer_scan_report_url($baseQuery + ['export' => 'excel'])) ?>">
                                Export Excel
                            </a>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-12">
                            <label class="form-label" for="searchReport">Search Item / Report</label>
                            <input
                                class="form-control form-control-sm"
                                type="search"
                                id="searchReport"
                                name="q"
                                value="<?= h($q) ?>"
                                placeholder="Search SAP code, part name, trace, GRPO lot, WH lot, ITR, issuer..."
                            >
                            <div class="form-text">
                                Use SAP ItemCode or Part Name to search items. Press Enter or click Filter to search all records.
                            </div>
                        </div>
                    </div>
                </form>

                <div class="report-table-wrap">
                    <table class="table table-bordered table-striped align-middle report-table" id="reportTable">
                        <thead>
                            <tr>
                                <th class="col-trace">Trace No</th>
                                <th class="col-item">Part No</th>
                                <th class="col-part">Part Name</th>
                                <th class="col-qty">Req Qty</th>
                                <th class="col-qty">Iss Qty</th>
                                <th class="col-qty">Received Qty</th>
                                <th class="col-variance">Variance</th>
                                <th class="col-received-lot">Received Lot</th>
                                <th class="col-verification">Verification</th>
                                <th class="col-lot">GRPO Lot No</th>
                                <th class="col-wh-lot">WH Lot No</th>
                                <th class="col-itr">ITR/IT</th>
                                <th class="col-user">Iss By</th>
                                <th class="col-status">Issue Status</th>
                                <th class="col-user">Received By</th>
                                <th class="col-date">Received At</th>
                                <th class="col-host">Hostname</th>
                                <th class="col-ip">IP Address</th>
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
                                    ?>
                                    <tr>
                                        <td class="col-trace" title="<?= h(report_cell($r['TraceNo'] ?? '')) ?>">
                                            <?= h(report_cell($r['TraceNo'] ?? '')) ?>
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

                                        <td class="col-qty" title="Received qty: <?= h(report_cell($r['DisplayReceivedQty'] ?? '')) ?>">
                                            <?= h(report_cell($r['DisplayReceivedQty'] ?? '')) ?>
                                        </td>

                                        <td class="col-variance" title="Issued minus received: <?= h(report_cell($r['QtyVariance'] ?? '')) ?>">
                                            <?= h(report_cell($r['QtyVariance'] ?? '')) ?>
                                        </td>

                                        <td class="col-received-lot" title="<?= h(report_cell($r['DisplayReceivedLotNo'] ?? '')) ?>">
                                            <?= h(report_cell($r['DisplayReceivedLotNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-verification" title="<?= h(report_cell($r['ReceiveVerificationNote'] ?? '')) ?>">
                                            <span class="status-pill status-<?= h(strtolower((string)($r['ReceiveVerification'] ?? 'pending_receive'))) ?>">
                                                <?= h(report_cell($r['ReceiveVerification'] ?? 'PENDING_RECEIVE')) ?>
                                            </span>
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

                                        <td class="col-user" title="<?= h(report_cell($r['DisplayBarcodeUser'] ?? '')) ?>">
                                            <?= h(report_cell($r['DisplayBarcodeUser'] ?? '')) ?>
                                        </td>

                                        <td class="col-date" title="<?= h(report_cell($r['DisplayReceivedAt'] ?? '')) ?>">
                                            <?= h(report_cell($r['DisplayReceivedAt'] ?? '')) ?>
                                        </td>

                                        <td class="col-host" title="<?= h(report_cell($r['DeviceHostname'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceHostname'] ?? '')) ?>
                                        </td>

                                        <td class="col-ip" title="<?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>
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
                    Issue status shows the issuer transaction state. Receiver quantity uses local receiving first, then ScanPlus/SAP cache when available.
                </div>

                <?php if (!$export && $totalPages > 1): ?>
                    <nav class="mt-3" aria-label="Issuer scan report pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => max(1, $page - 1)])) ?>">Previous</a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => $p])) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a>
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
                    <table class="table table-bordered table-striped align-middle report-table" id="noStockReportTable">
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
                                <th class="col-host">Hostname</th>
                                <th class="col-ip">IP Address</th>
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
                                        <td class="col-trace" title="<?= h(report_cell($r['RequestNo'] ?? '')) ?>">
                                            <?= h(report_cell($r['RequestNo'] ?? '')) ?>
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
                                        <td class="col-host" title="<?= h(report_cell($r['DeviceHostname'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceHostname'] ?? '')) ?>
                                        </td>
                                        <td class="col-ip" title="<?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>
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
                        Showing latest <?= number_format(count($noStockRows)) ?> of <?= number_format($noStockTotalRows) ?> returned no-stock line(s).
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const searchInput = document.getElementById('searchReport');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#reportTable tbody tr, #noStockReportTable tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', function () {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
    });
}
</script>

</body>
</html>
