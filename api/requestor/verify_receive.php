<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function verify_receive_json($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function verify_receive_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return $value === null ? '' : (string)$value;
}

function verify_receive_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = ?",
        [$table]
    );
}

function verify_receive_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

function verify_receive_qty($value)
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function verify_receive_status(array $line)
{
    $issuedQty = verify_receive_qty($line['IssuedQty'] ?? 0);
    $receivedQty = verify_receive_qty($line['CacheReceivedQty'] ?? 0);
    $cacheStatus = strtoupper(trim((string)($line['CacheStatus'] ?? '')));
    $hasCacheRow = (int)($line['HasCacheRow'] ?? 0) === 1;

    /* A request line that was never issued must never borrow a SAP receipt. */
    if ($issuedQty <= 0) {
        return 'NOT_ISSUED_REQUEST_LINE';
    }

    if (in_array($cacheStatus, [
        'NOT_ISSUED_REQUEST_LINE',
        'NOT_ALLOCATED_TO_REQUEST_LINE',
        'LOT_REQUIRED_FOR_ALLOCATION',
        'AMBIGUOUS_REQUEST_MATCH'
    ], true)) {
        return $cacheStatus;
    }

    if ($receivedQty > 0 && $receivedQty + 0.0005 >= $issuedQty) {
        return 'RECEIVED';
    }

    if ($receivedQty > 0) {
        return 'PARTIAL_RECEIVED';
    }

    if (!$hasCacheRow) {
        return 'CACHE_MISSING';
    }

    if ($cacheStatus === 'NOT RECEIVED IN SAP') {
        return 'NOT_RECEIVED_IN_SAP_CACHE';
    }

    if ($cacheStatus === 'OLD_CACHE_RECEIVE') {
        return 'NOT_CONFIRMED';
    }

    return 'NOT_CONFIRMED';
}

$requestNo = trim((string)($_GET['request_no'] ?? $_GET['request'] ?? ''));

if ($requestNo === '') {
    verify_receive_json([
        'ok' => false,
        'message' => 'Missing request_no.'
    ], 400);
}

$conn = get_whpokayoke_connection();

foreach ([
    'WarehouseIssueRequestHeader',
    'WarehouseIssueRequestLines',
    'RawmatTraceScanPlusCache'
] as $table) {
    if (!verify_receive_has_table($conn, $table)) {
        verify_receive_json([
            'ok' => false,
            'message' => "Required local table is missing: {$table}"
        ], 500);
    }
}

$lineWarehouseLotExpr = verify_receive_has_column($conn, 'WarehouseIssueRequestLines', 'WarehouseLotNo')
    ? "NULLIF(LTRIM(RTRIM(L.WarehouseLotNo)), '')"
    : "CAST(NULL AS NVARCHAR(100))";

$cacheHasReceivedLot = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'ReceivedLotNo');

$cacheReceivedLotExpr = $cacheHasReceivedLot
    ? "NULLIF(LTRIM(RTRIM(C0.ReceivedLotNo)), '')"
    : "CAST(NULL AS NVARCHAR(100))";

$cacheReceivedLotMatchSql = $cacheHasReceivedLot
    ? "
                OR LTRIM(RTRIM(C0.ReceivedLotNo)) = LTRIM(RTRIM(B.LotNo))
                OR LTRIM(RTRIM(C0.ReceivedLotNo)) = LTRIM(RTRIM(B.WarehouseLotNo))
                OR (
                    TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, B.LotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo) = TRY_CONVERT(BIGINT, B.LotNo)
                )
                OR (
                    TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, B.WarehouseLotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo) = TRY_CONVERT(BIGINT, B.WarehouseLotNo)
                )"
    : '';

$cacheReceivedLotOrderSql = $cacheHasReceivedLot
    ? "
                WHEN NULLIF(LTRIM(RTRIM(C0.ReceivedLotNo)), '') IS NOT NULL
                     AND (
                        LTRIM(RTRIM(C0.ReceivedLotNo)) IN (LTRIM(RTRIM(B.LotNo)), LTRIM(RTRIM(B.WarehouseLotNo)))
                        OR (
                            TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, B.LotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo) = TRY_CONVERT(BIGINT, B.LotNo)
                        )
                        OR (
                            TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, B.WarehouseLotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo) = TRY_CONVERT(BIGINT, B.WarehouseLotNo)
                        )
                     )
                    THEN 1"
    : '';

