<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/scanplus_lookup.php';
require_login();

function dashboard_log_time($label, $start)
{
    error_log('[Verification Dashboard] ' . $label . ': ' . round((microtime(true) - $start), 3) . ' sec');
}

$conn = get_whpokayoke_connection();

$status = trim($_GET['status'] ?? '');
$limit = 100;

$where = '';
$params = [];

if ($status !== '') {
    $where = 'WHERE L.VerificationStatus = ?';
    $params = [$status];
}

$t = microtime(true);

$summary = fetch_all(
    $conn,
    "SELECT VerificationStatus, COUNT(*) Cnt
     FROM RawmatTraceLines
     GROUP BY VerificationStatus
     ORDER BY VerificationStatus"
);

dashboard_log_time('summary query', $t);

$t = microtime(true);

$hasScanPlusCache = scanplus_cache_ensure($conn);
$hasScanPlusReceivedLotColumn = $hasScanPlusCache
    && scanplus_has_column($conn, 'RawmatTraceScanPlusCache', 'ReceivedLotNo');

dashboard_log_time('cache table check', $t);

$scanPlusLotExpr = $hasScanPlusReceivedLotColumn
    ? "ISNULL(NULLIF(SP.ReceivedLotNo, ''), ISNULL(SP.CacheLotNo, ''))"
    : "ISNULL(SP.CacheLotNo, '')";

$scanPlusReceivedLotSelect = $hasScanPlusReceivedLotColumn
    ? 'T.ReceivedLotNo'
    : "CAST('' AS NVARCHAR(80))";

$scanPlusSelect = $hasScanPlusCache
    ? ",
        ISNULL(SP.ScanStatus, '') AS ScanPlusStatus,
        {$scanPlusLotExpr} AS ScanPlusLotNo,
        ISNULL(CONVERT(varchar(50), SP.ReceivedQty), '') AS ScanPlusQty,
        ISNULL(SP.BarcodeUser, '') AS BarcodeUser,
        ISNULL(CONVERT(varchar(19), SP.ReceivedAt, 120), '') AS ScanPlusAt"
    : ",
        CAST('' AS varchar(50)) AS ScanPlusStatus,
        CAST('' AS varchar(80)) AS ScanPlusLotNo,
        CAST('' AS varchar(50)) AS ScanPlusQty,
        CAST('' AS varchar(100)) AS BarcodeUser,
        CAST('' AS varchar(50)) AS ScanPlusAt";

$scanPlusApply = $hasScanPlusCache
    ? "OUTER APPLY (
        SELECT TOP 1
            T.ScanStatus,
            {$scanPlusReceivedLotSelect} AS ReceivedLotNo,
            T.LotNo AS CacheLotNo,
            T.ReceivedQty,
            T.BarcodeUser,
            T.ReceivedAt
        FROM RawmatTraceScanPlusCache T
        WHERE T.SAP_IT_DocEntry = ISNULL(L.SAP_IT_DocEntry, H.SAP_IT_DocEntry)
          AND (
                T.SAP_IT_LineNum = L.SAP_IT_LineNum
                OR (T.SAP_IT_LineNum IS NULL AND L.SAP_IT_LineNum IS NULL)
              )
          AND T.ItemCode = L.ItemCode
          AND (
                T.LotNo = L.LotNo
                OR T.LotNo IS NULL
                OR T.LotNo = ''
              )
        ORDER BY
            CASE WHEN T.LotNo = L.LotNo THEN 0 ELSE 1 END,
            T.LastSyncedAt DESC
     ) SP"
    : "";

$t = microtime(true);

