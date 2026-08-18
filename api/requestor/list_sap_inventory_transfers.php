<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

const SAP_IT_CACHE_TTL_SECONDS = 1800;
const SAP_IT_CACHE_MAX_STALE_SECONDS = 86400;
const SAP_IT_MAX_CACHED_PAGES = 2;

function sap_it_json_out($payload)
{
    echo json_encode($payload);
    exit;
}

function sap_it_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

function sap_it_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_NAME = ?",
        [$table]
    );
}

function sap_it_has_base_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasBaseTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = ?
           AND TABLE_TYPE = 'BASE TABLE'",
        [$table]
    );
}

function sap_it_dt($value)
{
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
}

function sap_it_int_param($name, $default, $min, $max)
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);

    if (($value === false || $value === null) && isset($_GET[$name])) {
        $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    }

    if ($value === false || $value === null) {
        $value = $default;
    }

    return max($min, min($max, (int)$value));
}

function sap_it_like_param($value)
{
    $value = trim((string)$value);
    $value = str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $value);

    return '%' . $value . '%';
}

function sap_it_document_matches_search(array $doc, $searchText)
{
    $searchText = strtolower(trim((string)$searchText));

    if ($searchText === '') {
        return true;
    }

    $parts = [
        $doc['it_number'] ?? '',
        $doc['itr_number'] ?? '',
        $doc['it_date'] ?? '',
    ];

    foreach (($doc['lines'] ?? []) as $line) {
        if (!is_array($line)) {
            continue;
        }

        $parts[] = $line['item_code'] ?? '';
        $parts[] = $line['part_name'] ?? '';
        $parts[] = $line['lot_no'] ?? '';
        $parts[] = $line['from_whs_code'] ?? '';
        $parts[] = $line['to_whs_code'] ?? '';
    }

    return strpos(strtolower(implode(' ', array_map('strval', $parts))), $searchText) !== false;
}

function sap_it_filter_cached_payload(array $payload, $maxDocuments, $searchText)
{
    $documents = [];

    foreach (($payload['documents'] ?? []) as $doc) {
        if (!is_array($doc) || !sap_it_document_matches_search($doc, $searchText)) {
            continue;
        }

        $documents[] = $doc;

        if (count($documents) >= $maxDocuments) {
            break;
        }
    }

    $payload['documents'] = $documents;
    $payload['limit'] = $maxDocuments;
    $payload['filtered_from_base_cache'] = true;

    return $payload;
}

function sap_it_cache_parts($role, $section, array $warehouses, $maxDocuments, $searchText, $page)
{
    $parts = [
        'role' => $role,
        'section' => $section,
        'warehouses' => implode(',', $warehouses),
        'max' => $maxDocuments,
        'search' => $searchText,
        'month' => date('Y-m'),
        'version' => 'stock-per-line-v2-cpu-optimized'
    ];

    if ((int)$page > 1) {
        $parts['page'] = (int)$page;
    }

    return $parts;
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

$whp = get_whpokayoke_connection();
$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? $currentUser['RoleName'] ?? '');
$maxDocuments = sap_it_int_param('max', 50, 10, 50);
$page = sap_it_int_param('page', 1, 1, 20);
$searchText = trim((string)($_GET['q'] ?? ''));

$currentSection = '';
$allowedWarehouses = [];
$sectionFilterText = 'All sections';