$cacheStatusExpr = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'ScanStatus')
    ? 'C0.ScanStatus'
    : "CAST(NULL AS NVARCHAR(50))";

$cacheReceivedQtyExpr = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'ReceivedQty')
    ? 'TRY_CONVERT(DECIMAL(18, 3), C0.ReceivedQty)'
    : 'CAST(NULL AS DECIMAL(18, 3))';

$cacheBarcodeUserExpr = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'BarcodeUser')
    ? 'C0.BarcodeUser'
    : "CAST(NULL AS NVARCHAR(120))";

$cacheReceivedAtExpr = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'ReceivedAt')
    ? 'C0.ReceivedAt'
    : 'CAST(NULL AS DATETIME)';

$cacheHasLastSyncedAt = verify_receive_has_column($conn, 'RawmatTraceScanPlusCache', 'LastSyncedAt');

$cacheLastSyncedAtExpr = $cacheHasLastSyncedAt
    ? 'C0.LastSyncedAt'
    : 'CAST(NULL AS DATETIME)';

$cacheLastSyncedOrderExpr = $cacheHasLastSyncedAt
    ? 'C0.LastSyncedAt'
    : 'C0.SAP_IT_DocEntry';

$hasLineReceiveCache = verify_receive_has_table($conn, 'WarehouseIssueRequestLineReceiveCache')
    && verify_receive_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'RequestLineID')
    && verify_receive_has_column($conn, 'WarehouseIssueRequestLineReceiveCache', 'IsCurrentMatch');

$lineReceiveApply = "
    OUTER APPLY
    (
        SELECT
            CAST(NULL AS INT) AS RequestLineID,
            CAST(0 AS BIT) AS IsCurrentMatch,
            CAST(NULL AS NVARCHAR(50)) AS MatchStatus,
            CAST(NULL AS DECIMAL(18, 3)) AS RawReceivedQty,
            CAST(NULL AS DECIMAL(18, 3)) AS ReceivedQty,
            CAST(NULL AS NVARCHAR(80)) AS ReceivedLotNo,
            CAST(NULL AS NVARCHAR(120)) AS BarcodeUser,
            CAST(NULL AS DATETIME) AS ReceivedAt,
            CAST(NULL AS DATETIME) AS LastSyncedAt
    ) M
";

if ($hasLineReceiveCache) {
    $lineReceiveApply = "
        OUTER APPLY
        (
            SELECT TOP (1)
                M0.RequestLineID,
                ISNULL(M0.IsCurrentMatch, 0) AS IsCurrentMatch,
                M0.MatchStatus,
                M0.RawReceivedQty,
                M0.ReceivedQty,
                M0.ReceivedLotNo,
                M0.BarcodeUser,
                M0.ReceivedAt,
                M0.LastSyncedAt
            FROM dbo.WarehouseIssueRequestLineReceiveCache M0
            WHERE M0.RequestLineID = B.RequestLineID
            ORDER BY
                CASE WHEN ISNULL(M0.IsCurrentMatch, 0) = 1 THEN 0 ELSE 1 END,
                M0.LastSyncedAt DESC
        ) M
    ";
}

$user = current_user();
$role = strtolower(trim((string)($user['role'] ?? $user['RoleName'] ?? '')));
$where = ['H.RequestNo = ?'];
$params = [$requestNo];

if ($role !== ROLE_ADMIN) {
    $where[] = '(H.RequestedByUserID = ? OR H.RequestedByUsername = ?)';
    $params[] = (int)($user['id'] ?? $user['user_id'] ?? $user['UserID'] ?? 0);
    $params[] = (string)($user['username'] ?? $user['Username'] ?? '');
}

