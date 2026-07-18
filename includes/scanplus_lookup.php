<?php

const SCANPLUS_CACHE_TTL_SECONDS = 300;

function scanplus_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
        [$table]
    );
}

function scanplus_has_column($conn, $table, $column)
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

function scanplus_key($docEntry, $lineNum, $itemCode)
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

function scanplus_lot_key($docEntry, $lineNum, $itemCode, $lotNo)
{
    $key = scanplus_key($docEntry, $lineNum, $itemCode);
    $lotNo = strtoupper(trim((string)$lotNo));

    if ($key === '' || $lotNo === '') {
        return '';
    }

    return $key . '|' . $lotNo;
}

function scanplus_datetime_text($dateValue, $timeValue = null)
{
    if ($dateValue instanceof DateTimeInterface) {
        $dateText = $dateValue->format('Y-m-d');
    } else {
        $dateText = trim((string)$dateValue);
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

function scanplus_cache_ensure($conn)
{
    $create = sqlsrv_query(
        $conn,
        "IF OBJECT_ID('dbo.RawmatTraceScanPlusCache', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.RawmatTraceScanPlusCache (
                CacheID INT IDENTITY(1,1) PRIMARY KEY,
                SAP_IT_DocEntry INT NOT NULL,
                SAP_IT_LineNum INT NULL,
                ItemCode NVARCHAR(50) NOT NULL,
                LotNo NVARCHAR(80) NULL,
                ReceivedLotNo NVARCHAR(80) NULL,
                ScanStatus NVARCHAR(50) NULL,
                ReceivedQty DECIMAL(18,3) NULL,
                BarcodeUser NVARCHAR(120) NULL,
                ReceivedAt DATETIME NULL,
                LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
            );
         END"
    );

    if ($create === false) {
        return false;
    }

    sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.RawmatTraceScanPlusCache', 'ReceivedLotNo') IS NULL
         BEGIN
            ALTER TABLE dbo.RawmatTraceScanPlusCache ADD ReceivedLotNo NVARCHAR(80) NULL;
         END"
    );

    sqlsrv_query(
        $conn,
        "IF NOT EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'IX_RawmatTraceScanPlusCache_Lookup'
              AND object_id = OBJECT_ID('dbo.RawmatTraceScanPlusCache')
         )
         BEGIN
            CREATE INDEX IX_RawmatTraceScanPlusCache_Lookup
            ON dbo.RawmatTraceScanPlusCache(SAP_IT_DocEntry, SAP_IT_LineNum, ItemCode, LotNo, LastSyncedAt);
         END"
    );

    return scanplus_has_table($conn, 'RawmatTraceScanPlusCache');
}

