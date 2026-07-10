<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_role([ROLE_PICKER, ROLE_ADMIN]);

function export_date(string $name, string $default): string
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function export_cell($value): string
{
    if ($value instanceof DateTimeInterface) {
        $value = $value->format('Y-m-d H:i:s');
    }

    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function export_qty($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $number = (float)$value;
    if (floor($number) === $number) {
        return number_format($number, 0, '.', '');
    }

    return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
}

function export_sql_error(string $prefix): string
{
    return $prefix . ': ' . print_r(sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [], true);
}

$today = date('Y-m-d');
$dateFrom = export_date('date_from', $today);
$dateTo = export_date('date_to', $dateFrom);
$q = trim((string)($_GET['q'] ?? ''));

if ($dateFrom > $dateTo) {
    http_response_code(422);
    exit('Date From cannot be later than Date To.');
}

$conn = get_whpokayoke_connection();

$where = [
    'GrpoDocDate >= ?',
    'GrpoDocDate < DATEADD(day, 1, ?)',
];
$params = [$dateFrom, $dateTo];

if ($q !== '') {
    $escaped = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q);
    $like = '%' . $escaped . '%';
    $where[] = "(
        CONVERT(NVARCHAR(40), PoDocNum) LIKE ?
        OR CONVERT(NVARCHAR(40), GrpoDocNum) LIKE ?
        OR VendorCode LIKE ?
        OR VendorName LIKE ?
        OR ItemCode LIKE ?
        OR PartName LIKE ?
        OR LotNo LIKE ?
        OR PoWarehouse LIKE ?
        OR GrpoWarehouse LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);
$stmt = sqlsrv_query(
    $conn,
    "SELECT
        CONVERT(VARCHAR(10), GrpoDocDate, 23) AS GrpoDocDate,
        GrpoDocNum,
        CONVERT(VARCHAR(10), PoDocDate, 23) AS PoDocDate,
        PoDocNum,
        VendorCode,
        VendorName,
        ItemCode,
        PartName,
        LotNo,
        ReceivedQty,
        GrpoLineQty,
        OrderedQty,
        Uom,
        PoWarehouse,
        GrpoWarehouse,
        CONVERT(VARCHAR(19), SyncedAt, 120) AS SyncedAt
     FROM dbo.PickerGrpoReceiptCache WITH (NOLOCK)
     WHERE {$whereSql}
     ORDER BY GrpoDocDate DESC, GrpoDocNum DESC, GrpoLineNum ASC, LotNo ASC",
    $params,
    ['QueryTimeout' => 60]
);

if ($stmt === false) {
    http_response_code(500);
    exit(export_sql_error('Unable to export cached GRPO records'));
}

$filename = 'picker_grpo_receiving_' . str_replace('-', '', $dateFrom) . '_to_' . str_replace('-', '', $dateTo) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF";
?>
<!doctype html>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 10pt; }
        th { background: #d9eaf7; font-weight: bold; text-align: center; }
        th, td { border: 1px solid #7f8c8d; padding: 5px 7px; vertical-align: middle; }
        .text { mso-number-format: "\@"; }
        .number { mso-number-format: "0.######"; text-align: right; }
        .date { mso-number-format: "yyyy-mm-dd"; }
    </style>
</head>
<body>
<table>
    <tr>
        <th colspan="16">Picker GRPO Receiving Report</th>
    </tr>
    <tr>
        <td colspan="16"><strong>Date Range:</strong> <?= export_cell($dateFrom) ?> to <?= export_cell($dateTo) ?><?= $q !== '' ? ' | <strong>Search:</strong> ' . export_cell($q) : '' ?></td>
    </tr>
    <tr>
        <th>GRPO Date</th>
        <th>GRPO No.</th>
        <th>PO Date</th>
        <th>PO No.</th>
        <th>Vendor Code</th>
        <th>Vendor Name</th>
        <th>Item Code</th>
        <th>Part Name</th>
        <th>Lot No.</th>
        <th>Received Qty</th>
        <th>GRPO Line Qty</th>
        <th>Ordered Qty</th>
        <th>UOM</th>
        <th>PO WH</th>
        <th>GRPO WH</th>
        <th>Last Synced</th>
    </tr>
    <?php
    $rowCount = 0;
    $totalReceived = 0.0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)):
        $rowCount++;
        $totalReceived += (float)($row['ReceivedQty'] ?? 0);
    ?>
    <tr>
        <td class="date"><?= export_cell($row['GrpoDocDate'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['GrpoDocNum'] ?? '') ?></td>
        <td class="date"><?= export_cell($row['PoDocDate'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['PoDocNum'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['VendorCode'] ?? '') ?></td>
        <td><?= export_cell($row['VendorName'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['ItemCode'] ?? '') ?></td>
        <td><?= export_cell($row['PartName'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['LotNo'] ?? '') ?></td>
        <td class="number"><?= export_cell(export_qty($row['ReceivedQty'] ?? '')) ?></td>
        <td class="number"><?= export_cell(export_qty($row['GrpoLineQty'] ?? '')) ?></td>
        <td class="number"><?= export_cell(export_qty($row['OrderedQty'] ?? '')) ?></td>
        <td class="text" x:str><?= export_cell($row['Uom'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['PoWarehouse'] ?? '') ?></td>
        <td class="text" x:str><?= export_cell($row['GrpoWarehouse'] ?? '') ?></td>
        <td><?= export_cell($row['SyncedAt'] ?? '') ?></td>
    </tr>
    <?php endwhile; ?>
    <tr>
        <td colspan="8"><strong>Total Rows: <?= number_format($rowCount) ?></strong></td>
        <td><strong>Total Received Qty</strong></td>
        <td class="number"><strong><?= export_cell(export_qty($totalReceived)) ?></strong></td>
        <td colspan="6"></td>
    </tr>
</table>
</body>
</html>
