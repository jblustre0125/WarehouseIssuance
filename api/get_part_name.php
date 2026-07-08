<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_once __DIR__ . '/../includes/item_locations.php';

function clean_lookup_value($value) {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
}

function table_exists($conn, $tableName) {
    return (bool)fetch_one($conn, "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?", [$tableName]);
}

function column_exists($conn, $tableName, $columnName) {
    return (bool)fetch_one($conn, "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?", [$tableName, $columnName]);
}

function lookup_sap_item($conn, $code) {
    $code = clean_lookup_value($code);
    if ($code === '') return null;

    // 1) Exact SAP ItemCode.
    $row = fetch_one($conn,
        "SELECT TOP 1 ItemCode, ItemName FROM OITM WHERE LTRIM(RTRIM(ItemCode)) = LTRIM(RTRIM(?))",
        [$code]
    );
    if ($row) return ['found'=>true, 'item_code'=>$row['ItemCode'], 'part_name'=>$row['ItemName'], 'match_by'=>'OITM.ItemCode'];

    // 2) OITM.CodeBars, if used.
    if (column_exists($conn, 'OITM', 'CodeBars')) {
        $row = fetch_one($conn,
            "SELECT TOP 1 ItemCode, ItemName FROM OITM WHERE LTRIM(RTRIM(CodeBars)) = LTRIM(RTRIM(?))",
            [$code]
        );
        if ($row) return ['found'=>true, 'item_code'=>$row['ItemCode'], 'part_name'=>$row['ItemName'], 'match_by'=>'OITM.CodeBars'];
    }

    // 3) Barcode master data, if used.
    if (table_exists($conn, 'OBCD')) {
        $row = fetch_one($conn,
            "SELECT TOP 1 I.ItemCode, I.ItemName
             FROM OBCD B
             INNER JOIN OITM I ON I.ItemCode = B.ItemCode
             WHERE LTRIM(RTRIM(B.BcdCode)) = LTRIM(RTRIM(?))",
            [$code]
        );
        if ($row) return ['found'=>true, 'item_code'=>$row['ItemCode'], 'part_name'=>$row['ItemName'], 'match_by'=>'OBCD.BcdCode'];
    }

    // 4) Your actual case: scanned value is part of the item description/name.
    // Example scan: 4F1640-0000-1000 means description code 4F1640-0000, qty 1000.
    // If SAP has:
    //   CONNECTOR 4F1640-0000
    //   CONNECTOR 4F1640-0000-Y
    //   CONNECTOR 4F1640-0000-P
    // prefer the exact base description code with no suffix.
    $like = '%' . str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $code) . '%';
    $stmt = sqlsrv_query($conn,
        "SELECT TOP 20 ItemCode, ItemName
         FROM OITM
         WHERE ItemName LIKE ?
         ORDER BY ItemCode",
        [$like]
    );
    if ($stmt !== false) {
        $matches = [];
        $exactBaseMatches = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $itemName = (string)$r['ItemName'];
            $m = ['item_code'=>$r['ItemCode'], 'part_name'=>$itemName];
            $matches[] = $m;

            $pos = stripos($itemName, $code);
            while ($pos !== false) {
                $before = $pos > 0 ? substr($itemName, $pos - 1, 1) : '';
                $afterPos = $pos + strlen($code);
                $after = $afterPos < strlen($itemName) ? substr($itemName, $afterPos, 1) : '';

                // Exact base means the scanned code is not followed by suffix characters.
                // In your case, 4F1640-0000 should match CONNECTOR 4F1640-0000,
                // but should NOT match CONNECTOR 4F1640-0000-Y or -P.
                $beforeOk = ($before === '' || preg_match('/[^A-Za-z0-9]/', $before));
                $afterOk = ($after === '' || preg_match('/[^A-Za-z0-9\-]/', $after));
                if ($beforeOk && $afterOk) {
                    $exactBaseMatches[] = $m;
                    break;
                }
                $pos = stripos($itemName, $code, $pos + 1);
            }
        }

        if (count($exactBaseMatches) === 1) {
            return ['found'=>true, 'item_code'=>$exactBaseMatches[0]['item_code'], 'part_name'=>$exactBaseMatches[0]['part_name'], 'match_by'=>'OITM.ItemName exact base description code'];
        }
        if (count($exactBaseMatches) > 1) {
            return ['found'=>false, 'ambiguous'=>true, 'matches'=>$exactBaseMatches, 'message'=>'More than one SAP item description has the exact base scanned code. Use SAP ItemCode/barcode, or make the description code unique.'];
        }
        if (count($matches) === 1) {
            return ['found'=>true, 'item_code'=>$matches[0]['item_code'], 'part_name'=>$matches[0]['part_name'], 'match_by'=>'OITM.ItemName contains scanned description code'];
        }
        if (count($matches) > 1) {
            return ['found'=>false, 'ambiguous'=>true, 'matches'=>$matches, 'message'=>'More than one SAP item description contains this scanned value, and no exact base description match was found. Use SAP ItemCode/barcode, or make the description code unique.'];
        }
    }

    return null;
}

$item_code = clean_lookup_value($_GET['item_code'] ?? '');
if ($item_code === '') {
    echo json_encode(['found'=>false,'part_name'=>'','item_code'=>'','message'=>'No item_code provided']);
    exit;
}

$whp = get_whpokayoke_connection();
$cacheKey = sap_cache_make_key('sap.item_lookup', [
    'item_code' => strtoupper($item_code),
    'location_lookup' => 'v1'
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($whp, $cacheKey);

    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }
}

$conn = get_erp_connection();
$item = lookup_sap_item($conn, $item_code);
if (!$item) {
    echo json_encode([
        'found' => false,
        'part_name' => '',
        'item_code' => $item_code,
        'message' => 'Not found in SAP B1 ItemCode, barcode fields, or ItemName/description. The scanned value may not exist in SAP master data.'
    ]);
    exit;
}

$item['ok'] = true;
$location = item_location_for_code($whp, $item['item_code'] ?? $item_code);
$item['parts_code'] = $location['parts_code'] ?? '';
$item['location_code'] = $location['location_code'] ?? '';
sap_cache_put($whp, 'sap.item_lookup', $cacheKey, $item, 86400);
echo json_encode($item);
?>
