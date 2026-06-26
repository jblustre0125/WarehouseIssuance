<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';

require_role([ROLE_ISSUER, ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function json_out($payload)
{
    echo json_encode($payload);
    exit;
}

function has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

function dt_text($value)
{
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
}

/*
    Section to SAP destination warehouse mapping.

    SAP filter:
    From Warehouse = 01
    To Warehouse = based on RequestorSection

    Backend        = HM, CSW, MR
    Cut and Crimp  = CNC
    Sub-Assy       = SA
    Kitting        = KIT
*/
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

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$whp = get_whpokayoke_connection();
$erp = get_erp_connection();

/*
    Read current logged-in user.
    Important:
    We query AppUsers directly because current_user() may not include RequestorSection.
*/
$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? $currentUser['RoleName'] ?? '');
$currentSection = '';

if ($currentRole === ROLE_REQUESTOR) {
    $userId = (int)($currentUser['user_id'] ?? $currentUser['UserID'] ?? $currentUser['id'] ?? 0);
    $username = trim($currentUser['username'] ?? $currentUser['Username'] ?? '');

    if ($userId > 0) {
        $sectionRow = fetch_one(
            $whp,
            "SELECT RequestorSection
             FROM AppUsers
             WHERE UserID = ?",
            [$userId]
        );
    } else {
        $sectionRow = fetch_one(
            $whp,
            "SELECT RequestorSection
             FROM AppUsers
             WHERE Username = ?",
            [$username]
        );
    }

    $currentSection = trim($sectionRow['RequestorSection'] ?? '');
}

$hasOwtq = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OWTQ'"
);

$hasWtq1 = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'WTQ1'"
);

if (!$hasOwtq || !$hasWtq1) {
    json_out([
        'ok' => false,
        'message' => 'SAP Inventory Transfer Request tables OWTQ/WTQ1 were not found.',
        'requests' => [],
        'documents' => []
    ]);
}

$hasCanceled = has_column($erp, 'OWTQ', 'CANCELED');
$hasDocStatus = has_column($erp, 'OWTQ', 'DocStatus');
$hasLineStatus = has_column($erp, 'WTQ1', 'LineStatus');
$hasDscription = has_column($erp, 'WTQ1', 'Dscription');
$hasFromWhs = has_column($erp, 'WTQ1', 'FromWhsCod');
$hasToWhs = has_column($erp, 'WTQ1', 'WhsCode');
$hasUnitMsr = has_column($erp, 'WTQ1', 'unitMsr');
$hasUomCode = has_column($erp, 'WTQ1', 'UomCode');
$hasNumPerMsr = has_column($erp, 'WTQ1', 'NumPerMsr');
$hasInvntryUom = has_column($erp, 'OITM', 'InvntryUom');

if (!$hasFromWhs) {
    json_out([
        'ok' => false,
        'message' => 'SAP ITR line field WTQ1.FromWhsCod was not found, so warehouse 01 cannot be filtered.',
        'requests' => [],
        'documents' => []
    ]);
}

if (!$hasToWhs) {
    json_out([
        'ok' => false,
        'message' => 'SAP ITR line field WTQ1.WhsCode was not found, so requestor section warehouse cannot be filtered.',
        'requests' => [],
        'documents' => []
    ]);
}

/*
    IMPORTANT FIX:
    Your SAP screenshot shows the real ITR request quantity in WTQ1.Quantity.

    The old code used WTQ1.OpenQty when it exists:
        $openQtyExpr = $hasOpenQty ? 'L.OpenQty' : 'L.Quantity';

    In your SAP, OpenQty appears to be returning 1 per line.
    So the Requestor page showed Remaining = 1.

    Use L.Quantity as the requestable quantity instead.
*/
$openQtyExpr = 'L.Quantity';

$partNameExpr = $hasDscription
    ? "COALESCE(NULLIF(L.Dscription, ''), I.ItemName, '')"
    : "COALESCE(I.ItemName, '')";

$itemUomExpr = $hasInvntryUom ? 'I.InvntryUom' : "CAST('' AS NVARCHAR(50))";

