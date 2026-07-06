<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
$queuedPrintCount = isset($_GET['print_queued']) ? max(0, (int)$_GET['print_queued']) : 0;
$queuedPrintJob = trim((string)($_GET['print_job'] ?? ''));
$queuedPrintPrinter = trim((string)($_GET['print_printer'] ?? ''));
$queuedPrintTrigger = trim((string)($_GET['print_trigger'] ?? ''));
$defaultPickPrinter = strtolower(trim((string)(defined('PICK_TAG_DEFAULT_PRINTER') ? PICK_TAG_DEFAULT_PRINTER : 'nitto')));
$defaultPickPrinter = $defaultPickPrinter === 'zebra' ? 'zebra' : 'nitto';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Picker - Warehouse</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --shell: #263b4f;
            --shell2: #354f68;
            --accent: #0a6ed1;
            --accent-dark: #085caf;
            --bg: #eef2f7;
            --card: #ffffff;
            --border: #d7e0ec;
            --soft: #f6f8fb;
            --text: #111827;
            --muted: #64748b;
            --success: #047857;
            --danger: #b91c1c;
            --warning: #c2410c;
            --topbar-height: 3.5rem;
            --sidebar-full-width: 250px;
            --sidebar-rail-width: 68px;
            --right-panel-width: clamp(330px, 23vw, 400px);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: #eef2f7;
            color: var(--text);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 15px;
            line-height: 1.45;
            overflow-x: hidden;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
            padding-top: var(--topbar-height);
        }

        .main-content {
            margin-left: var(--sidebar-rail-width);
            width: calc(100% - var(--sidebar-rail-width));
            min-height: calc(100vh - var(--topbar-height));
            padding: 14px 16px;
            overflow-x: hidden;
        }

        .sap-shellbar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 1040;
            min-height: var(--topbar-height);
            background: linear-gradient(90deg, var(--shell), var(--shell2));
            color: #fff;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 8px 18px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .22);
        }

        .shell-menu-btn {
            display: none;
            border: 0;
            background: rgba(255, 255, 255, .14);
            color: #fff;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            font-size: 23px;
            line-height: 1;
        }

        .shell-logo {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 auto;
            padding: 3px;
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
            font-size: 16px;
            font-weight: 900;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .shell-subtitle {
            font-size: 12px;
            color: rgba(255, 255, 255, .86);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .sidebar,
        .sap-side-nav {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            width: var(--sidebar-rail-width);
            z-index: 1035;
            background: #fff;
            border-right: 1px solid var(--border);
            box-shadow: 4px 0 16px rgba(15, 23, 42, .08);
            overflow-x: hidden;
            transition: width .18s ease, box-shadow .18s ease, transform .2s ease;
        }

        body.sidebar-rail-mode .sidebar:hover,
        body.sidebar-rail-mode .sidebar:focus-within,
        body.sidebar-rail-mode .sap-side-nav:hover,
        body.sidebar-rail-mode .sap-side-nav:focus-within {
            width: var(--sidebar-full-width);
            z-index: 1050;
            box-shadow: 12px 0 34px rgba(15, 23, 42, .22);
        }

        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-title,
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-subtitle,
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-section,
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .user-box,
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .logout-link,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-title,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-subtitle,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-section,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .user-box,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .logout-link {
            display: none !important;
        }

        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-link,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-link {
            width: 48px !important;
            height: 48px !important;
            padding: 0 !important;
            margin: 0 auto 8px !important;
            justify-content: center !important;
            gap: 0 !important;
            font-size: 0 !important;
        }

        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-icon,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-icon {
            font-size: 18px !important;
            width: 24px !important;
            min-width: 24px !important;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: var(--topbar-height) 0 0 0;
            z-index: 1030;
            background: rgba(15, 23, 42, .45);
        }

        .mobile-topbar {
            display: none;
        }

        .picker-page {
            max-width: none;
            margin: 0;
        }

        .picker-hero {
            background: #ffffff;
            color: var(--text);
            border: 1px solid var(--border);
            border-left: 6px solid var(--accent);
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
            padding: 12px 14px;
            margin-bottom: 12px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
            overflow: hidden;
            position: relative;
        }

        .hero-kicker {
            color: var(--accent);
            font-size: 10px;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 900;
            margin-bottom: 2px;
        }

        .page-title {
            color: var(--text);
            margin: 0;
            font-size: 21px;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.03em;
        }

        .page-subtitle {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 7px 13px;
            border-radius: 999px;
            background: #eff6ff;
            color: #075985;
            border: 1px solid #bfdbfe;
            font-size: 14px;
            font-weight: 950;
            white-space: nowrap;
        }

        .picker-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) var(--right-panel-width);
            gap: 14px;
            align-items: start;
        }

        .picker-main,
        .picker-side {
            min-width: 0;
        }

        .picker-side {
            position: sticky;
            top: calc(var(--topbar-height) + 14px);
        }

        .content-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .055);
            overflow: hidden;
        }

        .content-card-header {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            background: #fbfdff;
        }

        .content-card-title {
            font-size: 16px;
            font-weight: 950;
            color: var(--text);
            margin: 0;
            letter-spacing: -.02em;
        }

        .content-card-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .content-card-body {
            padding: 12px 14px;
        }

        .selected-strip {
            border: 1px solid #a5f3fc;
            background: #ecfeff;
            color: #164e63;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .form-control {
            min-height: 38px;
            border-radius: 8px;
            border: 1px solid #aebbc9;
            color: var(--text);
            font-size: 13px;
            background-color: #fff;
        }

        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(10, 110, 209, .14);
        }

        .btn {
            min-height: 38px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 850;
            padding-left: 14px;
            padding-right: 14px;
        }

        .btn-sm {
            min-height: 34px;
            font-size: 12px;
            padding: 6px 10px;
        }

        .btn-primary,
        .btn-success {
            background: var(--accent);
            border-color: var(--accent);
        }

        .btn-primary:hover,
        .btn-success:hover {
            background: var(--accent-dark);
            border-color: var(--accent-dark);
        }

        .item-search-bar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }

        .item-search-input-wrap {
            position: relative;
            min-width: 0;
        }

        .item-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 15px;
            pointer-events: none;
        }

        .item-search-input {
            padding-left: 38px;
            background: #fff;
            min-height: 44px;
            font-weight: 700;
        }

        .item-search-count {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            background: #f1f5f9;
            border: 1px solid #dbe5f0;
            color: #334155;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .picker-table-wrap {
            width: 100%;
            max-height: calc(100vh - 282px);
            min-height: 360px;
            overflow: auto;
            border: 1px solid #cfd9e6;
            border-radius: 12px;
            background: #fff;
            scrollbar-width: thin;
        }

        .picker-table {
            width: 100%;
            min-width: 1180px;
            margin: 0;
            table-layout: fixed;
            font-size: 12px;
        }

        .picker-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #eaf0f7;
            color: #1f2937;
            border-bottom: 1px solid #cfd9e6;
            font-weight: 950;
            padding: 9px 8px;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .02em;
            white-space: normal;
            line-height: 1.15;
        }

        .picker-table td {
            padding: 8px 8px;
            color: var(--text);
            vertical-align: middle;
            border-color: #e5eaf2;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.25;
        }

        .picker-table tbody tr:nth-child(odd) td {
            background: #ffffff;
        }

        .picker-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .picker-table tbody tr:hover td {
            background: #f0f7ff;
        }

        .col-no {
            width: 58px;
            text-align: center;
            font-weight: 950;
        }

        .col-item {
            width: 132px;
            font-weight: 950;
        }

        .col-part {
            width: 220px;
        }

        .col-qty {
            width: 135px;
        }

        .col-uom {
            width: 70px;
        }

        .col-lot {
            width: 170px;
        }

        .col-itr {
            width: 150px;
        }

        .col-payload {
            width: 170px;
        }

        .col-action {
            width: 135px;
            text-align: center;
        }

        .table-input {
            width: 100%;
            min-width: 0;
            height: 38px;
            font-size: 13px;
            padding: 6px 8px;
            border-radius: 8px;
            font-weight: 800;
            background: #fff;
            border: 1px solid #aebbc9;
        }

        .payload-preview {
            font-size: 10px;
            color: #64748b;
            word-break: break-all;
            line-height: 1.25;
        }

        .print-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
        }

        .print-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn-group .btn {
            min-height: 38px;
        }

        .documents-card {
            position: sticky;
            top: calc(var(--topbar-height) + 14px);
            max-height: calc(100vh - var(--topbar-height) - 28px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .documents-card .content-card-header {
            background: #ffffff;
        }

        .documents-card .content-card-body {
            min-height: 0;
            overflow: hidden;
        }

        .source-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            border-bottom: 0;
            margin-top: 12px;
        }

        .source-tabs .nav-item {
            width: 100%;
            min-width: 0;
        }

        .source-tabs .nav-link {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #1f2937;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 950;
            padding: 8px;
            min-height: 42px;
        }

        .source-tabs .nav-link.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: 0 8px 16px rgba(10, 110, 209, .20);
        }

        .source-tabs .nav-link.active .badge {
            color: #075985 !important;
            background: #fff !important;
        }

        .tab-content {
            min-height: 0;
        }

        .request-list {
            max-height: calc(100vh - 310px);
            overflow-y: auto;
            padding-right: 4px;
            scrollbar-width: thin;
        }

        .request-card {
            width: 100%;
            border: 1px solid #d6e0ec;
            background: #ffffff;
            border-radius: 12px;
            padding: 12px;
            text-align: left;
            margin-bottom: 10px;
            color: var(--text);
            box-shadow: none;
            transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }

        .request-card:hover {
            transform: translateY(-1px);
            border-color: #93c5fd;
            background: #f8fbff;
            box-shadow: 0 8px 20px rgba(10, 110, 209, .10);
        }

        .request-card.active {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(10, 110, 209, .13);
        }

        .request-title {
            font-size: 14px;
            font-weight: 950;
            color: #0f172a;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .request-meta {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
            line-height: 1.35;
        }

        .qty-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            margin-top: 10px;
        }

        .qty-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 7px;
            min-width: 0;
        }

        .qty-box .label {
            font-size: 9px;
            color: #64748b;
            font-weight: 900;
            text-transform: uppercase;
        }

        .qty-box .value {
            font-size: 12px;
            font-weight: 950;
            color: #0f172a;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .po-toolbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            margin-bottom: 10px;
        }

        .alert {
            border-radius: 14px;
        }

        .modal-content {
            border-radius: 18px;
            border-color: var(--border);
        }

        .modal-title {
            font-weight: 950;
        }

        /*
            IMPORTANT:
            Tablet WebView usually reports width around 1024px to 1280px.
            Do NOT stack the layout at 1366px.
            Keep the same desktop layout for tablet.
        */
        @media (max-width: 1366px) {
            :root {
                --right-panel-width: clamp(330px, 23vw, 400px);
            }

            .main-content {
                padding: 14px 16px !important;
                overflow-x: auto !important;
            }

            .picker-page {
                min-width: 1180px !important;
            }

            .picker-workspace {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) var(--right-panel-width) !important;
                gap: 14px !important;
                align-items: start !important;
            }

            .picker-side {
                position: sticky !important;
                top: calc(var(--topbar-height) + 14px) !important;
                grid-row: auto !important;
            }

            .picker-main {
                grid-row: auto !important;
            }

            .documents-card {
                position: sticky !important;
                top: calc(var(--topbar-height) + 14px) !important;
                max-height: calc(100vh - var(--topbar-height) - 28px) !important;
                display: flex !important;
                flex-direction: column !important;
            }

            .documents-card .content-card-body {
                min-height: 0 !important;
                overflow: hidden !important;
            }

            .documents-card .tab-content {
                display: block !important;
            }

            .documents-card .tab-pane {
                display: none !important;
                opacity: 0 !important;
                visibility: hidden !important;
            }

            .documents-card .tab-pane.active,
            .documents-card .tab-pane.show {
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .source-tabs {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
            }

            .request-list {
                max-height: calc(100vh - 310px) !important;
                overflow-y: auto !important;
            }

            .picker-table-wrap {
                max-height: calc(100vh - 282px) !important;
                min-height: 360px !important;
                overflow: auto !important;
            }

            .picker-table {
                min-width: 1180px !important;
                table-layout: fixed !important;
            }

            .col-payload {
                display: table-cell !important;
            }
        }

        @media (max-width: 900px) {
            .shell-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .app-layout {
                padding-top: var(--topbar-height);
            }

            .sidebar,
            .sap-side-nav {
                transform: translateX(-105%);
                width: min(82vw, 270px);
            }

            .sidebar.show,
            .sap-side-nav.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 12px;
                overflow-x: auto;
            }

            .mobile-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #fff;
                border: 1px solid var(--border);
                border-radius: 14px;
                padding: 12px 14px;
                margin-bottom: 12px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
            }

            .picker-page {
                min-width: 1180px;
            }

            .picker-hero {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .picker-workspace {
                grid-template-columns: minmax(0, 1fr) var(--right-panel-width);
            }

            .picker-side {
                position: sticky;
            }

            .picker-table-wrap {
                max-height: calc(100vh - 282px);
                min-height: 360px;
            }

            .request-list {
                max-height: calc(100vh - 310px);
            }

            .print-toolbar {
                align-items: center;
            }

            .print-actions {
                justify-content: flex-end;
            }
        }

        @media (max-width: 575.98px) {
            .main-content {
                padding: 10px;
                overflow-x: auto;
            }

            .picker-page {
                min-width: 1180px;
            }

            .picker-hero {
                border-radius: 15px;
            }

            .content-card {
                border-radius: 15px;
            }

            .content-card-header,
            .content-card-body {
                padding: 12px;
            }

            .source-tabs {
                grid-template-columns: 1fr 1fr;
            }

            .po-toolbar {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .qty-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>

<body class="sidebar-rail-mode">
<header class="sap-shellbar">
    <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">&#9776;</button>

    <div class="shell-logo" aria-hidden="true">
        <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
    </div>

    <div class="shell-title-wrap">
        <div class="shell-title">NBC Rawmats Traceability</div>
        <div class="shell-subtitle">Picker workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('picker'); ?>

    <main class="main-content">
        <div class="mobile-topbar">
            <strong>Picker Warehouse</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggleMobile">Menu</button>
        </div>

        <div class="picker-page">
            <section class="picker-hero">
                <div>
                    <div class="hero-kicker">Warehouse Picker Console</div>
                    <h1 class="page-title">Pick Tag Printing</h1>
                    <div class="page-subtitle">
                        Load a request or open PO, enter actual lot details, split quantities when needed, then print pick tags for issuer scanning.
                    </div>
                </div>

                <div class="hero-actions">
                    <span class="count-pill" id="countBadge">0 tag(s)</span>
                </div>
            </section>

            <?php if ($queuedPrintCount > 0): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <strong><?= h($queuedPrintCount) ?> picker tag(s) queued.</strong>
                        <?php if ($queuedPrintPrinter !== ''): ?>
                            <span>Printer: <?= h($queuedPrintPrinter) ?>.</span>
                        <?php endif; ?>
                        <?= h($queuedPrintTrigger ?: 'The print worker was started.') ?>
                        <?php if ($queuedPrintJob !== ''): ?>
                            <span class="small text-muted">Job <?= h($queuedPrintJob) ?></span>
                        <?php endif; ?>
                    </div>

                    <a class="btn btn-sm btn-outline-success" href="<?= h(app_path('pages/picker/picker.php')) ?>">Dismiss</a>
                </div>
            <?php endif; ?>

            <div class="alert alert-success d-none" id="printQueueAlert"></div>

            <div class="picker-workspace">
                <section class="picker-main">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Pick Tag Details</h5>
                            <div class="content-card-subtitle">
                                The barcode scans the PO/ITR number. The QR scans as (01)SAP ItemCode(17)Qty(10)Lot No.
                            </div>
                        </div>

                        <div class="content-card-body">
                            <div id="selectedRequestBox" class="selected-strip d-none">
                                <div class="d-flex justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-bold" id="selectedRequestTitle"></div>
                                        <div class="small" id="selectedRequestDetails"></div>
                                    </div>

                                    <button class="btn btn-sm btn-outline-secondary align-self-start" type="button" onclick="clearSelectedRequest()">
                                        Clear
                                    </button>
                                </div>
                            </div>

                            <div class="item-search-bar">
                                <div class="item-search-input-wrap">
                                    <span class="item-search-icon">⌕</span>
                                    <input
                                        class="form-control item-search-input"
                                        id="pickItemSearchInput"
                                        placeholder="Search table: item code, part name, lot, request, ITR, QR payload..."
                                        oninput="filterPickTable()"
                                    >
                                </div>

                                <span class="item-search-count" id="pickItemSearchCount">0 shown</span>
                            </div>

                            <div class="picker-table-wrap">
                                <table class="table table-bordered table-striped align-middle picker-table" id="pickTable">
                                    <thead>
                                        <tr>
                                            <th class="col-no">No.</th>
                                            <th class="col-item">SAP ItemCode</th>
                                            <th class="col-part">Part Name</th>
                                            <th class="col-qty">Qty</th>
                                            <th class="col-uom">UOM</th>
                                            <th class="col-lot">Actual Lot No</th>
                                            <th class="col-itr">Request / ITR</th>
                                            <th class="col-payload">QR Payload</th>
                                            <th class="col-action"></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                            <div class="print-toolbar">
                                <div class="btn-group" role="group" aria-label="Pick tag printer">
                                    <input class="btn-check" type="radio" name="pickPrinter" id="pickPrinterNitto" value="nitto" autocomplete="off" <?= $defaultPickPrinter === 'nitto' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="pickPrinterNitto">Nitto</label>

                                    <input class="btn-check" type="radio" name="pickPrinter" id="pickPrinterZebra" value="zebra" autocomplete="off" <?= $defaultPickPrinter === 'zebra' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="pickPrinterZebra">Zebra QLn320</label>
                                </div>

                                <div class="print-actions">
                                    <button class="btn btn-outline-secondary" type="button" onclick="clearPickedLots()">Clear Lots</button>
                                    <button id="printBtn" class="btn btn-success" type="button" onclick="printTags()" disabled>Print Pick Tags</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <aside class="picker-side">
                    <div class="content-card documents-card">
                        <div class="content-card-header">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="hero-kicker text-primary">Open Documents</div>
                                    <h5 class="content-card-title">Requests & POs</h5>
                                    <div class="content-card-subtitle">Select a document to load pick tag lines.</div>
                                </div>

                                <button class="btn btn-sm btn-outline-primary" type="button" onclick="refreshOpenDocuments()">
                                    Refresh
                                </button>
                            </div>

                            <ul class="nav source-tabs" id="pickerSourceTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link active"
                                        id="requestsTab"
                                        data-bs-toggle="tab"
                                        data-bs-target="#requestsPane"
                                        type="button"
                                        role="tab"
                                        aria-controls="requestsPane"
                                        aria-selected="true"
                                    >
                                        Requests
                                        <span class="badge text-bg-light ms-1" id="requestCount">0</span>
                                    </button>
                                </li>

                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link"
                                        id="purchaseOrdersTab"
                                        data-bs-toggle="tab"
                                        data-bs-target="#purchaseOrdersPane"
                                        type="button"
                                        role="tab"
                                        aria-controls="purchaseOrdersPane"
                                        aria-selected="false"
                                    >
                                        Open POs
                                        <span class="badge text-bg-light ms-1" id="poCount">0</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="content-card-body">
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="requestsPane" role="tabpanel" aria-labelledby="requestsTab" tabindex="0">
                                    <div class="small text-muted mb-2" id="requestStatus">Loading requests...</div>
                                    <div id="requestList" class="request-list"></div>
                                </div>

                                <div class="tab-pane fade" id="purchaseOrdersPane" role="tabpanel" aria-labelledby="purchaseOrdersTab" tabindex="0">
                                    <div class="po-toolbar">
                                        <input class="form-control form-control-sm" id="poSearchInput" placeholder="Search PO, vendor, item" oninput="queuePurchaseOrderSearch()">
                                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadOpenPurchaseOrders()">Search</button>
                                    </div>

                                    <div class="small text-muted mb-2" id="poStatus">Loading open purchase orders...</div>
                                    <div id="poList" class="request-list"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="breakdownModal" tabindex="-1" aria-labelledby="breakdownModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="breakdownModalTitle">Lot Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-light border small" id="breakdownLineInfo"></div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold" for="breakdownLot">Lot No</label>
                        <input class="form-control" id="breakdownLot" placeholder="Actual lot">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold" for="breakdownTotalQty">Total Qty</label>
                        <input class="form-control" id="breakdownTotalQty" type="number" min="0.001" step="0.001">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold" for="breakdownBoxQty">Box Qty</label>
                        <input class="form-control" id="breakdownBoxQty" type="number" min="0" step="0.001" placeholder="Example 1000">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold" for="breakdownPackQty">Qty Per Pack / Tag</label>
                        <input class="form-control" id="breakdownPackQty" type="number" min="0.001" step="0.001" placeholder="Example 100">
                    </div>
                </div>

                <div class="small text-muted mt-2" id="breakdownPreview"></div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="button" onclick="applyBreakdown()">Create Tag Lines</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    document.body.classList.add('sidebar-rail-mode');
})();
</script>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/app-refresh.js"></script>

