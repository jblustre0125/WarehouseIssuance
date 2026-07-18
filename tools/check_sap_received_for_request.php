<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

function usage(): void
{
    echo "Usage:\n";
    echo "  php tools/check_sap_received_for_request.php --request=REQ-20260718-xxxxx\n";
    echo "  php tools/check_sap_received_for_request.php --date=2026-07-18 [--requestor=2301-002]\n";
    echo "\n";
}

function arg_value(array $args, string $name): string
{
    $prefix = '--' . $name . '=';

    foreach ($args as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return trim(substr($arg, strlen($prefix)));
        }
    }

    return '';
}

function cli_date_value(string $value): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function qty($value): float
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function fmt_qty($value): string
{
    $n = qty($value);
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}

function has_column($conn, string $table, string $column): bool
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

$requestNo = arg_value($argv, 'request');
$date = cli_date_value(arg_value($argv, 'date'));
$requestor = arg_value($argv, 'requestor');

if ($requestNo === '' && $date === '') {
    usage();
    exit(2);
}

$whp = get_whpokayoke_connection();
$erp = get_erp_connection();

$where = [];
$params = [];

if ($requestNo !== '') {
    $where[] = 'H.RequestNo = ?';
    $params[] = $requestNo;
} else {
    $where[] = 'H.RequestedAt >= ?';
    $where[] = 'H.RequestedAt < DATEADD(day, 1, ?)';
    $params[] = $date;
    $params[] = $date;
}

if ($requestor !== '') {
    $where[] = 'H.RequestedByUsername = ?';
    $params[] = $requestor;
}

$lines = fetch_all(
    $whp,
    "SELECT
        H.RequestNo,
        H.ITRNumber,
        H.SAP_IT_DocEntry AS HeaderSAPDocEntry,
        H.RequestedByUsername,
        CONVERT(varchar(19), H.RequestedAt, 120) AS RequestedAt,
        H.Status AS HeaderStatus,
        L.RequestLineID,
        COALESCE(L.SAP_IT_DocEntry, H.SAP_IT_DocEntry) AS SAP_IT_DocEntry,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        L.IssuedQty,
        L.LotNo,
        CASE WHEN COL_LENGTH('dbo.WarehouseIssueRequestLines', 'WarehouseLotNo') IS NULL
             THEN CAST('' AS NVARCHAR(80))
             ELSE COALESCE(L.WarehouseLotNo, '')
        END AS WarehouseLotNo,
        L.Status AS LineStatus
     FROM dbo.WarehouseIssueRequestHeader H
     INNER JOIN dbo.WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
     WHERE " . implode(' AND ', $where) . "
     ORDER BY H.RequestedAt DESC, H.RequestNo, L.RequestLineID",
    $params
);

if (empty($lines)) {
    echo "No WH request lines found.\n";
    exit(1);
}

$docEntries = [];

foreach ($lines as $line) {
    $docEntry = (int)($line['SAP_IT_DocEntry'] ?? 0);

    if ($docEntry > 0) {
        $docEntries[$docEntry] = true;
    }
}

if (empty($docEntries)) {
    echo "No SAP ITR DocEntry values found on the selected WH request lines.\n";
    exit(1);
}

$hasCanceled = has_column($erp, 'OWTR', 'CANCELED');
$hasWtq1 = (bool)fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'WTQ1'");
$hasLineStatus = $hasWtq1 && has_column($erp, 'WTQ1', 'LineStatus');
$hasOpenQty = $hasWtq1 && has_column($erp, 'WTQ1', 'OpenQty');

$placeholders = implode(',', array_fill(0, count($docEntries), '?'));
$sapWhere = [
    'L.BaseType = ?',
    "L.BaseEntry IN ({$placeholders})"
];
$sapParams = array_merge([1250000001], array_keys($docEntries));

if ($hasCanceled) {
    $sapWhere[] = "ISNULL(T.CANCELED, 'N') = 'N'";
}

$lineStatusExpr = $hasLineStatus ? 'R.LineStatus' : "CAST('' AS NVARCHAR(10))";
$openQtyExpr = $hasOpenQty ? 'R.OpenQty' : 'CAST(NULL AS DECIMAL(18,3))';
$requestLineJoin = $hasWtq1
    ? 'LEFT JOIN WTQ1 R ON R.DocEntry = L.BaseEntry AND R.LineNum = L.BaseLine'
    : '';

