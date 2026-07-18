<?php

const SCANPLUS_CACHE_TTL_SECONDS = 300;

/*
|--------------------------------------------------------------------------
| Database schema helpers
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
        [$table, $column]
    );
}

/*
|--------------------------------------------------------------------------
| ScanPlus keys
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
    $itemCode = strtoupper(trim((string)$itemCode));

    if (
        $docEntry <= 0 ||
        $lineNum < 0 ||
        $itemCode === ''
    ) {
        return '';
    }

    return $docEntry . '|' . $lineNum . '|' . $itemCode;
}

function scanplus_normalize_lot($lotNo)
{
    $lotNo = strtoupper(trim((string)$lotNo));

    $lotNo = preg_replace(
        '/[^A-Z0-9]+/',
        '',
        $lotNo
    );

    if ($lotNo === null) {
        return '';
    }

    if ($lotNo !== '' && ctype_digit($lotNo)) {
        $lotNo = ltrim($lotNo, '0');

        return $lotNo === ''
            ? '0'
            : $lotNo;
    }

    return $lotNo;
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

/*
|--------------------------------------------------------------------------
| Date conversion
|--------------------------------------------------------------------------
*/

function scanplus_datetime_text($dateValue, $timeValue = null)
{
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
            $containsTime = preg_match(
                '/\d{1,2}:\d{2}/',
                $dateText
            );

            return $containsTime
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

        return $dateText . ' ' .
            substr($timeText, 0, 2) . ':' .
            substr($timeText, 2, 2) . ':00';
    }

    $timeText = str_pad(
        substr($timeText, -6),
        6,
        '0',
        STR_PAD_LEFT
    );

    return $dateText . ' ' .
        substr($timeText, 0, 2) . ':' .
        substr($timeText, 2, 2) . ':' .
        substr($timeText, 4, 2);
}

/*
|--------------------------------------------------------------------------
| Cache table and index
|--------------------------------------------------------------------------
*/

