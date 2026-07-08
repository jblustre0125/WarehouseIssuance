<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lot_balance_lib.php';

$itemCode = trim((string)($_GET['item_code'] ?? $_POST['item_code'] ?? ''));
$lotNo = trim((string)($_GET['lot_no'] ?? $_POST['lot_no'] ?? ''));
$warehouseCode = trim((string)($_GET['warehouse_code'] ?? $_POST['warehouse_code'] ?? '01'));
$warehouseCode = $warehouseCode !== '' ? $warehouseCode : '01';

$erp = get_erp_connection();
$whp = get_whpokayoke_connection();

/*
    No cache here. Lot balance must be live because printing/saving immediately
    changes Warehouse Issuance IssuedQty, and cached balance can suggest an already consumed lot.
*/
echo json_encode(issuer_lot_balance($erp, $whp, $itemCode, $lotNo, $warehouseCode));
exit;
?>
