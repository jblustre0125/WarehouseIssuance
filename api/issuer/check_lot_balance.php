<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lot_balance_lib.php';

$itemCode = trim((string)($_GET['item_code'] ?? $_POST['item_code'] ?? ''));
$lotNo = trim((string)($_GET['lot_no'] ?? $_POST['lot_no'] ?? ''));
$warehouseCode = trim((string)($_GET['warehouse_code'] ?? $_POST['warehouse_code'] ?? '01'));
$warehouseCode = $warehouseCode !== '' ? $warehouseCode : '01';

$whp = get_whpokayoke_connection();
$cacheKey = sap_cache_make_key('sap.issuer.lot_balance', [
    'item_code' => strtoupper($itemCode),
    'lot_no' => strtoupper($lotNo),
    'warehouse_code' => strtoupper($warehouseCode)
]);

if (!sap_cache_should_refresh()) {
    $cached = sap_cache_get($whp, $cacheKey);

    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }
}

$erp = get_erp_connection();

$payload = issuer_lot_balance($erp, $whp, $itemCode, $lotNo, $warehouseCode);

if ($payload['ok'] ?? false) {
    sap_cache_put($whp, 'sap.issuer.lot_balance', $cacheKey, $payload, 60);
}

echo json_encode($payload);
exit;
?>
