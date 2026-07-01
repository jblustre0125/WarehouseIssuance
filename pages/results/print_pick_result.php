<?php
$currentUser = function_exists('current_user') ? current_user() : [];
$currentRole = strtolower($currentUser['role'] ?? '');

if (!function_exists('pick_result_h')) {
    function pick_result_h($value)
    {
        return function_exists('h')
            ? h($value)
            : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$saved = isset($saved) && is_array($saved) ? $saved : [];
$failed = isset($failed) && is_array($failed) ? $failed : [];
$pageTitle = $pageTitle ?? 'Pick Tags Ready';
$backUrl = $backUrl ?? 'pages/picker/picker.php';
$printEnabled = isset($zebraPrintResult) && (bool)($zebraPrintResult['enabled'] ?? false);
$printOk = isset($zebraPrintResult) && (bool)($zebraPrintResult['ok'] ?? false);
$printQueued = isset($zebraPrintResult) && (bool)($zebraPrintResult['queued'] ?? false);
$printQueuedCount = isset($zebraPrintResult) ? (int)($zebraPrintResult['queued_count'] ?? 0) : 0;
$printed = isset($zebraPrintResult) ? (int)($zebraPrintResult['printed'] ?? 0) : 0;
$printFailed = isset($zebraPrintResult) ? (int)($zebraPrintResult['failed'] ?? 0) : 0;
$printPrinterName = isset($zebraPrintResult) ? trim((string)($zebraPrintResult['printer_name'] ?? 'printer')) : 'printer';
$printBytesSent = isset($zebraPrintResult) ? (int)($zebraPrintResult['bytes_sent'] ?? 0) : 0;
$printMessages = isset($zebraPrintResult) && is_array($zebraPrintResult['messages'] ?? null)
    ? $zebraPrintResult['messages']
    : [];

$qrPayloads = [];

foreach ($saved as $idx => $s) {
    if (!empty($s['qr_payload'])) {
        $qrPayloads[] = [
            'idx' => (int)$idx,
            'payload' => (string)$s['qr_payload']
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <title><?= pick_result_h($pageTitle) ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= pick_result_h(app_path('')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f7fb; font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wrap { max-width:1180px; margin:0 auto; padding:18px; }
        .panel { background:#fff; border:1px solid #e5eaf2; border-radius:16px; box-shadow:0 12px 35px rgba(15,23,42,.06); overflow:hidden; }
        .panel-head { padding:16px 18px; border-bottom:1px solid #e5eaf2; display:flex; justify-content:space-between; gap:12px; align-items:start; }
        .panel-body { padding:18px; }
        .title { font-weight:800; color:#1f2937; margin:0; }
        .sub { color:#6b7280; font-size:14px; margin-top:3px; }
        .qr-box { width:104px; height:104px; margin:0 auto; }
        .payload { font-size:11px; word-break:break-all; color:#4b5563; max-width:210px; }
        .print-status { border-radius:14px; padding:12px; border:1px solid #e5eaf2; background:#f8fafc; }
        .print-status.success { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
        .print-status.warning { background:#fffbeb; border-color:#fde68a; color:#92400e; }
        .table { font-size:13px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <div class="panel-head">
            <div>
                <h4 class="title"><?= pick_result_h($pageTitle) ?></h4>
                <div class="sub">Picker tags for issuer scanning. Barcode is PO/ITR; QR is item, qty, and lot.</div>
            </div>
            <a class="btn btn-outline-primary btn-sm" href="<?= pick_result_h($backUrl) ?>">Back to Picker</a>
        </div>
        <div class="panel-body">
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="p-3 bg-light rounded-3"><div class="fw-bold">Ready</div><div class="fs-2 text-success"><?= number_format(count($saved)) ?></div></div></div>
                <div class="col-md-4"><div class="p-3 bg-light rounded-3"><div class="fw-bold">Failed</div><div class="fs-2 text-danger"><?= number_format(count($failed)) ?></div></div></div>
                <div class="col-md-4">
                    <div class="print-status <?= $printEnabled ? ($printOk ? 'success' : 'warning') : '' ?>">
                        <div class="fw-bold"><?= $printEnabled ? ($printQueued ? number_format($printQueuedCount) . ' tag(s) queued for ' . pick_result_h($printPrinterName) . '.' : ($printOk ? 'Labels sent to ' . pick_result_h($printPrinterName) . '.' : 'Some labels failed to print on ' . pick_result_h($printPrinterName) . '.')) : 'Auto-print is disabled.' ?></div>
                        <div class="small"><?= $printQueued ? 'The Windows print task will process this job shortly.' : (number_format($printed) . ' printed, ' . number_format($printFailed) . ' failed' . ($printBytesSent > 0 ? ', ' . number_format($printBytesSent) . ' bytes sent' : '') . '.') ?></div>
                        <?php if (!empty($printMessages)): ?><div class="small mt-2"><?= pick_result_h(implode(' ', $printMessages)) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:130px">QR</th>
                            <th>Item</th>
                            <th>Part Name</th>
                            <th>Qty</th>
                            <th>Lot</th>
                            <th>Reference</th>
                            <th>Payload</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($saved as $idx => $s): ?>
                            <tr>
                                <td class="text-center"><div class="qr-box" id="pick_qr_<?= (int)$idx ?>"></div></td>
                                <td><?= pick_result_h($s['item_code'] ?? '') ?></td>
                                <td><?= pick_result_h($s['part_name'] ?? '') ?></td>
                                <td><?= pick_result_h($s['quantity'] ?? '') ?></td>
                                <td><?= pick_result_h($s['lot_no'] ?? '') ?></td>
                                <td><?= pick_result_h($s['request_no'] ?? '') ?></td>
                                <td><div class="payload"><?= pick_result_h($s['qr_payload'] ?? '') ?></div></td>
                                <td class="text-success fw-bold">Ready</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($failed as $f): $it = $f['item'] ?? []; ?>
                            <tr>
                                <td></td>
                                <td><?= pick_result_h($it['item_code'] ?? '') ?></td>
                                <td><?= pick_result_h($it['part_name'] ?? '') ?></td>
                                <td><?= pick_result_h($it['quantity'] ?? '') ?></td>
                                <td><?= pick_result_h($it['lot_no'] ?? '') ?></td>
                                <td><?= pick_result_h($it['request_no'] ?? '') ?></td>
                                <td></td>
                                <td class="text-danger fw-bold"><?= pick_result_h($f['reason'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
const qrPayloads = <?= json_encode($qrPayloads, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
qrPayloads.forEach(function (payload) {
    const el = document.getElementById('pick_qr_' + payload.idx);
    if (el) {
        new QRCode(el, { text: payload.payload, width: 104, height: 104 });
    }
});
</script>
</body>
</html>
