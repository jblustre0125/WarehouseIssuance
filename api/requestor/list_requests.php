<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function requestor_json_out($payload)
{
    echo json_encode($payload);
    exit;
}

function requestor_dt($value)
{
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
}

$conn = get_whpokayoke_connection();
$u = current_user();
$role = strtolower($u['role'] ?? '');

$where = [
    "H.Status IN ('OPEN','PARTIAL','RETURNED_NO_STOCK')",
    "L.Status IN ('OPEN','PARTIAL','RETURNED_NO_STOCK')",
    "L.RequestedQty > ISNULL(L.IssuedQty, 0)"
];
$params = [];

if ($role !== ROLE_ADMIN) {
    $where[] = '(H.RequestedByUserID = ? OR H.RequestedByUsername = ?)';
    $params[] = (int)($u['id'] ?? 0);
    $params[] = (string)($u['username'] ?? '');
}

/*
    Requestor Pending Queue must use the same pending logic as Issuer Open Requests.
    Important:
    - Do not load already issued lines.
    - Do not use TOP 100 line rows because many issued lines can consume the limit and hide older pending requests.
    - Show only request lines where RequestedQty > IssuedQty.
*/
$rows = fetch_all(
    $conn,
    "SELECT
        H.RequestID,
        H.RequestNo,
        H.ITRNumber,
        H.SAP_IT_DocEntry,
        H.NeededDate,
        H.Status AS HeaderStatus,
        H.Remarks,
        H.RequestedAt,
        L.RequestLineID,
        L.SAP_IT_DocEntry AS LineSAPDocEntry,
        L.SAP_IT_DocNum,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        ISNULL(L.IssuedQty, 0) AS IssuedQty,
        L.RequestedQty - ISNULL(L.IssuedQty, 0) AS RemainingQty,
        L.LotNo,
        L.Status AS LineStatus
     FROM WarehouseIssueRequestHeader H
     INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
     WHERE " . implode(' AND ', $where) . "
     ORDER BY H.RequestedAt DESC, H.RequestNo DESC, L.RequestLineID ASC",
    $params
);

$rowSignatureParts = [];
foreach ($rows as $sigRow) {
    $rowSignatureParts[] = implode(':', [
        $sigRow['RequestID'] ?? '',
        $sigRow['RequestLineID'] ?? '',
        $sigRow['RequestedQty'] ?? '',
        $sigRow['IssuedQty'] ?? '',
        $sigRow['RemainingQty'] ?? '',
        $sigRow['LotNo'] ?? '',
        $sigRow['LineStatus'] ?? ''
    ]);
}

$cacheKey = sap_cache_make_key('sap.requestor.list_requests', [
    'role' => $role,
    'user' => $role === ROLE_ADMIN ? 'admin' : (string)($u['username'] ?? ''),
    'pending_logic' => 'issuer_matching_remaining_lines_returned_no_stock_top_v2',
    'signature' => hash('sha256', implode('|', $rowSignatureParts))
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($conn, $cacheKey);

    if ($cached !== null) {
        requestor_json_out($cached);
    }
}

$erp = get_erp_connection();
$stockByLine = [];
$itemCodes = [];
$docEntries = [];

foreach ($rows as $r) {
    $itemCode = trim((string)$r['ItemCode']);
    $docEntry = (int)($r['LineSAPDocEntry'] ?? $r['SAP_IT_DocEntry'] ?? 0);

    if ($itemCode !== '') {
        $itemCodes[$itemCode] = true;
    }

    if ($docEntry > 0) {
        $docEntries[$docEntry] = true;
    }
}

$hasOitw = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'OITW'"
);

$hasWtq1 = fetch_one(
    $erp,
    "SELECT 1 AS HasTable
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME = 'WTQ1'"
);

if ($hasOitw && $hasWtq1 && count($itemCodes) > 0 && count($docEntries) > 0) {
    $codes = array_keys($itemCodes);
    $entries = array_keys($docEntries);
    $entryPlaceholders = implode(',', array_fill(0, count($entries), '?'));
    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));

    $stockRows = fetch_all(
        $erp,
        "SELECT
            L.DocEntry,
            L.LineNum,
            L.ItemCode,
            L.FromWhsCod,
            L.WhsCode,
            ISNULL(FW.OnHand, 0) AS SourceStockQty,
            ISNULL(TW.OnHand, 0) AS DestinationStockQty
         FROM WTQ1 L
         LEFT JOIN OITW FW ON FW.ItemCode = L.ItemCode AND FW.WhsCode = L.FromWhsCod
         LEFT JOIN OITW TW ON TW.ItemCode = L.ItemCode AND TW.WhsCode = L.WhsCode
         WHERE L.DocEntry IN ({$entryPlaceholders})
           AND L.ItemCode IN ({$codePlaceholders})",
        array_merge($entries, $codes)
    );

    foreach ($stockRows as $stockRow) {
        $key = (string)$stockRow['DocEntry'] . '|' . (string)$stockRow['LineNum'] . '|' . (string)$stockRow['ItemCode'];
        $stockByLine[$key] = [
            'from_whs_code' => (string)$stockRow['FromWhsCod'],
            'to_whs_code' => (string)$stockRow['WhsCode'],
            'source_stock_qty' => (float)$stockRow['SourceStockQty'],
            'destination_stock_qty' => (float)$stockRow['DestinationStockQty']
        ];
    }
}

$documents = [];