if ($currentRole === ROLE_REQUESTOR) {
    $userId = (int)($currentUser['user_id'] ?? $currentUser['UserID'] ?? $currentUser['id'] ?? 0);
    $username = trim($currentUser['username'] ?? $currentUser['Username'] ?? '');

    $sectionRow = $userId > 0
        ? fetch_one($whp, "SELECT RequestorSection FROM AppUsers WHERE UserID = ?", [$userId])
        : fetch_one($whp, "SELECT RequestorSection FROM AppUsers WHERE Username = ?", [$username]);

    $currentSection = trim($sectionRow['RequestorSection'] ?? '');

    if ($currentSection === '') {
        sap_it_json_out([
            'ok' => false,
            'message' => 'Your account has no Requestor Section assigned. Please ask admin to update your user account.',
            'documents' => []
        ]);
    }

    $currentSectionKey = strtolower(trim($currentSection));
    $currentSectionKey = preg_replace('/\s+/', ' ', $currentSectionKey);

    if (!isset($sectionWarehouseMap[$currentSectionKey])) {
        sap_it_json_out([
            'ok' => false,
            'message' => 'No warehouse mapping found for your section: ' . $currentSection,
            'documents' => []
        ]);
    }

    $allowedWarehouses = $sectionWarehouseMap[$currentSectionKey];
    $sectionFilterText = $currentSection . ' / To Warehouse: ' . implode(', ', $allowedWarehouses);
}

$cacheKey = sap_cache_make_key(
    'sap.requestor.inventory_transfers',
    sap_it_cache_parts($currentRole, $currentSection, $allowedWarehouses, $maxDocuments, $searchText, $page)
);

$cached = sap_cache_get_preferred($whp, $cacheKey, SAP_IT_CACHE_MAX_STALE_SECONDS);

if ($cached !== null) {
    sap_it_json_out($cached);
}

$baseCacheKey = sap_cache_make_key(
    'sap.requestor.inventory_transfers',
    sap_it_cache_parts($currentRole, $currentSection, $allowedWarehouses, 50, '', $page)
);

if ($baseCacheKey !== $cacheKey) {
    $baseCached = sap_cache_get_preferred($whp, $baseCacheKey, SAP_IT_CACHE_MAX_STALE_SECONDS);

    if ($baseCached !== null) {
        sap_it_json_out(sap_it_filter_cached_payload($baseCached, $maxDocuments, $searchText));
    }
}

$legacyBaseCacheKey = sap_cache_make_key(
    'sap.requestor.inventory_transfers',
    sap_it_cache_parts($currentRole, $currentSection, $allowedWarehouses, 20, '', 1)
);

if ($page === 1 && $legacyBaseCacheKey !== $cacheKey && $legacyBaseCacheKey !== $baseCacheKey) {
    $legacyBaseCached = sap_cache_get_preferred($whp, $legacyBaseCacheKey, SAP_IT_CACHE_MAX_STALE_SECONDS);

    if ($legacyBaseCached !== null) {
        sap_it_json_out(sap_it_filter_cached_payload($legacyBaseCached, $maxDocuments, $searchText));
    }
}

if (!sap_cache_live_queries_enabled()) {
    $payload = sap_cache_live_disabled_payload('SAP Inventory Transfers are served from cache only. Please wait for the scheduled SAP cache refresh.');
    $payload['documents'] = [];
    sap_it_json_out($payload);
}

$erp = get_erp_connection();

if (!sap_it_has_table($erp, 'OWTR') || !sap_it_has_table($erp, 'WTR1')) {
    sap_it_json_out([
        'ok' => false,
        'message' => 'SAP Inventory Transfer tables OWTR/WTR1 were not found.',
        'documents' => []
    ]);
}

if (!sap_it_has_table($erp, 'OWTQ') || !sap_it_has_table($erp, 'WTQ1')) {
    sap_it_json_out([
        'ok' => false,
        'message' => 'SAP Inventory Transfer Request tables OWTQ/WTQ1 were not found.',
        'documents' => []
    ]);
}