function scanplus_cache_ensure($conn)
{
    $statement = sqlsrv_query(
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

                ItemCode NVARCHAR(50) NOT NULL,
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

    if ($statement === false) {
        return false;
    }

    sqlsrv_free_stmt($statement);

    $statement = sqlsrv_query(
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

    if ($statement !== false) {
        sqlsrv_free_stmt($statement);
    }

    $statement = sqlsrv_query(
        $conn,
        "
        IF NOT EXISTS
        (
            SELECT 1
            FROM sys.indexes
            WHERE name =
                'IX_RawmatTraceScanPlusCache_Lookup'
              AND object_id =
                OBJECT_ID(
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
            )

            INCLUDE
            (
                ReceivedLotNo,
                ScanStatus,
                ReceivedQty,
                BarcodeUser,
                ReceivedAt
            );
        END
        "
    );

    if ($statement !== false) {
        sqlsrv_free_stmt($statement);
    }

    return scanplus_has_table(
        $conn,
        'RawmatTraceScanPlusCache'
    );
}

/*
|--------------------------------------------------------------------------
| Cache reader
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
        time() - max(1, (int)$ttlSeconds)
    );

    $result = [];
    $freshKeys = [];
    $normalizedRefs = [];

    foreach (array_values($refs) as $idx => $ref) {
        if (!is_array($ref)) {
            continue;
        }

        $docEntry = (int)($ref['doc_entry'] ?? 0);

        $lineNum =
            !array_key_exists('line_num', $ref) ||
            $ref['line_num'] === null ||
            trim((string)$ref['line_num']) === ''
                ? null
                : (int)$ref['line_num'];

        $itemCode = trim(
            (string)($ref['item_code'] ?? '')
        );

        $lotNo = trim(
            (string)($ref['lot_no'] ?? '')
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

    foreach (
        array_chunk(
            array_keys($normalizedRefs),
            300
        ) as $chunkIndexes
    ) {
        $referenceQueries = [];
        $params = [];

        foreach ($chunkIndexes as $idx) {
            $ref = $normalizedRefs[$idx];

            $referenceQueries[] = "
                SELECT
                    ? AS RefIdx,
                    ? AS SAP_IT_DocEntry,
                    ? AS SAP_IT_LineNum,
                    ? AS ItemCode,
                    ? AS LotNo
            ";

            array_push(
                $params,
                $idx,
                $ref['doc_entry'],
                $ref['line_num'],
                $ref['item_code'],
                $ref['lot_no']
            );
        }

        $cacheRows = fetch_all(
            $conn,
            "
            WITH Ref AS
            (
                " .
                implode(
                    "\nUNION ALL\n",
                    $referenceQueries
                ) .
                "
            ),
            RankedCache AS
            (
                SELECT
                    Ref.RefIdx,

                    C.LotNo,
                    C.ReceivedLotNo,
                    C.ScanStatus,
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
                                    UPPER(
                                        LTRIM(
                                            RTRIM(
                                                ISNULL(
                                                    C.LotNo,
                                                    ''
                                                )
                                            )
                                        )
                                    ) =
                                    UPPER(
                                        LTRIM(
                                            RTRIM(
                                                ISNULL(
                                                    Ref.LotNo,
                                                    ''
                                                )
                                            )
                                        )
                                    )
                                    THEN 0

                                WHEN
                                    UPPER(
                                        LTRIM(
                                            RTRIM(
                                                ISNULL(
                                                    C.ReceivedLotNo,
                                                    ''
                                                )
                                            )
                                        )
                                    ) =
                                    UPPER(
                                        LTRIM(
                                            RTRIM(
                                                ISNULL(
                                                    Ref.LotNo,
                                                    ''
                                                )
                                            )
                                        )
                                    )
                                    THEN 1

                                WHEN
                                    NULLIF(
                                        LTRIM(
                                            RTRIM(
                                                C.LotNo
                                            )
                                        ),
                                        ''
                                    ) IS NULL
                                    THEN 2

                                ELSE 3
                            END,

                            C.LastSyncedAt DESC,
                            C.CacheID DESC
                    ) AS RowNum

                FROM Ref

                INNER JOIN dbo.RawmatTraceScanPlusCache C
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
                        NULLIF(
                            LTRIM(
                                RTRIM(
                                    Ref.LotNo
                                )
                            ),
                            ''
                        ) IS NULL

                        OR UPPER(
                            LTRIM(
                                RTRIM(
                                    ISNULL(
                                        C.LotNo,
                                        ''
                                    )
                                )
                            )
                        ) = UPPER(
                            LTRIM(
                                RTRIM(
                                    ISNULL(
                                        Ref.LotNo,
                                        ''
                                    )
                                )
                            )
                        )

                        OR UPPER(
                            LTRIM(
                                RTRIM(
                                    ISNULL(
                                        C.ReceivedLotNo,
                                        ''
                                    )
                                )
                            )
                        ) = UPPER(
                            LTRIM(
                                RTRIM(
                                    ISNULL(
                                        Ref.LotNo,
                                        ''
                                    )
                                )
                            )
                        )

                        OR NULLIF(
                            LTRIM(
                                RTRIM(
                                    C.LotNo
                                )
                            ),
                            ''
                        ) IS NULL
                   )
            )
            SELECT
                RefIdx,
                LotNo,
                ReceivedLotNo,
                ScanStatus,
                ReceivedQty,
                BarcodeUser,
                ReceivedAt,
                LastSyncedAt

            FROM RankedCache

            WHERE RowNum = 1
            ",
            $params
        );

        if (!is_array($cacheRows)) {
            continue;
        }

        foreach ($cacheRows as $cacheRow) {
            $idx = (int)($cacheRow['RefIdx'] ?? -1);

            if (!isset($normalizedRefs[$idx])) {
                continue;
            }

            $ref = $normalizedRefs[$idx];

            $scan = [
                'scan_status' => trim(
                    (string)($cacheRow['ScanStatus'] ?? '')
                ),

                'received_lot_no' => trim(
                    (string)(
                        $cacheRow['ReceivedLotNo'] ??
                        ''
                    )
                ),

                'lot_no' => trim(
                    (string)($cacheRow['LotNo'] ?? '')
                ),

                'received_qty' =>
                    $cacheRow['ReceivedQty'] ?? '',

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

            if ($scanLotKey !== '') {
                $result[$scanKey] = $scan;
            }

            /*
             * Web pages always use the scheduled local cache.
             * They must not perform live SAP refreshes.
             */
            $isFresh =
                PHP_SAPI !== 'cli' ||
                trim(
                    (string)(
                        $cacheRow['LastSyncedAt'] ??
                        ''
                    )
                ) >= $freshCutoff;

            if ($isFresh) {
                $freshKeys[$targetKey] = true;
                $freshKeys[$scanKey] = true;
            }
        }
    }

    return [
        'rows' => $result,
        'fresh_keys' => $freshKeys
    ];
}

