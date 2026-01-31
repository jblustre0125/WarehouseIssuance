<!DOCTYPE html>
<html>

<head>
    <title>Scan ID Tag</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }

        .navbar .navbar-logo {
            max-height: 28px;
            width: auto;
            border-radius: 6px;
            object-fit: cover;
            display: inline-block
        }

        .card {
            margin-top: 1.5rem;
            position: relative;
            z-index: 2;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(16, 24, 40, 0.06);
        }

        .scan-input {
            padding: 10px 12px;
            border-radius: 8px
        }

        .small-note {
            font-size: 0.9rem;
            color: #6b7280
        }

        .table-responsive {
            max-height: 60vh;
            overflow: auto;
        }

        @media(min-width:720px) {
            .card {
                margin-top: 90px;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="image/nbc-bg-dashboard.jpg" alt="NBC logo" class="navbar-logo me-2">
                <span class="mb-0">NBC (Philippines) Car Technology Corporation</span>
            </a>
            <div class="d-flex align-items-center">
                <div class="small-note me-3 text-light">Warehouse Issuance</div>
                <button class="btn btn-outline-light btn-sm me-2" id="navCount">0</button>
                <a class="btn btn-sm btn-outline-light" href="view_tags.php">View Saved</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card p-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h4 class="mb-1 text-primary">Scan ID Tag</h4>
                        <div class="small-note">Scan QR or barcode to stage items before saving</div>
                    </div>
                </div>

                <form id="scanForm" autocomplete="off" onsubmit="return false;">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <input type="text" id="scanner_id" class="form-control scan-input" placeholder="Enter/Scan Scanner ID here" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <input type="text" id="itr_input" class="form-control scan-input" placeholder="Enter ITR Number here" required>
                        </div>
                        <div class="col-12 mt-2">
                            <input type="text" id="qr_input" class="form-control scan-input" placeholder="Enter or Scan QR/Barcode here" required onkeydown="if(event.key==='Enter'){event.preventDefault();addItem();}">
                        </div>
                        <div class="col-12 text-end mt-2">
                            <button class="btn btn-primary" id="saveAllBtn" type="button" onclick="saveAllItems()" style="display:none;">Save All</button>
                        </div>
                    </div>
                </form>

                <div class="table-responsive mt-3">
                    <table class="table table-hover table-sm" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Part Name</th>
                                <th>Quantity</th>
                                <th>Lot No</th>
                                <th>ITR Number</th>
                                <th>Scanner ID</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-primary">Confirm Save</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="modalCount" class="fw-semibold mb-2"></div>
                    <pre id="modalList" style="max-height:320px;overflow:auto;background:#f8fafc;padding:10px;border-radius:6px;font-family:monospace;font-size:0.95rem"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="modalConfirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Focus on scanner ID input on load
        document.getElementById('scanner_id').focus();

        // Store items in memory for this session
        let scannedItems = [];


        function addItem() {
            const scannerId = document.getElementById('scanner_id').value.trim();
            const itrNumber = document.getElementById('itr_input').value.trim();
            const qr = document.getElementById('qr_input').value.trim();
            if (!scannerId || !itrNumber || !qr) {
                alert('All fields are required.');
                return;
            }
            // Parse QR
            let itemCode = '';
            let quantity = '';
            let lotNo = '';
            let qrStr = qr.replace(/\s+/g, '');
            let matchItem = qrStr.match(/\(01\)(\d{8,})/);
            if (matchItem) itemCode = matchItem[1];
            let matchQty = qrStr.match(/\(17\)(\d+)/);
            if (matchQty) quantity = matchQty[1];
            let matchLot = qrStr.match(/\(10\)([A-Za-z0-9\-]+)/);
            if (matchLot) lotNo = matchLot[1];
            if (!itemCode || !quantity || !lotNo) {
                alert('QR code must contain (01)ItemCode, (17)Quantity, and (10)LotNo.');
                return;
            }
            // Fetch part name from server
            fetch('get_part_name.php?item_code=' + encodeURIComponent(itemCode))
                .then(response => response.json())
                .then(data => {
                    let partName = data.part_name || '';
                    scannedItems.push({
                        scanner_id: scannerId,
                        itr_number: itrNumber,
                        item_code: itemCode,
                        part_name: partName,
                        quantity: quantity,
                        lot_no: lotNo
                    });
                    renderTable();
                    document.getElementById('qr_input').value = '';
                    document.getElementById('qr_input').focus();
                    document.getElementById('saveAllBtn').style.display = scannedItems.length > 0 ? '' : 'none';
                    updateNavCount();
                })
                .catch(() => {
                    alert('Failed to fetch part name.');
                });
        }

        function renderTable() {
            const tbody = document.querySelector('#itemsTable tbody');
            tbody.innerHTML = '';
            scannedItems.forEach((item, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${item.item_code}</td>
                    <td>${item.part_name || ''}</td>
                    <td>${item.quantity}</td>
                    <td>${item.lot_no}</td>
                    <td>${item.itr_number}</td>
                    <td>${item.scanner_id}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // removeItem removed — rows are not removable from UI

        function updateNavCount() {
            const el = document.getElementById('navCount');
            if (el) el.textContent = scannedItems.length;
        }

        function saveAllItems() {
            if (scannedItems.length === 0) return;
            // build preview
            const preview = scannedItems.slice(0, 20).map(i => i.item_code + ' (' + i.quantity + ')').join('\n');
            document.getElementById('modalCount').textContent = 'You are about to save ' + scannedItems.length + ' item(s).';
            document.getElementById('modalList').textContent = preview + (scannedItems.length > 20 ? '\n...and ' + (scannedItems.length - 20) + ' more' : '');
            // show bootstrap modal
            const bsModalEl = document.getElementById('confirmModal');
            const bsModal = new bootstrap.Modal(bsModalEl);
            bsModal.show();
            document.getElementById('modalConfirm').onclick = function() {
                bsModal.hide();
                confirmSave();
            };
        }

        function confirmSave() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'save_tag.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'batch_items';
            input.value = JSON.stringify(scannedItems);
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>

</html>