$hasCanceled = sap_it_has_column($erp, 'OWTR', 'CANCELED');
$hasDscription = sap_it_has_column($erp, 'WTR1', 'Dscription');
$hasFromWhs = sap_it_has_column($erp, 'WTR1', 'FromWhsCod');
$hasToWhs = sap_it_has_column($erp, 'WTR1', 'WhsCode');
$hasBaseType = sap_it_has_column($erp, 'WTR1', 'BaseType');
$hasBaseEntry = sap_it_has_column($erp, 'WTR1', 'BaseEntry');
$hasBaseLine = sap_it_has_column($erp, 'WTR1', 'BaseLine');
$hasUnitMsr = sap_it_has_column($erp, 'WTR1', 'unitMsr');
$hasUomCode = sap_it_has_column($erp, 'WTR1', 'UomCode');
$hasInvntryUom = sap_it_has_column($erp, 'OITM', 'InvntryUom');
$hasOitw = sap_it_has_table($erp, 'OITW');

if (!$hasBaseType || !$hasBaseEntry || !$hasBaseLine) {
    sap_it_json_out([
        'ok' => false,
        'message' => 'SAP WTR1 base document fields BaseType/BaseEntry/BaseLine were not found.',
        'documents' => []
    ]);
}

if (!$hasFromWhs || !$hasToWhs) {
    sap_it_json_out([
        'ok' => false,
        'message' => 'SAP WTR1 warehouse fields FromWhsCod/WhsCode were not found.',
        'documents' => []
    ]);
}

$itemUomExpr = $hasInvntryUom ? 'I.InvntryUom' : "CAST('' AS NVARCHAR(50))";

$uomExpr = $hasUnitMsr
    ? "COALESCE(NULLIF(L.unitMsr, ''), {$itemUomExpr}, '')"
    : ($hasUomCode ? "COALESCE(NULLIF(L.UomCode, ''), {$itemUomExpr}, '')" : "COALESCE({$itemUomExpr}, '')");

$partNameExpr = $hasDscription
    ? "COALESCE(NULLIF(L.Dscription, ''), I.ItemName, '')"
    : "COALESCE(I.ItemName, '')";

/*
 * Prefer SAP's physical inventory-log tables for batch/lot data. In this
 * environment IBT1 can be a compatibility view and is expensive when joined
 * repeatedly, so only use it when it is a real base table.
 */
$hasInventoryLogBatchJoin =
    sap_it_has_table($erp, 'OITL') &&
    sap_it_has_table($erp, 'ITL1') &&
    sap_it_has_table($erp, 'OBTN') &&
    sap_it_has_column($erp, 'OITL', 'LogEntry') &&
    sap_it_has_column($erp, 'OITL', 'DocType') &&
    sap_it_has_column($erp, 'OITL', 'DocEntry') &&
    sap_it_has_column($erp, 'OITL', 'DocLine') &&
    sap_it_has_column($erp, 'OITL', 'LocCode') &&
    sap_it_has_column($erp, 'ITL1', 'LogEntry') &&
    sap_it_has_column($erp, 'ITL1', 'ItemCode') &&
    sap_it_has_column($erp, 'ITL1', 'SysNumber') &&
    sap_it_has_column($erp, 'ITL1', 'Quantity') &&
    sap_it_has_column($erp, 'OBTN', 'ItemCode') &&
    sap_it_has_column($erp, 'OBTN', 'SysNumber') &&
    sap_it_has_column($erp, 'OBTN', 'DistNumber');

$hasBatchJoin =
    !$hasInventoryLogBatchJoin &&
    sap_it_has_base_table($erp, 'IBT1') &&
    sap_it_has_column($erp, 'IBT1', 'BaseType') &&
    sap_it_has_column($erp, 'IBT1', 'BaseEntry') &&
    sap_it_has_column($erp, 'IBT1', 'BaseLinNum') &&
    sap_it_has_column($erp, 'IBT1', 'ItemCode') &&
    sap_it_has_column($erp, 'IBT1', 'BatchNum') &&
    sap_it_has_column($erp, 'IBT1', 'Quantity') &&
    sap_it_has_column($erp, 'IBT1', 'WhsCode');

