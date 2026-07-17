<?php

function issuer_lot_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_NAME = ?",
        [$table]
    );
}

function issuer_lot_has_column($conn, $table, $column)
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

function issuer_lot_num($value)
{
    return (float)str_replace(',', '', (string)($value ?? 0));
}

function issuer_app_issued_qty_for_lot($whp, $itemCode, $lotNo)
{
    $itemCode = trim((string)$itemCode);
    $lotNo = trim((string)$lotNo);

    if ($itemCode === '' || $lotNo === '') {
        return 0.0;
    }

    if (
        issuer_lot_has_table($whp, 'IssuanceTransactions') &&
        issuer_lot_has_column($whp, 'IssuanceTransactions', 'ItemCode') &&
        issuer_lot_has_column($whp, 'IssuanceTransactions', 'LotNo') &&
        issuer_lot_has_column($whp, 'IssuanceTransactions', 'Quantity')
    ) {
        $issuedRow = fetch_one(
            $whp,
            "SELECT ISNULL(SUM(ISNULL(Quantity, 0)), 0) AS IssuedQty
             FROM IssuanceTransactions
             WHERE ItemCode = ?
               AND LotNo = ?",
            [$itemCode, $lotNo]
        );

        return $issuedRow ? issuer_lot_num($issuedRow['IssuedQty'] ?? 0) : 0.0;
    }

    if (
        issuer_lot_has_table($whp, 'RawmatTraceLines') &&
        issuer_lot_has_column($whp, 'RawmatTraceLines', 'ItemCode') &&
        issuer_lot_has_column($whp, 'RawmatTraceLines', 'LotNo') &&
        issuer_lot_has_column($whp, 'RawmatTraceLines', 'IssuedQty')
    ) {
        $statusFilter = issuer_lot_has_column($whp, 'RawmatTraceLines', 'VerificationStatus')
            ? "AND ISNULL(VerificationStatus, '') NOT IN ('CANCELLED', 'CANCELED', 'VOID')"
            : '';

        $issuedRow = fetch_one(
            $whp,
            "SELECT ISNULL(SUM(ISNULL(IssuedQty, 0)), 0) AS IssuedQty
             FROM RawmatTraceLines
             WHERE ItemCode = ?
               AND LotNo = ?
               {$statusFilter}",
            [$itemCode, $lotNo]
        );

        return $issuedRow ? issuer_lot_num($issuedRow['IssuedQty'] ?? 0) : 0.0;
    }

    if (
        !issuer_lot_has_table($whp, 'WarehouseIssueRequestLines') ||
        !issuer_lot_has_column($whp, 'WarehouseIssueRequestLines', 'ItemCode') ||
        !issuer_lot_has_column($whp, 'WarehouseIssueRequestLines', 'LotNo') ||
        !issuer_lot_has_column($whp, 'WarehouseIssueRequestLines', 'IssuedQty')
    ) {
        return 0.0;
    }

    $statusFilter = issuer_lot_has_column($whp, 'WarehouseIssueRequestLines', 'Status')
        ? "AND ISNULL(Status, '') NOT IN ('CANCELLED', 'CANCELED', 'VOID')"
        : '';

    $issuedRow = fetch_one(
        $whp,
        "SELECT ISNULL(SUM(ISNULL(IssuedQty, 0)), 0) AS IssuedQty
         FROM WarehouseIssueRequestLines
         WHERE ItemCode = ?
           AND LotNo = ?
           {$statusFilter}",
        [$itemCode, $lotNo]
    );

    return $issuedRow ? issuer_lot_num($issuedRow['IssuedQty'] ?? 0) : 0.0;
}

