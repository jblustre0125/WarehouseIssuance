<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';

require_role([ROLE_PICKER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

const PICKER_PO_CACHE_SCOPE = 'open_purchase_orders';
const PICKER_PO_DELTA_OVERLAP_MINUTES = 10;
const PICKER_PO_FULL_REFRESH_HOURS = 24;
const PICKER_PO_FULL_REFRESH_START_HOUR = 1;
const PICKER_PO_FULL_REFRESH_END_HOUR = 4;
const PICKER_PO_INSERT_BATCH_SIZE = 80;
const PICKER_PO_DOC_CHUNK_SIZE = 100;

function picker_po_json_out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function picker_po_sql_error($fallback)
{
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);

    if (!is_array($errors) || empty($errors)) {
        return $fallback;
    }

    $messages = [];

    foreach ($errors as $error) {
        $message = trim((string)($error['message'] ?? ''));

        if ($message !== '') {
            $messages[] = $message;
        }
    }

    return empty($messages) ? $fallback : $fallback . ' ' . implode(' | ', $messages);
}

function picker_po_dt($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    return $value === null ? '' : (string)$value;
}

function picker_po_datetime($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return $value === null ? '' : (string)$value;
}

function picker_po_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = ?",
        [$table]
    );
}

function picker_po_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS FoundColumn
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

function picker_po_cache_ready($conn)
{
    return picker_po_has_table($conn, 'SapOpenPurchaseOrderCache')
        && picker_po_has_table($conn, 'SapOpenPurchaseOrderCacheStage')
        && picker_po_has_table($conn, 'SapOpenPurchaseOrderSyncState');
}

function picker_po_sync_state($conn)
{
    return fetch_one(
        $conn,
        "SELECT TOP 1
            ScopeName,
            LastSuccessfulSync,
            LastFullSync,
            LastDeltaSync,
            LastStatus,
            LastMessage,
            LastRowCount,
            UpdatedAt
         FROM dbo.SapOpenPurchaseOrderSyncState
         WHERE ScopeName = ?",
        [PICKER_PO_CACHE_SCOPE]
    );
}

function picker_po_update_sync_state($conn, $mode, $status, $message, $rowCount, $successful = false)
{
    $setSuccess = $successful ? 'LastSuccessfulSync = SYSDATETIME(),' : '';
    $setModeTime = '';

    if ($successful && $mode === 'full') {
        $setModeTime = 'LastFullSync = SYSDATETIME(),';
    } elseif ($successful && $mode === 'delta') {
        $setModeTime = 'LastDeltaSync = SYSDATETIME(),';
    }

    $sql = "MERGE dbo.SapOpenPurchaseOrderSyncState AS T
            USING (SELECT CAST(? AS NVARCHAR(80)) AS ScopeName) AS S
               ON T.ScopeName = S.ScopeName
            WHEN MATCHED THEN
                UPDATE SET
                    {$setSuccess}
                    {$setModeTime}
                    LastStatus = ?,
                    LastMessage = ?,
                    LastRowCount = ?,
                    UpdatedAt = SYSDATETIME()
            WHEN NOT MATCHED THEN
                INSERT (
                    ScopeName,
                    LastSuccessfulSync,
                    LastFullSync,
                    LastDeltaSync,
                    LastStatus,
                    LastMessage,
                    LastRowCount,
                    UpdatedAt
                )
                VALUES (
                    S.ScopeName,
                    " . ($successful ? 'SYSDATETIME()' : 'NULL') . ",
                    " . ($successful && $mode === 'full' ? 'SYSDATETIME()' : 'NULL') . ",
                    " . ($successful && $mode === 'delta' ? 'SYSDATETIME()' : 'NULL') . ",
                    ?, ?, ?, SYSDATETIME()
                );";

    $params = [
        PICKER_PO_CACHE_SCOPE,
        $status,
        substr((string)$message, 0, 1000),
        $rowCount,
        $status,
        substr((string)$message, 0, 1000),
        $rowCount
    ];

    return sqlsrv_query($conn, $sql, $params) !== false;
}

function picker_po_cache_count($conn)
{
    $row = fetch_one(
        $conn,
        "SELECT COUNT_BIG(*) AS RowCount
         FROM dbo.SapOpenPurchaseOrderCache"
    );

    return (int)($row['RowCount'] ?? 0);
}