if ($hasInventoryLogBatchJoin) {
    $batchSource = 'OITL/ITL1/OBTN';
    $batchSelect = "BT.DistNumber AS LotNo, ABS(ISNULL(BL.Quantity, 0)) AS LotQty";
    $batchJoin = "LEFT JOIN OITL IL
       ON IL.DocType = 67
      AND IL.DocEntry = T.DocEntry
      AND IL.DocLine = L.LineNum
      AND IL.LocCode = L.WhsCode
LEFT JOIN ITL1 BL ON BL.LogEntry = IL.LogEntry AND BL.ItemCode = L.ItemCode
LEFT JOIN OBTN BT ON BT.ItemCode = BL.ItemCode AND BT.SysNumber = BL.SysNumber";
} elseif ($hasBatchJoin) {
    $batchSource = 'IBT1_BASE_TABLE';
    $batchSelect = "B.BatchNum AS LotNo, ABS(ISNULL(B.Quantity, 0)) AS LotQty";
    $batchJoin = "LEFT JOIN IBT1 B
       ON B.BaseType = 67
      AND B.BaseEntry = T.DocEntry
      AND B.BaseLinNum = L.LineNum
      AND B.ItemCode = L.ItemCode
      AND B.WhsCode = L.WhsCode";
} else {
    $batchSource = 'none';
    $batchSelect = "CAST('' AS NVARCHAR(80)) AS LotNo, CAST(0 AS DECIMAL(18,3)) AS LotQty";
    $batchJoin = "";
}

/*
|--------------------------------------------------------------------------
| Current month filter
|--------------------------------------------------------------------------
| This prevents the API from loading all historical SAP Inventory Transfers.
| Example:
|   T.DocDate >= 2026-06-01
|   T.DocDate <  2026-07-01
*/
$monthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('+1 month'));

$where = [
    'L.BaseType = ?',
    'L.BaseEntry IS NOT NULL',
    'L.BaseEntry > 0',
    'Q.DocEntry IS NOT NULL',
    'L.FromWhsCod = ?',
    'T.DocDate >= ?',
    'T.DocDate < ?'
];

$params = [
    1250000001,
    '01',
    $monthStart,
    $nextMonthStart
];

if ($hasCanceled) {
    $where[] = "ISNULL(T.CANCELED, 'N') = 'N'";
}

if ($currentRole === ROLE_REQUESTOR) {
    $placeholders = implode(',', array_fill(0, count($allowedWarehouses), '?'));
    $where[] = "L.WhsCode IN ($placeholders)";

    foreach ($allowedWarehouses as $warehouseCode) {
        $params[] = $warehouseCode;
    }
}

$docWhere = $where;
$docParams = $params;
$docItemJoin = '';

if ($searchText !== '') {
    $like = sap_it_like_param($searchText);
    $docItemJoin = 'LEFT JOIN OITM I ON I.ItemCode = L.ItemCode';
    $docWhere[] = "(
        CAST(T.DocNum AS NVARCHAR(30)) LIKE ?
        OR CAST(Q.DocNum AS NVARCHAR(30)) LIKE ?
        OR L.ItemCode LIKE ?
        OR {$partNameExpr} LIKE ?
    )";
    array_push($docParams, $like, $like, $like, $like);
}

$offset = ($page - 1) * $maxDocuments;
$fetchThrough = $offset + $maxDocuments + 1;
$docRows = fetch_all(
    $erp,
    "WITH Documents AS (
        SELECT
            T.DocEntry,
            MAX(T.DocDate) AS SortDocDate,
            MAX(T.DocNum) AS SortDocNum
        FROM OWTR T
        INNER JOIN WTR1 L ON T.DocEntry = L.DocEntry
        INNER JOIN OWTQ Q ON Q.DocEntry = L.BaseEntry
        {$docItemJoin}
        WHERE " . implode(' AND ', $docWhere) . "
        GROUP BY T.DocEntry
     ),
     Numbered AS (
        SELECT
            DocEntry,
            ROW_NUMBER() OVER (ORDER BY SortDocDate DESC, SortDocNum DESC) AS RowNum
        FROM Documents
     )
     SELECT DocEntry
     FROM Numbered
     WHERE RowNum > ?
       AND RowNum <= ?
     ORDER BY RowNum
     OPTION (MAXDOP 1, RECOMPILE)",
    array_merge($docParams, [$offset, $fetchThrough])
);

