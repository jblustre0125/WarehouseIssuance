<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';
require_role([ROLE_PICKER, ROLE_ISSUER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function json_out($payload)
{
    echo json_encode($payload);
    exit;
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
     WHERE H.Status IN ('OPEN','PARTIAL')
       AND L.Status IN ('OPEN','PARTIAL')
       AND L.RequestedQty > ISNULL(L.IssuedQty, 0)
     ORDER BY H.NeededDate ASC, H.RequestedAt ASC, H.RequestNo ASC, L.RequestLineID ASC"
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
    'pack_sizes' => itr_pack_sizes_cache_token()
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($conn, $cacheKey);

    if ($cached !== null) {
        json_out($cached);
    }
}

$erp = get_erp_connection();
$stockByItem = [];
$uomByItem = [];
$lotsByItem = [];
$itemCodes = [];

foreach ($rows as $r) {
    $itemCode = trim((string)$r['ItemCode']);

    if ($itemCode !== '') {
        $itemCodes[$itemCode] = true;
    }
}

$hasOitw = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OITW'"
);

if ($hasOitw && count($itemCodes) > 0) {
    $codes = array_keys($itemCodes);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stockRows = fetch_all(
        $erp,
        "SELECT ItemCode, WhsCode, OnHand
         FROM OITW
         WHERE WhsCode = ?
           AND ItemCode IN ({$placeholders})",
        array_merge(['01'], $codes)
    );

    foreach ($stockRows as $stockRow) {
        $stockByItem[(string)$stockRow['ItemCode']] = (float)$stockRow['OnHand'];
    }
}

if (count($itemCodes) > 0 && fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OITM' AND COLUMN_NAME = 'InvntryUom'")) {
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
        $uomByItem[(string)$uomRow['ItemCode']] = (string)$uomRow['UomName'];
    }
}

$hasBatchBalance =
    count($itemCodes) > 0 &&
    fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OBTQ'") &&
    fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OBTN'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTQ' AND COLUMN_NAME = 'ItemCode'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTQ' AND COLUMN_NAME = 'SysNumber'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTQ' AND COLUMN_NAME = 'WhsCode'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTQ' AND COLUMN_NAME = 'Quantity'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTN' AND COLUMN_NAME = 'ItemCode'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTN' AND COLUMN_NAME = 'SysNumber'") &&
    fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTN' AND COLUMN_NAME = 'DistNumber'");

if ($hasBatchBalance) {
    $codes = array_keys($itemCodes);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $hasCommitQty = fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTQ' AND COLUMN_NAME = 'CommitQty'");
    $commitExpr = $hasCommitQty ? 'ISNULL(Q.CommitQty, 0)' : '0';
    $lotRows = fetch_all(
        $erp,
        "SELECT
             Q.ItemCode,
             B.DistNumber AS LotNo,
             ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) AS OnHandQty,
             ISNULL(SUM({$commitExpr}), 0) AS CommittedQty
         FROM OBTQ Q
         INNER JOIN OBTN B
            ON B.ItemCode = Q.ItemCode
           AND B.SysNumber = Q.SysNumber
         WHERE Q.WhsCode = ?
           AND Q.ItemCode IN ({$placeholders})
         GROUP BY Q.ItemCode, B.DistNumber
         ORDER BY Q.ItemCode, B.DistNumber",
        array_merge(['01'], $codes)
    );

    foreach ($lotRows as $lotRow) {
        $itemCode = (string)$lotRow['ItemCode'];
        $lotNo = trim((string)($lotRow['LotNo'] ?? ''));
        $onHandQty = (float)$lotRow['OnHandQty'];
        $committedQty = (float)$lotRow['CommittedQty'];
        $availableQty = max(0, $onHandQty - $committedQty);

        if ($lotNo === '' || $onHandQty <= 0) {
            continue;
        }

        if (!isset($lotsByItem[$itemCode])) {
            $lotsByItem[$itemCode] = [];
        }

        $lotsByItem[$itemCode][] = [
            'lot_no' => $lotNo,
            'warehouse_code' => '01',
            'on_hand_qty' => $onHandQty,
            'committed_qty' => $committedQty,
            'available_qty' => $availableQty
        ];
    }
}

$documents = [];
$requests = [];
foreach ($rows as $r) {
    $neededDate = $r['NeededDate'] instanceof DateTimeInterface ? $r['NeededDate']->format('Y-m-d') : (string)$r['NeededDate'];
    $requestedAt = $r['RequestedAt'] instanceof DateTimeInterface ? $r['RequestedAt']->format('Y-m-d H:i:s') : (string)$r['RequestedAt'];
    $requestedQty = (float)$r['RequestedQty'];
    $issuedQty = (float)$r['IssuedQty'];
    $remainingQty = max(0, $requestedQty - $issuedQty);
    $stockQty = $stockByItem[(string)$r['ItemCode']] ?? 0.0;
    $qtyPerPack = itr_qty_per_pack_for_item($r['ItemCode']);
    $line = [
        'request_id' => (int)$r['RequestID'],
        'request_line_id' => (int)$r['RequestLineID'],
        'request_no' => (string)$r['RequestNo'],
        'itr_number' => (string)$r['ITRNumber'],
        'doc_entry' => $r['SAP_IT_DocEntry'] !== null ? (int)$r['SAP_IT_DocEntry'] : null,
        'doc_num' => (string)$r['ITRNumber'],
        'line_num' => $r['SAP_IT_LineNum'] !== null ? (int)$r['SAP_IT_LineNum'] : null,
        'item_code' => (string)$r['ItemCode'],
        'part_name' => (string)$r['PartName'],
        'requested_qty' => $requestedQty,
        'open_qty' => $requestedQty,
        'issued_qty' => $issuedQty,
        'remaining_qty' => $remainingQty,
        'lot_no' => (string)($r['LotNo'] ?? ''),
        'warehouse_lot_no' => (string)($r['WarehouseLotNo'] ?? ''),
        'available_lots' => $lotsByItem[(string)$r['ItemCode']] ?? [],
        'stock_whs_code' => '01',
        'warehouse_stock_qty' => $stockQty,
        'uom' => $uomByItem[(string)$r['ItemCode']] ?? '',
        'qty_per_pack' => $qtyPerPack,
        'qty_per_pack_source' => $qtyPerPack > 0 ? 'June 2026 Excel SUMMARY' : '',
        'num_per_msr' => 1,
        'needed_date' => $neededDate,
        'destination_area' => (string)($r['DestinationArea'] ?? ''),
        'requested_by' => (string)$r['RequestedByUsername'],
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
    'documents' => array_values($documents)
];

sap_cache_put($conn, 'sap.open_issue_requests', $cacheKey, $payload, 60);
json_out($payload);
?>
