<?php
require_once 'db_connect.php';
if (isset($_POST['batch_items'])) {
    $items = json_decode($_POST['batch_items'], true);
    if (!is_array($items) || count($items) === 0) {
        echo "<h3>No items to save.</h3>";
        exit;
    }
    $conn = get_whpokayoke_connection();
    $erp_conn = get_erp_connection();
    $success = 0;
    $fail = 0;
    foreach ($items as $item) {
        $item_code = isset($item['item_code']) ? trim($item['item_code']) : '';
        $lot_no = isset($item['lot_no']) ? trim($item['lot_no']) : '';
        $quantity = isset($item['quantity']) ? trim($item['quantity']) : '';
        $itr_number = isset($item['itr_number']) ? trim($item['itr_number']) : '';
        $scanned_by = isset($item['scanner_id']) ? trim($item['scanner_id']) : '';
        if (!$item_code) {
            $fail++;
            continue;
        }
        // Get PartName from SAP (ERP) database
        $part_name = null;
        $sql_part = "SELECT ItemName FROM OITM WHERE ItemCode = ?";
        $stmt_part = sqlsrv_query($erp_conn, $sql_part, [$item_code]);
        if ($stmt_part && ($row = sqlsrv_fetch_array($stmt_part, SQLSRV_FETCH_ASSOC))) {
            $part_name = $row['ItemName'];
        }
        if (!$part_name) {
            $fail++;
            continue;
        }
        $sql = "INSERT INTO ScannedTags (ItemCode, PartName, LotNo, Quantity, ITRNumber, ScannedBy) VALUES (?, ?, ?, ?, ?, ?)";
        $params = [$item_code, $part_name, $lot_no, $quantity, $itr_number, $scanned_by];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $fail++;
        } else {
            $success++;
        }
    }
    echo "<h3>Batch Save Complete</h3>";
    echo "<p>Success: $success, Failed: $fail</p>";
    echo "<a href='index.php'>Scan more</a> | <a href='view_tags.php'>View Scanned Tags</a>";
    exit;
}