function picker_po_build_sap_expressions($erp)
{
    $hasOpenQty = picker_po_has_column($erp, 'POR1', 'OpenQty');
    $hasDscription = picker_po_has_column($erp, 'POR1', 'Dscription');
    $hasWhsCode = picker_po_has_column($erp, 'POR1', 'WhsCode');
    $hasUnitMsr = picker_po_has_column($erp, 'POR1', 'unitMsr');
    $hasUomCode = picker_po_has_column($erp, 'POR1', 'UomCode');
    $hasNumPerMsr = picker_po_has_column($erp, 'POR1', 'NumPerMsr');
    $hasInvntryUom = picker_po_has_column($erp, 'OITM', 'InvntryUom');
    $hasCardCode = picker_po_has_column($erp, 'OPOR', 'CardCode');
    $hasCardName = picker_po_has_column($erp, 'OPOR', 'CardName');
    $hasDocDueDate = picker_po_has_column($erp, 'OPOR', 'DocDueDate');

    $openQtyExpr = $hasOpenQty ? 'L.OpenQty' : 'L.Quantity';
    $partNameExpr = $hasDscription
        ? "COALESCE(NULLIF(L.Dscription, ''), I.ItemName, '')"
        : "COALESCE(I.ItemName, '')";
    $whsExpr = $hasWhsCode ? 'L.WhsCode' : "CAST('' AS NVARCHAR(20))";
    $itemUomExpr = $hasInvntryUom ? 'I.InvntryUom' : "CAST('' AS NVARCHAR(50))";

    if ($hasUnitMsr) {
        $uomExpr = "COALESCE(NULLIF(L.unitMsr, ''), {$itemUomExpr}, '')";
    } elseif ($hasUomCode) {
        $uomExpr = "COALESCE(NULLIF(L.UomCode, ''), {$itemUomExpr}, '')";
    } else {
        $uomExpr = "COALESCE({$itemUomExpr}, '')";
    }

    return [
        'open_qty' => $openQtyExpr,
        'part_name' => $partNameExpr,
        'warehouse' => $whsExpr,
        'uom' => $uomExpr,
        'num_per_msr' => $hasNumPerMsr ? 'L.NumPerMsr' : '1',
        'card_code' => $hasCardCode ? 'H.CardCode' : "CAST('' AS NVARCHAR(50))",
        'card_name' => $hasCardName ? 'H.CardName' : "CAST('' AS NVARCHAR(200))",
        'due_date' => $hasDocDueDate ? 'H.DocDueDate' : 'H.DocDate',
        'has_canceled' => picker_po_has_column($erp, 'OPOR', 'CANCELED'),
        'has_doc_status' => picker_po_has_column($erp, 'OPOR', 'DocStatus'),
        'has_line_status' => picker_po_has_column($erp, 'POR1', 'LineStatus'),
        'has_update_date' => picker_po_has_column($erp, 'OPOR', 'UpdateDate'),
        'has_update_time' => picker_po_has_column($erp, 'OPOR', 'UpdateTS')
    ];
}

function picker_po_sap_session_low_priority($erp)
{
    sqlsrv_query($erp, "SET DEADLOCK_PRIORITY LOW; SET LOCK_TIMEOUT 5000;");
}

function picker_po_open_line_query(array $expr, array $docEntries = [])
{
    $where = [$expr['open_qty'] . ' > 0'];
    $params = [];

    if ($expr['has_canceled']) {
        $where[] = "ISNULL(H.CANCELED, 'N') = 'N'";
    }

    if ($expr['has_doc_status']) {
        $where[] = "H.DocStatus = 'O'";
    }

    if ($expr['has_line_status']) {
        $where[] = "L.LineStatus = 'O'";
    }

    if (!empty($docEntries)) {
        $where[] = 'H.DocEntry IN (' . implode(',', array_fill(0, count($docEntries), '?')) . ')';

        foreach ($docEntries as $docEntry) {
            $params[] = (int)$docEntry;
        }
    }

    $sql = "SELECT
                H.DocEntry,
                H.DocNum,
                H.DocDate,
                {$expr['due_date']} AS DocDueDate,
                {$expr['card_code']} AS CardCode,
                {$expr['card_name']} AS CardName,
                L.LineNum,
                L.ItemCode,
                {$expr['part_name']} AS PartName,
                L.Quantity AS OrderedQty,
                {$expr['open_qty']} AS OpenQty,
                {$expr['warehouse']} AS WhsCode,
                {$expr['uom']} AS UomName,
                {$expr['num_per_msr']} AS NumPerMsr
            FROM OPOR H
            INNER JOIN POR1 L
                ON L.DocEntry = H.DocEntry
            LEFT JOIN OITM I
                ON I.ItemCode = L.ItemCode
            WHERE " . implode(' AND ', $where);

    return [$sql, $params];
}

