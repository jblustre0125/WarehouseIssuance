<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function grpo_api_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function grpo_api_date(string $name, string $default): string
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function grpo_api_error(string $prefix): string
{
    return $prefix . ': ' . print_r(sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [], true);
}

$startedAt = microtime(true);
$today = date('Y-m-d');
$dateFrom = grpo_api_date('date_from', $today);
$dateTo = grpo_api_date('date_to', $dateFrom);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

if ($dateFrom > $dateTo) {
    grpo_api_out(['ok' => false, 'message' => 'Date From cannot be later than Date To.', 'rows' => []], 422);
}

$whp = get_whpokayoke_connection();

$tableCheck = sqlsrv_query(
    $whp,
    "SELECT CASE WHEN OBJECT_ID(N'dbo.PickerGrpoReceiptCache', N'U') IS NULL THEN 0 ELSE 1 END AS Ready"
);
if ($tableCheck === false) {
    grpo_api_out(['ok' => false, 'message' => grpo_api_error('Unable to verify GRPO cache table'), 'rows' => []], 500);
}
$tableRow = sqlsrv_fetch_array($tableCheck, SQLSRV_FETCH_ASSOC) ?: [];
if ((int)($tableRow['Ready'] ?? 0) !== 1) {
    grpo_api_out([
        'ok' => false,
        'message' => 'GRPO cache table is not installed. Run 01_create_picker_grpo_cache.sql.',
        'rows' => [],
    ], 503);
}

$where = [
    'GrpoDocDate >= ?',
    'GrpoDocDate < DATEADD(day, 1, ?)',
];
$params = [$dateFrom, $dateTo];

if ($q !== '') {
    $escaped = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q);
    $like = '%' . $escaped . '%';
    $where[] = "(
        CONVERT(NVARCHAR(40), PoDocNum) LIKE ?
        OR CONVERT(NVARCHAR(40), GrpoDocNum) LIKE ?
        OR VendorCode LIKE ?
        OR VendorName LIKE ?
        OR ItemCode LIKE ?
        OR PartName LIKE ?
        OR LotNo LIKE ?
        OR PoWarehouse LIKE ?
        OR GrpoWarehouse LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$countStmt = sqlsrv_query(
    $whp,
    "SELECT COUNT_BIG(*) AS TotalRows
     FROM dbo.PickerGrpoReceiptCache WITH (NOLOCK)
     WHERE {$whereSql}",
    $params
);
if ($countStmt === false) {
    grpo_api_out(['ok' => false, 'message' => grpo_api_error('Unable to count cached GRPO rows'), 'rows' => []], 500);
}
$countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC) ?: [];
$totalRows = (int)($countRow['TotalRows'] ?? 0);

$rowParams = array_merge($params, [$offset, $pageSize]);
$rowStmt = sqlsrv_query(
    $whp,
    "SELECT
        PoDocEntry,
        PoDocNum,
        CONVERT(VARCHAR(10), PoDocDate, 23) AS PoDocDate,
        PoLineNum,
        VendorCode,
        VendorName,
        GrpoDocEntry,
        GrpoDocNum,
        CONVERT(VARCHAR(10), GrpoDocDate, 23) AS GrpoDocDate,
        GrpoLineNum,
        ItemCode,
        PartName,
        LotNo,
        ReceivedQty,
        GrpoLineQty,
        OrderedQty,
        Uom,
        PoWarehouse,
        GrpoWarehouse,
        CONVERT(VARCHAR(19), SyncedAt, 120) AS SyncedAt
     FROM dbo.PickerGrpoReceiptCache WITH (NOLOCK)
     WHERE {$whereSql}
     ORDER BY GrpoDocDate DESC, GrpoDocNum DESC, GrpoLineNum ASC, LotNo ASC
     OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
    $rowParams
);
if ($rowStmt === false) {
    grpo_api_out(['ok' => false, 'message' => grpo_api_error('Unable to load cached GRPO rows'), 'rows' => []], 500);
}

$rows = [];
$totalQty = 0.0;
while ($row = sqlsrv_fetch_array($rowStmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
    $totalQty += (float)($row['ReceivedQty'] ?? 0);
}

$status = null;
$statusStmt = sqlsrv_query(
    $whp,
    "SELECT TOP 1
        LastStatus,
        LastMessage,
        LastRowCount,
        CONVERT(VARCHAR(19), LastStartedAt, 120) AS LastStartedAt,
        CONVERT(VARCHAR(19), LastSuccessfulAt, 120) AS LastSuccessfulAt,
        CONVERT(VARCHAR(19), LastFinishedAt, 120) AS LastFinishedAt,
        CONVERT(VARCHAR(10), WindowDateFrom, 23) AS WindowDateFrom,
        CONVERT(VARCHAR(10), WindowDateTo, 23) AS WindowDateTo
     FROM dbo.PickerGrpoCacheStatus WITH (NOLOCK)
     WHERE StatusID = 1"
);
if ($statusStmt !== false) {
    $status = sqlsrv_fetch_array($statusStmt, SQLSRV_FETCH_ASSOC) ?: null;
}

grpo_api_out([
    'ok' => true,
    'page' => $page,
    'page_size' => $pageSize,
    'has_more' => ($offset + count($rows)) < $totalRows,
    'total_rows' => $totalRows,
    'from_cache' => true,
    'cache_type' => 'database',
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => $q,
    'total_qty' => $totalQty,
    'rows' => $rows,
    'sync_status' => $status,
    '_performance' => [
        'response_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ],
]);