function issuer_lot_balance($erp, $whp, $itemCode, $lotNo, $warehouseCode = '01')
{
    $itemCode = trim((string)$itemCode);
    $lotNo = trim((string)$lotNo);
    $warehouseCode = trim((string)$warehouseCode) !== '' ? trim((string)$warehouseCode) : '01';

    if ($itemCode === '' || $lotNo === '') {
        return [
            'ok' => false,
            'valid' => false,
            'message' => 'Item code and lot number are required.',
            'item_code' => $itemCode,
            'lot_no' => $lotNo,
            'warehouse_code' => $warehouseCode,
            'received_qty' => 0,
            'on_hand_qty' => 0,
            'committed_qty' => 0,
            'sap_available_qty' => 0,
            'issued_qty' => 0,
            'available_qty' => 0,
            'source' => 'none'
        ];
    }

    $sapOnHandQty = 0.0;
    $sapCommittedQty = 0.0;
    $sapAvailableQty = 0.0;
    $source = 'none';

    if (
        issuer_lot_has_table($erp, 'OBTQ') &&
        issuer_lot_has_table($erp, 'OBTN') &&
        issuer_lot_has_column($erp, 'OBTQ', 'ItemCode') &&
        issuer_lot_has_column($erp, 'OBTQ', 'SysNumber') &&
        issuer_lot_has_column($erp, 'OBTQ', 'WhsCode') &&
        issuer_lot_has_column($erp, 'OBTQ', 'Quantity') &&
        issuer_lot_has_column($erp, 'OBTN', 'ItemCode') &&
        issuer_lot_has_column($erp, 'OBTN', 'SysNumber') &&
        issuer_lot_has_column($erp, 'OBTN', 'DistNumber')
    ) {
        $hasCommitQty = issuer_lot_has_column($erp, 'OBTQ', 'CommitQty');
        $commitExpr = $hasCommitQty ? 'ISNULL(Q.CommitQty, 0)' : '0';

        $row = fetch_one(
            $erp,
            "SELECT
                 ISNULL(SUM(ISNULL(Q.Quantity, 0)), 0) AS OnHandQty,
                 ISNULL(SUM({$commitExpr}), 0) AS CommittedQty
             FROM OBTQ Q
             INNER JOIN OBTN B
                ON B.ItemCode = Q.ItemCode
               AND B.SysNumber = Q.SysNumber
             WHERE Q.ItemCode = ?
               AND B.DistNumber = ?
               AND Q.WhsCode = ?",
            [$itemCode, $lotNo, $warehouseCode]
        );

        if ($row) {
            $sapOnHandQty = issuer_lot_num($row['OnHandQty'] ?? 0);
            $sapCommittedQty = issuer_lot_num($row['CommittedQty'] ?? 0);
            $sapAvailableQty = max(0, $sapOnHandQty - $sapCommittedQty);
            $source = 'SAP OBTQ/OBTN';
        }
    }

    $appIssuedQty = issuer_app_issued_qty_for_lot($whp, $itemCode, $lotNo);
    $systemAvailableQty = $sapAvailableQty;
    $valid = $systemAvailableQty > 0;

    return [
        'ok' => true,
        'valid' => $valid,
        'item_code' => $itemCode,
        'lot_no' => $lotNo,
        'warehouse_code' => $warehouseCode,
        'received_qty' => $sapOnHandQty,
        'on_hand_qty' => $sapOnHandQty,
        'committed_qty' => $sapCommittedQty,
        'sap_available_qty' => $sapAvailableQty,
        'issued_qty' => $appIssuedQty,
        'available_qty' => $systemAvailableQty,
        'source' => $source,
        'message' => $valid
            ? 'Lot is available. SAP batch available: ' . $sapAvailableQty . '.'
            : 'Lot has no SAP batch balance available.'
    ];
}

function issuer_validate_batch_lot_balances($erp, $whp, array $items)
{
    $balances = [];
    $pending = [];
    $checked = [];

    foreach ($items as $idx => $item) {
        $itemCode = trim((string)($item['item_code'] ?? ''));
        $lotNo = trim((string)($item['lot_no'] ?? ''));
        $qty = issuer_lot_num($item['quantity'] ?? 0);
        $warehouseCode = trim((string)($item['stock_whs_code'] ?? '01'));
        $warehouseCode = $warehouseCode !== '' ? $warehouseCode : '01';

        if ($itemCode === '' || $lotNo === '' || $qty <= 0) {
            return [
                'ok' => false,
                'message' => 'Line ' . ($idx + 1) . ' is missing item, lot, or valid quantity.',
                'line' => $idx + 1
            ];
        }

        $key = strtoupper($itemCode) . '|' . strtoupper($lotNo) . '|' . strtoupper($warehouseCode);

        if (!isset($balances[$key])) {
            $balances[$key] = issuer_lot_balance($erp, $whp, $itemCode, $lotNo, $warehouseCode);
        }

        $balance = $balances[$key];

        if (!$balance['ok'] || !$balance['valid']) {
            return [
                'ok' => false,
                'message' => 'Line ' . ($idx + 1) . ': ' . ($balance['message'] ?? 'Lot is not available.'),
                'line' => $idx + 1,
                'balance' => $balance
            ];
        }

        $pending[$key] = ($pending[$key] ?? 0.0) + $qty;
        $availableQty = issuer_lot_num($balance['available_qty'] ?? 0);

        if ($pending[$key] > $availableQty) {
            return [
                'ok' => false,
                'message' => 'Line ' . ($idx + 1) . ': Lot ' . $lotNo . ' only has ' . $availableQty . ' available in warehouse ' . $warehouseCode . '. Pending issue qty is ' . $pending[$key] . '.',
                'line' => $idx + 1,
                'balance' => $balance,
                'pending_qty' => $pending[$key]
            ];
        }

        $checked[] = [
            'line' => $idx + 1,
            'item_code' => $itemCode,
            'lot_no' => $lotNo,
            'warehouse_code' => $warehouseCode,
            'qty' => $qty,
            'available_qty' => $availableQty,
            'pending_qty' => $pending[$key]
        ];
    }

    return [
        'ok' => true,
        'message' => 'Lot balances validated.',
        'checked' => $checked
    ];
}
