<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lot_balance_lib.php';

$items = json_decode((string)($_POST['batch_items'] ?? '[]'), true);

if (!is_array($items) || count($items) === 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'No items to validate.'
    ]);
    exit;
}

$erp = get_erp_connection();
$whp = get_whpokayoke_connection();

echo json_encode(issuer_validate_batch_lot_balances($erp, $whp, $items));
exit;
?>