$rows = fetch_all(
    $conn,
    "
    WITH RequestLines AS
    (
        SELECT
            H.RequestID,
            H.RequestNo,
            H.ITRNumber,
            H.RequestedAt,
            H.RequestedByUsername,
            H.Status AS HeaderStatus,
            L.RequestLineID,
            COALESCE(NULLIF(L.SAP_IT_DocEntry, 0), H.SAP_IT_DocEntry) AS SAP_IT_DocEntry,
            L.SAP_IT_LineNum,
            L.ItemCode,
            L.PartName,
            TRY_CONVERT(DECIMAL(18, 3), L.RequestedQty) AS RequestedQty,
            TRY_CONVERT(DECIMAL(18, 3), ISNULL(L.IssuedQty, 0)) AS IssuedQty,
            NULLIF(LTRIM(RTRIM(L.LotNo)), '') AS LotNo,
            {$lineWarehouseLotExpr} AS WarehouseLotNo,
            L.Status AS LineStatus
        FROM dbo.WarehouseIssueRequestHeader H
        INNER JOIN dbo.WarehouseIssueRequestLines L
            ON L.RequestID = H.RequestID
        WHERE " . implode(' AND ', $where) . "
    )
    SELECT
        B.*,
        CASE
            WHEN C.SAP_IT_DocEntry IS NULL AND M.RequestLineID IS NULL THEN 0
            ELSE 1
        END AS HasCacheRow,
        C.LotNo AS CacheLotNo,
        COALESCE(M.ReceivedLotNo, C.ReceivedLotNo) AS CacheReceivedLotNo,
        CASE
            WHEN ISNULL(B.IssuedQty, 0) <= 0
                THEN 'NOT_ISSUED_REQUEST_LINE'
            WHEN M.RequestLineID IS NULL AND C.SAP_IT_DocEntry IS NOT NULL
                THEN 'NOT_ALLOCATED_TO_REQUEST_LINE'
            WHEN M.RequestLineID IS NULL
                THEN NULL
            ELSE M.MatchStatus
        END AS CacheStatus,
        CASE
            WHEN ISNULL(B.IssuedQty, 0) > 0
                 AND ISNULL(M.IsCurrentMatch, 0) = 1
                THEN M.ReceivedQty
            ELSE NULL
        END AS CacheReceivedQty,
        COALESCE(M.RawReceivedQty, C.ReceivedQty) AS CacheRawReceivedQty,
        CASE
            WHEN ISNULL(M.IsCurrentMatch, 0) = 1 THEN M.BarcodeUser
            ELSE NULL
        END AS CacheReceivedBy,
        CASE
            WHEN ISNULL(M.IsCurrentMatch, 0) = 1 THEN M.ReceivedAt
            ELSE NULL
        END AS CacheReceivedAt,
        COALESCE(M.LastSyncedAt, C.LastSyncedAt) AS CacheLastSyncedAt
    FROM RequestLines B
    OUTER APPLY
    (
        SELECT TOP (1)
            C0.SAP_IT_DocEntry,
            NULLIF(LTRIM(RTRIM(C0.LotNo)), '') AS LotNo,
            {$cacheReceivedLotExpr} AS ReceivedLotNo,
            {$cacheStatusExpr} AS ScanStatus,
            {$cacheReceivedQtyExpr} AS ReceivedQty,
            {$cacheBarcodeUserExpr} AS BarcodeUser,
            {$cacheReceivedAtExpr} AS ReceivedAt,
            {$cacheLastSyncedAtExpr} AS LastSyncedAt
        FROM dbo.RawmatTraceScanPlusCache C0
        WHERE C0.SAP_IT_DocEntry = B.SAP_IT_DocEntry
          AND ISNULL(C0.SAP_IT_LineNum, -1) = ISNULL(B.SAP_IT_LineNum, -1)
          AND C0.ItemCode = B.ItemCode
          AND
          (
                (
                    NULLIF(LTRIM(RTRIM(B.LotNo)), '') IS NULL
                    AND NULLIF(LTRIM(RTRIM(B.WarehouseLotNo)), '') IS NULL
                )
                OR NULLIF(LTRIM(RTRIM(C0.LotNo)), '') IS NULL
                OR LTRIM(RTRIM(C0.LotNo)) = LTRIM(RTRIM(B.LotNo))
                OR LTRIM(RTRIM(C0.LotNo)) = LTRIM(RTRIM(B.WarehouseLotNo))
                OR (
                    TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, B.LotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, C0.LotNo) = TRY_CONVERT(BIGINT, B.LotNo)
                )
                OR (
                    TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, B.WarehouseLotNo) IS NOT NULL
                    AND TRY_CONVERT(BIGINT, C0.LotNo) = TRY_CONVERT(BIGINT, B.WarehouseLotNo)
                )
                {$cacheReceivedLotMatchSql}
          )
        ORDER BY
            CASE
                WHEN NULLIF(LTRIM(RTRIM(C0.LotNo)), '') IS NOT NULL
                     AND (
                        LTRIM(RTRIM(C0.LotNo)) IN (LTRIM(RTRIM(B.LotNo)), LTRIM(RTRIM(B.WarehouseLotNo)))
                        OR (
                            TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, B.LotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, C0.LotNo) = TRY_CONVERT(BIGINT, B.LotNo)
                        )
                        OR (
                            TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, B.WarehouseLotNo) IS NOT NULL
                            AND TRY_CONVERT(BIGINT, C0.LotNo) = TRY_CONVERT(BIGINT, B.WarehouseLotNo)
                        )
                     )
                    THEN 0
                {$cacheReceivedLotOrderSql}
                WHEN NULLIF(LTRIM(RTRIM(C0.LotNo)), '') IS NULL
                    THEN 2
                ELSE 3
            END,
            CASE WHEN ISNULL(TRY_CONVERT(DECIMAL(18, 3), C0.ReceivedQty), 0) > 0 THEN 0 ELSE 1 END,
            {$cacheLastSyncedOrderExpr} DESC
    ) C
    {$lineReceiveApply}
    ORDER BY B.RequestLineID ASC
    ",
    $params
);

