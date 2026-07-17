<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

function picker_report_date_value($name, $default = '')
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function picker_report_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function picker_report_date_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function picker_report_qty($value)
{
    if ($value === null || $value === '') {
        return '';
    }

    $n = (float)$value;

    if (floor($n) == $n) {
        return number_format($n, 0);
    }

    return rtrim(rtrim(number_format($n, 3), '0'), '.');
}

function picker_report_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
        [$table]
    );
}

function picker_report_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

function picker_report_query($conn, $sql, $params = [])
{
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(sqlsrv_fail_message());
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function picker_report_excel_cell($value)
{
    return htmlspecialchars(picker_report_cell($value), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$dateFrom = picker_report_date_value('date_from', $today);
$dateTo = picker_report_date_value('date_to', $today);
$q = trim((string)($_GET['q'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? ''))) === 'excel';
$shouldRun = $export;           // PHP data fetch only needed for export
$run  = (string)($_GET['run']  ?? '') === '1'; // JS uses this to auto-trigger AJAX

$pageSize = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$rows = [];
$shownRows = 0;
$totalReceivedQty = 0.0;
$hasMoreRows = false;
$errorMessage = '';
$lotSourceLabel = $shouldRun ? 'IBT1 batch transactions' : 'Not loaded';

// Only cache small date ranges (≤ 7 days) to avoid full-table scans on wide ranges
$grpoRangeDays = ($shouldRun && !$export)
    ? (int)(new DateTime($dateFrom))->diff(new DateTime($dateTo))->days
    : 999;
$grpoCacheKey = ($grpoRangeDays <= 14)
    ? sap_cache_make_key('sap.picker.grpo_receipts', [
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'q'         => $q,
    ])
    : null;
$whp = ($grpoCacheKey !== null) ? get_whpokayoke_connection() : null;

try {
    if ($shouldRun) {
        $allRows      = null;
        $useFullFetch = !$export && $grpoCacheKey !== null; // set early so cache-hit path can use it

        if (!$export && $grpoCacheKey !== null && $whp !== null) {
            $cached = sap_cache_get_preferred($whp, $grpoCacheKey);

            if ($cached !== null && isset($cached['rows'])) {
                $allRows = $cached['rows'];
            }
        }

        if ($allRows === null) {
            if (!sap_cache_live_queries_enabled()) {
                throw new RuntimeException('Live SAP GRPO report queries are disabled for browser requests. Please use the cached GRPO report or wait for the scheduled SAP cache refresh.');
            }

            $erp = get_erp_connection();
            // Cacheable (small) range: fetch all rows at once for PHP pagination + caching.
            // Large range or export: use original SQL-level pagination to avoid timeouts.
            $fetchRows = $export ? 5000 : ($useFullFetch ? 2000 : ($pageSize + 1));
            $sqlOffset = $useFullFetch ? 0 : ($export ? 0 : $offset);
        $where = [
            'G.DocDate >= ?',
            'G.DocDate < DATEADD(day, 1, ?)',
            'GL.BaseType = 22',
            'GL.BaseEntry IS NOT NULL',
            'GL.BaseLine IS NOT NULL',
            "ISNULL(PO.CANCELED, 'N') = 'N'",
            "ISNULL(G.CANCELED, 'N') = 'N'"
        ];
        $params = [$dateFrom, $dateTo];

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_', '['], ['[%]', '[_]', '[[]'], $q) . '%';
            $where[] = "(
                CAST(PO.DocNum AS NVARCHAR(40)) LIKE ?
                OR CAST(G.DocNum AS NVARCHAR(40)) LIKE ?
                OR PO.CardCode LIKE ?
                OR PO.CardName LIKE ?
                OR GL.ItemCode LIKE ?
                OR COALESCE(NULLIF(GL.Dscription, ''), NULLIF(POL.Dscription, ''), '') LIKE ?
                OR POL.WhsCode LIKE ?
                OR GL.WhsCode LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        if (!$export && $q === '') {
            $rowSql = "
                WITH RecentGrpoLines AS (
                    SELECT
                        G.DocEntry AS GrpoDocEntry,
                        G.DocNum AS GrpoDocNum,
                        G.DocDate AS GrpoDocDate,
                        GL.LineNum AS GrpoLineNum,
                        GL.BaseEntry AS PoDocEntry,
                        GL.BaseLine AS PoLineNum,
                        GL.ItemCode,
                        GL.Dscription AS GrpoPartName,
                        GL.Quantity AS GrpoLineQty,
                        GL.WhsCode AS GrpoWarehouse,
                        GL.unitMsr AS GrpoUnitMsr,
                        GL.UomCode AS GrpoUomCode
                    FROM OPDN G WITH (NOLOCK)
                    INNER JOIN PDN1 GL WITH (NOLOCK) ON GL.DocEntry = G.DocEntry
                    WHERE G.DocDate >= ?
                      AND G.DocDate < DATEADD(day, 1, ?)
                      AND GL.BaseType = 22
                      AND GL.BaseEntry IS NOT NULL
                      AND GL.BaseLine IS NOT NULL
                      AND ISNULL(G.CANCELED, 'N') = 'N'
                    ORDER BY G.DocDate DESC, G.DocNum DESC, GL.LineNum ASC
                    OFFSET " . (int)$sqlOffset . " ROWS FETCH NEXT " . (int)$fetchRows . " ROWS ONLY
                )
                SELECT
                    PO.DocEntry AS PoDocEntry,
                    PO.DocNum AS PoDocNum,
                    PO.DocDate AS PoDocDate,
                    PO.CardCode AS VendorCode,
                    PO.CardName AS VendorName,
                    POL.LineNum AS PoLineNum,
                    POL.Quantity AS OrderedQty,
                    R.GrpoDocEntry,
                    R.GrpoDocNum,
                    R.GrpoDocDate,
                    R.GrpoLineNum,
                    R.ItemCode,
                    COALESCE(NULLIF(R.GrpoPartName, ''), NULLIF(POL.Dscription, ''), '') AS PartName,
                    R.GrpoLineQty,
                    POL.WhsCode AS PoWarehouse,
                    R.GrpoWarehouse,
                    COALESCE(NULLIF(R.GrpoUnitMsr, ''), NULLIF(R.GrpoUomCode, ''), NULLIF(POL.unitMsr, ''), NULLIF(POL.UomCode, ''), '') AS Uom
                FROM RecentGrpoLines R
                INNER JOIN OPOR PO WITH (NOLOCK) ON PO.DocEntry = R.PoDocEntry
                INNER JOIN POR1 POL WITH (NOLOCK) ON POL.DocEntry = R.PoDocEntry AND POL.LineNum = R.PoLineNum
                WHERE ISNULL(PO.CANCELED, 'N') = 'N'
                ORDER BY R.GrpoDocDate DESC, R.GrpoDocNum DESC, R.GrpoLineNum ASC
                OPTION (FAST " . (int)$pageSize . ")";
            $params = [$dateFrom, $dateTo];
        } else {
            $baseSelectSql = "
                SELECT
                    PO.DocEntry AS PoDocEntry,
                    PO.DocNum AS PoDocNum,
                    PO.DocDate AS PoDocDate,
                    PO.CardCode AS VendorCode,
                    PO.CardName AS VendorName,
                    POL.LineNum AS PoLineNum,
                    POL.Quantity AS OrderedQty,
                    G.DocEntry AS GrpoDocEntry,
                    G.DocNum AS GrpoDocNum,
                    G.DocDate AS GrpoDocDate,
                    GL.LineNum AS GrpoLineNum,
                    GL.ItemCode,
                    COALESCE(NULLIF(GL.Dscription, ''), NULLIF(POL.Dscription, ''), '') AS PartName,
                    GL.Quantity AS GrpoLineQty,
                    POL.WhsCode AS PoWarehouse,
                    GL.WhsCode AS GrpoWarehouse,
                    COALESCE(NULLIF(GL.unitMsr, ''), NULLIF(GL.UomCode, ''), NULLIF(POL.unitMsr, ''), NULLIF(POL.UomCode, ''), '') AS Uom
                FROM OPDN G WITH (NOLOCK)
                INNER JOIN PDN1 GL WITH (NOLOCK) ON GL.DocEntry = G.DocEntry
                INNER JOIN OPOR PO WITH (NOLOCK) ON PO.DocEntry = GL.BaseEntry
                INNER JOIN POR1 POL WITH (NOLOCK) ON POL.DocEntry = GL.BaseEntry AND POL.LineNum = GL.BaseLine
                WHERE {$whereSql}
            ";
            $rowSql = $baseSelectSql . "
                    ORDER BY G.DocDate DESC, G.DocNum DESC, GL.LineNum ASC
                    OFFSET " . (int)$sqlOffset . " ROWS FETCH NEXT " . (int)$fetchRows . " ROWS ONLY
                    OPTION (FAST " . (int)$pageSize . ")";
        }

        $baseRows = picker_report_query($erp, $rowSql, $params);

        // For large-range / SQL-paginated paths, trim the sentinel row and set hasMoreRows
        if (!$useFullFetch && !$export && count($baseRows) > $pageSize) {
            $hasMoreRows = true;
            $baseRows    = array_slice($baseRows, 0, $pageSize);
        }

        $lotRowsByKey = [];

        for ($start = 0; $start < count($baseRows); $start += 500) {
            $chunk = array_slice($baseRows, $start, 500);
            $lotWhere = [];
            $lotParams = [];

            foreach ($chunk as $row) {
                $grpoDocEntry = (int)($row['GrpoDocEntry'] ?? 0);
                $grpoLineNum = (int)($row['GrpoLineNum'] ?? -1);
                $itemCode = trim((string)($row['ItemCode'] ?? ''));

                if ($grpoDocEntry <= 0 || $grpoLineNum < 0 || $itemCode === '') {
                    continue;
                }

                $lotWhere[] = '(BaseEntry = ? AND BaseLinNum = ? AND ItemCode = ?)';
                array_push($lotParams, $grpoDocEntry, $grpoLineNum, $itemCode);
            }

            if ($lotWhere === []) {
                continue;
            }

            $lotRows = picker_report_query(
                $erp,
                "SELECT
                    BaseEntry,
                    BaseLinNum,
                    ItemCode,
                    BatchNum,
                    ABS(ISNULL(Quantity, 0)) AS Quantity
                 FROM IBT1 WITH (NOLOCK)
                 WHERE BaseType = 20
                   AND (" . implode(' OR ', $lotWhere) . ")
                 ORDER BY BaseEntry DESC, BaseLinNum ASC, BatchNum ASC",
                $lotParams
            );

            foreach ($lotRows as $lotRow) {
                $key = (int)($lotRow['BaseEntry'] ?? 0) . '|' .
                    (int)($lotRow['BaseLinNum'] ?? -1) . '|' .
                    strtoupper(trim((string)($lotRow['ItemCode'] ?? '')));

                if (!isset($lotRowsByKey[$key])) {
                    $lotRowsByKey[$key] = [];
                }

                $lotRowsByKey[$key][] = $lotRow;
            }
        }

        $allRows = [];

        foreach ($baseRows as $baseRow) {
            foreach ($baseRow as $k => $v) {
                if ($v instanceof DateTimeInterface) {
                    $baseRow[$k] = $v->format('Y-m-d');
                }
            }

            $key = (int)($baseRow['GrpoDocEntry'] ?? 0) . '|' .
                (int)($baseRow['GrpoLineNum'] ?? -1) . '|' .
                strtoupper(trim((string)($baseRow['ItemCode'] ?? '')));
            $lineLots = $lotRowsByKey[$key] ?? [];

            if ($lineLots === []) {
                $baseRow['ReceivedQty'] = $baseRow['GrpoLineQty'] ?? 0;
                $baseRow['LotNo'] = '';
                $allRows[] = $baseRow;
                continue;
            }

            foreach ($lineLots as $lotRow) {
                $reportRow = $baseRow;
                $reportRow['ReceivedQty'] = $lotRow['Quantity'] ?? 0;
                $reportRow['LotNo'] = trim((string)($lotRow['BatchNum'] ?? ''));
                $allRows[] = $reportRow;
            }
        }

        if ($useFullFetch && $grpoCacheKey !== null && $whp !== null) {
            sap_cache_put($whp, 'sap.picker.grpo_receipts', $grpoCacheKey, [
                'ok'   => true,
                'rows' => $allRows,
            ], 600);
        }
        } // end: if ($allRows === null)

        // PHP pagination for full-fetch (small range) path; SQL already paginated for wide ranges
        if ($useFullFetch ?? false) {
            $sliced = array_slice($allRows, $offset, $pageSize + 1);

            if (count($sliced) > $pageSize) {
                $hasMoreRows = true;
                $sliced      = array_slice($sliced, 0, $pageSize);
            }

            $rows = $sliced;
        } else {
            $rows = $allRows; // SQL already applied pagination or full export
        }

        $shownRows = count($rows);

        foreach ($rows as $reportRow) {
            $totalReceivedQty += (float)($reportRow['ReceivedQty'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

if ($export) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=picker_grpo_lot_report_' . date('Ymd_His') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <table border="1">
        <thead>
            <tr>
                <th>GRPO Date</th>
                <th>GRPO No</th>
                <th>PO No</th>
                <th>Vendor Code</th>
                <th>Vendor Name</th>
                <th>Item Code</th>
                <th>Part Name</th>
                <th>Lot No</th>
                <th>Received Qty</th>
                <th>GRPO Line Qty</th>
                <th>Ordered Qty</th>
                <th>UOM</th>
                <th>PO WH</th>
                <th>GRPO WH</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= picker_report_excel_cell(picker_report_date_cell($r['GrpoDocDate'] ?? '')) ?></td>
                    <td><?= picker_report_excel_cell($r['GrpoDocNum'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['PoDocNum'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['VendorCode'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['VendorName'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['ItemCode'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['PartName'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['LotNo'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell(picker_report_qty($r['ReceivedQty'] ?? '')) ?></td>
                    <td><?= picker_report_excel_cell(picker_report_qty($r['GrpoLineQty'] ?? '')) ?></td>
                    <td><?= picker_report_excel_cell(picker_report_qty($r['OrderedQty'] ?? '')) ?></td>
                    <td><?= picker_report_excel_cell($r['Uom'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['PoWarehouse'] ?? '') ?></td>
                    <td><?= picker_report_excel_cell($r['GrpoWarehouse'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    exit;
}

$queryBase = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => $q,
    'run' => '1'
];
$prevUrl = app_path('pages/picker/picker_report.php?' . http_build_query(array_merge($queryBase, ['page' => max(1, $page - 1)])));
$nextUrl = app_path('pages/picker/picker_report.php?' . http_build_query(array_merge($queryBase, ['page' => $page + 1])));
$exportUrl = app_path('api/picker/export_grpo_receipts.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => $q,
]));
?>
<!doctype html>
<html lang="en">
<head>
    <title>Picker Receiving Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">
    <link href="assets/app-shell.css" rel="stylesheet">
    <style>
        :root {
            --topbar-height: 3.5rem;
            --sidebar-rail-width: 68px;
            --sidebar-full-width: 250px;
            --page-bg: #f3f6fa;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --line: #dbe4ee;
            --line-strong: #c8d4e1;
            --text: #142033;
            --muted: #66758a;
            --brand: #0a6ed1;
            --brand-dark: #075aa9;
            --success: #15803d;
            --warning: #b45309;
            --danger: #b42318;
            --shadow: 0 12px 30px rgba(15, 23, 42, .07);
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        .app-layout { display: flex; min-height: 100vh; padding-top: var(--topbar-height); }
        .main-content {
            width: calc(100% - var(--sidebar-rail-width));
            margin-left: var(--sidebar-rail-width);
            min-height: calc(100vh - var(--topbar-height));
            padding: 18px;
        }

        .sap-shellbar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 1040;
            min-height: var(--topbar-height);
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 18px;
            color: #fff;
            background: linear-gradient(90deg, #20364a 0%, #31516e 100%);
            box-shadow: 0 4px 18px rgba(15, 23, 42, .2);
        }
        .shell-menu-btn {
            display: none;
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 10px;
            color: #fff;
            background: rgba(255,255,255,.13);
            font-size: 22px;
            cursor: pointer;
        }
        .shell-logo {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            padding: 3px;
            overflow: hidden;
            border-radius: 9px;
            background: #fff;
        }
        .shell-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .shell-title-wrap { min-width: 0; flex: 1; }
        .shell-title { font-size: 15px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .shell-subtitle { margin-top: 2px; color: rgba(255,255,255,.78); font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .sidebar, .sap-side-nav {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            z-index: 1035;
            width: var(--sidebar-rail-width);
            overflow-x: hidden;
            background: #fff;
            border-right: 1px solid var(--line);
            box-shadow: 4px 0 14px rgba(15, 23, 42, .06);
            transition: width .18s ease, transform .2s ease, box-shadow .18s ease;
        }
        body.sidebar-rail-mode .sidebar:hover,
        body.sidebar-rail-mode .sidebar:focus-within,
        body.sidebar-rail-mode .sap-side-nav:hover,
        body.sidebar-rail-mode .sap-side-nav:focus-within {
            z-index: 1050;
            width: var(--sidebar-full-width);
            box-shadow: 12px 0 34px rgba(15, 23, 42, .2);
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
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .logout-link { display: none !important; }
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-link,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-link {
            width: 48px !important;
            height: 48px !important;
            justify-content: center !important;
            gap: 0 !important;
            margin: 0 auto 8px !important;
            padding: 0 !important;
            font-size: 0 !important;
        }
        body.sidebar-rail-mode .sidebar:not(:hover):not(:focus-within) .sidebar-icon,
        body.sidebar-rail-mode .sap-side-nav:not(:hover):not(:focus-within) .sidebar-icon {
            width: 24px !important;
            min-width: 24px !important;
            font-size: 18px !important;
        }
        .sidebar-backdrop { display: none; position: fixed; inset: var(--topbar-height) 0 0; z-index: 1030; background: rgba(15,23,42,.46); }

        .report-page { width: min(100%, 1780px); margin: 0 auto; }
        .page-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }
        .page-heading { min-width: 0; }
        .eyebrow { margin-bottom: 4px; color: var(--brand); font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .page-title { margin: 0; font-size: clamp(24px, 2.2vw, 32px); line-height: 1.1; font-weight: 850; letter-spacing: -.025em; }
        .page-subtitle { margin-top: 6px; max-width: 850px; color: var(--muted); font-size: 13px; line-height: 1.45; }
        .toolbar-actions { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }

        .btn {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 14px;
            border: 1px solid var(--line-strong);
            border-radius: 9px;
            background: #fff;
            color: #27364a;
            font: inherit;
            font-size: 13px;
            font-weight: 750;
            line-height: 1;
            text-decoration: none;
            cursor: pointer;
            transition: transform .12s ease, background .12s ease, border-color .12s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { color: #fff; border-color: var(--brand); background: var(--brand); }
        .btn-primary:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
        .btn-success { color: #fff; border-color: var(--success); background: var(--success); }
        .btn-light { background: #fff; }
        .btn.disabled, .btn:disabled { opacity: .48; pointer-events: none; transform: none; }

        .report-shell {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        .filter-panel {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
        }
        .filter-row {
            display: grid;
            grid-template-columns: 145px 145px minmax(230px, 1fr) auto;
            gap: 10px;
            align-items: end;
        }
        .field { min-width: 0; }
        .field-label { display: block; margin-bottom: 5px; color: var(--muted); font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .form-control {
            width: 100%;
            min-height: 40px;
            padding: 8px 11px;
            border: 1px solid var(--line-strong);
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: 13px;
        }
        .form-control:focus { border-color: #78afe4; box-shadow: 0 0 0 3px rgba(10,110,209,.1); }
        .search-wrap { position: relative; }
        .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #8593a6; pointer-events: none; }
        .search-wrap .form-control { padding-left: 34px; }
        .filter-actions { display: flex; gap: 7px; }
        .preset-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .preset-label { margin-right: 2px; color: var(--muted); font-size: 11px; font-weight: 700; }
        .preset-btn {
            min-height: 30px;
            padding: 5px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface-soft);
            color: #40516a;
            font-size: 11px;
            font-weight: 750;
            cursor: pointer;
        }
        .preset-btn:hover { border-color: #9fc7ec; color: var(--brand); background: #eef7ff; }

        .summary-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-bottom: 1px solid var(--line);
            background: var(--surface-soft);
        }
        .metric {
            min-width: 0;
            padding: 13px 16px;
            border-right: 1px solid var(--line);
        }
        .metric:last-child { border-right: 0; }
        .metric-label { color: var(--muted); font-size: 10px; font-weight: 800; letter-spacing: .055em; text-transform: uppercase; }
        .metric-value { margin-top: 4px; overflow: hidden; color: #122038; font-size: 19px; font-weight: 850; text-overflow: ellipsis; white-space: nowrap; }
        .metric-value.small-value { font-size: 13px; line-height: 1.55; }

        .table-section { padding: 0 16px 14px; }
        .table-toolbar {
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .table-title { font-size: 14px; font-weight: 800; }
        .table-meta { color: var(--muted); font-size: 11px; text-align: right; }
        .alert {
            margin: 14px 16px 0;
            padding: 11px 13px;
            border: 1px solid #f4b8b3;
            border-radius: 10px;
            background: #fff3f2;
            color: var(--danger);
            font-size: 13px;
        }
        .table-wrap {
            max-height: calc(100vh - 330px);
            min-height: 300px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: #fff;
        }
        .report-table { width: 100%; min-width: 1240px; border-collapse: separate; border-spacing: 0; font-size: 12px; }
        .report-table th, .report-table td { padding: 9px 10px; border-bottom: 1px solid #e8edf3; vertical-align: middle; }
        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 4;
            color: #475569;
            background: #f1f5f9;
            box-shadow: inset 0 -1px 0 var(--line-strong);
            font-size: 10px;
            font-weight: 850;
            letter-spacing: .045em;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .report-table tbody tr:nth-child(even) { background: #fbfcfe; }
        .report-table tbody tr:hover { background: #edf6ff; }
        .report-table tbody tr:last-child td { border-bottom: 0; }
        .report-table td { color: #243247; white-space: nowrap; }
        .report-table .part-cell { min-width: 250px; max-width: 360px; white-space: normal; line-height: 1.35; }
        .doc-no { color: #0b5cad; font-weight: 800; }
        .item-code { font-weight: 800; }
        .vendor-name { max-width: 180px; overflow: hidden; color: var(--muted); font-size: 11px; text-overflow: ellipsis; }
        .number-cell { text-align: right !important; font-variant-numeric: tabular-nums; }
        .lot-pill {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 8px;
            border: 1px solid #b7d9f7;
            border-radius: 999px;
            background: #edf7ff;
            color: #075985;
            font-size: 11px;
            font-weight: 800;
        }
        .empty-lot { color: #94a3b8; font-style: italic; }
        .warehouse-pill { display: inline-flex; padding: 3px 7px; border-radius: 6px; background: #f1f5f9; color: #475569; font-weight: 750; }
        .empty-state { padding: 58px 20px !important; color: var(--muted) !important; text-align: center; }
        .empty-state strong { display: block; margin-bottom: 5px; color: #334155; font-size: 14px; }
        .loading-line { width: 170px; height: 4px; margin: 12px auto 0; overflow: hidden; border-radius: 99px; background: #e2e8f0; }
        .loading-line::after { content: ""; display: block; width: 45%; height: 100%; border-radius: inherit; background: var(--brand); animation: slide 1s infinite ease-in-out; }
        @keyframes slide { from { transform: translateX(-110%); } to { transform: translateX(260%); } }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 12px;
        }
        .pagination-info { color: var(--muted); font-size: 12px; }
        .pagination-controls { display: flex; align-items: center; gap: 6px; }
        .page-btn {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            background: #fff;
            color: #334155;
            font-size: 12px;
            font-weight: 750;
            cursor: pointer;
        }
        .page-btn:hover:not(.disabled) { border-color: #8bbbe7; background: #eef7ff; color: var(--brand); }
        .page-btn.disabled { opacity: .42; pointer-events: none; }
        .page-current { min-width: 82px; border-color: var(--brand); color: #fff; background: var(--brand); }

        @media (max-width: 1050px) {
            .filter-row { grid-template-columns: 1fr 1fr; }
            .search-field { grid-column: 1 / -1; }
            .filter-actions { grid-column: 1 / -1; justify-content: flex-end; }
            .summary-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .metric:nth-child(2) { border-right: 0; }
            .metric:nth-child(-n+2) { border-bottom: 1px solid var(--line); }
        }

        @media (max-width: 767px) {
            .main-content { width: 100%; margin-left: 0; padding: 11px; }
            .shell-menu-btn { display: inline-grid; place-items: center; }
            .sidebar, .sap-side-nav { width: var(--sidebar-full-width); transform: translateX(-100%); }
            .sidebar.show, .sap-side-nav.show { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .page-toolbar { align-items: flex-start; }
            .page-subtitle { display: none; }
            .toolbar-actions .btn span { display: none; }
            .filter-panel { padding: 12px; }
            .filter-row { grid-template-columns: 1fr; }
            .search-field, .filter-actions { grid-column: auto; }
            .filter-actions { justify-content: stretch; }
            .filter-actions .btn { flex: 1; }
            .summary-strip { grid-template-columns: 1fr 1fr; }
            .metric { padding: 11px 12px; }
            .metric-value { font-size: 16px; }
            .table-section { padding: 0 10px 10px; }
            .table-toolbar { align-items: flex-start; padding: 8px 0; }
            .table-wrap { max-height: none; min-height: 260px; overflow: visible; border: 0; background: transparent; }
            .report-table { min-width: 0; display: block; }
            .report-table thead { display: none; }
            .report-table tbody { display: grid; gap: 9px; }
            .report-table tr { display: grid; grid-template-columns: 1fr 1fr; gap: 0; overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #fff !important; box-shadow: 0 4px 12px rgba(15,23,42,.04); }
            .report-table td { display: block; min-width: 0; padding: 8px 10px; border-bottom: 1px solid #edf1f5; white-space: normal; }
            .report-table td::before { content: attr(data-label); display: block; margin-bottom: 3px; color: var(--muted); font-size: 9px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
            .report-table .part-cell { grid-column: 1 / -1; min-width: 0; max-width: none; }
            .report-table td:nth-last-child(-n+2) { border-bottom: 0; }
            .number-cell { text-align: left !important; }
            .empty-state { grid-column: 1 / -1; }
            .empty-state::before { display: none !important; }
            .pagination-bar { align-items: stretch; flex-direction: column; }
            .pagination-controls { justify-content: space-between; }
            .page-btn { flex: 1; }
        }
    </style>
</head>
<body class="sidebar-rail-mode">
<header class="sap-shellbar">
    <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">&#9776;</button>
    <div class="shell-logo" aria-hidden="true"><img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo"></div>
    <div class="shell-title-wrap">
        <div class="shell-title">NBC Rawmats Traceability</div>
        <div class="shell-subtitle">Picker receiving and GRPO lot monitoring</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="app-layout">
    <?php app_sidebar('picker_report'); ?>

    <main class="main-content">
        <div class="report-page">
            <div class="page-toolbar">
                <div class="page-heading">
                    <div class="eyebrow">Picker Report</div>
                    <h1 class="page-title">GRPO Receiving Report</h1>
                    <div class="page-subtitle">Review purchase-order receipts, SAP batch or lot numbers, quantities, suppliers, and destination warehouses in one compact report.</div>
                </div>
                <div class="toolbar-actions">
                    <a class="btn btn-light" href="<?= h(app_path('pages/picker/picker.php')) ?>">&#8592; <span>Back to Picker</span></a>
                    <a class="btn btn-success disabled" id="excel-btn" href="<?= h($exportUrl) ?>">&#8681; <span>Export Excel</span></a>
                </div>
            </div>

            <section class="report-shell">
                <div class="filter-panel">
                    <form id="report-form" method="get">
                        <input type="hidden" name="run" value="1">
                        <div class="filter-row">
                            <div class="field">
                                <label class="field-label" for="date_from">Date From</label>
                                <input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($dateFrom) ?>">
                            </div>
                            <div class="field">
                                <label class="field-label" for="date_to">Date To</label>
                                <input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($dateTo) ?>">
                            </div>
                            <div class="field search-field">
                                <label class="field-label" for="q">Search</label>
                                <div class="search-wrap">
                                    <span class="search-icon">&#128269;</span>
                                    <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="PO, GRPO, vendor, item, part name, warehouse">
                                </div>
                            </div>
                            <div class="filter-actions">
                                <button class="btn btn-primary" type="submit">Apply Filter</button>
                                <button class="btn btn-light" type="button" id="reset-btn">Reset</button>
                            </div>
                        </div>
                        <div class="preset-row">
                            <span class="preset-label">Quick date:</span>
                            <button type="button" class="preset-btn" data-preset="today">Today</button>
                            <button type="button" class="preset-btn" data-preset="yesterday">Yesterday</button>
                            <button type="button" class="preset-btn" data-preset="7d">Last 7 days</button>
                            <button type="button" class="preset-btn" data-preset="14d">Last 14 days</button>
                            <button type="button" class="preset-btn" data-preset="30d">Last 30 days</button>
                        </div>
                    </form>
                </div>

                <div class="summary-strip">
                    <div class="metric">
                        <div class="metric-label">Rows on Page</div>
                        <div class="metric-value" id="stat-rows">&mdash;</div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Total Matching Rows</div>
                        <div class="metric-value" id="stat-total">&mdash;</div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Received Qty on Page</div>
                        <div class="metric-value" id="stat-qty">&mdash;</div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Data Status</div>
                        <div class="metric-value small-value" id="stat-source"><?= $run ? 'Loading&hellip;' : 'Ready to load' ?></div>
                    </div>
                </div>

                <div id="report-error" class="alert" style="display:none;"></div>

                <div class="table-section">
                    <div class="table-toolbar">
                        <div class="table-title">GRPO Receipt Details</div>
                        <div class="table-meta" id="cache-badge">Select a date range and apply the filter.</div>
                    </div>
                    <div class="table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>GRPO Date</th>
                                    <th>GRPO No.</th>
                                    <th>PO No.</th>
                                    <th>Vendor</th>
                                    <th>Item Code</th>
                                    <th>Part Name</th>
                                    <th>Lot No.</th>
                                    <th class="number-cell">Received</th>
                                    <th class="number-cell">GRPO Qty</th>
                                    <th class="number-cell">Ordered</th>
                                    <th>UOM</th>
                                    <th>PO WH</th>
                                    <th>GRPO WH</th>
                                </tr>
                            </thead>
                            <tbody id="report-tbody">
                                <tr><td colspan="13" class="empty-state">
                                    <strong><?= $run ? 'Loading report...' : 'No report loaded' ?></strong>
                                    <?= $run ? 'Retrieving GRPO receipt records from SAP.' : 'Set your date range, then click Apply Filter.' ?>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-bar">
                        <div class="pagination-info" id="rows-info">&nbsp;</div>
                        <div class="pagination-controls">
                            <button class="page-btn disabled" type="button" id="pag-first">&#171; First</button>
                            <button class="page-btn disabled" type="button" id="pag-prev">&#8249; Prev</button>
                            <button class="page-btn page-current" type="button" id="pag-cur">Page <?= (int)$page ?></button>
                            <button class="page-btn disabled" type="button" id="pag-next">Next &#8250;</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
(function () {
    'use strict';

    const API = <?= json_encode(app_path('api/picker/open_grpo_receipts.php')) ?>;
    const SELF = <?= json_encode(app_path('pages/picker/picker_report.php')) ?>;
    const EXPORT_API = <?= json_encode(app_path('api/picker/export_grpo_receipts.php')) ?>;
    const PAGE_SIZE = 25;

    let currentPage = <?= (int)$page ?>;
    const browserPageCache = new Map();
    let activeRequestController = null;
    let currentParams = {
        date_from: <?= json_encode($dateFrom) ?>,
        date_to: <?= json_encode($dateTo) ?>,
        q: <?= json_encode($q) ?>
    };

    const el = {
        tbody: document.getElementById('report-tbody'),
        statRows: document.getElementById('stat-rows'),
        statTotal: document.getElementById('stat-total'),
        statQty: document.getElementById('stat-qty'),
        statSource: document.getElementById('stat-source'),
        badge: document.getElementById('cache-badge'),
        error: document.getElementById('report-error'),
        rowsInfo: document.getElementById('rows-info'),
        first: document.getElementById('pag-first'),
        prev: document.getElementById('pag-prev'),
        current: document.getElementById('pag-cur'),
        next: document.getElementById('pag-next'),
        excel: document.getElementById('excel-btn')
    };

    function h(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) { return String(value ?? '').slice(0, 10); }
    function formatQty(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        return Math.abs(n - Math.round(n)) < 0.0005
            ? Math.round(n).toLocaleString()
            : n.toLocaleString(undefined, { maximumFractionDigits: 3 });
    }
    function formatInputDate(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }
    function exportUrl() {
        return EXPORT_API + '?' + new URLSearchParams(currentParams).toString();
    }

    function rowHtml(r) {
        const lot = String(r.LotNo ?? '').trim();
        return `<tr>
            <td data-label="GRPO Date">${h(formatDate(r.GrpoDocDate))}</td>
            <td data-label="GRPO No."><span class="doc-no">${h(r.GrpoDocNum)}</span></td>
            <td data-label="PO No."><span class="doc-no">${h(r.PoDocNum)}</span></td>
            <td data-label="Vendor" title="${h((r.VendorCode ?? '') + ' ' + (r.VendorName ?? ''))}">
                <div><strong>${h(r.VendorCode)}</strong></div>
                <div class="vendor-name">${h(r.VendorName)}</div>
            </td>
            <td data-label="Item Code"><span class="item-code">${h(r.ItemCode)}</span></td>
            <td data-label="Part Name" class="part-cell">${h(r.PartName)}</td>
            <td data-label="Lot No.">${lot ? `<span class="lot-pill">${h(lot)}</span>` : '<span class="empty-lot">No SAP lot</span>'}</td>
            <td data-label="Received" class="number-cell"><strong>${h(formatQty(r.ReceivedQty))}</strong></td>
            <td data-label="GRPO Qty" class="number-cell">${h(formatQty(r.GrpoLineQty))}</td>
            <td data-label="Ordered" class="number-cell">${h(formatQty(r.OrderedQty))}</td>
            <td data-label="UOM">${h(r.Uom)}</td>
            <td data-label="PO WH"><span class="warehouse-pill">${h(r.PoWarehouse)}</span></td>
            <td data-label="GRPO WH"><span class="warehouse-pill">${h(r.GrpoWarehouse)}</span></td>
        </tr>`;
    }

    function showLoading() {
        el.tbody.innerHTML = `<tr><td colspan="13" class="empty-state"><strong>Loading GRPO records</strong>Please wait while SAP receipt and lot data is being prepared.<div class="loading-line"></div></td></tr>`;
        el.statRows.textContent = '—';
        el.statTotal.textContent = '—';
        el.statQty.textContent = '—';
        el.statSource.textContent = 'Loading...';
        el.badge.textContent = 'Retrieving data...';
        el.rowsInfo.textContent = 'Loading...';
        el.error.style.display = 'none';
    }

    function setButton(button, page, enabled) {
        button.dataset.page = String(page);
        button.classList.toggle('disabled', !enabled);
    }

    function updatePagination(page, totalRows, hasMore) {
        const start = totalRows > 0 ? ((page - 1) * PAGE_SIZE) + 1 : 0;
        const end = Math.min(page * PAGE_SIZE, totalRows);
        el.current.textContent = 'Page ' + page;
        el.rowsInfo.textContent = totalRows > 0
            ? `Showing ${start.toLocaleString()}-${end.toLocaleString()} of ${Number(totalRows).toLocaleString()} rows`
            : 'No matching rows';
        setButton(el.first, 1, page > 1);
        setButton(el.prev, page - 1, page > 1);
        setButton(el.next, page + 1, hasMore);
    }

    function pageCacheKey(page) {
        return JSON.stringify({
            date_from: currentParams.date_from,
            date_to: currentParams.date_to,
            q: currentParams.q,
            page: Number(page)
        });
    }

    function renderPageData(data) {
        const rows = Array.isArray(data.rows) ? data.rows : [];
        el.tbody.innerHTML = rows.length
            ? rows.map(rowHtml).join('')
            : '<tr><td colspan="13" class="empty-state"><strong>No matching GRPO records</strong>Try another date range or clear the search keyword.</td></tr>';

        el.statRows.textContent = rows.length.toLocaleString();
        el.statTotal.textContent = Number(data.total_rows || 0).toLocaleString();
        el.statQty.textContent = formatQty(data.total_qty || 0);
        el.statSource.textContent = data.from_cache ? 'Cached SAP data' : 'Live SAP query';
        el.badge.textContent = `${data.date_from} to ${data.date_to}` + (data.from_cache ? ' • cached result' : ' • refreshed result');
        updatePagination(Number(data.page || currentPage), Number(data.total_rows || 0), Boolean(data.has_more));
        el.excel.href = exportUrl();
        el.excel.classList.remove('disabled');
        el.error.style.display = 'none';
    }

    async function fetchPageData(page, signal) {
        const response = await fetch(API + '?' + new URLSearchParams({ ...currentParams, page }).toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok) {
            throw new Error(data?.message || ('Request failed with HTTP ' + response.status));
        }
        browserPageCache.set(pageCacheKey(page), data);
        return data;
    }

    function prefetchPage(page) {
        if (page < 1) return;
        const key = pageCacheKey(page);
        if (browserPageCache.has(key)) return;
        fetchPageData(page).catch(() => {});
    }

    async function loadPage(page, pushHistory = true) {
        currentPage = Math.max(1, Number(page) || 1);
        if (pushHistory) {
            history.pushState({ page: currentPage }, '', '?' + new URLSearchParams({ ...currentParams, run: '1', page: currentPage }).toString());
        }

        const key = pageCacheKey(currentPage);
        const cachedPage = browserPageCache.get(key);
        if (cachedPage) {
            renderPageData(cachedPage);
            prefetchPage(currentPage + 1);
            if (currentPage > 1) prefetchPage(currentPage - 1);
            return;
        }

        if (activeRequestController) activeRequestController.abort();
        activeRequestController = new AbortController();
        showLoading();

        try {
            const data = await fetchPageData(currentPage, activeRequestController.signal);
            renderPageData(data);
            if (data.has_more) prefetchPage(currentPage + 1);
            if (currentPage > 1) prefetchPage(currentPage - 1);
        } catch (error) {
            if (error.name === 'AbortError') return;
            el.tbody.innerHTML = '<tr><td colspan="13" class="empty-state"><strong>Unable to load the report</strong>Review the error message shown above, then try again.</td></tr>';
            el.error.textContent = 'Failed to load data: ' + error.message;
            el.error.style.display = '';
            el.statRows.textContent = '0';
            el.statTotal.textContent = '0';
            el.statQty.textContent = '0';
            el.statSource.textContent = 'Load failed';
            el.badge.textContent = 'No data loaded';
            updatePagination(currentPage, 0, false);
            el.excel.classList.add('disabled');
        } finally {
            activeRequestController = null;
        }
    }

    document.getElementById('report-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        if (!dateFrom || !dateTo) {
            el.error.textContent = 'Both Date From and Date To are required.';
            el.error.style.display = '';
            return;
        }
        if (dateFrom > dateTo) {
            el.error.textContent = 'Date From cannot be later than Date To.';
            el.error.style.display = '';
            return;
        }
        browserPageCache.clear();
        currentParams = {
            date_from: dateFrom,
            date_to: dateTo,
            q: document.getElementById('q').value.trim()
        };
        loadPage(1);
    });

    document.getElementById('reset-btn').addEventListener('click', function () {
        const today = new Date();
        document.getElementById('date_from').value = formatInputDate(today);
        document.getElementById('date_to').value = formatInputDate(today);
        document.getElementById('q').value = '';
        browserPageCache.clear();
        currentParams = { date_from: formatInputDate(today), date_to: formatInputDate(today), q: '' };
        loadPage(1);
    });

    document.querySelectorAll('[data-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const start = new Date(today);
            switch (button.dataset.preset) {
                case 'yesterday': start.setDate(start.getDate() - 1); today.setDate(today.getDate() - 1); break;
                case '7d': start.setDate(start.getDate() - 6); break;
                case '14d': start.setDate(start.getDate() - 13); break;
                case '30d': start.setDate(start.getDate() - 29); break;
            }
            document.getElementById('date_from').value = formatInputDate(start);
            document.getElementById('date_to').value = formatInputDate(today);
        });
    });

    [el.first, el.prev, el.next].forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.classList.contains('disabled')) return;
            loadPage(Number(button.dataset.page || 1));
        });
    });

    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebarToggle = document.getElementById('sidebarToggle');
    function closeSidebar() {
        sidebar?.classList.remove('show');
        sidebarBackdrop?.classList.remove('show');
    }
    sidebarToggle?.addEventListener('click', function () {
        sidebar?.classList.toggle('show');
        sidebarBackdrop?.classList.toggle('show');
    });
    sidebarBackdrop?.addEventListener('click', closeSidebar);

    window.addEventListener('popstate', function () {
        const url = new URL(window.location.href);
        currentParams = {
            date_from: url.searchParams.get('date_from') || currentParams.date_from,
            date_to: url.searchParams.get('date_to') || currentParams.date_to,
            q: url.searchParams.get('q') || ''
        };
        document.getElementById('date_from').value = currentParams.date_from;
        document.getElementById('date_to').value = currentParams.date_to;
        document.getElementById('q').value = currentParams.q;
        loadPage(Number(url.searchParams.get('page') || 1), false);
    });

    loadPage(<?= (int)$page ?>, false);
}());
</script>
</body>
</html>
