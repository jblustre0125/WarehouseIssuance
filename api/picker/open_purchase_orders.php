<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function picker_po_json_out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function picker_po_has_column($conn, $table, $column)
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

function picker_po_dt($value)
{
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
}

$search = trim((string)($_GET['q'] ?? ''));
$whp = get_whpokayoke_connection();
$cacheKey = sap_cache_make_key('sap.picker.open_purchase_orders', [
    'search' => $search,
    'sort' => 'recent_docdate_docnum_desc'
]);

$cached = sap_cache_get_preferred($whp, $cacheKey);

if ($cached !== null) {
    picker_po_json_out($cached);
}

if (!sap_cache_live_queries_enabled()) {
    $payload = sap_cache_live_disabled_payload('Open purchase orders are served from cache only. Please wait for the scheduled SAP cache refresh.');
    $payload['documents'] = [];
    $payload['lines'] = [];
    picker_po_json_out($payload, 503);
}

$erp = get_erp_connection();

$hasOpor = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OPOR'"
);

$hasPor1 = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'POR1'"
);

if (!$hasOpor || !$hasPor1) {
    picker_po_json_out([
        'ok' => false,
        'message' => 'SAP purchase order tables OPOR/POR1 were not found.',
        'documents' => [],
        'lines' => []
    ], 500);
}

$hasCanceled = picker_po_has_column($erp, 'OPOR', 'CANCELED');
$hasDocStatus = picker_po_has_column($erp, 'OPOR', 'DocStatus');
$hasLineStatus = picker_po_has_column($erp, 'POR1', 'LineStatus');
$hasOpenQty = picker_po_has_column($erp, 'POR1', 'OpenQty');
$hasDscription = picker_po_has_column($erp, 'POR1', 'Dscription');
$hasWhsCode = picker_po_has_column($erp, 'POR1', 'WhsCode');
$hasUnitMsr = picker_po_has_column($erp, 'POR1', 'unitMsr');
$hasUomCode = picker_po_has_column($erp, 'POR1', 'UomCode');
$hasNumPerMsr = picker_po_has_column($erp, 'POR1', 'NumPerMsr');
$hasInvntryUom = picker_po_has_column($erp, 'OITM', 'InvntryUom');
$hasCardCode = picker_po_has_column($erp, 'OPOR', 'CardCode');
$hasCardName = picker_po_has_column($erp, 'OPOR', 'CardName');
$hasDocDueDate = picker_po_has_column($erp, 'OPOR', 'DocDueDate');

$openQtyExpr = $hasOpenQty ? 'L.OpenQty' : 'L.Quantity';
$partNameExpr = $hasDscription ? "COALESCE(NULLIF(L.Dscription, ''), I.ItemName, '')" : "COALESCE(I.ItemName, '')";
$whsExpr = $hasWhsCode ? 'L.WhsCode' : "CAST('' AS NVARCHAR(20))";
$itemUomExpr = $hasInvntryUom ? 'I.InvntryUom' : "CAST('' AS NVARCHAR(50))";
$uomExpr = $hasUnitMsr
    ? "COALESCE(NULLIF(L.unitMsr, ''), {$itemUomExpr}, '')"
    : ($hasUomCode ? "COALESCE(NULLIF(L.UomCode, ''), {$itemUomExpr}, '')" : "COALESCE({$itemUomExpr}, '')");
$numPerMsrExpr = $hasNumPerMsr ? 'L.NumPerMsr' : '1';
$cardCodeExpr = $hasCardCode ? 'H.CardCode' : "CAST('' AS NVARCHAR(50))";
$cardNameExpr = $hasCardName ? 'H.CardName' : "CAST('' AS NVARCHAR(120))";
$dueDateExpr = $hasDocDueDate ? 'H.DocDueDate' : 'H.DocDate';

$where = [
    $openQtyExpr . ' > 0'
];
$params = [];

if ($hasCanceled) {
    $where[] = "ISNULL(H.CANCELED, 'N') = 'N'";
}

if ($hasDocStatus) {
    $where[] = "H.DocStatus = 'O'";
}

if ($hasLineStatus) {
    $where[] = "L.LineStatus = 'O'";
}

if ($search !== '') {
    $like = '%' . str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $search) . '%';
    $where[] = "(
        CAST(H.DocNum AS NVARCHAR(40)) LIKE ?
        OR {$cardCodeExpr} LIKE ?
        OR {$cardNameExpr} LIKE ?
        OR L.ItemCode LIKE ?
        OR {$partNameExpr} LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like);
}

$rows = fetch_all(
    $erp,
    "SELECT TOP 500
        H.DocEntry,
        H.DocNum,
        H.DocDate,
        {$dueDateExpr} AS DocDueDate,
        {$cardCodeExpr} AS CardCode,
        {$cardNameExpr} AS CardName,
        L.LineNum,
        L.ItemCode,
        {$partNameExpr} AS PartName,
        L.Quantity AS OrderedQty,
        {$openQtyExpr} AS OpenQty,
        {$whsExpr} AS WhsCode,
        {$uomExpr} AS UomName,
        {$numPerMsrExpr} AS NumPerMsr
     FROM OPOR H
     INNER JOIN POR1 L ON L.DocEntry = H.DocEntry
     LEFT JOIN OITM I ON I.ItemCode = L.ItemCode
     WHERE " . implode(' AND ', $where) . "
     ORDER BY H.DocDate DESC, H.DocNum DESC, H.DocEntry DESC, L.LineNum ASC",
    $params
);

$documents = [];
$lines = [];

foreach ($rows as $r) {
    $docKey = (string)$r['DocEntry'];
    $openQty = (float)$r['OpenQty'];

    $line = [
        'doc_entry' => (int)$r['DocEntry'],
        'doc_num' => (string)$r['DocNum'],
        'doc_date' => picker_po_dt($r['DocDate']),
        'due_date' => picker_po_dt($r['DocDueDate']),
        'vendor_code' => (string)$r['CardCode'],
        'vendor_name' => (string)$r['CardName'],
        'line_num' => (int)$r['LineNum'],
        'item_code' => (string)$r['ItemCode'],
        'part_name' => (string)$r['PartName'],
        'ordered_qty' => (float)$r['OrderedQty'],
        'open_qty' => $openQty,
        'warehouse_code' => (string)$r['WhsCode'],
        'uom' => (string)($r['UomName'] ?? ''),
        'num_per_msr' => (float)($r['NumPerMsr'] ?? 1)
    ];

    $lines[] = $line;

    if (!isset($documents[$docKey])) {
        $documents[$docKey] = [
            'doc_entry' => (int)$r['DocEntry'],
            'doc_num' => (string)$r['DocNum'],
            'doc_date' => picker_po_dt($r['DocDate']),
            'due_date' => picker_po_dt($r['DocDueDate']),
            'vendor_code' => (string)$r['CardCode'],
            'vendor_name' => (string)$r['CardName'],
            'line_count' => 0,
            'open_qty' => 0.0,
            'lines' => []
        ];
    }

    $documents[$docKey]['line_count']++;
    $documents[$docKey]['open_qty'] += $openQty;
    $documents[$docKey]['lines'][] = $line;
}

$payload = [
    'ok' => true,
    'search' => $search,
    'limited' => count($rows) >= 500,
    'documents' => array_values($documents),
    'lines' => $lines
];

sap_cache_put($whp, 'sap.picker.open_purchase_orders', $cacheKey, $payload, 300);
picker_po_json_out($payload);
?>
