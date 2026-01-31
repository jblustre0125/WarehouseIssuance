<?php
require_once 'db_connect.php';
if (!isset($_POST['batch_items'])) {
    header('Location: index.php');
    exit;
}

$items = json_decode($_POST['batch_items'], true);
if (!is_array($items) || count($items) === 0) {
    $pageTitle = 'No items to save';
    $savedItems = [];
    $failedItems = [];
} else {
    $conn = get_whpokayoke_connection();
    $erp_conn = get_erp_connection();
    $success = 0;
    $fail = 0;
    $savedItems = [];
    $failedItems = [];
    foreach ($items as $item) {
        $item_code = isset($item['item_code']) ? trim($item['item_code']) : '';
        $lot_no = isset($item['lot_no']) ? trim($item['lot_no']) : '';
        $quantity = isset($item['quantity']) ? trim($item['quantity']) : '';
        $itr_number = isset($item['itr_number']) ? trim($item['itr_number']) : '';
        $scanned_by = isset($item['scanner_id']) ? trim($item['scanner_id']) : '';
        if (!$item_code) {
            $fail++;
            $failedItems[] = ['item' => $item, 'reason' => 'Missing item code'];
            continue;
        }
        // Get PartName from SAP (ERP) database
        $part_name = null;
        $sql_part = "SELECT ItemName FROM OITM WHERE ItemCode = ?";
        $stmt_part = @sqlsrv_query($erp_conn, $sql_part, [$item_code]);
        if ($stmt_part && ($row = sqlsrv_fetch_array($stmt_part, SQLSRV_FETCH_ASSOC))) {
            $part_name = $row['ItemName'];
        }
        if (!$part_name) {
            $fail++;
            $failedItems[] = ['item' => $item, 'reason' => 'Part not found in ERP'];
            continue;
        }
        $sql = "INSERT INTO ScannedTags (ItemCode, PartName, LotNo, Quantity, ITRNumber, ScannedBy) VALUES (?, ?, ?, ?, ?, ?)";
        $params = [$item_code, $part_name, $lot_no, $quantity, $itr_number, $scanned_by];
        $stmt = @sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $fail++;
            $err = sqlsrv_errors();
            $errMsg = is_array($err) ? json_encode($err) : 'DB insert failed';
            $failedItems[] = ['item' => $item, 'reason' => $errMsg];
        } else {
            $success++;
            $savedItems[] = ['item' => $item, 'part_name' => $part_name];
        }
    }
    $pageTitle = 'Batch Save Complete';
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        body {
            background: #f5f7fb;
        }

        .navbar .navbar-logo {
            max-height: 28px;
            width: auto;
            border-radius: 6px;
            object-fit: cover;
            display: inline-block
        }

        .card {
            margin-top: 1.5rem;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(16, 24, 40, 0.06);
        }

        .small-note {
            font-size: 0.9rem;
            color: #6b7280
        }

        .table-responsive {
            max-height: 60vh;
            overflow: auto;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="image/nbc-bg-dashboard.jpg" alt="NBC logo" class="navbar-logo me-2">
                <span class="mb-0">NBC (Philippines) Car Technology Corporation</span>
            </a>
            <div class="d-flex align-items-center">
                <div class="small-note me-3 text-light">Warehouse Issuance</div>
                <a class="btn btn-sm btn-outline-light" href="index.php">Scan More</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card p-3">
            <div class="card-body">
                <h4 class="text-primary"><?php echo htmlspecialchars($pageTitle); ?></h4>
                <div class="row mt-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="fw-semibold">Saved</div>
                            <div class="display-6 text-success"><?php echo isset($success) ? (int)$success : 0; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="fw-semibold">Failed</div>
                            <div class="display-6 text-danger"><?php echo isset($fail) ? (int)$fail : 0; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <a class="btn btn-outline-primary" href="view_tags.php">View Scanned Tags</a>
                    </div>
                </div>

                <div class="table-responsive mt-4">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Part Name</th>
                                <th>Qty</th>
                                <th>Lot</th>
                                <th>ITR</th>
                                <th>Scanner</th>
                                <th>Status / Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($savedItems as $s) {
                                $it = $s['item'];
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($it['item_code'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($s['part_name'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['quantity'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['lot_no'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['itr_number'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['scanner_id'] ?? '') . '</td>';
                                echo '<td class="text-success">Saved</td>';
                                echo '</tr>';
                            }
                            foreach ($failedItems as $f) {
                                $it = $f['item'];
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($it['item_code'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['part_name'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['quantity'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['lot_no'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['itr_number'] ?? '') . '</td>';
                                echo '<td>' . htmlspecialchars($it['scanner_id'] ?? '') . '</td>';
                                echo '<td class="text-danger">' . htmlspecialchars($f['reason']) . '</td>';
                                echo '</tr>';
                            }
                            if (empty($savedItems) && empty($failedItems)) {
                                echo '<tr><td colspan="7" class="text-center">No items to display.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>