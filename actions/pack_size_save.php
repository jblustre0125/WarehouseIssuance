<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/itr_pack_sizes.php';

itr_pack_sizes_require_maintainer();

$conn = get_whpokayoke_connection();

if (!itr_pack_sizes_ensure_table($conn)) {
    app_error('Unable to prepare qty per pack table.');
}

$u = current_user();
$originalItemCode = itr_pack_normalize_item_code($_POST['original_item_code'] ?? '');
$itemCode = itr_pack_normalize_item_code($_POST['item_code'] ?? '');
$partName = trim((string)($_POST['part_name'] ?? ''));
$qtyPerPack = (float)($_POST['qty_per_pack'] ?? 0);
$sourceName = trim((string)($_POST['source_name'] ?? ''));
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($itemCode === '') {
    app_error('SAP item code is required.', 400);
}

if ($qtyPerPack <= 0) {
    app_error('Qty/Pack must be greater than zero.', 400);
}

if ($sourceName === '') {
    $sourceName = 'Manual maintenance';
}

if ($originalItemCode !== '' && strcasecmp($originalItemCode, $itemCode) !== 0) {
    $deactivateStmt = sqlsrv_query(
        $conn,
        "UPDATE dbo.RawMaterialQtyPerPack
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
    "MERGE dbo.RawMaterialQtyPerPack AS T
     USING (
        SELECT ? AS ItemCode, ? AS PartName, ? AS QtyPerPack, ? AS SourceName, ? AS IsActive, ? AS UpdatedByUsername
     ) AS S
     ON T.ItemCode = S.ItemCode
     WHEN MATCHED THEN
        UPDATE SET
            PartName = S.PartName,
            QtyPerPack = S.QtyPerPack,
            SourceName = S.SourceName,
            IsActive = S.IsActive,
            UpdatedAt = GETDATE(),
            UpdatedByUsername = S.UpdatedByUsername
     WHEN NOT MATCHED THEN
        INSERT (ItemCode, PartName, QtyPerPack, SourceName, IsActive, UpdatedByUsername)
        VALUES (S.ItemCode, S.PartName, S.QtyPerPack, S.SourceName, S.IsActive, S.UpdatedByUsername);",
    [
        $itemCode,
        $partName,
        $qtyPerPack,
        $sourceName,
        $isActive,
        $u['username'] ?? ''
    ]
);

if ($stmt === false) {
    app_error(sqlsrv_fail_message());
}

header('Location: ' . app_path('pages/warehouse/pack_sizes.php?saved=1&q=' . urlencode($itemCode)));
exit;
?>