<script>
let openDocuments = [];
let openPurchaseOrders = [];
let selectedDocument = null;
let pickItems = [];
let poSearchTimer = null;
let breakdownIdx = null;

function fmtQty(v) {
    const n = Number(v || 0);
    return Number.isInteger(n) ? String(n) : n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

function esc(v) {
    return String(v ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[c]));
}

function pickPayload(it) {
    return '(01)' + String(it.item_code || '').trim() + '(17)' + String(it.quantity || '').trim() + '(10)' + String(it.lot_no || '').trim();
}

function getPickItemSearchText(it) {
    return [
        it.item_code,
        it.part_name,
        it.quantity,
        it.uom,
        it.lot_no,
        it.request_no,
        it.itr_number,
        it.itr_doc_num,
        pickPayload(it)
    ].join(' ').toLowerCase();
}

function filterPickTable() {
    syncPickItems();
    renderPickItems();
}

function isPurchaseOrderPickItem(it) {
    return String(it.source_type || '').toLowerCase() === 'purchase_order'
        || String(selectedDocument?.request_id || '').startsWith('PO-');
}

function selectedPickPrinter() {
    return document.querySelector('input[name="pickPrinter"]:checked')?.value || 'nitto';
}

function readyPickItemCount() {
    if (!selectedDocument || !String(selectedDocument.request_id || '').startsWith('PO-')) {
        return pickItems.length;
    }

    return pickItems.filter(it => String(it.lot_no || '').trim() !== '').length;
}

