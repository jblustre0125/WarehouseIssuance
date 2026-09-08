<?php

function sap_item_batch_required_message($itemCode)
{
    $itemCode = trim((string)$itemCode);
    $prefix = $itemCode !== '' ? 'Item ' . $itemCode . ' ' : 'This item ';

    return $prefix . 'must be managed by batches in SAP before it can be requested. Please contact your SAP admin.';
}

function sap_item_batch_status($erp, $itemCode)
{
    $itemCode = trim((string)$itemCode);
    static $cache = [];

    if ($itemCode === '') {
        return [
            'found' => false,
            'managed' => false,
            'man_btch_num' => '',
            'message' => sap_item_batch_required_message($itemCode)
        ];
    }

    $cacheKey = strtoupper($itemCode);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $row = fetch_one(
        $erp,
        "SELECT TOP 1 ItemCode, ItemName, ManBtchNum
         FROM OITM
         WHERE LTRIM(RTRIM(ItemCode)) = LTRIM(RTRIM(?))",
        [$itemCode]
    );

    if (!$row) {
        $cache[$cacheKey] = [
            'found' => false,
            'managed' => false,
            'man_btch_num' => '',
            'message' => 'Item ' . $itemCode . ' was not found in SAP.'
        ];

        return $cache[$cacheKey];
    }

    $managed = strtoupper(trim((string)($row['ManBtchNum'] ?? ''))) === 'Y';

    $cache[$cacheKey] = [
        'found' => true,
        'managed' => $managed,
        'item_code' => (string)($row['ItemCode'] ?? $itemCode),
        'item_name' => (string)($row['ItemName'] ?? ''),
        'man_btch_num' => (string)($row['ManBtchNum'] ?? ''),
        'message' => $managed ? '' : sap_item_batch_required_message($row['ItemCode'] ?? $itemCode)
    ];

    return $cache[$cacheKey];
}

function sap_item_batch_statuses($erp, array $itemCodes)
{
    $cleanCodes = [];

    foreach ($itemCodes as $itemCode) {
        $itemCode = trim((string)$itemCode);

        if ($itemCode !== '') {
            $cleanCodes[$itemCode] = true;
        }
    }

    if (count($cleanCodes) === 0) {
        return [];
    }

    $codes = array_keys($cleanCodes);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $rows = fetch_all(
        $erp,
        "SELECT ItemCode, ItemName, ManBtchNum
         FROM OITM
         WHERE ItemCode IN ({$placeholders})",
        $codes
    );

    $statuses = [];

    foreach ($rows as $row) {
        $itemCode = trim((string)($row['ItemCode'] ?? ''));

        if ($itemCode === '') {
            continue;
        }

        $managed = strtoupper(trim((string)($row['ManBtchNum'] ?? ''))) === 'Y';
        $statuses[$itemCode] = [
            'found' => true,
            'managed' => $managed,
            'item_code' => $itemCode,
            'item_name' => (string)($row['ItemName'] ?? ''),
            'man_btch_num' => (string)($row['ManBtchNum'] ?? ''),
            'message' => $managed ? '' : sap_item_batch_required_message($itemCode)
        ];
    }

    foreach ($codes as $itemCode) {
        if (!isset($statuses[$itemCode])) {
            $statuses[$itemCode] = [
                'found' => false,
                'managed' => false,
                'item_code' => $itemCode,
                'item_name' => '',
                'man_btch_num' => '',
                'message' => 'Item ' . $itemCode . ' was not found in SAP.'
            ];
        }
    }

    return $statuses;
}
?>