$hasMore = count($docRows) > $maxDocuments;
$docRows = array_slice($docRows, 0, $maxDocuments);

$docEntries = [];

foreach ($docRows as $docRow) {
    $docEntry = (int)($docRow['DocEntry'] ?? 0);

    if ($docEntry > 0) {
        $docEntries[] = $docEntry;
    }
}

if (empty($docEntries)) {
    $payload = [
        'ok' => true,
        'section_filter' => $sectionFilterText,
        'batch_source' => $batchSource,
        'month_start' => $monthStart,
        'month_end' => date('Y-m-d', strtotime($nextMonthStart . ' -1 day')),
        'limit' => $maxDocuments,
        'page' => $page,
        'page_size' => $maxDocuments,
        'has_more' => false,
        'documents' => []
    ];

    sap_cache_put($whp, 'sap.requestor.inventory_transfers', $cacheKey, $payload, SAP_IT_CACHE_TTL_SECONDS);
    sap_it_json_out($payload);
}

$docEntryPlaceholders = implode(',', array_fill(0, count($docEntries), '?'));

/*
 * Keep the transfer/batch query narrow. OITW stock is fetched separately below
 * so SQL Server does not have to optimize two extra OITW joins together with
 * the transfer and batch joins. MAXDOP 1 prevents this web/cache refresh from
 * consuming several CPU workers at once on the ERP server.
 */
$sql = "
SELECT
    Q.DocEntry AS ITRDocEntry,
    Q.DocNum AS ITRNumber,
    T.DocEntry AS ITDocEntry,
    T.DocNum AS ITNumber,
    T.DocDate AS ITDate,
    L.LineNum AS ITLineNum,
    L.BaseLine AS ITRLineNum,
    L.ItemCode,
    {$partNameExpr} AS PartName,
    L.Quantity AS TransferQty,
    {$uomExpr} AS UomName,
    L.FromWhsCod AS FromWhsCode,
    L.WhsCode AS ToWhsCode,
    {$batchSelect}
FROM OWTR T
INNER JOIN WTR1 L ON T.DocEntry = L.DocEntry
INNER JOIN OWTQ Q ON Q.DocEntry = L.BaseEntry
LEFT JOIN OITM I ON I.ItemCode = L.ItemCode
{$batchJoin}
WHERE T.DocEntry IN ({$docEntryPlaceholders})
ORDER BY T.DocDate DESC, T.DocNum DESC, L.LineNum ASC
OPTION (MAXDOP 1, RECOMPILE)
";

$rows = fetch_all($erp, $sql, $docEntries);

$stockByKey = [];
if ($hasOitw && !empty($rows)) {
    $stockPairs = [];

    foreach ($rows as $row) {
        $itemCode = trim((string)($row['ItemCode'] ?? ''));
        if ($itemCode === '') {
            continue;
        }

        foreach (['FromWhsCode', 'ToWhsCode'] as $warehouseField) {
            $warehouseCode = trim((string)($row[$warehouseField] ?? ''));
            if ($warehouseCode === '') {
                continue;
            }

            $stockPairs[strtoupper($itemCode) . '|' . strtoupper($warehouseCode)] = [
                $itemCode,
                $warehouseCode
            ];
        }
    }

    foreach (array_chunk(array_values($stockPairs), 100) as $pairChunk) {
        $refRows = [];
        $stockParams = [];

        foreach ($pairChunk as $pair) {
            $refRows[] = 'SELECT ? AS ItemCode, ? AS WhsCode';
            array_push($stockParams, $pair[0], $pair[1]);
        }

        $stockRows = fetch_all(
            $erp,
            "WITH Ref AS (
                " . implode("\nUNION ALL\n", $refRows) . "
             )
             SELECT W.ItemCode, W.WhsCode, ISNULL(W.OnHand, 0) AS OnHand
             FROM Ref
             INNER JOIN OITW W
                ON W.ItemCode = Ref.ItemCode
               AND W.WhsCode = Ref.WhsCode
             OPTION (MAXDOP 1, RECOMPILE)",
            $stockParams
        );

        foreach ($stockRows as $stockRow) {
            $stockKey = strtoupper(trim((string)($stockRow['ItemCode'] ?? ''))) . '|' .
                strtoupper(trim((string)($stockRow['WhsCode'] ?? '')));
            $stockByKey[$stockKey] = (float)($stockRow['OnHand'] ?? 0);
        }
    }
}