function picker_po_normalize_sap_row(array $row)
{
    return [
        'DocEntry' => (int)($row['DocEntry'] ?? 0),
        'LineNum' => (int)($row['LineNum'] ?? 0),
        'DocNum' => (int)($row['DocNum'] ?? 0),
        'DocDate' => picker_po_dt($row['DocDate'] ?? null),
        'DueDate' => picker_po_dt($row['DocDueDate'] ?? null),
        'VendorCode' => trim((string)($row['CardCode'] ?? '')),
        'VendorName' => trim((string)($row['CardName'] ?? '')),
        'ItemCode' => trim((string)($row['ItemCode'] ?? '')),
        'PartName' => trim((string)($row['PartName'] ?? '')),
        'OrderedQty' => (float)($row['OrderedQty'] ?? 0),
        'OpenQty' => (float)($row['OpenQty'] ?? 0),
        'WarehouseCode' => trim((string)($row['WhsCode'] ?? '')),
        'UomName' => trim((string)($row['UomName'] ?? '')),
        'NumPerMsr' => (float)($row['NumPerMsr'] ?? 1)
    ];
}

function picker_po_insert_rows($conn, $table, array $rows)
{
    if (empty($rows)) {
        return true;
    }

    $allowedTables = [
        'dbo.SapOpenPurchaseOrderCache',
        'dbo.SapOpenPurchaseOrderCacheStage'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    foreach (array_chunk($rows, PICKER_PO_INSERT_BATCH_SIZE) as $batch) {
        $values = [];
        $params = [];

        foreach ($batch as $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())';
            array_push(
                $params,
                (int)$row['DocEntry'],
                (int)$row['LineNum'],
                (int)$row['DocNum'],
                $row['DocDate'] !== '' ? $row['DocDate'] : null,
                $row['DueDate'] !== '' ? $row['DueDate'] : null,
                (string)$row['VendorCode'],
                (string)$row['VendorName'],
                (string)$row['ItemCode'],
                (string)$row['PartName'],
                (float)$row['OrderedQty'],
                (float)$row['OpenQty'],
                (string)$row['WarehouseCode'],
                (string)$row['UomName'],
                (float)$row['NumPerMsr']
            );
        }

        $sql = "INSERT INTO {$table} (
                    DocEntry,
                    LineNum,
                    DocNum,
                    DocDate,
                    DueDate,
                    VendorCode,
                    VendorName,
                    ItemCode,
                    PartName,
                    OrderedQty,
                    OpenQty,
                    WarehouseCode,
                    UomName,
                    NumPerMsr,
                    CachedAt
                ) VALUES " . implode(',', $values);

        if (sqlsrv_query($conn, $sql, $params) === false) {
            return false;
        }
    }

    return true;
}

function picker_po_stream_sap_rows_to_table($erp, $whp, $table, $sql, array $params)
{
    $stmt = sqlsrv_query($erp, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(picker_po_sql_error('Unable to read open purchase orders from SAP.'));
    }

    $batch = [];
    $rowCount = 0;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $normalized = picker_po_normalize_sap_row($row);

        if ($normalized['OpenQty'] <= 0) {
            continue;
        }

        $batch[] = $normalized;
        $rowCount++;

        if (count($batch) >= PICKER_PO_INSERT_BATCH_SIZE) {
            if (!picker_po_insert_rows($whp, $table, $batch)) {
                throw new RuntimeException(picker_po_sql_error('Unable to write the local open PO cache.'));
            }

            $batch = [];
        }
    }

    if (!empty($batch) && !picker_po_insert_rows($whp, $table, $batch)) {
        throw new RuntimeException(picker_po_sql_error('Unable to write the local open PO cache.'));
    }

    return $rowCount;
}

