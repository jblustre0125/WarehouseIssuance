<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/itr_pack_sizes.php';

itr_pack_sizes_require_maintainer();

$conn = get_whpokayoke_connection();

if (!itr_pack_sizes_ensure_table($conn)) {
    app_error('Unable to prepare qty per pack table.');
}

item_locations_ensure_table($conn);

$seeded = itr_pack_sizes_seed_from_json($conn);
$q = trim((string)($_GET['q'] ?? ''));
$editCode = trim((string)($_GET['edit'] ?? ''));
$showInactive = isset($_GET['inactive']);

$editRow = null;

if ($editCode !== '') {
    $editRow = fetch_one(
        $conn,
        "SELECT
            P.ItemCode,
            COALESCE(NULLIF(P.PartName, ''), NULLIF(L.PartsCode, ''), NULLIF(L.ItemName, ''), '') AS PartName,
            P.QtyPerPack,
            P.SourceName,
            P.IsActive
         FROM dbo.RawMaterialQtyPerPack P
         LEFT JOIN dbo.RawMaterialItemLocations L ON L.ItemCode = P.ItemCode
         WHERE P.ItemCode = ?",
        [$editCode]
    );
}

$where = [];
$params = [];

if (!$showInactive) {
    $where[] = 'P.IsActive = 1';
}

