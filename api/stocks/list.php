<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_PICKER, ROLE_ISSUER, ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function stock_json_out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function stock_user_id($user)
{
    return (int)($user['id'] ?? $user['UserID'] ?? $user['user_id'] ?? 0);
}

function stock_has_column($conn, $table, $column)
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

$sectionWarehouseMap = [
    'backend' => ['HM', 'CSW', 'MR'],
    'back end' => ['HM', 'CSW', 'MR'],
    'cut and crimp' => ['CNC'],
    'cut & crimp' => ['CNC'],
    'sub-assy' => ['SA'],
    'sub assy' => ['SA'],
    'subassy' => ['SA'],
    'sub assembly' => ['SA'],
    'kitting' => ['KIT'],
];

$scope = strtolower(trim((string)($_GET['scope'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$u = current_user();
$role = strtolower((string)($u['role'] ?? ''));
$whp = get_whpokayoke_connection();

if ($scope === '') {
    $scope = in_array($role, [ROLE_PICKER, ROLE_ISSUER], true) ? 'issuer' : 'requestor';
}

$warehouses = [];
$sectionLabel = '';

if ($scope === 'issuer') {
    if (!in_array($role, [ROLE_PICKER, ROLE_ISSUER, ROLE_ADMIN], true)) {
        stock_json_out(['ok' => false, 'message' => 'Access denied for issuer stock.'], 403);
    }

    $warehouses = ['01'];
    $sectionLabel = 'Warehouse 01';
} elseif ($scope === 'requestor') {
    if (!in_array($role, [ROLE_REQUESTOR, ROLE_ADMIN], true)) {
        stock_json_out(['ok' => false, 'message' => 'Access denied for requestor stock.'], 403);
    }

    if ($role === ROLE_ADMIN) {
        $allWarehouses = [];

        foreach ($sectionWarehouseMap as $mappedWarehouses) {
            foreach ($mappedWarehouses as $warehouseCode) {
                $allWarehouses[] = $warehouseCode;
            }
        }

        $warehouses = array_values(array_unique($allWarehouses));
        $sectionLabel = 'All requestor warehouses';
    } else {
        $userId = stock_user_id($u);
        $username = trim((string)($u['username'] ?? ''));

        if ($userId > 0) {
            $sectionRow = fetch_one(
                $whp,
                'SELECT RequestorSection FROM AppUsers WHERE UserID = ?',
                [$userId]
            );
        } else {
            $sectionRow = fetch_one(
                $whp,
                'SELECT RequestorSection FROM AppUsers WHERE Username = ?',
                [$username]
            );
        }

        $sectionLabel = trim((string)($sectionRow['RequestorSection'] ?? ''));
        $sectionKey = strtolower(trim($sectionLabel));
        $sectionKey = preg_replace('/\s+/', ' ', $sectionKey);

        if ($sectionKey === '' || !isset($sectionWarehouseMap[$sectionKey])) {
            stock_json_out([
                'ok' => false,
                'message' => 'No warehouse mapping found for your requestor section.'
            ], 400);
        }

        $warehouses = $sectionWarehouseMap[$sectionKey];
    }
} else {
    stock_json_out(['ok' => false, 'message' => 'Unknown stock scope.'], 400);
}

$cacheKey = sap_cache_make_key('sap.stock.list', [
    'scope' => $scope,
    'search' => $search,
    'warehouses' => implode(',', $warehouses)
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($whp, $cacheKey);

    if ($cached !== null) {
        stock_json_out($cached);
    }
}

$erp = get_erp_connection();

$hasOitw = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OITW'"
);

if (!$hasOitw) {
    stock_json_out(['ok' => false, 'message' => 'SAP stock table OITW was not found.'], 500);
}

$committedExpr = stock_has_column($erp, 'OITW', 'IsCommited') ? 'W.IsCommited' : '0';
$onOrderExpr = stock_has_column($erp, 'OITW', 'OnOrder') ? 'W.OnOrder' : '0';

$placeholders = implode(',', array_fill(0, count($warehouses), '?'));
$where = [
    "W.WhsCode IN ({$placeholders})",
    'W.OnHand > 0'
];
$params = $warehouses;

if ($search !== '') {
    $where[] = '(W.ItemCode LIKE ? OR I.ItemName LIKE ? OR W.WhsCode LIKE ?)';
    $like = '%' . str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $search) . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$rows = fetch_all(
    $erp,
    "SELECT TOP 1000
        W.WhsCode,
        W.ItemCode,
        COALESCE(I.ItemName, '') AS ItemName,
        W.OnHand,
        {$committedExpr} AS IsCommited,
        {$onOrderExpr} AS OnOrder
     FROM OITW W
     LEFT JOIN OITM I ON I.ItemCode = W.ItemCode
     WHERE " . implode(' AND ', $where) . "
     ORDER BY W.WhsCode, W.ItemCode",
    $params
);

$stocks = [];

foreach ($rows as $row) {
    $onHand = (float)$row['OnHand'];
    $committed = (float)$row['IsCommited'];
    $onOrder = (float)$row['OnOrder'];

    $stocks[] = [
        'warehouse_code' => (string)$row['WhsCode'],
        'item_code' => (string)$row['ItemCode'],
        'item_name' => (string)$row['ItemName'],
        'on_hand_qty' => $onHand,
        'committed_qty' => $committed,
        'on_order_qty' => $onOrder,
        'available_qty' => $onHand - $committed + $onOrder
    ];
}

$payload = [
    'ok' => true,
    'scope' => $scope,
    'section' => $sectionLabel,
    'warehouses' => $warehouses,
    'count' => count($stocks),
    'limited' => count($stocks) >= 1000,
    'stocks' => $stocks
];

sap_cache_put($whp, 'sap.stock.list', $cacheKey, $payload, 300);
stock_json_out($payload);
?>
