<?php
require_once __DIR__ . '/../../includes/app_shell.php';

$currentUser = function_exists('current_user') ? current_user() : [];
$currentRole = strtolower($currentUser['role'] ?? '');

if (!function_exists('result_h')) {
    function result_h($value)
    {
        if (function_exists('h')) {
            return h($value);
        }

        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('result_qty')) {
    function result_qty($value)
    {
        if ($value === '' || $value === null) {
            return '';
        }

        $n = (float)$value;

        if (floor($n) == $n) {
            return number_format($n, 0);
        }

        return rtrim(rtrim(number_format($n, 3), '0'), '.');
    }
}

if (!function_exists('result_app_url')) {
    function result_app_url($path)
    {
        if (function_exists('app_url')) {
            return app_url($path);
        }

        return $path;
    }
}

$pageTitle = isset($pageTitle) ? $pageTitle : 'Issuance Saved';
$backUrl = isset($backUrl) ? $backUrl : 'pages/issuer/issuer.php';

$saved = isset($saved) && is_array($saved) ? $saved : [];
$failed = isset($failed) && is_array($failed) ? $failed : [];

$savedCount = count($saved);
$failedCount = count($failed);

$totalQty = 0;

foreach ($saved as $s) {
    $totalQty += (float)($s['quantity'] ?? 0);
}

$printEnabled = isset($zebraPrintResult) && (bool)($zebraPrintResult['enabled'] ?? false);
$printOk = isset($zebraPrintResult) && (bool)($zebraPrintResult['ok'] ?? false);
$printed = isset($zebraPrintResult) ? (int)($zebraPrintResult['printed'] ?? 0) : 0;
$printFailed = isset($zebraPrintResult) ? (int)($zebraPrintResult['failed'] ?? 0) : 0;
$printMessages = isset($zebraPrintResult) && is_array($zebraPrintResult['messages'] ?? null)
    ? $zebraPrintResult['messages']
    : [];

$statusOk = $savedCount > 0 && $failedCount === 0;
$statusPartial = $savedCount > 0 && $failedCount > 0;

$qrPayloads = [];
?>
<!doctype html>
<html lang="en">
<head>
    <title><?= result_h($pageTitle) ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
            --primary: #0d6efd;
            --sidebar-bg: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-active: #2563eb;
            --body-bg: #f4f7fb;
            --border-soft: #e5eaf2;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--body-bg);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            overflow-x: hidden;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: #ffffff;
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1030;
            display: flex;
            flex-direction: column;
            box-shadow: 8px 0 30px rgba(15, 23, 42, 0.12);
        }

        .sidebar-brand {
            padding: 20px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .sidebar-title {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sidebar-subtitle {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-menu {
            padding: 14px 10px;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-section {
            color: #6b7280;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 12px 12px 6px;
        }

        .sidebar-link {
            color: #d1d5db;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 11px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .sidebar-link.active {
            background: var(--sidebar-active);
            color: #ffffff;
        }

        .sidebar-icon {
            width: 22px;
            text-align: center;
            opacity: 0.95;
            flex-shrink: 0;
        }

        .sidebar-footer {
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-box {
            background: rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 11px;
            margin-bottom: 10px;
        }

        .user-name {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 12px;
            color: #9ca3af;
            text-transform: uppercase;
        }

        .logout-link {
            display: block;
            text-align: center;
            color: #fecaca;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            padding: 9px 10px;
            border-radius: 10px;
        }

        .logout-link:hover {
            background: rgba(239, 68, 68, 0.14);
            color: #ffffff;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 18px;
            overflow-x: hidden;
        }

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .page-title {
            color: var(--text-dark);
            font-weight: 800;
            margin-bottom: 4px;
            letter-spacing: -0.03em;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        .content-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .content-card-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-soft);
            background: #ffffff;
        }

        .content-card-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
        }

        .content-card-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .content-card-body {
            padding: 18px;
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .result-hero {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.06);
            padding: 18px;
            margin-bottom: 18px;
        }

        .status-icon {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 30px;
            font-weight: 900;
            flex-shrink: 0;
        }

        .status-success {
            background: #16a34a;
        }

        .status-warning {
            background: #f59e0b;
        }

        .status-danger {
            background: #dc2626;
        }

        .trace-box {
            background: #f3f8ff;
            border: 1px solid #b6d4fe;
            border-radius: 14px;
            padding: 12px;
            word-break: break-word;
        }

        .trace-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .trace-value {
            font-size: 21px;
            font-weight: 900;
            color: #111827;
            margin-top: 2px;
        }

        .summary-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.06);
            padding: 16px;
            height: 100%;
        }

        .summary-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .summary-value {
            font-size: 26px;
            font-weight: 900;
            color: #111827;
            margin-top: 3px;
        }

        .result-table-wrap {
            max-height: 58vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .result-table {
            width: 100%;
            table-layout: fixed;
            font-size: 11px;
            margin-bottom: 0;
        }

        .result-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 8px 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .result-table td {
            padding: 7px 5px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .result-table tbody tr:hover {
            background: #eef6ff;
        }

        .qr-box {
            width: 96px;
            height: 96px;
            margin: 0 auto;
        }

        .qr-cell {
            width: 120px;
            text-align: center;
        }

        .col-item {
            width: 13%;
            white-space: nowrap;
        }

        .col-part {
            width: 27%;
            white-space: normal;
            line-height: 1.25;
        }

        .col-qty {
            width: 10%;
            white-space: nowrap;
        }

        .col-lot {
            width: 14%;
            white-space: nowrap;
        }

        .col-status {
            width: 14%;
            white-space: nowrap;
        }

        .print-status {
            border-radius: 14px;
            padding: 12px;
            border: 1px solid var(--border-soft);
            background: #f8fafc;
        }

        .print-status.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .print-status.warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .print-status.secondary {
            background: #f8fafc;
            border-color: #e5eaf2;
            color: #374151;
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.2s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 14px;
            }

            .mobile-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #ffffff;
                border: 1px solid var(--border-soft);
                border-radius: 14px;
                padding: 12px 14px;
                margin-bottom: 14px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            }

            .sidebar-backdrop {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.45);
                z-index: 1029;
            }

            .sidebar-backdrop.show {
                display: block;
            }

            .page-header {
                flex-direction: column;
            }

            .result-table-wrap {
                overflow: auto;
            }

            .result-table {
                min-width: 900px;
                table-layout: auto;
                font-size: 12px;
            }

            .result-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .result-table td {
                padding: 7px 6px;
                white-space: nowrap;
            }

            .col-item,
            .col-part,
            .col-qty,
            .col-lot,
            .col-status {
                width: auto;
                min-width: 120px;
            }

            .col-part {
                min-width: 240px;
            }
        }
    </style>