$mainRowsSql = "SELECT TOP {$limit}
        H.TraceNo,
        H.ITRNumber,
        H.Status HeaderStatus,
        H.SAP_IT_DocEntry AS HeaderSAPDocEntry,
        H.CreatedByUsername,
        H.CreatedAt,
        L.SAP_IT_DocEntry AS LineSAPDocEntry,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.LotNo,
        L.IssuedQty,
        L.ReceivedLotNo,
        L.ReceivedQty,
        L.VarianceQty,
        L.EntryMethod,
        L.VerificationStatus,
        L.ReceivedByUsername,
        L.ReceivedAt
        {$scanPlusSelect}
     FROM RawmatTraceHeader H
     INNER JOIN RawmatTraceLines L ON H.TraceID = L.TraceID
     {$scanPlusApply}
     $where
     ORDER BY H.CreatedAt DESC, L.TraceLineID DESC";

$mainRowsStmt = sqlsrv_query($conn, $mainRowsSql, $params);
$dashboardQueryError = '';
$rows = [];

if ($mainRowsStmt === false) {
    $dashboardQueryError = sqlsrv_fail_message();
    error_log('[Verification Dashboard] main rows query failed: ' . $dashboardQueryError);
} else {
    while ($row = sqlsrv_fetch_array($mainRowsStmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
}

dashboard_log_time('main rows query with local SAP cache', $t);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
$totalRows = count($rows);
$totalSummaryRows = 0;
foreach ($summary as $summaryRow) {
    $totalSummaryRows += (int)($summaryRow['Cnt'] ?? 0);
}

$baseDashboardUrl = 'pages/dashboard/verification_dashboard.php';
$clearUrl = $baseDashboardUrl;
?>
<!doctype html>
<html lang="en">
<head>
    <title>Verification Dashboard</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sap-shell: #354a5f;
            --sap-shell-dark: #233545;
            --sap-accent: #0a6ed1;
            --sap-accent-hover: #085caf;
            --sap-highlight: #d1e8ff;
            --sap-bg: #f7f7f7;
            --sap-card: #ffffff;
            --sap-border: #d9d9d9;
            --sap-border-soft: #ebebeb;
            --sap-text: #32363a;
            --sap-muted: #6a6d70;
            --sap-success-bg: #f1fdf6;
            --sap-success: #107e3e;
            --sap-error-bg: #fff1f1;
            --sap-error: #bb0000;
            --sap-warning-bg: #fff8e6;
            --sap-warning: #b06000;
            --side-width: 17rem;
            --topbar-height: 3.25rem;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--sap-bg);
            color: var(--sap-text);
            font-family: "72", "Segoe UI", Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        .sap-shellbar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 1040;
            min-height: var(--topbar-height);
            background: linear-gradient(90deg, var(--sap-shell-dark), var(--sap-shell));
            color: #fff;
            display: flex;
            align-items: center;
            gap: .85rem;
            padding: .45rem 1rem;
            box-shadow: 0 .125rem .5rem rgba(0, 0, 0, .22);
        }

        .shell-menu-btn {
            display: none;
            border: 0;
            background: rgba(255, 255, 255, .12);
            color: #fff;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .375rem;
            font-size: 1.25rem;
            line-height: 1;
        }

        .shell-logo {
            width: 2.4rem;
            height: 2.4rem;
            border-radius: .35rem;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 auto;
            padding: .15rem;
            margin-right: .25rem;
        }

        .shell-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .shell-title-wrap {
            min-width: 0;
            flex: 1;
        }

        .shell-title {
            font-size: .98rem;
            font-weight: 800;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .shell-subtitle {
            font-size: .74rem;
            color: rgba(255, 255, 255, .85);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-layout {
            min-height: 100vh;
            padding-top: var(--topbar-height);
        }

        .sap-side-nav {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            width: var(--side-width);
            z-index: 1035;
            background: #fff;
            border-right: 1px solid var(--sap-border);
            display: flex;
            flex-direction: column;
            box-shadow: .125rem 0 .5rem rgba(0, 0, 0, .08);
        }

        .side-nav-header {
            padding: 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fff;
        }

        .side-nav-eyebrow {
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .side-nav-title {
            margin-top: .25rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--sap-text);
        }

        .sap-nav-menu {
            flex: 1;
            overflow-y: auto;
            padding: .5rem;
        }

        .sap-nav-section {
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: .875rem .625rem .375rem;
            letter-spacing: .045em;
        }

        .sap-nav-link {
            min-height: 2.5rem;
            display: flex;
            align-items: center;
            gap: .625rem;
            color: var(--sap-text);
            text-decoration: none;
            border-radius: .375rem;
            padding: .5rem .625rem;
            font-size: .875rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .sap-nav-link:hover {
            background: #f5f6f7;
            border-color: #e5e5e5;
            color: var(--sap-accent);
        }

        .sap-nav-link.active {
            background: var(--sap-highlight);
            border-color: #8fc7ff;
            color: #074f91;
        }

        .sap-nav-icon {
            width: 1.375rem;
            text-align: center;
            font-size: 1rem;
            flex: 0 0 auto;
        }

        .sap-side-footer {
            padding: .75rem;
            border-top: 1px solid var(--sap-border-soft);
            background: #fbfbfb;
        }

        .side-user-card {
            display: flex;
            align-items: center;
            gap: .625rem;
            padding: .625rem;
            margin-bottom: .625rem;
            border: 1px solid var(--sap-border-soft);
            background: #fff;
            border-radius: .5rem;
            min-width: 0;
        }

        .side-user-avatar {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: #91c8f6;
            color: #0b2948;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .side-user-details {
            min-width: 0;
            flex: 1;
        }

        .side-user-name,
        .side-user-role {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .side-user-name {
            color: var(--sap-text);
            font-size: .875rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .side-user-role {
            margin-top: .125rem;
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .logout-link {
            width: 100%;
            min-height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ffb8b8;
            color: var(--sap-error);
            background: #fff;
            text-decoration: none;
            border-radius: .375rem;
            font-size: .875rem;
            font-weight: 700;
        }

        .logout-link:hover {
            background: var(--sap-error-bg);
            color: var(--sap-error);
        }

        .main-content {
            margin-left: var(--side-width);
            min-height: calc(100vh - var(--topbar-height));
        }

        .sap-page-header {
            background: linear-gradient(180deg, #eff6ff 0%, #f7f7f7 100%);
            border-bottom: 1px solid var(--sap-border-soft);
            padding: 1.25rem 1.5rem 1rem;
        }

        .page-kicker {
            color: var(--sap-muted);
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: .25rem;
        }

        .page-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .page-title {
            margin: 0;
            color: var(--sap-text);
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 700;
            letter-spacing: -.015em;
        }

        .page-subtitle {
            margin-top: .375rem;
            color: var(--sap-muted);
            font-size: .875rem;
        }

        .sap-page-body {
            padding: 1rem 1.5rem 1.5rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: .75rem;
            margin-bottom: 1rem;
        }

        .summary-card {
            display: block;
            color: inherit;
            text-decoration: none;
            min-width: 0;
        }

        .summary-box {
            min-height: 6rem;
            background: var(--sap-card);
            border: 1px solid var(--sap-border-soft);
            border-radius: .5rem;
            padding: .875rem 1rem;
            box-shadow: 0 .125rem .375rem rgba(0, 0, 0, .04);
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .summary-box:hover {
            transform: translateY(-1px);
            border-color: #8fc7ff;
            box-shadow: 0 .25rem .75rem rgba(10, 110, 209, .12);
        }

        .summary-box.active-filter {
            border-color: var(--sap-accent);
            box-shadow: 0 0 0 .125rem rgba(10, 110, 209, .2);
        }

        .summary-label {
            color: var(--sap-muted);
            font-size: .625rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            line-height: 1.25;
            word-break: break-word;
        }

        .summary-count {
            margin-top: .45rem;
            color: var(--sap-text);
            font-size: 1.75rem;
            line-height: 1;
            font-weight: 800;
        }

        .sap-card {
            background: var(--sap-card);
            border: 1px solid var(--sap-border);
            border-radius: .5rem;
            box-shadow: 0 .125rem .5rem rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        .sap-card-header {
            min-height: 3.25rem;
            padding: .875rem 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fff;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
        }

        .sap-card-title {
            margin: 0;
            color: var(--sap-text);
            font-size: 1rem;
            font-weight: 700;
        }

        .sap-card-subtitle {
            margin-top: .1875rem;
            color: var(--sap-muted);
            font-size: .8125rem;
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .75rem 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fbfbfb;
        }

        .table-search {
            max-width: 25rem;
            min-height: 2.375rem;
            border-radius: .25rem;
            border-color: #89919a;
            color: var(--sap-text);
            font-size: .875rem;
            background-color: #fff;
        }

        .table-search:hover {
            border-color: var(--sap-accent);
        }

        .table-search:focus {
            border-color: var(--sap-accent);
            box-shadow: 0 0 0 .125rem rgba(10, 110, 209, .22);
        }

        .row-count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 1.75rem;
            padding: .25rem .75rem;
            border-radius: 1rem;
            background: var(--sap-highlight);
            color: #074f91;
            border: 1px solid #8fc7ff;
            font-size: .75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .table-wrap {
            overflow-y: auto;
            overflow-x: hidden;
            max-height: 66vh;
            width: 100%;
        }

        .dashboard-table {
            width: 100%;
            margin: 0;
            table-layout: fixed;
            font-size: .6875rem;
        }

        .dashboard-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f2f2f2;
            color: var(--sap-text);
            border-bottom: 1px solid var(--sap-border);
            font-weight: 800;
            padding: .5rem .35rem;
            white-space: normal;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: .625rem;
            letter-spacing: .01em;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .dashboard-table .th-title {
            display: block;
        }

        .dashboard-table .th-source {
            display: block;
            margin-top: .125rem;
            color: var(--sap-muted);
            font-size: .55rem;
            font-weight: 700;
            text-transform: none;
            letter-spacing: 0;
        }

        .dashboard-table td {
            padding: .5rem .35rem;
            color: var(--sap-text);
            vertical-align: middle;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            overflow-wrap: anywhere;
            line-height: 1.2;
        }

        .dashboard-table th:nth-child(1),
        .dashboard-table td:nth-child(1) { width: 11.5%; }
        .dashboard-table th:nth-child(2),
        .dashboard-table td:nth-child(2) { width: 5.5%; }
        .dashboard-table th:nth-child(3),
        .dashboard-table td:nth-child(3) { width: 6%; }
        .dashboard-table th:nth-child(4),
        .dashboard-table td:nth-child(4) { width: 8%; }
        .dashboard-table th:nth-child(5),
        .dashboard-table td:nth-child(5) { width: 5%; }
        .dashboard-table th:nth-child(6),
        .dashboard-table td:nth-child(6) { width: 5%; }
        .dashboard-table th:nth-child(7),
        .dashboard-table td:nth-child(7) { width: 5%; }
        .dashboard-table th:nth-child(8),
        .dashboard-table td:nth-child(8) { width: 5%; }
        .dashboard-table th:nth-child(9),
        .dashboard-table td:nth-child(9) { width: 5%; }
        .dashboard-table th:nth-child(10),
        .dashboard-table td:nth-child(10) { width: 4%; }
        .dashboard-table th:nth-child(11),
        .dashboard-table td:nth-child(11) { width: 6%; }
        .dashboard-table th:nth-child(12),
        .dashboard-table td:nth-child(12) { width: 6%; }
        .dashboard-table th:nth-child(13),
        .dashboard-table td:nth-child(13) { width: 5%; }
        .dashboard-table th:nth-child(14),
        .dashboard-table td:nth-child(14) { width: 5.5%; }
        .dashboard-table th:nth-child(15),
        .dashboard-table td:nth-child(15) { width: 7%; }

        .dashboard-table tbody tr:hover {
            background: #f5f9ff;
        }

        .dashboard-table a {
            color: var(--sap-accent);
            font-weight: 800;
            text-decoration: none;
        }

        .dashboard-table a:hover {
            color: var(--sap-accent-hover);
            text-decoration: underline;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            max-width: 100%;
            min-height: 1.5rem;
            padding: .1875rem .625rem;
            border-radius: 1rem;
            font-size: .6875rem;
            font-weight: 800;
            border: 1px solid transparent;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-MATCHED .status-badge {
            background: var(--sap-success-bg);
            color: var(--sap-success);
            border-color: #b8e6c9;
        }

        .status-PENDING_RECEIVE .status-badge {
            background: var(--sap-warning-bg);
            color: var(--sap-warning);
            border-color: #ffd580;
        }

        .status-QTY_VARIANCE .status-badge,
        .status-LOT_MISMATCH .status-badge,
        .status-LOT_AND_QTY_VARIANCE .status-badge {
            background: var(--sap-error-bg);
            color: var(--sap-error);
            border-color: #ffc6c6;
        }

        .empty-row {
            padding: 2.5rem 1rem !important;
            color: var(--sap-muted) !important;
            text-align: center;
        }

        .sap-loading {
            color: var(--sap-muted) !important;
            font-style: italic;
        }

        .btn {
            min-height: 2.25rem;
            border-radius: .25rem;
            font-size: .875rem;
            font-weight: 700;
        }

        .btn-primary {
            background: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--sap-accent-hover);
            border-color: var(--sap-accent-hover);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: var(--topbar-height) 0 0 0;
            z-index: 1030;
            background: rgba(0, 0, 0, .38);
        }



        @media (max-width: 1199.98px) {
            .dashboard-table {
                font-size: .625rem;
            }

            .dashboard-table th,
            .dashboard-table td {
                padding: .45rem .25rem;
            }

            .dashboard-table th:nth-child(4),
            .dashboard-table td:nth-child(4),
            .dashboard-table th:nth-child(10),
            .dashboard-table td:nth-child(10),
            .dashboard-table th:nth-child(13),
            .dashboard-table td:nth-child(13),
            .dashboard-table th:nth-child(14),
            .dashboard-table td:nth-child(14),
            .dashboard-table th:nth-child(15),
            .dashboard-table td:nth-child(15) {
                display: none;
            }
        }

        @media (max-width: 1399.98px) {
            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            :root {
                --side-width: 16rem;
            }

            .shell-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .sap-side-nav {
                transform: translateX(-105%);
                transition: transform .2s ease;
            }

            .sap-side-nav.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
            }

            .sap-page-header,
            .sap-page-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .page-title-row {
                flex-direction: column;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .sap-shellbar {
                padding-inline: .75rem;
            }

            .shell-logo {
                width: 2rem;
                height: 2rem;
            }

            .shell-subtitle {
                display: none;
            }

            .sap-page-body {
                padding: .75rem;
            }

            .sap-card-header,
            .table-toolbar {
                padding-left: .75rem;
                padding-right: .75rem;
            }

            .table-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .table-search {
                max-width: none;
                width: 100%;
            }

            .row-count-pill {
                width: fit-content;
            }
        }

        @media (max-width: 575.98px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 1.25rem;
            }

            .sap-page-header {
                padding-top: 1rem;
            }
        }
    </style>
</head>

<body>
<header class="sap-shellbar">
    <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">☰</button>

    <div class="shell-logo" aria-hidden="true">
        <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
    </div>

    <div class="shell-title-wrap">
        <div class="shell-title">NBC Rawmats Traceability</div>
        <div class="shell-subtitle">Administration workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('dashboard'); ?>

    <main class="main-content">
        <section class="sap-page-header">
            <div class="page-kicker">Raw Material Verification</div>
            <div class="page-title-row">
                <div>
                    <h1 class="page-title">Management Verification Dashboard</h1>
                    <div class="page-subtitle">
                        Monitor issued and received raw material verification status.
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <?php if ($status !== ''): ?>
                        <a class="btn btn-outline-secondary" href="<?= h($clearUrl) ?>">
                            Clear Filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="sap-page-body">
            <div class="summary-grid" aria-label="Verification summary">
                <?php foreach ($summary as $s): ?>
                    <?php
                        $summaryStatus = (string)$s['VerificationStatus'];
                        $isActive = $status !== '' && $status === $summaryStatus;
                    ?>
                    <a class="summary-card" href="pages/dashboard/verification_dashboard.php?status=<?= urlencode($summaryStatus) ?>">
                        <div class="summary-box <?= $isActive ? 'active-filter' : '' ?>">
                            <div class="summary-label"><?= h($summaryStatus) ?></div>
                            <div class="summary-count"><?= (int)$s['Cnt'] ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <a class="summary-card" href="pages/dashboard/verification_dashboard.php">
                    <div class="summary-box <?= $status === '' ? 'active-filter' : '' ?>">
                        <div class="summary-label">All Records</div>
                        <div class="summary-count"><?= (int)$totalSummaryRows ?></div>
                    </div>
                </a>
            </div>

            <div class="sap-card">
                <div class="sap-card-header">
                    <div>
                        <h2 class="sap-card-title">Verification Records</h2>
                        <div class="sap-card-subtitle">
                            Showing latest <?= (int)$limit ?> trace line records. SAP data is read from the local cache for faster loading.
                        </div>
                    </div>
                </div>

                <div class="table-toolbar">
                    <input
                        id="search"
                        class="form-control table-search"
                        type="search"
                        placeholder="Search trace, item, lot, status, user..."
                        aria-label="Search dashboard records"
                    >

                    <span class="row-count-pill">
                        <?= (int)$totalRows ?> row(s)
                    </span>
                </div>

                <div class="table-wrap">
                    <table class="table table-hover align-middle dashboard-table" id="dashTable">
                        <thead>
                            <tr>
                                <th scope="col"><span class="th-title">Trace No</span><span class="th-source">WH trace</span></th>
                                <th scope="col"><span class="th-title">SAP Doc</span><span class="th-source">ITR / IT</span></th>
                                <th scope="col"><span class="th-title">SAP Item</span><span class="th-source">ItemCode</span></th>
                                <th scope="col"><span class="th-title">Part Name</span><span class="th-source">SAP item name</span></th>
                                <th scope="col"><span class="th-title">Issue Lot</span><span class="th-source">issued batch</span></th>
                                <th scope="col" class="text-right"><span class="th-title">Issue Qty</span><span class="th-source">issued</span></th>
                                <th scope="col"><span class="th-title">Receive Lot</span><span class="th-source">WH / SAP cache</span></th>
                                <th scope="col" class="text-right"><span class="th-title">Receive Qty</span><span class="th-source">WH / SAP cache</span></th>
                                <th scope="col" class="text-right"><span class="th-title">Qty Gap</span><span class="th-source">issue - receive</span></th>
                                <th scope="col" class="text-center"><span class="th-title">Input</span><span class="th-source">scan/manual</span></th>
                                <th scope="col" class="text-center"><span class="th-title">WH Status</span><span class="th-source">trace line</span></th>
                                <th scope="col" class="text-center"><span class="th-title">SAP Status</span><span class="th-source">receive</span></th>
                                <th scope="col"><span class="th-title">Issuer</span><span class="th-source">created by</span></th>
                                <th scope="col"><span class="th-title">SAP User</span><span class="th-source">barcode scan</span></th>
                                <th scope="col"><span class="th-title">SAP Receive Date</span><span class="th-source">scan date</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($dashboardQueryError !== ''): ?>
                                <tr>
                                    <td colspan="15" class="empty-row text-danger">
                                        Unable to load records. <?= h($dashboardQueryError) ?>
                                    </td>
                                </tr>
                            <?php elseif (empty($rows)): ?>
                                <tr>
                                    <td colspan="15" class="empty-row">
                                        No records found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $receivedLotDisplay = trim((string)($r['ReceivedLotNo'] ?? '')) !== ''
                                            ? $r['ReceivedLotNo']
                                            : ($r['ScanPlusLotNo'] ?? '');
                                        $receivedQtyRaw = trim((string)($r['ReceivedQty'] ?? '')) !== ''
                                            ? $r['ReceivedQty']
                                            : ($r['ScanPlusQty'] ?? '');
                                        $receivedQtyDisplay = ($receivedQtyRaw !== '' && is_numeric($receivedQtyRaw))
                                            ? number_format((float)$receivedQtyRaw, 0, '.', '')
                                            : $receivedQtyRaw;
                                    ?>
                                    <tr>
                                        <td title="<?= h($r['TraceNo']) ?>">
                                            <a href="pages/reports/print_trace.php?trace_no=<?= urlencode($r['TraceNo']) ?>" target="_blank">
                                                <?= h($r['TraceNo']) ?>
                                            </a>
                                        </td>

                                        <td title="<?= h($r['ITRNumber']) ?>">
                                            <?= h($r['ITRNumber']) ?>
                                        </td>

                                        <td title="<?= h($r['ItemCode']) ?>">
                                            <?= h($r['ItemCode']) ?>
                                        </td>

                                        <td title="<?= h($r['PartName']) ?>">
                                            <?= h($r['PartName']) ?>
                                        </td>

                                        <td title="<?= h($r['LotNo']) ?>">
                                            <?= h($r['LotNo']) ?>
                                        </td>

                                        <td class="text-right" title="<?= h($r['IssuedQty']) ?>">
                                            <?= h($r['IssuedQty']) ?>
                                        </td>

                                        <td title="<?= h($receivedLotDisplay) ?>">
                                            <?= h($receivedLotDisplay) ?>
                                        </td>

                                        <td class="text-right" title="<?= h($receivedQtyDisplay) ?>">
                                            <?= h($receivedQtyDisplay) ?>
                                        </td>

                                        <td class="text-right" title="<?= h($r['VarianceQty']) ?>">
                                            <?= h($r['VarianceQty']) ?>
                                        </td>

                                        <td class="text-center" title="<?= h($r['EntryMethod']) ?>">
                                            <?= h($r['EntryMethod']) ?>
                                        </td>

                                        <td class="text-center status-<?= h($r['VerificationStatus']) ?>" title="<?= h($r['VerificationStatus']) ?>">
                                            <span class="status-badge">
                                                <?= h($r['VerificationStatus']) ?>
                                            </span>
                                        </td>

                                        <td class="text-center" title="<?= h($r['ScanPlusStatus'] ?? '') ?>">
                                            <?= h($r['ScanPlusStatus'] ?? '') ?>
                                        </td>

                                        <td title="<?= h($r['CreatedByUsername']) ?>">
                                            <?= h($r['CreatedByUsername']) ?>
                                        </td>

                                        <td title="<?= h($r['BarcodeUser'] ?? '') ?>">
                                            <?= h($r['BarcodeUser'] ?? '') ?>
                                        </td>

                                        <td title="<?= h($r['ScanPlusAt'] ?? '') ?>">
                                            <?= h($r['ScanPlusAt'] ?? '') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<script>
const searchInput = document.getElementById('search');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#dashTable tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}


const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function closeSidebar() {
    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.classList.remove('show');
    }
}

if (sidebarToggle && sidebar && sidebarBackdrop) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
}

document.querySelectorAll('.sap-nav-link').forEach(function (link) {
    link.addEventListener('click', closeSidebar);
});
</script>

</body>
</html>
