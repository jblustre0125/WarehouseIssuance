<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN]);

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');

$area = $currentUser['receiver_area'] ?: 'Receiver';
$prefillToken = trim($_GET['token'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <title>Receiver Scan</title>
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

        .scan-input {
            font-size: 18px;
            padding: 14px 16px;
            min-height: 58px;
        }

        .form-control {
            border-radius: 12px;
            border: 1px solid #d9e2ef;
            background-color: #f9fbfd;
        }

        .form-control:focus {
            background-color: #ffffff;
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .status-panel {
            min-height: 86px;
            border-radius: 14px;
            font-size: 14px;
        }

        .exception-card {
            border: 1px solid #fecaca;
            background: #fff7f7;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .exception-title {
            color: #b42318;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .exception-preview {
            border-radius: 12px;
            font-size: 14px;
        }

        .receiver-table-wrap {
            max-height: 48vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .receiver-table {
            width: 100%;
            table-layout: fixed;
            font-size: 12px;
            margin-bottom: 0;
        }

        .receiver-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 9px 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .receiver-table td {
            padding: 8px 6px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .receiver-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-time {
            width: 10%;
            white-space: nowrap;
        }

        .col-trace {
            width: 15%;
            white-space: nowrap;
        }

        .col-item {
            width: 15%;
            white-space: nowrap;
        }

        .col-part {
            width: 25%;
            white-space: normal;
            line-height: 1.25;
        }

        .col-lot {
            width: 13%;
            white-space: nowrap;
        }

        .col-qty {
            width: 10%;
            white-space: nowrap;
            text-align: right;
        }

        .col-status {
            width: 12%;
            white-space: nowrap;
            font-weight: 800;
        }

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
        }

        @media (max-width: 1200px) {
            .receiver-table {
                font-size: 11px;
            }

            .receiver-table thead th {
                font-size: 10px;
                padding: 8px 4px;
            }

            .receiver-table td {
                padding: 7px 4px;
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

            .receiver-table-wrap {
                overflow: auto;
            }

            .receiver-table {
                min-width: 850px;
                table-layout: auto;
            }

            .receiver-table td {
                white-space: nowrap;
            }

            .col-time,
            .col-trace,
            .col-item,
            .col-part,
            .col-lot,
            .col-qty,
            .col-status {
                width: auto;
                min-width: 110px;
            }

            .col-part {
                min-width: 220px;
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
        <div class="shell-subtitle">Receiver workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('receiver'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>Receiver Scan</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">Receiver - <?= h($area) ?></h4>
                <div class="page-subtitle">
                    Scan each digital item QR. Matched items are received automatically; use exception entry only for mismatch.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2" id="statusBadge">
                Waiting
            </span>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Receiving Scan</h5>
                <div class="content-card-subtitle">
                    Scan the item QR label to validate and receive.
                </div>
            </div>

            <div class="content-card-body">

                <div class="row g-2 mb-3">
                    <div class="col-12">
                        <input
                            id="scanInput"
                            class="form-control scan-input"
                            placeholder="Scan item QR label"
                            value="<?= h($prefillToken) ?>"
                            autofocus
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div id="scanStatus" class="alert alert-secondary status-panel">
                    Ready to scan item QR.
                </div>

                <div class="mb-3">
                    <button class="btn btn-outline-danger btn-sm" type="button" onclick="toggleException()">
                        Exception Receive
                    </button>
                </div>

                <div id="exceptionBox" class="exception-card d-none">
                    <h5 class="exception-title">Mismatch / Exception Receiving</h5>
                    <div class="text-muted small mb-3">
                        Use this only when the actual received quantity is different from the issued quantity.
                    </div>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <input id="exceptionToken" class="form-control" placeholder="Scan item QR label">
                        </div>

                        <div class="col-md-3">
                            <input id="exceptionLot" class="form-control" placeholder="Issued lot" readonly>
                        </div>

                        <div class="col-md-2">
                            <input id="exceptionQty" class="form-control" type="number" step="0.001" placeholder="Actual qty">
                        </div>

                        <div class="col-md-3">
                            <input id="exceptionRemarks" class="form-control" placeholder="Remarks required">
                        </div>
                    </div>

                    <div id="exceptionPreview" class="alert alert-light border mt-2 mb-0 d-none exception-preview"></div>

                    <div class="text-end mt-2">
                        <button class="btn btn-danger" type="button" onclick="saveException()">
                            Save Exception
                        </button>
                    </div>
                </div>

                <div class="receiver-table-wrap">
                    <table class="table table-bordered table-striped align-middle receiver-table" id="scanTable">
                        <thead>
                            <tr>
                                <th class="col-time">Time</th>
                                <th class="col-trace">Trace No</th>
                                <th class="col-item">Part Number</th>
                                <th class="col-part">Part Name</th>
                                <th class="col-lot">Issued Lot</th>
                                <th class="col-qty">Issued Qty</th>
                                <th class="col-status">Status</th>
                            </tr>
                        </thead>

                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>

    </main>
</div>

<div class="modal fade" id="duplicateModal" tabindex="-1" aria-labelledby="duplicateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title fw-bold text-warning-emphasis" id="duplicateModalLabel">
                    Item Already Received / Closed
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="duplicateModalMessage" class="mb-3">
                    This item was already received or closed.
                </div>

                <div id="duplicateModalDetails" class="border rounded p-3 bg-light d-none"></div>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-warning"
                    id="duplicateModalOkBtn"
                    data-bs-dismiss="modal"
                    onclick="clearScan()"
                >
                    OK
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<script>
function esc(v) {
    return String(v ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[c]));
}

let scanTimer = null;
let exceptionTimer = null;
let scanBusy = false;
let duplicateModalInstance = null;

function extractToken(raw) {
    raw = String(raw || '').trim();

    try {
        const u = new URL(raw);
        return u.searchParams.get('token') || raw;
    } catch (e) {}

    const m = raw.match(/token=([A-Fa-f0-9]+)/);
    return m ? m[1] : raw;
}

async function postReceive(payload) {
    const body = new URLSearchParams(payload);

    const res = await fetch('api/scan_receive_item.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body
    });

    return await res.json();
}

function isDuplicateOrClosed(data) {
    const msg = String(data?.message || '').toLowerCase();
    const status = String(data?.status || '').toLowerCase();

    return Boolean(
        data?.duplicate ||
        data?.closed ||
        status === 'duplicate' ||
        status === 'closed' ||
        status === 'received' ||
        msg.includes('already received') ||
        msg.includes('already closed') ||
        msg.includes('was already received') ||
        msg.includes('closed')
    );
}

function showDuplicateModal(message, line) {
    document.getElementById('duplicateModalMessage').textContent =
        message || 'This item was already received or closed.';

    const detailBox = document.getElementById('duplicateModalDetails');

    if (line) {
        const lot = line.issued_lot_no || line.lot_no || '';
        const qty = line.issued_qty || line.qty || '';

        detailBox.classList.remove('d-none');
        detailBox.innerHTML = `
            <div><strong>Trace No:</strong> ${esc(line.trace_no || '')}</div>
            <div><strong>Part Number:</strong> ${esc(line.item_code || '')}</div>
            <div><strong>Part Name:</strong> ${esc(line.part_name || '')}</div>
            <div><strong>Issued Lot:</strong> ${esc(lot)}</div>
            <div><strong>Issued Qty:</strong> ${esc(qty)}</div>
        `;
    } else {
        detailBox.classList.add('d-none');
        detailBox.innerHTML = '';
    }

    document.getElementById('statusBadge').textContent = 'Duplicate';
    setStatus('warning', message || 'This item was already received or closed.', line);

    if (!duplicateModalInstance) {
        duplicateModalInstance = new bootstrap.Modal(document.getElementById('duplicateModal'));
    }

    duplicateModalInstance.show();
}

function toggleException() {
    const box = document.getElementById('exceptionBox');
    const opening = box.classList.contains('d-none');

    box.classList.toggle('d-none');

    if (opening) {
        document.getElementById('exceptionToken').value = extractToken(document.getElementById('scanInput').value);
        document.getElementById('exceptionToken').focus();

        if (document.getElementById('exceptionToken').value.trim()) {
            lookupExceptionItem();
        }
    } else {
        clearException();
        clearScan();
    }
}

async function receiveScan() {
    if (scanBusy) {
        return;
    }

    const input = document.getElementById('scanInput');
    const token = extractToken(input.value);

    if (!token) {
        setStatus('danger', 'Item QR token is required.');
        input.focus();
        return;
    }

    scanBusy = true;
    document.getElementById('statusBadge').textContent = 'Scanning';

    try {
        const data = await postReceive({ token });

        if (data.ok) {
            setStatus(data.status === 'MATCHED' ? 'success' : 'warning', data.message, data.line);
            addRow(data.line, data.status);
            document.getElementById('exceptionBox').classList.add('d-none');
            clearScan();
            document.getElementById('statusBadge').textContent = 'Received';
        } else if (isDuplicateOrClosed(data)) {
            showDuplicateModal(data.message || 'This item was already received or closed.', data.line);
            clearException();
            document.getElementById('exceptionBox').classList.add('d-none');
        } else {
            setStatus('danger', data.message || 'Unable to receive item.', data.line);
            document.getElementById('exceptionBox').classList.remove('d-none');
            document.getElementById('exceptionToken').value = token;
            document.getElementById('exceptionLot').focus();
            document.getElementById('statusBadge').textContent = 'Exception';
        }
    } catch (e) {
        setStatus('danger', 'Scanner validation failed. Check network/server connection.');
        document.getElementById('statusBadge').textContent = 'Error';
    } finally {
        scanBusy = false;
    }
}

async function saveException() {
    const token = extractToken(document.getElementById('exceptionToken').value);
    const lot = document.getElementById('exceptionLot').value.trim();
    const qty = document.getElementById('exceptionQty').value.trim();
    const remarks = document.getElementById('exceptionRemarks').value.trim();

    if (!token || !lot || !qty) {
        setStatus('danger', 'Exception requires token, issued lot, and actual qty. Scan the QR in Exception Receive first.');
        return;
    }

    if (!remarks) {
        setStatus('danger', 'Exception remarks are required.');
        return;
    }

    const data = await postReceive({
        mode: 'exception',
        token,
        received_lot_no: lot,
        received_qty: qty,
        remarks
    });

    if (data.ok) {
        setStatus(data.status === 'MATCHED' ? 'success' : 'warning', data.message, data.line);
        addRow(data.line, data.status);

        document.getElementById('exceptionBox').classList.add('d-none');
        document.getElementById('exceptionToken').value = '';

        clearException();
        clearScan();
        document.getElementById('statusBadge').textContent = 'Received';
    } else if (isDuplicateOrClosed(data)) {
        showDuplicateModal(data.message || 'This item was already received or closed.', data.line);
        clearException();
        document.getElementById('exceptionBox').classList.add('d-none');
    } else {
        setStatus('danger', data.message || 'Unable to save exception.', data.line);
        document.getElementById('statusBadge').textContent = 'Exception';
    }
}

async function lookupExceptionItem() {
    const token = extractToken(document.getElementById('exceptionToken').value);

    if (!token) {
        return;
    }

    try {
        const data = await postReceive({
            mode: 'lookup',
            token
        });

        if (data.ok) {
            document.getElementById('exceptionToken').value = token;
            document.getElementById('exceptionLot').value = data.line.issued_lot_no || data.line.lot_no || '';
            document.getElementById('exceptionQty').value = data.line.issued_qty || '';

            showExceptionPreview(data.line);
            setStatus('info', 'Item loaded. Enter actual received quantity and remarks.', data.line);

            document.getElementById('exceptionQty').focus();
        } else if (isDuplicateOrClosed(data)) {
            showDuplicateModal(data.message || 'This item was already received or closed.', data.line);
            clearException();
            document.getElementById('exceptionBox').classList.add('d-none');
        } else {
            clearException(false);
            setStatus('danger', data.message || 'Item QR not found.');
        }
    } catch (e) {
        setStatus('danger', 'Unable to load item details. Check network/server connection.');
    }
}

function showExceptionPreview(line) {
    const box = document.getElementById('exceptionPreview');

    box.classList.remove('d-none');

    box.innerHTML =
        `<b>${esc(line.item_code)}</b> - ${esc(line.part_name)}<br>` +
        `Trace ${esc(line.trace_no)} | Issued lot ${esc(line.issued_lot_no || line.lot_no)} | Issued qty ${esc(line.issued_qty)}`;
}

function clearException(clearToken = true) {
    if (clearToken) {
        document.getElementById('exceptionToken').value = '';
    }

    document.getElementById('exceptionLot').value = '';
    document.getElementById('exceptionQty').value = '';
    document.getElementById('exceptionRemarks').value = '';
    document.getElementById('exceptionPreview').classList.add('d-none');
    document.getElementById('exceptionPreview').innerHTML = '';
}

function setStatus(type, msg, line) {
    const box = document.getElementById('scanStatus');

    box.className = 'alert alert-' + type + ' status-panel';

    const detail = line
        ? `<div class="mt-1"><b>${esc(line.item_code)}</b> - ${esc(line.part_name)} | Lot ${esc(line.issued_lot_no || line.lot_no)} | Qty ${esc(line.issued_qty)}</div>`
        : '';

    box.innerHTML = esc(msg) + detail;
}

function addRow(line, status) {
    if (!line) {
        return;
    }

    const tb = document.querySelector('#scanTable tbody');
    const cls = status === 'MATCHED' ? 'text-success' : 'text-danger';
    const lot = line.issued_lot_no || line.lot_no || '';
    const qty = line.issued_qty || line.qty || '';
    const time = new Date().toLocaleTimeString();

    tb.insertAdjacentHTML('afterbegin', `
        <tr>
            <td class="col-time" title="${esc(time)}">${esc(time)}</td>
            <td class="col-trace" title="${esc(line.trace_no)}">${esc(line.trace_no)}</td>
            <td class="col-item" title="${esc(line.item_code)}">${esc(line.item_code)}</td>
            <td class="col-part" title="${esc(line.part_name)}">${esc(line.part_name)}</td>
            <td class="col-lot" title="${esc(lot)}">${esc(lot)}</td>
            <td class="col-qty" title="${esc(qty)}">${esc(qty)}</td>
            <td class="col-status ${cls}" title="${esc(status)}">${esc(status)}</td>
        </tr>
    `);
}

function clearScan() {
    const input = document.getElementById('scanInput');

    input.value = '';
    input.focus();
}

function scheduleAutoReceive() {
    clearTimeout(scanTimer);

    if (document.getElementById('scanInput').value.trim()) {
        scanTimer = setTimeout(receiveScan, 300);
    }
}

function scheduleExceptionLookup() {
    clearTimeout(exceptionTimer);

    if (document.getElementById('exceptionToken').value.trim()) {
        exceptionTimer = setTimeout(lookupExceptionItem, 300);
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

document.getElementById('scanInput').addEventListener('input', scheduleAutoReceive);

document.getElementById('scanInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(scanTimer);
        receiveScan();
    }
});

document.getElementById('exceptionToken').addEventListener('input', scheduleExceptionLookup);

document.getElementById('exceptionToken').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(exceptionTimer);
        lookupExceptionItem();
    }
});

['exceptionQty', 'exceptionRemarks'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveException();
        }
    });
});

document.getElementById('duplicateModal').addEventListener('shown.bs.modal', function () {
    const okBtn = document.getElementById('duplicateModalOkBtn');

    if (okBtn) {
        okBtn.focus();
    }
});

document.getElementById('duplicateModal').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();

        const modalEl = document.getElementById('duplicateModal');
        const modal = bootstrap.Modal.getInstance(modalEl);

        if (modal) {
            modal.hide();
        }

        clearScan();
    }
});

document.getElementById('duplicateModal').addEventListener('hidden.bs.modal', function () {
    clearScan();
});

if (document.getElementById('scanInput').value.trim()) {
    receiveScan();
}
</script>

</body>
</html>
