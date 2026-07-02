<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
$queuedPrintCount = isset($_GET['print_queued']) ? max(0, (int)$_GET['print_queued']) : 0;
$queuedPrintJob = trim((string)($_GET['print_job'] ?? ''));
$queuedPrintTrigger = trim((string)($_GET['print_trigger'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <title>Picker - Warehouse</title>
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

        .app-layout { display:flex; min-height:100vh; }
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color:#fff;
            position:fixed;
            inset:0 auto 0 0;
            z-index:1030;
            display:flex;
            flex-direction:column;
            box-shadow:8px 0 30px rgba(15,23,42,.12);
        }
        .sidebar-brand { padding:20px 18px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:12px; }
        .sidebar-logo { width:44px; height:44px; border-radius:12px; background:#fff; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .sidebar-logo img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
        .sidebar-title { font-size:15px; font-weight:800; line-height:1.2; }
        .sidebar-subtitle { font-size:12px; color:#9ca3af; margin-top:2px; }
        .sidebar-menu { padding:14px 10px; flex:1; overflow-y:auto; }
        .sidebar-section { color:#6b7280; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:12px 12px 6px; }
        .sidebar-link { color:#d1d5db; text-decoration:none; display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:11px; font-size:14px; font-weight:600; margin-bottom:5px; transition:background .15s ease,color .15s ease; }
        .sidebar-link:hover { background:var(--sidebar-hover); color:#fff; }
        .sidebar-link.active { background:var(--sidebar-active); color:#fff; }
        .sidebar-icon { width:22px; text-align:center; opacity:.95; flex-shrink:0; }
        .sidebar-footer { padding:14px 16px; border-top:1px solid rgba(255,255,255,.08); }
        .user-box { background:rgba(255,255,255,.06); border-radius:14px; padding:11px; margin-bottom:10px; }
        .user-name { font-size:14px; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .user-role { font-size:12px; color:#9ca3af; text-transform:uppercase; }
        .logout-link { display:block; text-align:center; color:#fecaca; text-decoration:none; font-size:13px; font-weight:700; padding:9px 10px; border-radius:10px; }
        .logout-link:hover { background:rgba(239,68,68,.14); color:#fff; }
        .main-content { margin-left:var(--sidebar-width); width:calc(100% - var(--sidebar-width)); padding:18px; overflow-x:hidden; }
        .page-header { display:flex; justify-content:space-between; align-items:start; gap:16px; margin-bottom:18px; }
        .page-title { color:var(--text-dark); font-weight:800; margin-bottom:4px; letter-spacing:-.03em; }
        .page-subtitle { color:var(--text-muted); font-size:14px; }
        .content-card { background:#fff; border:1px solid var(--border-soft); border-radius:16px; box-shadow:0 12px 35px rgba(15,23,42,.06); overflow:hidden; }
        .content-card-header { padding:16px 18px; border-bottom:1px solid var(--border-soft); background:#fff; }
        .content-card-title { font-size:16px; font-weight:800; color:var(--text-dark); margin:0; }
        .content-card-subtitle { font-size:13px; color:var(--text-muted); margin-top:3px; }
        .content-card-body { padding:18px; }
        .form-control { border-radius:11px; border:1px solid #d9e2ef; min-height:42px; font-size:14px; background-color:#f9fbfd; }
        .form-control:focus { background-color:#fff; border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.12); }
        .btn { border-radius:10px; font-weight:700; }
        .request-list { max-height:calc(100vh - 220px); overflow:auto; padding-right:4px; }
        .request-card { width:100%; border:1px solid var(--border-soft); background:#fff; border-radius:14px; padding:14px; text-align:left; margin-bottom:10px; box-shadow:0 8px 20px rgba(15,23,42,.04); }
        .request-card:hover { background:#f8fbff; }
        .request-card.active { border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.12); }
        .request-title { font-weight:800; color:#111827; }
        .request-meta { font-size:12px; color:#6b7280; margin-top:3px; }
        .qty-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:7px; margin-top:12px; }
        .qty-box { background:#f8fafc; border:1px solid #e5eaf2; border-radius:10px; padding:8px; }
        .qty-box .label { font-size:10px; color:#6b7280; font-weight:800; }
        .qty-box .value { font-size:13px; font-weight:900; color:#111827; margin-top:2px; overflow:hidden; text-overflow:ellipsis; }
        .picker-table-wrap { max-height:63vh; overflow:auto; border:1px solid var(--border-soft); border-radius:14px; background:#fff; }
        .picker-table { width:100%; table-layout:fixed; font-size:11px; margin-bottom:0; }
        .picker-table thead th { position:sticky; top:0; z-index:5; background:#f8fafc; color:#374151; font-size:9px; font-weight:800; text-transform:uppercase; border-bottom:1px solid #d8e0eb; padding:8px 5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .picker-table td { padding:7px 5px; vertical-align:middle; color:#111827; overflow:hidden; text-overflow:ellipsis; }
        .col-no { width:5%; text-align:center; white-space:nowrap; }
        .col-item { width:12%; white-space:nowrap; }
        .col-part { width:19%; white-space:normal; line-height:1.25; }
        .col-qty { width:12%; }
        .col-uom { width:8%; white-space:nowrap; }
        .col-lot { width:18%; }
        .col-itr { width:12%; white-space:nowrap; }
        .col-payload { width:16%; }
        .col-action { width:9%; text-align:center; }
        .table-input { width:100%; min-width:0; height:34px; font-size:11px; padding:4px 6px; border-radius:8px; }
        .payload-preview { font-size:10px; color:#6b7280; word-break:break-all; line-height:1.25; }
        .source-tabs { gap:6px; border-bottom:0; }
        .source-tabs .nav-link { border:1px solid var(--border-soft); border-radius:10px; color:#4b5563; font-size:13px; font-weight:800; padding:8px 10px; }
        .source-tabs .nav-link.active { background:#0d6efd; border-color:#0d6efd; color:#fff; }
        .po-toolbar { display:flex; gap:8px; margin-bottom:10px; }
        .mobile-topbar, .sidebar-backdrop { display:none; }

        @media (max-width:900px) {
            .sidebar { transform:translateX(-100%); transition:transform .2s ease; }
            .sidebar.show { transform:translateX(0); }
            .main-content { margin-left:0; width:100%; padding:14px; }
            .mobile-topbar { display:flex; align-items:center; justify-content:space-between; background:#fff; border:1px solid var(--border-soft); border-radius:14px; padding:12px 14px; margin-bottom:14px; box-shadow:0 8px 22px rgba(15,23,42,.06); }
            .sidebar-backdrop { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1029; }
            .sidebar-backdrop.show { display:block; }
            .page-header { flex-direction:column; }
            .request-list { max-height:none; padding-right:0; }
            .picker-table { min-width:980px; table-layout:auto; font-size:12px; }
            .picker-table thead th { font-size:10px; padding:8px 6px; }
            .picker-table td { padding:7px 6px; white-space:nowrap; }
            .col-no { width:auto; min-width:70px; }
            .col-item,.col-part,.col-qty,.col-uom,.col-lot,.col-itr,.col-payload,.col-action { width:auto; min-width:110px; }
            .col-part { min-width:230px; }
            .col-lot { min-width:160px; }
            .col-payload { min-width:220px; }
        }


        /* FINAL CLEAN COLLAPSIBLE SIDEBAR / ICON RAIL FIX
           - Sidebar is collapsed by default.
           - Only centered icons are visible when collapsed.
           - Full sidebar automatically appears on hover/focus.
           - Main content uses the freed width.
        */
        :root {
            --sidebar-full-width: 250px;
            --sidebar-rail-width: 68px;
        }

        @media (min-width: 901px) {
            body.sidebar-rail-mode .sidebar {
                width: var(--sidebar-rail-width) !important;
                min-width: var(--sidebar-rail-width) !important;
                max-width: var(--sidebar-rail-width) !important;
                overflow-x: hidden !important;
                transition: width .18s ease, min-width .18s ease, max-width .18s ease, box-shadow .18s ease;
            }

            body.sidebar-rail-mode .main-content {
                margin-left: var(--sidebar-rail-width) !important;
                width: calc(100% - var(--sidebar-rail-width)) !important;
                max-width: calc(100% - var(--sidebar-rail-width)) !important;
                transition: margin-left .18s ease, width .18s ease;
            }

            body.sidebar-rail-mode .sidebar:hover,
            body.sidebar-rail-mode .sidebar:focus-within {
                width: var(--sidebar-full-width) !important;
                min-width: var(--sidebar-full-width) !important;
                max-width: var(--sidebar-full-width) !important;
                z-index: 1050;
                box-shadow: 12px 0 34px rgba(15,23,42,.22);
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-brand {
                justify-content: center !important;
                padding: 14px 8px !important;
                gap: 0 !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-logo {
                width: 38px !important;
                height: 38px !important;
                margin: 0 auto !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-title,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-subtitle,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-section,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .user-name,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .user-role,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .user-box,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .logout-link {
                display: none !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-menu {
                padding: 12px 6px !important;
                overflow-x: hidden !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-footer {
                display: none !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-link,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .nav-link,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) a {
                width: 48px !important;
                min-width: 48px !important;
                height: 48px !important;
                min-height: 48px !important;
                padding: 0 !important;
                margin: 0 auto 8px !important;
                justify-content: center !important;
                align-items: center !important;
                gap: 0 !important;
                font-size: 0 !important;
                line-height: 0 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-indent: 0 !important;
            }

            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-icon,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-link i,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-link svg,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .nav-link i,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .nav-link svg,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) a i,
            body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) a svg {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                width: 24px !important;
                min-width: 24px !important;
                height: 24px !important;
                margin: 0 !important;
                font-size: 18px !important;
                line-height: 1 !important;
                text-indent: 0 !important;
            }

            body.sidebar-rail-mode .sidebar:hover .sidebar-link,
            body.sidebar-rail-mode .sidebar:focus-within .sidebar-link,
            body.sidebar-rail-mode .sidebar:hover .nav-link,
            body.sidebar-rail-mode .sidebar:focus-within .nav-link,
            body.sidebar-rail-mode .sidebar:hover a,
            body.sidebar-rail-mode .sidebar:focus-within a {
                font-size: 14px !important;
                line-height: normal !important;
            }

            /* Let the picker table use the full available screen width. */
            .row.g-3 > .col-xl-8,
            .row.g-3 > .col-xl-4 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
                width: 100% !important;
            }

            #requestList,
            #poList {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 12px;
                max-height: 420px;
            }

            #requestList .request-card,
            #poList .request-card {
                margin-bottom: 0 !important;
            }
        }

        /* Keep table actions visible and stop Split/Remove from being cut. */
        .picker-table-wrap {
            overflow-x: auto !important;
            overflow-y: auto !important;
            scrollbar-width: thin;
        }

        .picker-table {
            min-width: 1260px !important;
        }

        .col-action {
            width: 170px !important;
            min-width: 170px !important;
            white-space: nowrap !important;
        }

        .col-action .d-flex {
            flex-wrap: nowrap !important;
            justify-content: center !important;
            gap: 6px !important;
        }

        .col-action .btn {
            min-width: 64px !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
        }

        .col-payload {
            width: 180px !important;
            min-width: 180px !important;
        }

        @media (max-width: 900px) {
            body.sidebar-rail-mode .sidebar {
                width: 58px !important;
                min-width: 58px !important;
                max-width: 58px !important;
                transform: none !important;
                overflow-x: hidden !important;
            }

            body.sidebar-rail-mode .main-content {
                margin-left: 58px !important;
                width: calc(100% - 58px) !important;
                max-width: calc(100% - 58px) !important;
                padding: 12px !important;
            }

            body.sidebar-rail-mode .sidebar .sidebar-title,
            body.sidebar-rail-mode .sidebar .sidebar-subtitle,
            body.sidebar-rail-mode .sidebar .sidebar-section,
            body.sidebar-rail-mode .sidebar .user-box,
            body.sidebar-rail-mode .sidebar .logout-link {
                display: none !important;
            }

            body.sidebar-rail-mode .sidebar .sidebar-brand {
                justify-content: center !important;
                padding: 10px 6px !important;
            }

            body.sidebar-rail-mode .sidebar .sidebar-menu {
                padding: 10px 5px !important;
            }

            body.sidebar-rail-mode .sidebar .sidebar-link,
            body.sidebar-rail-mode .sidebar a {
                width: 44px !important;
                height: 44px !important;
                min-height: 44px !important;
                padding: 0 !important;
                margin: 0 auto 7px !important;
                justify-content: center !important;
                font-size: 0 !important;
                overflow: hidden !important;
            }

            .picker-table {
                min-width: 1180px !important;
            }
        }



        /* RIGHT-SIDE OPEN DOCUMENTS LAYOUT FIX
           Keep Open Documents on the right side while still allowing
           the Pick Tag table to scroll horizontally inside its own card.
        */
        @media (min-width: 901px) {
            .main-content > .row.g-3 {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) clamp(340px, 24vw, 420px) !important;
                align-items: start !important;
                gap: 16px !important;
            }

            .main-content > .row.g-3 > .col-xl-8,
            .main-content > .row.g-3 > .col-xl-4 {
                width: auto !important;
                max-width: none !important;
                flex: none !important;
            }

            .main-content > .row.g-3 > .col-xl-8 {
                grid-column: 1 !important;
                min-width: 0 !important;
            }

            .main-content > .row.g-3 > .col-xl-4 {
                grid-column: 2 !important;
                min-width: 0 !important;
            }

            #requestList,
            #poList {
                display: block !important;
                grid-template-columns: none !important;
                max-height: calc(100vh - 285px) !important;
                overflow-y: auto !important;
                padding-right: 4px !important;
            }

            #requestList .request-card,
            #poList .request-card {
                display: block !important;
                width: 100% !important;
                margin-bottom: 10px !important;
            }
        }

        @media (min-width: 901px) and (max-width: 1280px) {
            .main-content > .row.g-3 {
                grid-template-columns: minmax(0, 1fr) 350px !important;
                gap: 12px !important;
            }

            .content-card-header,
            .content-card-body {
                padding: 14px !important;
            }

            .request-card {
                padding: 12px !important;
            }
        }


        /* TABLET LANDSCAPE FIT MODE
           Keep Open Documents on the right, but make the Pick Tag table fit
           without left-right scrolling on tablet landscape / small laptops.
        */
        @media (min-width: 901px) and (max-width: 1180px) and (orientation: landscape) {
            body.sidebar-rail-mode .main-content {
                padding: 10px 12px !important;
            }

            .page-header {
                margin-bottom: 10px !important;
                align-items: center !important;
            }

            .page-title {
                font-size: 20px !important;
                margin-bottom: 2px !important;
            }

            .page-subtitle,
            .content-card-subtitle {
                font-size: 12px !important;
            }

            .main-content > .row.g-3 {
                grid-template-columns: minmax(0, 1fr) 300px !important;
                gap: 10px !important;
            }

            .content-card {
                border-radius: 12px !important;
            }

            .content-card-header {
                padding: 10px 12px !important;
            }

            .content-card-body {
                padding: 10px 12px 12px !important;
            }

            #selectedRequestBox {
                padding: 10px 12px !important;
                margin-bottom: 10px !important;
                font-size: 12px !important;
            }

            .picker-table-wrap {
                overflow-x: hidden !important;
                max-height: calc(100vh - 295px) !important;
            }

            .picker-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed !important;
                font-size: 9.5px !important;
            }

            .picker-table thead th {
                font-size: 8px !important;
                padding: 5px 4px !important;
                white-space: normal !important;
                line-height: 1.1 !important;
            }

            .picker-table td {
                padding: 5px 4px !important;
                white-space: normal !important;
                line-height: 1.15 !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
            }

            .col-no {
                width: 34px !important;
                min-width: 34px !important;
            }

            .col-item {
                width: 86px !important;
                min-width: 86px !important;
            }

            .col-part {
                width: 150px !important;
                min-width: 150px !important;
            }

            .col-qty {
                width: 82px !important;
                min-width: 82px !important;
            }

            .col-uom {
                width: 45px !important;
                min-width: 45px !important;
            }

            .col-lot {
                width: 115px !important;
                min-width: 115px !important;
            }

            .col-itr {
                width: 95px !important;
                min-width: 95px !important;
            }

            .col-action {
                width: 92px !important;
                min-width: 92px !important;
            }

            .col-payload {
                display: none !important;
                width: 0 !important;
                min-width: 0 !important;
            }

            .table-input {
                height: 32px !important;
                min-height: 32px !important;
                font-size: 10px !important;
                padding: 4px 5px !important;
                border-radius: 7px !important;
            }

            .col-action .d-flex {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 4px !important;
            }

            .col-action .btn {
                min-width: 0 !important;
                width: 100% !important;
                padding: 4px 5px !important;
                font-size: 10px !important;
                line-height: 1.1 !important;
            }

            .d-flex.justify-content-end.gap-2.mt-3 {
                margin-top: 10px !important;
            }

            .d-flex.justify-content-end.gap-2.mt-3 .btn {
                padding: 7px 10px !important;
                font-size: 12px !important;
            }

            #requestList,
            #poList {
                max-height: calc(100vh - 245px) !important;
            }

            .request-card {
                padding: 10px !important;
                border-radius: 12px !important;
            }

            .request-title {
                font-size: 13px !important;
            }

            .request-meta {
                font-size: 11px !important;
            }

            .qty-grid {
                gap: 5px !important;
                margin-top: 8px !important;
            }

            .qty-box {
                padding: 6px !important;
            }

            .qty-box .label {
                font-size: 8px !important;
            }

            .qty-box .value {
                font-size: 11px !important;
            }

            .source-tabs .nav-link {
                padding: 7px 8px !important;
                font-size: 12px !important;
            }
        }

        /* Smaller tablet landscape: keep right card but give more room to the table. */
        @media (min-width: 901px) and (max-width: 1050px) and (orientation: landscape) {
            .main-content > .row.g-3 {
                grid-template-columns: minmax(0, 1fr) 280px !important;
            }

            .content-card-header,
            .content-card-body {
                padding-left: 9px !important;
                padding-right: 9px !important;
            }

            .col-part {
                width: 130px !important;
                min-width: 130px !important;
            }

            .col-lot {
                width: 105px !important;
                min-width: 105px !important;
            }

            .col-itr {
                width: 84px !important;
                min-width: 84px !important;
            }

            .col-action {
                width: 78px !important;
                min-width: 78px !important;
            }
        }


        /* JB FIX: Hide only the QR Payload column in tablet landscape.
           This is placed at the end so it overrides earlier table width rules. */
        @media (orientation: landscape) and (min-width: 700px) and (max-width: 1366px) and (max-height: 900px) {
            #pickTable th.col-payload,
            #pickTable td.col-payload {
                display: none !important;
                width: 0 !important;
                min-width: 0 !important;
                max-width: 0 !important;
                padding: 0 !important;
                border: 0 !important;
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

        <div class="page-header">
            <div>
                <h4 class="page-title">Picker - Warehouse</h4>
                <div class="page-subtitle">Load a request or PO, split by lot/UOM when needed, then print pick tags for issuer scanning.</div>
            </div>
            <span class="badge bg-primary rounded-pill px-3 py-2" id="countBadge">0 tag(s)</span>
        </div>

        <?php if ($queuedPrintCount > 0): ?>
            <div class="alert alert-success d-flex justify-content-between align-items-start gap-2">
                <div>
                    <strong><?= h($queuedPrintCount) ?> picker tag(s) queued.</strong>
                    <?= h($queuedPrintTrigger ?: 'The print worker was started.') ?>
                    <?php if ($queuedPrintJob !== ''): ?><span class="small text-muted">Job <?= h($queuedPrintJob) ?></span><?php endif; ?>
                </div>
                <a class="btn btn-sm btn-outline-success" href="<?= h(app_path('pages/picker/picker.php')) ?>">Dismiss</a>
            </div>
        <?php endif; ?>
        <div class="alert alert-success d-none" id="printQueueAlert"></div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">Pick Tag Details</h5>
                        <div class="content-card-subtitle">The barcode scans the PO/ITR number. The QR scans as (01)SAP ItemCode(17)Qty(10)Lot No.</div>
                    </div>
                    <div class="content-card-body">
                        <div id="selectedRequestBox" class="alert alert-primary d-none">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="fw-bold" id="selectedRequestTitle"></div>
                                    <div class="small" id="selectedRequestDetails"></div>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary align-self-start" type="button" onclick="clearSelectedRequest()">Clear</button>
                            </div>
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

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button class="btn btn-outline-secondary" type="button" onclick="clearPickedLots()">Clear Lots</button>
                            <button id="printBtn" class="btn btn-success" type="button" onclick="printTags()" disabled>Print Pick Tags</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="content-card-title">Open Documents</h5>
                                <div class="content-card-subtitle">Requests and SAP purchase orders for picking.</div>
                            </div>
                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="refreshOpenDocuments()">Refresh</button>
                        </div>

                        <ul class="nav source-tabs mt-3" id="pickerSourceTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="requestsTab" data-bs-toggle="tab" data-bs-target="#requestsPane" type="button" role="tab" aria-controls="requestsPane" aria-selected="true">
                                    Requests
                                    <span class="badge text-bg-light ms-1" id="requestCount">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="purchaseOrdersTab" data-bs-toggle="tab" data-bs-target="#purchaseOrdersPane" type="button" role="tab" aria-controls="purchaseOrdersPane" aria-selected="false">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[c]));
}

function pickPayload(it) {
    return '(01)' + String(it.item_code || '').trim() + '(17)' + String(it.quantity || '').trim() + '(10)' + String(it.lot_no || '').trim();
}

function isPurchaseOrderPickItem(it) {
    return String(it.source_type || '').toLowerCase() === 'purchase_order'
        || String(selectedDocument?.request_id || '').startsWith('PO-');
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

    document.getElementById('countBadge').textContent = countText;
    document.getElementById('printBtn').disabled = readyCount === 0;
}

async function loadOpenRequests() {
    const status = document.getElementById('requestStatus');
    status.textContent = 'Refreshing requests...';

    try {
        const res = await fetch('api/get_open_issue_requests.php', { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            openDocuments = [];
            document.getElementById('requestCount').textContent = '0';
            renderRequests();
            status.textContent = data.message || 'Unable to load requests.';
            return;
        }

        openDocuments = data.documents || [];
        document.getElementById('requestCount').textContent = openDocuments.length;
        renderRequests();
        status.textContent = openDocuments.length + ' open request(s) available.';
    } catch (e) {
        openDocuments = [];
        document.getElementById('requestCount').textContent = '0';
        renderRequests();
        status.textContent = 'Unable to load requests. Check WHPOKAYOKE connection or login session.';
    }
}

async function loadOpenPurchaseOrders() {
    const status = document.getElementById('poStatus');
    const search = (document.getElementById('poSearchInput')?.value || '').trim();
    status.textContent = search ? 'Searching open purchase orders...' : 'Open POs load when needed. Use Search or Refresh.';

    if (!search && openPurchaseOrders.length === 0 && document.getElementById('purchaseOrdersTab')?.classList.contains('active') !== true) {
        status.textContent = 'Open POs are paused until you open this tab or search.';
        return;
    }

    try {
        const url = 'api/picker/open_purchase_orders.php?q=' + encodeURIComponent(search);
        const res = await fetch(url, { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            openPurchaseOrders = [];
            document.getElementById('poCount').textContent = '0';
            renderPurchaseOrders();
            status.textContent = data.message || 'Unable to load open purchase orders.';
            return;
        }

        openPurchaseOrders = data.documents || [];
        document.getElementById('poCount').textContent = openPurchaseOrders.length;
        renderPurchaseOrders();
        status.textContent = openPurchaseOrders.length + ' open PO(s) found' + (data.limited ? ' (limited)' : '') + '.';
    } catch (e) {
        openPurchaseOrders = [];
        document.getElementById('poCount').textContent = '0';
        renderPurchaseOrders();
        status.textContent = 'Unable to load open purchase orders. Check SAP connection or login session.';
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
                    <div>
                        <div class="request-title">${esc(doc.request_no || doc.doc_num)}</div>
                        <div class="request-meta">ITR ${esc(doc.itr_number || doc.doc_num)} | Needed ${esc(doc.needed_date || doc.doc_date)} | ${esc(doc.line_count)} item(s)</div>
                        <div class="request-meta">By ${esc(doc.requested_by || '')}${doc.remarks ? ' | ' + esc(doc.remarks) : ''}</div>
                    </div>
                    <span class="badge text-bg-primary rounded-pill align-self-start">Load</span>
                </div>
                <div class="qty-grid">
                    <div class="qty-box"><div class="label">Requested</div><div class="value">${fmtQty(doc.requested_qty)}</div></div>
                    <div class="qty-box"><div class="label">Remaining</div><div class="value">${fmtQty(doc.remaining_qty)}</div></div>
                    <div class="qty-box"><div class="label">WH 01</div><div class="value">${fmtQty(doc.warehouse_stock_qty)}</div></div>
                </div>
            </button>
        `);
    });
}

function renderPurchaseOrders() {
    const list = document.getElementById('poList');
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
                    <div class="qty-box"><div class="label">Open Items</div><div class="value">${esc(doc.line_count)}</div></div>
                    <div class="qty-box"><div class="label">Open Qty</div><div class="value">${fmtQty(doc.open_qty)}</div></div>
                    <div class="qty-box"><div class="label">Due</div><div class="value">${esc(doc.due_date || doc.doc_date)}</div></div>
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

    document.getElementById('selectedRequestBox').classList.remove('d-none');
    document.getElementById('selectedRequestTitle').textContent = 'PO ' + poNumber;
    document.getElementById('selectedRequestDetails').textContent = (doc.vendor_name || doc.vendor_code || '') + ' | ' + pickItems.length + ' item line(s).';

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

    document.getElementById('selectedRequestBox').classList.remove('d-none');
    document.getElementById('selectedRequestTitle').textContent = (doc.request_no || 'Request') + ' / ITR ' + (doc.itr_number || doc.doc_num);
    document.getElementById('selectedRequestDetails').textContent = doc.line_count + ' item(s), needed ' + (doc.needed_date || doc.doc_date) + ', remaining total ' + fmtQty(doc.remaining_qty);

    renderRequests();
    renderPickItems();
}

function renderPickItems() {
    const tb = document.querySelector('#pickTable tbody');
    tb.innerHTML = '';

    pickItems.forEach((it, idx) => {
        tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="col-no text-center fw-bold">${idx + 1}</td>
                <td class="col-item" title="${esc(it.item_code)}">${esc(it.item_code)}</td>
                <td class="col-part" title="${esc(it.part_name)}">${esc(it.part_name)}</td>
                <td class="col-qty">
                    <input class="form-control form-control-sm table-input" type="number" min="0.001" step="0.001" id="qty_${idx}" value="${esc(it.quantity)}" onchange="updatePickItem(${idx}, 'quantity', this.value)">
                    <div class="small text-muted">Remaining ${fmtQty(it.remaining_qty)}</div>
                </td>
                <td class="col-uom" title="${esc(it.num_per_msr || '')}">${esc(it.uom || '')}</td>
                <td class="col-lot">
                    <input class="form-control form-control-sm table-input" id="lot_${idx}" value="${esc(it.lot_no)}" placeholder="Enter actual lot" onchange="updatePickItem(${idx}, 'lot_no', this.value)" oninput="updatePickItem(${idx}, 'lot_no', this.value)">
                </td>
                <td class="col-itr" title="${esc(it.request_no)} / ${esc(it.itr_number)}">${esc(it.request_no)}<br><span class="text-muted">${esc(it.itr_number)}</span></td>
                <td class="col-payload"><div class="payload-preview" id="payload_${idx}">${esc(pickPayload(it))}</div></td>
                <td class="col-action">
                    <div class="d-flex gap-1 justify-content-center">
                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openBreakdown(${idx})">Split</button>
                        <button class="btn btn-sm btn-outline-danger" type="button" onclick="removePickItem(${idx})">X</button>
                    </div>
                </td>
            </tr>
        `);
    });

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
    document.getElementById('breakdownLineInfo').textContent =
        it.item_code + ' | ' + (it.part_name || '') + ' | Remaining ' + fmtQty(it.remaining_qty) + (it.uom ? ' ' + it.uom : '');
    document.getElementById('breakdownLot').value = it.lot_no || '';
    document.getElementById('breakdownTotalQty').value = it.quantity || it.remaining_qty || '';
    document.getElementById('breakdownBoxQty').value = '';
    document.getElementById('breakdownPackQty').value = it.num_per_msr && Number(it.num_per_msr) > 1 ? it.num_per_msr : '';
    updateBreakdownPreview();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('breakdownModal')).show();
}

function updateBreakdownPreview() {
    const total = Number(document.getElementById('breakdownTotalQty').value || 0);
    const pack = Number(document.getElementById('breakdownPackQty').value || 0);
    const box = Number(document.getElementById('breakdownBoxQty').value || 0);
    const preview = document.getElementById('breakdownPreview');

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

    const lot = document.getElementById('breakdownLot').value.trim();
    const total = Number(document.getElementById('breakdownTotalQty').value || 0);
    const pack = Number(document.getElementById('breakdownPackQty').value || 0);

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
    document.getElementById('selectedRequestBox').classList.add('d-none');
    renderRequests();
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

        printable.push(Object.assign({}, it, { qr_payload: pickPayload(it) }));
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

        const res = await fetch('actions/print_pick_tags.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body
        });

        const data = await res.json();

        if (!res.ok || !data.ok) {
            throw new Error(data.message || 'Unable to queue pick tags.');
        }

        showPrintQueueMessage(
            data.queued + ' picker tag(s) queued. ' +
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

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', openSidebar);
}

if (sidebarToggleMobile) {
    sidebarToggleMobile.addEventListener('click', openSidebar);
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', function () {
        if (sidebar) {
            sidebar.classList.remove('show');
        }

        sidebarBackdrop.classList.remove('show');
    });
}

['breakdownTotalQty', 'breakdownBoxQty', 'breakdownPackQty'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', updateBreakdownPreview);
    }
});

const pickerRefresh = window.createRefreshController([
    { name: 'pickerRequests', fn: loadOpenRequests, intervalMs: 60000 },
    { name: 'pickerPurchaseOrders', fn: loadOpenPurchaseOrders, intervalMs: 120000, options: { immediate: false } }
]);

pickerRefresh.scheduleAll();

const purchaseOrdersTab = document.getElementById('purchaseOrdersTab');

if (purchaseOrdersTab) {
    purchaseOrdersTab.addEventListener('shown.bs.tab', function () {
        pickerRefresh.run('pickerPurchaseOrders', loadOpenPurchaseOrders);
    });
}
</script>
</body>
</html>
