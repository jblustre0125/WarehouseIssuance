<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

const DEFAULT_LOOKBACK_DAYS = 14;
const MAX_LOOKBACK_DAYS = 366;
const SAP_QUERY_TIMEOUT_SECONDS = 120;

function grpo_sync_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function grpo_sql_error(string $prefix): RuntimeException
{
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [];
    return new RuntimeException($prefix . ': ' . print_r($errors, true));
}

function grpo_cache_tables_exist($conn): bool
{
    $stmt = sqlsrv_query(
        $conn,
        "SELECT
            CASE WHEN OBJECT_ID(N'dbo.PickerGrpoReceiptCache', N'U') IS NOT NULL THEN 1 ELSE 0 END AS HasCache,
            CASE WHEN OBJECT_ID(N'dbo.PickerGrpoCacheStatus', N'U') IS NOT NULL THEN 1 ELSE 0 END AS HasStatus"
    );

    if ($stmt === false) {
        return false;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: [];
    return (int)($row['HasCache'] ?? 0) === 1 && (int)($row['HasStatus'] ?? 0) === 1;
}

function grpo_update_status($conn, string $status, string $message, ?int $rowCount, string $dateFrom, string $dateTo, bool $success): void
{
    $sql = "UPDATE dbo.PickerGrpoCacheStatus
            SET LastFinishedAt = SYSDATETIME(),
                LastStatus = ?,
                LastMessage = ?,
                LastRowCount = ?,
                WindowDateFrom = ?,
                WindowDateTo = ?" .
                ($success ? ", LastSuccessfulAt = SYSDATETIME()" : "") .
            " WHERE StatusID = 1";

    sqlsrv_query($conn, $sql, [
        $status,
        mb_substr($message, 0, 1000),
        $rowCount,
        $dateFrom,
        $dateTo,
    ]);
}

$lookbackDays = isset($argv[1]) ? (int)$argv[1] : DEFAULT_LOOKBACK_DAYS;
$lookbackDays = max(1, min(MAX_LOOKBACK_DAYS, $lookbackDays));

$dateTo = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-' . ($lookbackDays - 1) . ' days'));

$whp = get_whpokayoke_connection();
if (!grpo_cache_tables_exist($whp)) {
    grpo_sync_log('Required cache tables do not exist. Run 01_create_picker_grpo_cache.sql first.');
    exit(2);
}

sqlsrv_query(
    $whp,
    "UPDATE dbo.PickerGrpoCacheStatus
     SET LastStartedAt = SYSDATETIME(),
         LastFinishedAt = NULL,
         LastStatus = 'RUNNING',
         LastMessage = ?,
         WindowDateFrom = ?,
         WindowDateTo = ?
     WHERE StatusID = 1",
    ['Refreshing GRPO cache.', $dateFrom, $dateTo]
);

grpo_sync_log("Refreshing {$dateFrom} through {$dateTo} ({$lookbackDays} days).");

try {
    $erp = get_erp_connection();

    $sql = "SELECT
                PO.DocEntry AS PoDocEntry,
                PO.DocNum AS PoDocNum,
                CONVERT(VARCHAR(10), PO.DocDate, 23) AS PoDocDate,
                POL.LineNum AS PoLineNum,
                PO.CardCode AS VendorCode,
                PO.CardName AS VendorName,
                G.DocEntry AS GrpoDocEntry,
                G.DocNum AS GrpoDocNum,
                CONVERT(VARCHAR(10), G.DocDate, 23) AS GrpoDocDate,
                GL.LineNum AS GrpoLineNum,
                GL.ItemCode,
                COALESCE(NULLIF(GL.Dscription, ''), NULLIF(POL.Dscription, ''), '') AS PartName,
                COALESCE(B.BatchNum, '') AS LotNo,
                CASE
                    WHEN B.BatchNum IS NULL THEN ABS(ISNULL(GL.Quantity, 0))
                    ELSE ABS(ISNULL(B.Quantity, 0))
                END AS ReceivedQty,
                ABS(ISNULL(GL.Quantity, 0)) AS GrpoLineQty,
                ABS(ISNULL(POL.Quantity, 0)) AS OrderedQty,
                COALESCE(NULLIF(GL.unitMsr, ''), NULLIF(GL.UomCode, ''),
                         NULLIF(POL.unitMsr, ''), NULLIF(POL.UomCode, ''), '') AS Uom,
                POL.WhsCode AS PoWarehouse,
                GL.WhsCode AS GrpoWarehouse
            FROM OPDN G WITH (NOLOCK)
            INNER JOIN PDN1 GL WITH (NOLOCK)
                ON GL.DocEntry = G.DocEntry
            INNER JOIN OPOR PO WITH (NOLOCK)
                ON PO.DocEntry = GL.BaseEntry
            INNER JOIN POR1 POL WITH (NOLOCK)
                ON POL.DocEntry = GL.BaseEntry
               AND POL.LineNum = GL.BaseLine
            LEFT JOIN IBT1 B WITH (NOLOCK)
                ON B.BaseType = 20
               AND B.BaseEntry = G.DocEntry
               AND B.BaseLinNum = GL.LineNum
               AND B.ItemCode = GL.ItemCode
            WHERE G.DocDate >= ?
              AND G.DocDate < DATEADD(day, 1, ?)
              AND GL.BaseType = 22
              AND GL.BaseEntry IS NOT NULL
              AND GL.BaseLine IS NOT NULL
              AND ISNULL(G.CANCELED, 'N') = 'N'
              AND ISNULL(PO.CANCELED, 'N') = 'N'
            ORDER BY G.DocDate, G.DocNum, GL.LineNum, B.BatchNum";

    $stmt = sqlsrv_query(
        $erp,
        $sql,
        [$dateFrom, $dateTo],
        ['QueryTimeout' => SAP_QUERY_TIMEOUT_SECONDS]
    );

    if ($stmt === false) {
        throw grpo_sql_error('SAP GRPO query failed');
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

    grpo_sync_log('SAP rows retrieved: ' . count($rows));

    if (sqlsrv_begin_transaction($whp) === false) {
        throw grpo_sql_error('Unable to start local cache transaction');
    }

    try {
        $deleteStmt = sqlsrv_query(
            $whp,
            "DELETE FROM dbo.PickerGrpoReceiptCache
             WHERE GrpoDocDate >= ?
               AND GrpoDocDate < DATEADD(day, 1, ?)",
            [$dateFrom, $dateTo]
        );

        if ($deleteStmt === false) {
            throw grpo_sql_error('Unable to clear the refresh window');
        }

        $insertSql = "INSERT INTO dbo.PickerGrpoReceiptCache
            (PoDocEntry, PoDocNum, PoDocDate, PoLineNum,
             VendorCode, VendorName,
             GrpoDocEntry, GrpoDocNum, GrpoDocDate, GrpoLineNum,
             ItemCode, PartName, LotNo,
             ReceivedQty, GrpoLineQty, OrderedQty,
             Uom, PoWarehouse, GrpoWarehouse, SyncedAt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())";

        $inserted = 0;
        foreach ($rows as $row) {
            $params = [
                (int)$row['PoDocEntry'],
                $row['PoDocNum'],
                $row['PoDocDate'],
                (int)$row['PoLineNum'],
                (string)($row['VendorCode'] ?? ''),
                (string)($row['VendorName'] ?? ''),
                (int)$row['GrpoDocEntry'],
                $row['GrpoDocNum'],
                $row['GrpoDocDate'],
                (int)$row['GrpoLineNum'],
                (string)$row['ItemCode'],
                (string)($row['PartName'] ?? ''),
                trim((string)($row['LotNo'] ?? '')),
                (float)($row['ReceivedQty'] ?? 0),
                (float)($row['GrpoLineQty'] ?? 0),
                (float)($row['OrderedQty'] ?? 0),
                (string)($row['Uom'] ?? ''),
                (string)($row['PoWarehouse'] ?? ''),
                (string)($row['GrpoWarehouse'] ?? ''),
            ];

            $insertStmt = sqlsrv_query($whp, $insertSql, $params);
            if ($insertStmt === false) {
                throw grpo_sql_error('Unable to insert GRPO cache row');
            }
            $inserted++;
        }

        if (sqlsrv_commit($whp) === false) {
            throw grpo_sql_error('Unable to commit GRPO cache transaction');
        }

        $message = "Successfully refreshed {$inserted} GRPO cache rows.";
        grpo_update_status($whp, 'SUCCESS', $message, $inserted, $dateFrom, $dateTo, true);
        grpo_sync_log($message);
        exit(0);
    } catch (Throwable $e) {
        sqlsrv_rollback($whp);
        throw $e;
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    grpo_update_status($whp, 'FAILED', $message, null, $dateFrom, $dateTo, false);
    grpo_sync_log('FAILED: ' . $message);
    exit(1);
}
