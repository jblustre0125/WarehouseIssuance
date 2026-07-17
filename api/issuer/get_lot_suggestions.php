<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/sap_cache.php';
require_once __DIR__ . '/lot_balance_lib.php';

function json_out($payload)
{
    echo json_encode($payload);
    exit;
}

$itemCode = trim((string)($_GET['item_code'] ?? $_POST['item_code'] ?? ''));
$warehouseCode = trim((string)($_GET['warehouse_code'] ?? $_POST['warehouse_code'] ?? '01'));
$warehouseCode = $warehouseCode !== '' ? $warehouseCode : '01';
$qtyNeeded = (float)str_replace(',', '', (string)($_GET['qty_needed'] ?? $_POST['qty_needed'] ?? 0));
$qtyNeeded = $qtyNeeded > 0 ? $qtyNeeded : 0.0;

if ($itemCode === '') {
    json_out([
        'ok' => false,
        'message' => 'Item code is required.',
        'lots' => []
    ]);
}

$whp = get_whpokayoke_connection();
$cacheKey = sap_cache_make_key('sap.issuer.lot_suggestions', [
    'item_code' => strtoupper($itemCode),
    'warehouse_code' => strtoupper($warehouseCode),
    'qty_needed' => number_format($qtyNeeded, 3, '.', ''),
    'version' => 'fifo_sequence_v1'
]);

$cached = sap_cache_get_preferred($whp, $cacheKey, 3600);

if ($cached !== null) {
    json_out($cached);
}

$erp = get_erp_connection();

if (
    !issuer_lot_has_table($erp, 'OBTQ') ||
    !issuer_lot_has_table($erp, 'OBTN') ||
    !issuer_lot_has_column($erp, 'OBTQ', 'ItemCode') ||
    !issuer_lot_has_column($erp, 'OBTQ', 'SysNumber') ||
    !issuer_lot_has_column($erp, 'OBTQ', 'WhsCode') ||
    !issuer_lot_has_column($erp, 'OBTQ', 'Quantity') ||
    !issuer_lot_has_column($erp, 'OBTN', 'ItemCode') ||
    !issuer_lot_has_column($erp, 'OBTN', 'SysNumber') ||
    !issuer_lot_has_column($erp, 'OBTN', 'DistNumber')
) {
    json_out([
        'ok' => false,
        'message' => 'SAP batch balance tables/columns are not available.',
        'lots' => []
    ]);
}

$hasCommitQty = issuer_lot_has_column($erp, 'OBTQ', 'CommitQty');
$commitExpr = $hasCommitQty ? 'ISNULL(Q.CommitQty, 0)' : '0';

$fifoDateExpr = "CONVERT(datetime, '2999-12-31', 120)";
if (issuer_lot_has_column($erp, 'OBTN', 'InDate')) {
    $fifoDateExpr = 'B.InDate';
} elseif (issuer_lot_has_column($erp, 'OBTN', 'CreateDate')) {
    $fifoDateExpr = 'B.CreateDate';
} elseif (issuer_lot_has_column($erp, 'OBTN', 'MnfDate')) {
    $fifoDateExpr = 'B.MnfDate';
} elseif (issuer_lot_has_column($erp, 'OBTN', 'ExpDate')) {
    $fifoDateExpr = 'B.ExpDate';
}

/*
    Get FIFO candidates, then validate each candidate against Warehouse Issuance IssuedQty.
    Return only the FIFO sequence needed to cover the requested quantity, not every available lot.
    Example: requested 1500, first FIFO lot has 259, second FIFO lot has 5000:
             return first lot with issue_qty 259 and second lot with issue_qty 1241.
*/
$candidates = fetch_all(
    $erp,
    "SELECT TOP 50
         Q.ItemCode,
         B.DistNumber AS LotNo,
         Q.WhsCode,
         MIN({$fifoDateExpr}) AS FifoDate,
         ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) AS OnHandQty,
         ISNULL(SUM({$commitExpr}), 0) AS CommittedQty
     FROM OBTQ Q
     INNER JOIN OBTN B
        ON B.ItemCode = Q.ItemCode
       AND B.SysNumber = Q.SysNumber
     WHERE Q.ItemCode = ?
       AND Q.WhsCode = ?
     GROUP BY Q.ItemCode, B.DistNumber, Q.WhsCode
     HAVING ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) - ISNULL(SUM({$commitExpr}), 0) > 0
     ORDER BY MIN({$fifoDateExpr}) ASC, B.DistNumber ASC",
    [$itemCode, $warehouseCode]
);

$lots = [];
$coveredQty = 0.0;
$remainingNeeded = $qtyNeeded > 0 ? $qtyNeeded : 0.0;

foreach ($candidates as $candidate) {
    $lotNo = trim((string)($candidate['LotNo'] ?? ''));

    if ($lotNo === '') {
        continue;
    }

    $balance = issuer_lot_balance($erp, $whp, $itemCode, $lotNo, $warehouseCode);

    if (!(($balance['ok'] ?? false) && ($balance['valid'] ?? false))) {
        continue;
    }

    $availableQty = (float)($balance['available_qty'] ?? 0);

    if ($availableQty <= 0) {
        continue;
    }

    $fifoDate = $candidate['FifoDate'] ?? null;
    $fifoDateText = $fifoDate instanceof DateTimeInterface ? $fifoDate->format('Y-m-d') : (string)$fifoDate;
    $suggestedIssueQty = $qtyNeeded > 0 ? min($availableQty, max(0, $remainingNeeded)) : $availableQty;

    if ($qtyNeeded > 0 && $suggestedIssueQty <= 0) {
        break;
    }

    $lots[] = [
        'lot_no' => $lotNo,
        'warehouse_code' => $warehouseCode,
        'fifo_date' => $fifoDateText,
        'on_hand_qty' => $balance['on_hand_qty'] ?? 0,
        'committed_qty' => $balance['committed_qty'] ?? 0,
        'sap_available_qty' => $balance['sap_available_qty'] ?? 0,
        'issued_qty' => $balance['issued_qty'] ?? 0,
        'available_qty' => $availableQty,
        'suggested_issue_qty' => $suggestedIssueQty,
        'label' => 'FIFO issue ' . $suggestedIssueQty . ' / available ' . $availableQty
    ];

    $coveredQty += $suggestedIssueQty;

    if ($qtyNeeded > 0) {
        $remainingNeeded = max(0, $qtyNeeded - $coveredQty);

        if ($remainingNeeded <= 0.0005) {
            break;
        }
    } else {
        break;
    }
}

$payload = [
    'ok' => true,
    'message' => count($lots) > 0 ? 'FIFO lot sequence found.' : 'No FIFO lot with remaining available balance was found.',
    'mode' => 'fifo_sequence',
    'qty_needed' => $qtyNeeded,
    'covered_qty' => $coveredQty,
    'remaining_qty' => $qtyNeeded > 0 ? max(0, $qtyNeeded - $coveredQty) : 0,
    'lots' => $lots
];

sap_cache_put($whp, 'sap.issuer.lot_suggestions', $cacheKey, $payload, 300);
json_out($payload);
?>
