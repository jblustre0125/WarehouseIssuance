<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <title>Issue Request</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

        * { box-sizing: border-box; }

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
            background: #fff;
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

        .shell-title-wrap { min-width: 0; flex: 1; }
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
            display: flex;
            min-height: 100vh;
            padding-top: var(--topbar-height);
        }

        .sidebar {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            width: var(--side-width);
            z-index: 1035;
            background: #fff;
            color: var(--sap-text);
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

        .sidebar-brand { display: none; }
        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding: .5rem;
        }

        .sidebar-section {
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: .875rem .625rem .375rem;
            letter-spacing: .045em;
        }

        .sidebar-link {
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
            margin-bottom: .125rem;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }

        .sidebar-link:hover {
            background: #f5f6f7;
            border-color: #e5e5e5;
            color: var(--sap-accent);
        }

        .sidebar-link.active {
            background: var(--sap-highlight);
            border-color: #8fc7ff;
            color: #074f91;
        }

        .sidebar-icon {
            width: 1.375rem;
            text-align: center;
            font-size: 1rem;
            flex: 0 0 auto;
        }

        .sidebar-footer {
            padding: .75rem;
            border-top: 1px solid var(--sap-border-soft);
            background: #fbfbfb;
        }

        .user-box {
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

        .user-box::before {
            content: "<?= h(strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'] ?? 'U', 0, 1))) ?>";
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

        .user-name,
        .user-role {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-name {
            color: var(--sap-text);
            font-size: .875rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .user-role {
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
            padding: .45rem .75rem;
        }

        .logout-link:hover {
            background: var(--sap-error-bg);
            color: var(--sap-error);
        }

        .main-content {
            margin-left: var(--side-width);
            width: calc(100% - var(--side-width));
            min-height: calc(100vh - var(--topbar-height));
            overflow-x: hidden;
        }

        .mobile-topbar { display: none !important; }

        .page-header {
            background: linear-gradient(180deg, #eff6ff 0%, #f7f7f7 100%);
            border-bottom: 1px solid var(--sap-border-soft);
            padding: 1.25rem 1.5rem 1rem;
            margin: 0;
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

        #countBadge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 1.75rem;
            padding: .25rem .75rem !important;
            border-radius: 1rem !important;
            background: var(--sap-highlight) !important;
            color: #074f91 !important;
            border: 1px solid #8fc7ff;
            font-size: .75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .main-content > .row,
        .main-content > .content-card,
        .main-content > .sap-page-body {
            padding: 1rem 1.5rem 1.5rem;
        }

        .main-content > .row {
            margin: 0;
        }

        .content-card {
            background: var(--sap-card);
            border: 1px solid var(--sap-border);
            border-radius: .5rem;
            box-shadow: 0 .125rem .5rem rgba(0, 0, 0, .06);
            overflow: hidden;
            height: 100%;
        }

        .content-card-header {
            min-height: 3.25rem;
            padding: .875rem 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fff;
        }

        .content-card-title {
            margin: 0;
            color: var(--sap-text);
            font-size: 1rem;
            font-weight: 700;
        }

        .content-card-subtitle {
            margin-top: .1875rem;
            color: var(--sap-muted);
            font-size: .8125rem;
        }

        .content-card-body { padding: 1rem; }

        .form-label {
            color: var(--sap-text);
            font-size: .8125rem;
            font-weight: 700;
            margin-bottom: .3125rem;
        }

        .form-control,
        .form-select {
            min-height: 2.375rem;
            border-radius: .25rem;
            border-color: #89919a;
            color: var(--sap-text);
            font-size: .875rem;
            background-color: #fff;
        }

        .form-control:hover,
        .form-select:hover { border-color: var(--sap-accent); }
        .form-control:focus,
        .form-select:focus {
            border-color: var(--sap-accent);
            box-shadow: 0 0 0 .125rem rgba(10, 110, 209, .22);
        }

        .btn {
            min-height: 2.25rem;
            border-radius: .25rem;
            font-size: .875rem;
            font-weight: 700;
        }

        .btn-primary,
        .btn-success {
            background: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .btn-primary:hover,
        .btn-success:hover,
        .btn-primary:focus,
        .btn-success:focus {
            background: var(--sap-accent-hover);
            border-color: var(--sap-accent-hover);
        }

        .info-box {
            border-radius: .5rem;
            font-size: .875rem;
            border-color: var(--sap-border-soft);
        }

        .request-table-wrap {
            max-height: 58vh;
            overflow: auto;
            border: 1px solid var(--sap-border);
            border-radius: .5rem;
            background: #fff;
        }

        .request-table {
            margin: 0;
            min-width: 64rem;
            table-layout: fixed;
            font-size: .75rem;
        }

        .request-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f2f2f2;
            color: var(--sap-text);
            border-bottom: 1px solid var(--sap-border);
            font-weight: 800;
            padding: .625rem .5rem;
            white-space: nowrap;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: .6875rem;
            letter-spacing: .02em;
        }

        .request-table td {
            padding: .575rem .5rem;
            color: var(--sap-text);
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .request-table tbody tr:hover { background: #f5f9ff; }

        .col-item { width: 13%; white-space: nowrap; }
        .col-part { width: 20%; white-space: normal; line-height: 1.25; }
        .col-line { width: 11%; white-space: nowrap; }
        .col-requested,
        .col-remaining,
        .col-stock { width: 11%; text-align: right; white-space: nowrap; }
        .col-qty { width: 13%; white-space: nowrap; }
        .col-action { width: 9%; text-align: center; white-space: nowrap; }

        .table-input {
            width: 100%;
            min-width: 0;
            height: 2.25rem;
            font-size: .8125rem;
            padding: .375rem .5rem;
            border-radius: .25rem;
        }

        .remove-btn {
            min-height: 1.875rem;
            font-size: .75rem;
            padding: .25rem .5rem;
        }

        .request-actions { display: flex; gap: .375rem; margin-top: .75rem; }
        .request-actions .btn { flex: 1; min-height: 2rem; font-size: .75rem; padding: .25rem .5rem; }

        .requests-panel {
            max-height: calc(100vh - 210px);
            overflow-y: auto;
            padding-right: .25rem;
        }

        .side-panel-list { max-height: calc(100vh - 300px); }

        .request-tabs {
            gap: .375rem;
            border-bottom: 0;
            flex-wrap: wrap;
        }

        .request-tabs .nav-link {
            border: 1px solid var(--sap-border-soft);
            border-radius: .375rem;
            color: var(--sap-text);
            background: #fff;
            font-size: .8125rem;
            font-weight: 800;
            padding: .45rem .625rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .request-tabs .nav-link.active {
            background: var(--sap-highlight);
            border-color: #8fc7ff;
            color: #074f91;
        }

        .tab-count {
            min-width: 1.35rem;
            height: 1.35rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 .4rem;
            background: #e5e7eb;
            color: #111827;
            font-size: .6875rem;
            font-weight: 900;
            line-height: 1;
        }

        .request-tabs .nav-link.active .tab-count {
            background: #fff;
            color: #074f91;
        }

        .stock-toolbar {
            display: flex;
            gap: .5rem;
            margin-bottom: .625rem;
        }

        .stock-card,
        .itr-card {
            background: #fff;
            border: 1px solid var(--sap-border-soft);
            border-radius: .5rem;
            margin-bottom: .5rem;
            color: var(--sap-text);
            overflow: hidden;
            box-shadow: 0 .125rem .375rem rgba(0, 0, 0, .04);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .stock-card { padding: .75rem; }
        .stock-card:hover,
        .itr-card:hover {
            transform: translateY(-1px);
            border-color: #8fc7ff;
            box-shadow: 0 .25rem .75rem rgba(10, 110, 209, .12);
        }

        .itr-card.active {
            border-color: var(--sap-accent);
            box-shadow: 0 0 0 .125rem rgba(10, 110, 209, .2);
        }

        .itr-header {
            width: 100%;
            border: 0;
            background: #fff;
            text-align: left;
            padding: .875rem;
        }

        .itr-header:hover { background: #f8fbff; }

        .request-title,
        .stock-code {
            font-size: .875rem;
            font-weight: 800;
            color: var(--sap-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .request-meta,
        .stock-name {
            font-size: .75rem;
            color: var(--sap-muted);
            margin-top: .125rem;
            line-height: 1.25;
        }

        .qty-grid,
        .stock-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .4375rem;
            margin-top: .75rem;
        }

        .qty-box,
        .stock-metric {
            background: #f8fafc;
            border: 1px solid var(--sap-border-soft);
            border-radius: .375rem;
            padding: .5rem;
            min-width: 0;
        }

        .qty-box .label,
        .stock-metric .label {
            font-size: .6875rem;
            color: var(--sap-muted);
            font-weight: 700;
        }

        .qty-box .value,
        .stock-metric .value {
            font-size: .875rem;
            font-weight: 800;
            color: var(--sap-text);
            margin-top: .125rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sap-it-lines { margin-top: .625rem; border-top: 1px solid var(--sap-border-soft); }
        .sap-it-line { padding: .5rem 0; border-bottom: 1px solid #edf2f7; }
        .sap-it-line:last-child { border-bottom: 0; padding-bottom: 0; }
        .sap-it-lot { font-size: .75rem; font-weight: 800; color: var(--sap-text); word-break: break-all; }

        .badge.text-bg-primary,
        .badge.bg-primary { background: var(--sap-accent) !important; }
        .badge.text-bg-success { background: var(--sap-success) !important; }
        .badge.text-bg-warning { background: var(--sap-warning-bg) !important; color: var(--sap-warning) !important; border: 1px solid #ffd580; }

        .modal-content { border-radius: .5rem; border-color: var(--sap-border); }
        .modal-header { border-bottom-color: var(--sap-border-soft); }
        .modal-footer { border-top-color: var(--sap-border-soft); }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: var(--topbar-height) 0 0 0;
            z-index: 1030;
            background: rgba(0, 0, 0, .38);
        }

        @media (max-width: 1199.98px) {
            .request-table { font-size: .6875rem; }
            .request-table thead th { font-size: .625rem; padding: .5rem .375rem; }
            .request-table td { padding: .5rem .375rem; }
        }

        @media (max-width: 991.98px) {
            :root { --side-width: 16rem; }
            .shell-menu-btn { display: inline-flex; align-items: center; justify-content: center; }
            .sidebar { transform: translateX(-105%); transition: transform .2s ease; }
            .sidebar.show { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; width: 100%; }
            .page-header { flex-direction: column; padding-left: 1rem; padding-right: 1rem; }
            .main-content > .row { padding-left: 1rem; padding-right: 1rem; }
            .requests-panel { max-height: none; padding-right: 0; }
        }

        @media (max-width: 767.98px) {
            .sap-shellbar { padding-inline: .75rem; }
            .shell-logo { width: 2rem; height: 2rem; }
            .shell-subtitle { display: none; }
            .main-content > .row { padding: .75rem; }
            .content-card-header,
            .content-card-body { padding-left: .75rem; padding-right: .75rem; }
            .stock-toolbar { flex-direction: column; }
            .request-tabs .nav-link { flex: 1 1 auto; justify-content: center; }
        }

        @media (max-width: 575.98px) {
            .page-title { font-size: 1.25rem; }
            .page-header { padding-top: 1rem; }
            .qty-grid,
            .stock-metrics { grid-template-columns: 1fr; }
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

    <?php app_sidebar('requestor'); ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h4 class="page-title">Requestor - Issue Order</h4>
                <div class="page-subtitle">
                    Load an open WH 01 ITR, then enter only the quantities needed for the selected date.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2" id="countBadge">
                0 line(s)
            </span>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">Issue Request Details</h5>
                        <div class="content-card-subtitle">
                            Select a needed date, add remarks, and input requested quantities.
                        </div>
                    </div>

                    <div class="content-card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label" for="neededDate">Needed Date</label>
                                <input id="neededDate" class="form-control" type="date" value="<?= h(date('Y-m-d')) ?>">
                            </div>

                            <div class="col-md-9">
                                <label class="form-label" for="remarksInput">Remarks</label>
                                <input id="remarksInput" class="form-control" placeholder="Optional note for warehouse">
                            </div>
                        </div>

                        <div id="loadedInfo" class="alert alert-secondary info-box">
                            No ITR loaded.
                        </div>

                        <input
                            id="itemSearchInput"
                            class="form-control form-control-sm mb-3"
                            placeholder="Search SAP code or part name..."
                            oninput="renderTable()"
                        >

                        <div class="request-table-wrap">
                            <table class="table table-bordered table-striped align-middle request-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th class="col-item">SAP ItemCode</th>
                                        <th class="col-part">Part Name</th>
                                        <th class="col-line">ITR/Line</th>
                                        <th class="col-requested">Already Requested</th>
                                        <th class="col-remaining">Remaining</th>
                                        <th class="col-stock">Your WH Stock</th>
                                        <th class="col-qty">Qty to Request</th>
                                        <th class="col-action"></th>
                                    </tr>
                                </thead>

                                <tbody></tbody>
                            </table>
                        </div>

                        <div class="text-end mt-3">
                            <button id="saveBtn" class="btn btn-success" onclick="saveRequest()" disabled>
                                Submit Issue Request
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="content-card-title">Requests</h5>
                                <div class="content-card-subtitle">Manage saved requests or load a new ITR.</div>
                            </div>

                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="refreshSideTabs()">
                                Refresh
                            </button>
                        </div>

                        <ul class="nav request-tabs" id="requestSideTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="myRequestsTab" data-bs-toggle="tab" data-bs-target="#myRequestsPane" type="button" role="tab" aria-controls="myRequestsPane" aria-selected="true">
                                    My Open
                                    <span class="tab-count" id="myRequestCount">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="openItrsTab" data-bs-toggle="tab" data-bs-target="#openItrsPane" type="button" role="tab" aria-controls="openItrsPane" aria-selected="false">
                                    Open ITRs
                                    <span class="tab-count" id="openItrCount">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="sapItsTab" data-bs-toggle="tab" data-bs-target="#sapItsPane" type="button" role="tab" aria-controls="sapItsPane" aria-selected="false">
                                    SAP ITs
                                    <span class="tab-count" id="sapItCount">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="stockViewerTab" data-bs-toggle="tab" data-bs-target="#stockViewerPane" type="button" role="tab" aria-controls="stockViewerPane" aria-selected="false">
                                    Stock
                                    <span class="tab-count" id="stockCount">0</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="content-card-body">
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="myRequestsPane" role="tabpanel" aria-labelledby="myRequestsTab" tabindex="0">
                                <div class="small text-muted mb-2" id="myRequestStatus">
                                    Loading requests...
                                </div>

                                <div id="myRequestList" class="requests-panel side-panel-list"></div>
                            </div>

                            <div class="tab-pane fade" id="openItrsPane" role="tabpanel" aria-labelledby="openItrsTab" tabindex="0">
                                <div class="stock-toolbar">
                                    <input class="form-control form-control-sm" id="itrItemSearchInput" placeholder="Search ITR, SAP code, or part name" oninput="renderItrs()">
                                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadOpenItrs()">Reload</button>
                                </div>

                                <div class="small text-muted mb-2" id="requestStatus">
                                    Loading ITRs...
                                </div>

                                <div class="small text-muted mb-2" id="requestMonth">Current month</div>

                                <div id="itrList" class="requests-panel side-panel-list"></div>
                            </div>

                            <div class="tab-pane fade" id="sapItsPane" role="tabpanel" aria-labelledby="sapItsTab" tabindex="0">
                                <div class="stock-toolbar">
                                    <input class="form-control form-control-sm" id="sapItSearchInput" placeholder="Search IT, ITR, item, lot" oninput="renderSapInventoryTransfers()">
                                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadSapInventoryTransfers()">Reload</button>
                                </div>

                                <div class="small text-muted mb-2" id="sapItStatus">
                                    Click Reload to load the latest SAP ITs.
                                </div>

                                <div id="sapItList" class="requests-panel side-panel-list"></div>
                            </div>

                            <div class="tab-pane fade" id="stockViewerPane" role="tabpanel" aria-labelledby="stockViewerTab" tabindex="0">
                                <div class="stock-toolbar">
                                    <input class="form-control form-control-sm" id="stockSearchInput" placeholder="Search item or warehouse" oninput="renderStocks()">
                                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadStocks()">Reload</button>
                                </div>

                                <div class="small text-muted mb-2" id="stockStatus">
                                    Loading stock...
                                </div>

                                <div id="stockList" class="requests-panel side-panel-list"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<div class="modal fade" id="requestSavedModal" tabindex="-1" aria-labelledby="requestSavedTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="requestSavedTitle">Request Saved</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success mb-3" id="requestSavedMessage">
                    Issue request saved successfully.
                </div>
                <div class="row g-2 small">
                    <div class="col-5 text-muted">Request No</div>
                    <div class="col-7 fw-bold" id="modalRequestNo">-</div>
                    <div class="col-5 text-muted">ITR Number</div>
                    <div class="col-7 fw-bold" id="modalItrNumber">-</div>
                    <div class="col-5 text-muted">Needed Date</div>
                    <div class="col-7 fw-bold" id="modalNeededDate">-</div>
                    <div class="col-5 text-muted">Saved Lines</div>
                    <div class="col-7 fw-bold" id="modalSavedLines">-</div>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary" href="pages/requestor/requestor_report.php">View Report</a>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Create Another</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app-refresh.js"></script>

<script>
let openDocuments = [];
let selectedDocument = null;
let requestItems = [];
let myRequests = [];
let editingRequest = null;
let stockRows = [];
let sapInventoryTransfers = [];
let selectedSapIt = null;
let sapItLoading = false;

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


function findWarehouseStock(itemCode, whsCode) {
    const item = String(itemCode || '').trim().toLowerCase();
    const whs = String(whsCode || '').trim().toLowerCase();

    const row = stockRows.find(r =>
        String(r.item_code || '').trim().toLowerCase() === item &&
        String(r.warehouse_code || '').trim().toLowerCase() === whs
    );

    if (!row) {
        return 0;
    }

    const available = Number(row.available_qty || 0);
    const onHand = Number(row.on_hand_qty || 0);

    return available > 0 ? available : onHand;
}

async function loadOpenItrs() {
    const status = document.getElementById('requestStatus');
    status.textContent = 'Refreshing ITRs...';

    try {
        const res = await fetch('api/get_open_itr_requests.php', { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            openDocuments = [];
            document.getElementById('openItrCount').textContent = '0';
            renderItrs();
            status.textContent = data.message || 'Unable to load ITRs.';
            return;
        }

        openDocuments = data.documents || [];
        document.getElementById('openItrCount').textContent = openDocuments.length;
        document.getElementById('requestMonth').textContent = (data.month_start || '') + ' to ' + (data.month_end || '');

        renderItrs();

        status.textContent = openDocuments.length + ' ITR(s), updated ' + new Date().toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    } catch (e) {
        document.getElementById('openItrCount').textContent = '0';
        status.textContent = 'Unable to load ITRs. Check SAP connection or login session.';
    }
}

async function loadMyRequests() {
    const status = document.getElementById('myRequestStatus');
    status.textContent = 'Refreshing requests...';

    try {
        const res = await fetch('api/requestor/list_requests.php', { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            myRequests = [];
            document.getElementById('myRequestCount').textContent = '0';
            renderMyRequests();
            status.textContent = data.message || 'Unable to load your requests.';
            return;
        }

        myRequests = data.requests || [];
        document.getElementById('myRequestCount').textContent = myRequests.length;
        renderMyRequests();
        status.textContent = myRequests.length + ' open request(s), updated ' + new Date().toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    } catch (e) {
        myRequests = [];
        document.getElementById('myRequestCount').textContent = '0';
        renderMyRequests();
        status.textContent = 'Unable to load your requests.';
    }
}

async function refreshSideTabs() {
    await Promise.all([
        loadMyRequests(),
        loadOpenItrs(),
        loadStocks()
    ]);
}

async function loadSapInventoryTransfers() {
    if (sapItLoading) {
        return;
    }

    sapItLoading = true;

    const status = document.getElementById('sapItStatus');

    if (status) {
        status.textContent = 'Refreshing SAP ITs...';
    }

    try {
        const search = (document.getElementById('sapItSearchInput')?.value || '').trim();
        let url = 'api/requestor/list_sap_inventory_transfers.php?max=20';

        if (search !== '') {
            url += '&q=' + encodeURIComponent(search);
        }

        const res = await fetch(url, { cache: 'no-store' });
        const text = await res.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (e) {
            sapInventoryTransfers = [];
            document.getElementById('sapItCount').textContent = '0';
            renderSapInventoryTransfers();

            if (status) {
                status.textContent = 'SAP IT API returned invalid JSON. Check api/requestor/list_sap_inventory_transfers.php';
            }

            console.error('SAP IT invalid response:', text);
            return;
        }

        if (!data.ok) {
            sapInventoryTransfers = [];
            document.getElementById('sapItCount').textContent = '0';
            renderSapInventoryTransfers();

            if (status) {
                status.textContent = data.message || 'Unable to load SAP ITs.';
            }

            return;
        }

        sapInventoryTransfers = data.documents || [];
        document.getElementById('sapItCount').textContent = sapInventoryTransfers.length;
        renderSapInventoryTransfers();

        if (status) {
            let msg = (data.section_filter || 'All sections') + ' | ' + sapInventoryTransfers.length + ' SAP IT document(s)';

            if (data.month_start && data.month_end) {
                msg += ' | ' + data.month_start + ' to ' + data.month_end;
            }

            if (data.limit) {
                msg += ' | latest ' + data.limit;
            }

            if (data.limited) {
                msg += ' (limited)';
            }

            status.textContent = msg;
        }
    } catch (e) {
        sapInventoryTransfers = [];
        document.getElementById('sapItCount').textContent = '0';
        renderSapInventoryTransfers();

        if (status) {
            status.textContent = 'Unable to load SAP ITs. Check SAP connection, API file, or login session.';
        }

        console.error(e);
    } finally {
        sapItLoading = false;
    }
}

async function loadStocks() {
    const status = document.getElementById('stockStatus');
    status.textContent = 'Refreshing stock...';

    try {
        const res = await fetch('api/stocks/list.php?scope=requestor', { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            stockRows = [];
            document.getElementById('stockCount').textContent = '0';
            renderStocks();
            status.textContent = data.message || 'Unable to load stock.';
            return;
        }

        stockRows = data.stocks || [];
        document.getElementById('stockCount').textContent = stockRows.length;
        renderStocks();
        status.textContent = (data.warehouses || []).join(', ') + ' | ' + stockRows.length + ' stocked item(s)';
    } catch (e) {
        stockRows = [];
        document.getElementById('stockCount').textContent = '0';
        renderStocks();
        status.textContent = 'Unable to load stock. Check SAP connection or login session.';
    }
}

function sapItMatchesSearch(doc, search) {
    if (!search) {
        return true;
    }

    const haystack = [
        doc.it_number,
        doc.itr_number,
        doc.it_date,
        ...(doc.lines || []).flatMap(line => [
            line.item_code,
            line.part_name,
            line.lot_no,
            line.from_whs_code,
            line.to_whs_code
        ])
    ].join(' ').toLowerCase();

    return haystack.includes(search);
}

function renderSapInventoryTransfers() {
    const list = document.getElementById('sapItList');
    const search = (document.getElementById('sapItSearchInput')?.value || '').trim().toLowerCase();
    list.innerHTML = '';

    const rows = sapInventoryTransfers.filter(doc => sapItMatchesSearch(doc, search));

    if (rows.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No SAP ITs found for the current filter.</div>';
        return;
    }

    rows.forEach(doc => {
        const originalIdx = sapInventoryTransfers.indexOf(doc);
        const active = selectedSapIt && String(selectedSapIt.it_number) === String(doc.it_number) ? ' active' : '';
        const lineCount = Number(doc.line_count || (doc.lines || []).length || 0);
        const lotCount = Number(doc.lot_count || 0);

        list.insertAdjacentHTML('beforeend', `
            <div class="itr-card${active}">
                <button type="button" class="itr-header" onclick="loadSapInventoryTransfer(${originalIdx})">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div class="request-title">IT ${esc(doc.it_number)}</div>
                            <div class="request-meta">Based on ITR ${esc(doc.itr_number)} | ${esc(doc.it_date)}</div>
                        </div>
                        <span class="badge text-bg-success rounded-pill">Load</span>
                    </div>
                    <div class="qty-grid">
                        <div class="qty-box"><div class="label">Total Qty</div><div class="value">${fmtQty(doc.total_qty)}</div></div>
                        <div class="qty-box"><div class="label">Rows</div><div class="value">${esc(lineCount)}</div></div>
                        <div class="qty-box"><div class="label">Lots</div><div class="value">${esc(lotCount)}</div></div>
                        <div class="qty-box"><div class="label">Status</div><div class="value">Ready</div></div>
                    </div>
                </button>
            </div>
        `);
    });
}

function loadSapInventoryTransfer(idx) {
    const doc = sapInventoryTransfers[idx];

    if (!doc) {
        return;
    }

    if (requestItems.length > 0 && !confirm('Load this SAP IT and replace the current table?')) {
        return;
    }

    selectedSapIt = doc;
    selectedDocument = null;
    editingRequest = null;

    requestItems = (doc.lines || []).map(line => {
        const lotQty = Number(line.lot_qty || 0) > 0 ? line.lot_qty : line.transfer_qty;
        const toWhs = line.to_whs_code || '';
        const fromWhs = line.from_whs_code || '';

        return {
            sap_it_mode: true,
            it_number: doc.it_number,
            itr_number: doc.itr_number,
            it_date: doc.it_date,
            doc_entry: line.it_doc_entry || doc.it_doc_entry || '',
            doc_num: doc.it_number,
            line_num: line.it_line_num ?? '',
            itr_line_num: line.itr_line_num ?? '',
            item_code: line.item_code,
            part_name: line.part_name || '',
            lot_no: line.lot_no || '',
            transfer_qty: lotQty,
            remaining_qty: lotQty,
            uom: line.uom || '',
            source_stock_qty: Number(line.source_stock_qty ?? findWarehouseStock(line.item_code, fromWhs) ?? 0),
            destination_stock_qty: Number(line.destination_stock_qty ?? findWarehouseStock(line.item_code, toWhs) ?? 0),
            from_whs_code: fromWhs,
            to_whs_code: toWhs
        };
    });

    document.getElementById('loadedInfo').className = 'alert alert-info info-box';
    document.getElementById('loadedInfo').textContent = 'Loaded SAP IT ' + doc.it_number + ' based on ITR ' + doc.itr_number + '. Showing SAP transfer records only.';
    document.getElementById('saveBtn').disabled = true;
    document.getElementById('saveBtn').textContent = 'SAP IT View Only';

    renderSapInventoryTransfers();
    renderItrs();
    renderMyRequests();
    renderTable();
}

function renderStocks() {
    const list = document.getElementById('stockList');
    const search = (document.getElementById('stockSearchInput')?.value || '').trim().toLowerCase();
    list.innerHTML = '';

    const rows = stockRows.filter(row => {
        if (!search) {
            return true;
        }

        return String(row.item_code || '').toLowerCase().includes(search) ||
            String(row.item_name || '').toLowerCase().includes(search) ||
            String(row.warehouse_code || '').toLowerCase().includes(search);
    });

    if (rows.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No stock items found.</div>';
        return;
    }

    rows.forEach(row => {
        list.insertAdjacentHTML('beforeend', `
            <div class="stock-card">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="stock-code" title="${esc(row.item_code)}">${esc(row.item_code)}</div>
                        <div class="stock-name" title="${esc(row.item_name)}">${esc(row.item_name)}</div>
                    </div>
                    <span class="badge text-bg-primary rounded-pill">${esc(row.warehouse_code)}</span>
                </div>

                <div class="stock-metrics">
                    <div class="stock-metric">
                        <div class="label">On Hand</div>
                        <div class="value">${fmtQty(row.on_hand_qty)}</div>
                    </div>
                    <div class="stock-metric">
                        <div class="label">Committed</div>
                        <div class="value">${fmtQty(row.committed_qty)}</div>
                    </div>
                    <div class="stock-metric">
                        <div class="label">On Order</div>
                        <div class="value">${fmtQty(row.on_order_qty)}</div>
                    </div>
                    <div class="stock-metric">
                        <div class="label">Available</div>
                        <div class="value">${fmtQty(row.available_qty)}</div>
                    </div>
                </div>
            </div>
        `);
    });
}

function itemMatchesSearch(item, search) {
    if (!search) {
        return true;
    }

    return String(item.item_code || '').toLowerCase().includes(search) ||
        String(item.part_name || '').toLowerCase().includes(search);
}

function itrMatchesItemSearch(doc, search) {
    if (!search) {
        return true;
    }

    const headerText = [
        doc.doc_num,
        doc.doc_date
    ].join(' ').toLowerCase();

    if (headerText.includes(search)) {
        return true;
    }

    return (doc.lines || []).some(line =>
        String(line.item_code || '').toLowerCase().includes(search) ||
        String(line.part_name || '').toLowerCase().includes(search)
    );
}

function renderItrs() {
    const list = document.getElementById('itrList');
    const search = (document.getElementById('itrItemSearchInput')?.value || '').trim().toLowerCase();
    list.innerHTML = '';

    if (openDocuments.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No open current-month ITRs from warehouse 01 found.</div>';
        return;
    }

    const rows = openDocuments
        .map((doc, idx) => ({ doc, idx }))
        .filter(row => itrMatchesItemSearch(row.doc, search));

    if (rows.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No ITRs found for that SAP code or part name.</div>';
        return;
    }

    rows.forEach(({ doc, idx }) => {
        const active = selectedDocument && selectedDocument.doc_num === doc.doc_num ? ' active' : '';

        list.insertAdjacentHTML('beforeend', `
            <div class="itr-card${active}">
                <button type="button" class="itr-header" onclick="loadItr(${idx})">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="request-title">ITR ${esc(doc.doc_num)}</div>
                            <div class="request-meta">${esc(doc.doc_date)} | ${esc(doc.line_count)} WH 01 item(s)</div>
                        </div>
                        <span class="badge text-bg-primary rounded-pill">Load</span>
                    </div>

                    <div class="qty-grid">
                        <div class="qty-box">
                            <div class="label">Requested</div>
                            <div class="value">${fmtQty(doc.requested_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">Open</div>
                            <div class="value">${fmtQty(doc.open_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">App Request</div>
                            <div class="value">${fmtQty(doc.app_requested_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">Remaining</div>
                            <div class="value">${fmtQty(doc.remaining_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">Your WH Stock</div>
                            <div class="value">${fmtQty(doc.destination_stock_qty)}</div>
                        </div>
                    </div>
                </button>
            </div>
        `);
    });
}

function renderMyRequests() {
    const list = document.getElementById('myRequestList');
    list.innerHTML = '';

    if (myRequests.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No open requests found.</div>';
        return;
    }

    myRequests.forEach((doc, idx) => {
        const active = editingRequest && Number(editingRequest.request_id) === Number(doc.request_id) ? ' active' : '';
        const disabled = doc.editable ? '' : ' disabled';
        const note = doc.editable ? 'Ready to edit' : 'Already issued or partially issued';

        list.insertAdjacentHTML('beforeend', `
            <div class="itr-card${active}">
                <div class="itr-header">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="request-title">${esc(doc.request_no)}</div>
                            <div class="request-meta">ITR ${esc(doc.itr_number)} | Needed ${esc(doc.needed_date)} | ${esc(doc.line_count)} line(s)</div>
                            <div class="request-meta">${esc(note)}${doc.remarks ? ' | ' + esc(doc.remarks) : ''}</div>
                        </div>
                        <span class="badge text-bg-warning rounded-pill">${esc(doc.status)}</span>
                    </div>

                    <div class="qty-grid">
                        <div class="qty-box">
                            <div class="label">Requested</div>
                            <div class="value">${fmtQty(doc.requested_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">Issued</div>
                            <div class="value">${fmtQty(doc.issued_qty)}</div>
                        </div>
                    </div>

                    <div class="request-actions">
                        <button type="button" class="btn btn-outline-primary" onclick="loadSavedRequest(${idx})"${disabled}>
                            Edit
                        </button>
                        <button type="button" class="btn btn-outline-danger" onclick="deleteSavedRequest(${idx})"${disabled}>
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        `);
    });
}

function loadItr(idx) {
    const doc = openDocuments[idx];

    if (!doc) {
        return;
    }

    if (requestItems.length > 0 && !confirm('Load this ITR and replace the current request table?')) {
        return;
    }

    selectedDocument = doc;
    selectedSapIt = null;
    editingRequest = null;
    document.getElementById('saveBtn').textContent = 'Submit Issue Request';

    requestItems = doc.lines
        .filter(l => Number(l.remaining_qty) > 0)
        .map(line => ({
            doc_entry: line.doc_entry,
            doc_num: line.doc_num,
            line_num: line.line_num,
            item_code: line.item_code,
            part_name: line.part_name || '',
            app_requested_qty: line.app_requested_qty || 0,
            remaining_qty: line.remaining_qty,
            uom: line.uom || '',
            num_per_msr: line.num_per_msr || 1,
            source_stock_qty: line.source_stock_qty || 0,
            destination_stock_qty: line.destination_stock_qty || 0,
            from_whs_code: line.from_whs_code || '01',
            to_whs_code: line.to_whs_code || '',
            request_qty: ''
        }));

    document.getElementById('loadedInfo').className = 'alert alert-info info-box';
    document.getElementById('loadedInfo').textContent = 'Loaded ITR ' + doc.doc_num + '. Enter quantity only for items needed on the selected date.';

    renderItrs();
    renderMyRequests();
    renderTable();
}

function loadSavedRequest(idx) {
    const doc = myRequests[idx];

    if (!doc || !doc.editable) {
        alert('This request is no longer editable.');
        return;
    }

    if (requestItems.length > 0 && !confirm('Load this request and replace the current request table?')) {
        return;
    }

    editingRequest = doc;
    selectedDocument = null;
    selectedSapIt = null;

    document.getElementById('neededDate').value = doc.needed_date || '';
    document.getElementById('remarksInput').value = doc.remarks || '';

    requestItems = (doc.lines || []).map(line => ({
        request_line_id: line.request_line_id,
        doc_entry: line.doc_entry,
        doc_num: line.doc_num || doc.itr_number,
        line_num: line.line_num,
        item_code: line.item_code,
        part_name: line.part_name || '',
        app_requested_qty: line.issued_qty || 0,
        remaining_qty: line.requested_qty,
        uom: line.uom || '',
        num_per_msr: line.num_per_msr || 1,
        source_stock_qty: line.source_stock_qty || 0,
        destination_stock_qty: line.destination_stock_qty || 0,
        from_whs_code: line.from_whs_code || '01',
        to_whs_code: line.to_whs_code || '',
        request_qty: line.requested_qty
    }));

    document.getElementById('loadedInfo').className = 'alert alert-warning info-box';
    document.getElementById('loadedInfo').textContent = 'Editing ' + doc.request_no + '. Remove a line to cancel that line, or use Delete to cancel the whole request.';
    document.getElementById('saveBtn').textContent = 'Update Issue Request';

    renderItrs();
    renderMyRequests();
    renderTable();
}

function renderTable() {
    const tb = document.querySelector('#itemsTable tbody');
    tb.innerHTML = '';

    const sapItMode = Boolean(selectedSapIt);
    const table = document.getElementById('itemsTable');
    const headerRow = table.querySelector('thead tr');

    if (sapItMode) {
        headerRow.innerHTML = `
            <th class="col-item">SAP ItemCode</th>
            <th class="col-part">Part Name</th>
            <th class="col-line">IT</th>
            <th class="col-requested">Qty</th>
            <th class="col-remaining">UOM</th>
            <th class="col-stock">Remaining</th>
            <th class="col-qty">WH Stock</th>
            <th class="col-action">Lot No</th>
        `;
    } else {
        headerRow.innerHTML = `
            <th class="col-item">SAP ItemCode</th>
            <th class="col-part">Part Name</th>
            <th class="col-line">ITR/Line</th>
            <th class="col-requested">Already Requested</th>
            <th class="col-remaining">Remaining</th>
            <th class="col-stock">Your WH Stock</th>
            <th class="col-qty">Qty to Request</th>
            <th class="col-action"></th>
        `;
    }

    const itemSearch = (document.getElementById('itemSearchInput')?.value || '').trim().toLowerCase();
    const visibleItems = requestItems
        .map((it, idx) => ({ it, idx }))
        .filter(row => itemMatchesSearch(row.it, itemSearch));

    if (requestItems.length > 0 && visibleItems.length === 0) {
        tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No items found for that SAP code or part name.</td></tr>';
    }

    visibleItems.forEach(({ it, idx }) => {
        if (sapItMode) {
            tb.insertAdjacentHTML('beforeend', `
                <tr>
                    <td class="col-item" title="${esc(it.item_code)}">${esc(it.item_code)}</td>
                    <td class="col-part" title="${esc(it.part_name)}">${esc(it.part_name)}</td>
                    <td class="col-line" title="IT ${esc(it.it_number)} / line ${esc(it.line_num)} | ITR ${esc(it.itr_number)} / line ${esc(it.itr_line_num)}">
                        ${esc(it.it_number)}
                        <div class="small text-muted">Line ${esc(it.line_num)}</div>
                    </td>
                    <td class="col-requested text-end" title="${fmtQty(it.transfer_qty)}">${fmtQty(it.transfer_qty)}</td>
                    <td class="col-remaining" title="${esc(it.uom || '')}">${esc(it.uom || '')}</td>
                    <td class="col-stock text-end" title="${fmtQty(it.remaining_qty)}">${fmtQty(it.remaining_qty)}</td>
                    <td class="col-qty text-end" title="${esc(it.to_whs_code || '')} stock">
                        ${fmtQty(it.destination_stock_qty)}
                        <div class="small text-muted">${esc(it.to_whs_code || '')}</div>
                    </td>
                    <td class="col-action" title="${esc(it.lot_no || 'No SAP batch/lot recorded')}">${esc(it.lot_no || 'No lot')}</td>
                </tr>
            `);
            return;
        }

        tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="col-item" title="${esc(it.item_code)}">${esc(it.item_code)}</td>
                <td class="col-part" title="${esc(it.part_name)}">${esc(it.part_name)}</td>
                <td class="col-line" title="${esc(it.doc_num)} / ${esc(it.line_num)}">${esc(it.doc_num)} / ${esc(it.line_num)}</td>
                <td class="col-requested" title="${fmtQty(it.app_requested_qty)}">${fmtQty(it.app_requested_qty)}</td>
                <td class="col-remaining" title="${fmtQty(it.remaining_qty)} ${esc(it.uom || '')}">
                    ${fmtQty(it.remaining_qty)}
                    ${it.uom ? `<div class="small text-muted">${esc(it.uom)}</div>` : ''}
                </td>
                <td class="col-stock" title="${esc(it.to_whs_code || 'Your warehouse')} stock: ${fmtQty(it.destination_stock_qty)}${it.from_whs_code ? ' | ' + esc(it.from_whs_code) + ' stock: ' + fmtQty(it.source_stock_qty) : ''}">
                    <div>${fmtQty(it.destination_stock_qty)}</div>
                    <div class="small text-muted">${esc(it.to_whs_code || 'WH')}</div>
                </td>
                <td class="col-qty">
                    <input
                        class="form-control form-control-sm table-input"
                        type="number"
                        min="0"
                        max="${esc(it.remaining_qty)}"
                        step="0.001"
                        id="qty_${idx}"
                        value="${esc(it.request_qty)}"
                        onchange="requestItems[${idx}].request_qty=this.value"
                    >
                </td>
                <td class="col-action">
                    <button class="btn btn-sm btn-outline-danger remove-btn" onclick="removeLine(${idx})">
                        Remove
                    </button>
                </td>
            </tr>
        `);
    });

    document.getElementById('countBadge').textContent = itemSearch ? visibleItems.length + ' of ' + requestItems.length + ' line(s)' : requestItems.length + ' line(s)';

    if (sapItMode) {
        document.getElementById('saveBtn').disabled = true;
        document.getElementById('saveBtn').textContent = 'SAP IT View Only';
    } else {
        document.getElementById('saveBtn').disabled = requestItems.length === 0;
        document.getElementById('saveBtn').textContent = editingRequest ? 'Update Issue Request' : 'Submit Issue Request';
    }
}

function removeLine(idx) {
    requestItems.splice(idx, 1);
    renderTable();
}

async function postForm(url, fields) {
    const body = new FormData();

    Object.keys(fields).forEach(k => {
        body.append(k, fields[k]);
    });

    const res = await fetch(url, {
        method: 'POST',
        body: body
    });

    const text = await res.text();
    let data = null;

    try {
        data = JSON.parse(text);
    } catch (e) {
        data = {
            ok: false,
            message: text || 'Unexpected server response.'
        };
    }

    if (!res.ok && data.ok !== false) {
        data.ok = false;
    }

    return data;
}

function clearRequestForm() {
    selectedDocument = null;
    selectedSapIt = null;
    editingRequest = null;
    requestItems = [];
    document.getElementById('remarksInput').value = '';
    document.getElementById('loadedInfo').className = 'alert alert-secondary info-box';
    document.getElementById('loadedInfo').textContent = 'No ITR loaded.';
    document.getElementById('saveBtn').textContent = 'Submit Issue Request';
    renderItrs();
    renderMyRequests();
    renderTable();
}

function showSuccessModal(data) {
    document.getElementById('requestSavedMessage').textContent = data.message || 'Issue request saved successfully.';
    document.getElementById('modalRequestNo').textContent = data.request_no || '-';
    document.getElementById('modalItrNumber').textContent = data.itr_number || (editingRequest ? editingRequest.itr_number : '-');
    document.getElementById('modalNeededDate').textContent = data.needed_date || document.getElementById('neededDate').value || '-';
    document.getElementById('modalSavedLines').textContent = data.saved_lines ?? '-';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('requestSavedModal')).show();
}

async function saveRequest() {
    if (selectedSapIt) {
        alert('SAP IT records are view-only. Select an Open ITR or My Open request to submit/update an issue request.');
        return;
    }

    if (!selectedDocument && !editingRequest) {
        alert('Load an ITR first.');
        return;
    }

    const neededDate = document.getElementById('neededDate').value;

    if (!neededDate) {
        alert('Needed date is required.');
        return;
    }

    requestItems.forEach((it, idx) => {
        const el = document.getElementById('qty_' + idx);

        if (el) {
            it.request_qty = el.value.trim();
        }
    });

    const lines = requestItems.filter(it => Number(it.request_qty) > 0);

    if (lines.length === 0) {
        alert('Enter quantity for at least one item.');
        return;
    }

    for (let i = 0; i < lines.length; i++) {
        if (
            Number(lines[i].request_qty) > Number(lines[i].remaining_qty) &&
            !confirm('Line ' + (i + 1) + ' request qty is greater than remaining ITR qty. Continue?')
        ) {
            return;
        }
    }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = editingRequest ? 'Updating...' : 'Saving...';

    const fields = editingRequest ? {
        request_id: editingRequest.request_id,
        needed_date: neededDate,
        remarks: document.getElementById('remarksInput').value.trim(),
        batch_items: JSON.stringify(lines)
    } : {
        ajax: '1',
        needed_date: neededDate,
        remarks: document.getElementById('remarksInput').value.trim(),
        itr_number: selectedDocument.doc_num,
        itr_doc_entry: selectedDocument.doc_entry,
        batch_items: JSON.stringify(lines)
    };

    try {
        const data = await postForm(
            editingRequest ? 'api/requestor/update_request.php' : 'actions/save_issue_request.php',
            fields
        );

        if (!data.ok) {
            alert(data.message || 'Unable to save request.');
            return;
        }

        const modalData = {
            ...data,
            itr_number: data.itr_number || (editingRequest ? editingRequest.itr_number : selectedDocument.doc_num),
            needed_date: data.needed_date || neededDate,
            saved_lines: data.saved_lines ?? lines.length
        };

        showSuccessModal(modalData);
        clearRequestForm();
        await loadOpenItrs();
        await loadMyRequests();
    } catch (e) {
        alert('Unable to save request. Check connection or login session.');
    } finally {
        saveBtn.disabled = requestItems.length === 0;
        saveBtn.textContent = editingRequest ? 'Update Issue Request' : 'Submit Issue Request';
    }
}

async function deleteSavedRequest(idx) {
    const doc = myRequests[idx];

    if (!doc || !doc.editable) {
        alert('This request is no longer deletable.');
        return;
    }

    if (!confirm('Delete ' + doc.request_no + '? This will cancel the request and return its quantities to the available ITR balance.')) {
        return;
    }

    try {
        const data = await postForm('api/requestor/delete_request.php', {
            request_id: doc.request_id
        });

        if (!data.ok) {
            alert(data.message || 'Unable to delete request.');
            return;
        }

        if (editingRequest && Number(editingRequest.request_id) === Number(doc.request_id)) {
            clearRequestForm();
        }

        showSuccessModal({
            message: data.message || 'Request cancelled successfully.',
            request_no: data.request_no || doc.request_no,
            itr_number: doc.itr_number,
            needed_date: doc.needed_date,
            saved_lines: 0
        });

        await loadOpenItrs();
        await loadMyRequests();
    } catch (e) {
        alert('Unable to delete request. Check connection or login session.');
    }
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

const requestorRefresh = window.createRefreshController([
    { name: 'requestorItrs', fn: loadOpenItrs, intervalMs: 60000 },
    { name: 'requestorRequests', fn: loadMyRequests, intervalMs: 60000 },
    { name: 'requestorStocks', fn: loadStocks, intervalMs: 120000 }
]);

requestorRefresh.scheduleAll();

const sapItsTabEl = document.getElementById('sapItsTab');
if (sapItsTabEl) {
    sapItsTabEl.addEventListener('click', function () {
        const status = document.getElementById('sapItStatus');

        if (status && sapInventoryTransfers.length === 0 && !sapItLoading) {
            status.textContent = 'Click Reload to load the latest SAP ITs.';
        }
    });

    sapItsTabEl.addEventListener('shown.bs.tab', function () {
        const status = document.getElementById('sapItStatus');

        if (status && sapInventoryTransfers.length === 0 && !sapItLoading) {
            status.textContent = 'Click Reload to load the latest SAP ITs.';
        }
    });
}

</script>

</body>
</html>
