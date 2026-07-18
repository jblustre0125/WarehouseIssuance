<?php

const SCANPLUS_CACHE_TTL_SECONDS = 300;

/*
|--------------------------------------------------------------------------
| Schema helpers
|--------------------------------------------------------------------------
*/

function scanplus_has_table($conn, $table)
{
    return (bool)fetch_one(
        $conn,
        "
        SELECT TOP 1
            1 AS HasTable
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_NAME = ?
        ",
        [$table]
    );
}

function scanplus_has_column($conn, $table, $column)
{
    return (bool)fetch_one(
        $conn,
        "
        SELECT TOP 1
            1 AS HasColumn
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = ?
          AND COLUMN_NAME = ?
        ",
        [
            $table,
            $column
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Key and value helpers
|--------------------------------------------------------------------------
*/

function scanplus_key($docEntry, $lineNum, $itemCode)
{
    if (
        $lineNum === null ||
        trim((string)$lineNum) === ''
    ) {
        return '';
    }

    $docEntry = (int)$docEntry;
    $lineNum = (int)$lineNum;
    $itemCode = strtoupper(
        trim((string)$itemCode)
    );

    if (
        $docEntry <= 0 ||
        $lineNum < 0 ||
        $itemCode === ''
    ) {
        return '';
    }

    return
        $docEntry .
        '|' .
        $lineNum .
        '|' .
        $itemCode;
}

function scanplus_normalize_lot($lotNo)
{
    $lotNo = strtoupper(
        trim((string)$lotNo)
    );

    $lotNo = preg_replace(
        '/[^A-Z0-9]+/',
        '',
        $lotNo
    );

    return $lotNo === null
        ? ''
        : $lotNo;
}

function scanplus_lot_key(
    $docEntry,
    $lineNum,
    $itemCode,
    $lotNo
) {
    $key = scanplus_key(
        $docEntry,
        $lineNum,
        $itemCode
    );

    $lotNo = scanplus_normalize_lot($lotNo);

    if ($key === '' || $lotNo === '') {
        return '';
    }

    return $key . '|' . $lotNo;
}

function scanplus_datetime_text(
    $dateValue,
    $timeValue = null
) {
    if ($dateValue instanceof DateTimeInterface) {
        $dateText = $dateValue->format('Y-m-d');

        if (
            $timeValue === null ||
            trim((string)$timeValue) === ''
        ) {
            $timeText = $dateValue->format('H:i:s');

            return $timeText === '00:00:00'
                ? $dateText
                : $dateText . ' ' . $timeText;
        }
    } else {
        $dateText = trim((string)$dateValue);

        if (
            $dateText === '' ||
            strpos($dateText, '1900-01-01') === 0
        ) {
            return '';
        }

        $timestamp = strtotime($dateText);

        if ($timestamp === false) {
            return '';
        }

        if (
            $timeValue === null ||
            trim((string)$timeValue) === ''
        ) {
            $hasTime = preg_match(
                '/\d{1,2}:\d{2}/',
                $dateText
            );

            return $hasTime
                ? date('Y-m-d H:i:s', $timestamp)
                : date('Y-m-d', $timestamp);
        }

        $dateText = date('Y-m-d', $timestamp);
    }

    $timeText = preg_replace(
        '/\D+/',
        '',
        (string)$timeValue
    );

    if ($timeText === null || $timeText === '') {
        return $dateText;
    }

    if (strlen($timeText) <= 4) {
        $timeText = str_pad(
            substr($timeText, -4),
            4,
            '0',
            STR_PAD_LEFT
        );

        return
            $dateText .
            ' ' .
            substr($timeText, 0, 2) .
            ':' .
            substr($timeText, 2, 2) .
            ':00';
    }

    $timeText = str_pad(
        substr($timeText, -6),
        6,
        '0',
        STR_PAD_LEFT
    );

    return
        $dateText .
        ' ' .
        substr($timeText, 0, 2) .
        ':' .
        substr($timeText, 2, 2) .
        ':' .
        substr($timeText, 4, 2);
}

/*
|--------------------------------------------------------------------------
| Cache table
|--------------------------------------------------------------------------
*/

function scanplus_cache_ensure($conn)
{
    $create = sqlsrv_query(
        $conn,
        "
        IF OBJECT_ID(
            'dbo.RawmatTraceScanPlusCache',
            'U'
        ) IS NULL
        BEGIN
            CREATE TABLE dbo.RawmatTraceScanPlusCache
            (
                CacheID INT IDENTITY(1,1)
                    NOT NULL
                    PRIMARY KEY,

                SAP_IT_DocEntry INT NOT NULL,
                SAP_IT_LineNum INT NULL,

                ItemCode NVARCHAR(50)
                    NOT NULL,

                LotNo NVARCHAR(80) NULL,
                ReceivedLotNo NVARCHAR(80) NULL,

                ScanStatus NVARCHAR(50) NULL,

                ReceivedQty DECIMAL(18,3) NULL,

                BarcodeUser NVARCHAR(120) NULL,

                ReceivedAt DATETIME NULL,

                LastSyncedAt DATETIME
                    NOT NULL
                    DEFAULT GETDATE()
            );
        END
        "
    );

    if ($create === false) {
        return false;
    }

    sqlsrv_free_stmt($create);

    $alter = sqlsrv_query(
        $conn,
        "
        IF COL_LENGTH(
            'dbo.RawmatTraceScanPlusCache',
            'ReceivedLotNo'
        ) IS NULL
        BEGIN
            ALTER TABLE dbo.RawmatTraceScanPlusCache
            ADD ReceivedLotNo NVARCHAR(80) NULL;
        END
        "
    );

    if ($alter !== false) {
        sqlsrv_free_stmt($alter);
    }

    $index = sqlsrv_query(
        $conn,
        "
        IF NOT EXISTS
        (
            SELECT 1
            FROM sys.indexes
            WHERE name =
                'IX_RawmatTraceScanPlusCache_Lookup'
              AND object_id = OBJECT_ID(
                    'dbo.RawmatTraceScanPlusCache'
                  )
        )
        BEGIN
            CREATE INDEX
                IX_RawmatTraceScanPlusCache_Lookup
            ON dbo.RawmatTraceScanPlusCache
            (
                SAP_IT_DocEntry,
                SAP_IT_LineNum,
                ItemCode,
                LotNo,
                LastSyncedAt
            );
        END
        "
    );

    if ($index !== false) {
        sqlsrv_free_stmt($index);
    }

    return scanplus_has_table(
        $conn,
        'RawmatTraceScanPlusCache'
    );
}

/*
|--------------------------------------------------------------------------
| Read local cache
|--------------------------------------------------------------------------
*/

function scanplus_cache_read(
    $conn,
    array $refs,
    $ttlSeconds = SCANPLUS_CACHE_TTL_SECONDS
) {
    if (
        empty($refs) ||
        !scanplus_cache_ensure($conn)
    ) {
        return [
            'rows' => [],
            'fresh_keys' => []
        ];
    }

    $freshCutoff = date(
        'Y-m-d H:i:s',
        time() - max(
            1,
            (int)$ttlSeconds
        )
    );

    $result = [];
    $freshKeys = [];
    $normalizedRefs = [];

    foreach (
        array_values($refs) as $idx => $ref
    ) {
        $docEntry = (int)(
            $ref['doc_entry'] ??
            0
        );

        $lineNum =
            !array_key_exists('line_num', $ref) ||
            $ref['line_num'] === null ||
            trim((string)$ref['line_num']) === ''
                ? null
                : (int)$ref['line_num'];

        $itemCode = trim(
            (string)(
                $ref['item_code'] ??
                ''
            )
        );

        $lotNo = trim(
            (string)(
                $ref['lot_no'] ??
                ''
            )
        );

        $scanKey = scanplus_key(
            $docEntry,
            $lineNum,
            $itemCode
        );

        if ($scanKey === '') {
            continue;
        }

        $normalizedRefs[$idx] = [
            'doc_entry' => $docEntry,
            'line_num' => $lineNum,
            'item_code' => $itemCode,
            'lot_no' => $lotNo,
            'scan_key' => $scanKey
        ];
    }

    if (empty($normalizedRefs)) {
        return [
            'rows' => [],
            'fresh_keys' => []
        ];
    }

    $cacheRows = [];

    /*
     * Five parameters are used for each reference.
     *
     * 300 references = 1,500 SQL parameters, safely below SQL Server's
     * parameter limit.
     */
    foreach (
        array_chunk(
            array_keys($normalizedRefs),
            300
        ) as $chunkIndexes
    ) {
        $chunkRows = [];
        $chunkParams = [];

        foreach ($chunkIndexes as $idx) {
            $ref = $normalizedRefs[$idx];

            $chunkRows[] = "
                SELECT
                    ? AS RefIdx,
                    ? AS SAP_IT_DocEntry,
                    ? AS SAP_IT_LineNum,
                    ? AS ItemCode,
                    ? AS LotNo
            ";

            array_push(
                $chunkParams,
                $idx,
                $ref['doc_entry'],
                $ref['line_num'],
                $ref['item_code'],
                $ref['lot_no']
            );
        }

        $chunkCacheRows = fetch_all(
            $conn,
            "
            WITH Ref AS
            (
                " .
                implode(
                    "\nUNION ALL\n",
                    $chunkRows
                ) .
                "
            ),
            RankedCache AS
            (
                SELECT
                    Ref.RefIdx,

                    C.ScanStatus,
                    C.ReceivedLotNo,
                    C.ReceivedQty,
                    C.BarcodeUser,

                    CONVERT(
                        VARCHAR(19),
                        C.ReceivedAt,
                        120
                    ) AS ReceivedAt,

                    CONVERT(
                        VARCHAR(19),
                        C.LastSyncedAt,
                        120
                    ) AS LastSyncedAt,

                    ROW_NUMBER() OVER
                    (
                        PARTITION BY Ref.RefIdx

                        ORDER BY
                            CASE
                                WHEN
                                    ISNULL(
                                        C.LotNo,
                                        ''
                                    ) =
                                    ISNULL(
                                        Ref.LotNo,
                                        ''
                                    )
                                    THEN 0

                                WHEN
                                    ISNULL(
                                        C.ReceivedLotNo,
                                        ''
                                    ) =
                                    ISNULL(
                                        Ref.LotNo,
                                        ''
                                    )
                                    THEN 1

                                WHEN
                                    ISNULL(
                                        C.LotNo,
                                        ''
                                    ) = ''
                                    THEN 2

                                ELSE 3
                            END,

                            C.LastSyncedAt DESC
                    ) AS RowNum

                FROM Ref

                INNER JOIN
                    dbo.RawmatTraceScanPlusCache C

                    ON C.SAP_IT_DocEntry =
                        Ref.SAP_IT_DocEntry

                    AND ISNULL(
                        C.SAP_IT_LineNum,
                        -1
                    ) = ISNULL(
                        Ref.SAP_IT_LineNum,
                        -1
                    )

                    AND C.ItemCode =
                        Ref.ItemCode

                    AND
                    (
                        ISNULL(
                            Ref.LotNo,
                            ''
                        ) = ''

                        OR ISNULL(
                            C.LotNo,
                            ''
                        ) = ISNULL(
                            Ref.LotNo,
                            ''
                        )

                        OR ISNULL(
                            C.ReceivedLotNo,
                            ''
                        ) = ISNULL(
                            Ref.LotNo,
                            ''
                        )
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

            WHERE RowNum = 1
            ",
            $chunkParams
        );

        if (!is_array($chunkCacheRows)) {
            continue;
        }

        foreach ($chunkCacheRows as $chunkCacheRow) {
            $cacheRows[] = $chunkCacheRow;
        }
    }

    foreach ($cacheRows as $cacheRow) {
        $idx = (int)(
            $cacheRow['RefIdx'] ??
            -1
        );

        if (!isset($normalizedRefs[$idx])) {
            continue;
        }

        $ref = $normalizedRefs[$idx];

        $scan = [
            'scan_status' => trim(
                (string)(
                    $cacheRow['ScanStatus'] ??
                    ''
                )
            ),

            'received_lot_no' => trim(
                (string)(
                    $cacheRow['ReceivedLotNo'] ??
                    ''
                )
            ),

            'received_qty' =>
                $cacheRow['ReceivedQty'] ??
                '',

            'barcode_user' => trim(
                (string)(
                    $cacheRow['BarcodeUser'] ??
                    ''
                )
            ),

            'received_at' => trim(
                (string)(
                    $cacheRow['ReceivedAt'] ??
                    ''
                )
            )
        ];

        $scanKey = $ref['scan_key'];

        $scanLotKey = scanplus_lot_key(
            $ref['doc_entry'],
            $ref['line_num'],
            $ref['item_code'],
            $ref['lot_no']
        );

        $targetKey =
            $scanLotKey !== ''
                ? $scanLotKey
                : $scanKey;

        $result[$targetKey] = $scan;

        /*
         * Preserve the existing base-key fallback for pages that do not
         * provide a lot number.
         */
        if ($scanLotKey !== '') {
            $result[$scanKey] = $scan;
        }

        /*
         * Browser pages must use the scheduled cache and must never perform
         * a direct SAP refresh simply because the cache is older than the
         * configured TTL.
         */
        $isCacheFresh =
            PHP_SAPI !== 'cli' ||
            trim(
                (string)(
                    $cacheRow['LastSyncedAt'] ??
                    ''
                )
            ) >= $freshCutoff;

        if ($isCacheFresh) {
            $freshKeys[$targetKey] = true;
            $freshKeys[$scanKey] = true;
        }
    }

    return [
        'rows' => $result,
        'fresh_keys' => $freshKeys
    ];
}

/*
|--------------------------------------------------------------------------
| Write local cache
|--------------------------------------------------------------------------
*/

function scanplus_cache_write(
    $conn,
    array $ref,
    ?array $scan
) {
    /*
     * Cache writing is normally reserved for the CLI scheduled task.
     */
    $webWriteAllowed =
        defined('SCANPLUS_ALLOW_WEB_CACHE_WRITE') &&
        SCANPLUS_ALLOW_WEB_CACHE_WRITE === true;

    if (
        PHP_SAPI !== 'cli' &&
        !$webWriteAllowed
    ) {
        return false;
    }

    static $cacheReady = false;

    if (!$cacheReady) {
        $cacheReady =
            scanplus_cache_ensure($conn);
    }

    if (!$cacheReady) {
        return false;
    }

    $docEntry = (int)(
        $ref['doc_entry'] ??
        0
    );

    $lineNum =
        !array_key_exists('line_num', $ref) ||
        $ref['line_num'] === null ||
        trim((string)$ref['line_num']) === ''
            ? null
            : (int)$ref['line_num'];

    $itemCode = trim(
        (string)(
            $ref['item_code'] ??
            ''
        )
    );

    $lotNo = trim(
        (string)(
            $ref['lot_no'] ??
            ''
        )
    );

    if (
        scanplus_key(
            $docEntry,
            $lineNum,
            $itemCode
        ) === ''
    ) {
        return false;
    }

    $scanStatus =
        $scan['scan_status'] ??
        'NOT RECEIVED IN SAP';

    $receivedLotNo =
        $scan['received_lot_no'] ??
        $scan['lot_no'] ??
        null;

    $receivedQty =
        $scan['received_qty'] ??
        null;

    $barcodeUser =
        $scan['barcode_user'] ??
        null;

    $receivedAt =
        $scan['received_at'] ??
        null;

    $statement = sqlsrv_query(
        $conn,
        "
        MERGE dbo.RawmatTraceScanPlusCache
            WITH (HOLDLOCK) AS T

        USING
        (
            SELECT
                ? AS SAP_IT_DocEntry,
                ? AS SAP_IT_LineNum,
                ? AS ItemCode,
                ? AS LotNo
        ) AS S

        ON
            T.SAP_IT_DocEntry =
                S.SAP_IT_DocEntry

            AND ISNULL(
                T.SAP_IT_LineNum,
                -1
            ) = ISNULL(
                S.SAP_IT_LineNum,
                -1
            )

            AND T.ItemCode =
                S.ItemCode

            AND UPPER(
                LTRIM(
                    RTRIM(
                        ISNULL(
                            T.LotNo,
                            ''
                        )
                    )
                )
            ) = UPPER(
                LTRIM(
                    RTRIM(
                        ISNULL(
                            S.LotNo,
                            ''
                        )
                    )
                )
            )

        WHEN MATCHED THEN
            UPDATE SET
                ScanStatus = ?,
                ReceivedLotNo = ?,
                ReceivedQty = ?,
                BarcodeUser = ?,
                ReceivedAt = ?,
                LastSyncedAt = GETDATE()

        WHEN NOT MATCHED THEN
            INSERT
            (
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
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                GETDATE()
            );
        ",
        [
            $docEntry,
            $lineNum,
            $itemCode,
            $lotNo,

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
    );

    if ($statement === false) {
        return false;
    }

    sqlsrv_free_stmt($statement);

    return true;
}

/*
|--------------------------------------------------------------------------
| Exact SAP / ScanPlus lookup
|--------------------------------------------------------------------------
|
| This function:
|
| - Is blocked for normal web requests.
| - Matches exact ITR DocEntry, line number and item code.
| - Does not query all lines under each ITR document.
| - Matches IBT1 to the destination warehouse.
| - Uses MAXDOP 1 to prevent CXPACKET CPU spikes.
| - Does not perform an expensive result ORDER BY.
|
*/

function scanplus_lookup_by_itr_lines(
    $erp,
    array $refs
) {
    $webLiveAllowed =
        defined('SCANPLUS_ALLOW_WEB_LIVE_LOOKUP') &&
        SCANPLUS_ALLOW_WEB_LIVE_LOOKUP === true;

    if (
        PHP_SAPI !== 'cli' &&
        !$webLiveAllowed
    ) {
        return [];
    }

    if (!$erp || empty($refs)) {
        return [];
    }

    $requestedRefs = [];
    $queryRefs = [];

    $seenRequested = [];
    $seenQuery = [];

    foreach ($refs as $ref) {
        if (!is_array($ref)) {
            continue;
        }

        $docEntry = (int)(
            $ref['doc_entry'] ??
            $ref['SAP_IT_DocEntry'] ??
            $ref['ITRDocEntry'] ??
            0
        );

        $lineRaw =
            $ref['line_num'] ??
            $ref['SAP_IT_LineNum'] ??
            $ref['ITRLineNum'] ??
            null;

        $lineNum =
            $lineRaw === null ||
            trim((string)$lineRaw) === ''
                ? null
                : (int)$lineRaw;

        $itemCode = trim(
            (string)(
                $ref['item_code'] ??
                $ref['ItemCode'] ??
                ''
            )
        );

        $lotNo = trim(
            (string)(
                $ref['lot_no'] ??
                $ref['LotNo'] ??
                ''
            )
        );

        $baseKey = scanplus_key(
            $docEntry,
            $lineNum,
            $itemCode
        );

        if ($baseKey === '') {
            continue;
        }

        $requestedKey =
            $baseKey .
            '|' .
            scanplus_normalize_lot($lotNo);

        if (!isset($seenRequested[$requestedKey])) {
            $seenRequested[$requestedKey] = true;

            $requestedRefs[] = [
                'doc_entry' => $docEntry,
                'line_num' => $lineNum,
                'item_code' => $itemCode,
                'lot_no' => $lotNo
            ];
        }

        if (!isset($seenQuery[$baseKey])) {
            $seenQuery[$baseKey] = true;

            $queryRefs[] = [
                'doc_entry' => $docEntry,
                'line_num' => $lineNum,
                'item_code' => $itemCode
            ];
        }
    }

    if (empty($queryRefs)) {
        return [];
    }

    /*
     * Cache schema detection during the same PHP process.
     */
    static $schema = null;

    if ($schema === null) {
        $requiredTables =
            scanplus_has_table(
                $erp,
                'OWTR'
            ) &&
            scanplus_has_table(
                $erp,
                'WTR1'
            ) &&
            scanplus_has_table(
                $erp,
                'WTQ1'
            );

        $requiredColumns =
            scanplus_has_column(
                $erp,
                'WTR1',
                'BaseType'
            ) &&
            scanplus_has_column(
                $erp,
                'WTR1',
                'BaseEntry'
            ) &&
            scanplus_has_column(
                $erp,
                'WTR1',
                'BaseLine'
            );

        $hasDestinationWarehouse =
            scanplus_has_column(
                $erp,
                'WTR1',
                'WhsCode'
            );

        $hasBatchJoin =
            $hasDestinationWarehouse &&
            scanplus_has_table(
                $erp,
                'IBT1'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'BaseType'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'BaseEntry'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'BaseLinNum'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'ItemCode'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'BatchNum'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'Quantity'
            ) &&
            scanplus_has_column(
                $erp,
                'IBT1',
                'WhsCode'
            );

        $hasInventoryLogBatchJoin =
            !$hasBatchJoin &&
            $hasDestinationWarehouse &&
            scanplus_has_table(
                $erp,
                'OITL'
            ) &&
            scanplus_has_table(
                $erp,
                'ITL1'
            ) &&
            scanplus_has_table(
                $erp,
                'OBTN'
            ) &&
            scanplus_has_column(
                $erp,
                'OITL',
                'LogEntry'
            ) &&
            scanplus_has_column(
                $erp,
                'OITL',
                'DocType'
            ) &&
            scanplus_has_column(
                $erp,
                'OITL',
                'DocEntry'
            ) &&
            scanplus_has_column(
                $erp,
                'OITL',
                'DocLine'
            ) &&
            scanplus_has_column(
                $erp,
                'OITL',
                'LocCode'
            ) &&
            scanplus_has_column(
                $erp,
                'ITL1',
                'LogEntry'
            ) &&
            scanplus_has_column(
                $erp,
                'ITL1',
                'ItemCode'
            ) &&
            scanplus_has_column(
                $erp,
                'ITL1',
                'SysNumber'
            ) &&
            scanplus_has_column(
                $erp,
                'ITL1',
                'Quantity'
            ) &&
            scanplus_has_column(
                $erp,
                'OBTN',
                'ItemCode'
            ) &&
            scanplus_has_column(
                $erp,
                'OBTN',
                'SysNumber'
            ) &&
            scanplus_has_column(
                $erp,
                'OBTN',
                'DistNumber'
            );

        $hasOusr =
            scanplus_has_table(
                $erp,
                'OUSR'
            ) &&
            scanplus_has_column(
                $erp,
                'OUSR',
                'USERID'
            );

        $schema = [
            'required_ok' =>
                $requiredTables &&
                $requiredColumns,

            'has_canceled' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'CANCELED'
                ),

            'has_user_sign' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'UserSign'
                ),

            'has_barcode_user' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'U_BarcodeUser'
                ),

            'has_scan_date' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'U_ScanDateTime'
                ),

            'has_scan_time' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'U_ScanTime'
                ),

            'has_create_date' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'CreateDate'
                ),

            'has_create_time' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'CreateTS'
                ),

            'has_doc_date' =>
                scanplus_has_column(
                    $erp,
                    'OWTR',
                    'DocDate'
                ),

            'has_line_status' =>
                scanplus_has_column(
                    $erp,
                    'WTQ1',
                    'LineStatus'
                ),

            'has_open_qty' =>
                scanplus_has_column(
                    $erp,
                    'WTQ1',
                    'OpenQty'
                ),

            'has_destination_warehouse' =>
                $hasDestinationWarehouse,

            'has_batch_join' =>
                $hasBatchJoin,

            'has_inventory_log_batch_join' =>
                $hasInventoryLogBatchJoin,

            'has_ousr' =>
                $hasOusr,

            'has_user_code' =>
                $hasOusr &&
                scanplus_has_column(
                    $erp,
                    'OUSR',
                    'USER_CODE'
                ),

            'has_user_name' =>
                $hasOusr &&
                scanplus_has_column(
                    $erp,
                    'OUSR',
                    'U_NAME'
                )
        ];
    }

    if (empty($schema['required_ok'])) {
        return [];
    }

    $scanDateExpr =
        $schema['has_scan_date']
            ? 'T.U_ScanDateTime'
            : (
                $schema['has_create_date']
                    ? 'T.CreateDate'
                    : (
                        $schema['has_doc_date']
                            ? 'T.DocDate'
                            : 'CAST(NULL AS DATETIME)'
                    )
            );

    $scanTimeExpr =
        $schema['has_scan_time']
            ? 'T.U_ScanTime'
            : (
                $schema['has_create_time']
                    ? 'T.CreateTS'
                    : 'CAST(NULL AS INT)'
            );

    $lineStatusExpr =
        $schema['has_line_status']
            ? 'R.LineStatus'
            : "CAST('' AS NVARCHAR(10))";

    $openQtyExpr =
        $schema['has_open_qty']
            ? 'R.OpenQty'
            : 'CAST(NULL AS DECIMAL(18,3))';

    $destinationWarehouseExpr =
        $schema['has_destination_warehouse']
            ? 'L.WhsCode'
            : "CAST('' AS NVARCHAR(50))";

    $userJoin = '';
    $scannedByParts = [];

    if ($schema['has_barcode_user']) {
        $scannedByParts[] = "
            NULLIF(
                CAST(
                    T.U_BarcodeUser
                    AS NVARCHAR(120)
                ),
                ''
            )
        ";
    }

    if ($schema['has_user_sign']) {
        if ($schema['has_ousr']) {
            $nameParts = [];

            if ($schema['has_user_code']) {
                $nameParts[] = "
                    NULLIF(
                        CAST(
                            U1.USER_CODE
                            AS NVARCHAR(120)
                        ),
                        ''
                    )
                ";
            }

            if ($schema['has_user_name']) {
                $nameParts[] = "
                    NULLIF(
                        CAST(
                            U1.U_NAME
                            AS NVARCHAR(120)
                        ),
                        ''
                    )
                ";
            }

            $nameParts[] = "
                CAST(
                    T.UserSign
                    AS NVARCHAR(120)
                )
            ";

            $userJoin = "
                LEFT JOIN OUSR U1
                    ON U1.USERID =
                        T.UserSign
            ";

            $scannedByParts[] =
                'COALESCE(' .
                implode(
                    ', ',
                    $nameParts
                ) .
                ')';
        } else {
            $scannedByParts[] = "
                CAST(
                    T.UserSign
                    AS NVARCHAR(120)
                )
            ";
        }
    }

    $scannedByExpr =
        !empty($scannedByParts)
            ? 'COALESCE(' .
                implode(
                    ', ',
                    $scannedByParts
                ) .
                ')'
            : "CAST('' AS NVARCHAR(120))";

    if ($schema['has_batch_join']) {
        $lotSelect = "
            COALESCE(
                B.BatchNum,
                ''
            ) AS ReceivedLotNo,

            CASE
                WHEN T.DocEntry IS NULL
                    THEN 0

                WHEN B.BatchNum IS NULL
                    THEN ABS(
                        ISNULL(
                            L.Quantity,
                            0
                        )
                    )

                ELSE ABS(
                    ISNULL(
                        B.Quantity,
                        0
                    )
                )
            END AS ReceivedQty
        ";

        $lotJoin = "
            LEFT JOIN IBT1 B
                ON B.BaseType = 67
               AND B.BaseEntry =
                    T.DocEntry
               AND B.BaseLinNum =
                    L.LineNum
               AND B.ItemCode =
                    L.ItemCode
               AND B.WhsCode =
                    L.WhsCode
        ";
    } elseif (
        $schema['has_inventory_log_batch_join']
    ) {
        $lotSelect = "
            COALESCE(
                BT.DistNumber,
                ''
            ) AS ReceivedLotNo,

            CASE
                WHEN T.DocEntry IS NULL
                    THEN 0

                WHEN BT.DistNumber IS NULL
                    THEN ABS(
                        ISNULL(
                            L.Quantity,
                            0
                        )
                    )

                ELSE ABS(
                    ISNULL(
                        BL.Quantity,
                        0
                    )
                )
            END AS ReceivedQty
        ";

        $lotJoin = "
            LEFT JOIN OITL IL
                ON IL.DocType = 67
               AND IL.DocEntry =
                    T.DocEntry
               AND IL.DocLine =
                    L.LineNum
               AND IL.LocCode =
                    L.WhsCode

            LEFT JOIN ITL1 BL
                ON BL.LogEntry =
                    IL.LogEntry
               AND BL.ItemCode =
                    L.ItemCode

            LEFT JOIN OBTN BT
                ON BT.ItemCode =
                    BL.ItemCode
               AND BT.SysNumber =
                    BL.SysNumber
        ";
    } else {
        $lotSelect = "
            CAST(
                ''
                AS NVARCHAR(80)
            ) AS ReceivedLotNo,

            CASE
                WHEN T.DocEntry IS NULL
                    THEN 0

                ELSE ABS(
                    ISNULL(
                        L.Quantity,
                        0
                    )
                )
            END AS ReceivedQty
        ";

        $lotJoin = '';
    }

    $transferCanceledSql =
        $schema['has_canceled']
            ? "
                AND ISNULL(
                    T.CANCELED,
                    'N'
                ) = 'N'
            "
            : '';

    $sapRows = [];

    /*
     * Limit each SAP statement to 40 exact lines.
     *
     * Each reference uses three parameters, so this remains far below
     * SQL Server's parameter limit.
     */
    foreach (
        array_chunk(
            $queryRefs,
            40
        ) as $queryChunk
    ) {
        $valueRows = [];
        $params = [];

        foreach ($queryChunk as $queryRef) {
            $valueRows[] = '(?, ?, ?)';

            $params[] =
                $queryRef['doc_entry'];

            $params[] =
                $queryRef['line_num'];

            $params[] =
                $queryRef['item_code'];
        }

        $valuesSql = implode(
            ', ',
            $valueRows
        );

        $chunkRows = fetch_all(
            $erp,
            "
            WITH RequestedReferences AS
            (
                SELECT
                    CAST(
                        V.DocEntry
                        AS INT
                    ) AS DocEntry,

                    CAST(
                        V.LineNum
                        AS INT
                    ) AS LineNum,

                    CAST(
                        V.ItemCode
                        AS NVARCHAR(100)
                    ) AS ItemCode

                FROM
                (
                    VALUES {$valuesSql}
                ) V
                (
                    DocEntry,
                    LineNum,
                    ItemCode
                )
            )
            SELECT
                R.DocEntry AS ITRDocEntry,
                R.LineNum AS ITRLineNum,
                R.ItemCode,

                T.DocEntry AS ITDocEntry,
                T.DocNum AS ITNumber,

                L.LineNum AS ITLineNum,

                {$scanDateExpr}
                    AS ScanDate,

                {$scanTimeExpr}
                    AS ScanTime,

                {$scannedByExpr}
                    AS BarcodeUser,

                {$destinationWarehouseExpr}
                    AS DestinationWarehouse,

                {$lineStatusExpr}
                    AS ITRLineStatus,

                {$openQtyExpr}
                    AS ITROpenQty,

                {$lotSelect}

            FROM RequestedReferences X

            INNER JOIN WTQ1 R
                ON R.DocEntry =
                    X.DocEntry

               AND R.LineNum =
                    X.LineNum

               AND R.ItemCode =
                    X.ItemCode

            LEFT JOIN WTR1 L
                ON L.BaseType =
                    1250000001

               AND L.BaseEntry =
                    R.DocEntry

               AND L.BaseLine =
                    R.LineNum

               AND L.ItemCode =
                    R.ItemCode

            LEFT JOIN OWTR T
                ON T.DocEntry =
                    L.DocEntry

                {$transferCanceledSql}

            {$lotJoin}

            {$userJoin}

            OPTION (MAXDOP 1);
            ",
            $params
        );

        if (!is_array($chunkRows)) {
            continue;
        }

        foreach ($chunkRows as $chunkRow) {
            $sapRows[] = $chunkRow;
        }
    }

    /*
     * Group SAP rows by:
     *
     * ITR line
     * + inventory-transfer document
     * + inventory-transfer line
     * + batch lot
     */
    $buckets = [];

    foreach ($sapRows as $row) {
        $baseKey = scanplus_key(
            $row['ITRDocEntry'] ??
                0,

            $row['ITRLineNum'] ??
                null,

            $row['ItemCode'] ??
                ''
        );

        if ($baseKey === '') {
            continue;
        }

        $receivedQty = (float)(
            $row['ReceivedQty'] ??
            0
        );

        if ($receivedQty <= 0) {
            continue;
        }

        $itDocEntry = (int)(
            $row['ITDocEntry'] ??
            0
        );

        if ($itDocEntry <= 0) {
            continue;
        }

        $itLineNum = (int)(
            $row['ITLineNum'] ??
            0
        );

        $receivedLotNo = trim(
            (string)(
                $row['ReceivedLotNo'] ??
                ''
            )
        );

        $normalizedLot =
            scanplus_normalize_lot(
                $receivedLotNo
            );

        $bucketKey =
            $baseKey .
            '|' .
            $itDocEntry .
            '|' .
            $itLineNum .
            '|' .
            $normalizedLot;

        $scanAt = scanplus_datetime_text(
            $row['ScanDate'] ??
                '',

            $row['ScanTime'] ??
                null
        );

        $lineStatus = strtoupper(
            trim(
                (string)(
                    $row['ITRLineStatus'] ??
                    ''
                )
            )
        );

        $openQty =
            $row['ITROpenQty'] ??
            null;

        $isClosed =
            $lineStatus === 'C' ||
            (
                $openQty !== null &&
                is_numeric($openQty) &&
                (float)$openQty <= 0
            );

        if (!isset($buckets[$bucketKey])) {
            $buckets[$bucketKey] = [
                'base_key' =>
                    $baseKey,

                'received_lot_no' =>
                    $receivedLotNo,

                'received_qty' =>
                    0.0,

                'barcode_user' =>
                    '',

                'destination_warehouse' =>
                    '',

                'received_at' =>
                    '',

                'is_closed' =>
                    false,

                'it_number' =>
                    ''
            ];
        }

        $buckets[$bucketKey]['received_qty'] +=
            $receivedQty;

        $buckets[$bucketKey]['is_closed'] =
            $buckets[$bucketKey]['is_closed'] ||
            $isClosed;

        $currentScanAt = trim(
            (string)(
                $buckets[$bucketKey]['received_at'] ??
                ''
            )
        );

        if (
            $scanAt !== '' &&
            (
                $currentScanAt === '' ||
                strcmp(
                    $scanAt,
                    $currentScanAt
                ) > 0
            )
        ) {
            $buckets[$bucketKey]['received_at'] =
                $scanAt;

            $buckets[$bucketKey]['barcode_user'] =
                trim(
                    (string)(
                        $row['BarcodeUser'] ??
                        ''
                    )
                );

            $buckets[$bucketKey]['destination_warehouse'] =
                trim(
                    (string)(
                        $row['DestinationWarehouse'] ??
                        ''
                    )
                );
        }

        $itNumber = trim(
            (string)(
                $row['ITNumber'] ??
                ''
            )
        );

        if ($itNumber !== '') {
            $buckets[$bucketKey]['it_number'] =
                $itNumber;
        }
    }

    /*
     * Create both:
     *
     * Base key:
     *   DocEntry|LineNum|ItemCode
     *
     * Lot key:
     *   DocEntry|LineNum|ItemCode|Lot
     */
    $result = [];

    $createResultRow = static function () {
        return [
            'barcode_user' => '',
            'scan_area' => '',
            'received_at' => '',
            'received_lot_no' => '',
            'received_qty' => 0.0,
            'scan_status' => 'SAP_RECEIVED',
            'received_lots' => [],
            'it_numbers' => [],
            '_closed' => false
        ];
    };

    $addBucket = static function (
        array &$target,
        array $bucket
    ) {
        $target['received_qty'] +=
            (float)(
                $bucket['received_qty'] ??
                0
            );

        $receivedLotNo = trim(
            (string)(
                $bucket['received_lot_no'] ??
                ''
            )
        );

        if ($receivedLotNo !== '') {
            $target['received_lots'][$receivedLotNo] =
                true;
        }

        $itNumber = trim(
            (string)(
                $bucket['it_number'] ??
                ''
            )
        );

        if ($itNumber !== '') {
            $target['it_numbers'][$itNumber] =
                true;
        }

        $target['_closed'] =
            $target['_closed'] ||
            !empty($bucket['is_closed']);

        $bucketScanAt = trim(
            (string)(
                $bucket['received_at'] ??
                ''
            )
        );

        $targetScanAt = trim(
            (string)(
                $target['received_at'] ??
                ''
            )
        );

        if (
            $bucketScanAt !== '' &&
            (
                $targetScanAt === '' ||
                strcmp(
                    $bucketScanAt,
                    $targetScanAt
                ) > 0
            )
        ) {
            $target['received_at'] =
                $bucketScanAt;

            $target['barcode_user'] =
                trim(
                    (string)(
                        $bucket['barcode_user'] ??
                        ''
                    )
                );

            $target['scan_area'] =
                trim(
                    (string)(
                        $bucket['destination_warehouse'] ??
                        ''
                    )
                );
        }
    };

    foreach ($buckets as $bucket) {
        $baseKey = trim(
            (string)(
                $bucket['base_key'] ??
                ''
            )
        );

        if ($baseKey === '') {
            continue;
        }

        if (!isset($result[$baseKey])) {
            $result[$baseKey] =
                $createResultRow();
        }

        $addBucket(
            $result[$baseKey],
            $bucket
        );

        $receivedLotNo = trim(
            (string)(
                $bucket['received_lot_no'] ??
                ''
            )
        );

        if ($receivedLotNo === '') {
            continue;
        }

        $lotKey =
            $baseKey .
            '|' .
            scanplus_normalize_lot(
                $receivedLotNo
            );

        if (!isset($result[$lotKey])) {
            $result[$lotKey] =
                $createResultRow();
        }

        $addBucket(
            $result[$lotKey],
            $bucket
        );
    }

    foreach ($result as &$resultRow) {
        $resultRow['received_lot_no'] =
            implode(
                ', ',
                array_keys(
                    $resultRow['received_lots']
                )
            );

        $resultRow['it_numbers'] =
            implode(
                ', ',
                array_keys(
                    $resultRow['it_numbers']
                )
            );

        $resultRow['scan_status'] =
            !empty($resultRow['_closed'])
                ? 'CLOSED'
                : 'SAP_RECEIVED';

        unset(
            $resultRow['received_lots'],
            $resultRow['_closed']
        );
    }

    unset($resultRow);

    /*
     * Create explicit NOT RECEIVED records for references that were not found
     * in SAP.
     */
    foreach ($requestedRefs as $requestedRef) {
        $baseKey = scanplus_key(
            $requestedRef['doc_entry'],
            $requestedRef['line_num'],
            $requestedRef['item_code']
        );

        $lotKey = scanplus_lot_key(
            $requestedRef['doc_entry'],
            $requestedRef['line_num'],
            $requestedRef['item_code'],
            $requestedRef['lot_no']
        );

        $targetKey =
            $lotKey !== ''
                ? $lotKey
                : $baseKey;

        if (
            $targetKey !== '' &&
            !isset($result[$targetKey])
        ) {
            $result[$targetKey] = [
                'barcode_user' => '',
                'scan_area' => '',
                'received_at' => '',
                'received_lot_no' => '',
                'received_qty' => 0,
                'scan_status' =>
                    'NOT RECEIVED IN SAP',
                'it_numbers' => ''
            ];
        }

        if (
            $baseKey !== '' &&
            !isset($result[$baseKey])
        ) {
            $result[$baseKey] = [
                'barcode_user' => '',
                'scan_area' => '',
                'received_at' => '',
                'received_lot_no' => '',
                'received_qty' => 0,
                'scan_status' =>
                    'NOT RECEIVED IN SAP',
                'it_numbers' => ''
            ];
        }
    }

    return $result;
}