function picker_po_full_sync($erp, $whp, array $expr)
{
    $started = microtime(true);
    picker_po_update_sync_state($whp, 'full', 'RUNNING', 'Full open PO cache refresh is running.', null, false);

    if (sqlsrv_query($whp, 'DELETE FROM dbo.SapOpenPurchaseOrderCacheStage') === false) {
        throw new RuntimeException(picker_po_sql_error('Unable to clear the open PO staging table.'));
    }

    list($sql, $params) = picker_po_open_line_query($expr);
    $rowCount = picker_po_stream_sap_rows_to_table(
        $erp,
        $whp,
        'dbo.SapOpenPurchaseOrderCacheStage',
        $sql,
        $params
    );

    if (!sqlsrv_begin_transaction($whp)) {
        throw new RuntimeException(picker_po_sql_error('Unable to begin the local cache replacement transaction.'));
    }

    try {
        if (sqlsrv_query($whp, 'DELETE FROM dbo.SapOpenPurchaseOrderCache') === false) {
            throw new RuntimeException(picker_po_sql_error('Unable to clear the previous open PO cache.'));
        }

        $copySql = "INSERT INTO dbo.SapOpenPurchaseOrderCache (
                        DocEntry, LineNum, DocNum, DocDate, DueDate,
                        VendorCode, VendorName, ItemCode, PartName,
                        OrderedQty, OpenQty, WarehouseCode, UomName,
                        NumPerMsr, CachedAt
                    )
                    SELECT
                        DocEntry, LineNum, DocNum, DocDate, DueDate,
                        VendorCode, VendorName, ItemCode, PartName,
                        OrderedQty, OpenQty, WarehouseCode, UomName,
                        NumPerMsr, CachedAt
                    FROM dbo.SapOpenPurchaseOrderCacheStage;";

        if (sqlsrv_query($whp, $copySql) === false) {
            throw new RuntimeException(picker_po_sql_error('Unable to replace the open PO cache from staging.'));
        }

        $seconds = round(microtime(true) - $started, 3);
        $message = "Full open PO cache refreshed in {$seconds}s.";

        if (!picker_po_update_sync_state($whp, 'full', 'SUCCESS', $message, $rowCount, true)) {
            throw new RuntimeException(picker_po_sql_error('Unable to update the open PO sync state.'));
        }

        if (!sqlsrv_commit($whp)) {
            throw new RuntimeException(picker_po_sql_error('Unable to commit the open PO cache replacement.'));
        }

        sqlsrv_query($whp, 'DELETE FROM dbo.SapOpenPurchaseOrderCacheStage');

        return [
            'ok' => true,
            'mode' => 'full',
            'message' => $message,
            'row_count' => $rowCount,
            'seconds' => $seconds
        ];
    } catch (Throwable $e) {
        sqlsrv_rollback($whp);
        throw $e;
    }
}

function picker_po_changed_doc_entries($erp, array $expr, $sinceDateTime)
{
    if (!$expr['has_update_date']) {
        return null;
    }

    $since = new DateTimeImmutable($sinceDateTime ?: '-1 day');
    $since = $since->modify('-' . PICKER_PO_DELTA_OVERLAP_MINUTES . ' minutes');
    $sinceDate = $since->format('Y-m-d');
    $sinceTime = (int)$since->format('His');

    if ($expr['has_update_time']) {
        $sql = "SELECT H.DocEntry
                FROM OPOR H
                WHERE H.UpdateDate > ?
                   OR (H.UpdateDate = ? AND ISNULL(H.UpdateTS, 0) >= ?)";
        $params = [$sinceDate, $sinceDate, $sinceTime];
    } else {
        $sql = "SELECT H.DocEntry
                FROM OPOR H
                WHERE H.UpdateDate >= ?";
        $params = [$sinceDate];
    }

    $stmt = sqlsrv_query($erp, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(picker_po_sql_error('Unable to identify changed SAP purchase orders.'));
    }

    $entries = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $docEntry = (int)($row['DocEntry'] ?? 0);

        if ($docEntry > 0) {
            $entries[$docEntry] = $docEntry;
        }
    }

    return array_values($entries);
}

