<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';
require_once __DIR__ . '/../includes/item_locations.php';
require_once __DIR__ . '/../includes/sap_item_batch.php';
require_once __DIR__ . '/issuer/lot_balance_lib.php';
require_role([ROLE_PICKER, ROLE_ISSUER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function json_out($payload)
{
    echo json_encode($payload);
    exit;
}

function requestor_section_to_warehouse($section)
{
    $value = strtolower(trim((string)$section));

    if ($value === '') {
        return '';
    }

    if (strpos($value, 'cut') !== false || strpos($value, 'crimp') !== false || strpos($value, 'cnc') !== false) {
        return 'CNC';
    }

    if (strpos($value, 'kitting') !== false || $value === 'kit') {
        return 'KIT';
    }

    if (strpos($value, 'sub') !== false || strpos($value, 'assy') !== false || $value === 'sa') {
        return 'SA';
    }

    if (
        strpos($value, 'backend') !== false ||
        strpos($value, 'hotmelt') !== false ||
        strpos($value, 'hot melt') !== false ||
        strpos($value, 'contact') !== false ||
        strpos($value, 'csw') !== false ||
        strpos($value, 'mr') !== false
    ) {
        return 'BACKEND';
    }

    return trim((string)$section);
}

function open_issue_request_line_is_visible(array $line)
{
    if (array_key_exists('is_batch_managed', $line) && !$line['is_batch_managed']) {
        return false;
    }

    return true;
}

function open_issue_request_rebuild_document(array $doc, array $lines)
{
    $doc['lines'] = [];
    $doc['line_count'] = 0;
    $doc['requested_qty'] = 0.0;
    $doc['open_qty'] = 0.0;
    $doc['issued_qty'] = 0.0;
    $doc['remaining_qty'] = 0.0;
    $doc['warehouse_stock_qty'] = 0.0;

    foreach ($lines as $line) {
        $requestedQty = (float)($line['requested_qty'] ?? 0);
        $issuedQty = (float)($line['issued_qty'] ?? 0);
        $remainingQty = (float)($line['remaining_qty'] ?? max(0, $requestedQty - $issuedQty));

        $doc['line_count']++;
        $doc['requested_qty'] += $requestedQty;
        $doc['open_qty'] += (float)($line['open_qty'] ?? $requestedQty);
        $doc['issued_qty'] += $issuedQty;
        $doc['remaining_qty'] += $remainingQty;
        $doc['warehouse_stock_qty'] += (float)($line['warehouse_stock_qty'] ?? 0);
        $doc['lines'][] = $line;
    }

    return $doc;
}

function open_issue_request_filter_payload(array $payload)
{
    $payload['requests'] = array_values(array_filter(
        $payload['requests'] ?? [],
        static function ($line) {
            return is_array($line) && open_issue_request_line_is_visible($line);
        }
    ));

    $documents = [];

    foreach (($payload['documents'] ?? []) as $doc) {
        if (!is_array($doc)) {
            continue;
        }

        $lines = array_values(array_filter(
            $doc['lines'] ?? [],
            static function ($line) {
                return is_array($line) && open_issue_request_line_is_visible($line);
            }
        ));

        if (count($lines) === 0) {
            continue;
        }

        $documents[] = open_issue_request_rebuild_document($doc, $lines);
    }

    $payload['documents'] = $documents;

    return $payload;
}

function open_issue_available_lot_qty(array $lots)
{
    $total = 0.0;

    foreach ($lots as $lot) {
        if (!is_array($lot)) {
            continue;
        }

        $availableQty = (float)($lot['available_qty'] ?? 0);

        if ($availableQty > 0) {
            $total += $availableQty;
        }
    }

    return $total;
}

$conn = get_whpokayoke_connection();
$hasHeader = fetch_one($conn, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'WarehouseIssueRequestHeader'");
$hasLines = fetch_one($conn, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'WarehouseIssueRequestLines'");
if (!$hasHeader || !$hasLines) {
    json_out([
        'ok' => false,
        'message' => 'Issue request tables are missing. Run the updated database/schema.sql on WHPOKAYOKE.',
        'requests' => [],
        'documents' => []
    ]);
}
$hasDestinationArea = fetch_one(
    $conn,
    "SELECT 1 AS HasColumn
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'WarehouseIssueRequestHeader'
       AND COLUMN_NAME = 'DestinationArea'"
);
$destinationAreaSelect = $hasDestinationArea
    ? 'H.DestinationArea,'
    : "CAST('' AS NVARCHAR(80)) AS DestinationArea,";
$hasWarehouseLotNo = fetch_one(
    $conn,
    "SELECT 1 AS HasColumn
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'WarehouseIssueRequestLines'
       AND COLUMN_NAME = 'WarehouseLotNo'"
);
$warehouseLotSelect = $hasWarehouseLotNo
    ? 'L.WarehouseLotNo,'
    : "CAST('' AS NVARCHAR(80)) AS WarehouseLotNo,";

$hasAppUsers = fetch_one(
    $conn,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'AppUsers'"
);

$hasAppUsersRequestorSection = $hasAppUsers ? fetch_one(
    $conn,
    "SELECT 1 AS HasColumn
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'AppUsers'
       AND COLUMN_NAME = 'RequestorSection'"
) : false;

$hasAppUsersUsername = $hasAppUsers ? fetch_one(
    $conn,
    "SELECT 1 AS HasColumn
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'AppUsers'
       AND COLUMN_NAME = 'Username'"
) : false;

$requestorSectionSelect = ($hasAppUsers && $hasAppUsersRequestorSection && $hasAppUsersUsername)
    ? "COALESCE(U.RequestorSection, '') AS RequestorSection,"
    : "CAST('' AS NVARCHAR(80)) AS RequestorSection,";

$appUsersJoin = ($hasAppUsers && $hasAppUsersRequestorSection && $hasAppUsersUsername)
    ? "LEFT JOIN AppUsers U ON U.Username = H.RequestedByUsername"
    : "";
$rows = fetch_all(
    $conn,
    "SELECT TOP 200
        H.RequestID,
        H.RequestNo,
        H.ITRNumber,
        H.SAP_IT_DocEntry,
        H.SAP_IT_DocNum,
        {$destinationAreaSelect}
        H.NeededDate,
        H.Status HeaderStatus,
        H.Remarks,
        H.RequestedByUsername,
        {$requestorSectionSelect}
        H.RequestedAt,
        L.RequestLineID,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        ISNULL(L.IssuedQty, 0) AS IssuedQty,
        L.LotNo,
        {$warehouseLotSelect}
        L.Status LineStatus
     FROM WarehouseIssueRequestHeader H
     INNER JOIN WarehouseIssueRequestLines L ON H.RequestID = L.RequestID
     {$appUsersJoin}
     WHERE H.Status IN ('OPEN','PARTIAL')
       AND L.Status IN ('OPEN','PARTIAL')
       AND L.RequestedQty > ISNULL(L.IssuedQty, 0)
     ORDER BY H.RequestedAt DESC, H.RequestNo DESC, L.RequestLineID ASC"
);

$rowSignatureParts = [];
foreach ($rows as $sigRow) {
    $rowSignatureParts[] = implode(':', [
        $sigRow['RequestID'] ?? '',
        $sigRow['RequestLineID'] ?? '',
        $sigRow['RequestedQty'] ?? '',
        $sigRow['IssuedQty'] ?? '',
        $sigRow['LotNo'] ?? '',
        $sigRow['WarehouseLotNo'] ?? '',
        $sigRow['LineStatus'] ?? ''
    ]);
}

$cacheKey = sap_cache_make_key('sap.open_issue_requests', [
    'signature' => hash('sha256', implode('|', $rowSignatureParts)),
    'pack_sizes' => itr_pack_sizes_cache_token(),
    // New cache version: initial request loading uses lightweight OITW stock only.
    // Batch/lot balances are loaded lazily per item from get_lot_suggestions.php.
    'lot_query_version' => 'oitw_stock_always_refresh_v11'
]);

$forceRefresh = isset($_GET['refresh'])
    && in_array(
        strtolower(trim((string)$_GET['refresh'])),
        ['1', 'true', 'yes'],
        true
    );

/*
    Normal page loads may use a short stale window to protect SAP.
    ?refresh=1 bypasses this request cache and performs the lightweight OITW refresh
    when live SAP reads are enabled.
*/
if (!$forceRefresh) {
    $cached = sap_cache_get_preferred($conn, $cacheKey, 300);

    if ($cached !== null) {
        json_out(open_issue_request_filter_payload($cached));
    }
}

$sapLiveQueriesEnabled = sap_cache_live_queries_enabled();
$hydratedFromCache = null;
$stockByItem = [];
$uomByItem = [];
$batchByItem = [];
$lotsByItem = [];
$itemCodes = [];

foreach ($rows as $r) {
    $itemCode = trim((string)$r['ItemCode']);

    if ($itemCode !== '') {
        $itemCodes[$itemCode] = true;
    }
}

if (!$sapLiveQueriesEnabled) {
    /*
        Full/heavy live SAP reads are disabled, so keep any useful cached metadata.
        IMPORTANT: stock itself is refreshed from OITW below using one lightweight query.
    */
    $latestCached = sap_cache_get_latest_by_scope($conn, 'sap.open_issue_requests', 86400);

    if (is_array($latestCached)) {
        foreach (($latestCached['requests'] ?? []) as $cachedLine) {
            $itemCode = trim((string)($cachedLine['item_code'] ?? ''));

            if ($itemCode === '' || !isset($itemCodes[$itemCode])) {
                continue;
            }

            $cachedStockQty = (float)($cachedLine['warehouse_stock_qty'] ?? 0);
            $stockByItem[$itemCode] = max($stockByItem[$itemCode] ?? 0.0, $cachedStockQty);

            $cachedUom = trim((string)($cachedLine['uom'] ?? ''));
            if ($cachedUom !== '' && !isset($uomByItem[$itemCode])) {
                $uomByItem[$itemCode] = $cachedUom;
            }

            if (array_key_exists('is_batch_managed', $cachedLine)) {
                $batchByItem[$itemCode] = [
                    'managed' => (bool)$cachedLine['is_batch_managed'],
                    'message' => (string)($cachedLine['batch_management_message'] ?? '')
                ];
            }
        }

        $hydratedFromCache = $latestCached['_cache'] ?? null;
    }

    $latestStockCached = sap_cache_get_latest_by_scope($conn, 'sap.stock.list', 86400);

    if (is_array($latestStockCached)) {
        foreach (($latestStockCached['stocks'] ?? []) as $cachedStock) {
            $itemCode = trim((string)($cachedStock['item_code'] ?? ''));
            $warehouseCode = trim((string)($cachedStock['warehouse_code'] ?? ''));

            if ($itemCode === '' || !isset($itemCodes[$itemCode]) || strcasecmp($warehouseCode, '01') !== 0) {
                continue;
            }

            $cachedStockQty = (float)($cachedStock['on_hand_qty'] ?? 0);
            $stockByItem[$itemCode] = max($stockByItem[$itemCode] ?? 0.0, $cachedStockQty);
        }
    }
}

/*
    LIGHTWEIGHT LIVE STOCK REFRESH
    ------------------------------
    Even when sap_cache_live_queries_enabled() is FALSE, refresh WH 01 item stock
    using ONE indexed OITW query. This avoids the old false-zero problem while still
    keeping expensive OBTQ/OBTN batch/lot reads out of the initial page load.
*/
$didLiveStockRefresh = false;
$stockRefreshError = '';

try {
    if (count($itemCodes) > 0) {
        $erp = get_erp_connection();

        /*
            When full live reads are enabled, preserve the existing batch-managed
            filtering and UOM lookup. When disabled, do not run these extra lookups;
            only the lightweight OITW stock query is required.
        */
        if ($sapLiveQueriesEnabled && fetch_one(
            $erp,
            "SELECT 1 AS HasColumn
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = 'OITM'
               AND COLUMN_NAME = 'ManBtchNum'"
        )) {
            foreach (sap_item_batch_statuses($erp, array_keys($itemCodes)) as $itemCode => $batchStatus) {
                $batchByItem[$itemCode] = $batchStatus;

                if (!($batchStatus['managed'] ?? false)) {
                    unset($itemCodes[$itemCode]);
                }
            }
        }

        if (count($itemCodes) > 0) {
            $codes = array_keys($itemCodes);
            $placeholders = implode(',', array_fill(0, count($codes), '?'));

            $stockRows = fetch_all(
                $erp,
                "SELECT ItemCode, WhsCode, ISNULL(OnHand, 0) AS OnHand
                 FROM OITW
                 WHERE WhsCode = ?
                   AND ItemCode IN ({$placeholders})",
                array_merge(['01'], $codes)
            );

            /*
                IMPORTANT: overwrite cached zero/stale values with SAP OITW values.
                Do not use max() here; SAP is authoritative for the current item stock.
            */
            foreach ($codes as $code) {
                $stockByItem[$code] = 0.0;
            }

            foreach ($stockRows as $stockRow) {
                $code = trim((string)($stockRow['ItemCode'] ?? ''));
                if ($code !== '') {
                    $stockByItem[$code] = (float)($stockRow['OnHand'] ?? 0);
                }
            }

            $didLiveStockRefresh = true;
        }

        if ($sapLiveQueriesEnabled && count($itemCodes) > 0 && fetch_one(
            $erp,
            "SELECT 1 AS HasColumn
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = 'OITM'
               AND COLUMN_NAME = 'InvntryUom'"
        )) {
            $codes = array_keys($itemCodes);
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $uomRows = fetch_all(
                $erp,
                "SELECT ItemCode, COALESCE(InvntryUom, '') AS UomName
                 FROM OITM
                 WHERE ItemCode IN ({$placeholders})",
                $codes
            );

            foreach ($uomRows as $uomRow) {
                $uomByItem[trim((string)$uomRow['ItemCode'])] = (string)$uomRow['UomName'];
            }
        }
    }
} catch (Throwable $e) {
    /*
        Do not break the issuer page if SAP is temporarily unreachable.
        Existing cache values remain as fallback.
    */
    $stockRefreshError = $e->getMessage();
}

/*
    LOTS ARE LAZY-LOADED
    --------------------
    Do not query OBTQ/OBTN for all open request items here.
    api/issuer/get_lot_suggestions.php should fetch lots only for the selected item.
*/
$lotsByItem = [];

$documents = [];
$requests = [];
$itemLocationByCode = item_locations_by_codes($conn, array_keys($itemCodes));
foreach ($rows as $r) {
    $itemCode = trim((string)$r['ItemCode']);
    $neededDate = $r['NeededDate'] instanceof DateTimeInterface ? $r['NeededDate']->format('Y-m-d') : (string)$r['NeededDate'];
    $requestedAt = $r['RequestedAt'] instanceof DateTimeInterface ? $r['RequestedAt']->format('Y-m-d H:i:s') : (string)$r['RequestedAt'];
    $requestedQty = (float)$r['RequestedQty'];
    $issuedQty = (float)$r['IssuedQty'];
    $remainingQty = max(0, $requestedQty - $issuedQty);
    if ($remainingQty <= 0.0005) {
        continue;
    }

    $stockQty = $stockByItem[$itemCode] ?? 0.0;
    $lotAvailableStockQty = open_issue_available_lot_qty($lotsByItem[$itemCode] ?? []);
    if ($lotAvailableStockQty > 0 && $stockQty <= 0.0005) {
        $stockQty = $lotAvailableStockQty;
    }

    $qtyPerPack = itr_qty_per_pack_for_item($itemCode);
    $itemLocation = $itemLocationByCode[$itemCode] ?? [];
    $batchStatus = $batchByItem[$itemCode] ?? [
        'managed' => true,
        'message' => ''
    ];
    $isBatchManaged = (bool)($batchStatus['managed'] ?? true);
    $isReturnedNoStock = strtoupper((string)($r['LineStatus'] ?? '')) === 'RETURNED_NO_STOCK';

    if (!$isBatchManaged) {
        continue;
    }
    $line = [
        'request_id' => (int)$r['RequestID'],
        'request_line_id' => (int)$r['RequestLineID'],
        'request_no' => (string)$r['RequestNo'],
        'itr_number' => (string)$r['ITRNumber'],
        'doc_entry' => $r['SAP_IT_DocEntry'] !== null ? (int)$r['SAP_IT_DocEntry'] : null,
        'doc_num' => (string)$r['ITRNumber'],
        'line_num' => $r['SAP_IT_LineNum'] !== null ? (int)$r['SAP_IT_LineNum'] : null,
        'item_code' => $itemCode,
        'part_name' => (string)$r['PartName'],
        'parts_code' => (string)($itemLocation['parts_code'] ?? ''),
        'location_code' => (string)($itemLocation['location_code'] ?? ''),
        'requested_qty' => $requestedQty,
        'open_qty' => $requestedQty,
        'issued_qty' => $issuedQty,
        'remaining_qty' => $remainingQty,
        'lot_no' => $isReturnedNoStock ? '' : (string)($r['LotNo'] ?? ''),
        'warehouse_lot_no' => $isReturnedNoStock ? '' : (string)($r['WarehouseLotNo'] ?? ''),
        'available_lots' => $lotsByItem[$itemCode] ?? [],
        'is_batch_managed' => true,
        'batch_management_message' => '',
        'stock_whs_code' => '01',
        'warehouse_stock_qty' => $stockQty,
        'uom' => $uomByItem[$itemCode] ?? '',
        'qty_per_pack' => $qtyPerPack,
        'qty_per_pack_source' => $qtyPerPack > 0 ? 'June 2026 Excel SUMMARY' : '',
        'num_per_msr' => 1,
        'needed_date' => $neededDate,
        'destination_area' => (string)($r['DestinationArea'] ?? ''),
        'requested_by' => (string)$r['RequestedByUsername'],
        'requestor_section' => (string)($r['RequestorSection'] ?? ''),
        'to_whs_code' => requestor_section_to_warehouse($r['RequestorSection'] ?? ''),
        'remarks' => (string)$r['Remarks']
    ];
    $requests[] = $line;

    $docKey = (string)$r['RequestNo'];
    if (!isset($documents[$docKey])) {
        $documents[$docKey] = [
            'request_id' => (int)$r['RequestID'],
            'request_no' => $docKey,
            'doc_num' => (string)$r['ITRNumber'],
            'itr_number' => (string)$r['ITRNumber'],
            'doc_entry' => $r['SAP_IT_DocEntry'] !== null ? (int)$r['SAP_IT_DocEntry'] : null,
            'doc_date' => $neededDate,
            'needed_date' => $neededDate,
            'destination_area' => (string)($r['DestinationArea'] ?? ''),
            'requested_by' => (string)$r['RequestedByUsername'],
            'requestor_section' => (string)($r['RequestorSection'] ?? ''),
            'to_whs_code' => requestor_section_to_warehouse($r['RequestorSection'] ?? ''),
            'requested_at' => $requestedAt,
            'remarks' => (string)$r['Remarks'],
            'line_count' => 0,
            'requested_qty' => 0.0,
            'open_qty' => 0.0,
            'issued_qty' => 0.0,
            'remaining_qty' => 0.0,
            'warehouse_stock_qty' => 0.0,
            'stock_whs_code' => '01',
            'lines' => []
        ];
    }
    $documents[$docKey]['line_count']++;
    $documents[$docKey]['requested_qty'] += $requestedQty;
    $documents[$docKey]['open_qty'] += $requestedQty;
    $documents[$docKey]['issued_qty'] += $issuedQty;
    $documents[$docKey]['remaining_qty'] += $remainingQty;
    $documents[$docKey]['warehouse_stock_qty'] += $stockQty;
    $documents[$docKey]['lines'][] = $line;
}

$payload = [
    'ok' => true,
    'requests' => $requests,
    'documents' => array_values($documents),
    '_stock' => [
        'warehouse' => '01',
        'source' => $didLiveStockRefresh ? 'SAP_OITW_LIVE' : 'CACHE_FALLBACK',
        'live_queries_enabled' => $sapLiveQueriesEnabled,
        'refresh_error' => $stockRefreshError
    ]
];

if ($hydratedFromCache !== null) {
    $payload['_cache'] = [
        'hit' => false,
        'hydrated_from_latest_scope' => true,
        'source_cached_at' => $hydratedFromCache['cached_at'] ?? '',
        'source_expires_at' => $hydratedFromCache['expires_at'] ?? '',
        'source_key' => $hydratedFromCache['key'] ?? ''
    ];
}

if ($sapLiveQueriesEnabled || $didLiveStockRefresh) {
    sap_cache_put($conn, 'sap.open_issue_requests', $cacheKey, $payload, 60);
}
json_out($payload);
?>