foreach ($rows as $r) {
    $requestId = (int)$r['RequestID'];
    $headerStatus = strtoupper((string)$r['HeaderStatus']);

    if (!isset($documents[$requestId])) {
        $documents[$requestId] = [
            'request_id' => $requestId,
            'request_no' => (string)$r['RequestNo'],
            'itr_number' => (string)$r['ITRNumber'],
            'doc_entry' => $r['SAP_IT_DocEntry'] !== null ? (int)$r['SAP_IT_DocEntry'] : null,
            'needed_date' => requestor_dt($r['NeededDate']),
            'status' => (string)$r['HeaderStatus'],
            'remarks' => (string)$r['Remarks'],
            'requested_at' => $r['RequestedAt'] instanceof DateTimeInterface ? $r['RequestedAt']->format('Y-m-d H:i:s') : (string)$r['RequestedAt'],
            'line_count' => 0,
            'requested_qty' => 0.0,
            'issued_qty' => 0.0,
            'remaining_qty' => 0.0,
            'source_stock_qty' => 0.0,
            'destination_stock_qty' => 0.0,
            'returned_no_stock_count' => 0,
            'has_returned_no_stock' => false,
            /*
                Editable only while the whole request is still OPEN.
                PARTIAL requests are view-only / Load Remaining because some issuance was already done.
            */
            'editable' => in_array($headerStatus, ['OPEN', 'RETURNED_NO_STOCK'], true),
            'lines' => []
        ];
    }

    $requestedQty = (float)$r['RequestedQty'];
    $issuedQty = (float)$r['IssuedQty'];
    $remainingQty = max(0, (float)($r['RemainingQty'] ?? ($requestedQty - $issuedQty)));

    $stockKey = (string)($r['LineSAPDocEntry'] ?? $r['SAP_IT_DocEntry']) . '|' . (string)$r['SAP_IT_LineNum'] . '|' . (string)$r['ItemCode'];
    $stock = $stockByLine[$stockKey] ?? [
        'from_whs_code' => '01',
        'to_whs_code' => '',
        'source_stock_qty' => 0.0,
        'destination_stock_qty' => 0.0
    ];

    $lineStatus = strtoupper((string)$r['LineStatus']);

    if ($lineStatus === 'RETURNED_NO_STOCK') {
        $documents[$requestId]['returned_no_stock_count']++;
        $documents[$requestId]['has_returned_no_stock'] = true;
    }

    if (
        $issuedQty > 0 ||
        !in_array($lineStatus, ['OPEN', 'RETURNED_NO_STOCK'], true) ||
        !in_array($headerStatus, ['OPEN', 'RETURNED_NO_STOCK'], true)
    ) {
        $documents[$requestId]['editable'] = false;
    }

    $documents[$requestId]['line_count']++;
    $documents[$requestId]['requested_qty'] += $requestedQty;
    $documents[$requestId]['issued_qty'] += $issuedQty;
    $documents[$requestId]['remaining_qty'] += $remainingQty;
    $documents[$requestId]['source_stock_qty'] += (float)$stock['source_stock_qty'];
    $documents[$requestId]['destination_stock_qty'] += (float)$stock['destination_stock_qty'];

    $documents[$requestId]['lines'][] = [
        'request_line_id' => (int)$r['RequestLineID'],
        'doc_entry' => $r['LineSAPDocEntry'] !== null ? (int)$r['LineSAPDocEntry'] : ($r['SAP_IT_DocEntry'] !== null ? (int)$r['SAP_IT_DocEntry'] : null),
        'doc_num' => (string)$r['SAP_IT_DocNum'],
        'line_num' => $r['SAP_IT_LineNum'] !== null ? (int)$r['SAP_IT_LineNum'] : null,
        'item_code' => (string)$r['ItemCode'],
        'part_name' => (string)$r['PartName'],
        'requested_qty' => $requestedQty,
        'issued_qty' => $issuedQty,
        'remaining_qty' => $remainingQty,
        'lot_no' => (string)($r['LotNo'] ?? ''),
        'source_stock_qty' => (float)$stock['source_stock_qty'],
        'destination_stock_qty' => (float)$stock['destination_stock_qty'],
        'from_whs_code' => (string)$stock['from_whs_code'],
        'to_whs_code' => (string)$stock['to_whs_code'],
        'status' => (string)$r['LineStatus']
    ];
}

foreach ($documents as &$doc) {
    $returnedCount = (int)($doc['returned_no_stock_count'] ?? 0);
    $lineCount = (int)($doc['line_count'] ?? 0);
    $issuedQty = (float)($doc['issued_qty'] ?? 0);

    if ($returnedCount > 0) {
        $doc['has_returned_no_stock'] = true;
        $doc['display_status'] = 'RETURNED_NO_STOCK';

        if ($issuedQty <= 0.0005 && $returnedCount >= $lineCount) {
            $doc['status'] = 'RETURNED_NO_STOCK';
            $doc['editable'] = true;
        }
    } else {
        $doc['display_status'] = $doc['status'];
    }
}
unset($doc);

usort($documents, static function ($a, $b) {
    $aReturned = !empty($a['has_returned_no_stock']) ? 1 : 0;
    $bReturned = !empty($b['has_returned_no_stock']) ? 1 : 0;

    if ($aReturned !== $bReturned) {
        return $bReturned <=> $aReturned;
    }

    return strcmp((string)($b['requested_at'] ?? ''), (string)($a['requested_at'] ?? ''));
});

$payload = [
    'ok' => true,
    'requests' => array_values($documents)
];

sap_cache_put($conn, 'sap.requestor.list_requests', $cacheKey, $payload, 60);
requestor_json_out($payload);
?>
