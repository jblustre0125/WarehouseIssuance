<?php
require __DIR__ . '/includes/db_connect.php';

$conn = get_whpokayoke_connection();
$item = '00000000209';

echo "REQUEST LINES\n";
$requestRows = fetch_all(
    $conn,
    "SELECT TOP 50
        H.RequestID,
        H.RequestNo,
        H.ITRNumber,
        H.RequestedAt,
        H.SAP_IT_DocEntry,
        H.IssuedTraceNo,
        L.RequestLineID,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        L.IssuedQty,
        L.LotNo,
        L.WarehouseLotNo,
        L.SAP_IT_DocEntry AS LineDocEntry,
        L.SAP_IT_LineNum
     FROM dbo.WarehouseIssueRequestHeader H
     INNER JOIN dbo.WarehouseIssueRequestLines L ON L.RequestID = H.RequestID
     WHERE L.ItemCode = ?
       AND H.RequestedAt >= '2026-09-12'
       AND H.RequestedAt < '2026-09-13'
     ORDER BY H.RequestedAt DESC, H.RequestID DESC, L.RequestLineID",
    [$item]
);

foreach ($requestRows as $row) {
    foreach ($row as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        echo $key . '=' . (string)$value . ' | ';
    }
    echo PHP_EOL;
}

echo "\nISSUANCE TRANSACTIONS\n";
$txRows = fetch_all(
    $conn,
    "SELECT TOP 100
        TransactionID,
        TraceNo,
        IssueRequestID,
        IssueRequestLineID,
        ITRDocEntry,
        ITRLineNum,
        ItemCode,
        PartName,
        Quantity,
        LotNo,
        WarehouseLotNo,
        IssuedAt,
        IssuedByUsername
     FROM dbo.IssuanceTransactions
     WHERE ItemCode = ?
       AND IssuedAt >= '2026-09-12'
       AND IssuedAt < '2026-09-13'
     ORDER BY IssuedAt DESC, TransactionID DESC",
    [$item]
);

foreach ($txRows as $row) {
    foreach ($row as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        echo $key . '=' . (string)$value . ' | ';
    }
    echo PHP_EOL;
}