function refreshPickControls() {
    const readyCount = readyPickItemCount();
    const countText = selectedDocument && String(selectedDocument.request_id || '').startsWith('PO-')
        ? readyCount + ' ready / ' + pickItems.length + ' line(s)'
        : pickItems.length + ' tag(s)';

    const countBadge = document.getElementById('countBadge');
    const printBtn = document.getElementById('printBtn');

    if (countBadge) {
        countBadge.textContent = countText;
    }

    if (printBtn) {
        printBtn.disabled = readyCount === 0;
    }
}

async function loadOpenRequests() {
    const status = document.getElementById('requestStatus');

    if (status) {
        status.textContent = 'Refreshing requests...';
    }

    try {
        const res = await fetch('api/get_open_issue_requests.php', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const text = await res.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (jsonError) {
            throw new Error('Invalid response from request API. Please check PHP error log.');
        }

        if (!res.ok || !data.ok) {
            openDocuments = [];
            const requestCount = document.getElementById('requestCount');

            if (requestCount) {
                requestCount.textContent = '0';
            }

            renderRequests();

            if (status) {
                status.textContent = data.message || 'Unable to load requests.';
            }

            return;
        }

        openDocuments = data.documents || [];

        const requestCount = document.getElementById('requestCount');

        if (requestCount) {
            requestCount.textContent = openDocuments.length;
        }

        renderRequests();

        if (status) {
            status.textContent = openDocuments.length + ' open request(s) available.';
        }
    } catch (e) {
        openDocuments = [];

        const requestCount = document.getElementById('requestCount');

        if (requestCount) {
            requestCount.textContent = '0';
        }

        renderRequests();

        if (status) {
            status.textContent = e.message || 'Unable to load requests. Check WHPOKAYOKE connection or login session.';
        }
    }
}

async function loadOpenPurchaseOrders() {
    const status = document.getElementById('poStatus');
    const search = (document.getElementById('poSearchInput')?.value || '').trim();

    if (status) {
        status.textContent = search ? 'Searching open purchase orders...' : 'Open POs load when needed. Use Search or Refresh.';
    }

    if (!search && openPurchaseOrders.length === 0 && document.getElementById('purchaseOrdersTab')?.classList.contains('active') !== true) {
        if (status) {
            status.textContent = 'Open POs are paused until you open this tab or search.';
        }
        return;
    }

    try {
        const url = 'api/picker/open_purchase_orders.php?q=' + encodeURIComponent(search);

        const res = await fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const text = await res.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (jsonError) {
            throw new Error('Invalid response from open PO API. Please check PHP error log.');
        }

        if (!res.ok || !data.ok) {
            openPurchaseOrders = [];
            const poCount = document.getElementById('poCount');

            if (poCount) {
                poCount.textContent = '0';
            }

            renderPurchaseOrders();

            if (status) {
                status.textContent = data.message || 'Unable to load open purchase orders.';
            }

            return;
        }

        openPurchaseOrders = data.documents || [];

        const poCount = document.getElementById('poCount');

        if (poCount) {
            poCount.textContent = openPurchaseOrders.length;
        }

        renderPurchaseOrders();

        if (status) {
            status.textContent = openPurchaseOrders.length + ' open PO(s) found' + (data.limited ? ' (limited)' : '') + '.';
        }
    } catch (e) {
        openPurchaseOrders = [];

        const poCount = document.getElementById('poCount');

        if (poCount) {
            poCount.textContent = '0';
        }

        renderPurchaseOrders();

        if (status) {
            status.textContent = e.message || 'Unable to load open purchase orders. Check SAP connection or login session.';
        }
    }
}

async function refreshOpenDocuments() {
    await loadOpenRequests();

    if (document.getElementById('purchaseOrdersTab')?.classList.contains('active')) {
        await loadOpenPurchaseOrders();
    }
}

function queuePurchaseOrderSearch() {
    if (poSearchTimer) {
        clearTimeout(poSearchTimer);
    }

    poSearchTimer = setTimeout(loadOpenPurchaseOrders, 350);
}

function renderRequests() {
    const list = document.getElementById('requestList');

    if (!list) {
        return;
    }

    list.innerHTML = '';

    if (openDocuments.length === 0) {
        list.innerHTML = '<div class="alert alert-light border">No open issue requests found.</div>';
        return;
    }

    openDocuments.forEach((doc, idx) => {
        const active = selectedDocument && selectedDocument.request_id === doc.request_id ? ' active' : '';

        list.insertAdjacentHTML('beforeend', `
            <button class="request-card${active}" type="button" onclick="loadDocument(${idx})">
                <div class="d-flex justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="request-title">${esc(doc.request_no || doc.doc_num)}</div>
                        <div class="request-meta">ITR ${esc(doc.itr_number || doc.doc_num)} | Needed ${esc(doc.needed_date || doc.doc_date)} | ${esc(doc.line_count)} item(s)</div>
                        <div class="request-meta">By ${esc(doc.requested_by || '')}${doc.remarks ? ' | ' + esc(doc.remarks) : ''}</div>
                    </div>
                    <span class="badge text-bg-primary rounded-pill align-self-start">Load</span>
                </div>

                <div class="qty-grid">
                    <div class="qty-box">
                        <div class="label">Requested</div>
                        <div class="value">${fmtQty(doc.requested_qty)}</div>
                    </div>

                    <div class="qty-box">
                        <div class="label">Remaining</div>
                        <div class="value">${fmtQty(doc.remaining_qty)}</div>
                    </div>

                    <div class="qty-box">
                        <div class="label">WH 01</div>
                        <div class="value">${fmtQty(doc.warehouse_stock_qty)}</div>
                    </div>
                </div>
            </button>
        `);
    });
}

function renderPurchaseOrders() {
    const list = document.getElementById('poList');

    if (!list) {
        return;
    }

    list.innerHTML = '';

    if (openPurchaseOrders.length === 0) {
        list.innerHTML = '<div class="alert alert-light border">No open purchase orders found.</div>';
        return;
    }

    openPurchaseOrders.forEach((doc, docIdx) => {
        const active = selectedDocument && selectedDocument.request_id === 'PO-' + doc.doc_entry ? ' active' : '';

        list.insertAdjacentHTML('beforeend', `
            <button class="request-card${active}" type="button" onclick="loadPurchaseOrderDocument(${docIdx})">
                <div class="d-flex justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="request-title">PO ${esc(doc.doc_num)}</div>
                        <div class="request-meta">Due ${esc(doc.due_date || doc.doc_date)} | ${esc(doc.line_count)} item(s)</div>
                        <div class="request-meta" title="${esc(doc.vendor_name || '')}">${esc(doc.vendor_code || '')}${doc.vendor_name ? ' | ' + esc(doc.vendor_name) : ''}</div>
                    </div>
                    <span class="badge text-bg-secondary rounded-pill align-self-start">${fmtQty(doc.open_qty)}</span>
                </div>

                <div class="qty-grid">
                    <div class="qty-box">
                        <div class="label">Open Items</div>
                        <div class="value">${esc(doc.line_count)}</div>
                    </div>

                    <div class="qty-box">
                        <div class="label">Open Qty</div>
                        <div class="value">${fmtQty(doc.open_qty)}</div>
                    </div>

                    <div class="qty-box">
                        <div class="label">Due</div>
                        <div class="value">${esc(doc.due_date || doc.doc_date)}</div>
                    </div>
                </div>
            </button>
        `);
    });
}

function loadPurchaseOrderDocument(docIdx) {
    const doc = openPurchaseOrders[docIdx];

    if (!doc) {
        return;
    }

    const poNumber = String(doc.doc_num || '').trim();

    selectedDocument = {
        request_id: 'PO-' + (doc.doc_entry || poNumber),
        request_no: 'PO ' + poNumber,
        doc_num: poNumber
    };

    pickItems = (doc.lines || [])
        .filter(line => Number(line.open_qty || 0) > 0)
        .map(line => ({
            item_code: line.item_code,
            part_name: line.part_name || '',
            quantity: line.open_qty,
            requested_qty: line.ordered_qty,
            remaining_qty: line.open_qty,
            lot_no: '',
            request_no: 'PO ' + poNumber,
            request_id: '',
            request_line_id: '',
            itr_number: poNumber,
            itr_doc_entry: line.doc_entry || doc.doc_entry || '',
            itr_doc_num: poNumber,
            itr_line_num: line.line_num || '',
            source_type: 'purchase_order',
            uom: line.uom || '',
            num_per_msr: line.num_per_msr || 1
        }));

    const selectedRequestBox = document.getElementById('selectedRequestBox');
    const selectedRequestTitle = document.getElementById('selectedRequestTitle');
    const selectedRequestDetails = document.getElementById('selectedRequestDetails');

    if (selectedRequestBox) {
        selectedRequestBox.classList.remove('d-none');
    }

    if (selectedRequestTitle) {
        selectedRequestTitle.textContent = 'PO ' + poNumber;
    }

    if (selectedRequestDetails) {
        selectedRequestDetails.textContent = (doc.vendor_name || doc.vendor_code || '') + ' | ' + pickItems.length + ' item line(s).';
    }

    renderRequests();
    renderPurchaseOrders();
    renderPickItems();
}

function loadDocument(idx) {
    const doc = openDocuments[idx];

    if (!doc) {
        return;
    }

    selectedDocument = doc;

    pickItems = (doc.lines || [])
        .filter(line => Number(line.remaining_qty || 0) > 0)
        .map(line => ({
            item_code: line.item_code,
            part_name: line.part_name || '',
            quantity: line.remaining_qty,
            requested_qty: line.requested_qty,
            remaining_qty: line.remaining_qty,
            lot_no: '',
            request_no: line.request_no || doc.request_no || '',
            request_id: line.request_id || '',
            request_line_id: line.request_line_id || '',
            itr_number: line.doc_num || line.itr_number || doc.itr_number || '',
            itr_doc_entry: line.doc_entry || '',
            itr_doc_num: line.doc_num || '',
            itr_line_num: line.line_num || '',
            uom: line.uom || '',
            num_per_msr: line.num_per_msr || 1
        }));

    const selectedRequestBox = document.getElementById('selectedRequestBox');
    const selectedRequestTitle = document.getElementById('selectedRequestTitle');
    const selectedRequestDetails = document.getElementById('selectedRequestDetails');

    if (selectedRequestBox) {
        selectedRequestBox.classList.remove('d-none');
    }

    if (selectedRequestTitle) {
        selectedRequestTitle.textContent = (doc.request_no || 'Request') + ' / ITR ' + (doc.itr_number || doc.doc_num);
    }

    if (selectedRequestDetails) {
        selectedRequestDetails.textContent = doc.line_count + ' item(s), needed ' + (doc.needed_date || doc.doc_date) + ', remaining total ' + fmtQty(doc.remaining_qty);
    }

    renderRequests();
    renderPickItems();
}

function renderPickItems() {
    const tb = document.querySelector('#pickTable tbody');
    const searchInput = document.getElementById('pickItemSearchInput');
    const search = (searchInput?.value || '').trim().toLowerCase();
    const countEl = document.getElementById('pickItemSearchCount');

    if (!tb) {
        return;
    }

    tb.innerHTML = '';

    const visibleRows = pickItems
        .map((it, idx) => ({ it, idx }))
        .filter(row => !search || getPickItemSearchText(row.it).includes(search));

    if (pickItems.length === 0) {
        tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5 fw-bold">No pick tag lines loaded. Select a Request or Open PO from the right panel.</td></tr>';
    } else if (visibleRows.length === 0) {
        tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5 fw-bold">No item found for the current search.</td></tr>';
    }

    visibleRows.forEach(({ it, idx }, displayIdx) => {
        tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="col-no text-center fw-bold">${displayIdx + 1}</td>
                <td class="col-item" title="${esc(it.item_code)}">${esc(it.item_code)}</td>
                <td class="col-part" title="${esc(it.part_name)}">${esc(it.part_name)}</td>

                <td class="col-qty">
                    <input
                        class="form-control form-control-sm table-input"
                        type="number"
                        min="0.001"
                        step="0.001"
                        id="qty_${idx}"
                        value="${esc(it.quantity)}"
                        oninput="updatePickItem(${idx}, 'quantity', this.value)"
                        onchange="updatePickItem(${idx}, 'quantity', this.value)"
                    >
                    <div class="small text-muted">Remaining ${fmtQty(it.remaining_qty)}</div>
                </td>

                <td class="col-uom" title="${esc(it.num_per_msr || '')}">${esc(it.uom || '')}</td>

                <td class="col-lot">
                    <input
                        class="form-control form-control-sm table-input"
                        id="lot_${idx}"
                        value="${esc(it.lot_no)}"
                        placeholder="Enter actual lot"
                        onchange="updatePickItem(${idx}, 'lot_no', this.value)"
                        oninput="updatePickItem(${idx}, 'lot_no', this.value)"
                    >
                </td>

                <td class="col-itr" title="${esc(it.request_no)} / ${esc(it.itr_number)}">
                    ${esc(it.request_no)}<br>
                    <span class="text-muted">${esc(it.itr_number)}</span>
                </td>

                <td class="col-payload">
                    <div class="payload-preview" id="payload_${idx}">${esc(pickPayload(it))}</div>
                </td>

                <td class="col-action">
                    <div class="d-flex gap-1 justify-content-center">
                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openBreakdown(${idx})">Split</button>
                        <button class="btn btn-sm btn-outline-danger" type="button" onclick="removePickItem(${idx})">X</button>
                    </div>
                </td>
            </tr>
        `);
    });

    if (countEl) {
        countEl.textContent = search
            ? visibleRows.length + ' of ' + pickItems.length + ' shown'
            : pickItems.length + ' shown';
    }

    refreshPickControls();
}

function updatePickItem(idx, key, value) {
    if (!pickItems[idx]) {
        return;
    }

    pickItems[idx][key] = value;

    const payload = document.getElementById('payload_' + idx);

    if (payload) {
        payload.textContent = pickPayload(pickItems[idx]);
    }

    refreshPickControls();
}

function syncPickItems() {
    pickItems.forEach((it, idx) => {
        const qty = document.getElementById('qty_' + idx);
        const lot = document.getElementById('lot_' + idx);

        if (qty) {
            it.quantity = qty.value.trim();
        }

        if (lot) {
            it.lot_no = lot.value.trim();
        }
    });
}

function removePickItem(idx) {
    pickItems.splice(idx, 1);
    renderPickItems();
}

function openBreakdown(idx) {
    const it = pickItems[idx];

    if (!it) {
        return;
    }

    breakdownIdx = idx;

    const breakdownLineInfo = document.getElementById('breakdownLineInfo');
    const breakdownLot = document.getElementById('breakdownLot');
    const breakdownTotalQty = document.getElementById('breakdownTotalQty');
    const breakdownBoxQty = document.getElementById('breakdownBoxQty');
    const breakdownPackQty = document.getElementById('breakdownPackQty');

    if (breakdownLineInfo) {
        breakdownLineInfo.textContent =
            it.item_code + ' | ' + (it.part_name || '') + ' | Remaining ' + fmtQty(it.remaining_qty) + (it.uom ? ' ' + it.uom : '');
    }

    if (breakdownLot) {
        breakdownLot.value = it.lot_no || '';
    }

    if (breakdownTotalQty) {
        breakdownTotalQty.value = it.quantity || it.remaining_qty || '';
    }

    if (breakdownBoxQty) {
        breakdownBoxQty.value = '';
    }

    if (breakdownPackQty) {
        breakdownPackQty.value = it.num_per_msr && Number(it.num_per_msr) > 1 ? it.num_per_msr : '';
    }

    updateBreakdownPreview();

    bootstrap.Modal.getOrCreateInstance(document.getElementById('breakdownModal')).show();
}

function updateBreakdownPreview() {
    const total = Number(document.getElementById('breakdownTotalQty')?.value || 0);
    const pack = Number(document.getElementById('breakdownPackQty')?.value || 0);
    const box = Number(document.getElementById('breakdownBoxQty')?.value || 0);
    const preview = document.getElementById('breakdownPreview');

    if (!preview) {
        return;
    }

    if (total <= 0 || pack <= 0) {
        preview.textContent = 'Enter total quantity and quantity per pack/tag.';
        return;
    }

    const fullTags = Math.floor(total / pack);
    const remainder = +(total - (fullTags * pack)).toFixed(3);
    const boxText = box > 0 ? ' | ' + fmtQty(total / box) + ' box(es) at ' + fmtQty(box) : '';

    preview.textContent = fullTags + ' tag(s) at ' + fmtQty(pack) + (remainder > 0 ? ', plus 1 tag at ' + fmtQty(remainder) : '') + boxText + '.';
}

function applyBreakdown() {
    const it = pickItems[breakdownIdx];

    if (!it) {
        return;
    }

    const lot = document.getElementById('breakdownLot')?.value.trim() || '';
    const total = Number(document.getElementById('breakdownTotalQty')?.value || 0);
    const pack = Number(document.getElementById('breakdownPackQty')?.value || 0);

    if (!lot) {
        alert('Lot number is required.');
        return;
    }

    if (total <= 0 || pack <= 0) {
        alert('Total quantity and quantity per pack/tag must be greater than zero.');
        return;
    }

    if (it.remaining_qty && total > Number(it.remaining_qty)) {
        alert('Breakdown total is greater than remaining quantity.');
        return;
    }

    const newItems = [];
    let remaining = total;

    while (remaining > 0.0001) {
        const tagQty = Math.min(pack, remaining);

        newItems.push(Object.assign({}, it, {
            quantity: +tagQty.toFixed(3),
            lot_no: lot,
            breakdown_total_qty: total,
            breakdown_pack_qty: pack
        }));

        remaining = +(remaining - tagQty).toFixed(3);
    }

    pickItems.splice(breakdownIdx, 1, ...newItems);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('breakdownModal')).hide();

    breakdownIdx = null;

    renderPickItems();
}

function clearPickedLots() {
    pickItems.forEach(it => {
        it.lot_no = '';
    });

    renderPickItems();
}

function clearSelectedRequest() {
    selectedDocument = null;
    pickItems = [];

    const itemSearch = document.getElementById('pickItemSearchInput');

    if (itemSearch) {
        itemSearch.value = '';
    }

    const selectedRequestBox = document.getElementById('selectedRequestBox');

    if (selectedRequestBox) {
        selectedRequestBox.classList.add('d-none');
    }

    renderRequests();
    renderPurchaseOrders();
    renderPickItems();
}

function showPrintQueueMessage(message, isError = false) {
    const alertBox = document.getElementById('printQueueAlert');

    if (!alertBox) {
        return;
    }

    alertBox.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
    alertBox.textContent = message;
}

async function printTags() {
    syncPickItems();

    const printable = [];
    const printableIndexes = [];

    for (let idx = 0; idx < pickItems.length; idx++) {
        const it = pickItems[idx];

        if (!it.lot_no) {
            if (isPurchaseOrderPickItem(it)) {
                continue;
            }

            alert('Line ' + (idx + 1) + ' actual lot number is required.');
            return;
        }

        if (!it.quantity || Number(it.quantity) <= 0) {
            alert('Line ' + (idx + 1) + ' quantity must be greater than zero.');
            return;
        }

        if (it.remaining_qty && Number(it.quantity) > Number(it.remaining_qty)) {
            alert('Line ' + (idx + 1) + ' quantity is greater than remaining request qty.');
            return;
        }

        printable.push(Object.assign({}, it, {
            qr_payload: pickPayload(it)
        }));

        printableIndexes.push(idx);
    }

    if (printable.length === 0) {
        alert('Enter an actual lot number for at least one delivered PO item before printing.');
        refreshPickControls();
        return;
    }

    const printBtn = document.getElementById('printBtn');
    const originalText = printBtn ? printBtn.textContent : '';
    const queuedRequestId = selectedDocument ? String(selectedDocument.request_id || '') : '';

    if (printBtn) {
        printBtn.disabled = true;
        printBtn.textContent = 'Queueing...';
    }

    try {
        const body = new FormData();
        body.append('batch_items', JSON.stringify(printable));
        body.append('pick_printer', selectedPickPrinter());

        const res = await fetch('actions/print_pick_tags.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body
        });

        const text = await res.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (jsonError) {
            throw new Error('Invalid response from print action. Please check PHP error log.');
        }

        if (!res.ok || !data.ok) {
            throw new Error(data.message || 'Unable to queue pick tags.');
        }

        showPrintQueueMessage(
            data.queued + ' picker tag(s) queued. ' +
            (data.printer_name ? 'Printer: ' + data.printer_name + '. ' : '') +
            (data.trigger_message || 'You can continue picking.') +
            (data.job_id ? ' Job ' + data.job_id + '.' : '')
        );

        if (queuedRequestId === String(selectedDocument?.request_id || '')) {
            printableIndexes
                .sort((a, b) => b - a)
                .forEach(idx => pickItems.splice(idx, 1));

            if (pickItems.length === 0) {
                clearSelectedRequest();
            } else {
                renderPickItems();
            }
        }
    } catch (e) {
        showPrintQueueMessage(e.message || 'Unable to queue pick tags.', true);
        refreshPickControls();
    } finally {
        if (printBtn) {
            printBtn.textContent = originalText || 'Print Pick Tags';
            refreshPickControls();
        }
    }
}

const sidebar = document.getElementById('sidebar');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');

function openSidebar() {
    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.classList.add('show');
    }
}

function closeSidebar() {
    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.classList.remove('show');
    }
}

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', openSidebar);
}

if (sidebarToggleMobile) {
    sidebarToggleMobile.addEventListener('click', openSidebar);
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
}

['breakdownTotalQty', 'breakdownBoxQty', 'breakdownPackQty'].forEach(function (id) {
    const el = document.getElementById(id);

    if (el) {
        el.addEventListener('input', updateBreakdownPreview);
    }
});

const purchaseOrdersTab = document.getElementById('purchaseOrdersTab');

if (purchaseOrdersTab) {
    purchaseOrdersTab.addEventListener('shown.bs.tab', function () {
        loadOpenPurchaseOrders();
    });
}

/*
    Direct load for Android tablet WebView.
    This prevents blank request cards/table when the refresh controller does not start correctly.
*/
document.addEventListener('DOMContentLoaded', function () {
    renderPickItems();

    loadOpenRequests().catch(function () {
        const status = document.getElementById('requestStatus');

        if (status) {
            status.textContent = 'Unable to load requests. Please refresh.';
        }
    });

    if (typeof window.createRefreshController === 'function') {
        const pickerRefresh = window.createRefreshController([
            {
                name: 'pickerRequests',
                fn: loadOpenRequests,
                intervalMs: 60000
            },
            {
                name: 'pickerPurchaseOrders',
                fn: loadOpenPurchaseOrders,
                intervalMs: 120000,
                options: {
                    immediate: false
                }
            }
        ]);

        if (pickerRefresh && typeof pickerRefresh.scheduleAll === 'function') {
            pickerRefresh.scheduleAll();
        }
    } else {
        window.setInterval(loadOpenRequests, 60000);
    }
});
</script>
</body>
</html>