/*
|--------------------------------------------------------------------------
| Cache writer
|--------------------------------------------------------------------------
*/

function scanplus_cache_write(
    $conn,
    array $ref,
    ?array $scan
) {
    /*
     * Only the scheduled CLI task should normally write the cache.
     */
    $allowWebWrite =
        defined('SCANPLUS_ALLOW_WEB_CACHE_WRITE') &&
        SCANPLUS_ALLOW_WEB_CACHE_WRITE === true;

    if (
        PHP_SAPI !== 'cli' &&
        !$allowWebWrite
    ) {
        return false;
    }

    static $cacheReady = false;

    if (!$cacheReady) {
        $cacheReady = scanplus_cache_ensure($conn);
    }

    if (!$cacheReady) {
        return false;
    }

    $docEntry = (int)($ref['doc_entry'] ?? 0);

    $lineNum =
        !array_key_exists('line_num', $ref) ||
        $ref['line_num'] === null ||
        trim((string)$ref['line_num']) === ''
            ? null
            : (int)$ref['line_num'];

    $itemCode = trim(
        (string)($ref['item_code'] ?? '')
    );

    $lotNo = trim(
        (string)($ref['lot_no'] ?? '')
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
| Optimized SAP lookup
|--------------------------------------------------------------------------
|
| Controls:
|
| - Normal browser pages cannot call SAP directly.
| - Exact DocEntry + LineNum + ItemCode matching.
| - Five references per SAP query.
| - Destination-side batch rows only.
| - No large ORDER BY.
| - MAXDOP 1 removes CXPACKET parallelism.
| - RECOMPILE avoids a bad cached execution plan.
| - FORCE ORDER starts from the small reference list.
| - LOOP JOIN encourages indexed lookups.
|
*/

function scanplus_lookup_by_itr_lines($erp, array $refs)
{
    $allowWebLookup =
        defined('SCANPLUS_ALLOW_WEB_LIVE_LOOKUP') &&
        SCANPLUS_ALLOW_WEB_LIVE_LOOKUP === true;

    if (
        PHP_SAPI !== 'cli' &&
        !$allowWebLookup
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
     * Load SAP schema information only once for the current PHP run.
     */
    static $schema = null;

    if ($schema === null) {
        $hasRequiredTables =
            scanplus_has_table($erp, 'OWTR') &&
            scanplus_has_table($erp, 'WTR1') &&
            scanplus_has_table($erp, 'WTQ1');

        $hasRequiredColumns =
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
            scanplus_has_table($erp, 'IBT1') &&
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
            scanplus_has_table($erp, 'OITL') &&
            scanplus_has_table($erp, 'ITL1') &&
            scanplus_has_table($erp, 'OBTN') &&
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
            scanplus_has_table($erp, 'OUSR') &&
            scanplus_has_column(
                $erp,
                'OUSR',
                'USERID'
            );

        $schema = [
            'required_ok' =>
                $hasRequiredTables &&
                $hasRequiredColumns,

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

    $scanDateExpression =
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

    $scanTimeExpression =
        $schema['has_scan_time']
            ? 'T.U_ScanTime'
            : (
                $schema['has_create_time']
                    ? 'T.CreateTS'
                    : 'CAST(NULL AS INT)'
            );

    $lineStatusExpression =
        $schema['has_line_status']
            ? 'R.LineStatus'
            : "CAST('' AS NVARCHAR(10))";

    $openQtyExpression =
        $schema['has_open_qty']
            ? 'R.OpenQty'
            : 'CAST(NULL AS DECIMAL(18,3))';

    $destinationWarehouseExpression =
        $schema['has_destination_warehouse']
            ? 'L.WhsCode'
            : "CAST('' AS NVARCHAR(50))";

    /*
     * SAP user name expression.
     */
    $userJoin = '';
    $barcodeUserParts = [];

    if ($schema['has_barcode_user']) {
        $barcodeUserParts[] = "
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
            $userNameParts = [];

            if ($schema['has_user_code']) {
                $userNameParts[] = "
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
                $userNameParts[] = "
                    NULLIF(
                        CAST(
                            U1.U_NAME
                            AS NVARCHAR(120)
                        ),
                        ''
                    )
                ";
            }

            $userNameParts[] = "
                CAST(
                    T.UserSign
                    AS NVARCHAR(120)
                )
            ";

            $userJoin = "
                LEFT JOIN OUSR U1
                    ON U1.USERID = T.UserSign
            ";

            $barcodeUserParts[] =
                'COALESCE(' .
                implode(', ', $userNameParts) .
                ')';
        } else {
            $barcodeUserParts[] = "
                CAST(
                    T.UserSign
                    AS NVARCHAR(120)
                )
            ";
        }
    }

    $barcodeUserExpression =
        !empty($barcodeUserParts)
            ? 'COALESCE(' .
                implode(', ', $barcodeUserParts) .
                ')'
            : "CAST('' AS NVARCHAR(120))";

    /*
     * Batch source.
     */
    if ($schema['has_batch_join']) {
        $batchSelect = "
            COALESCE(
                B.BatchNum,
                ''
            ) AS ReceivedLotNo,

            CASE
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

        $batchJoin = "
            LEFT JOIN IBT1 B
                ON B.BaseType = 67
               AND B.BaseEntry = T.DocEntry
               AND B.BaseLinNum = L.LineNum
               AND B.ItemCode = L.ItemCode
               AND B.WhsCode = L.WhsCode
        ";
    } elseif ($schema['has_inventory_log_batch_join']) {
        $batchSelect = "
            COALESCE(
                BT.DistNumber,
                ''
            ) AS ReceivedLotNo,

            CASE
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

        $batchJoin = "
            LEFT JOIN OITL IL
                ON IL.DocType = 67
               AND IL.DocEntry = T.DocEntry
               AND IL.DocLine = L.LineNum
               AND IL.LocCode = L.WhsCode

            LEFT JOIN ITL1 BL
                ON BL.LogEntry = IL.LogEntry
               AND BL.ItemCode = L.ItemCode

            LEFT JOIN OBTN BT
                ON BT.ItemCode = BL.ItemCode
               AND BT.SysNumber = BL.SysNumber
        ";
    } else {
        $batchSelect = "
            CAST(
                ''
                AS NVARCHAR(80)
            ) AS ReceivedLotNo,

            ABS(
                ISNULL(
                    L.Quantity,
                    0
                )
            ) AS ReceivedQty
        ";

        $batchJoin = '';
    }

    $canceledCondition =
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
     * Only five exact references per SAP query.
     */
    foreach (
        array_chunk(
            $queryRefs,
            5
        ) as $queryChunk
    ) {
        $valueRows = [];
        $params = [];

        foreach ($queryChunk as $queryRef) {
            $valueRows[] = '(?, ?, ?)';

            $params[] = $queryRef['doc_entry'];
            $params[] = $queryRef['line_num'];
            $params[] = $queryRef['item_code'];
        }

        $valuesSql = implode(', ', $valueRows);

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
                        AS NVARCHAR(50)
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
                X.DocEntry AS ITRDocEntry,
                X.LineNum AS ITRLineNum,
                X.ItemCode,

                T.DocEntry AS ITDocEntry,
                T.DocNum AS ITNumber,
                L.LineNum AS ITLineNum,

                {$scanDateExpression}
                    AS ScanDate,

                {$scanTimeExpression}
                    AS ScanTime,

                {$barcodeUserExpression}
                    AS BarcodeUser,

                {$destinationWarehouseExpression}
                    AS DestinationWarehouse,

                {$lineStatusExpression}
                    AS ITRLineStatus,

                {$openQtyExpression}
                    AS ITROpenQty,

                {$batchSelect}

            FROM RequestedReferences X

            INNER LOOP JOIN WTR1 L
                ON L.BaseType = 1250000001
               AND L.BaseEntry = X.DocEntry
               AND L.BaseLine = X.LineNum
               AND L.ItemCode = X.ItemCode

            INNER LOOP JOIN OWTR T
                ON T.DocEntry = L.DocEntry

                {$canceledCondition}

            INNER LOOP JOIN WTQ1 R
                ON R.DocEntry = X.DocEntry
               AND R.LineNum = X.LineNum
               AND R.ItemCode = X.ItemCode

            {$batchJoin}

            {$userJoin}

            OPTION
            (
                MAXDOP 1,
                RECOMPILE,
                FORCE ORDER,
                LOOP JOIN
            );
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
     * Deduplicate SAP result rows by:
     *
     * ITR line + IT document + IT line + lot.
     */
    $buckets = [];

    foreach ($sapRows as $sapRow) {
        $baseKey = scanplus_key(
            $sapRow['ITRDocEntry'] ?? 0,
            $sapRow['ITRLineNum'] ?? null,
            $sapRow['ItemCode'] ?? ''
        );

        if ($baseKey === '') {
            continue;
        }

        $itDocEntry = (int)(
            $sapRow['ITDocEntry'] ?? 0
        );

        if ($itDocEntry <= 0) {
            continue;
        }

        $itLineNum = (int)(
            $sapRow['ITLineNum'] ?? 0
        );

        $receivedQty = abs(
            (float)($sapRow['ReceivedQty'] ?? 0)
        );

        if ($receivedQty <= 0) {
            continue;
        }

        $receivedLotNo = trim(
            (string)(
                $sapRow['ReceivedLotNo'] ?? ''
            )
        );

        $normalizedLot = scanplus_normalize_lot(
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
            $sapRow['ScanDate'] ?? '',
            $sapRow['ScanTime'] ?? null
        );

        $lineStatus = strtoupper(
            trim(
                (string)(
                    $sapRow['ITRLineStatus'] ?? ''
                )
            )
        );

        $openQty =
            $sapRow['ITROpenQty'] ?? null;

        $isClosed =
            $lineStatus === 'C' ||
            (
                $openQty !== null &&
                is_numeric($openQty) &&
                (float)$openQty <= 0
            );

        if (!isset($buckets[$bucketKey])) {
            $buckets[$bucketKey] = [
                'base_key' => $baseKey,
                'received_lot_no' => $receivedLotNo,
                'received_qty' => $receivedQty,
                'barcode_user' => trim(
                    (string)(
                        $sapRow['BarcodeUser'] ?? ''
                    )
                ),
                'scan_area' => trim(
                    (string)(
                        $sapRow['DestinationWarehouse'] ??
                        ''
                    )
                ),
                'received_at' => $scanAt,
                'is_closed' => $isClosed,
                'it_number' => trim(
                    (string)(
                        $sapRow['ITNumber'] ?? ''
                    )
                )
            ];

            continue;
        }

        /*
         * Use the largest value for duplicate physical rows.
         * Do not repeatedly add the same IBT1 quantity.
         */
        $buckets[$bucketKey]['received_qty'] = max(
            (float)$buckets[$bucketKey]['received_qty'],
            $receivedQty
        );

        $buckets[$bucketKey]['is_closed'] =
            !empty($buckets[$bucketKey]['is_closed']) ||
            $isClosed;

        $existingScanAt = trim(
            (string)(
                $buckets[$bucketKey]['received_at'] ??
                ''
            )
        );

        if (
            $scanAt !== '' &&
            (
                $existingScanAt === '' ||
                strcmp($scanAt, $existingScanAt) > 0
            )
        ) {
            $buckets[$bucketKey]['received_at'] =
                $scanAt;

            $buckets[$bucketKey]['barcode_user'] =
                trim(
                    (string)(
                        $sapRow['BarcodeUser'] ?? ''
                    )
                );

            $buckets[$bucketKey]['scan_area'] =
                trim(
                    (string)(
                        $sapRow['DestinationWarehouse'] ??
                        ''
                    )
                );
        }
    }

    /*
     * Build base-key and lot-key results.
     */
    $results = [];

    $newResult = static function () {
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
        array &$result,
        array $bucket
    ) {
        $result['received_qty'] +=
            (float)($bucket['received_qty'] ?? 0);

        $lotNo = trim(
            (string)(
                $bucket['received_lot_no'] ?? ''
            )
        );

        if ($lotNo !== '') {
            $result['received_lots'][$lotNo] = true;
        }

        $itNumber = trim(
            (string)(
                $bucket['it_number'] ?? ''
            )
        );

        if ($itNumber !== '') {
            $result['it_numbers'][$itNumber] = true;
        }

        $result['_closed'] =
            !empty($result['_closed']) ||
            !empty($bucket['is_closed']);

        $bucketScanAt = trim(
            (string)(
                $bucket['received_at'] ?? ''
            )
        );

        $resultScanAt = trim(
            (string)(
                $result['received_at'] ?? ''
            )
        );

        if (
            $bucketScanAt !== '' &&
            (
                $resultScanAt === '' ||
                strcmp(
                    $bucketScanAt,
                    $resultScanAt
                ) > 0
            )
        ) {
            $result['received_at'] =
                $bucketScanAt;

            $result['barcode_user'] =
                trim(
                    (string)(
                        $bucket['barcode_user'] ?? ''
                    )
                );

            $result['scan_area'] =
                trim(
                    (string)(
                        $bucket['scan_area'] ?? ''
                    )
                );
        }
    };

    foreach ($buckets as $bucket) {
        $baseKey = trim(
            (string)($bucket['base_key'] ?? '')
        );

        if ($baseKey === '') {
            continue;
        }

        if (!isset($results[$baseKey])) {
            $results[$baseKey] = $newResult();
        }

        $addBucket(
            $results[$baseKey],
            $bucket
        );

        $lotNo = trim(
            (string)(
                $bucket['received_lot_no'] ?? ''
            )
        );

        if ($lotNo === '') {
            continue;
        }

        $lotKey =
            $baseKey .
            '|' .
            scanplus_normalize_lot($lotNo);

        if (!isset($results[$lotKey])) {
            $results[$lotKey] = $newResult();
        }

        $addBucket(
            $results[$lotKey],
            $bucket
        );
    }

    foreach ($results as &$result) {
        $result['received_lot_no'] =
            implode(
                ', ',
                array_keys(
                    $result['received_lots']
                )
            );

        $result['it_numbers'] =
            implode(
                ', ',
                array_keys(
                    $result['it_numbers']
                )
            );

        $result['scan_status'] =
            !empty($result['_closed'])
                ? 'CLOSED'
                : 'SAP_RECEIVED';

        unset(
            $result['received_lots'],
            $result['_closed']
        );
    }

    unset($result);

    /*
     * Add explicit NOT RECEIVED results.
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

        $notReceived = [
            'barcode_user' => '',
            'scan_area' => '',
            'received_at' => '',
            'received_lot_no' => '',
            'received_qty' => 0,
            'scan_status' => 'NOT RECEIVED IN SAP',
            'it_numbers' => ''
        ];

        if (
            $targetKey !== '' &&
            !isset($results[$targetKey])
        ) {
            $results[$targetKey] = $notReceived;
        }

        if (
            $baseKey !== '' &&
            !isset($results[$baseKey])
        ) {
            $results[$baseKey] = $notReceived;
        }
    }

    return $results;
}