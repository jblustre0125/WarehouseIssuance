<?php
require_once 'db_connect.php';

$conn = get_whpokayoke_connection();
$sql = "SELECT ITEM_ID, ItemCode, PartName, LotNo, Quantity, ITRNumber, ScannedBy, ScannedAt FROM ScannedTags ORDER BY ScannedAt DESC";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die("Error fetching scanned tags: " . print_r(sqlsrv_errors(), true));
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}
$count = count($rows);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scanned Tags</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
            position: relative;
            z-index: 2;
            background-color: #fff;
        }

        .table-responsive {
            max-height: 60vh;
            overflow: auto;
        }

        .badge-count {
            font-weight: 600;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="image/nbc-bg-dashboard.jpg" alt="NBC logo" class="navbar-logo me-2">
                <span class="mb-0">NBC (Philippines) Car Technology Corporation</span>
            </a>
            <div class="d-flex align-items-center">
                <span class="badge bg-light text-dark me-2">Total Records: <span class="badge-count"><?php echo $count; ?></span></span>
                <a class="btn btn-sm btn-outline-light" href="index.php">Back to Scan</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Scanned Tags</h5>
                <div class="d-flex gap-2 align-items-center">
                    <input id="search" class="form-control form-control-sm" style="width:220px" placeholder="Search (Item, Part, Lot, ITR)...">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-hover mb-0" id="tagsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Part Name</th>
                                <th>Lot No</th>
                                <th>Quantity</th>
                                <th>ITR Number</th>
                                <th>Scanned By</th>
                                <th>Scanned At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['ItemCode']) ?></td>
                                    <td><?= htmlspecialchars($row['PartName']) ?></td>
                                    <td><?= htmlspecialchars($row['LotNo']) ?></td>
                                    <td><?= htmlspecialchars($row['Quantity']) ?></td>
                                    <td><?= htmlspecialchars($row['ITRNumber']) ?></td>
                                    <td><?= htmlspecialchars($row['ScannedBy']) ?></td>
                                    <td><?= $row['ScannedAt'] ? $row['ScannedAt']->format('Y-m-d H:i:s') : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // simple client-side filter across all cells
        document.getElementById('search').addEventListener('input', function(e) {
            const val = e.target.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#tagsTable tbody tr');
            rows.forEach(r => {
                const text = Array.from(r.cells).map(c => c.textContent.toLowerCase()).join(' ');
                r.style.display = text.includes(val) ? '' : 'none';
            });
        });
    </script>
</body>

</html>