function scanplus_cache_read($conn, array $refs, $ttlSeconds = SCANPLUS_CACHE_TTL_SECONDS)
{
    if (empty($refs) || !scanplus_cache_ensure($conn)) {
        return ['rows' => [], 'fresh_keys' => []];
    }

    $freshCutoff = date('Y-m-d H:i:s', time() - max(1, (int)$ttlSeconds));
    $result = [];
    $freshKeys = [];
    $normalizedRefs = [];

    foreach (array_values($refs) as $idx => $ref) {
        $scanKey = scanplus_key($ref['doc_entry'] ?? 0, $ref['line_num'] ?? null, $ref['item_code'] ?? '');

        if ($scanKey === '') {
            continue;
        }

        $normalizedRefs[$idx] = [
            'doc_entry' => (int)($ref['doc_entry'] ?? 0),
            'line_num' => $ref['line_num'] === null || trim((string)$ref['line_num']) === '' ? null : (int)$ref['line_num'],
            'item_code' => trim((string)($ref['item_code'] ?? '')),
            'lot_no' => trim((string)($ref['lot_no'] ?? '')),
            'scan_key' => $scanKey
        ];
    }

    if (empty($normalizedRefs)) {
        return ['rows' => [], 'fresh_keys' => []];
    }

    $cacheRows = [];

    foreach (array_chunk(array_keys($normalizedRefs), 350) as $chunkIndexes) {
        $chunkRows = [];
        $chunkParams = [];

        foreach ($chunkIndexes as $idx) {
            $ref = $normalizedRefs[$idx];
            $chunkRows[] = 'SELECT ? AS RefIdx, ? AS SAP_IT_DocEntry, ? AS SAP_IT_LineNum, ? AS ItemCode, ? AS LotNo';
            array_push($chunkParams, $idx, $ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
        }

        $chunkCacheRows = fetch_all(
            $conn,
            "WITH Ref AS (
                " . implode("\nUNION ALL\n", $chunkRows) . "
             ),
             RankedCache AS (
                SELECT
                    Ref.RefIdx,
                    C.ScanStatus,
                    C.ReceivedLotNo,
                    C.ReceivedQty,
                    C.BarcodeUser,
                    CONVERT(varchar(19), C.ReceivedAt, 120) AS ReceivedAt,
                    CONVERT(varchar(19), C.LastSyncedAt, 120) AS LastSyncedAt,
                    ROW_NUMBER() OVER (
                        PARTITION BY Ref.RefIdx
                        ORDER BY
                            CASE
                                WHEN ISNULL(C.LotNo, '') = ISNULL(Ref.LotNo, '') THEN 0
                                WHEN ISNULL(C.ReceivedLotNo, '') = ISNULL(Ref.LotNo, '') THEN 1
                                WHEN ISNULL(C.LotNo, '') = '' THEN 2
                                ELSE 3
                            END,
                            C.LastSyncedAt DESC
                    ) AS RowNum
                FROM Ref
                INNER JOIN dbo.RawmatTraceScanPlusCache C
                    ON C.SAP_IT_DocEntry = Ref.SAP_IT_DocEntry
                   AND ISNULL(C.SAP_IT_LineNum, -1) = ISNULL(Ref.SAP_IT_LineNum, -1)
                   AND C.ItemCode = Ref.ItemCode
                   AND (
                        ISNULL(Ref.LotNo, '') = ''
                        OR ISNULL(C.LotNo, '') = ISNULL(Ref.LotNo, '')
                        OR ISNULL(C.ReceivedLotNo, '') = ISNULL(Ref.LotNo, '')
                   )
             )
             SELECT
                RefIdx,
                ScanStatus,
                ReceivedLotNo,
                ReceivedQty,
                BarcodeUser,
                ReceivedAt,
                LastSyncedAt
             FROM RankedCache
             WHERE RowNum = 1",
            $chunkParams
        );

        foreach ($chunkCacheRows as $chunkCacheRow) {
            $cacheRows[] = $chunkCacheRow;
        }
    }

    foreach ($cacheRows as $cacheRow) {
        $idx = (int)($cacheRow['RefIdx'] ?? -1);

        if (!isset($normalizedRefs[$idx])) {
            continue;
        }

        $ref = $normalizedRefs[$idx];
        $scan = [
            'scan_status' => trim((string)($cacheRow['ScanStatus'] ?? '')),
            'received_lot_no' => trim((string)($cacheRow['ReceivedLotNo'] ?? '')),
            'received_qty' => $cacheRow['ReceivedQty'] ?? '',
            'barcode_user' => trim((string)($cacheRow['BarcodeUser'] ?? '')),
            'received_at' => trim((string)($cacheRow['ReceivedAt'] ?? ''))
        ];

        $scanKey = $ref['scan_key'];
        $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
        $targetKey = $scanLotKey !== '' ? $scanLotKey : $scanKey;
        $result[$targetKey] = $scan;

        if ($scanLotKey !== '') {
            $result[$scanKey] = $scan;
        }

        if (trim((string)($cacheRow['LastSyncedAt'] ?? '')) >= $freshCutoff) {
            $freshKeys[$targetKey] = true;
            $freshKeys[$scanKey] = true;
        }
    }

    return ['rows' => $result, 'fresh_keys' => $freshKeys];
}