$documents = [];

foreach ($rows as $r) {
    $docKey = (string)$r['ITDocEntry'];

    if (!isset($documents[$docKey])) {
        $documents[$docKey] = [
            'it_doc_entry' => (int)$r['ITDocEntry'],
            'it_number' => (string)$r['ITNumber'],
            'it_date' => sap_it_dt($r['ITDate']),
            'itr_number' => (string)$r['ITRNumber'],
            'itr_doc_entry' => (int)$r['ITRDocEntry'],
            'line_count' => 0,
            'lot_count' => 0,
            'total_qty' => 0.0,
            'lines' => []
        ];
    }

    $lotNo = trim((string)($r['LotNo'] ?? ''));
    $lotQty = (float)($r['LotQty'] ?? 0);
    $transferQty = (float)($r['TransferQty'] ?? 0);

    $documents[$docKey]['line_count']++;
    $documents[$docKey]['total_qty'] += $lotQty > 0 ? $lotQty : $transferQty;

    if ($lotNo !== '') {
        $documents[$docKey]['lot_count']++;
    }

    $documents[$docKey]['lines'][] = [
        'it_number' => (string)$r['ITNumber'],
        'itr_number' => (string)$r['ITRNumber'],
        'it_line_num' => $r['ITLineNum'] !== null ? (int)$r['ITLineNum'] : null,
        'itr_line_num' => $r['ITRLineNum'] !== null ? (int)$r['ITRLineNum'] : null,
        'item_code' => (string)$r['ItemCode'],
        'part_name' => (string)$r['PartName'],
        'transfer_qty' => $transferQty,
        'lot_no' => $lotNo,
        'lot_qty' => $lotQty,
        'uom' => (string)($r['UomName'] ?? ''),
        'from_whs_code' => (string)$r['FromWhsCode'],
        'to_whs_code' => (string)$r['ToWhsCode'],
        'source_stock_qty' => (float)($stockByKey[
            strtoupper(trim((string)$r['ItemCode'])) . '|' . strtoupper(trim((string)$r['FromWhsCode']))
        ] ?? 0),
        'destination_stock_qty' => (float)($stockByKey[
            strtoupper(trim((string)$r['ItemCode'])) . '|' . strtoupper(trim((string)$r['ToWhsCode']))
        ] ?? 0)
    ];
}

$payload = [
    'ok' => true,
    'section_filter' => $sectionFilterText,
    'batch_source' => $batchSource,
    'month_start' => $monthStart,
    'month_end' => date('Y-m-d', strtotime($nextMonthStart . ' -1 day')),
    'limit' => $maxDocuments,
    'page' => $page,
    'page_size' => $maxDocuments,
    'has_more' => $hasMore && $page < SAP_IT_MAX_CACHED_PAGES,
    'documents' => array_values($documents)
];

sap_cache_put($whp, 'sap.requestor.inventory_transfers', $cacheKey, $payload, SAP_IT_CACHE_TTL_SECONDS);
sap_it_json_out($payload);
?>