function picker_po_fetch_sap_rows($erp, $sql, array $params)
{
    $stmt = sqlsrv_query($erp, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(picker_po_sql_error('Unable to retrieve changed open PO lines from SAP.'));
    }

    $rows = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $normalized = picker_po_normalize_sap_row($row);

        if ($normalized['OpenQty'] > 0) {
            $rows[] = $normalized;
        }
    }

    return $rows;
}

function picker_po_delete_cached_documents($whp, array $docEntries)
{
    if (empty($docEntries)) {
        return true;
    }

    $placeholders = implode(',', array_fill(0, count($docEntries), '?'));
    $params = array_map('intval', $docEntries);

    return sqlsrv_query(
        $whp,
        "DELETE FROM dbo.SapOpenPurchaseOrderCache
         WHERE DocEntry IN ({$placeholders})",
        $params
    ) !== false;
}

function picker_po_delta_sync($erp, $whp, array $expr, array $state)
{
    $started = microtime(true);
    $lastSuccessful = picker_po_datetime($state['LastSuccessfulSync'] ?? null);

    if ($lastSuccessful === '') {
        return picker_po_full_sync($erp, $whp, $expr);
    }

    $changedDocs = picker_po_changed_doc_entries($erp, $expr, $lastSuccessful);

    if ($changedDocs === null) {
        return picker_po_full_sync($erp, $whp, $expr);
    }

    picker_po_update_sync_state($whp, 'delta', 'RUNNING', 'Incremental open PO cache refresh is running.', null, false);

    $insertedRows = 0;

    foreach (array_chunk($changedDocs, PICKER_PO_DOC_CHUNK_SIZE) as $docChunk) {
        list($sql, $params) = picker_po_open_line_query($expr, $docChunk);
        $openRows = picker_po_fetch_sap_rows($erp, $sql, $params);

        if (!sqlsrv_begin_transaction($whp)) {
            throw new RuntimeException(picker_po_sql_error('Unable to begin an incremental open PO cache transaction.'));
        }

        try {
            if (!picker_po_delete_cached_documents($whp, $docChunk)) {
                throw new RuntimeException(picker_po_sql_error('Unable to remove outdated cached PO lines.'));
            }

            if (!picker_po_insert_rows($whp, 'dbo.SapOpenPurchaseOrderCache', $openRows)) {
                throw new RuntimeException(picker_po_sql_error('Unable to insert changed open PO lines into the cache.'));
            }

            if (!sqlsrv_commit($whp)) {
                throw new RuntimeException(picker_po_sql_error('Unable to commit an incremental open PO cache transaction.'));
            }

            $insertedRows += count($openRows);
        } catch (Throwable $e) {
            sqlsrv_rollback($whp);
            throw $e;
        }
    }

    $seconds = round(microtime(true) - $started, 3);
    $message = count($changedDocs) === 0
        ? "No SAP purchase orders changed. Delta check completed in {$seconds}s."
        : count($changedDocs) . " changed PO document(s) synchronized in {$seconds}s.";

    picker_po_update_sync_state($whp, 'delta', 'SUCCESS', $message, $insertedRows, true);

    return [
        'ok' => true,
        'mode' => 'delta',
        'message' => $message,
        'changed_documents' => count($changedDocs),
        'row_count' => $insertedRows,
        'seconds' => $seconds
    ];
}

function picker_po_choose_sync_mode($whp, $requestedMode)
{
    $requestedMode = strtolower(trim((string)$requestedMode));

    if (in_array($requestedMode, ['full', 'delta'], true)) {
        return $requestedMode;
    }

    $cacheCount = picker_po_cache_count($whp);
    $state = picker_po_sync_state($whp) ?: [];
    $lastFull = $state['LastFullSync'] ?? null;

    if ($cacheCount === 0 || !$lastFull) {
        return 'full';
    }

    $lastFullTimestamp = $lastFull instanceof DateTimeInterface
        ? $lastFull->getTimestamp()
        : strtotime((string)$lastFull);
    $fullExpired = !$lastFullTimestamp
        || $lastFullTimestamp < time() - (PICKER_PO_FULL_REFRESH_HOURS * 3600);
    $hour = (int)date('G');
    $insideFullWindow = $hour >= PICKER_PO_FULL_REFRESH_START_HOUR
        && $hour < PICKER_PO_FULL_REFRESH_END_HOUR;

    return $fullExpired && $insideFullWindow ? 'full' : 'delta';
}