</head>

<body>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('issuer_report'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong><?= result_h($pageTitle) ?></strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title"><?= result_h($pageTitle) ?></h4>
                <div class="page-subtitle">
                    Saved issuance details for SAP IT/ITR processing.
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-primary btn-sm" href="<?= result_h($backUrl) ?>">
                    Issue More
                </a>
                <a class="btn btn-secondary btn-sm" href="pages/dashboard/verification_dashboard.php">
                    Dashboard
                </a>
            </div>
        </div>

        <div class="result-hero">
            <div class="d-flex gap-3 align-items-start flex-wrap">
                <?php if ($statusOk): ?>
                    <div class="status-icon status-success">✓</div>
                <?php elseif ($statusPartial): ?>
                    <div class="status-icon status-warning">!</div>
                <?php else: ?>
                    <div class="status-icon status-danger">×</div>
                <?php endif; ?>

                <div class="flex-grow-1">
                    <h4 class="page-title mb-1">
                        <?php if ($statusOk): ?>
                            Issuance saved successfully
                        <?php elseif ($statusPartial): ?>
                            Issuance saved with some failed lines
                        <?php else: ?>
                            No issuance lines were saved
                        <?php endif; ?>
                    </h4>

                    <div class="page-subtitle mb-3">
                        Receiving is handled through SAP IT/ITR. This screen records the issued lot and quantity.
                    </div>

                    <div class="trace-box">
                        <div class="trace-label">Trace No</div>
                        <div class="trace-value"><?= result_h($traceNo ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-label">Saved</div>
                    <div class="summary-value text-success"><?= number_format($savedCount) ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-label">Failed</div>
                    <div class="summary-value text-danger"><?= number_format($failedCount) ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-label">Total Qty</div>
                    <div class="summary-value"><?= result_h(result_qty($totalQty)) ?></div>
                </div>
            </div>
        </div>

        <?php if (isset($zebraPrintResult)): ?>
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">Zebra Labels</h5>
                    <div class="content-card-subtitle">
                        Auto-print result for physical receiving labels.
                    </div>
                </div>

                <div class="content-card-body">
                    <?php if ($printEnabled): ?>
                        <div class="print-status <?= $printOk ? 'success' : 'warning' ?>">
                            <div class="fw-bold mb-1">
                                <?= $printOk ? 'Labels sent to Zebra printer.' : 'Some Zebra labels failed to print.' ?>
                            </div>
                            <div class="small">
                                <?= number_format($printed) ?> printed,
                                <?= number_format($printFailed) ?> failed.
                            </div>

                            <?php if (!empty($printMessages)): ?>
                                <div class="small mt-2">
                                    <?= result_h(implode(' ', $printMessages)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="print-status secondary">
                            <div class="fw-bold mb-1">Auto-print is disabled.</div>
                            <div class="small">
                                Set the Zebra printer share in config to print labels automatically.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Saved Items</h5>
                <div class="content-card-subtitle">
                    <?= number_format($savedCount) ?> saved item(s).
                </div>
            </div>

            <div class="content-card-body">
                <div class="result-table-wrap">
                    <table class="table table-bordered table-striped align-middle result-table">
                        <thead>
                            <tr>
                                <th class="col-item">Part Number</th>
                                <th class="col-part">Part Name</th>
                                <th class="col-qty">Qty</th>
                                <th class="col-lot">Lot</th>
                                <th class="col-status">Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($saved) && empty($failed)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No items to display.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($saved as $idx => $s): ?>
                                <tr>
                                    <td class="col-item" title="<?= result_h($s['item_code'] ?? '') ?>">
                                        <?= result_h($s['item_code'] ?? '') ?>
                                    </td>

                                    <td class="col-part" title="<?= result_h($s['part_name'] ?? '') ?>">
                                        <?= result_h($s['part_name'] ?? '') ?>
                                    </td>

                                    <td class="col-qty">
                                        <?= result_h(result_qty($s['quantity'] ?? 0)) ?>
                                    </td>

                                    <td class="col-lot" title="<?= result_h($s['lot_no'] ?? '') ?>">
                                        <?= result_h($s['lot_no'] ?? '') ?>
                                    </td>

                                    <td class="col-status text-success fw-bold">
                                        Saved
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php foreach ($failed as $f): ?>
                                <?php $it = $f['item'] ?? []; ?>
                                <tr>
                                    <td class="col-item" title="<?= result_h($it['item_code'] ?? '') ?>">
                                        <?= result_h($it['item_code'] ?? '') ?>
                                    </td>

                                    <td class="col-part text-muted">
                                        Not saved
                                    </td>

                                    <td class="col-qty">
                                        <?= result_h($it['quantity'] ?? '') ?>
                                    </td>

                                    <td class="col-lot">
                                        <?= result_h($it['lot_no'] ?? '') ?>
                                    </td>

                                    <td class="col-status text-danger fw-bold" title="<?= result_h($f['reason'] ?? '') ?>">
                                        <?= result_h($f['reason'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<script>
const qrPayloads = <?= json_encode($qrPayloads, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

qrPayloads.forEach(function (payload) {
    const el = document.getElementById('qr_' + payload.idx);

    if (el) {
        new QRCode(el, {
            text: payload.url,
            width: 96,
            height: 96
        });
    }
});

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', function () {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
    });
}
</script>

</body>
</html>
