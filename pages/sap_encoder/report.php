<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/db_connect.php';

require_role([ROLE_SAP_ENCODER, ROLE_ADMIN]);

$currentUser = current_user();

$process = trim($_GET['process'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedProcesses = [
    '' => 'All Process',
    'CNC' => 'CNC',
    'SA' => 'Sub-Assy',
    'KIT' => 'Kitting',
    'HM' => 'Hotmelt',
    'CSW' => 'Contact Switch',
    'MR' => 'MR Switch',
];

$rows = [];
$errorMessage = '';

try {
    $conn = get_whpokayoke_connection();

    $where = [];
    $params = [];

    if ($process !== '') {
        $where[] = "ProcessCode = ?";
        $params[] = $process;
    }

    if ($dateFrom !== '') {
        $where[] = "CAST(CreatedAt AS date) >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "CAST(CreatedAt AS date) <= ?";
        $params[] = $dateTo;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            TraceNo,
            ProcessCode,
            RequestNo,
            ItrNumber,
            SapItemCode,
            PartName,
            LotNo,
            WarehouseLotNo,
            Qty,
            IssuedBy,
            ReceivedBy,
            CreatedAt,
            Status
        FROM dbo.vw_SapEncoderReport
        $whereSql
        ORDER BY CreatedAt DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(sqlsrv_fail_message());
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function qty_fmt($value): string
{
    $num = (float) $value;
    return number_format($num, 3, '.', ',');
}

function date_fmt($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null || $value === '') {
        return '';
    }

    return (string) $value;
}

function status_class($status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'open' => 'status-open',
        'issued' => 'status-issued',
        'received' => 'status-received',
        'returned' => 'status-returned',
        'revoked' => 'status-revoked',
        default => 'status-default',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <title>SAP Encoder Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
            --body-bg: #f4f7fb;
            --border-soft: #e5eaf2;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
        }

        body {
            margin: 0;
            background: var(--body-bg);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            overflow-x: hidden;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 18px;
            overflow-x: hidden;
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

        .form-control,
        .form-select {
            border-radius: 11px;
            border: 1px solid #d9e2ef;
            min-height: 42px;
            font-size: 14px;
            background-color: #f9fbfd;
        }

        .form-control:focus,
        .form-select:focus {
            background-color: #ffffff;
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .summary-box {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
        }

        .summary-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .summary-value {
            font-size: 22px;
            color: #111827;
            font-weight: 900;
            margin-top: 3px;
        }

        .report-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
            max-height: calc(100vh - 360px);
            overflow-y: auto;
        }

        .report-table {
            min-width: 1350px;
            margin-bottom: 0;
            font-size: 12px;
        }

        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #f8fafc;
            color: #374151;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
            border-bottom: 1px solid #d8e0eb;
        }

        .report-table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        .status-badge {
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .status-open {
            background: #fff7ed;
            color: #c2410c;
        }

        .status-issued {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-received {
            background: #ecfdf5;
            color: #047857;
        }

        .status-returned {
            background: #fef2f2;
            color: #b91c1c;
        }

        .status-revoked {
            background: #f3f4f6;
            color: #374151;
        }

        .status-default {
            background: #f3f4f6;
            color: #374151;
        }

        .search-box {
            max-width: 320px;
        }

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 14px;
            }

            .page-header {
                flex-direction: column;
            }

            .summary-grid {
                grid-template-columns: 1fr;
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
        <div class="shell-subtitle">SAP Encoder workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('sap_encoder_report'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>SAP Encoder Report</strong>
            <button class="btn btn-sm btn-primary" type="button" id="mobileSidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">SAP Encoder Report</h4>
                <div class="page-subtitle">
                    View issuance and receiving transactions for SAP encoding. Filter by process and date range.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2">
                <?= count($rows) ?> record(s)
            </span>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger">
                <div class="fw-bold mb-1">Unable to load SAP Encoder Report.</div>
                <div><?= e($errorMessage) ?></div>
            </div>
        <?php endif; ?>

        <?php
        $totalQty = 0;
        $issuedCount = 0;
        $receivedCount = 0;

        foreach ($rows as $row) {
            $totalQty += (float) ($row['Qty'] ?? 0);

            $status = strtolower((string) ($row['Status'] ?? ''));

            if ($status === 'issued') {
                $issuedCount++;
            }

            if ($status === 'received') {
                $receivedCount++;
            }
        }
        ?>

        <div class="summary-grid">
            <div class="summary-box">
                <div class="summary-label">Total Records</div>
                <div class="summary-value"><?= count($rows) ?></div>
            </div>

            <div class="summary-box">
                <div class="summary-label">Total Qty</div>
                <div class="summary-value"><?= qty_fmt($totalQty) ?></div>
            </div>

            <div class="summary-box">
                <div class="summary-label">Issued</div>
                <div class="summary-value"><?= $issuedCount ?></div>
            </div>

            <div class="summary-box">
                <div class="summary-label">Received</div>
                <div class="summary-value"><?= $receivedCount ?></div>
            </div>
        </div>

        <div class="content-card mb-3">
            <div class="content-card-header">
                <h5 class="content-card-title">Filters</h5>
                <div class="content-card-subtitle">Select process and date range to narrow the report.</div>
            </div>

            <div class="content-card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-muted">Process</label>
                        <select name="process" class="form-select">
                            <?php foreach ($allowedProcesses as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $process === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold small text-muted">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold small text-muted">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                    </div>

                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">
                            Filter
                        </button>
                    </div>

                    <div class="col-md-2 d-grid">
                        <a href="<?= h(app_path('pages/sap_encoder/sap_encoder_report.php')) ?>" class="btn btn-outline-secondary">
                            Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="content-card-title">Report Details</h5>
                        <div class="content-card-subtitle">
                            Use the search box for quick filtering on the loaded data.
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <input
                            type="search"
                            class="form-control form-control-sm search-box"
                            id="tableSearch"
                            placeholder="Search report..."
                            oninput="filterTable()"
                        >

                        <button type="button" class="btn btn-sm btn-outline-success" onclick="exportTableToCsv()">
                            Export CSV
                        </button>
                    </div>
                </div>
            </div>

            <div class="content-card-body">
                <div class="report-table-wrap">
                    <table class="table table-bordered table-striped align-middle report-table" id="sapEncoderReportTable">
                        <thead>
                            <tr>
                                <th>Trace No</th>
                                <th>Process</th>
                                <th>Request No</th>
                                <th>ITR No</th>
                                <th>SAP ItemCode</th>
                                <th>Part Name</th>
                                <th>GRPO Lot No</th>
                                <th>WH Lot No</th>
                                <th class="text-end">Qty</th>
                                <th>Issued By</th>
                                <th>Received By</th>
                                <th>Date/Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">
                                        No records found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $status = $row['Status'] ?? '';
                                    $statusClass = status_class($status);
                                    ?>
                                    <tr>
                                        <td><?= e($row['TraceNo'] ?? '') ?></td>
                                        <td><?= e($row['ProcessCode'] ?? '') ?></td>
                                        <td><?= e($row['RequestNo'] ?? '') ?></td>
                                        <td><?= e($row['ItrNumber'] ?? '') ?></td>
                                        <td><?= e($row['SapItemCode'] ?? '') ?></td>
                                        <td><?= e($row['PartName'] ?? '') ?></td>
                                        <td><?= e($row['LotNo'] ?? '') ?></td>
                                        <td><?= e($row['WarehouseLotNo'] ?? '') ?></td>
                                        <td class="text-end fw-bold"><?= qty_fmt($row['Qty'] ?? 0) ?></td>
                                        <td><?= e($row['IssuedBy'] ?? '') ?></td>
                                        <td><?= e($row['ReceivedBy'] ?? '') ?></td>
                                        <td><?= e(date_fmt($row['CreatedAt'] ?? '')) ?></td>
                                        <td>
                                            <span class="status-badge <?= e($statusClass) ?>">
                                                <?= e($status) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
function filterTable() {
    const search = document.getElementById('tableSearch');
    const table = document.getElementById('sapEncoderReportTable');

    if (!search || !table) {
        return;
    }

    const query = search.value.trim().toLowerCase();
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(function (row) {
        const text = row.innerText.toLowerCase();
        row.hidden = query !== '' && !text.includes(query);
    });
}

function csvCell(value) {
    let text = String(value || '').replace(/\s+/g, ' ').trim();
    text = text.replace(/"/g, '""');
    return '"' + text + '"';
}

function csvTextCell(value) {
    let text = String(value || '').replace(/\s+/g, ' ').trim();
    text = text.replace(/"/g, '""');

    /*
        Force Excel to read value as text.
        Example result in CSV:
        ="7L0086-7024"
    */
    return '"=""' + text + '"""';
}

function exportTableToCsv() {
    const table = document.getElementById('sapEncoderReportTable');

    if (!table) {
        return;
    }

    const rows = Array.from(table.querySelectorAll('tr')).filter(function (row) {
        return !row.hidden;
    });

    const csv = rows.map(function (row, rowIndex) {
        const cells = Array.from(row.querySelectorAll('th,td'));

        return cells.map(function (cell, colIndex) {
            const text = cell.innerText;

            /*
                Column index 4 = SAP ItemCode.
                Apply only to body rows, not header row.
            */
            if (rowIndex > 0 && colIndex === 4) {
                return csvTextCell(text);
            }

            return csvCell(text);
        }).join(',');
    }).join('\n');

    /*
        UTF-8 BOM helps Excel open CSV correctly.
    */
    const bom = '\uFEFF';

    const blob = new Blob([bom + csv], {
        type: 'text/csv;charset=utf-8;'
    });

    const url = URL.createObjectURL(blob);

    const now = new Date();
    const stamp = now.getFullYear()
        + String(now.getMonth() + 1).padStart(2, '0')
        + String(now.getDate()).padStart(2, '0')
        + '_'
        + String(now.getHours()).padStart(2, '0')
        + String(now.getMinutes()).padStart(2, '0');

    const a = document.createElement('a');
    a.href = url;
    a.download = 'sap_encoder_report_' + stamp + '.csv';

    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    URL.revokeObjectURL(url);
}

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function openSidebar() {
    if (!sidebar || !sidebarBackdrop) {
        return;
    }

    sidebar.classList.add('show');
    sidebarBackdrop.classList.add('show');
}

function closeSidebar() {
    if (!sidebar || !sidebarBackdrop) {
        return;
    }

    sidebar.classList.remove('show');
    sidebarBackdrop.classList.remove('show');
}

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', openSidebar);
}

if (mobileSidebarToggle) {
    mobileSidebarToggle.addEventListener('click', openSidebar);
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
}
</script>

</body>
</html>