function picker_po_run_sync($whp, $requestedMode)
{
    $erp = get_erp_connection();

    if (!$erp) {
        throw new RuntimeException('Unable to connect to the SAP company database.');
    }

    $hasOpor = fetch_one(
        $erp,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_NAME = 'OPOR'"
    );
    $hasPor1 = fetch_one(
        $erp,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_NAME = 'POR1'"
    );

    if (!$hasOpor || !$hasPor1) {
        throw new RuntimeException('SAP purchase order tables OPOR/POR1 were not found.');
    }

    picker_po_sap_session_low_priority($erp);
    $expr = picker_po_build_sap_expressions($erp);
    $mode = picker_po_choose_sync_mode($whp, $requestedMode);
    $state = picker_po_sync_state($whp) ?: [];

    return $mode === 'full'
        ? picker_po_full_sync($erp, $whp, $expr)
        : picker_po_delta_sync($erp, $whp, $expr, $state);
}

function picker_po_cache_state_payload($whp)
{
    $state = picker_po_sync_state($whp) ?: [];

    return [
        'last_successful_sync' => picker_po_datetime($state['LastSuccessfulSync'] ?? null),
        'last_full_sync' => picker_po_datetime($state['LastFullSync'] ?? null),
        'last_delta_sync' => picker_po_datetime($state['LastDeltaSync'] ?? null),
        'status' => (string)($state['LastStatus'] ?? ''),
        'message' => (string)($state['LastMessage'] ?? ''),
        'row_count' => (int)($state['LastRowCount'] ?? 0)
    ];
}