function scanplus_cache_write($conn, array $ref, ?array $scan)
{
    static $cacheReady = false;

    if (!$cacheReady) {
        $cacheReady = scanplus_cache_ensure($conn);
    }

    if (!$cacheReady) {
        return false;
    }

    $docEntry = (int)($ref['doc_entry'] ?? 0);
    $lineNum = $ref['line_num'] === null || trim((string)$ref['line_num']) === '' ? null : (int)$ref['line_num'];
    $itemCode = trim((string)($ref['item_code'] ?? ''));
    $lotNo = trim((string)($ref['lot_no'] ?? ''));

    if (scanplus_key($docEntry, $lineNum, $itemCode) === '') {
        return false;
    }

    $hasScan = $scan !== null;
    $scanStatus = $scan['scan_status'] ?? null;
    $receivedLotNo = $scan['received_lot_no'] ?? null;
    $receivedQty = $scan['received_qty'] ?? null;
    $barcodeUser = $scan['barcode_user'] ?? null;
    $receivedAt = $scan['received_at'] ?? null;

    /*
     * Only overwrite an existing cache row when this lookup actually produced
     * data, or when the existing row never had real received data to begin
     * with. Without this guard, a lookup that simply fails to find the exact
     * (DocEntry, LineNum, ItemCode, LotNo) match on a later run would blank
     * out previously-good scan data instead of leaving it alone.
     */
    return sqlsrv_query(
        $conn,
        "MERGE dbo.RawmatTraceScanPlusCache AS T
         USING (
            SELECT
                ? AS SAP_IT_DocEntry,
                ? AS SAP_IT_LineNum,
                ? AS ItemCode,
                ? AS LotNo
         ) AS S
         ON
            T.SAP_IT_DocEntry = S.SAP_IT_DocEntry
            AND ISNULL(T.SAP_IT_LineNum, -1) = ISNULL(S.SAP_IT_LineNum, -1)
            AND T.ItemCode = S.ItemCode
            AND ISNULL(T.LotNo, '') = ISNULL(S.LotNo, '')
         WHEN MATCHED AND (? = 1 OR ISNULL(T.ReceivedQty, 0) <= 0) THEN
            UPDATE SET
                ScanStatus = ?,
                ReceivedLotNo = ?,
                ReceivedQty = ?,
                BarcodeUser = ?,
                ReceivedAt = ?,
                LastSyncedAt = GETDATE()
         WHEN NOT MATCHED THEN
            INSERT (
                SAP_IT_DocEntry,
                SAP_IT_LineNum,
                ItemCode,
                LotNo,
                ScanStatus,
                ReceivedLotNo,
                ReceivedQty,
                BarcodeUser,
                ReceivedAt,
                LastSyncedAt
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE());",
        [
            $docEntry,
            $lineNum,
            $itemCode,
            $lotNo,

            $hasScan ? 1 : 0,
            $scanStatus,
            $receivedLotNo,
            $receivedQty,
            $barcodeUser,
            $receivedAt,

            $docEntry,
            $lineNum,
            $itemCode,
            $lotNo,
            $scanStatus,
            $receivedLotNo,
            $receivedQty,
            $barcodeUser,
            $receivedAt
        ]
    ) !== false;
}