$uomExpr = $hasUnitMsr
    ? "COALESCE(NULLIF(L.unitMsr, ''), {$itemUomExpr}, '')"
    : ($hasUomCode ? "COALESCE(NULLIF(L.UomCode, ''), {$itemUomExpr}, '')" : "COALESCE({$itemUomExpr}, '')");

$numPerMsrExpr = $hasNumPerMsr ? 'L.NumPerMsr' : '1';

$fromWhsExpr = 'L.FromWhsCod';
$toWhsExpr = 'L.WhsCode';

$hasOitw = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OITW'"
);

$stockSelect = $hasOitw
    ? 'ISNULL(FW.OnHand, 0) AS SourceStockQty, ISNULL(TW.OnHand, 0) AS DestinationStockQty'
    : '0 AS SourceStockQty, 0 AS DestinationStockQty';

$stockJoin = $hasOitw
    ? "LEFT JOIN OITW FW ON FW.ItemCode = L.ItemCode AND FW.WhsCode = {$fromWhsExpr}
LEFT JOIN OITW TW ON TW.ItemCode = L.ItemCode AND TW.WhsCode = {$toWhsExpr}"
    : '';

$where = [
    'H.DocDate >= ?',
    'H.DocDate < ?',
    $openQtyExpr . ' > 0',
    'L.FromWhsCod = ?'
];

$params = [
    $monthStart,
    $monthEnd,
    '01'
];

if ($hasCanceled) {
    $where[] = "ISNULL(H.CANCELED, 'N') = 'N'";
}

if ($hasDocStatus) {
    $where[] = "H.DocStatus = 'O'";
}

if ($hasLineStatus) {
    $where[] = "L.LineStatus = 'O'";
}

/*
    Requestor section filtering.

    Admin and Issuer:
    - See all open ITRs from warehouse 01.

    Requestor:
    - See only open ITRs for their assigned section warehouse.
*/
$sectionFilterText = 'All sections';
$allowedWarehouses = [];
$currentSectionKey = '';

