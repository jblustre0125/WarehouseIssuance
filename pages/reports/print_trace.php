<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
$conn = get_whpokayoke_connection();
$traceNo = trim($_GET['trace_no'] ?? '');
$h = fetch_one($conn, 'SELECT * FROM RawmatTraceHeader WHERE TraceNo = ?', [$traceNo]);
if (!$h) app_error('Trace not found.', 404);
$lines = fetch_all($conn, 'SELECT * FROM RawmatTraceLines WHERE TraceID = ? ORDER BY TraceLineID', [$h['TraceID']]);
$receiveUrl = app_url('pages/receiver/receiver.php?trace_no=' . urlencode($traceNo));
?>
<!doctype html><html><head><title>Print Trace QR</title><meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>"><link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"><style>@media print{.no-print{display:none}.card{border:0!important;box-shadow:none!important}} body{background:#fff}</style></head>
<body><div class="container p-3"><div class="no-print mb-3"><button class="btn btn-primary" onclick="window.print()">Print</button> <a class="btn btn-outline-secondary" href="pages/issuer/issuer.php">Back</a></div><div class="card border border-dark"><div class="card-body"><div class="row"><div class="col-8"><h3>NBC Rawmat Traceability</h3><h4>Trace No: <?= h($traceNo) ?></h4><div>ITR / IT Reference: <?= h($h['ITRNumber']) ?></div><div>Issued By: <?= h($h['CreatedByUsername']) ?></div><div>Issued At: <?= $h['CreatedAt'] ? h($h['CreatedAt']->format('Y-m-d H:i:s')) : '' ?></div></div><div class="col-4 text-center"><div id="qrcode" class="d-inline-block"></div><div class="small mt-2"><?= h($receiveUrl) ?></div></div></div><hr><table class="table table-sm table-bordered"><thead><tr><th>Item</th><th>Part Name</th><th>Lot</th><th>Qty</th></tr></thead><tbody><?php foreach($lines as $l): ?><tr><td><?= h($l['ItemCode']) ?></td><td><?= h($l['PartName']) ?></td><td><?= h($l['LotNo']) ?></td><td><?= h($l['IssuedQty']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><script src="assets/vendor/qrcodejs/qrcode.min.js"></script><script>new QRCode(document.getElementById('qrcode'), {text: <?= json_encode($receiveUrl) ?>, width: 180, height: 180});</script></body></html>
