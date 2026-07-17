<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';
require_once __DIR__ . '/../includes/item_locations.php';
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
    'lot_query_version' => 'fifo_initial_available_lots_requestor_section_location_v5_latest_first'
]);

$cached = sap_cache_get_preferred($conn, $cacheKey, 86400);

if ($cached !== null) {
    json_out($cached);
}

$sapLiveQueriesEnabled = sap_cache_live_queries_enabled();
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

if ($sapLiveQueriesEnabled) {
    $erp = get_erp_connection();

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


    /*
        FAST FIFO MODE:
        Do not load every SAP lot during initial request loading. Load a small FIFO
        window per ItemCode, then subtract Warehouse Issuance issued qty so a fully
        consumed first lot does not hide the next available lot.

        Final validation still uses api/issuer/check_lot_balance.php before printing/saving.
    */
    $lotsByItem = [];

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

    $fifoDateParts = [];
    foreach (['InDate', 'CreateDate', 'MnfDate', 'ExpDate'] as $dateColumn) {
        if (fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OBTN' AND COLUMN_NAME = ?", [$dateColumn])) {
            $fifoDateParts[] = 'TRY_CONVERT(datetime, B.[' . $dateColumn . '])';
        }
    }

    $fifoDateExpr = count($fifoDateParts) > 0
        ? 'COALESCE(' . implode(', ', $fifoDateParts) . ", CONVERT(datetime, '2099-12-31'))"
        : "CONVERT(datetime, '2099-12-31')";

    $lotRows = fetch_all(
        $erp,
        "WITH LotBalances AS (
             SELECT
                 Q.ItemCode,
                 B.DistNumber AS LotNo,
                 Q.WhsCode,
                 ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) AS OnHandQty,
                 ISNULL(SUM({$commitExpr}), 0) AS CommittedQty,
                 MIN({$fifoDateExpr}) AS FifoDate
             FROM OBTQ Q
             INNER JOIN OBTN B
                ON B.ItemCode = Q.ItemCode
               AND B.SysNumber = Q.SysNumber
             WHERE Q.WhsCode = ?
               AND Q.ItemCode IN ({$placeholders})
             GROUP BY Q.ItemCode, B.DistNumber, Q.WhsCode
             HAVING ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) - ISNULL(SUM({$commitExpr}), 0) > 0
         ), RankedLots AS (
             SELECT
                 ItemCode,
                 LotNo,
                 WhsCode,
                 OnHandQty,
                 CommittedQty,
                 OnHandQty - CommittedQty AS AvailableQty,
                 FifoDate,
                 ROW_NUMBER() OVER (PARTITION BY ItemCode ORDER BY FifoDate ASC, LotNo ASC) AS Rn
             FROM LotBalances
         )
         SELECT ItemCode, LotNo, WhsCode, OnHandQty, CommittedQty, AvailableQty, FifoDate, Rn
         FROM RankedLots
         WHERE Rn <= 10
         ORDER BY ItemCode, Rn",
        array_merge(['01'], $codes)
    );

    $appIssuedByItemLot = [];
    if (count($lotRows) > 0) {
        $lotItemCodes = [];
        $lotNos = [];
        foreach ($lotRows as $lotRow) {
            $lotItemCode = trim((string)($lotRow['ItemCode'] ?? ''));
            $lotNo = trim((string)($lotRow['LotNo'] ?? ''));
            if ($lotItemCode !== '') {
                $lotItemCodes[$lotItemCode] = true;
            }
            if ($lotNo !== '') {
                $lotNos[$lotNo] = true;
            }
        }

        if (count($lotItemCodes) > 0 && count($lotNos) > 0) {
            $itemPlaceholders = implode(',', array_fill(0, count($lotItemCodes), '?'));
            $lotPlaceholders = implode(',', array_fill(0, count($lotNos), '?'));

            if (
                issuer_lot_has_table($conn, 'IssuanceTransactions') &&
                issuer_lot_has_column($conn, 'IssuanceTransactions', 'ItemCode') &&
                issuer_lot_has_column($conn, 'IssuanceTransactions', 'LotNo') &&
                issuer_lot_has_column($conn, 'IssuanceTransactions', 'Quantity')
            ) {
                $issuedRows = fetch_all(
                    $conn,
                    "SELECT ItemCode, LotNo, ISNULL(SUM(ISNULL(Quantity, 0)), 0) AS IssuedQty
                     FROM IssuanceTransactions
                     WHERE ItemCode IN ({$itemPlaceholders})
                       AND LotNo IN ({$lotPlaceholders})
                     GROUP BY ItemCode, LotNo",
                    array_merge(array_keys($lotItemCodes), array_keys($lotNos))
                );
            } elseif (
                issuer_lot_has_table($conn, 'WarehouseIssueRequestLines') &&
                issuer_lot_has_column($conn, 'WarehouseIssueRequestLines', 'ItemCode') &&
                issuer_lot_has_column($conn, 'WarehouseIssueRequestLines', 'LotNo') &&
                issuer_lot_has_column($conn, 'WarehouseIssueRequestLines', 'IssuedQty')
            ) {
                $statusFilter = issuer_lot_has_column($conn, 'WarehouseIssueRequestLines', 'Status')
                    ? "AND ISNULL(Status, '') NOT IN ('CANCELLED', 'CANCELED', 'VOID')"
                    : '';
                $issuedRows = fetch_all(
                    $conn,
                    "SELECT ItemCode, LotNo, ISNULL(SUM(ISNULL(IssuedQty, 0)), 0) AS IssuedQty
                     FROM WarehouseIssueRequestLines
                     WHERE ItemCode IN ({$itemPlaceholders})
                       AND LotNo IN ({$lotPlaceholders})
                       {$statusFilter}
                     GROUP BY ItemCode, LotNo",
                    array_merge(array_keys($lotItemCodes), array_keys($lotNos))
                );
            } else {
                $issuedRows = [];
            }

            foreach ($issuedRows as $issuedRow) {
                $key = strtoupper(trim((string)($issuedRow['ItemCode'] ?? ''))) . '|' . strtoupper(trim((string)($issuedRow['LotNo'] ?? '')));
                $appIssuedByItemLot[$key] = (float)($issuedRow['IssuedQty'] ?? 0);
            }
        }
    }

    foreach ($lotRows as $lotRow) {
        $itemCode = trim((string)($lotRow['ItemCode'] ?? ''));
        $lotNo = trim((string)($lotRow['LotNo'] ?? ''));
        $sapAvailableQty = (float)($lotRow['AvailableQty'] ?? 0);
        $appIssuedQty = $appIssuedByItemLot[strtoupper($itemCode) . '|' . strtoupper($lotNo)] ?? 0.0;
        $availableQty = max(0, $sapAvailableQty - $appIssuedQty);

        if ($itemCode === '' || $lotNo === '' || $availableQty <= 0) {
            continue;
        }

        $fifoDate = $lotRow['FifoDate'] ?? null;
        if ($fifoDate instanceof DateTimeInterface) {
            $fifoDate = $fifoDate->format('Y-m-d');
        }

        if (!isset($lotsByItem[$itemCode])) {
            $lotsByItem[$itemCode] = [];
        }

        $lotsByItem[$itemCode][] = [
            'lot_no' => $lotNo,
            'warehouse_code' => (string)($lotRow['WhsCode'] ?? '01'),
            'on_hand_qty' => (float)($lotRow['OnHandQty'] ?? 0),
            'committed_qty' => (float)($lotRow['CommittedQty'] ?? 0),
            'sap_available_qty' => $sapAvailableQty,
            'issued_qty' => $appIssuedQty,
            'available_qty' => $availableQty,
            'fifo_date' => (string)$fifoDate,
            'fifo_rank' => (int)($lotRow['Rn'] ?? 0)
        ];
    }
}
}

