<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <title>Issuer - Warehouse</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
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

        .selected-box {
            border: 1px solid #b6d4fe;
            background: #f3f8ff;
            border-radius: 14px;
            padding: 12px;
        }

        .issuer-table-wrap {
            max-height: 63vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .issuer-table {
            width: 100%;
            table-layout: fixed;
            font-size: 10.5px;
            margin-bottom: 0;
        }

        .issuer-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 8.5px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 7px 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .issuer-table td {
            padding: 6px 4px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .issuer-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-item {
            width: 10%;
            white-space: nowrap;
        }

        .col-scanned {
            width: 13%;
            white-space: nowrap;
        }

        .col-part {
            width: 16%;
            white-space: normal;
            line-height: 1.25;
        }

        .col-stock {
            width: 9%;
            text-align: right;
            white-space: nowrap;
        }

        .col-requested {
            width: 10%;
            text-align: right;
            white-space: nowrap;
        }

        .col-qty {
            width: 10%;
            white-space: nowrap;
        }

        .col-lot {
            width: 12%;
            white-space: nowrap;
        }

        .col-wh-lot {
            width: 12%;
            white-space: nowrap;
        }

        .col-itr {
            width: 8%;
            white-space: nowrap;
        }

        .col-match {
            width: 9%;
            white-space: nowrap;
        }

        .col-action {
            width: 7%;
            text-align: center;
            white-space: nowrap;
        }

        .table-input,
        .lot-input {
            width: 100%;
            min-width: 0;
            height: 34px;
            font-size: 11px;
            padding: 4px 6px;
            border-radius: 8px;
        }

        .remove-btn {
            font-size: 10px;
            padding: 4px 5px;
            border-radius: 8px;
        }

        .requests-panel {
            max-height: calc(100vh - 210px);
            overflow-y: auto;
            padding-right: 4px;
        }

        .side-panel-list {
            max-height: calc(100vh - 300px);
        }

        .request-tabs {
            gap: 6px;
            border-bottom: 0;
        }

        .request-tabs .nav-link {
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            color: #4b5563;
            font-size: 13px;
            font-weight: 800;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .request-tabs .nav-link.active {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #ffffff;
        }

        .tab-count {
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 7px;
            background: #e5e7eb;
            color: #111827;
            font-size: 12px;
            font-weight: 900;
            line-height: 1;
        }

        .request-tabs .nav-link.active .tab-count {
            background: #ffffff;
            color: #0d6efd;
        }

        .stock-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .stock-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 8px;
        }

        .stock-code {
            font-size: 13px;
            font-weight: 900;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stock-name {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
            line-height: 1.25;
        }

        .stock-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px;
            margin-top: 10px;
        }

        .stock-metric {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            border-radius: 9px;
            padding: 7px;
        }

        .stock-metric .label {
            font-size: 10px;
            color: #6b7280;
            font-weight: 800;
        }

        .stock-metric .value {
            font-size: 13px;
            font-weight: 900;
            color: #111827;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .itr-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            margin-bottom: 10px;
            color: #212529;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .itr-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.07);
        }

        .itr-card.active {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, .12);
        }

        .itr-header {
            width: 100%;
            border: 0;
            background: #ffffff;
            text-align: left;
            padding: 14px;
        }

        .itr-header:hover {
            background: #f8fbff;
        }

        .request-title {
            font-weight: 800;
            line-height: 1.2;
            color: #111827;
        }

        .request-meta {
            font-size: 12px;
            color: #6b7280;
            margin-top: 3px;
        }

        .qty-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 7px;
            margin-top: 12px;
        }

        .qty-box {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            border-radius: 10px;
            padding: 8px;
        }

        .qty-box .label {
            font-size: 10px;
            color: #6b7280;
            font-weight: 700;
        }

        .qty-box .value {
            font-size: 13px;
            font-weight: 800;
            color: #111827;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lot-list {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #eef2f7;
        }

        .lot-list-title {
            font-size: 10px;
            color: #6b7280;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .lot-line {
            margin-bottom: 8px;
        }

        .lot-line:last-child {
            margin-bottom: 0;
        }

        .lot-item {
            font-size: 11px;
            font-weight: 800;
            color: #374151;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .lot-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }

        .lot-pill,
        .lot-empty {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border-radius: 999px;
            padding: 3px 7px;
            font-size: 10.5px;
            line-height: 1.2;
        }

        .lot-pill {
            background: #eef6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-weight: 800;
        }

        .lot-empty {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            color: #6b7280;
            font-weight: 700;
        }

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
        }

        @media (max-width: 1300px) {
            .issuer-table {
                font-size: 9.5px;
            }

            .issuer-table thead th {
                font-size: 7.8px;
                padding: 6px 3px;
            }

            .issuer-table td {
                padding: 5px 3px;
            }

            .table-input,
            .lot-input {
                height: 32px;
                font-size: 10px;
                padding: 3px 5px;
            }

            .remove-btn {
                font-size: 9px;
                padding: 4px 4px;
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

            .requests-panel {
                max-height: none;
                padding-right: 0;
            }

            .issuer-table-wrap {
                overflow: auto;
            }

            .issuer-table {
                min-width: 1000px;
                table-layout: auto;
                font-size: 12px;
            }

            .issuer-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .issuer-table td {
                padding: 7px 6px;
                white-space: nowrap;
            }

            .col-item,
            .col-scanned,
            .col-part,
            .col-stock,
            .col-requested,
            .col-qty,
            .col-lot,
            .col-wh-lot,
            .col-itr,
            .col-match,
            .col-action {
                width: auto;
                min-width: 100px;
            }

            .col-part {
                min-width: 220px;
            }

            .col-lot {
                min-width: 150px;
            }

            .col-wh-lot {
                min-width: 150px;
            }
        }

        /* Tablet/WebView fix: keep desktop layout and keep right panel usable */
        @media (max-width: 1366px) and (min-width: 901px) {
            .main-content > .row.g-3 {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: flex-start !important;
            }

            .main-content > .row.g-3 > .col-xl-8 {
                flex: 0 0 68% !important;
                max-width: 68% !important;
                width: 68% !important;
                order: 1 !important;
            }

            .main-content > .row.g-3 > .col-xl-4 {
                flex: 0 0 32% !important;
                max-width: 32% !important;
                width: 32% !important;
                order: 2 !important;
            }

            #issuerSideTabs {
                display: flex !important;
            }

            .content-card-body > .tab-content {
                display: block !important;
            }

            .content-card-body > .tab-content > .tab-pane {
                display: none !important;
            }

            .content-card-body > .tab-content > .tab-pane.show.active,
            .content-card-body > .tab-content > .tab-pane.webview-tab-active {
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .issuer-table-wrap {
                max-height: calc(100vh - 360px) !important;
                overflow-y: auto !important;
                overflow-x: auto !important;
            }

            .issuer-table {
                min-width: 980px;
                table-layout: auto;
            }
        }

        /* WebView right panel fix: request and stock lists stay scrollable instead of being clipped */
        .col-xl-4 .content-card,
        .col-xl-4 .content-card-body,
        #issueRequestsPane,
        #issuerStockPane {
            overflow: visible !important;
        }

        #requestList,
        #stockList {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            min-height: 260px !important;
            max-height: calc(100vh - 335px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 8px 4px 12px 0 !important;
            -webkit-overflow-scrolling: touch;
        }

        #requestList .itr-card,
        #stockList .stock-card {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            height: auto !important;
            min-height: auto !important;
            overflow: visible !important;
            margin-bottom: 12px !important;
        }

        #requestList .itr-header {
            display: block !important;
            width: 100% !important;
            height: auto !important;
            min-height: auto !important;
            overflow: visible !important;
            white-space: normal !important;
            cursor: default;
        }

        #requestList .qty-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
            margin-top: 12px !important;
        }

        #requestList .qty-box {
            display: block !important;
            min-height: 56px !important;
        }

        /* Manual tab state used as a fallback when Bootstrap tabs do not behave well in WebView */
        #issueRequestsPane,
        #issuerStockPane {
            display: none !important;
        }

        #issueRequestsPane.webview-tab-active,
        #issuerStockPane.webview-tab-active {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        @media (max-width: 900px) {
            #requestList,
            #stockList {
                max-height: 60vh !important;
                overflow-y: auto !important;
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
        <div class="shell-subtitle">Issuer workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('issuer'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>Issuer Warehouse</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">Issuer - Warehouse</h4>
                <div class="page-subtitle">
                    Load open issue requests, keep the GRPO lot for app scanning, enter warehouse lot numbers, then save issuance.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2" id="countBadge">
                0 item(s)
            </span>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">Issuance Details</h5>
                        <div class="content-card-subtitle">
                            Select an open issue request from the right panel to load the issuance table.
                        </div>
                    </div>

                    <div class="content-card-body">

                        <div id="selectedRequestBox" class="selected-box mb-3 d-none">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="small text-muted">Loaded request</div>
                                    <div class="fw-bold" id="selectedRequestTitle"></div>
                                    <div class="small" id="selectedRequestDetails"></div>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary align-self-start" type="button" onclick="clearSelectedRequest()">
                                    Clear
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted" for="pickerQrInput">
                                Picker QR Scan
                            </label>
                            <div class="input-group">
                                <input
                                    class="form-control"
                                    id="pickerQrInput"
                                    placeholder="Scan picker QR: (01)ItemCode(17)Qty(10)Lot"
                                    autocomplete="off"
                                >
                                <button class="btn btn-outline-primary" type="button" onclick="applyPickerQrScan()">
                                    Apply
                                </button>
                            </div>
                            <div class="small text-muted mt-1">
                                Load a request first, then scan the picker tag to fill item quantity and GRPO lot.
                            </div>
                        </div>

                        <div class="issuer-table-wrap">
                            <table class="table table-bordered table-striped align-middle issuer-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th class="col-item">SAP ItemCode</th>
                                        <th class="col-scanned">Scanned Code</th>
                                        <th class="col-part">Part Name</th>
                                        <th class="col-stock">WH 01 Stock</th>
                                        <th class="col-requested">Qty Requested</th>
                                        <th class="col-qty">Qty to Issue</th>
                                        <th class="col-lot">GRPO Lot No</th>
                                        <th class="col-wh-lot">WH Lot No</th>
                                        <th class="col-match">Lot Balance</th>
                                        <th class="col-itr">ITR/IT</th>
                                        <th class="col-action">Remove</th>
                                    </tr>
                                </thead>

                                <tbody></tbody>
                            </table>
                        </div>

                        <div class="text-end mt-3 d-flex justify-content-end gap-2">
                            <button id="printBtn" class="btn btn-outline-primary" onclick="printAllIssueTags()" disabled>
                                Print All Tags
                            </button>
                            <button id="saveBtn" class="btn btn-success" onclick="saveItems()" disabled>
                                Save Issuance and Generate Trace QR
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
                                <h5 class="content-card-title">Warehouse</h5>
                                <div class="content-card-subtitle">
                                    Process requests or check WH 01 stock.
                                </div>
                            </div>

                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="refreshSideTabs()">
                                Refresh
                            </button>
                        </div>

                        <ul class="nav request-tabs" id="issuerSideTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="issueRequestsTab" data-bs-toggle="tab" data-bs-target="#issueRequestsPane" type="button" role="tab" aria-controls="issueRequestsPane" aria-selected="true">
                                    Requests
                                    <span class="tab-count" id="requestCount">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="issuerStockTab" data-bs-toggle="tab" data-bs-target="#issuerStockPane" type="button" role="tab" aria-controls="issuerStockPane" aria-selected="false">
                                    Stock
                                    <span class="tab-count" id="stockCount">0</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="content-card-body">
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="issueRequestsPane" role="tabpanel" aria-labelledby="issueRequestsTab" tabindex="0">
                                <div class="small text-muted mb-2" id="requestStatus">
                                    Loading requests...
                                </div>

                                <div id="requestList" class="requests-panel side-panel-list"></div>
                            </div>

                            <div class="tab-pane fade" id="issuerStockPane" role="tabpanel" aria-labelledby="issuerStockTab" tabindex="0">
                                <div class="stock-toolbar">
                                    <input class="form-control form-control-sm" id="stockSearchInput" placeholder="Search WH 01 item" oninput="renderStocks()">
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

<div class="modal fade" id="loadRequestModal" tabindex="-1" aria-labelledby="loadRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="loadRequestModalLabel">
                    Load Request
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2 fw-semibold">
                    Load this request and replace the current issuance table?
                </div>
                <div class="text-muted small">
                    Existing unsaved lines in the issuance table will be cleared.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmLoadRequestBtn">
                    Load Request
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="overQtyModal" tabindex="-1" aria-labelledby="overQtyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="overQtyModalLabel">
                    Quantity Warning
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2 fw-semibold" id="overQtyMessage">
                    Quantity is greater than remaining request quantity.
                </div>
                <div class="text-muted small">
                    Please confirm if you still want to continue saving this issuance.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button" class="btn btn-warning" id="confirmOverQtyBtn">
                    Continue Save
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="removeItemModal" tabindex="-1" aria-labelledby="removeItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="removeItemModalLabel">
                    Remove Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2 fw-semibold">
                    Remove this item from the issuance table?
                </div>
                <div class="text-muted small">
                    This will remove the selected row from the current issuance list.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmRemoveItemBtn">
                    Remove
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/app-refresh.js"></script>

<script>
let items = [];
let openRequests = [];
let openDocuments = [];
let selectedDocument = null;
let pendingLoadDocIdx = null;
let pendingSaveAfterOverQty = false;
let pendingRemoveItemIdx = null;
let stockRows = [];

function fmtQty(v) {
    const n = Number(v || 0);
    return Number.isInteger(n) ? String(n) : n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

function lotKey(itemCode, lotNo, whsCode = '01') {
    return String(itemCode || '').trim().toUpperCase() + '|' +
        String(lotNo || '').trim().toUpperCase() + '|' +
        String(whsCode || '01').trim().toUpperCase();
}

function pendingQtyForLot(itemCode, lotNo, whsCode = '01', excludeIdx = -1) {
    const key = lotKey(itemCode, lotNo, whsCode);
    let total = 0;

    items.forEach((row, idx) => {
        if (idx === excludeIdx) {
            return;
        }

        if (lotKey(row.item_code, row.lot_no, row.stock_whs_code || whsCode) === key) {
            total += Number(row.quantity || 0);
        }
    });

    return total;
}

function setLotStatus(idx, status, message, balance = null) {
    if (!items[idx]) {
        return;
    }

    items[idx].lot_status = status;
    items[idx].lot_message = message || '';

    if (balance) {
        items[idx].lot_received_qty = Number(balance.received_qty || 0);
        items[idx].lot_issued_qty = Number(balance.issued_qty || 0);
        items[idx].lot_available_qty = Number(balance.available_qty || 0);
        items[idx].lot_source = balance.source || '';
    }
}

function lotStatusHtml(it) {
    const status = String(it.lot_status || '').toLowerCase();

    if (status === 'valid') {
        return `<span class="badge text-bg-success">OK</span><div class="small text-muted">Avail ${fmtQty(it.lot_available_qty)}</div>`;
    }

    if (status === 'invalid') {
        return `<span class="badge text-bg-danger">Blocked</span><div class="small text-muted">${esc(it.lot_message || 'No balance')}</div>`;
    }

    if (status === 'checking') {
        return `<span class="badge text-bg-warning">Checking</span>`;
    }

    return `<span class="badge text-bg-secondary">Not checked</span>`;
}

async function loadOpenRequests() {
    const status = document.getElementById('requestStatus');
    status.textContent = 'Refreshing requests...';

    try {
        const res = await fetch('api/get_open_issue_requests.php', { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) {
            openRequests = [];
            openDocuments = [];
            document.getElementById('requestCount').textContent = '0';
            renderRequests();
            status.textContent = data.message || 'Unable to load requests.';
            return;
        }

        openRequests = data.requests || [];
        openDocuments = data.documents || groupRequestsByDocument(openRequests);
        document.getElementById('requestCount').textContent = openDocuments.length;

        renderRequests();

        const stamp = new Date().toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });

        status.textContent = openDocuments.length + ' open request(s), ' + openRequests.length + ' line(s), updated ' + stamp;
    } catch (e) {
        console.error('loadOpenRequests failed:', e);
        document.getElementById('requestCount').textContent = '0';
        status.textContent = 'Unable to load requests. Check WHPOKAYOKE connection, login session, or API path.';
    }
}

async function loadStocks() {
    const status = document.getElementById('stockStatus');
    status.textContent = 'Refreshing stock...';

    try {
        const res = await fetch('api/stocks/list.php?scope=issuer', { cache: 'no-store' });
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
        console.error('loadStocks failed:', e);
        stockRows = [];
        document.getElementById('stockCount').textContent = '0';
        renderStocks();
        status.textContent = 'Unable to load stock. Check SAP connection, login session, or API path.';
    }
}

async function refreshSideTabs() {
    await Promise.all([
        loadOpenRequests(),
        loadStocks()
    ]);
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
        list.innerHTML = '<div class="alert alert-light border">No stock items found.</div>';
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

function groupRequestsByDocument(lines) {
    const docs = {};

    lines.forEach(line => {
        const key = String(line.doc_num);

        if (!docs[key]) {
            docs[key] = {
                doc_entry: line.doc_entry,
                doc_num: key,
                doc_date: line.doc_date,
                line_count: 0,
                requested_qty: 0,
                open_qty: 0,
                issued_qty: 0,
                remaining_qty: 0,
                warehouse_stock_qty: 0,
                lines: []
            };
        }

        docs[key].line_count++;
        docs[key].requested_qty += Number(line.requested_qty || 0);
        docs[key].open_qty += Number(line.open_qty || 0);
        docs[key].issued_qty += Number(line.issued_qty || 0);
        docs[key].remaining_qty += Number(line.remaining_qty || 0);
        docs[key].warehouse_stock_qty += Number(line.warehouse_stock_qty || 0);
        docs[key].lines.push(line);
    });

    return Object.values(docs);
}

function requestLotListHtml(doc) {
    const lines = Array.isArray(doc.lines) ? doc.lines : [];

    if (lines.length === 0) {
        return '';
    }

    const rows = lines.map(line => {
        const lots = Array.isArray(line.available_lots) ? line.available_lots : [];
        const requestedLot = String(line.lot_no || '').trim();
        let lotHtml = '';

        if (lots.length > 0) {
            lotHtml = lots.slice(0, 8).map(lot => {
                const lotNo = String(lot.lot_no || '').trim();
                const availableQty = Number(lot.available_qty || 0);
                const title = lotNo + ' | On hand ' + fmtQty(lot.on_hand_qty) +
                    ' | Committed ' + fmtQty(lot.committed_qty) +
                    ' | Available ' + fmtQty(availableQty);

                return `<span class="lot-pill" title="${esc(title)}">${esc(lotNo)} (${fmtQty(availableQty)})</span>`;
            }).join('');

            if (lots.length > 8) {
                lotHtml += `<span class="lot-empty">+${lots.length - 8} more</span>`;
            }
        } else if (requestedLot) {
            lotHtml = `<span class="lot-pill" title="Request lot">${esc(requestedLot)}</span>`;
        } else {
            lotHtml = '<span class="lot-empty">No WH 01 lot balance</span>';
        }

        return `
            <div class="lot-line">
                <div class="lot-item" title="${esc(line.item_code || '')}">${esc(line.item_code || 'Item')} | Remaining ${fmtQty(line.remaining_qty)}</div>
                <div class="lot-pills">${lotHtml}</div>
            </div>
        `;
    }).join('');

    return `
        <div class="lot-list">
            <div class="lot-list-title">WH 01 Lot Numbers</div>
            ${rows}
        </div>
    `;
}

function renderRequests() {
    const list = document.getElementById('requestList');
    list.innerHTML = '';

    if (openDocuments.length === 0) {
        list.innerHTML = '<div class="alert alert-light border">No open issue requests found.</div>';
        return;
    }

    openDocuments.forEach((doc, docIdx) => {
        const docActive = selectedDocument && (selectedDocument.request_no || selectedDocument.doc_num) === (doc.request_no || doc.doc_num) ? ' active' : '';

        list.insertAdjacentHTML('beforeend', `
            <div class="itr-card${docActive}">
                <div class="itr-header">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="request-title">${esc(doc.request_no || doc.doc_num)}</div>
                            <div class="request-meta">ITR ${esc(doc.itr_number || doc.doc_num)} | Needed ${esc(doc.needed_date || doc.doc_date)} | ${esc(doc.line_count)} item(s)</div>
                            <div class="request-meta">By ${esc(doc.requested_by || '')}${doc.remarks ? ' | ' + esc(doc.remarks) : ''}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill flex-shrink-0" onclick="loadDocumentItems(${docIdx})">
                            Load
                        </button>
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
                            <div class="label">Remaining</div>
                            <div class="value">${fmtQty(doc.remaining_qty)}</div>
                        </div>

                        <div class="qty-box">
                            <div class="label">WH 01 Stock</div>
                            <div class="value">${fmtQty(doc.warehouse_stock_qty)}</div>
                        </div>
                    </div>

                    ${requestLotListHtml(doc)}
                </div>
            </div>
        `);
    });
}

function loadDocumentItems(docIdx) {
    const doc = openDocuments[docIdx];

    if (!doc) {
        return;
    }

    if (items.length > 0) {
        pendingLoadDocIdx = docIdx;

        const modalEl = document.getElementById('loadRequestModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        return;
    }

    loadDocumentItemsConfirmed(docIdx);
}

function roundIssueQty(value) {
    return Math.round((Number(value || 0) + Number.EPSILON) * 1000) / 1000;
}

function buildIssueItemFromRequest(req) {
    return {
        item_code: req.item_code,
        scanned_code: (req.request_no ? req.request_no + ' / ' : '') + 'ITR ' + req.doc_num + ' line ' + req.line_num,
        part_name: req.part_name || '',
        quantity: req.remaining_qty,
        requested_qty: req.requested_qty || req.original_requested_qty || req.request_qty || req.remaining_qty,
        open_qty: req.open_qty,
        remaining_qty: req.remaining_qty,
        warehouse_stock_qty: req.warehouse_stock_qty || 0,
        stock_whs_code: req.stock_whs_code || '01',
        requested_lot_no: req.lot_no || '',
        available_lots: Array.isArray(req.available_lots) ? req.available_lots : [],
        lot_no: req.lot_no || '',
        warehouse_lot_no: req.warehouse_lot_no || '',
        itr_number: req.doc_num,
        itr_doc_entry: req.doc_entry,
        itr_doc_num: req.doc_num,
        itr_line_num: req.line_num,
        request_id: req.request_id || '',
        request_line_id: req.request_line_id || '',
        source_request_line_id: req.request_line_id || (String(req.doc_entry || '') + '-' + String(req.line_num || '') + '-' + String(req.item_code || '')),
        source_remaining_qty: req.remaining_qty,
        source_requested_qty: req.requested_qty || req.original_requested_qty || req.request_qty || req.remaining_qty,
        qty_per_pack: Number(req.qty_per_pack || 0),
        qty_per_pack_source: req.qty_per_pack_source || '',
        pack_row_no: 1,
        pack_row_count: 1,
        entry_method: 'SCAN',
        manual_reason: '',
        match_by: req.request_no ? 'Issue request' : 'SAP ITR request'
    };
}

function splitIssueItemByPack(baseItem) {
    const totalQty = roundIssueQty(baseItem.quantity);
    const qtyPerPack = roundIssueQty(baseItem.qty_per_pack);

    if (totalQty <= 0 || qtyPerPack <= 0 || totalQty <= qtyPerPack) {
        return [baseItem];
    }

    const rows = [];
    let remainingQty = totalQty;

    while (remainingQty > 0.0005) {
        const rowQty = roundIssueQty(Math.min(qtyPerPack, remainingQty));

        rows.push({
            ...baseItem,
            quantity: rowQty,
            lot_no: '',
            warehouse_lot_no: '',
            pack_row_no: rows.length + 1,
            pack_row_count: 0,
            lot_status: '',
            lot_message: '',
            lot_received_qty: 0,
            lot_issued_qty: 0,
            lot_available_qty: 0,
            lot_source: ''
        });

        remainingQty = roundIssueQty(remainingQty - rowQty);
    }

    return rows.map(row => ({
        ...row,
        pack_row_count: rows.length,
        scanned_code: row.scanned_code + ' pack ' + row.pack_row_no + '/' + rows.length
    }));
}

function loadDocumentItemsConfirmed(docIdx) {
    const doc = openDocuments[docIdx];

    if (!doc) {
        return;
    }

    selectedDocument = doc;

    items = doc.lines
        .filter(req => Number(req.remaining_qty) > 0)
        .flatMap(req => splitIssueItemByPack(buildIssueItemFromRequest(req)));

    document.getElementById('selectedRequestBox').classList.remove('d-none');
    document.getElementById('selectedRequestTitle').textContent = (doc.request_no || 'Request') + ' / ITR ' + (doc.itr_number || doc.doc_num);
    document.getElementById('selectedRequestDetails').textContent = doc.line_count + ' item(s), ' + items.length + ' issue row(s), needed ' + (doc.needed_date || doc.doc_date) + ', remaining total ' + fmtQty(doc.remaining_qty);

    renderRequests();
    render();

    // Keep the loaded issuance table visible after selecting a request in WebView/tablet.
    const tableWrap = document.querySelector('.issuer-table-wrap');
    if (tableWrap) {
        tableWrap.scrollTop = 0;
    }
}

function clearSelectedRequest() {
    selectedDocument = null;
    items = [];

    document.getElementById('selectedRequestBox').classList.add('d-none');

    renderRequests();
    render();
}


function requestLineKey(it) {
    return String(
        it.source_request_line_id ||
        it.request_line_id ||
        (String(it.itr_doc_entry || '') + '-' + String(it.itr_line_num || '') + '-' + String(it.item_code || ''))
    );
}

function requestLineLimit(it) {
    return Number(it.source_remaining_qty || it.remaining_qty || it.requested_qty || 0);
}

function totalIssueQtyForRequestLine(key, excludeIdx = -1) {
    let total = 0;

    items.forEach((row, idx) => {
        if (idx === excludeIdx) {
            return;
        }

        if (requestLineKey(row) === key) {
            total += Number(row.quantity || 0);
        }
    });

    return total;
}

function remainingToAllocateForLine(it, excludeIdx = -1) {
    const limit = requestLineLimit(it);
    const used = totalIssueQtyForRequestLine(requestLineKey(it), excludeIdx);
    return Math.max(0, limit - used);
}

function canRemoveLine(idx) {
    return !!items[idx];
}

function validateIssueTotals(showAlert = true) {
    const groups = {};

    items.forEach((it, idx) => {
        const key = requestLineKey(it);

        if (!groups[key]) {
            groups[key] = {
                item_code: it.item_code,
                itr_number: it.itr_number,
                line_num: it.itr_line_num,
                limit: requestLineLimit(it),
                total: 0,
                rows: []
            };
        }

        groups[key].total += Number(it.quantity || 0);
        groups[key].rows.push(idx + 1);
    });

    for (const key of Object.keys(groups)) {
        const g = groups[key];

        if (g.limit > 0 && g.total > g.limit) {
            const msg = 'Total Qty to Issue for item ' + g.item_code +
                ' / ITR ' + g.itr_number + ' line ' + g.line_num +
                ' is ' + fmtQty(g.total) + ', but remaining requested qty is only ' + fmtQty(g.limit) +
                '. Adjust split lot rows first.';

            if (showAlert) {
                showMessage(msg);
            }

            return false;
        }
    }

    return true;
}

function render() {
    const tb = document.querySelector('#itemsTable tbody');
    tb.innerHTML = '';

    items.forEach((it, idx) => {
        const lotOptions = Array.isArray(it.available_lots) ? it.available_lots : [];
        const lotOptionsHtml = lotOptions.map(lot => {
            const lotNo = String(lot.lot_no || '').trim();

            if (!lotNo) {
                return '';
            }

            return `<option value="${esc(lotNo)}" label="Available ${fmtQty(lot.available_qty)}"></option>`;
        }).join('');
        const lotListAttr = lotOptionsHtml ? ` list="lot_options_${idx}"` : '';

        tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="col-item" title="${esc(it.item_code)}">${esc(it.item_code)}</td>
                <td class="col-scanned" title="${esc(it.scanned_code)}">${esc(it.scanned_code)}</td>
                <td class="col-part" title="${esc(it.part_name)}">${esc(it.part_name)}</td>
                <td class="col-stock" title="${esc(it.stock_whs_code || '01')} stock: ${fmtQty(it.warehouse_stock_qty)}">
                    <div>${fmtQty(it.warehouse_stock_qty)}</div>
                    <div class="small text-muted">${esc(it.stock_whs_code || '01')}</div>
                </td>

                <td class="col-requested" title="Requested qty: ${fmtQty(it.requested_qty)}">
                    <div class="fw-bold">${fmtQty(it.requested_qty)}</div>
                    ${it.remaining_qty ? `<div class="small text-muted">Remaining ${fmtQty(it.remaining_qty)}</div>` : ''}
                    ${Number(it.qty_per_pack || 0) > 0 ? `<div class="small text-muted">Pack ${fmtQty(it.qty_per_pack)}${Number(it.pack_row_count || 0) > 1 ? ' | ' + esc(it.pack_row_no) + '/' + esc(it.pack_row_count) : ''}</div>` : ''}
                </td>

                <td class="col-qty">
                    <input
                        class="form-control form-control-sm table-input"
                        type="number"
                        min="0.001"
                        step="0.001"
                        id="qty_${idx}"
                        value="${esc(it.quantity)}"
                        onchange="updateItemField(${idx}, 'quantity', this.value); validateItemLot(${idx})"
                    >
                </td>

                <td class="col-lot">
                    <input
                        class="form-control form-control-sm lot-input"
                        id="lot_${idx}"
                        ${lotListAttr}
                        value="${esc(it.lot_no)}"
                        placeholder="GRPO lot"
                        onchange="updateItemField(${idx}, 'lot_no', this.value); validateItemLot(${idx})"
                    >
                    ${lotOptionsHtml ? `<datalist id="lot_options_${idx}">${lotOptionsHtml}</datalist>` : ''}
                    ${it.requested_lot_no ? `<div class="small text-muted">GRPO ${esc(it.requested_lot_no)}</div>` : ''}
                </td>

                <td class="col-wh-lot">
                    <input
                        class="form-control form-control-sm lot-input"
                        id="warehouse_lot_${idx}"
                        value="${esc(it.warehouse_lot_no)}"
                        placeholder="WH actual lot"
                        onchange="updateItemField(${idx}, 'warehouse_lot_no', this.value)"
                    >
                </td>

                <td class="col-match" title="${esc(it.lot_message || '')}">${lotStatusHtml(it)}</td>
                <td class="col-itr" title="${esc(it.itr_number)}">${esc(it.itr_number)}</td>

                <td class="col-action">
                    <div class="d-grid gap-1">
                        <button
                            class="btn btn-sm btn-outline-danger remove-btn"
                            type="button"
                            onclick="removeItem(${idx})"
                            title="Remove this item from the issuance table"
                        >
                            Remove
                        </button>
                    </div>
                </td>
            </tr>
        `);
    });

    document.getElementById('countBadge').textContent = items.length + ' item(s)';
    document.getElementById('saveBtn').disabled = items.length === 0;
    const printBtn = document.getElementById('printBtn');
    if (printBtn) {
        printBtn.disabled = items.length === 0;
    }
}

function updateItemField(idx, key, value) {
    if (items[idx]) {
        items[idx][key] = value;
    }
}

function syncTableItems() {
    items.forEach((it, idx) => {
        const qty = document.getElementById('qty_' + idx);
        const lot = document.getElementById('lot_' + idx);
        const warehouseLot = document.getElementById('warehouse_lot_' + idx);

        if (qty) {
            it.quantity = qty.value.trim();
        }

        if (lot) {
            it.lot_no = lot.value.trim();
        }

        if (warehouseLot) {
            it.warehouse_lot_no = warehouseLot.value.trim();
        }
    });
}

function removeItem(i) {
    if (!items[i]) {
        return;
    }

    pendingRemoveItemIdx = i;

    const modalEl = document.getElementById('removeItemModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function removeItemConfirmed() {
    if (pendingRemoveItemIdx === null || !items[pendingRemoveItemIdx]) {
        return;
    }

    items.splice(pendingRemoveItemIdx, 1);
    pendingRemoveItemIdx = null;

    if (items.length === 0) {
        selectedDocument = null;
        document.getElementById('selectedRequestBox').classList.add('d-none');
    }

    renderRequests();
    render();
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

function showMessage(message) {
    alert(message);
}

async function checkLotBalance(itemCode, lotNo, whsCode = '01') {
    const url = 'api/issuer/check_lot_balance.php?item_code=' + encodeURIComponent(itemCode || '') +
        '&lot_no=' + encodeURIComponent(lotNo || '') +
        '&warehouse_code=' + encodeURIComponent(whsCode || '01');

    const res = await fetch(url, { cache: 'no-store' });
    const text = await res.text();

    let data = null;

    try {
        data = JSON.parse(text);
    } catch (e) {
        return {
            ok: false,
            valid: false,
            message: 'Lot balance API returned invalid response.',
            raw: text
        };
    }

    return data;
}

async function validateItemLotBalanceCandidate(idx, qty, lotNo, showAlert = false) {
    const it = items[idx];

    if (!it) {
        return false;
    }

    const itemCode = String(it.item_code || '').trim();
    const whsCode = String(it.stock_whs_code || '01').trim() || '01';
    const cleanLot = String(lotNo || '').trim();
    const cleanQty = Number(qty || 0);

    if (!itemCode || !cleanLot || cleanQty <= 0) {
        setLotStatus(idx, '', '');
        render();
        return false;
    }

    setLotStatus(idx, 'checking', 'Checking lot balance...');
    render();

    const balance = await checkLotBalance(itemCode, cleanLot, whsCode);

    if (!balance.ok || !balance.valid) {
        const msg = balance.message || 'Lot has no available balance.';
        setLotStatus(idx, 'invalid', msg, balance);
        render();

        if (showAlert) {
            showMessage(msg);
        }

        return false;
    }

    const pendingQty = pendingQtyForLot(itemCode, cleanLot, whsCode, idx);
    const availableQty = Number(balance.available_qty || 0);
    const remainingAvailableQty = Math.max(0, availableQty - pendingQty);
    let issueQty = cleanQty;

    if (issueQty > remainingAvailableQty) {
        if (remainingAvailableQty > 0) {
            issueQty = remainingAvailableQty;
            items[idx].quantity = String(issueQty);

            const qtyInput = document.getElementById('qty_' + idx);

            if (qtyInput) {
                qtyInput.value = issueQty;
            }

            const msg = 'Lot ' + cleanLot + ' only has ' + fmtQty(availableQty) +
                ' available. Already pending on this screen: ' + fmtQty(pendingQty) +
                '. Qty to issue was adjusted to remaining available: ' + fmtQty(issueQty) + '.';

            setLotStatus(idx, 'valid', msg, balance);
            render();

            if (showAlert) {
                showMessage(msg);
            }

            return true;
        }

        const msg = 'Lot ' + cleanLot + ' only has ' + fmtQty(availableQty) +
            ' available. Already pending on this screen: ' + fmtQty(pendingQty) +
            '. No remaining quantity is available for this line.';

        setLotStatus(idx, 'invalid', msg, balance);
        render();

        if (showAlert) {
            showMessage(msg);
        }

        return false;
    }

    const afterThisQty = pendingQty + issueQty;

    setLotStatus(idx, 'valid', 'Lot available. Available ' + fmtQty(availableQty) + ', pending after this ' + fmtQty(afterThisQty) + '.', balance);
    render();
    return true;
}

async function validateItemLot(idx) {
    const qtyEl = document.getElementById('qty_' + idx);
    const lotEl = document.getElementById('lot_' + idx);

    if (!items[idx]) {
        return false;
    }

    if (qtyEl) {
        items[idx].quantity = qtyEl.value.trim();
    }

    if (lotEl) {
        items[idx].lot_no = lotEl.value.trim();
    }

    return validateItemLotBalanceCandidate(
        idx,
        items[idx].quantity,
        items[idx].lot_no,
        false
    );
}

async function validateAllLotBalances(showAlert = true) {
    syncTableItems();

    for (let idx = 0; idx < items.length; idx++) {
        const it = items[idx];

        if (!it.quantity || Number(it.quantity) <= 0 || !it.lot_no) {
            continue;
        }

        const valid = await validateItemLotBalanceCandidate(idx, it.quantity, it.lot_no, showAlert);

        if (!valid) {
            return false;
        }
    }

    return true;
}

function parsePickerQrPayload(raw) {
    const text = String(raw || '').trim();

    if (!text) {
        return null;
    }

    const match = text.match(/^\(01\)(.*?)\(17\)(.*?)\(10\)(.*)$/);

    if (!match) {
        return null;
    }

    return {
        item_code: match[1].trim(),
        quantity: match[2].trim(),
        lot_no: match[3].trim(),
        payload: text
    };
}

async function applyPickerQrScan() {
    const input = document.getElementById('pickerQrInput');
    const parsed = parsePickerQrPayload(input ? input.value : '');

    if (!parsed) {
        showMessage('Picker QR format is invalid. Expected (01)ItemCode(17)Qty(10)Lot.');
        return;
    }

    if (!selectedDocument || items.length === 0) {
        showMessage('Load an open request before scanning a picker QR.');
        return;
    }

    const scannedItemCode = String(parsed.item_code || '').trim().toUpperCase();
    const scannedQty = Number(parsed.quantity || 0);
    const scannedLot = String(parsed.lot_no || '').trim();

    if (!scannedItemCode || scannedQty <= 0 || !scannedLot) {
        showMessage('Scanned QR has missing item, qty, or lot.');
        return;
    }

    const sameItemRows = items
        .map((row, idx) => ({ row, idx }))
        .filter(x => String(x.row.item_code || '').trim().toUpperCase() === scannedItemCode);

    if (sameItemRows.length === 0) {
        showMessage('Scanned item ' + parsed.item_code + ' is not in the loaded request.');
        return;
    }

    /*
        Scan behavior:
        - No split rows yet: fill the main row with scanned qty + lot.
        - Split rows already exist: keep the issuer's split qty per row and fill only the next blank lot row.
          This prevents a 50/25/25 split from becoming 50/50/25 when scanning a QR with qty 50.
        - If all split rows already have lots and remaining request qty still exists, create a new split row.
    */
    const matchingLotRows = sameItemRows.filter(x =>
        String(x.row.lot_no || '').trim().toUpperCase() === scannedLot.toUpperCase()
    );
    const blankLotRows = sameItemRows.filter(x => !String(x.row.lot_no || '').trim());
    const isSplitItem = sameItemRows.length > 1;

    let matchIdx = -1;
    let qtyToValidate = scannedQty;

    if (matchingLotRows.length > 0) {
        matchIdx = matchingLotRows[0].idx;
        qtyToValidate = Number(items[matchIdx].quantity || 0) > 0 ? Number(items[matchIdx].quantity || 0) : scannedQty;

        if (Number(items[matchIdx].quantity || 0) <= 0) {
            items[matchIdx].quantity = parsed.quantity;
        }
    } else if (blankLotRows.length > 0) {
        matchIdx = blankLotRows[0].idx;

        if (isSplitItem) {
            const existingQty = Number(items[matchIdx].quantity || 0);

            if (existingQty > 0) {
                qtyToValidate = existingQty;
            } else {
                qtyToValidate = scannedQty;
                items[matchIdx].quantity = parsed.quantity;
            }
        } else {
            items[matchIdx].quantity = parsed.quantity;
            qtyToValidate = scannedQty;
        }
    } else {
        const baseRow = sameItemRows[0].row;
        const totalAlreadyAllocated = sameItemRows.reduce((sum, x) => sum + Number(x.row.quantity || 0), 0);
        const maxQty = Number(baseRow.remaining_qty || baseRow.requested_qty || 0);
        const remainingToAllocate = maxQty - totalAlreadyAllocated;

        if (remainingToAllocate <= 0) {
            showMessage(
                'All split rows for item ' + parsed.item_code +
                ' already have lot numbers and total qty is already ' + fmtQty(totalAlreadyAllocated) +
                '. Remove or adjust a row before scanning another lot.'
            );
            return;
        }

        const newQty = Math.min(scannedQty, remainingToAllocate);

        const newRow = {
            ...baseRow,
            quantity: newQty,
            lot_no: '',
            warehouse_lot_no: '',
            scanned_code: 'ITR ' + (baseRow.itr_number || '') + ' line ' + (baseRow.itr_line_num || ''),
            entry_method: 'SCAN',
            manual_reason: '',
            match_by: 'Picker QR',
            lot_status: '',
            lot_message: '',
            lot_received_qty: 0,
            lot_issued_qty: 0,
            lot_available_qty: 0,
            lot_source: ''
        };

        items.push(newRow);
        matchIdx = items.length - 1;
        qtyToValidate = newQty;
    }

    if (matchIdx < 0 || !items[matchIdx]) {
        showMessage('Unable to find a row for scanned item ' + parsed.item_code + '.');
        return;
    }

    items[matchIdx].lot_no = scannedLot;
    items[matchIdx].scanned_code = parsed.payload;
    items[matchIdx].entry_method = 'SCAN';
    items[matchIdx].match_by = 'Picker QR';

    render();

    const validLot = await validateItemLotBalanceCandidate(
        matchIdx,
        qtyToValidate,
        scannedLot,
        true
    );

    render();

    if (!validLot) {
        if (input) {
            input.value = '';
            input.focus();
        }
        return;
    }

    if (input) {
        input.value = '';
        input.focus();
    }
}

async function printAllIssueTags(silent = false) {
    syncTableItems();

    if (items.length === 0) {
        if (!silent) {
            showMessage('No tags to print.');
        }
        return false;
    }

    for (let idx = 0; idx < items.length; idx++) {
        const it = items[idx];

        if (!it.quantity || Number(it.quantity) <= 0) {
            if (!silent) {
                showMessage('Line ' + (idx + 1) + ' quantity must be greater than zero.');
            }
            return false;
        }

        if (!it.lot_no) {
            if (!silent) {
                showMessage('Line ' + (idx + 1) + ' GRPO lot number is required.');
            }
            return false;
        }

        if (!it.warehouse_lot_no) {
            if (!silent) {
                showMessage('Line ' + (idx + 1) + ' warehouse lot number is required.');
            }
            return false;
        }
    }

    if (!validateIssueTotals(true)) {
        return false;
    }

    const lotOk = await validateAllLotBalances(true);

    if (!lotOk) {
        return false;
    }

    const printBtn = document.getElementById('printBtn');
    const oldText = printBtn ? printBtn.textContent : '';

    if (printBtn) {
        printBtn.disabled = true;
        printBtn.textContent = 'Printing...';
    }

    try {
        const body = new FormData();
        body.append('batch_items', JSON.stringify(items));

        const res = await fetch('api/issuer/print_issue_tags.php', {
            method: 'POST',
            body: body
        });

        const text = await res.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (e) {
            if (!silent) {
                showMessage('Print API returned invalid response.');
            }
            console.error(text);
            return false;
        }

        if (!data.ok) {
            if (!silent) {
                showMessage(data.message || 'Unable to print tags.');
            }
            return false;
        }

        if (!silent) {
            showMessage(data.message || ('Printed ' + (data.printed || 0) + ' tag(s).'));
        }

        return true;
    } catch (e) {
        console.error(e);
        if (!silent) {
            showMessage('Unable to print tags. Check printer/API connection.');
        }
        return false;
    } finally {
        if (printBtn) {
            printBtn.disabled = items.length === 0;
            printBtn.textContent = oldText || 'Print All Tags';
        }
    }
}

async function saveItems(skipOverQtyCheck = false) {
    syncTableItems();

    for (let idx = 0; idx < items.length; idx++) {
        const it = items[idx];

        if (!it.quantity || Number(it.quantity) <= 0) {
            showMessage('Line ' + (idx + 1) + ' quantity must be greater than zero.');
            return;
        }

        if (
            !skipOverQtyCheck &&
            it.remaining_qty &&
            Number(it.quantity) > Number(it.remaining_qty)
        ) {
            document.getElementById('overQtyMessage').textContent =
                'Line ' + (idx + 1) + ' quantity is greater than remaining request qty. Continue?';

            pendingSaveAfterOverQty = true;

            const modalEl = document.getElementById('overQtyModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            return;
        }

        if (!it.lot_no) {
            showMessage('Line ' + (idx + 1) + ' GRPO lot number is required.');
            return;
        }

        if (!it.warehouse_lot_no) {
            showMessage('Line ' + (idx + 1) + ' warehouse lot number is required.');
            return;
        }
    }

    if (!validateIssueTotals(true)) {
        return;
    }

    const lotOk = await validateAllLotBalances(true);

    if (!lotOk) {
        return;
    }

    const saveBtn = document.getElementById('saveBtn');
    const oldSaveText = saveBtn ? saveBtn.textContent : '';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Printing tags...';
    }

    const printOk = await printAllIssueTags(true);

    if (!printOk) {
        if (saveBtn) {
            saveBtn.disabled = items.length === 0;
            saveBtn.textContent = oldSaveText || 'Save Issuance and Generate Trace QR';
        }

        if (!confirm('Printing failed or was cancelled. Do you still want to save the issuance?')) {
            return;
        }
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving issuance...';
    }

    const f = document.createElement('form');
    f.method = 'post';
    f.action = 'actions/save_issue_with_lot_validation.php';

    const i = document.createElement('input');
    i.type = 'hidden';
    i.name = 'batch_items';
    i.value = JSON.stringify(items);

    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
}

const confirmLoadRequestBtn = document.getElementById('confirmLoadRequestBtn');

const pickerQrInput = document.getElementById('pickerQrInput');

if (pickerQrInput) {
    pickerQrInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyPickerQrScan();
        }
    });
}

if (confirmLoadRequestBtn) {
    confirmLoadRequestBtn.addEventListener('click', function () {
        if (pendingLoadDocIdx === null) {
            return;
        }

        const modalEl = document.getElementById('loadRequestModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        modal.hide();

        loadDocumentItemsConfirmed(pendingLoadDocIdx);
        pendingLoadDocIdx = null;
    });
}

const confirmRemoveItemBtn = document.getElementById('confirmRemoveItemBtn');

const confirmOverQtyBtn = document.getElementById('confirmOverQtyBtn');

if (confirmOverQtyBtn) {
    confirmOverQtyBtn.addEventListener('click', function () {
        if (!pendingSaveAfterOverQty) {
            return;
        }

        const modalEl = document.getElementById('overQtyModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        modal.hide();

        pendingSaveAfterOverQty = false;
        saveItems(true);
    });
}

if (confirmRemoveItemBtn) {
    confirmRemoveItemBtn.addEventListener('click', function () {
        const modalEl = document.getElementById('removeItemModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        modal.hide();
        removeItemConfirmed();
    });
}

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

if (sidebarToggle && sidebar && sidebarBackdrop) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

if (sidebarBackdrop && sidebar) {
    sidebarBackdrop.addEventListener('click', function () {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
    });
}

function setIssuerSideTab(tabName) {
    const requestTab = document.getElementById('issueRequestsTab');
    const stockTab = document.getElementById('issuerStockTab');
    const requestPane = document.getElementById('issueRequestsPane');
    const stockPane = document.getElementById('issuerStockPane');

    if (!requestTab || !stockTab || !requestPane || !stockPane) {
        return;
    }

    requestTab.classList.remove('active');
    stockTab.classList.remove('active');
    requestPane.classList.remove('show', 'active', 'webview-tab-active');
    stockPane.classList.remove('show', 'active', 'webview-tab-active');

    if (tabName === 'stock') {
        stockTab.classList.add('active');
        stockPane.classList.add('show', 'active', 'webview-tab-active');
        renderStocks();
        return;
    }

    requestTab.classList.add('active');
    requestPane.classList.add('show', 'active', 'webview-tab-active');
    renderRequests();
}

function initIssuerPage() {
    const requestTab = document.getElementById('issueRequestsTab');
    const stockTab = document.getElementById('issuerStockTab');

    if (requestTab) {
        requestTab.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            setIssuerSideTab('requests');
        });
    }

    if (stockTab) {
        stockTab.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            setIssuerSideTab('stock');
        });
    }

    setIssuerSideTab('requests');
    refreshSideTabs();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initIssuerPage);
} else {
    initIssuerPage();
}

if (window.createRefreshController) {
    const issuerRefresh = window.createRefreshController([
        { name: 'issuerRequests', fn: loadOpenRequests, intervalMs: 60000 },
        { name: 'issuerStocks', fn: loadStocks, intervalMs: 120000 }
    ]);

    issuerRefresh.scheduleAll();
} else {
    console.warn('createRefreshController not found. Using direct refresh only.');
}
</script>

</body>
</html>