if ($currentRole === ROLE_REQUESTOR) {
    if ($currentSection === '') {
        json_out([
            'ok' => false,
            'message' => 'Your account has no Requestor Section assigned. Please ask admin to update your user account.',
            'debug_user' => $currentUser,
            'requests' => [],
            'documents' => []
        ]);
    }

    $currentSectionKey = strtolower(trim($currentSection));
    $currentSectionKey = preg_replace('/\s+/', ' ', $currentSectionKey);

    if (!isset($sectionWarehouseMap[$currentSectionKey])) {
        json_out([
            'ok' => false,
            'message' => 'No warehouse mapping found for your section: ' . $currentSection,
            'debug_section_saved' => $currentSection,
            'debug_section_key' => $currentSectionKey,
            'debug_available_keys' => array_keys($sectionWarehouseMap),
            'requests' => [],
            'documents' => []
        ]);
    }

    $allowedWarehouses = $sectionWarehouseMap[$currentSectionKey];

    if (empty($allowedWarehouses)) {
        json_out([
            'ok' => false,
            'message' => 'No warehouse code assigned for your section: ' . $currentSection,
            'debug_section_saved' => $currentSection,
            'requests' => [],
            'documents' => []
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($allowedWarehouses), '?'));
    $where[] = "L.WhsCode IN ($placeholders)";

    foreach ($allowedWarehouses as $warehouseCode) {
        $params[] = $warehouseCode;
    }

    $sectionFilterText = $currentSection . ' / To Warehouse: ' . implode(', ', $allowedWarehouses);
}

/*
    Cache key version changed so old cached OpenQty results do not continue showing.
*/
$cacheKey = sap_cache_make_key('sap.open_itr_requests', [
    'role' => $currentRole,
    'section' => $currentSection,
    'warehouses' => implode(',', $allowedWarehouses),
    'month' => date('Y-m'),
    'version' => 'quantity-as-open-v3-pack-size',
    'pack_sizes' => itr_pack_sizes_cache_token()
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($whp, $cacheKey);

    if ($cached !== null) {
        json_out($cached);
    }
}

$sql = "
SELECT TOP 200
    H.DocEntry,
    H.DocNum,
    H.DocDate,
    L.LineNum,
    L.ItemCode,
    {$partNameExpr} AS PartName,
    L.Quantity AS RequestedQty,
    {$openQtyExpr} AS OpenQty,
    {$uomExpr} AS UomName,
    {$numPerMsrExpr} AS NumPerMsr,
    {$fromWhsExpr} AS FromWhsCode,
    {$toWhsExpr} AS ToWhsCode,
    {$stockSelect}
FROM OWTQ H
INNER JOIN WTQ1 L ON H.DocEntry = L.DocEntry
LEFT JOIN OITM I ON I.ItemCode = L.ItemCode
{$stockJoin}
WHERE " . implode(' AND ', $where) . "
ORDER BY H.DocDate DESC, H.DocNum DESC, L.LineNum
";

$rows = fetch_all($erp, $sql, $params);

/*
    Check issued quantity from app transactions.
*/
$lineTracking = has_column($whp, 'IssuanceTransactions', 'ITRLineNum');

$issuedSql = $lineTracking
    ? "SELECT
            ITRNumber,
            ItemCode,
            ITRLineNum,
            SUM(Quantity) AS IssuedQty
       FROM IssuanceTransactions
       WHERE ITRNumber IS NOT NULL
         AND ITRNumber <> ''
         AND IssuedAt >= ?
         AND IssuedAt < ?
       GROUP BY ITRNumber, ItemCode, ITRLineNum"
    : "SELECT
            ITRNumber,
            ItemCode,
            SUM(Quantity) AS IssuedQty
       FROM IssuanceTransactions
       WHERE ITRNumber IS NOT NULL
         AND ITRNumber <> ''
         AND IssuedAt >= ?
         AND IssuedAt < ?
       GROUP BY ITRNumber, ItemCode";

$issuedRows = fetch_all($whp, $issuedSql, [$monthStart, $monthEnd]);

$issued = [];

foreach ($issuedRows as $r) {
    $key = (string)$r['ITRNumber'] . '|' . (string)$r['ItemCode'];

    if ($lineTracking) {
        $key .= '|' . (string)$r['ITRLineNum'];
    }

    $issued[$key] = (float)$r['IssuedQty'];
}

/*
    Check already requested quantity from app request tables.
*/
$hasRequestHeader = fetch_one(
    $whp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'WarehouseIssueRequestHeader'"
);

$hasRequestLines = fetch_one(
    $whp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'WarehouseIssueRequestLines'"
);

$requested = [];

if ($hasRequestHeader && $hasRequestLines) {
    $requestedSql = $lineTracking
        ? "SELECT
                L.SAP_IT_DocNum AS ITRNumber,
                L.ItemCode,
                L.SAP_IT_LineNum AS ITRLineNum,
                SUM(L.RequestedQty) AS RequestedQty
           FROM WarehouseIssueRequestHeader H
           INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
           WHERE L.SAP_IT_DocNum IS NOT NULL
             AND L.SAP_IT_DocNum <> ''
             AND H.Status <> 'CANCELLED'
             AND L.Status <> 'CANCELLED'
           GROUP BY L.SAP_IT_DocNum, L.ItemCode, L.SAP_IT_LineNum"
        : "SELECT
                L.SAP_IT_DocNum AS ITRNumber,
                L.ItemCode,
                SUM(L.RequestedQty) AS RequestedQty
           FROM WarehouseIssueRequestHeader H
           INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
           WHERE L.SAP_IT_DocNum IS NOT NULL
             AND L.SAP_IT_DocNum <> ''
             AND H.Status <> 'CANCELLED'
             AND L.Status <> 'CANCELLED'
           GROUP BY L.SAP_IT_DocNum, L.ItemCode";

    $requestedRows = fetch_all($whp, $requestedSql);

    foreach ($requestedRows as $r) {
        $key = (string)$r['ITRNumber'] . '|' . (string)$r['ItemCode'];

        if ($lineTracking) {
            $key .= '|' . (string)$r['ITRLineNum'];
        }

        $requested[$key] = (float)$r['RequestedQty'];
    }
}

$requests = [];
$documents = [];

foreach ($rows as $r) {
    $key = (string)$r['DocNum'] . '|' . (string)$r['ItemCode'];

    if ($lineTracking) {
        $key .= '|' . (string)$r['LineNum'];
    }

    $issuedQty = $issued[$key] ?? 0.0;
    $appRequestedQty = $requested[$key] ?? 0.0;

    /*
        OpenQty is now based on SAP WTQ1.Quantity.
        Remaining Qty = SAP Quantity minus quantities already requested in your app.
    */
    $openQty = (float)$r['OpenQty'];
    $remainingQty = max(0, $openQty - $appRequestedQty);
    $qtyPerPack = itr_qty_per_pack_for_item($r['ItemCode']);

    if ($remainingQty <= 0) {
        continue;
    }

    $line = [
        'doc_entry' => (int)$r['DocEntry'],
        'doc_num' => (string)$r['DocNum'],
        'doc_date' => dt_text($r['DocDate']),
        'line_num' => (int)$r['LineNum'],
        'item_code' => (string)$r['ItemCode'],
        'part_name' => (string)$r['PartName'],
        'requested_qty' => (float)$r['RequestedQty'],
        'open_qty' => $openQty,
        'issued_qty' => $issuedQty,
        'app_requested_qty' => $appRequestedQty,
        'remaining_qty' => $remainingQty,
        'uom' => (string)($r['UomName'] ?? ''),
        'qty_per_pack' => $qtyPerPack,
        'qty_per_pack_source' => $qtyPerPack > 0 ? 'June 2026 Excel SUMMARY' : '',
        'num_per_msr' => (float)($r['NumPerMsr'] ?? 1),
        'from_whs_code' => (string)$r['FromWhsCode'],
        'to_whs_code' => (string)$r['ToWhsCode'],
        'source_stock_qty' => (float)$r['SourceStockQty'],
        'destination_stock_qty' => (float)$r['DestinationStockQty'],
        'requestor_section' => $currentRole === ROLE_REQUESTOR ? $currentSection : ''
    ];

    $requests[] = $line;

    $docKey = (string)$r['DocNum'];

    if (!isset($documents[$docKey])) {
        $documents[$docKey] = [
            'doc_entry' => (int)$r['DocEntry'],
            'doc_num' => $docKey,
            'doc_date' => dt_text($r['DocDate']),
            'line_count' => 0,
            'requested_qty' => 0.0,
            'open_qty' => 0.0,
            'issued_qty' => 0.0,
            'app_requested_qty' => 0.0,
            'remaining_qty' => 0.0,
            'source_stock_qty' => 0.0,
            'destination_stock_qty' => 0.0,
            'lines' => []
        ];
    }

    $documents[$docKey]['line_count']++;
    $documents[$docKey]['requested_qty'] += (float)$line['requested_qty'];
    $documents[$docKey]['open_qty'] += (float)$line['open_qty'];
    $documents[$docKey]['issued_qty'] += (float)$line['issued_qty'];
    $documents[$docKey]['app_requested_qty'] += (float)$line['app_requested_qty'];
    $documents[$docKey]['remaining_qty'] += (float)$line['remaining_qty'];
    $documents[$docKey]['source_stock_qty'] += (float)$line['source_stock_qty'];
    $documents[$docKey]['destination_stock_qty'] += (float)$line['destination_stock_qty'];
    $documents[$docKey]['lines'][] = $line;
}

$payload = [
    'ok' => true,
    'month_start' => $monthStart,
    'month_end' => date('Y-m-t'),
    'filter' => 'SAP ITR From Warehouse = 01',
    'section_filter' => $sectionFilterText,
    'current_role' => $currentRole,
    'current_section' => $currentRole === ROLE_REQUESTOR ? $currentSection : '',

    /*
        Temporary debug fields.
        You can remove these after confirming it works.
    */
    'debug_section_saved' => $currentSection,
    'debug_section_key' => $currentSectionKey,
    'debug_allowed_warehouses' => $allowedWarehouses,
    'debug_sap_rows_after_sql' => count($rows),
    'debug_requests_after_remaining_filter' => count($requests),
    'debug_qty_source' => 'WTQ1.Quantity',

    'requests' => $requests,
    'documents' => array_values($documents)
];

sap_cache_put($whp, 'sap.open_itr_requests', $cacheKey, $payload, 300);
json_out($payload);
?>