$sapRows = fetch_all(
    $erp,
    "SELECT
        L.BaseEntry AS ITRDocEntry,
        L.BaseLine AS ITRLineNum,
        L.ItemCode,
        T.DocEntry AS ITDocEntry,
        T.DocNum AS ITDocNum,
        L.LineNum AS ITLineNum,
        ABS(ISNULL(L.Quantity, 0)) AS TransferQty,
        {$lineStatusExpr} AS ITRLineStatus,
        {$openQtyExpr} AS ITROpenQty
     FROM OWTR T
     INNER JOIN WTR1 L ON L.DocEntry = T.DocEntry
     {$requestLineJoin}
     WHERE " . implode(' AND ', $sapWhere) . "
     ORDER BY T.DocEntry, L.LineNum",
    $sapParams
);

$sapByLine = [];

foreach ($sapRows as $row) {
    $key = (int)$row['ITRDocEntry'] . '|' . (int)$row['ITRLineNum'] . '|' . strtoupper(trim((string)$row['ItemCode']));

    if (!isset($sapByLine[$key])) {
        $sapByLine[$key] = [
            'qty' => 0.0,
            'it_numbers' => [],
            'line_status' => trim((string)($row['ITRLineStatus'] ?? '')),
            'open_qty' => $row['ITROpenQty'] ?? '',
            'seen_transfer_lines' => [],
        ];
    }

    $transferLineKey = (string)($row['ITDocEntry'] ?? '') . '|' . (string)($row['ITLineNum'] ?? '');

    if ($transferLineKey !== '|' && !isset($sapByLine[$key]['seen_transfer_lines'][$transferLineKey])) {
        $sapByLine[$key]['qty'] += qty($row['TransferQty'] ?? 0);
        $sapByLine[$key]['seen_transfer_lines'][$transferLineKey] = true;
    }

    $itNo = trim((string)($row['ITDocNum'] ?? ''));

    if ($itNo !== '') {
        $sapByLine[$key]['it_numbers'][$itNo] = true;
    }
}

$out = fopen('php://output', 'w');
fputcsv($out, [
    'RequestNo',
    'Requestor',
    'ITRNumber',
    'ITRDocEntry',
    'ITRLineNum',
    'ItemCode',
    'PartName',
    'GRPOLot',
    'WHLot',
    'RequestLineStatus',
    'RequestedQty',
    'IssuedQty',
    'SAPTransferQty',
    'DeltaIssuedVsSAP',
    'SAPITNumbers',
    'SAPITRLineStatus',
    'SAPITROpenQty',
    'ReceivedInSAP'
]);

foreach ($lines as $line) {
    $key = (int)($line['SAP_IT_DocEntry'] ?? 0) . '|' .
        (int)($line['SAP_IT_LineNum'] ?? -1) . '|' .
        strtoupper(trim((string)($line['ItemCode'] ?? '')));

    $sap = $sapByLine[$key] ?? [
        'qty' => 0.0,
        'it_numbers' => [],
        'line_status' => '',
        'open_qty' => '',
    ];

    $issuedQty = qty($line['IssuedQty'] ?? 0);
    $sapQty = qty($sap['qty'] ?? 0);
    $delta = $issuedQty - $sapQty;

    if ($issuedQty <= 0 && $sapQty <= 0) {
        $received = 'NO ISSUED QTY';
    } elseif ($sapQty <= 0) {
        $received = 'NO';
    } elseif ($sapQty + 0.0001 >= $issuedQty) {
        $received = 'YES';
    } else {
        $received = 'PARTIAL';
    }

    fputcsv($out, [
        $line['RequestNo'] ?? '',
        $line['RequestedByUsername'] ?? '',
        $line['ITRNumber'] ?? '',
        $line['SAP_IT_DocEntry'] ?? '',
        $line['SAP_IT_LineNum'] ?? '',
        $line['ItemCode'] ?? '',
        $line['PartName'] ?? '',
        $line['LotNo'] ?? '',
        $line['WarehouseLotNo'] ?? '',
        $line['LineStatus'] ?? '',
        fmt_qty($line['RequestedQty'] ?? 0),
        fmt_qty($issuedQty),
        fmt_qty($sapQty),
        fmt_qty($delta),
        implode(';', array_keys($sap['it_numbers'] ?? [])),
        $sap['line_status'] ?? '',
        fmt_qty($sap['open_qty'] ?? 0),
        $received,
    ]);
}

fclose($out);

?>
