<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_login();

$conn = get_whpokayoke_connection();

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');

function tx_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

$issueRows = fetch_all(
    $conn,
    'SELECT TOP 300
        TransactionID,
        TraceNo,
        ItemCode,
        PartName,
        Quantity,
        LotNo,
        ITRNumber,
        IssuedByUsername,
        DeviceHostname,
        DeviceIPAddress,
        IssuedAt
     FROM IssuanceTransactions
     ORDER BY IssuedAt DESC'
);

$receiveRows = fetch_all(
    $conn,
    'SELECT TOP 300
        TransactionID,
        TraceNo,
        ItemCode,
        PartName,
        Quantity,
        LotNo,
        ReceiverArea,
        Remarks,
        ReceivedByUsername,
        DeviceHostname,
        DeviceIPAddress,
        ReceivedAt
     FROM ReceivingTransactions
     ORDER BY ReceivedAt DESC'
);
?>
<!doctype html>
<html lang="en">
<head>
    <title>Transactions</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
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

        .summary-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .summary-pill {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 999px;
            padding: 8px 14px;
            color: #374151;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
        }

        .content-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.06);
            overflow: hidden;
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

        .nav-tabs {
            border-bottom: 1px solid var(--border-soft);
        }

        .nav-tabs .nav-link {
            border: none;
            color: #6b7280;
            font-weight: 800;
            font-size: 14px;
            border-radius: 12px 12px 0 0;
            padding: 12px 18px;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd;
            background: #eef6ff;
            border-bottom: 3px solid #0d6efd;
        }

        .tab-panel {
            border: 1px solid var(--border-soft);
            border-top: none;
            border-radius: 0 0 16px 16px;
            background: #ffffff;
            padding: 16px;
        }

        .form-control {
            border-radius: 11px;
            border: 1px solid #d9e2ef;
            min-height: 42px;
            font-size: 14px;
            background-color: #ffffff;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .table-wrap {
            max-height: 62vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .tx-table {
            width: 100%;
            table-layout: fixed;
            font-size: 11px;
            margin-bottom: 0;
        }

        .tx-table thead th {
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

        .tx-table td {
            padding: 7px 5px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tx-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-trace { width: 12%; white-space: nowrap; }
        .col-item { width: 11%; white-space: nowrap; }
        .col-part { width: 18%; white-space: normal; line-height: 1.25; }
        .col-qty { width: 7%; text-align: right; white-space: nowrap; }
        .col-lot { width: 10%; white-space: nowrap; }
        .col-itr { width: 8%; white-space: nowrap; }
        .col-user { width: 9%; white-space: nowrap; }
        .col-host { width: 8%; white-space: nowrap; }
        .col-ip { width: 8%; white-space: nowrap; }
        .col-date { width: 13%; white-space: nowrap; }
        .col-area { width: 9%; white-space: nowrap; }
        .col-remarks { width: 12%; white-space: nowrap; }

        .empty-row {
            padding: 34px !important;
            text-align: center;
            color: #6b7280 !important;
        }

        @media (max-width: 1300px) {
            .tx-table {
                font-size: 10px;
            }

            .tx-table thead th {
                font-size: 8px;
                padding: 7px 4px;
            }

            .tx-table td {
                padding: 6px 4px;
            }
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

            .table-wrap {
                overflow: auto;
            }

            .tx-table {
                min-width: 1150px;
                table-layout: auto;
                font-size: 12px;
            }

            .tx-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .tx-table td {
                padding: 7px 6px;
                white-space: nowrap;
            }

            .col-trace,
            .col-item,
            .col-part,
            .col-qty,
            .col-lot,
            .col-itr,
            .col-user,
            .col-host,
            .col-ip,
            .col-date,
            .col-area,
            .col-remarks {
                width: auto;
                min-width: 100px;
            }

            .col-part {
                min-width: 240px;
            }

            .col-date {
                min-width: 160px;
            }

            .col-remarks {
                min-width: 180px;
            }
        }
    </style>
</head>

<body>
<header class="sap-shellbar">
    <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">&#9776;</button>
    <div class="shell-logo" aria-hidden="true">
        <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
    </div>
    <div class="shell-title-wrap">
        <div class="shell-title">NBC Rawmats Traceability</div>
        <div class="shell-subtitle">Transaction records</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('transactions'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>Transactions</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">Transactions</h4>
                <div class="page-subtitle">
                    View recent issuance and receiving transaction history.
                </div>
            </div>

            <div class="summary-row">
                <div class="summary-pill">
                    Issuance: <?= count($issueRows) ?>
                </div>

                <div class="summary-pill">
                    Receiving: <?= count($receiveRows) ?>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Transaction Records</h5>
                <div class="content-card-subtitle">
                    Showing the latest 300 records for each transaction type.
                </div>
            </div>

            <div class="content-card-body">

                <ul class="nav nav-tabs" id="txTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link active"
                            data-bs-toggle="tab"
                            data-bs-target="#issued"
                            type="button"
                            role="tab"
                        >
                            Issuance
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            data-bs-toggle="tab"
                            data-bs-target="#received"
                            type="button"
                            role="tab"
                        >
                            Receiving
                        </button>
                    </li>
                </ul>

                <div class="tab-content tab-panel">
                    <div class="tab-pane fade show active" id="issued" role="tabpanel">
                        <input
                            class="form-control form-control-sm mb-3"
                            id="searchIssued"
                            placeholder="Search issued records..."
                        >

                        <div class="table-wrap">
                            <table class="table table-bordered table-striped align-middle tx-table" id="issuedTable">
                                <thead>
                                    <tr>
                                        <th class="col-trace">Trace No</th>
                                        <th class="col-item">Part Number</th>
                                        <th class="col-part">Part Name</th>
                                        <th class="col-qty">Qty</th>
                                        <th class="col-lot">Lot</th>
                                        <th class="col-itr">ITR/IT</th>
                                        <th class="col-user">Issued By</th>
                                        <th class="col-host">Hostname</th>
                                        <th class="col-ip">IP</th>
                                        <th class="col-date">Issued At</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (empty($issueRows)): ?>
                                        <tr>
                                            <td colspan="10" class="empty-row">
                                                No issuance records found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($issueRows as $r): ?>
                                            <tr>
                                                <td class="col-trace" title="<?= h(tx_cell($r['TraceNo'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['TraceNo'] ?? '')) ?>
                                                </td>

                                                <td class="col-item" title="<?= h(tx_cell($r['ItemCode'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ItemCode'] ?? '')) ?>
                                                </td>

                                                <td class="col-part" title="<?= h(tx_cell($r['PartName'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['PartName'] ?? '')) ?>
                                                </td>

                                                <td class="col-qty" title="<?= h(tx_cell($r['Quantity'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['Quantity'] ?? '')) ?>
                                                </td>

                                                <td class="col-lot" title="<?= h(tx_cell($r['LotNo'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['LotNo'] ?? '')) ?>
                                                </td>

                                                <td class="col-itr" title="<?= h(tx_cell($r['ITRNumber'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ITRNumber'] ?? '')) ?>
                                                </td>

                                                <td class="col-user" title="<?= h(tx_cell($r['IssuedByUsername'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['IssuedByUsername'] ?? '')) ?>
                                                </td>

                                                <td class="col-host" title="<?= h(tx_cell($r['DeviceHostname'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['DeviceHostname'] ?? '')) ?>
                                                </td>

                                                <td class="col-ip" title="<?= h(tx_cell($r['DeviceIPAddress'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['DeviceIPAddress'] ?? '')) ?>
                                                </td>

                                                <td class="col-date" title="<?= h(tx_cell($r['IssuedAt'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['IssuedAt'] ?? '')) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="received" role="tabpanel">
                        <input
                            class="form-control form-control-sm mb-3"
                            id="searchReceived"
                            placeholder="Search received records..."
                        >

                        <div class="table-wrap">
                            <table class="table table-bordered table-striped align-middle tx-table" id="receivedTable">
                                <thead>
                                    <tr>
                                        <th class="col-trace">Trace No</th>
                                        <th class="col-item">Part Number</th>
                                        <th class="col-part">Part Name</th>
                                        <th class="col-qty">Qty</th>
                                        <th class="col-lot">Lot</th>
                                        <th class="col-area">Area</th>
                                        <th class="col-remarks">Remarks</th>
                                        <th class="col-user">Received By</th>
                                        <th class="col-host">Hostname</th>
                                        <th class="col-ip">IP</th>
                                        <th class="col-date">Received At</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (empty($receiveRows)): ?>
                                        <tr>
                                            <td colspan="11" class="empty-row">
                                                No receiving records found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($receiveRows as $r): ?>
                                            <tr>
                                                <td class="col-trace" title="<?= h(tx_cell($r['TraceNo'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['TraceNo'] ?? '')) ?>
                                                </td>

                                                <td class="col-item" title="<?= h(tx_cell($r['ItemCode'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ItemCode'] ?? '')) ?>
                                                </td>

                                                <td class="col-part" title="<?= h(tx_cell($r['PartName'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['PartName'] ?? '')) ?>
                                                </td>

                                                <td class="col-qty" title="<?= h(tx_cell($r['Quantity'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['Quantity'] ?? '')) ?>
                                                </td>

                                                <td class="col-lot" title="<?= h(tx_cell($r['LotNo'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['LotNo'] ?? '')) ?>
                                                </td>

                                                <td class="col-area" title="<?= h(tx_cell($r['ReceiverArea'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ReceiverArea'] ?? '')) ?>
                                                </td>

                                                <td class="col-remarks" title="<?= h(tx_cell($r['Remarks'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['Remarks'] ?? '')) ?>
                                                </td>

                                                <td class="col-user" title="<?= h(tx_cell($r['ReceivedByUsername'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ReceivedByUsername'] ?? '')) ?>
                                                </td>

                                                <td class="col-host" title="<?= h(tx_cell($r['DeviceHostname'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['DeviceHostname'] ?? '')) ?>
                                                </td>

                                                <td class="col-ip" title="<?= h(tx_cell($r['DeviceIPAddress'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['DeviceIPAddress'] ?? '')) ?>
                                                </td>

                                                <td class="col-date" title="<?= h(tx_cell($r['ReceivedAt'] ?? '')) ?>">
                                                    <?= h(tx_cell($r['ReceivedAt'] ?? '')) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<script>
function bindSearch(inputId, tableId) {
    const input = document.getElementById(inputId);

    if (!input) {
        return;
    }

    input.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#' + tableId + ' tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

bindSearch('searchIssued', 'issuedTable');
bindSearch('searchReceived', 'receivedTable');

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