if ($q !== '') {
    $searchParts = ['P.ItemCode LIKE ?', 'P.PartName LIKE ?', 'P.SourceName LIKE ?', 'L.PartsCode LIKE ?', 'L.ItemName LIKE ?'];
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);

    $sapNameCodes = itr_pack_item_codes_matching_part_name($q);
    if (!empty($sapNameCodes)) {
        $searchParts[] = 'P.ItemCode IN (' . implode(',', array_fill(0, count($sapNameCodes), '?')) . ')';
        array_push($params, ...$sapNameCodes);
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$rows = fetch_all(
    $conn,
    "SELECT TOP 500
        P.ItemCode,
        COALESCE(NULLIF(P.PartName, ''), NULLIF(L.PartsCode, ''), NULLIF(L.ItemName, ''), '') AS PartName,
        P.QtyPerPack,
        P.SourceName,
        P.IsActive,
        P.UpdatedAt,
        P.UpdatedByUsername
     FROM dbo.RawMaterialQtyPerPack P
     LEFT JOIN dbo.RawMaterialItemLocations L ON L.ItemCode = P.ItemCode
     {$whereSql}
     ORDER BY P.ItemCode",
    $params
);

$itemCodesForNames = [];
if ($editRow) {
    $itemCodesForNames[] = $editRow['ItemCode'] ?? '';
}

foreach ($rows as $row) {
    $itemCodesForNames[] = $row['ItemCode'] ?? '';
}

$sapPartNames = itr_pack_part_names_by_codes($itemCodesForNames);

if ($editRow) {
    $editCodeKey = itr_pack_normalize_item_code($editRow['ItemCode'] ?? '');
    if (trim((string)($editRow['PartName'] ?? '')) === '' && isset($sapPartNames[$editCodeKey])) {
        $editRow['PartName'] = $sapPartNames[$editCodeKey];
    }
}

foreach ($rows as $index => $row) {
    $codeKey = itr_pack_normalize_item_code($row['ItemCode'] ?? '');
    if (trim((string)($row['PartName'] ?? '')) === '' && isset($sapPartNames[$codeKey])) {
        $rows[$index]['PartName'] = $sapPartNames[$codeKey];
    }
}

$totalActive = fetch_one($conn, "SELECT COUNT(*) AS Cnt FROM dbo.RawMaterialQtyPerPack WHERE IsActive = 1");
?>
<!doctype html>
<html lang="en">
<head>
    <title>Qty/Pack Maintenance | Warehouse Issuance</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: #f7f7f7;
            color: #32363a;
            font-family: "72", "Segoe UI", Arial, Helvetica, sans-serif;
        }

        .main-content {
            margin-left: 17rem;
            padding: 4.25rem 1rem 1.25rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .page-title {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 800;
        }

        .page-subtitle {
            color: #6a6d70;
            margin-top: .25rem;
            font-size: .92rem;
        }

        .content-card {
            background: #fff;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            box-shadow: 0 0.125rem 0.5rem rgba(0, 0, 0, .06);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .content-card-header {
            padding: .9rem 1rem;
            border-bottom: 1px solid #ebebeb;
            background: #fff;
        }

        .content-card-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
        }

        .content-card-body {
            padding: 1rem;
        }

        .form-label {
            font-size: .78rem;
            font-weight: 800;
            color: #1f2a44;
        }

        .form-control,
        .form-select {
            border-radius: 6px;
            border-color: #b8c3d1;
        }

        .btn {
            border-radius: 6px;
            font-weight: 700;
        }

        .pack-table-wrap {
            max-height: 58vh;
            overflow: auto;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
        }

        .pack-table {
            margin-bottom: 0;
            font-size: .82rem;
            table-layout: fixed;
            min-width: 1040px;
        }

        .pack-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            font-size: .72rem;
            text-transform: uppercase;
        }

        .pack-table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .col-code { width: 14%; }
        .col-name { width: 24%; }
        .col-qty { width: 12%; }
        .col-source { width: 18%; }
        .col-updated { width: 20%; }
        .col-actions { width: 12%; }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding-top: 4.25rem;
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
        <div class="shell-subtitle">Qty per pack maintenance</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('pack_sizes'); ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Qty/Pack Maintenance</h1>
                <div class="page-subtitle">
                    Maintained by Edwin Sanchez. Values are used by SAP ITR request and issuer packing quantities.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2">
                <?= number_format((int)($totalActive['Cnt'] ?? 0)) ?> active item(s)
            </span>
        </div>

        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success">Qty per pack saved.</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-warning">Qty per pack deactivated.</div>
        <?php elseif ($seeded > 0): ?>
            <div class="alert alert-info"><?= number_format($seeded) ?> item(s) seeded from the June 2026 SUMMARY file.</div>
        <?php endif; ?>

        <div class="content-card">
            <div class="content-card-header">
                <h2 class="content-card-title"><?= $editRow ? 'Edit Qty/Pack' : 'Add Qty/Pack' ?></h2>
            </div>

            <div class="content-card-body">
                <form method="post" action="actions/pack_size_save.php" autocomplete="off">
                    <input type="hidden" name="original_item_code" value="<?= h($editRow['ItemCode'] ?? '') ?>">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="item_code">SAP Item Code</label>
                            <input class="form-control" id="item_code" name="item_code" required value="<?= h($editRow['ItemCode'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="part_name">Part Name</label>
                            <input class="form-control" id="part_name" name="part_name" value="<?= h($editRow['PartName'] ?? '') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label" for="qty_per_pack">Qty/Pack</label>
                            <input class="form-control" id="qty_per_pack" name="qty_per_pack" type="number" min="0.001" step="0.001" required value="<?= h($editRow['QtyPerPack'] ?? '') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label" for="source_name">Source</label>
                            <input class="form-control" id="source_name" name="source_name" value="<?= h($editRow['SourceName'] ?? 'June 2026 Excel SUMMARY') ?>">
                        </div>

                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= (int)($editRow['IsActive'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label small fw-bold" for="is_active">Active</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <?php if ($editRow): ?>
                                <a class="btn btn-outline-secondary" href="pages/warehouse/pack_sizes.php">Cancel</a>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit">Save Qty/Pack</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <form class="row g-2 align-items-end" method="get">
                    <div class="col-md-8">
                        <label class="form-label" for="q">Search</label>
                        <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="SAP item code, part name, or source">
                    </div>

                    <div class="col-md-2">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="inactive" name="inactive" value="1" <?= $showInactive ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="inactive">Show inactive</label>
                        </div>
                    </div>

                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary" type="submit">Filter</button>
                    </div>
                </form>
            </div>

            <div class="content-card-body">
                <div class="pack-table-wrap">
                    <table class="table table-hover align-middle pack-table">
                        <thead>
                            <tr>
                                <th class="col-code">SAP Item Code</th>
                                <th class="col-name">Part Name</th>
                                <th class="col-qty">Qty/Pack</th>
                                <th class="col-source">Source</th>
                                <th class="col-updated">Updated</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No qty per pack rows found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $updatedAt = $row['UpdatedAt'] ?? null;
                                    if ($updatedAt instanceof DateTimeInterface) {
                                        $updatedAt = $updatedAt->format('Y-m-d H:i:s');
                                    }
                                    ?>
                                    <tr class="<?= (int)($row['IsActive'] ?? 0) === 1 ? '' : 'table-secondary' ?>">
                                        <td class="col-code fw-bold" title="<?= h($row['ItemCode'] ?? '') ?>"><?= h($row['ItemCode'] ?? '') ?></td>
                                        <td class="col-name" title="<?= h($row['PartName'] ?? '') ?>">
                                            <?php if (trim((string)($row['PartName'] ?? '')) !== ''): ?>
                                                <?= h($row['PartName']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">No part name found</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-qty" title="<?= h($row['QtyPerPack'] ?? '') ?>"><?= h(number_format((float)($row['QtyPerPack'] ?? 0), 3, '.', ',')) ?></td>
                                        <td class="col-source" title="<?= h($row['SourceName'] ?? '') ?>"><?= h($row['SourceName'] ?? '') ?></td>
                                        <td class="col-updated" title="<?= h(($updatedAt ?: '') . ' ' . ($row['UpdatedByUsername'] ?? '')) ?>">
                                            <?= h($updatedAt ?: '') ?>
                                            <?php if (!empty($row['UpdatedByUsername'])): ?>
                                                <div class="small text-muted"><?= h($row['UpdatedByUsername']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-actions">
                                            <div class="d-flex gap-2">
                                                <a class="btn btn-sm btn-outline-primary" href="pages/warehouse/pack_sizes.php?edit=<?= urlencode((string)$row['ItemCode']) ?>">Edit</a>
                                                <?php if ((int)($row['IsActive'] ?? 0) === 1): ?>
                                                    <form method="post" action="actions/pack_size_delete.php" onsubmit="return confirm('Deactivate this qty per pack row?');">
                                                        <input type="hidden" name="item_code" value="<?= h($row['ItemCode'] ?? '') ?>">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="small text-muted mt-2">
                    Showing up to 500 row(s). Use search to narrow the list.
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
</script>
</body>
</html>
