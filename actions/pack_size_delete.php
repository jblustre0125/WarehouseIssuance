<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';

itr_pack_sizes_require_maintainer();

$itemCode = itr_pack_normalize_item_code($_POST['item_code'] ?? '');

if ($itemCode === '') {
    app_error('SAP item code is required.', 400);
}

$conn = get_whpokayoke_connection();

if (!itr_pack_sizes_ensure_table($conn)) {
    app_error('Unable to prepare qty per pack table.');
}

$u = current_user();
$stmt = sqlsrv_query(
    $conn,
    "UPDATE dbo.RawMaterialQtyPerPack
     SET IsActive = 0,
         UpdatedAt = GETDATE(),
         UpdatedByUsername = ?
     WHERE ItemCode = ?",
    [$u['username'] ?? '', $itemCode]
);

if ($stmt === false) {
    app_error(sqlsrv_fail_message());
}

header('Location: ' . app_path('pages/warehouse/pack_sizes.php?deleted=1'));
exit;
?>
