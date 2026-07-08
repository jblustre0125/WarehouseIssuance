<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/item_locations.php';

item_locations_require_maintainer();

$itemCode = trim((string)($_POST['item_code'] ?? ''));

if ($itemCode === '') {
    app_error('SAP item code is required.', 400);
}

$conn = get_whpokayoke_connection();

if (!item_locations_ensure_table($conn)) {
    app_error('Unable to prepare item location table.');
}

$u = current_user();
$stmt = sqlsrv_query(
    $conn,
    "UPDATE dbo.RawMaterialItemLocations
     SET IsActive = 0,
         UpdatedAt = GETDATE(),
         UpdatedByUsername = ?
     WHERE ItemCode = ?",
    [$u['username'] ?? '', $itemCode]
);

if ($stmt === false) {
    app_error(sqlsrv_fail_message());
}

header('Location: ' . app_path('pages/warehouse/item_locations.php?deleted=1'));
exit;
?>
