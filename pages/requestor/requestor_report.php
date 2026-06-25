<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

function request_report_date_value($name, $default = '')
{
    $value = trim((string)($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function request_report_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function request_report_date_cell($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function request_report_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
        [$table]
    );
}

function request_report_has_column($conn, $table, $column)
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

function request_report_sap_datetime_text($dateValue, $timeValue = null)
{
    if ($dateValue instanceof DateTimeInterface) {
        $dateText = $dateValue->format('Y-m-d');
    } else {
        $dateText = trim((string)$dateValue);

        if ($dateText !== '' && preg_match('/^\w{3}\s+\w{3}\s+\d{1,2}/', $dateText)) {
            $ts = strtotime($dateText);
            $dateText = $ts ? date('Y-m-d', $ts) : $dateText;
        }
    }

    if ($dateText === '') {
        return '';
    }

    if ($timeValue === null || $timeValue === '') {
        return $dateText;
    }

    $timeText = preg_replace('/\D+/', '', (string)$timeValue);

    if ($timeText === '') {
        return $dateText;
    }

    if (strlen($timeText) <= 4) {
        $timeText = str_pad(substr($timeText, -4), 4, '0', STR_PAD_LEFT);

        return $dateText . ' ' .
            substr($timeText, 0, 2) . ':' .
            substr($timeText, 2, 2) . ':00';
    }

    $timeText = str_pad(substr($timeText, -6), 6, '0', STR_PAD_LEFT);

    return $dateText . ' ' .
        substr($timeText, 0, 2) . ':' .
        substr($timeText, 2, 2) . ':' .
        substr($timeText, 4, 2);
}

function request_report_sap_key($docEntry, $lineNum, $itemCode)
{
    if ($lineNum === null || trim((string)$lineNum) === '') {
        return '';
    }

    $docEntry = (int)$docEntry;
    $lineNum = (int)$lineNum;
    $itemCode = strtoupper(trim((string)$itemCode));

    if ($docEntry <= 0 || $lineNum < 0 || $itemCode === '') {
        return '';
    }

    return $docEntry . '|' . $lineNum . '|' . $itemCode;
}

function request_report_enrich_scanplus(array &$rows)
{
    if (empty($rows)) {
        return;
    }

    $docEntries = [];

    foreach ($rows as $r) {
        $docEntry = (int)($r['LineSAPDocEntry'] ?? $r['HeaderSAPDocEntry'] ?? 0);
        $lineNum = $r['SAP_IT_LineNum'] ?? null;
        $itemCode = $r['ItemCode'] ?? '';
        $key = request_report_sap_key($docEntry, $lineNum, $itemCode);

        if ($key !== '') {
            $docEntries[$docEntry] = true;
        }
    }

    if (empty($docEntries)) {
        return;
    }

    $erp = get_erp_connection();

    if (
        !request_report_has_table($erp, 'OWTR') ||
        !request_report_has_table($erp, 'WTR1') ||
        !request_report_has_column($erp, 'WTR1', 'BaseEntry') ||
        !request_report_has_column($erp, 'WTR1', 'BaseLine') ||
        !request_report_has_column($erp, 'WTR1', 'BaseType')
    ) {
        return;
    }

    $hasCanceled = request_report_has_column($erp, 'OWTR', 'CANCELED');
    $hasDocDate = request_report_has_column($erp, 'OWTR', 'DocDate');
    $hasCreateDate = request_report_has_column($erp, 'OWTR', 'CreateDate');
    $hasCreateTS = request_report_has_column($erp, 'OWTR', 'CreateTS');
    $hasUpdateDate = request_report_has_column($erp, 'OWTR', 'UpdateDate');
    $hasUpdateTS = request_report_has_column($erp, 'OWTR', 'UpdateTS');
    $hasUserSign = request_report_has_column($erp, 'OWTR', 'UserSign');
    $hasScanPlusBarcodeUser = request_report_has_column($erp, 'OWTR', 'U_BarcodeUser');
    $hasScanPlusDateTime = request_report_has_column($erp, 'OWTR', 'U_ScanDateTime');
    $hasScanPlusTime = request_report_has_column($erp, 'OWTR', 'U_ScanTime');
    $hasWhsCode = request_report_has_column($erp, 'WTR1', 'WhsCode');
    $hasWtq1 = request_report_has_table($erp, 'WTQ1');
    $hasLineStatus = $hasWtq1 && request_report_has_column($erp, 'WTQ1', 'LineStatus');
    $hasOpenQty = $hasWtq1 && request_report_has_column($erp, 'WTQ1', 'OpenQty');

    $scanDateExpr = $hasScanPlusDateTime
        ? 'T.U_ScanDateTime'
        : ($hasCreateDate
        ? 'T.CreateDate'
        : ($hasDocDate ? 'T.DocDate' : 'CAST(NULL AS DATETIME)'));
    $scanTimeExpr = $hasScanPlusTime
        ? 'T.U_ScanTime'
        : ($hasCreateTS ? 'T.CreateTS' : 'CAST(NULL AS INT)');
    $closeDateExpr = $hasUpdateDate
        ? 'T.UpdateDate'
        : ($hasCreateDate ? 'T.CreateDate' : ($hasDocDate ? 'T.DocDate' : 'CAST(NULL AS DATETIME)'));
    $closeTimeExpr = $hasUpdateTS
        ? 'T.UpdateTS'
        : ($hasCreateTS ? 'T.CreateTS' : 'CAST(NULL AS INT)');
    $toWhsExpr = $hasWhsCode ? 'L.WhsCode' : "CAST('' AS NVARCHAR(80))";
    $lineStatusExpr = $hasLineStatus ? 'R.LineStatus' : "CAST('' AS NVARCHAR(10))";
    $openQtyExpr = $hasOpenQty ? 'R.OpenQty' : 'CAST(NULL AS DECIMAL(18,3))';
    $requestLineJoin = $hasWtq1
        ? 'LEFT JOIN WTQ1 R ON R.DocEntry = L.BaseEntry AND R.LineNum = L.BaseLine'
        : '';

    $userJoin = '';
    $scannedByParts = [];

    if ($hasScanPlusBarcodeUser) {
        $scannedByParts[] = "NULLIF(CAST(T.U_BarcodeUser AS NVARCHAR(120)), '')";
    }

    if ($hasUserSign) {
        $hasOusr = request_report_has_table($erp, 'OUSR') &&
            request_report_has_column($erp, 'OUSR', 'USERID');

        if ($hasOusr) {
            $nameParts = [];

            if (request_report_has_column($erp, 'OUSR', 'USER_CODE')) {
                $nameParts[] = "NULLIF(CAST(U1.USER_CODE AS NVARCHAR(120)), '')";
            }

            if (request_report_has_column($erp, 'OUSR', 'U_NAME')) {
                $nameParts[] = "NULLIF(CAST(U1.U_NAME AS NVARCHAR(120)), '')";
            }

            $nameParts[] = 'CAST(T.UserSign AS NVARCHAR(120))';
            $userJoin = 'LEFT JOIN OUSR U1 ON U1.USERID = T.UserSign';
            $scannedByParts[] = 'COALESCE(' . implode(', ', $nameParts) . ')';
        } else {
            $scannedByParts[] = 'CAST(T.UserSign AS NVARCHAR(120))';
        }
    }

    $scannedByExpr = !empty($scannedByParts)
        ? 'COALESCE(' . implode(', ', $scannedByParts) . ')'
        : "CAST('' AS NVARCHAR(120))";

    $entryValues = array_keys($docEntries);
    $placeholders = implode(',', array_fill(0, count($entryValues), '?'));
    $where = [
        'L.BaseType = ?',
        "L.BaseEntry IN ({$placeholders})"
    ];
    $params = array_merge([1250000001], $entryValues);

    if ($hasCanceled) {
        $where[] = "ISNULL(T.CANCELED, 'N') = 'N'";
    }

    $sapRows = fetch_all(
        $erp,
        "SELECT
            L.BaseEntry AS ITRDocEntry,
            L.BaseLine AS ITRLineNum,
            L.ItemCode,
            T.DocEntry AS ITDocEntry,
            T.DocNum AS ITNumber,
            {$scanDateExpr} AS SAPScanDate,
            {$scanTimeExpr} AS SAPScanTime,
            {$closeDateExpr} AS SAPCloseDate,
            {$closeTimeExpr} AS SAPCloseTime,
            {$scannedByExpr} AS SAPScannedBy,
            {$toWhsExpr} AS SAPScanArea,
            {$lineStatusExpr} AS ITRLineStatus,
            {$openQtyExpr} AS ITROpenQty,
            L.Quantity AS TransferQty
         FROM OWTR T
         INNER JOIN WTR1 L ON L.DocEntry = T.DocEntry
         {$requestLineJoin}
         {$userJoin}
         WHERE " . implode(' AND ', $where) . "
         ORDER BY T.DocEntry DESC, L.LineNum DESC",
        $params
    );

    $scanPlusByLine = [];

    foreach ($sapRows as $sapRow) {
        $key = request_report_sap_key(
            $sapRow['ITRDocEntry'] ?? 0,
            $sapRow['ITRLineNum'] ?? null,
            $sapRow['ItemCode'] ?? ''
        );

        if ($key === '') {
            continue;
        }

        $lineStatus = strtoupper(trim((string)($sapRow['ITRLineStatus'] ?? '')));
        $openQty = $sapRow['ITROpenQty'];
        $isClosed = $lineStatus === 'C' || ($openQty !== null && (float)$openQty <= 0);
        $scanAt = request_report_sap_datetime_text($sapRow['SAPScanDate'] ?? '', $sapRow['SAPScanTime'] ?? null);
        $closeAt = $isClosed
            ? request_report_sap_datetime_text($sapRow['SAPCloseDate'] ?? '', $sapRow['SAPCloseTime'] ?? null)
            : '';

        $status = $isClosed ? 'CLOSED' : 'SAP_RECEIVED';

        if (!isset($scanPlusByLine[$key])) {
            $scanPlusByLine[$key] = [
                'scanned_by' => trim((string)($sapRow['SAPScannedBy'] ?? '')),
                'scan_area' => trim((string)($sapRow['SAPScanArea'] ?? '')),
                'scanned_at' => $scanAt,
                'receive_status' => $status,
                'closed_at' => $closeAt,
                'it_numbers' => [],
                'transfer_qty' => 0.0
            ];
        }

        $itNumber = trim((string)($sapRow['ITNumber'] ?? ''));

        if ($itNumber !== '') {
            $scanPlusByLine[$key]['it_numbers'][$itNumber] = true;
        }

        $scanPlusByLine[$key]['transfer_qty'] += (float)($sapRow['TransferQty'] ?? 0);

        if ($scanAt !== '' && strcmp($scanAt, (string)$scanPlusByLine[$key]['scanned_at']) > 0) {
            $scanPlusByLine[$key]['scanned_by'] = trim((string)($sapRow['SAPScannedBy'] ?? ''));
            $scanPlusByLine[$key]['scan_area'] = trim((string)($sapRow['SAPScanArea'] ?? ''));
            $scanPlusByLine[$key]['scanned_at'] = $scanAt;
        }

        if ($isClosed && $closeAt !== '') {
            $scanPlusByLine[$key]['receive_status'] = 'CLOSED';
            $scanPlusByLine[$key]['closed_at'] = $closeAt;
        }
    }

    foreach ($rows as &$row) {
        $key = request_report_sap_key(
            $row['LineSAPDocEntry'] ?? $row['HeaderSAPDocEntry'] ?? 0,
            $row['SAP_IT_LineNum'] ?? null,
            $row['ItemCode'] ?? ''
        );

        if ($key === '' || !isset($scanPlusByLine[$key])) {
            continue;
        }

        $scanPlus = $scanPlusByLine[$key];
        $localStatus = strtoupper(trim((string)($row['ReceiveStatus'] ?? '')));

        if (trim((string)($row['ScannedBy'] ?? '')) === '') {
            $row['ScannedBy'] = $scanPlus['scanned_by'];
        }

        if (trim((string)($row['ScannedArea'] ?? '')) === '') {
            $row['ScannedArea'] = $scanPlus['scan_area'];
        }

        if (trim((string)($row['ScannedAt'] ?? '')) === '') {
            $row['ScannedAt'] = $scanPlus['scanned_at'];
        }

        if ($localStatus === '' || $localStatus === 'ISSUED' || $localStatus === 'PENDING_RECEIVE') {
            $row['ReceiveStatus'] = $scanPlus['receive_status'];
        }

        if ($scanPlus['closed_at'] !== '') {
            $row['ClosedAt'] = $scanPlus['closed_at'];
        }
    }
    unset($row);
}

function request_excel_cell($value)
{
    return htmlspecialchars(request_report_cell($value), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$dateFrom = request_report_date_value('date_from', $today);
$dateTo = request_report_date_value('date_to', $today);
$export = strtolower(trim((string)($_GET['export'] ?? ''))) === 'excel';

$u = current_user();
$currentRole = strtolower($u['role'] ?? '');

$where = [
    'H.RequestedAt >= ?',
    'H.RequestedAt < DATEADD(day, 1, ?)'
];

$params = [
    $dateFrom,
    $dateTo
];

if (($u['role'] ?? '') !== ROLE_ADMIN) {
    $where[] = 'H.RequestedByUsername = ?';
    $params[] = $u['username'] ?? '';
}

$conn = get_whpokayoke_connection();

$traceQtyColumn = '';
foreach (['IssuedQty', 'IssueQty', 'Qty', 'Quantity', 'ScannedQty'] as $candidateQtyColumn) {
    if (request_report_has_column($conn, 'RawmatTraceLines', $candidateQtyColumn)) {
        $traceQtyColumn = $candidateQtyColumn;
        break;
    }
}

$traceQtyExpr = $traceQtyColumn !== ''
    ? 'TRY_CONVERT(DECIMAL(18,3), TL.' . $traceQtyColumn . ')'
    : 'CAST(NULL AS DECIMAL(18,3))';

$sql = "
    SELECT
        H.RequestNo,
        H.ITRNumber,
        H.NeededDate,
        H.Status AS HeaderStatus,
        H.Remarks,
        H.RequestedByUsername,
        H.RequestedAt,
        H.IssuedTraceNo,
        H.ClosedAt,
        H.SAP_IT_DocEntry AS HeaderSAPDocEntry,

        L.RequestLineID,
        L.SAP_IT_DocEntry AS LineSAPDocEntry,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        COALESCE({$traceQtyExpr}, L.IssuedQty) AS IssuedQty,
        COALESCE(NULLIF(LTRIM(RTRIM(TL.LotNo)), ''), L.LotNo) AS LotNo,

        TL.ReceivedByUsername AS ScannedBy,
        TL.ReceiverArea AS ScannedArea,
        COALESCE(TL.ReceivedScanAt, TL.ReceivedAt) AS ScannedAt,
        TL.VerificationStatus AS ReceiveStatus

    FROM WarehouseIssueRequestHeader H
    INNER JOIN WarehouseIssueRequestLines L ON L.RequestID = H.RequestID

    OUTER APPLY (
        SELECT TL0.*
        FROM RawmatTraceLines TL0
        LEFT JOIN RawmatTraceHeader TH0 ON TH0.TraceID = TL0.TraceID
        WHERE TL0.IssueRequestLineID = L.RequestLineID
           OR (
                TH0.TraceNo = H.IssuedTraceNo
            AND TL0.ItemCode = L.ItemCode
           )
    ) TL

    WHERE " . implode(' AND ', $where) . "
    ORDER BY H.RequestedAt DESC, H.RequestID DESC, L.RequestLineID ASC, TL.TraceLineID ASC
";

$rows = fetch_all($conn, $sql, $params);
request_report_enrich_scanplus($rows);

$columns = [
    'Request No',
    'ITR/IT',
    'Needed Date',
    'Request Status',
    'Part Number',
    'Part Name',
    'Requested Qty',
    'Issued Qty',
    'Lot',
    'Requested By',
    'Requested At',
    'Issued Trace No',
    'Scanned By',
    'Scan Area',
    'Scanned At',
    'Receive Status',
    'Closed At',
    'Remarks'
];

if ($export) {
    $filename = 'requestor_requests_' . $dateFrom . '_to_' . $dateTo . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Requestor Requests</title>
</head>
<body>
<table border="1">
    <thead>
        <tr>
            <?php foreach ($columns as $c): ?>
                <th><?= request_excel_cell($c) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?= count($columns) ?>">No records found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= request_excel_cell($r['RequestNo'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ITRNumber'] ?? '') ?></td>
                    <td><?= request_excel_cell(request_report_date_cell($r['NeededDate'] ?? '')) ?></td>
                    <td><?= request_excel_cell($r['HeaderStatus'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ItemCode'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['PartName'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['RequestedQty'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['IssuedQty'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['LotNo'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['RequestedByUsername'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['RequestedAt'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['IssuedTraceNo'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ScannedBy'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ScannedArea'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ScannedAt'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ReceiveStatus'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['ClosedAt'] ?? '') ?></td>
                    <td><?= request_excel_cell($r['Remarks'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <title>Requestor Report</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 250px;
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

        .mobile-topbar {
            display: none;
        }

        .sidebar-backdrop {
            display: none;
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

        .filter-box {
            background: #f8fafc;
            border: 1px solid #e5eaf2;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-control {
            border-radius: 11px;
            border: 1px solid #d9e2ef;
            min-height: 42px;
            font-size: 14px;
            background-color: #ffffff;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .report-table-wrap {
            max-height: 68vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            background: #ffffff;
        }

        .report-table {
            width: 100%;
            table-layout: fixed;
            font-size: 10px;
            margin-bottom: 0;
        }

        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            color: #374151;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #d8e0eb;
            padding: 8px 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table td {
            padding: 7px 5px;
            vertical-align: middle;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .report-table tbody tr:hover {
            background: #eef6ff;
        }

        .col-request { width: 8%; white-space: nowrap; }
        .col-itr { width: 6%; white-space: nowrap; }
        .col-needed { width: 7%; white-space: nowrap; }
        .col-header-status { width: 8%; white-space: nowrap; }
        .col-item { width: 8%; white-space: nowrap; }
        .col-part { width: 14%; white-space: normal; line-height: 1.25; }
        .col-qty { width: 6%; text-align: right; white-space: nowrap; }
        .col-lot { width: 7%; white-space: nowrap; }
        .col-user { width: 8%; white-space: nowrap; }
        .col-date { width: 10%; white-space: nowrap; }
        .col-trace { width: 9%; white-space: nowrap; }
        .col-closed { width: 10%; white-space: nowrap; }
        .col-remarks { width: 10%; white-space: nowrap; }

        .empty-row {
            padding: 34px !important;
            text-align: center;
            color: #6b7280 !important;
        }

        .status-pill {
            display: inline-flex;
            max-width: 100%;
            align-items: center;
            justify-content: center;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-open,
        .status-pending,
        .status-pending_receive {
            background: #fef3c7;
            color: #92400e;
        }

        .status-issued,
        .status-sap_received,
        .status-closed,
        .status-completed,
        .status-matched {
            background: #dcfce7;
            color: #166534;
        }

        .status-cancelled,
        .status-rejected,
        .status-lot_mismatch,
        .status-qty_variance,
        .status-lot_and_qty_variance {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 1300px) {
            .report-table {
                font-size: 10px;
            }

            .report-table thead th {
                font-size: 8px;
                padding: 7px 4px;
            }

            .report-table td {
                padding: 6px 4px;
            }

            .status-pill {
                font-size: 7.5px;
                padding: 3px 5px;
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

            .report-table-wrap {
                overflow: auto;
            }

            .report-table {
                min-width: 1650px;
                table-layout: auto;
                font-size: 12px;
            }

            .report-table thead th {
                font-size: 10px;
                padding: 8px 6px;
            }

            .report-table td {
                padding: 7px 6px;
                white-space: nowrap;
            }

            .col-request,
            .col-itr,
            .col-needed,
            .col-header-status,
            .col-item,
            .col-part,
            .col-qty,
            .col-lot,
            .col-user,
            .col-date,
            .col-trace,
            .col-closed,
            .col-remarks {
                width: auto;
                min-width: 100px;
            }

            .col-part {
                min-width: 240px;
            }

            .col-date,
            .col-closed {
                min-width: 160px;
            }

            .col-remarks {
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
        <div class="shell-subtitle">Requestor reporting</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">

    <?php app_sidebar('requestor_report'); ?>

    <main class="main-content">

        <div class="mobile-topbar">
            <strong>Requestor Report</strong>
            <button class="btn btn-sm btn-primary" type="button" id="sidebarToggle">
                Menu
            </button>
        </div>

        <div class="page-header">
            <div>
                <h4 class="page-title">Requestor Report</h4>
                <div class="page-subtitle">
                    Issue request history by date range, including receiver scan details.
                </div>
            </div>

            <span class="badge bg-primary rounded-pill px-3 py-2">
                <?= count($rows) ?> line(s)
            </span>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Report Filters</h5>
                <div class="content-card-subtitle">
                    Filter issue requests and export the result to Excel.
                </div>
            </div>

            <div class="content-card-body">

                <form class="filter-box" method="get">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label" for="date_from">Date From</label>
                            <input
                                class="form-control"
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= h($dateFrom) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label" for="date_to">Date To</label>
                            <input
                                class="form-control"
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= h($dateTo) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <button class="btn btn-primary" type="submit">
                                Filter
                            </button>
                        </div>

                        <div class="col-sm-6 col-md-3 d-grid">
                            <a
                                class="btn btn-success"
                                href="pages/requestor/requestor_report.php?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&export=excel"
                            >
                                Export Excel
                            </a>
                        </div>
                    </div>
                </form>

                <input
                    id="searchReport"
                    class="form-control form-control-sm mb-3"
                    placeholder="Search request, ITR, item, status, scanned by..."
                >

                <div class="report-table-wrap">
                    <table class="table table-bordered table-striped align-middle report-table" id="reportTable">
                        <thead>
                            <tr>
                                <th class="col-request">Request No</th>
                                <th class="col-itr">ITR/IT</th>
                                <th class="col-needed">Needed</th>
                                <th class="col-header-status">Req Status</th>
                                <th class="col-item">Part No</th>
                                <th class="col-part">Part Name</th>
                                <th class="col-qty">Req Qty</th>
                                <th class="col-qty">Iss Qty</th>
                                <th class="col-lot">Lot / Qty</th>
                                <th class="col-user">Req By</th>
                                <th class="col-date">Requested At</th>
                                <th class="col-trace">Trace No</th>
                                <th class="col-user">Scanned By</th>
                                <th class="col-user">Scan Area</th>
                                <th class="col-date">Scanned At</th>
                                <th class="col-header-status">Receive Status</th>
                                <th class="col-closed">Closed At</th>
                                <th class="col-remarks">Remarks</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="<?= count($columns) ?>" class="empty-row">
                                        No records found for the selected date range.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $headerStatus = strtolower((string)($r['HeaderStatus'] ?? ''));
                                        $receiveStatus = strtolower((string)($r['ReceiveStatus'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="col-request" title="<?= h(request_report_cell($r['RequestNo'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['RequestNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-itr" title="<?= h(request_report_cell($r['ITRNumber'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ITRNumber'] ?? '')) ?>
                                        </td>

                                        <td class="col-needed" title="<?= h(request_report_date_cell($r['NeededDate'] ?? '')) ?>">
                                            <?= h(request_report_date_cell($r['NeededDate'] ?? '')) ?>
                                        </td>

                                        <td class="col-header-status" title="<?= h(request_report_cell($r['HeaderStatus'] ?? '')) ?>">
                                            <span class="status-pill status-<?= h($headerStatus) ?>">
                                                <?= h(request_report_cell($r['HeaderStatus'] ?? '')) ?>
                                            </span>
                                        </td>

                                        <td class="col-item" title="<?= h(request_report_cell($r['ItemCode'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ItemCode'] ?? '')) ?>
                                        </td>

                                        <td class="col-part" title="<?= h(request_report_cell($r['PartName'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['PartName'] ?? '')) ?>
                                        </td>

                                        <td class="col-qty" title="<?= h(request_report_cell($r['RequestedQty'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['RequestedQty'] ?? '')) ?>
                                        </td>

                                        <td class="col-qty" title="<?= h(request_report_cell($r['IssuedQty'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['IssuedQty'] ?? '')) ?>
                                        </td>

                                        <td class="col-lot" title="<?= h(request_report_cell($r['LotNo'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['LotNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-user" title="<?= h(request_report_cell($r['RequestedByUsername'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['RequestedByUsername'] ?? '')) ?>
                                        </td>

                                        <td class="col-date" title="<?= h(request_report_cell($r['RequestedAt'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['RequestedAt'] ?? '')) ?>
                                        </td>

                                        <td class="col-trace" title="<?= h(request_report_cell($r['IssuedTraceNo'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['IssuedTraceNo'] ?? '')) ?>
                                        </td>

                                        <td class="col-user" title="<?= h(request_report_cell($r['ScannedBy'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ScannedBy'] ?? '')) ?>
                                        </td>

                                        <td class="col-user" title="<?= h(request_report_cell($r['ScannedArea'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ScannedArea'] ?? '')) ?>
                                        </td>

                                        <td class="col-date" title="<?= h(request_report_cell($r['ScannedAt'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ScannedAt'] ?? '')) ?>
                                        </td>

                                        <td class="col-header-status" title="<?= h(request_report_cell($r['ReceiveStatus'] ?? '')) ?>">
                                            <?php if (trim((string)($r['ReceiveStatus'] ?? '')) !== ''): ?>
                                                <span class="status-pill status-<?= h($receiveStatus) ?>">
                                                    <?= h(request_report_cell($r['ReceiveStatus'] ?? '')) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="col-closed" title="<?= h(request_report_cell($r['ClosedAt'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['ClosedAt'] ?? '')) ?>
                                        </td>

                                        <td class="col-remarks" title="<?= h(request_report_cell($r['Remarks'] ?? '')) ?>">
                                            <?= h(request_report_cell($r['Remarks'] ?? '')) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const searchInput = document.getElementById('searchReport');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        document.querySelectorAll('#reportTable tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
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
</script>

</body>
</html>
