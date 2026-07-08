<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/item_locations.php';

item_locations_require_maintainer();

$conn = get_whpokayoke_connection();

if (!item_locations_ensure_table($conn)) {
    app_error('Unable to prepare item location table.');
}

$u = current_user();
$originalItemCode = trim((string)($_POST['original_item_code'] ?? ''));
$itemCode = trim((string)($_POST['item_code'] ?? ''));
$partsCode = trim((string)($_POST['parts_code'] ?? ''));
$itemName = trim((string)($_POST['item_name'] ?? ''));
$locationCode = trim((string)($_POST['location_code'] ?? ''));
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($itemCode === '') {
    app_error('SAP item code is required.', 400);
}

if ($locationCode === '') {
    app_error('Location is required.', 400);
}

if ($originalItemCode !== '' && strcasecmp($originalItemCode, $itemCode) !== 0) {
    $deactivateStmt = sqlsrv_query(
        $conn,
        "UPDATE dbo.RawMaterialItemLocations
         SET IsActive = 0,
             UpdatedAt = GETDATE(),
             UpdatedByUsername = ?
         WHERE ItemCode = ?",
        [$u['username'] ?? '', $originalItemCode]
    );

    if ($deactivateStmt === false) {
        app_error(sqlsrv_fail_message());
    }
}

$stmt = sqlsrv_query(
    $conn,
    "MERGE dbo.RawMaterialItemLocations AS T
     USING (
        SELECT ? AS ItemCode, ? AS PartsCode, ? AS ItemName, ? AS LocationCode, ? AS IsActive, ? AS UpdatedByUsername
     ) AS S
     ON T.ItemCode = S.ItemCode
     WHEN MATCHED THEN
        UPDATE SET
            PartsCode = S.PartsCode,
            ItemName = S.ItemName,
            LocationCode = S.LocationCode,
            IsActive = S.IsActive,
            UpdatedAt = GETDATE(),
            UpdatedByUsername = S.UpdatedByUsername
     WHEN NOT MATCHED THEN
        INSERT (ItemCode, PartsCode, ItemName, LocationCode, IsActive, UpdatedByUsername)
        VALUES (S.ItemCode, S.PartsCode, S.ItemName, S.LocationCode, S.IsActive, S.UpdatedByUsername);",
    [
        $itemCode,
        $partsCode,
        $itemName,
        $locationCode,
        $isActive,
        $u['username'] ?? ''
    ]
);

if ($stmt === false) {
    app_error(sqlsrv_fail_message());
}

header('Location: ' . app_path('pages/warehouse/item_locations.php?saved=1&q=' . urlencode($itemCode)));
exit;
?>