$documents = [];
$requests = [];
$itemLocationByCode = item_locations_by_codes($conn, array_keys($itemCodes));
foreach ($rows as $r) {
    $neededDate = $r['NeededDate'] instanceof DateTimeInterface ? $r['NeededDate']->format('Y-m-d') : (string)$r['NeededDate'];
    $requestedAt = $r['RequestedAt'] instanceof DateTimeInterface ? $r['RequestedAt']->format('Y-m-d H:i:s') : (string)$r['RequestedAt'];
    $requestedQty = (float)$r['RequestedQty'];
    $issuedQty = (float)$r['IssuedQty'];
    $remainingQty = max(0, $requestedQty - $issuedQty);
    if ($remainingQty <= 0.0005) {
        continue;
    }

    $stockQty = $stockByItem[(string)$r['ItemCode']] ?? 0.0;
    $qtyPerPack = itr_qty_per_pack_for_item($r['ItemCode']);
    $itemLocation = $itemLocationByCode[(string)$r['ItemCode']] ?? [];
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
        'parts_code' => (string)($itemLocation['parts_code'] ?? ''),
        'location_code' => (string)($itemLocation['location_code'] ?? ''),
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
    'documents' => array_values($documents)
];

if ($sapLiveQueriesEnabled) {
    sap_cache_put($conn, 'sap.open_issue_requests', $cacheKey, $payload, 60);
}
json_out($payload);
?>
