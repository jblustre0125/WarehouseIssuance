<!doctype html>
<html>
<head>
    <title><?= h($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php navbar('transactions'); ?>
<div class="container-fluid p-3">
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="text-primary"><?= h($pageTitle) ?></h4>
            <div class="row my-3">
                <div class="col-md-3"><div class="p-3 bg-white rounded border"><div>Saved</div><div class="display-6 text-success"><?= count($saved) ?></div></div></div>
                <div class="col-md-3"><div class="p-3 bg-white rounded border"><div>Failed</div><div class="display-6 text-danger"><?= count($failed) ?></div></div></div>
                <div class="col-md-6 text-end align-self-center"><a class="btn btn-outline-primary" href="<?= h($backUrl) ?>">Scan More</a> <a class="btn btn-primary" href="pages/reports/view_transactions.php">View Transactions</a></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light"><tr><th>Part Number</th><th>Part Name</th><th>Qty</th><th>Lot/Location</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($saved as $s): ?>
                        <tr><td><?= h($s['item_code'] ?? '') ?></td><td><?= h($s['part_name'] ?? '') ?></td><td><?= h($s['quantity'] ?? '') ?></td><td><?= h(($s['lot_no'] ?? '') ?: ($s['location'] ?? '')) ?></td><td class="text-success">Saved</td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($failed as $f): $it = $f['item']; ?>
                        <tr><td><?= h($it['item_code'] ?? '') ?></td><td><?= h($it['part_name'] ?? '') ?></td><td><?= h($it['quantity'] ?? '') ?></td><td><?= h(($it['lot_no'] ?? '') ?: ($it['location'] ?? '')) ?></td><td class="text-danger"><?= h($f['reason']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