function picker_po_read_cache($whp, $search, $max)
{
    $max = max(1, min(500, (int)$max));
    $fetchLimit = $max + 1;
    $params = [];

    if ($search !== '') {
        $escaped = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $search);
        $like = '%' . $escaped . '%';

        $sql = "SELECT TOP {$fetchLimit}
                    DocEntry,
                    LineNum,
                    DocNum,
                    DocDate,
                    DueDate,
                    VendorCode,
                    VendorName,
                    ItemCode,
                    PartName,
                    OrderedQty,
                    OpenQty,
                    WarehouseCode,
                    UomName,
                    NumPerMsr,
                    CachedAt
                FROM dbo.SapOpenPurchaseOrderCache
                WHERE CAST(DocNum AS NVARCHAR(40)) LIKE ?
                   OR VendorCode LIKE ?
                   OR VendorName LIKE ?
                   OR ItemCode LIKE ?
                   OR PartName LIKE ?
                   OR WarehouseCode LIKE ?
                ORDER BY DocDate DESC, DocNum DESC, DocEntry DESC, LineNum ASC";
        $params = [$like, $like, $like, $like, $like, $like];
    } else {
        $documentLimit = max(1, min(50, $max));
        $sql = "WITH LatestDocuments AS (
                    SELECT TOP {$documentLimit}
                        DocEntry,
                        MAX(DocDate) AS DocDate,
                        MAX(DocNum) AS DocNum
                    FROM dbo.SapOpenPurchaseOrderCache
                    GROUP BY DocEntry
                    ORDER BY MAX(DocDate) DESC, MAX(DocNum) DESC, DocEntry DESC
                )
                SELECT
                    C.DocEntry,
                    C.LineNum,
                    C.DocNum,
                    C.DocDate,
                    C.DueDate,
                    C.VendorCode,
                    C.VendorName,
                    C.ItemCode,
                    C.PartName,
                    C.OrderedQty,
                    C.OpenQty,
                    C.WarehouseCode,
                    C.UomName,
                    C.NumPerMsr,
                    C.CachedAt
                FROM dbo.SapOpenPurchaseOrderCache C
                INNER JOIN LatestDocuments D
                    ON D.DocEntry = C.DocEntry
                ORDER BY C.DocDate DESC, C.DocNum DESC, C.DocEntry DESC, C.LineNum ASC";
    }

    $stmt = sqlsrv_query($whp, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(picker_po_sql_error('Unable to read the local open PO cache.'));
    }

    $rows = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

    $limited = $search !== '' && count($rows) > $max;

    if ($limited) {
        $rows = array_slice($rows, 0, $max);
    }

    $documents = [];
    $lines = [];

    foreach ($rows as $row) {
        $docEntry = (int)$row['DocEntry'];
        $docKey = (string)$docEntry;
        $openQty = (float)$row['OpenQty'];

        $line = [
            'doc_entry' => $docEntry,
            'doc_num' => (string)$row['DocNum'],
            'doc_date' => picker_po_dt($row['DocDate']),
            'due_date' => picker_po_dt($row['DueDate']),
            'vendor_code' => (string)$row['VendorCode'],
            'vendor_name' => (string)$row['VendorName'],
            'line_num' => (int)$row['LineNum'],
            'item_code' => (string)$row['ItemCode'],
            'part_name' => (string)$row['PartName'],
            'ordered_qty' => (float)$row['OrderedQty'],
            'open_qty' => $openQty,
            'warehouse_code' => (string)$row['WarehouseCode'],
            'uom' => (string)$row['UomName'],
            'num_per_msr' => (float)$row['NumPerMsr']
        ];

        $lines[] = $line;

        if (!isset($documents[$docKey])) {
            $documents[$docKey] = [
                'doc_entry' => $docEntry,
                'doc_num' => (string)$row['DocNum'],
                'doc_date' => picker_po_dt($row['DocDate']),
                'due_date' => picker_po_dt($row['DueDate']),
                'vendor_code' => (string)$row['VendorCode'],
                'vendor_name' => (string)$row['VendorName'],
                'line_count' => 0,
                'open_qty' => 0.0,
                'lines' => []
            ];
        }

        $documents[$docKey]['line_count']++;
        $documents[$docKey]['open_qty'] += $openQty;
        $documents[$docKey]['lines'][] = $line;
    }

    return [
        'ok' => true,
        'search' => $search,
        'limited' => $limited,
        'count' => count($documents),
        'documents' => array_values($documents),
        'lines' => $lines,
        '_cache' => array_merge(
            [
                'hit' => true,
                'source' => 'dbo.SapOpenPurchaseOrderCache',
                'total_cached_lines' => picker_po_cache_count($whp)
            ],
            picker_po_cache_state_payload($whp)
        )
    ];
}

$search = trim((string)($_GET['q'] ?? ''));
$max = (int)($_GET['max'] ?? ($search !== '' ? 50 : 20));
$whp = get_whpokayoke_connection();

if (!$whp) {
    picker_po_json_out([
        'ok' => false,
        'message' => 'Unable to connect to the Warehouse Poka-Yoke database.',
        'documents' => [],
        'lines' => []
    ], 500);
}

if (!picker_po_cache_ready($whp)) {
    picker_po_json_out([
        'ok' => false,
        'message' => 'Open PO cache tables are missing. Run database/open_po_cache_schema.sql first.',
        'documents' => [],
        'lines' => []
    ], 500);
}

if (PHP_SAPI === 'cli' && sap_cache_should_refresh()) {
    $requestedMode = trim((string)($_GET['sync'] ?? 'auto'));

    try {
        picker_po_json_out(picker_po_run_sync($whp, $requestedMode));
    } catch (Throwable $e) {
        picker_po_update_sync_state(
            $whp,
            $requestedMode === 'full' ? 'full' : 'delta',
            'FAILED',
            $e->getMessage(),
            null,
            false
        );

        picker_po_json_out([
            'ok' => false,
            'mode' => $requestedMode,
            'message' => $e->getMessage(),
            'row_count' => 0
        ], 500);
    }
}

try {
    picker_po_json_out(picker_po_read_cache($whp, $search, $max));
} catch (Throwable $e) {
    picker_po_json_out([
        'ok' => false,
        'message' => $e->getMessage(),
        'search' => $search,
        'documents' => [],
        'lines' => [],
        '_cache' => picker_po_cache_state_payload($whp)
    ], 500);
}
