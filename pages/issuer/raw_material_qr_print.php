<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
raw_material_qr_print_require_access();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= h(app_path('')) ?>">
    <title>Raw Material QR Labels</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --page-bg: #eef2f7;
            --line: #17365d;
            --text: #111827;
            --muted: #64748b;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page-bg); color: var(--text); font-family: Arial, Helvetica, sans-serif; }
        .main-content { margin-left: var(--sidebar-width); padding: 4.25rem 18px 18px; min-height: 100vh; }
        .toolbar {
            position: sticky; top: 0; z-index: 20;
            padding: 14px; margin-bottom: 16px;
            background: #fff; border: 1px solid #d9e2ef; border-radius: 14px;
            box-shadow: 0 10px 28px rgba(15,23,42,.08);
        }
        .toolbar-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .toolbar .form-control, .toolbar .form-select, .toolbar .btn { min-height: 40px; border-radius: 9px; }
        .toolbar .btn { font-weight: 700; }
        .status { margin-top: 8px; color: var(--muted); font-size: 13px; }

        .print-grid {
            width: min(100%, 980px);
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fill, 2in);
            grid-auto-rows: 2in;
            gap: 10px;
            justify-content: center;
        }
        .material-card {
            position: relative;
            width: 2in;
            height: 2in;
            padding: .10in .10in .08in;
            border: 1.5px solid var(--line);
            background: #fff;
            overflow: hidden;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .material-card.hidden { display: none !important; }
        .select-box { position: absolute; top: 5px; right: 5px; width: 16px; height: 16px; z-index: 2; }
        .qr-wrap { height: .93in; display: flex; align-items: center; justify-content: center; margin-bottom: .03in; }
        .qr-box, .qr-box canvas, .qr-box img { width: .88in !important; height: .88in !important; display: block; }
        .field-row { margin: .025in 0; font-size: 6.8pt; line-height: 1.18; overflow: hidden; }
        .field-label { font-weight: 800; }
        .item-code { font-weight: 800; word-break: break-all; }
        .part-name { display: block; max-height: .35in; overflow: hidden; font-weight: 700; text-transform: uppercase; }
        .item-type { display: block; max-height: .16in; overflow: hidden; color: #475569; font-size: 6.2pt; font-weight: 700; text-transform: uppercase; }
        .location { font-weight: 900; }
        .empty-state { grid-column: 1 / -1; padding: 55px 20px; text-align: center; background: #fff; border: 1px dashed #94a3b8; color: var(--muted); }

        @media (max-width: 900px) {
            .main-content { margin-left: 0; padding: 4.25rem 12px 12px; }
        }

        @page { size: A4 portrait; margin: 7mm; }
        @media print {
            html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .sidebar, .sap-side-nav, .sap-shellbar, .toolbar, .mobile-topbar, .sidebar-backdrop { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: auto !important; min-height: 0 !important; }
            .print-grid {
                width: 100% !important;
                margin: 0 !important;
                display: grid !important;
                grid-template-columns: repeat(3, 2in) !important;
                grid-auto-rows: 2in !important;
                column-gap: .18in !important;
                row-gap: .18in !important;
                justify-content: center !important;
                align-content: start !important;
            }
            .material-card {
                width: 2in !important;
                height: 2in !important;
                min-width: 2in !important;
                min-height: 2in !important;
                max-width: 2in !important;
                max-height: 2in !important;
                border-width: .35mm !important;
            }
            .material-card:nth-of-type(15n) { break-after: page !important; page-break-after: always !important; }
            .select-box { display: none !important; }
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
        <div class="shell-subtitle">Raw material QR labels</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('raw_material_qr_print'); ?>
    <main class="main-content">
        <div class="toolbar">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h4 class="mb-1 fw-bold">Raw Material QR Labels</h4>
                    <div class="text-muted small">Each label is exactly 2 × 2 inches. A4 portrait fits 3 columns × 5 rows, or 15 labels per page.</div>
                </div>
                <a class="btn btn-outline-secondary" href="<?= h(app_path('pages/issuer/issuer.php')) ?>">Back to Issuer</a>
            </div>

            <div class="toolbar-row">
                <div style="flex:1 1 280px;">
                    <label class="form-label small fw-bold mb-1" for="searchInput">Search</label>
                    <input class="form-control" id="searchInput" placeholder="Item code, parts code, item name, or location">
                </div>
                <div style="width:180px;">
                    <label class="form-label small fw-bold mb-1" for="locationFilter">Location</label>
                    <select class="form-select" id="locationFilter"><option value="">All locations</option></select>
                </div>
                <button class="btn btn-outline-primary" type="button" id="selectVisibleBtn">Select Visible</button>
                <button class="btn btn-outline-secondary" type="button" id="clearSelectionBtn">Clear</button>
                <button class="btn btn-primary" type="button" id="printSelectedBtn">Print Selected</button>
                <button class="btn btn-success" type="button" id="printAllBtn">Print All</button>
            </div>
            <div class="status" id="statusText">Loading raw-material master...</div>
        </div>

        <section class="print-grid" id="printGrid">
            <div class="empty-state">Loading raw-material master...</div>
        </section>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function closeSidebar() {
    sidebar?.classList.remove('show');
    sidebarBackdrop?.classList.remove('show');
}

if (sidebarToggle && sidebar && sidebarBackdrop) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

sidebarBackdrop?.addEventListener('click', closeSidebar);
document.querySelectorAll('.sap-nav-link').forEach(function (link) {
    link.addEventListener('click', closeSidebar);
});

(function () {
    'use strict';

    const API = <?= json_encode(app_path('api/issuer/raw_material_qr_master.php')) ?>;
    const grid = document.getElementById('printGrid');
    const searchInput = document.getElementById('searchInput');
    const locationFilter = document.getElementById('locationFilter');
    const statusText = document.getElementById('statusText');
    let rows = [];
    let sourceTable = '';

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function qrPayload(row) {
        return `ITEM:${row.item_code}|LOCATION:${row.location_code}`;
    }

    function cardHtml(row, index) {
        const searchText = [row.item_code, row.parts_code, row.item_name, row.location_code].join(' ').toLowerCase();
        return `
            <article class="material-card" data-index="${index}" data-search="${esc(searchText)}" data-location="${esc(row.location_code.toLowerCase())}">
                <input class="form-check-input select-box material-check" type="checkbox" value="${index}" aria-label="Select ${esc(row.item_code)}">
                <div class="qr-wrap"><div class="qr-box" id="qr_${index}"></div></div>
                <div class="field-row"><span class="field-label">ITEM CODE:</span> <span class="item-code">${esc(row.item_code)}</span></div>
                <div class="field-row"><span class="field-label">PART NAME:</span><span class="part-name">${esc(row.parts_code || row.item_name)}</span></div>
                ${row.item_name ? `<span class="item-type">${esc(row.item_name)}</span>` : ''}
                <div class="field-row"><span class="field-label">LOCATION:</span> <span class="location">${esc(row.location_code || '-')}</span></div>
            </article>`;
    }

    function buildQr(index, row) {
        const target = document.getElementById('qr_' + index);
        if (!target) return;
        target.innerHTML = '';
        new QRCode(target, {
            text: qrPayload(row),
            width: 100,
            height: 100,
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function populateLocationFilter() {
        const locations = [...new Set(rows.map(r => r.location_code).filter(Boolean))].sort((a, b) => a.localeCompare(b));
        locationFilter.innerHTML = '<option value="">All locations</option>' + locations.map(v => `<option value="${esc(v.toLowerCase())}">${esc(v)}</option>`).join('');
    }

    function applyFilters() {
        const q = searchInput.value.trim().toLowerCase();
        const location = locationFilter.value;
        let visible = 0;
        document.querySelectorAll('.material-card').forEach(card => {
            const show = (!q || card.dataset.search.includes(q)) && (!location || card.dataset.location === location);
            card.classList.toggle('hidden', !show);
            if (show) visible++;
        });
        updateStatus(visible);
    }

    function updateStatus(visibleCount = null) {
        const visible = visibleCount ?? document.querySelectorAll('.material-card:not(.hidden)').length;
        const selected = document.querySelectorAll('.material-check:checked').length;
        statusText.textContent = `${visible} visible of ${rows.length} raw materials • ${selected} selected` + (sourceTable ? ` • Source: ${sourceTable}` : '');
    }

    function render() {
        if (!rows.length) {
            grid.innerHTML = '<div class="empty-state">No active raw materials found.</div>';
            updateStatus(0);
            return;
        }
        grid.innerHTML = rows.map(cardHtml).join('');
        rows.forEach((row, index) => buildQr(index, row));
        populateLocationFilter();
        applyFilters();
        document.querySelectorAll('.material-check').forEach(cb => cb.addEventListener('change', () => updateStatus()));
    }

    async function loadRows() {
        try {
            const response = await fetch(API, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const raw = await response.text();
            let data = null;

            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                const preview = raw.replace(/\s+/g, ' ').trim().slice(0, 300);
                throw new Error(
                    'The raw-material API returned HTML instead of JSON. ' +
                    'HTTP ' + response.status + '. Response: ' + preview
                );
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.message || ('Unable to load raw-material master. HTTP ' + response.status));
            }

            rows = Array.isArray(data.rows) ? data.rows : [];
            sourceTable = data.source_table || '';
            render();
        } catch (error) {
            console.error(error);
            grid.innerHTML = `<div class="empty-state">${esc(error.message)}</div>`;
            statusText.textContent = 'Unable to load raw materials.';
        }
    }

    function preparePrint(mode) {
        const cards = [...document.querySelectorAll('.material-card')];
        const selected = new Set([...document.querySelectorAll('.material-check:checked')].map(cb => cb.value));
        if (mode === 'selected' && selected.size === 0) {
            alert('Select at least one raw material first.');
            return;
        }
        cards.forEach(card => {
            const shouldPrint = mode === 'all'
                ? !card.classList.contains('hidden')
                : selected.has(card.dataset.index);
            card.dataset.printHidden = shouldPrint ? '0' : '1';
            if (!shouldPrint) card.style.display = 'none';
        });
        window.print();
        cards.forEach(card => {
            card.style.display = '';
            delete card.dataset.printHidden;
        });
    }

    searchInput.addEventListener('input', applyFilters);
    locationFilter.addEventListener('change', applyFilters);
    document.getElementById('selectVisibleBtn').addEventListener('click', () => {
        document.querySelectorAll('.material-card:not(.hidden) .material-check').forEach(cb => cb.checked = true);
        updateStatus();
    });
    document.getElementById('clearSelectionBtn').addEventListener('click', () => {
        document.querySelectorAll('.material-check').forEach(cb => cb.checked = false);
        updateStatus();
    });
    document.getElementById('printSelectedBtn').addEventListener('click', () => preparePrint('selected'));
    document.getElementById('printAllBtn').addEventListener('click', () => preparePrint('all'));

    loadRows();
}());
</script>
</body>
</html>
