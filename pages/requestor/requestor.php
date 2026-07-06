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

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --shell: #263b4f;
            --shell2: #354f68;
            --accent: #0a6ed1;
            --accent-dark: #085caf;
            --bg: #f4f7fb;
            --card: #ffffff;
            --border: #dbe5f0;
            --soft: #f1f5f9;
            --text: #172033;
            --muted: #64748b;
            --success: #047857;
            --danger: #b91c1c;
            --warning: #c2410c;
            --side-width: 17rem;
            --topbar-height: 3.5rem;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 15px;
            line-height: 1.45;
            overflow-x: hidden;
        }

        /* NAVBAR AND SIDEBAR ARE KEPT AS YOUR ORIGINAL STRUCTURE */
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

        .shell-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .shell-title-wrap { min-width: 0; flex: 1; }
        .shell-title { font-size: 16px; font-weight: 900; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .shell-subtitle { font-size: 12px; color: rgba(255,255,255,.86); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }

        .app-layout { display: flex; min-height: 100vh; padding-top: var(--topbar-height); }

        .sidebar,
        .sap-side-nav {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            width: var(--side-width);
            z-index: 1035;
            background: #fff;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 16px rgba(15, 23, 42, .08);
        }

        .main-content {
            margin-left: var(--side-width);
            width: calc(100% - var(--side-width));
            min-height: calc(100vh - var(--topbar-height));
            overflow-x: hidden;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: var(--topbar-height) 0 0 0;
            z-index: 1030;
            background: rgba(15, 23, 42, .45);
        }

        /* NEW PAGE LAYOUT ONLY */
        .request-page {
            min-height: calc(100vh - var(--topbar-height));
            padding: 22px;
        }

        .request-board {
            display: grid;
            grid-template-columns: minmax(250px, 330px) minmax(0, 1fr) minmax(280px, 360px);
            gap: 18px;
            align-items: start;
        }

        .request-left,
        .request-center,
        .request-right {
            min-width: 0;
        }

        .request-left {
            position: sticky;
            top: calc(var(--topbar-height) + 22px);
        }

        .page-intro-card,
        .setup-panel,
        .items-panel,
        .pending-panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
        }

        .page-intro-card {
            overflow: hidden;
            margin-bottom: 14px;
        }

        .intro-top {
            padding: 18px;
            background: linear-gradient(135deg, #123b63, #0a6ed1);
            color: #fff;
        }

        .intro-kicker {
            font-size: 12px;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 850;
            opacity: .9;
        }

        .page-title {
            margin: 6px 0 0;
            font-size: clamp(24px, 3vw, 34px);
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.04em;
        }

        .page-subtitle {
            margin-top: 9px;
            color: rgba(255,255,255,.88);
            font-size: 14px;
        }

        .intro-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #fff;
        }

        .line-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 7px 13px;
            border-radius: 999px;
            background: #eff6ff;
            color: #075985;
            border: 1px solid #bfdbfe;
            font-size: 13px;
            font-weight: 950;
            white-space: nowrap;
        }

        .setup-panel { padding: 15px; }
        .panel-title { font-size: 15px; font-weight: 950; margin-bottom: 12px; }
        .panel-divider { height: 1px; background: var(--border); margin: 14px 0; }

        .form-label { color: #1e293b; font-size: 13px; font-weight: 900; margin-bottom: 6px; }
        .form-control,
        .form-select {
            min-height: 46px;
            border-radius: 12px;
            border-color: #b9c7d7;
            color: var(--text);
            font-size: 15px;
            background-color: #fff;
        }
        .form-control-lg { min-height: 48px; font-size: 15px; }
        .form-control-sm { min-height: 42px; font-size: 14px; }
        .form-control:focus,
        .form-select:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(10,110,209,.14); }

        .btn {
            min-height: 44px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 900;
            padding-left: 15px;
            padding-right: 15px;
        }
        .btn-primary,
        .btn-success { background: var(--accent); border-color: var(--accent); }
        .btn-primary:hover,
        .btn-success:hover,
        .btn-primary:focus,
        .btn-success:focus { background: var(--accent-dark); border-color: var(--accent-dark); }
        .btn-sm { min-height: 38px; font-size: 13px; padding: 7px 11px; }

        .tool-stack { display: grid; gap: 10px; }
        .tool-button {
            width: 100%;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            border-radius: 14px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: left;
            transition: .15s ease;
        }
        .tool-button:hover { border-color: #93c5fd; background: #f8fbff; transform: translateY(-1px); }
        .tool-button.primary { background: #0a6ed1; color: #fff; border-color: #0a6ed1; }
        .tool-button.primary:hover { background: #085caf; }
        .tool-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: #e8f3ff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            font-size: 18px;
        }
        .tool-button.primary .tool-icon { background: rgba(255,255,255,.18); }
        .tool-title { font-size: 15px; font-weight: 950; line-height: 1.1; }
        .tool-subtitle { font-size: 12px; opacity: .8; margin-top: 2px; }

        .info-box {
            border-radius: 13px;
            font-size: 14px;
            border-color: var(--border);
            padding: 13px 14px;
            margin-bottom: 0;
        }

        #saveBtn { width: 100%; min-height: 50px; font-size: 16px; }

        .items-panel { overflow: hidden; }
        .items-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(220px, 360px);
            gap: 14px;
            align-items: center;
        }
        .items-title { font-size: 20px; font-weight: 950; margin: 0; letter-spacing: -.02em; }
        .items-subtitle { color: var(--muted); font-size: 13px; margin-top: 3px; }
        .items-body { padding: 16px 18px 18px; }

        .request-table-wrap {
            width: 100%;
            overflow-x: hidden;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }

        .request-table {
            width: 100%;
            min-width: 0 !important;
            margin: 0;
            table-layout: fixed;
            font-size: clamp(11px, .8vw, 13px);
        }

        .request-table thead th {
            background: #f8fafc;
            color: #334155;
            border-bottom: 1px solid var(--border);
            font-weight: 950;
            padding: 11px 8px;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: clamp(10px, .68vw, 12px);
            letter-spacing: .02em;
            white-space: normal;
            line-height: 1.2;
        }

        .request-table td {
            padding: 10px 8px;
            color: var(--text);
            vertical-align: middle;
            border-color: #edf1f7;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.25;
        }

        .request-table tbody tr:hover { background: #f8fbff; }
        .col-item { width: 15%; font-weight: 950; }
        .col-part { width: 20%; }
        .col-line { width: 12%; }
        .col-requested,
        .col-remaining,
        .col-stock { width: 12%; text-align: right; font-weight: 850; }
        .col-qty { width: 12%; }
        .col-action { width: 9%; text-align: center; }

        .table-input {
            width: 100%;
            min-width: 0;
            height: 40px;
            font-size: 15px;
            padding: 7px 8px;
            border-radius: 10px;
            font-weight: 900;
            text-align: right;
        }
        .remove-btn { width: 100%; min-height: 36px; font-size: 12px; padding: 6px; border-radius: 10px; }

        .pending-panel { overflow: hidden; }
        .pending-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .pending-title { margin: 0; font-size: 18px; font-weight: 950; letter-spacing: -.02em; }
        .pending-status { color: var(--muted); font-size: 13px; margin-top: 3px; }
        .pending-body { padding: 14px; }
        .pending-grid { display: grid; gap: 12px; }
        .requests-panel { max-height: calc(100vh - 250px); overflow-y: auto; padding-right: 3px; }

        .itr-card,
        .stock-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 12px;
            color: var(--text);
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15,23,42,.05);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .stock-card { padding: 14px; }
        .itr-card:hover,
        .stock-card:hover { transform: translateY(-1px); border-color: #93c5fd; box-shadow: 0 8px 20px rgba(10,110,209,.12); }
        .itr-card.active { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(10,110,209,.14); }
        .itr-header { width: 100%; border: 0; background: #fff; text-align: left; padding: 15px; }
        .itr-header:hover { background: #f8fbff; }
        .request-title,
        .stock-code { font-size: 15px; font-weight: 950; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .request-meta,
        .stock-name { font-size: 13px; color: var(--muted); margin-top: 3px; line-height: 1.35; }
        .request-meta strong { color: #334155; }
        .warehouse-route { display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-top: 8px; font-size: 13px; font-weight: 850; }
        .warehouse-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 9px; background: #eff6ff; color: #075985; border: 1px solid #bfdbfe; }
        .warehouse-arrow { color: #64748b; font-weight: 950; }
        .request-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
        .request-actions .btn { min-height: 38px; font-size: 13px; padding: 7px 10px; }
        .qty-grid,
        .stock-metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
        .qty-box,
        .stock-metric { background: #f8fafc; border: 1px solid #edf1f7; border-radius: 12px; padding: 10px; min-width: 0; }
        .qty-box .label,
        .stock-metric .label { font-size: 11px; color: var(--muted); font-weight: 850; }
        .qty-box .value,
        .stock-metric .value { font-size: 15px; font-weight: 950; color: var(--text); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; }

        .badge.text-bg-primary,
        .badge.bg-primary { background: var(--accent) !important; }
        .badge.text-bg-success { background: var(--success) !important; }
        .badge.text-bg-warning { background: #fff7ed !important; color: var(--warning) !important; border: 1px solid #fed7aa; }

        .modal-content { border-radius: 18px; border-color: var(--border); }
        .modal-header,
        .modal-footer { padding: 16px 18px; }
        .modal-body { padding: 18px; font-size: 15px; }
        .modal-title { font-size: 20px; font-weight: 950; }
        .modal-xl { --bs-modal-width: 1180px; }
        .modal-search-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; margin-bottom: 14px; }
        .small, small { font-size: 12px !important; }

        @media (max-width: 1500px) {
            .request-board { grid-template-columns: 300px minmax(0, 1fr); }
            .request-right { grid-column: 1 / -1; }
            .requests-panel { max-height: 420px; }
            #myRequestList.pending-grid { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        }

        @media (max-width: 991.98px) {
            :root { --side-width: 16rem; }
            .shell-menu-btn { display: inline-flex; align-items: center; justify-content: center; }
            .sidebar,
            .sap-side-nav { transform: translateX(-105%); transition: transform .2s ease; }
            .sidebar.show,
            .sap-side-nav.show { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; width: 100%; }
            .request-page { padding: 15px; }
            .request-board { grid-template-columns: 1fr; }
            .request-left { position: static; }
            .items-header { grid-template-columns: 1fr; }
            .modal-search-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 767.98px) {
            .request-table-wrap { border: 0; background: transparent; }
            .request-table,
            .request-table thead,
            .request-table tbody,
            .request-table th,
            .request-table td,
            .request-table tr { display: block; width: 100%; }
            .request-table thead { display: none; }
            .request-table tbody tr {
                background: #fff;
                border: 1px solid var(--border);
                border-radius: 14px;
                padding: 10px;
                margin-bottom: 10px;
                box-shadow: 0 4px 12px rgba(15,23,42,.05);
            }
            .request-table td {
                border: 0;
                padding: 8px 0;
                display: grid;
                grid-template-columns: 42% minmax(0, 1fr);
                gap: 10px;
                text-align: left !important;
                font-size: 14px;
            }
            .request-table td::before {
                content: '';
                color: var(--muted);
                font-size: 11px;
                font-weight: 950;
                text-transform: uppercase;
                letter-spacing: .03em;
            }
            .request-table td:nth-child(1)::before { content: 'SAP ItemCode'; }
            .request-table td:nth-child(2)::before { content: 'Part Name'; }
            .request-table td:nth-child(3)::before { content: 'ITR/Line'; }
            .request-table td:nth-child(4)::before { content: 'Already Req.'; }
            .request-table td:nth-child(5)::before { content: 'Remaining'; }
            .request-table td:nth-child(6)::before { content: 'WH Stock'; }
            .request-table td:nth-child(7)::before { content: 'Qty'; }
            .request-table td:nth-child(8)::before { content: 'Action'; }
            .table-input { height: 42px; }
            .remove-btn { width: auto; }
            .pending-header { flex-direction: column; }
            .pending-header .btn { width: 100%; }
        }

        @media (max-width: 575.98px) {
            .request-page { padding: 10px; }
            .intro-bottom { align-items: flex-start; flex-direction: column; }
            .setup-panel,
            .items-body,
            .pending-body { padding: 12px; }
            .items-header { padding: 14px; }
            .qty-grid,
            .stock-metrics { grid-template-columns: 1fr; }
            .request-actions { grid-template-columns: 1fr; }
        }


        /* RIGHT-SIDE TABBED CARD REDESIGN */
        .request-board {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 410px);
            gap: 20px;
            align-items: start;
        }

        .request-main { min-width: 0; }

        .compact-hero { margin-bottom: 18px; }
        .compact-hero .intro-top { padding: 20px 22px; }
        .hero-metrics {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 10px;
        }
        .soft-badge {
            background: #f8fafc;
            color: #334155;
            border-color: #dbe5f0;
        }

        .request-right {
            position: sticky;
            top: calc(var(--topbar-height) + 22px);
            min-width: 0;
        }

        .request-tabs-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .08);
        }

        .tabs-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding: 18px;
            background: linear-gradient(135deg, #f8fbff, #eef6ff);
            border-bottom: 1px solid var(--border);
        }

        .tabs-kicker {
            font-size: 11px;
            font-weight: 950;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: #0a6ed1;
        }

        .tabs-title {
            margin: 3px 0 0;
            font-size: 22px;
            line-height: 1.1;
            font-weight: 950;
            letter-spacing: -.03em;
            color: var(--text);
        }

        .request-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 12px;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }

        .request-tabs .nav-item { min-width: 0; }
        .request-tabs .nav-link {
            width: 100%;
            min-height: 42px;
            border-radius: 12px;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 13px;
            font-weight: 950;
        }
        .request-tabs .nav-link.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: 0 8px 16px rgba(10,110,209,.18);
        }

        .request-tab-content { padding: 16px; }
        .tab-section-title {
            color: #1e293b;
            font-size: 13px;
            font-weight: 950;
            letter-spacing: .03em;
            text-transform: uppercase;
            margin-bottom: 11px;
        }

        .compact-tools { gap: 9px; }
        .compact-tools .tool-button { padding: 11px; }
        .compact-tools .tool-icon {
            width: 36px;
            height: 36px;
            border-radius: 11px;
        }

        .pending-tab-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .tabbed-request-list {
            max-height: calc(100vh - 325px);
            overflow-y: auto;
            padding-right: 4px;
        }
        .tabbed-request-list.pending-grid { grid-template-columns: 1fr; }
        .tabbed-request-list .itr-card:last-child { margin-bottom: 0; }

        .request-table-wrap { overflow-x: hidden; }

        @media (max-width: 1199.98px) {
            .request-board { grid-template-columns: 1fr; }
            .request-right { position: static; }
            .tabbed-request-list { max-height: 430px; }
        }

        @media (max-width: 575.98px) {
            .tabs-card-head,
            .pending-tab-head { flex-direction: column; }
            .tabs-card-head .btn,
            .pending-tab-head .btn { width: 100%; }
            .request-tabs { grid-template-columns: 1fr; }
        }


        /* V2 BALANCED RIGHT-TABBED LAYOUT FIX
           Purpose: keep setup/queue as one card on the right, but remove the heavy blue banner feel. */
        .request-page {
            padding: 16px;
            background: #f5f7fb;
        }

        .request-board {
            grid-template-columns: minmax(0, 1fr) minmax(380px, 430px) !important;
            gap: 16px !important;
            align-items: start;
        }

        .request-main {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .compact-hero {
            margin-bottom: 0 !important;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .05);
        }

        .compact-hero .intro-top {
            padding: 14px 16px !important;
            background: #ffffff !important;
            color: var(--text) !important;
            border-bottom: 1px solid var(--border);
        }

        .compact-hero .intro-kicker {
            color: #0a6ed1;
            opacity: 1;
            font-size: 11px;
        }

        .compact-hero .page-title {
            margin-top: 3px;
            font-size: clamp(22px, 1.7vw, 28px) !important;
            letter-spacing: -.03em;
        }

        .compact-hero .page-subtitle {
            margin-top: 5px;
            color: #64748b !important;
            font-size: 13px;
        }

        .compact-hero .intro-bottom {
            padding: 10px 14px !important;
            background: #f8fafc !important;
        }

        .hero-metrics {
            gap: 8px !important;
        }

        .line-count-badge {
            min-height: 30px;
            padding: 5px 11px;
            font-size: 12px;
            background: #eff6ff;
        }

        .items-panel {
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .05);
        }

        .items-header {
            padding: 14px 16px !important;
            grid-template-columns: minmax(0, 1fr) minmax(220px, 330px) !important;
        }

        .items-title {
            font-size: 19px !important;
        }

        .items-subtitle {
            font-size: 12px !important;
        }

        .items-body {
            padding: 12px 16px 16px !important;
        }

        .request-table {
            font-size: 12px !important;
        }

        .request-table thead th {
            padding: 10px 8px !important;
            font-size: 11px !important;
        }

        .request-table td {
            padding: 8px 8px !important;
        }

        .table-input {
            height: 36px !important;
            min-height: 36px !important;
            font-size: 14px !important;
        }

        .remove-btn {
            min-height: 34px !important;
            font-size: 11px !important;
        }

        .request-right {
            position: sticky;
            top: calc(var(--topbar-height) + 16px) !important;
        }

        .request-tabs-card {
            border-radius: 16px !important;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .07) !important;
        }

        .tabs-card-head {
            padding: 14px 16px !important;
            background: #ffffff !important;
        }

        .tabs-title {
            font-size: 20px !important;
        }

        .request-tabs {
            padding: 10px !important;
            gap: 8px !important;
            background: #f8fafc !important;
        }

        .request-tabs .nav-link {
            min-height: 40px !important;
            font-size: 13px !important;
        }

        .request-tab-content {
            padding: 14px !important;
        }

        .form-control,
        .form-select {
            min-height: 42px !important;
        }

        .btn {
            min-height: 40px !important;
        }

        #saveBtn {
            min-height: 46px !important;
        }

        .compact-tools .tool-button {
            padding: 10px 12px !important;
        }

        .info-box {
            padding: 11px 13px !important;
            font-size: 13px !important;
        }

        .tabbed-request-list {
            max-height: calc(100vh - 270px) !important;
        }

        @media (max-width: 1366px) {
            .request-board {
                grid-template-columns: 1fr !important;
            }

            .request-right {
                position: static !important;
                grid-row: 1 !important;
                order: -1 !important;
            }

            .request-main {
                grid-row: 2 !important;
            }

            .request-tabs {
                display: none !important;
            }

            .request-tab-content {
                display: grid !important;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)) !important;
                gap: 14px !important;
            }

            .request-tab-content .tab-pane {
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .request-tab-content .tab-pane.fade:not(.show) {
                opacity: 1 !important;
            }

            .tabbed-request-list {
                max-height: 300px !important;
            }

            .request-table-wrap {
                max-height: 520px;
            }
        }

        @media (max-width: 991.98px) {
            .items-header {
                grid-template-columns: 1fr !important;
            }
        }


        /* SCROLLABLE LOADED ITEMS TABLE
           Keeps the right tab card visible while the item list scrolls inside the table area. */
        .items-panel {
            min-height: 0;
        }

        .items-body {
            min-height: 0;
        }

        .request-table-wrap {
            max-height: calc(100vh - 305px);
            overflow: auto !important;
            scrollbar-gutter: stable;
        }

        .request-table {
            min-width: 980px !important;
        }

        .request-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            box-shadow: inset 0 -1px 0 var(--border);
        }

        @media (max-width: 767.98px) {
            .request-table-wrap {
                max-height: 560px;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }

            .request-table {
                min-width: 0 !important;
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
        <div class="shell-subtitle">Requestor workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('requestor'); ?>

    <main class="main-content">
        <div class="request-page">
            <div class="request-board">
                <section class="request-main">
                    <section class="page-intro-card compact-hero">
                        <div class="intro-top">
                            <div class="intro-kicker">Warehouse Request Console</div>
                            <h1 class="page-title">Issue Request</h1>
                            <div class="page-subtitle">Load one SAP ITR, enter the needed quantities, then submit the request to Warehouse.</div>
                        </div>
                        <div class="intro-bottom hero-metrics">
                            <span class="line-count-badge" id="countBadge">0 line(s)</span>
                            <span class="line-count-badge soft-badge"><span id="myRequestCount">0</span> open request(s)</span>
                            <span class="line-count-badge soft-badge"><span id="stockCount">0</span> stock item(s)</span>
                            <span class="line-count-badge soft-badge"><span id="sapItCount">0</span> SAP IT</span>
                        </div>
                    </section>

                    <section class="items-panel">
                        <div class="items-header">
                            <div>
                                <h2 class="items-title">Loaded Items</h2>
                                <div class="items-subtitle">Quantity entry is done here. Long item names wrap to fit the table.</div>
                            </div>

                            <input
                                id="itemSearchInput"
                                class="form-control form-control-lg"
                                placeholder="Search SAP code or part name..."
                                oninput="renderTable()"
                            >
                        </div>

                        <div class="items-body">
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
                        </div>
                    </section>
                </section>

                <aside class="request-right">
                    <section class="request-tabs-card">
                        <div class="tabs-card-head">
                            <div>
                                <div class="tabs-kicker">Request Workspace</div>
                                <h2 class="tabs-title">Setup & Queue</h2>
                            </div>
                            <button class="btn btn-outline-primary btn-sm" type="button" onclick="refreshSideTabs()">Refresh</button>
                        </div>

                        <ul class="nav nav-pills request-tabs" id="requestWorkspaceTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="setup-tab" data-bs-toggle="pill" data-bs-target="#setupPane" type="button" role="tab" aria-controls="setupPane" aria-selected="true">
                                    Request Setup
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pendingPane" type="button" role="tab" aria-controls="pendingPane" aria-selected="false">
                                    Pending Queue
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content request-tab-content">
                            <div class="tab-pane fade show active" id="setupPane" role="tabpanel" aria-labelledby="setup-tab" tabindex="0">
                                <div class="tab-section-title">Request Details</div>

                                <div class="mb-3">
                                    <label class="form-label" for="neededDate">Needed Date</label>
                                    <input id="neededDate" class="form-control" type="date" value="<?= h(date('Y-m-d')) ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="remarksInput">Remarks</label>
                                    <input id="remarksInput" class="form-control" placeholder="Optional note for warehouse">
                                </div>

                                <div class="tab-section-title mt-3">Tools</div>
                                <div class="tool-stack compact-tools">
                                    <button class="tool-button primary" type="button" onclick="openItrModal()">
                                        <span class="tool-icon">📄</span>
                                        <span>
                                            <span class="tool-title">Select SAP ITR</span>
                                            <span class="tool-subtitle d-block">Load open transfer request</span>
                                        </span>
                                    </button>

                                    <button class="tool-button" type="button" onclick="openStockModal()">
                                        <span class="tool-icon">📦</span>
                                        <span>
                                            <span class="tool-title">Check Stock</span>
                                            <span class="tool-subtitle d-block">Open warehouse stock list</span>
                                        </span>
                                    </button>

                                    <button class="tool-button" type="button" onclick="openSapItModal()">
                                        <span class="tool-icon">✅</span>
                                        <span>
                                            <span class="tool-title">View SAP IT</span>
                                            <span class="tool-subtitle d-block">Check posted transfer records</span>
                                        </span>
                                    </button>
                                </div>

                                <div id="loadedInfo" class="alert alert-secondary info-box mt-3">
                                    No ITR loaded. Click <strong>Select SAP ITR</strong> to start.
                                </div>

                                <button id="saveBtn" class="btn btn-success mt-3" onclick="saveRequest()" disabled>
                                    Submit Issue Request
                                </button>
                            </div>

                            <div class="tab-pane fade" id="pendingPane" role="tabpanel" aria-labelledby="pending-tab" tabindex="0">
                                <div class="pending-tab-head">
                                    <div>
                                        <div class="tab-section-title mb-1">Pending Queue</div>
                                        <div class="pending-status" id="myRequestStatus">Loading requests...</div>
                                    </div>
                                    <button class="btn btn-outline-primary btn-sm" type="button" onclick="loadMyRequests()">Reload</button>
                                </div>

                                <div id="myRequestList" class="requests-panel pending-grid tabbed-request-list"></div>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="itrSelectModal" tabindex="-1" aria-labelledby="itrSelectTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="itrSelectTitle">Select SAP ITR</h5>
                    <div class="text-muted" id="requestStatus">Loading ITRs...</div>
                    <div class="text-muted small" id="requestMonth">Current month + previous month until day 7</div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="modal-search-row">
                    <input class="form-control form-control-lg" id="itrItemSearchInput" placeholder="Search ITR, SAP code, or part name" oninput="renderItrs()">
                    <button class="btn btn-outline-primary" type="button" onclick="loadOpenItrs()">Reload ITR</button>
                </div>

                <div class="mb-3">
                    <span class="badge bg-primary rounded-pill px-3 py-2">
                        <span id="openItrCount">0</span> open ITR(s)
                    </span>
                </div>

                <div id="itrList" class="requests-panel"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sapItModal" tabindex="-1" aria-labelledby="sapItTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="sapItTitle">SAP IT Records</h5>
                    <div class="text-muted" id="sapItStatus">Click Reload to load the latest SAP ITs.</div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="modal-search-row">
                    <input class="form-control form-control-lg" id="sapItSearchInput" placeholder="Search IT, ITR, item, lot" oninput="renderSapInventoryTransfers()">
                    <button class="btn btn-outline-primary" type="button" onclick="loadSapInventoryTransfers()">Reload SAP IT</button>
                </div>

                <div id="sapItList" class="requests-panel"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockModal" tabindex="-1" aria-labelledby="stockTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="stockTitle">Warehouse Stock</h5>
                    <div class="text-muted" id="stockStatus">Loading stock...</div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="modal-search-row">
                    <input class="form-control form-control-lg" id="stockSearchInput" placeholder="Search item or warehouse" oninput="renderStocks()">
                    <button class="btn btn-outline-primary" type="button" onclick="loadStocks()">Reload Stock</button>
                </div>

                <div id="stockList" class="requests-panel"></div>
            </div>
        </div>
    </div>
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

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
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

function getDocWarehouseSummary(doc) {
    const fromWarehouses = [...new Set(
        (doc.lines || [])
            .map(line => String(line.from_whs_code || '').trim())
            .filter(Boolean)
    )];

    const toWarehouses = [...new Set(
        (doc.lines || [])
            .map(line => String(line.to_whs_code || '').trim())
            .filter(Boolean)
    )];

    return {
        from: fromWarehouses.length ? fromWarehouses.join(', ') : '-',
        to: toWarehouses.length ? toWarehouses.join(', ') : '-'
    };
}

function openItrModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('itrSelectModal')).show();

    if (openDocuments.length === 0) {
        loadOpenItrs();
    }
}

function openSapItModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('sapItModal')).show();

    if (sapInventoryTransfers.length === 0 && !sapItLoading) {
        const status = document.getElementById('sapItStatus');

        if (status) {
            status.textContent = 'Click Reload to load the latest SAP ITs.';
        }
    }
}

function openStockModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('stockModal')).show();

    if (stockRows.length === 0) {
        loadStocks();
    }
}

function hideModal(id) {
    const el = document.getElementById(id);

    if (!el) {
        return;
    }

    const modal = bootstrap.Modal.getInstance(el);

    if (modal) {
        modal.hide();
    }
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

function getItrRequestUrl() {
    const today = new Date();
    const includeLastMonth = today.getDate() <= 7;
    const params = new URLSearchParams();

    params.set('scope', includeLastMonth ? 'current_and_last_month' : 'current_month');
    params.set('last_month_grace_days', '7');

    return 'api/get_open_itr_requests.php?' + params.toString();
}

function getItrRangeText(data) {
    if (data && data.range_label) {
        return data.range_label;
    }

    if (data && data.month_start && data.month_end) {
        return data.month_start + ' to ' + data.month_end;
    }

    const today = new Date();
    return today.getDate() <= 7
        ? 'Current month and previous month ITRs. Previous month is shown until the 7th day only.'
        : 'Current month ITRs only.';
}

async function loadOpenItrs() {
    const status = document.getElementById('requestStatus');
    status.textContent = 'Refreshing ITRs...';

    try {
        const res = await fetch(getItrRequestUrl(), { cache: 'no-store' });
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
        document.getElementById('requestMonth').textContent = getItrRangeText(data);

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
                        <span class="badge text-bg-success rounded-pill">View</span>
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

    hideModal('sapItModal');
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

    const wh = getDocWarehouseSummary(doc);

    const headerText = [
        doc.doc_num,
        doc.doc_date,
        wh.from,
        wh.to
    ].join(' ').toLowerCase();

    if (headerText.includes(search)) {
        return true;
    }

    return (doc.lines || []).some(line =>
        String(line.item_code || '').toLowerCase().includes(search) ||
        String(line.part_name || '').toLowerCase().includes(search) ||
        String(line.from_whs_code || '').toLowerCase().includes(search) ||
        String(line.to_whs_code || '').toLowerCase().includes(search)
    );
}

function renderItrs() {
    const list = document.getElementById('itrList');
    const search = (document.getElementById('itrItemSearchInput')?.value || '').trim().toLowerCase();
    list.innerHTML = '';

    if (openDocuments.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No open current/allowed previous-month ITRs from warehouse 01 found.</div>';
        return;
    }

    const rows = openDocuments
        .map((doc, idx) => ({ doc, idx }))
        .filter(row => itrMatchesItemSearch(row.doc, search));

    if (rows.length === 0) {
        list.innerHTML = '<div class="alert alert-light border info-box">No ITRs found for that SAP code, part name, or warehouse.</div>';
        return;
    }

    rows.forEach(({ doc, idx }) => {
        const active = selectedDocument && selectedDocument.doc_num === doc.doc_num ? ' active' : '';
        const wh = getDocWarehouseSummary(doc);

        list.insertAdjacentHTML('beforeend', `
            <div class="itr-card${active}">
                <button type="button" class="itr-header" onclick="loadItr(${idx})">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="request-title">ITR ${esc(doc.doc_num)}</div>
                            <div class="request-meta">${esc(doc.doc_date)} | ${esc(doc.line_count)} item(s)</div>

                            <div class="warehouse-route">
                                <span class="warehouse-pill">From WH: ${esc(wh.from)}</span>
                                <span class="warehouse-arrow">→</span>
                                <span class="warehouse-pill">To WH: ${esc(wh.to)}</span>
                            </div>
                        </div>

                        <span class="badge text-bg-primary rounded-pill">Select</span>
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

                        <div class="qty-box">
                            <div class="label">Warehouse Route</div>
                            <div class="value">${esc(wh.from)} → ${esc(wh.to)}</div>
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
            lot_no: line.lot_no || '',
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

    const wh = getDocWarehouseSummary(doc);

    document.getElementById('loadedInfo').className = 'alert alert-info info-box';
    document.getElementById('loadedInfo').innerHTML =
        'Loaded ITR <strong>' + esc(doc.doc_num) + '</strong>. ' +
        'Warehouse route: <strong>' + esc(wh.from) + '</strong> to <strong>' + esc(wh.to) + '</strong>. ' +
        'Enter quantity only for items needed on the selected date.';

    hideModal('itrSelectModal');
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
        lot_no: line.lot_no || '',
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

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
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

    if (requestItems.length === 0) {
        tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No items loaded. Click Select SAP ITR to start.</td></tr>';
    } else if (visibleItems.length === 0) {
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
    document.getElementById('loadedInfo').innerHTML = 'No ITR loaded. Click <strong>Select SAP ITR</strong> to start.';
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

const requestorRefresh = window.createRefreshController([
    { name: 'requestorItrs', fn: loadOpenItrs, intervalMs: 60000 },
    { name: 'requestorRequests', fn: loadMyRequests, intervalMs: 60000 },
    { name: 'requestorStocks', fn: loadStocks, intervalMs: 120000 }
]);

requestorRefresh.scheduleAll();
renderTable();
</script>

</body>
</html>