if (empty($rows)) {
    verify_receive_json([
        'ok' => false,
        'message' => 'No request lines were found for that request number.',
        'request_no' => $requestNo,
        'lines' => []
    ], 404);
}

$latestCacheSync = $cacheHasLastSyncedAt
    ? fetch_one(
        $conn,
        "SELECT MAX(LastSyncedAt) AS LatestSync FROM dbo.RawmatTraceScanPlusCache"
    )
    : ['LatestSync' => ''];

$lines = [];
$summary = [
    'received' => 0,
    'partial_received' => 0,
    'not_confirmed' => 0,
    'cache_missing' => 0,
    'not_received_in_sap_cache' => 0,
    'old_cache_receive' => 0,
    'not_issued_request_line' => 0,
    'not_allocated_to_request_line' => 0,
    'lot_required_for_allocation' => 0,
    'ambiguous_request_match' => 0,
];

foreach ($rows as $row) {
    $status = verify_receive_status($row);
    $summaryKey = strtolower($status);

    if (isset($summary[$summaryKey])) {
        $summary[$summaryKey]++;
    }

    $lines[] = [
        'request_line_id' => (int)($row['RequestLineID'] ?? 0),
        'itr_number' => (string)($row['ITRNumber'] ?? ''),
        'sap_it_doc_entry' => $row['SAP_IT_DocEntry'] !== null ? (int)$row['SAP_IT_DocEntry'] : null,
        'sap_it_line_num' => $row['SAP_IT_LineNum'] !== null ? (int)$row['SAP_IT_LineNum'] : null,
        'item_code' => (string)($row['ItemCode'] ?? ''),
        'part_name' => (string)($row['PartName'] ?? ''),
        'requested_qty' => verify_receive_qty($row['RequestedQty'] ?? 0),
        'issued_qty' => verify_receive_qty($row['IssuedQty'] ?? 0),
        'lot_no' => (string)($row['LotNo'] ?? ''),
        'warehouse_lot_no' => (string)($row['WarehouseLotNo'] ?? ''),
        'cache_status' => (string)($row['CacheStatus'] ?? ''),
        'cache_received_qty' => verify_receive_qty($row['CacheReceivedQty'] ?? 0),
        'cache_raw_received_qty' => verify_receive_qty($row['CacheRawReceivedQty'] ?? 0),
        'cache_lot_no' => (string)($row['CacheLotNo'] ?? ''),
        'cache_received_lot_no' => (string)($row['CacheReceivedLotNo'] ?? ''),
        'cache_received_by' => (string)($row['CacheReceivedBy'] ?? ''),
        'cache_received_at' => verify_receive_cell($row['CacheReceivedAt'] ?? ''),
        'cache_last_synced_at' => verify_receive_cell($row['CacheLastSyncedAt'] ?? ''),
        'verification_status' => $status
    ];
}

verify_receive_json([
    'ok' => true,
    'request_no' => (string)($rows[0]['RequestNo'] ?? $requestNo),
    'itr_number' => (string)($rows[0]['ITRNumber'] ?? ''),
    'requested_by' => (string)($rows[0]['RequestedByUsername'] ?? ''),
    'requested_at' => verify_receive_cell($rows[0]['RequestedAt'] ?? ''),
    'latest_scanplus_cache_sync' => verify_receive_cell($latestCacheSync['LatestSync'] ?? ''),
    'summary' => $summary,
    'lines' => $lines,
    'source' => 'WHPOKAYOKE request-line receive cache and dbo.RawmatTraceScanPlusCache only; no browser SAP query was executed.'
]);
?>