function scanplus_lookup_by_itr_lines($erp, array $refs)
{
    $refTuples = [];

    foreach ($refs as $ref) {
        $key = scanplus_key(
            $ref['doc_entry'] ?? 0,
            $ref['line_num'] ?? null,
            $ref['item_code'] ?? ''
        );

        if ($key === '') {
            continue;
        }

        $refTuples[$key] = [
            (int)$ref['doc_entry'],
            (int)$ref['line_num'],
            trim((string)$ref['item_code'])
        ];
    }

    if (empty($refTuples)) {
        return [];
    }

    if (
        !scanplus_has_table($erp, 'OWTR') ||
        !scanplus_has_table($erp, 'WTR1') ||
        !scanplus_has_table($erp, 'WTQ1') ||
        !scanplus_has_column($erp, 'WTR1', 'BaseType') ||
        !scanplus_has_column($erp, 'WTR1', 'BaseEntry') ||
        !scanplus_has_column($erp, 'WTR1', 'BaseLine')
    ) {
        return [];
    }

    $hasCanceled = scanplus_has_column($erp, 'OWTR', 'CANCELED');
    $hasUserSign = scanplus_has_column($erp, 'OWTR', 'UserSign');
    $hasBarcodeUser = scanplus_has_column($erp, 'OWTR', 'U_BarcodeUser');
    $hasScanDateTime = scanplus_has_column($erp, 'OWTR', 'U_ScanDateTime');
    $hasScanTime = scanplus_has_column($erp, 'OWTR', 'U_ScanTime');
    $hasCreateDate = scanplus_has_column($erp, 'OWTR', 'CreateDate');
    $hasCreateTS = scanplus_has_column($erp, 'OWTR', 'CreateTS');
    $hasDocDate = scanplus_has_column($erp, 'OWTR', 'DocDate');
    $hasLineStatus = scanplus_has_column($erp, 'WTQ1', 'LineStatus');
    $hasOpenQty = scanplus_has_column($erp, 'WTQ1', 'OpenQty');
    $hasBatchJoin =
        scanplus_has_table($erp, 'IBT1') &&
        scanplus_has_column($erp, 'IBT1', 'BaseType') &&
        scanplus_has_column($erp, 'IBT1', 'BaseEntry') &&
        scanplus_has_column($erp, 'IBT1', 'BaseLinNum') &&
        scanplus_has_column($erp, 'IBT1', 'ItemCode') &&
        scanplus_has_column($erp, 'IBT1', 'BatchNum') &&
        scanplus_has_column($erp, 'IBT1', 'Quantity');
    $hasInventoryLogBatchJoin =
        !$hasBatchJoin &&
        scanplus_has_table($erp, 'OITL') &&
        scanplus_has_table($erp, 'ITL1') &&
        scanplus_has_table($erp, 'OBTN') &&
        scanplus_has_column($erp, 'OITL', 'LogEntry') &&
        scanplus_has_column($erp, 'OITL', 'DocType') &&
        scanplus_has_column($erp, 'OITL', 'DocEntry') &&
        scanplus_has_column($erp, 'OITL', 'DocLine') &&
        scanplus_has_column($erp, 'ITL1', 'LogEntry') &&
        scanplus_has_column($erp, 'ITL1', 'ItemCode') &&
        scanplus_has_column($erp, 'ITL1', 'SysNumber') &&
        scanplus_has_column($erp, 'ITL1', 'Quantity') &&
        scanplus_has_column($erp, 'OBTN', 'ItemCode') &&
        scanplus_has_column($erp, 'OBTN', 'SysNumber') &&
        scanplus_has_column($erp, 'OBTN', 'DistNumber');

    $scanDateExpr = $hasScanDateTime
        ? 'T.U_ScanDateTime'
        : ($hasCreateDate ? 'T.CreateDate' : ($hasDocDate ? 'T.DocDate' : 'CAST(NULL AS DATETIME)'));
    $scanTimeExpr = $hasScanTime
        ? 'T.U_ScanTime'
        : ($hasCreateTS ? 'T.CreateTS' : 'CAST(NULL AS INT)');
    $lineStatusExpr = $hasLineStatus ? 'R.LineStatus' : "CAST('' AS NVARCHAR(10))";
    $openQtyExpr = $hasOpenQty ? 'R.OpenQty' : 'CAST(NULL AS DECIMAL(18,3))';
    $userJoin = '';
    $scannedByParts = [];

    if ($hasBarcodeUser) {
        $scannedByParts[] = "NULLIF(CAST(T.U_BarcodeUser AS NVARCHAR(120)), '')";
    }

    if ($hasUserSign) {
        $hasOusr = scanplus_has_table($erp, 'OUSR') &&
            scanplus_has_column($erp, 'OUSR', 'USERID');

        if ($hasOusr) {
            $nameParts = [];

            if (scanplus_has_column($erp, 'OUSR', 'USER_CODE')) {
                $nameParts[] = "NULLIF(CAST(U1.USER_CODE AS NVARCHAR(120)), '')";
            }

            if (scanplus_has_column($erp, 'OUSR', 'U_NAME')) {
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

    if ($hasBatchJoin) {
        $lotSelect = "COALESCE(B.BatchNum, '') AS ReceivedLotNo,
            CASE WHEN T.DocEntry IS NULL THEN 0
                 WHEN B.BatchNum IS NULL THEN ABS(ISNULL(L.Quantity, 0))
                 ELSE ABS(ISNULL(B.Quantity, 0))
            END AS ReceivedQty";
        $lotJoin = "LEFT JOIN IBT1 B
           ON B.BaseType = 67
          AND B.BaseEntry = T.DocEntry
          AND B.BaseLinNum = L.LineNum
          AND B.ItemCode = L.ItemCode";
    } elseif ($hasInventoryLogBatchJoin) {
        $lotSelect = "COALESCE(BT.DistNumber, '') AS ReceivedLotNo,
            CASE WHEN T.DocEntry IS NULL THEN 0
                 WHEN BT.DistNumber IS NULL THEN ABS(ISNULL(L.Quantity, 0))
                 ELSE ABS(ISNULL(BL.Quantity, 0))
            END AS ReceivedQty";
        $lotJoin = "LEFT JOIN OITL IL
           ON IL.DocType = 67
          AND IL.DocEntry = T.DocEntry
          AND IL.DocLine = L.LineNum
        LEFT JOIN ITL1 BL ON BL.LogEntry = IL.LogEntry AND BL.ItemCode = L.ItemCode
        LEFT JOIN OBTN BT ON BT.ItemCode = BL.ItemCode AND BT.SysNumber = BL.SysNumber";
    } else {
        $lotSelect = "CAST('' AS NVARCHAR(80)) AS ReceivedLotNo, L.Quantity AS ReceivedQty";
        $lotJoin = '';
    }

    $transferJoinCanceledSql = $hasCanceled ? " AND ISNULL(T.CANCELED, 'N') = 'N'" : '';
    $result = [];
    $rows = [];

    /*
     * Join against an exact (DocEntry, LineNum, ItemCode) tuple list instead of
     * filtering WTQ1 by DocEntry alone. Filtering by DocEntry only pulls in every
     * line of every referenced document (fine for single-line docs, very expensive
     * for large multi-line documents), which was driving the heavy CXPACKET/CPU
     * usage seen in sp_who2 for this query. MAXDOP 1 also avoids parallel-plan
     * overhead for what is fundamentally a narrow, index-driven point lookup.
     */
    foreach (array_chunk(array_values($refTuples), 350) as $tupleChunk) {
        $refRows = [];
        $params = [];

        foreach ($tupleChunk as $tuple) {
            $refRows[] = 'SELECT ? AS DocEntry, ? AS LineNum, ? AS ItemCode';
            array_push($params, $tuple[0], $tuple[1], $tuple[2]);
        }

        $chunkRows = fetch_all(
            $erp,
            "WITH Ref AS (
                " . implode("\nUNION ALL\n", $refRows) . "
             )
             SELECT
                R.DocEntry AS ITRDocEntry,
                R.LineNum AS ITRLineNum,
                R.ItemCode,
                T.DocNum AS ITNumber,
                {$scanDateExpr} AS ScanDate,
                {$scanTimeExpr} AS ScanTime,
                {$scannedByExpr} AS BarcodeUser,
                {$lineStatusExpr} AS ITRLineStatus,
                {$openQtyExpr} AS ITROpenQty,
                {$lotSelect}
             FROM Ref
             INNER JOIN WTQ1 R
                ON R.DocEntry = Ref.DocEntry
               AND R.LineNum = Ref.LineNum
               AND R.ItemCode = Ref.ItemCode
             LEFT JOIN WTR1 L
                ON L.BaseType = 1250000001
               AND L.BaseEntry = R.DocEntry
               AND L.BaseLine = R.LineNum
               AND L.ItemCode = R.ItemCode
             LEFT JOIN OWTR T
                ON T.DocEntry = L.DocEntry
               {$transferJoinCanceledSql}
             {$lotJoin}
             {$userJoin}
             ORDER BY R.LineNum ASC, T.DocEntry DESC, L.LineNum DESC
             OPTION (MAXDOP 1)",
            $params
        );

        foreach ($chunkRows as $chunkRow) {
            $rows[] = $chunkRow;
        }
    }

    foreach ($rows as $row) {
        $key = scanplus_key($row['ITRDocEntry'] ?? 0, $row['ITRLineNum'] ?? null, $row['ItemCode'] ?? '');

        if ($key === '') {
            continue;
        }

        $lotKey = scanplus_lot_key(
            $row['ITRDocEntry'] ?? 0,
            $row['ITRLineNum'] ?? null,
            $row['ItemCode'] ?? '',
            $row['ReceivedLotNo'] ?? ''
        );

        $scanAt = scanplus_datetime_text($row['ScanDate'] ?? '', $row['ScanTime'] ?? null);
        $lineStatus = strtoupper(trim((string)($row['ITRLineStatus'] ?? '')));
        $openQty = $row['ITROpenQty'];
        $receivedLotNo = trim((string)($row['ReceivedLotNo'] ?? ''));
        $receivedQty = (float)($row['ReceivedQty'] ?? 0);
        $isClosed = $lineStatus === 'C' || ($openQty !== null && (float)$openQty <= 0);
        $status = $receivedQty <= 0
            ? 'NOT RECEIVED IN SAP'
            : ($isClosed ? 'CLOSED' : 'SAP_RECEIVED');

        if (!isset($result[$key])) {
            $result[$key] = [
                'barcode_user' => trim((string)($row['BarcodeUser'] ?? '')),
                'received_at' => $scanAt,
                'received_lot_no' => '',
                'received_qty' => 0.0,
                'scan_status' => $status,
                'received_lots' => [],
                'it_numbers' => []
            ];
        }

        $result[$key]['received_qty'] += $receivedQty;
        if ($receivedLotNo !== '') {
            $result[$key]['received_lots'][$receivedLotNo] = true;
        }

        if ($lotKey !== '') {
            if (!isset($result[$lotKey])) {
                $result[$lotKey] = [
                    'barcode_user' => trim((string)($row['BarcodeUser'] ?? '')),
                    'received_at' => $scanAt,
                    'received_lot_no' => $receivedLotNo,
                    'received_qty' => 0.0,
                    'scan_status' => $status,
                    'received_lots' => [],
                    'it_numbers' => []
                ];
            }

            $result[$lotKey]['received_qty'] += $receivedQty;
            if ($receivedLotNo !== '') {
                $result[$lotKey]['received_lots'][$receivedLotNo] = true;
            }

            if ($scanAt !== '' && strcmp($scanAt, (string)$result[$lotKey]['received_at']) > 0) {
                $result[$lotKey]['barcode_user'] = trim((string)($row['BarcodeUser'] ?? ''));
                $result[$lotKey]['received_at'] = $scanAt;
            }

            if ($status === 'CLOSED') {
                $result[$lotKey]['scan_status'] = 'CLOSED';
            }

            $itNumber = trim((string)($row['ITNumber'] ?? ''));

            if ($itNumber !== '') {
                $result[$lotKey]['it_numbers'][$itNumber] = true;
            }
        }

        if ($scanAt !== '' && strcmp($scanAt, (string)$result[$key]['received_at']) > 0) {
            $result[$key]['barcode_user'] = trim((string)($row['BarcodeUser'] ?? ''));
            $result[$key]['received_at'] = $scanAt;
        }

        if ($status === 'CLOSED') {
            $result[$key]['scan_status'] = 'CLOSED';
        }

        $itNumber = trim((string)($row['ITNumber'] ?? ''));

        if ($itNumber !== '') {
            $result[$key]['it_numbers'][$itNumber] = true;
        }
    }

    foreach ($result as &$row) {
        if (!empty($row['received_lots'])) {
            $row['received_lot_no'] = implode(', ', array_keys($row['received_lots']));
        }

        $row['it_numbers'] = implode(', ', array_keys($row['it_numbers']));
        unset($row['received_lots']);
    }
    unset($row);

    return $result;
}
