<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/scanplus_lookup.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);

function report_date_value($name, $default = '')
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function report_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function excel_cell($value)
{
    return htmlspecialchars(report_cell($value), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$dateFrom = report_date_value('date_from', $today);
$dateTo = report_date_value('date_to', $today);
$export = strtolower(trim((string)($_GET['export'] ?? ''))) === 'excel';
$q = trim((string)($_GET['q'] ?? ''));

$pageSize = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$u = current_user();
$currentRole = strtolower($u['role'] ?? '');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$where = [
    'IT.IssuedAt >= ?',
    'IT.IssuedAt < DATEADD(day, 1, ?)'
];

$params = [
    $dateFrom,
    $dateTo
];

if (($u['role'] ?? '') !== ROLE_ADMIN) {
    $where[] = 'IT.IssuedByUsername = ?';
    $params[] = $u['username'] ?? '';
}

if ($q !== '') {
    $where[] = '(
        IT.TraceNo LIKE ?
        OR IT.ItemCode LIKE ?
        OR IT.PartName LIKE ?
        OR IT.LotNo LIKE ?
        OR IT.ITRNumber LIKE ?
        OR IT.IssuedByUsername LIKE ?
    )';

    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$conn = get_whpokayoke_connection();
$whereSql = implode(' AND ', $where);

$countSql = '
    SELECT COUNT(*) AS TotalRows
    FROM IssuanceTransactions IT
    WHERE ' . $whereSql . '
';
$countRows = fetch_all($conn, $countSql, $params);
$totalRows = (int)($countRows[0]['TotalRows'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$sql = '
    SELECT
        IT.TraceNo,
        IT.ItemCode,
        IT.PartName,
        Req.RequestedQty,
        Req.IssuedQty AS RequestLineIssuedQty,
        Req.RequestNo,
        Req.RequestedByUsername,
        IT.Quantity,
        IT.LotNo,
        IT.ITRNumber,
        IT.ITRDocEntry,
        IT.ITRLineNum,
        IT.IssuedByUsername,
        IT.DeviceHostname,
        IT.DeviceIPAddress,
        IT.IssuedAt
    FROM IssuanceTransactions IT
    OUTER APPLY (
        SELECT TOP 1
            H.RequestNo,
            H.RequestedByUsername,
            L.RequestedQty,
            L.IssuedQty
        FROM WarehouseIssueRequestHeader H
        INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
        WHERE
            (
                H.IssuedTraceNo = IT.TraceNo
                OR (
                    L.SAP_IT_DocEntry = IT.ITRDocEntry
                    AND L.SAP_IT_LineNum = IT.ITRLineNum
                )
            )
            AND L.ItemCode = IT.ItemCode
            AND (
                ISNULL(L.LotNo, NCHAR(0)) = ISNULL(IT.LotNo, NCHAR(0))
                OR LEN(LTRIM(RTRIM(ISNULL(L.LotNo, NCHAR(0))))) = 0
                OR LEN(LTRIM(RTRIM(ISNULL(IT.LotNo, NCHAR(0))))) = 0
            )
        ORDER BY
            CASE WHEN H.IssuedTraceNo = IT.TraceNo THEN 0 ELSE 1 END,
            H.RequestedAt DESC,
            L.RequestLineID DESC
    ) Req
    WHERE ' . $whereSql . '
    ORDER BY IssuedAt DESC, TransactionID DESC
';

if (!$export) {
    $sql .= ' OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$pageSize . ' ROWS ONLY';
}

$rows = fetch_all($conn, $sql, $params);

function enrich_issuer_scan_rows_with_scanplus(&$rows, $whpConn)
{
    if (empty($rows)) {
        return;
    }

    $scanRefs = [];
    $seenRefs = [];

    foreach ($rows as $row) {
        $ref = [
            'doc_entry' => $row['ITRDocEntry'] ?? 0,
            'line_num' => $row['ITRLineNum'] ?? null,
            'item_code' => $row['ItemCode'] ?? '',
            'lot_no' => $row['LotNo'] ?? ''
        ];

        $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);

        if ($scanKey === '') {
            continue;
        }

        $dedupeKey = $scanKey . '|' . strtoupper(trim((string)$ref['lot_no']));

        if (isset($seenRefs[$dedupeKey])) {
            continue;
        }

        $seenRefs[$dedupeKey] = true;
        $scanRefs[] = $ref;
    }

    $hasScanRefs = false;

    foreach ($scanRefs as $ref) {
        if (scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']) !== '') {
            $hasScanRefs = true;
            break;
        }
    }

    $cache = $hasScanRefs ? scanplus_cache_read($whpConn, $scanRefs) : ['rows' => [], 'fresh_keys' => []];
    $scanplusRows = $cache['rows'];
    $freshKeys = $cache['fresh_keys'];
    $refsToRefresh = [];

    foreach ($scanRefs as $ref) {
        $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
        $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
        $targetKey = $scanLotKey !== '' ? $scanLotKey : $scanKey;

        if ($targetKey !== '' && !isset($freshKeys[$targetKey])) {
            $refsToRefresh[] = $ref;
        }
    }

    if (!empty($refsToRefresh)) {
        $freshScanplusRows = scanplus_lookup_by_itr_lines(get_erp_connection(), $refsToRefresh);

        foreach ($refsToRefresh as $ref) {
            $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
            $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
            $scan = $scanLotKey !== ''
                ? ($freshScanplusRows[$scanLotKey] ?? ($freshScanplusRows[$scanKey] ?? null))
                : ($scanKey !== '' ? ($freshScanplusRows[$scanKey] ?? null) : null);

            scanplus_cache_write($whpConn, $ref, $scan);

            if ($scanLotKey !== '') {
                $scanplusRows[$scanLotKey] = $scan ?? [];
            }

            if ($scanKey !== '') {
                $scanplusRows[$scanKey] = $scan ?? [];
            }
        }
    }

    foreach ($rows as &$row) {
        $scanKey = scanplus_key($row['ITRDocEntry'] ?? 0, $row['ITRLineNum'] ?? null, $row['ItemCode'] ?? '');
        $scanLotKey = scanplus_lot_key($row['ITRDocEntry'] ?? 0, $row['ITRLineNum'] ?? null, $row['ItemCode'] ?? '', $row['LotNo'] ?? '');
        $scan = $scanLotKey !== ''
            ? ($scanplusRows[$scanLotKey] ?? ($scanplusRows[$scanKey] ?? null))
            : ($scanKey !== '' ? ($scanplusRows[$scanKey] ?? null) : null);

        $row['ScanStatus'] = $scan['scan_status'] ?? '';
        $row['ReceivedQty'] = $scan['received_qty'] ?? '';
        $row['BarcodeUser'] = $scan['barcode_user'] ?? '';
        $row['ReceivedAt'] = $scan['received_at'] ?? '';
    }
    unset($row);
}

enrich_issuer_scan_rows_with_scanplus($rows, $conn);

$baseQuery = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

if ($q !== '') {
    $baseQuery['q'] = $q;
}

function issuer_scan_report_url($query)
{
    return 'pages/issuer/issuer_scan_report.php?' . http_build_query($query);
}

$columns = [
    'Trace No',
    'Part No',
    'Part Name',
    'Req Qty',
    'Iss Qty',
    'Qty',
    'Lot',
    'ITR/IT',
    'Iss By',
    'Scan Status',
    'Barcode User',
    'Scanned At',
    'Hostname',
    'IP Address',
    'Issued At'
];

if ($export) {
    $filename = 'issuer_scans_' . $dateFrom . '_to_' . $dateTo . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Issuer Scans</title>
</head>
<body>
<table border="1">
    <thead>
        <tr>
            <?php foreach ($columns as $c): ?>
                <th><?= excel_cell($c) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?= count($columns) ?>">No records found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= excel_cell($r['TraceNo'] ?? '') ?></td>
                    <td><?= excel_cell($r['ItemCode'] ?? '') ?></td>
                    <td><?= excel_cell($r['PartName'] ?? '') ?></td>
                    <td><?= excel_cell($r['RequestedQty'] ?? '') ?></td>
                    <td><?= excel_cell($r['Quantity'] ?? '') ?></td>
                    <td><?= excel_cell($r['ReceivedQty'] ?? '') ?></td>
                    <td><?= excel_cell($r['LotNo'] ?? '') ?></td>
                    <td><?= excel_cell($r['ITRNumber'] ?? '') ?></td>
                    <td><?= excel_cell($r['IssuedByUsername'] ?? '') ?></td>
                    <td><?= excel_cell($r['ScanStatus'] ?? '') ?></td>
                    <td><?= excel_cell($r['BarcodeUser'] ?? '') ?></td>
                    <td><?= excel_cell($r['ReceivedAt'] ?? '') ?></td>
                    <td><?= excel_cell($r['DeviceHostname'] ?? '') ?></td>
                    <td><?= excel_cell($r['DeviceIPAddress'] ?? '') ?></td>
                    <td><?= excel_cell($r['IssuedAt'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <title>Issuer Scan Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .filter-box {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
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

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .report-table-wrap {
            max-height: 68vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .report-table {
            width: 100%;
            table-layout: fixed;
            font-size: 10px;
            margin-bottom: 0;
        }

        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 8px 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table td {
            padding: 7px 5px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-trace { width: 9%; white-space: nowrap; }
        .col-item { width: 8%; white-space: nowrap; }
        .col-part { width: 14%; white-space: normal; line-height: 1.25; }
        .col-qty { width: 6%; text-align: right; white-space: nowrap; }
        .col-lot { width: 7%; white-space: nowrap; }
        .col-itr { width: 6%; white-space: nowrap; }
        .col-user { width: 8%; white-space: nowrap; }
        .col-status { width: 8%; white-space: nowrap; }
        .col-host { width: 8%; white-space: nowrap; }
        .col-ip { width: 8%; white-space: nowrap; }
        .col-date { width: 10%; white-space: nowrap; }

        .empty-row {
            padding: 34px !important;
            text-align: center;
            color: #6b7280 !important;
        }

        .status-pill {
            display: inline-flex;
            max-width: 100%;
            align-items: center;
            justify-content: center;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-open,
        .status-pending,
        .status-pending_receive {
            background: #fef3c7;
            color: #92400e;
        }

        .status-issued,
        .status-sap_received,
        .status-closed,
        .status-completed,
        .status-matched {
            background: #dcfce7;
            color: #166534;
        }

        .status-cancelled,
        .status-rejected,
        .status-lot_mismatch,
        .status-qty_variance,
        .status-lot_and_qty_variance {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 1300px) {
            .report-table {
                font-size: 10px;
            }

            .report-table thead th {
                font-size: 8px;
                padding: 7px 4px;
            }

            .report-table td {
                padding: 6px 4px;
            }

            .status-pill {
                font-size: 7.5px;
                padding: 3px 5px;
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

            .report-table-wrap {
                overflow: auto;
            }

            .report-table {
                min-width: 1750px;
                table-layout: auto;
                font-size: 12px;
            }

            .report-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .report-table td {
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
            .col-status,
            .col-host,
            .col-ip,
            .col-date {
                width: auto;
                min-width: 100px;
            }

            .col-part {
                min-width: 240px;
            }

            .col-date {
                min-width: 160px;
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
        <div class="shell-subtitle">Issuer reporting</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('issuer_report'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>Issuer Scan Report</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">Issuer Scan Report</h4>
                <div class="page-subtitle">
                    Issued transaction history by date range, with receiver scan details cached from SAP into WHPOKAYOKE.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2">
                <?= number_format($totalRows) ?> line(s)
            </span>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Report Filters</h5>
                <div class="content-card-subtitle">
                    Filter issued transactions and export the result to Excel.
                </div>
            </div>

            <div class="content-card-body">

                <form class="filter-box" method="get">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label" for="date_from">Date From</label>
                            <input
                                class="form-control"
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= h($dateFrom) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label" for="date_to">Date To</label>
                            <input
                                class="form-control"
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= h($dateTo) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <button class="btn btn-primary" type="submit">
                                Filter
                            </button>
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <a
                                class="btn btn-success"
                                href="<?= h(issuer_scan_report_url($baseQuery + ['export' => 'excel'])) ?>"
                            >
                                Export Excel
                            </a>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-12">
                            <label class="form-label" for="searchReport">Search Item / Report</label>
                            <input
                                class="form-control form-control-sm"
                                type="search"
                                id="searchReport"
                                name="q"
                                value="<?= h($q) ?>"
                                placeholder="Search SAP code, part name, trace, lot, ITR, issuer..."
                            >
                            <div class="form-text">
                                Use SAP ItemCode or Part Name to search items. Press Enter or click Filter to search all records.
                            </div>
                        </div>
                    </div>
                </form>

                <div class="report-table-wrap">
                    <table class="table table-bordered table-striped align-middle report-table" id="reportTable">
                        <thead>
                            <tr>
                                <th class="col-trace">Trace No</th>
                                <th class="col-item">Part No</th>
                                <th class="col-part">Part Name</th>
                                <th class="col-qty">Req Qty</th>
                                <th class="col-qty">Iss Qty</th>
                                <th class="col-qty">Qty</th>
                                <th class="col-lot">Lot</th>
                                <th class="col-itr">ITR/IT</th>
                                <th class="col-user">Iss By</th>
                                <th class="col-status">Scan Status</th>
                                <th class="col-user">Barcode User</th>
                                <th class="col-date">Scanned At</th>
                                <th class="col-host">Hostname</th>
                                <th class="col-ip">IP Address</th>
                                <th class="col-date">Issued At</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="<?= count($columns) ?>" class="empty-row">
                                        No records found for the selected date range.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $scanStatus = strtolower((string)($r['ScanStatus'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="col-trace" title="<?= h(report_cell($r['TraceNo'] ?? '')) ?>">
                                            <?= h(report_cell($r['TraceNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-item" title="<?= h(report_cell($r['ItemCode'] ?? '')) ?>">
                                            <?= h(report_cell($r['ItemCode'] ?? '')) ?>
                                        </td>

                                        <td class="col-part" title="<?= h(report_cell($r['PartName'] ?? '')) ?>">
                                            <?= h(report_cell($r['PartName'] ?? '')) ?>
                                        </td>

                                        <td class="col-qty" title="<?= h(report_cell($r['RequestedQty'] ?? '')) ?>">
                                            <?= h(report_cell($r['RequestedQty'] ?? '')) ?>
                                        </td>

                                        <td class="col-qty" title="<?= h(report_cell($r['Quantity'] ?? '')) ?>">
                                            <?= h(report_cell($r['Quantity'] ?? '')) ?>
                                        </td>

                                        <td class="col-qty" title="<?= h(report_cell($r['ReceivedQty'] ?? '')) ?>">
                                            <?= h(report_cell($r['ReceivedQty'] ?? '')) ?>
                                        </td>

                                        <td class="col-lot" title="<?= h(report_cell($r['LotNo'] ?? '')) ?>">
                                            <?= h(report_cell($r['LotNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-itr" title="<?= h(report_cell($r['ITRNumber'] ?? '')) ?>">
                                            <?= h(report_cell($r['ITRNumber'] ?? '')) ?>
                                        </td>

                                        <td class="col-user" title="<?= h(report_cell($r['IssuedByUsername'] ?? '')) ?>">
                                            <?= h(report_cell($r['IssuedByUsername'] ?? '')) ?>
                                        </td>

                                        <td class="col-status" title="<?= h(report_cell($r['ScanStatus'] ?? '')) ?>">
                                            <?php if (trim((string)($r['ScanStatus'] ?? '')) !== ''): ?>
                                                <span class="status-pill status-<?= h($scanStatus) ?>">
                                                    <?= h(report_cell($r['ScanStatus'] ?? '')) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="col-user" title="<?= h(report_cell($r['BarcodeUser'] ?? '')) ?>">
                                            <?= h(report_cell($r['BarcodeUser'] ?? '')) ?>
                                        </td>

                                        <td class="col-date" title="<?= h(report_cell($r['ReceivedAt'] ?? '')) ?>">
                                            <?= h(report_cell($r['ReceivedAt'] ?? '')) ?>
                                        </td>

                                        <td class="col-host" title="<?= h(report_cell($r['DeviceHostname'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceHostname'] ?? '')) ?>
                                        </td>

                                        <td class="col-ip" title="<?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>">
                                            <?= h(report_cell($r['DeviceIPAddress'] ?? '')) ?>
                                        </td>

                                        <td class="col-date" title="<?= h(report_cell($r['IssuedAt'] ?? '')) ?>">
                                            <?= h(report_cell($r['IssuedAt'] ?? '')) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="small text-muted mt-2">
                    Showing page <?= number_format($page) ?> of <?= number_format($totalPages) ?>.
                    Receiver scan status, quantity, barcode user, and scanned date are read from the WHPOKAYOKE cache, with missing or stale rows refreshed from SAP.
                </div>

                <?php if (!$export && $totalPages > 1): ?>
                    <nav class="mt-3" aria-label="Issuer scan report pages">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => max(1, $page - 1)])) ?>">Previous</a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => $p])) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(issuer_scan_report_url($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const searchInput = document.getElementById('searchReport');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#reportTable tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

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
