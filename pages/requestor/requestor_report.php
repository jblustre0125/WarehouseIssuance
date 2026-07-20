<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';

require_role([ROLE_REQUESTOR, ROLE_ADMIN]);

/*
|--------------------------------------------------------------------------
| Requestor Report
|--------------------------------------------------------------------------
|
| This page reads only the local WH PokaYoke database.
|
| SAP-derived information is read exclusively from:
| dbo.RawmatTraceScanPlusCache
|
| The scheduled synchronization task is responsible for updating that table.
|
*/

function request_report_date_value(string $name, string $default = ''): string
{
    $value = trim((string)($_GET[$name] ?? $default));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ? $value
        : $default;
}

function request_report_cell($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

function request_report_date_cell($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $text = trim((string)($value ?? ''));

    if ($text === '') {
        return '';
    }

    $timestamp = strtotime($text);

    return $timestamp === false
        ? $text
        : date('Y-m-d', $timestamp);
}

function request_report_number($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }

    return rtrim(
        rtrim(
            number_format((float)$value, 3, '.', ''),
            '0'
        ),
        '.'
    );
}

function request_report_status_class($status): string
{
    $status = strtolower(trim((string)$status));
    $status = preg_replace('/[^a-z0-9]+/', '_', $status);

    return trim((string)$status, '_');
}

function request_report_excel_cell($value): string
{
    return htmlspecialchars(
        request_report_cell($value),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Load the required schema information once.
 *
 * This avoids executing one INFORMATION_SCHEMA query for every table
 * and column check.
 */
function request_report_load_schema($conn): array
{
    $tables = [
        'WarehouseIssueRequestHeader',
        'WarehouseIssueRequestLines',
        'IssuanceTransactions',
        'RawmatTraceHeader',
        'RawmatTraceLines',
        'RawmatTraceScanPlusCache'
    ];

    $placeholders = implode(
        ',',
        array_fill(0, count($tables), '?')
    );

    $rows = fetch_all(
        $conn,
        "
        SELECT
            TABLE_NAME,
            COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME IN ({$placeholders})
        ",
        $tables
    );

    $schema = [];

    foreach ($rows as $row) {
        $tableName = strtolower(
            trim((string)($row['TABLE_NAME'] ?? ''))
        );

        $columnName = strtolower(
            trim((string)($row['COLUMN_NAME'] ?? ''))
        );

        if ($tableName === '' || $columnName === '') {
            continue;
        }

        if (!isset($schema[$tableName])) {
            $schema[$tableName] = [];
        }

        $schema[$tableName][$columnName] = true;
    }

    return $schema;
}

function request_report_has_table(
    array $schema,
    string $table
): bool {
    return isset($schema[strtolower($table)]);
}

function request_report_has_column(
    array $schema,
    string $table,
    string $column
): bool {
    return isset(
        $schema[strtolower($table)][strtolower($column)]
    );
}

function request_report_url(array $changes = []): string
{
    $query = $_GET;

    unset($query['export']);

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    return
        'pages/requestor/requestor_report.php?' .
        http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Filters and pagination
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$dateFrom = request_report_date_value(
    'date_from',
    $today
);

$dateTo = request_report_date_value(
    'date_to',
    $today
);

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$search = trim((string)($_GET['q'] ?? ''));

$allowedPageSizes = [50, 100, 200];

$pageSize = (int)($_GET['page_size'] ?? 100);

if (!in_array($pageSize, $allowedPageSizes, true)) {
    $pageSize = 100;
}

$page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

if ($page === false || $page === null || $page < 1) {
    $page = 1;
}

$export = strtolower(
    trim((string)($_GET['export'] ?? ''))
) === 'excel';

if ($export) {
    set_time_limit(180);
}

$currentUser = current_user();

$currentRole = strtolower(
    trim((string)($currentUser['role'] ?? ''))
);

$currentUsername = trim(
    (string)($currentUser['username'] ?? '')
);

$conn = get_whpokayoke_connection();

if (!$conn) {
    http_response_code(500);
    exit('Unable to connect to the WH PokaYoke database.');
}

$schema = request_report_load_schema($conn);

if (
    !request_report_has_table(
        $schema,
        'WarehouseIssueRequestHeader'
    ) ||
    !request_report_has_table(
        $schema,
        'WarehouseIssueRequestLines'
    )
) {
    http_response_code(500);

    exit(
        'The issue request tables were not found in the local database.'
    );
}

/*
|--------------------------------------------------------------------------
| Build local report filters
|--------------------------------------------------------------------------
*/

$where = [
    'H.RequestedAt >= ?',
    'H.RequestedAt < DATEADD(day, 1, ?)'
];

$params = [
    $dateFrom,
    $dateTo
];

if ($currentRole !== strtolower((string)ROLE_ADMIN)) {
    $where[] = 'H.RequestedByUsername = ?';
    $params[] = $currentUsername;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    $where[] = "
        (
            CAST(H.RequestNo AS NVARCHAR(100)) LIKE ?
            OR CAST(H.ITRNumber AS NVARCHAR(100)) LIKE ?
            OR L.ItemCode LIKE ?
            OR L.PartName LIKE ?
            OR H.Status LIKE ?
            OR L.Status LIKE ?
            OR H.Remarks LIKE ?
            OR H.RequestedByUsername LIKE ?
        )
    ";

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

$whereSql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Count request lines
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT
        COUNT_BIG(*) AS TotalRows
    FROM dbo.WarehouseIssueRequestHeader H
    INNER JOIN dbo.WarehouseIssueRequestLines L
        ON L.RequestID = H.RequestID
    WHERE {$whereSql}
";

$countResult = fetch_one(
    $conn,
    $countSql,
    $params
);

$totalRows = (int)($countResult['TotalRows'] ?? 0);

$totalPages = max(
    1,
    (int)ceil($totalRows / $pageSize)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $pageSize;

$firstRow = $offset + 1;
$lastRow = $offset + $pageSize;

/*
|--------------------------------------------------------------------------
| Optional local columns
|--------------------------------------------------------------------------
*/

$requestLineHasWarehouseLot =
    request_report_has_column(
        $schema,
        'WarehouseIssueRequestLines',
        'WarehouseLotNo'
    );

$requestWarehouseLotExpression =
    $requestLineHasWarehouseLot
        ? "NULLIF(LTRIM(RTRIM(L.WarehouseLotNo)), '')"
        : "CAST(NULL AS NVARCHAR(100))";

/*
|--------------------------------------------------------------------------
| Local trace lookup
|--------------------------------------------------------------------------
*/

$hasTraceTables =
    request_report_has_table(
        $schema,
        'RawmatTraceLines'
    ) &&
    request_report_has_table(
        $schema,
        'RawmatTraceHeader'
    );

$traceApply = "
    OUTER APPLY
    (
        SELECT
            CAST(NULL AS INT) AS TraceLineID,
            CAST(NULL AS DECIMAL(18, 3)) AS TraceIssuedQty,
            CAST(NULL AS DECIMAL(18, 3)) AS LocalReceivedQty,
            CAST(NULL AS NVARCHAR(100)) AS LotNo,
            CAST(NULL AS NVARCHAR(100)) AS WarehouseLotNo,
            CAST(NULL AS NVARCHAR(120)) AS ReceivedByUsername,
            CAST(NULL AS NVARCHAR(100)) AS ReceiverArea,
            CAST(NULL AS DATETIME) AS LocalReceivedAt,
            CAST(NULL AS NVARCHAR(50)) AS VerificationStatus
    ) TL
";

if ($hasTraceTables) {
    $traceQtyColumn = '';

    foreach (
        [
            'IssuedQty',
            'IssueQty',
            'Qty',
            'Quantity',
            'ScannedQty'
        ] as $candidate
    ) {
        if (
            request_report_has_column(
                $schema,
                'RawmatTraceLines',
                $candidate
            )
        ) {
            $traceQtyColumn = $candidate;
            break;
        }
    }

    $traceQuantityExpression =
        $traceQtyColumn !== ''
            ? "TRY_CONVERT(
                    DECIMAL(18, 3),
                    TL0.[{$traceQtyColumn}]
               )"
            : "CAST(NULL AS DECIMAL(18, 3))";

    $traceWarehouseLotExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'WarehouseLotNo'
        )
            ? "NULLIF(
                    LTRIM(RTRIM(TL0.WarehouseLotNo)),
                    ''
               )"
            : "CAST(NULL AS NVARCHAR(100))";

    $traceReceivedQtyExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'ReceivedQty'
        )
            ? "TRY_CONVERT(
                    DECIMAL(18, 3),
                    TL0.ReceivedQty
               )"
            : "CAST(NULL AS DECIMAL(18, 3))";

    $traceReceivedByExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'ReceivedByUsername'
        )
            ? "TL0.ReceivedByUsername"
            : "CAST(NULL AS NVARCHAR(120))";

    $traceReceiverAreaExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'ReceiverArea'
        )
            ? "TL0.ReceiverArea"
            : "CAST(NULL AS NVARCHAR(100))";

    $traceHasReceivedScanAt =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'ReceivedScanAt'
        );

    $traceHasReceivedAt =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'ReceivedAt'
        );

    if ($traceHasReceivedScanAt && $traceHasReceivedAt) {
        $traceReceivedAtExpression =
            "COALESCE(
                TL0.ReceivedScanAt,
                TL0.ReceivedAt
            )";
    } elseif ($traceHasReceivedScanAt) {
        $traceReceivedAtExpression = 'TL0.ReceivedScanAt';
    } elseif ($traceHasReceivedAt) {
        $traceReceivedAtExpression = 'TL0.ReceivedAt';
    } else {
        $traceReceivedAtExpression =
            'CAST(NULL AS DATETIME)';
    }

    $traceStatusExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceLines',
            'VerificationStatus'
        )
            ? "TL0.VerificationStatus"
            : "CAST(NULL AS NVARCHAR(50))";

    $traceApply = "
        OUTER APPLY
        (
            SELECT TOP (1)
                TL0.TraceLineID,

                {$traceQuantityExpression}
                    AS TraceIssuedQty,

                {$traceReceivedQtyExpression}
                    AS LocalReceivedQty,

                NULLIF(
                    LTRIM(RTRIM(TL0.LotNo)),
                    ''
                ) AS LotNo,

                {$traceWarehouseLotExpression}
                    AS WarehouseLotNo,

                {$traceReceivedByExpression}
                    AS ReceivedByUsername,

                {$traceReceiverAreaExpression}
                    AS ReceiverArea,

                {$traceReceivedAtExpression}
                    AS LocalReceivedAt,

                {$traceStatusExpression}
                    AS VerificationStatus

            FROM dbo.RawmatTraceLines TL0

            LEFT JOIN dbo.RawmatTraceHeader TH0
                ON TH0.TraceID = TL0.TraceID

            WHERE
                TL0.IssueRequestLineID = B.RequestLineID

                OR
                (
                    NULLIF(
                        LTRIM(RTRIM(B.IssuedTraceNo)),
                        ''
                    ) IS NOT NULL

                    AND TH0.TraceNo = B.IssuedTraceNo
                    AND TL0.ItemCode = B.ItemCode

                    AND
                    (
                        NULLIF(
                            LTRIM(RTRIM(B.LineLotNo)),
                            ''
                        ) IS NULL

                        OR
                        NULLIF(
                            LTRIM(RTRIM(TL0.LotNo)),
                            ''
                        ) IS NULL

                        OR
                        LTRIM(RTRIM(TL0.LotNo))
                            = LTRIM(RTRIM(B.LineLotNo))

                        OR
                        (
                            TRY_CONVERT(
                                BIGINT,
                                TL0.LotNo
                            ) IS NOT NULL

                            AND TRY_CONVERT(
                                BIGINT,
                                TL0.LotNo
                            ) = TRY_CONVERT(
                                BIGINT,
                                B.LineLotNo
                            )
                        )
                    )
                )

            ORDER BY
                CASE
                    WHEN TL0.IssueRequestLineID
                            = B.RequestLineID
                        THEN 0
                    ELSE 1
                END,

                TL0.TraceLineID DESC
        ) TL
    ";
}

/*
|--------------------------------------------------------------------------
| Local issuance transaction lookup
|--------------------------------------------------------------------------
*/

$hasIssuanceTransactions =
    request_report_has_table(
        $schema,
        'IssuanceTransactions'
    );

$issuanceApply = "
    OUTER APPLY
    (
        SELECT
            CAST(NULL AS INT) AS TransactionID,
            CAST(NULL AS NVARCHAR(100)) AS LotNo,
            CAST(NULL AS NVARCHAR(100)) AS WarehouseLotNo,
            CAST(NULL AS DECIMAL(18, 3)) AS Quantity,
            CAST(NULL AS DATETIME) AS IssuedAt
    ) ITX
";

if ($hasIssuanceTransactions) {
    $transactionMatches = [];
    $transactionOrder = [];

    $txHasRequestLineID =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'IssueRequestLineID'
        );

    $txHasRequestID =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'IssueRequestID'
        );

    $txHasTraceNo =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'TraceNo'
        );

    $txHasItrDocEntry =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'ITRDocEntry'
        );

    $txHasItrLineNum =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'ITRLineNum'
        );

    $txHasWarehouseLot =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'WarehouseLotNo'
        );

    $txHasIssuedAt =
        request_report_has_column(
            $schema,
            'IssuanceTransactions',
            'IssuedAt'
        );

    if ($txHasRequestLineID) {
        $transactionMatches[] =
            'IT0.IssueRequestLineID = B.RequestLineID';

        $transactionOrder[] = "
            CASE
                WHEN IT0.IssueRequestLineID
                        = B.RequestLineID
                    THEN 0
                ELSE 1
            END
        ";
    }

    if ($txHasRequestID) {
        $transactionMatches[] =
            'IT0.IssueRequestID = B.RequestID';

        $transactionOrder[] = "
            CASE
                WHEN IT0.IssueRequestID = B.RequestID
                    THEN 0
                ELSE 1
            END
        ";
    }

    if ($txHasTraceNo) {
        $transactionMatches[] = "
            (
                NULLIF(
                    LTRIM(RTRIM(B.IssuedTraceNo)),
                    ''
                ) IS NOT NULL

                AND IT0.TraceNo = B.IssuedTraceNo
            )
        ";

        $transactionOrder[] = "
            CASE
                WHEN IT0.TraceNo = B.IssuedTraceNo
                    THEN 0
                ELSE 1
            END
        ";
    }

    if ($txHasItrDocEntry && $txHasItrLineNum) {
        $transactionMatches[] = "
            (
                IT0.ITRDocEntry = COALESCE(
                    B.LineSAPDocEntry,
                    B.HeaderSAPDocEntry
                )

                AND ISNULL(
                    IT0.ITRLineNum,
                    -1
                ) = ISNULL(
                    B.SAP_IT_LineNum,
                    -1
                )
            )
        ";

        $transactionOrder[] = "
            CASE
                WHEN
                    IT0.ITRDocEntry = COALESCE(
                        B.LineSAPDocEntry,
                        B.HeaderSAPDocEntry
                    )

                    AND ISNULL(
                        IT0.ITRLineNum,
                        -1
                    ) = ISNULL(
                        B.SAP_IT_LineNum,
                        -1
                    )
                    THEN 0
                ELSE 1
            END
        ";
    }

    if (!empty($transactionMatches)) {
        $transactionWarehouseLotExpression =
            $txHasWarehouseLot
                ? "NULLIF(
                        LTRIM(RTRIM(IT0.WarehouseLotNo)),
                        ''
                   )"
                : "CAST(NULL AS NVARCHAR(100))";

        $transactionIssuedAtExpression =
            $txHasIssuedAt
                ? 'IT0.IssuedAt'
                : 'CAST(NULL AS DATETIME)';

        /*
         * Never attach an issuance transaction that happened before the
         * current request was created. The same SAP ITR line can appear in
         * older local transactions, so DocEntry/LineNum alone is not enough.
         */
        $transactionDateCondition = $txHasIssuedAt
            ? 'AND IT0.IssuedAt >= B.RequestedAt'
            : '';

        if ($txHasIssuedAt) {
            $transactionOrder[] = 'IT0.IssuedAt DESC';
        }

        $transactionOrder[] = 'IT0.TransactionID DESC';

        $transactionMatchSql = implode(
            ' OR ',
            $transactionMatches
        );

        $transactionOrderSql = implode(
            ', ',
            $transactionOrder
        );

        $issuanceApply = "
            OUTER APPLY
            (
                SELECT TOP (1)
                    IT0.TransactionID,

                    NULLIF(
                        LTRIM(RTRIM(IT0.LotNo)),
                        ''
                    ) AS LotNo,

                    {$transactionWarehouseLotExpression}
                        AS WarehouseLotNo,

                    TRY_CONVERT(
                        DECIMAL(18, 3),
                        IT0.Quantity
                    ) AS Quantity,

                    {$transactionIssuedAtExpression}
                        AS IssuedAt

                FROM dbo.IssuanceTransactions IT0

                WHERE
                    IT0.ItemCode = B.ItemCode

                    AND
                    (
                        {$transactionMatchSql}
                    )

                    {$transactionDateCondition}

                ORDER BY
                    {$transactionOrderSql}
            ) ITX
        ";
    }
}

/*
|--------------------------------------------------------------------------
| Local scheduled cache lookup
|--------------------------------------------------------------------------
*/

$hasCache =
    request_report_has_table(
        $schema,
        'RawmatTraceScanPlusCache'
    ) &&
    request_report_has_column(
        $schema,
        'RawmatTraceScanPlusCache',
        'SAP_IT_DocEntry'
    ) &&
    request_report_has_column(
        $schema,
        'RawmatTraceScanPlusCache',
        'SAP_IT_LineNum'
    ) &&
    request_report_has_column(
        $schema,
        'RawmatTraceScanPlusCache',
        'ItemCode'
    ) &&
    request_report_has_column(
        $schema,
        'RawmatTraceScanPlusCache',
        'LotNo'
    );

$cacheApply = "
    OUTER APPLY
    (
        SELECT
            CAST(NULL AS INT) AS SAP_IT_DocEntry,
            CAST(NULL AS INT) AS SAP_IT_LineNum,
            CAST(NULL AS NVARCHAR(100)) AS ItemCode,
            CAST(NULL AS NVARCHAR(100)) AS LotNo,
            CAST(NULL AS NVARCHAR(100)) AS ReceivedLotNo,
            CAST(NULL AS NVARCHAR(50)) AS ScanStatus,
            CAST(NULL AS DECIMAL(18, 3)) AS ReceivedQty,
            CAST(NULL AS NVARCHAR(120)) AS BarcodeUser,
            CAST(NULL AS DATETIME) AS ReceivedAt,
            CAST(NULL AS DATETIME) AS LastSyncedAt
    ) C
";

if ($hasCache) {
    $cacheReceivedLotExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'ReceivedLotNo'
        )
            ? "NULLIF(
                    LTRIM(RTRIM(C0.ReceivedLotNo)),
                    ''
               )"
            : "CAST(NULL AS NVARCHAR(100))";

    $cacheStatusExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'ScanStatus'
        )
            ? 'C0.ScanStatus'
            : "CAST(NULL AS NVARCHAR(50))";

    $cacheQuantityExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'ReceivedQty'
        )
            ? "TRY_CONVERT(
                    DECIMAL(18, 3),
                    C0.ReceivedQty
               )"
            : "CAST(NULL AS DECIMAL(18, 3))";

    $cacheBarcodeUserExpression =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'BarcodeUser'
        )
            ? 'C0.BarcodeUser'
            : "CAST(NULL AS NVARCHAR(120))";

    $cacheHasReceivedAt =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'ReceivedAt'
        );

    $cacheHasLastSyncedAt =
        request_report_has_column(
            $schema,
            'RawmatTraceScanPlusCache',
            'LastSyncedAt'
        );

    $cacheReceivedAtExpression =
        $cacheHasReceivedAt
            ? 'C0.ReceivedAt'
            : 'CAST(NULL AS DATETIME)';

    $cacheLastSyncedExpression =
        $cacheHasLastSyncedAt
            ? 'C0.LastSyncedAt'
            : 'CAST(NULL AS DATETIME)';

    $cacheDateCondition = '';

    if ($cacheHasReceivedAt && $cacheHasLastSyncedAt) {
        $cacheDateCondition = "
            AND
            (
                (
                    C0.ReceivedAt IS NOT NULL
                    AND C0.ReceivedAt >= B.RequestedAt
                    AND
                    (
                        ITX.IssuedAt IS NULL
                        OR C0.ReceivedAt >= ITX.IssuedAt
                    )
                )

                OR
                (
                    C0.ReceivedAt IS NULL
                    AND C0.LastSyncedAt >= B.RequestedAt
                    AND
                    (
                        ITX.IssuedAt IS NULL
                        OR C0.LastSyncedAt >= ITX.IssuedAt
                    )
                )
            )
        ";
    } elseif ($cacheHasReceivedAt) {
        $cacheDateCondition = "
            AND C0.ReceivedAt IS NOT NULL
            AND C0.ReceivedAt >= B.RequestedAt
            AND
            (
                ITX.IssuedAt IS NULL
                OR C0.ReceivedAt >= ITX.IssuedAt
            )
        ";
    } elseif ($cacheHasLastSyncedAt) {
        $cacheDateCondition = "
            AND C0.LastSyncedAt >= B.RequestedAt
            AND
            (
                ITX.IssuedAt IS NULL
                OR C0.LastSyncedAt >= ITX.IssuedAt
            )
        ";
    }

    $cacheReceivedAtOrder =
        $cacheHasReceivedAt
            ? 'C0.ReceivedAt DESC,'
            : '';

    $cacheLastSyncedOrder =
        $cacheHasLastSyncedAt
            ? 'C0.LastSyncedAt DESC'
            : 'C0.SAP_IT_DocEntry DESC';

    $cacheApply = "
        OUTER APPLY
        (
            SELECT TOP (1)
                C0.SAP_IT_DocEntry,
                C0.SAP_IT_LineNum,
                C0.ItemCode,

                NULLIF(
                    LTRIM(RTRIM(C0.LotNo)),
                    ''
                ) AS LotNo,

                {$cacheReceivedLotExpression}
                    AS ReceivedLotNo,

                {$cacheStatusExpression}
                    AS ScanStatus,

                {$cacheQuantityExpression}
                    AS ReceivedQty,

                {$cacheBarcodeUserExpression}
                    AS BarcodeUser,

                {$cacheReceivedAtExpression}
                    AS ReceivedAt,

                {$cacheLastSyncedExpression}
                    AS LastSyncedAt

            FROM dbo.RawmatTraceScanPlusCache C0

            WHERE
                C0.SAP_IT_DocEntry = COALESCE(
                    B.LineSAPDocEntry,
                    B.HeaderSAPDocEntry
                )

                AND ISNULL(
                    C0.SAP_IT_LineNum,
                    -1
                ) = ISNULL(
                    B.SAP_IT_LineNum,
                    -1
                )

                AND C0.ItemCode = B.ItemCode

                AND
                (
                    /* No issued lot: allow the base ITR-line cache row. */
                    NULLIF(
                        LTRIM(RTRIM(R.LocalLotNo)),
                        ''
                    ) IS NULL

                    /* Exact/normalized requested-lot match. */
                    OR
                    (
                        NULLIF(
                            LTRIM(RTRIM(C0.LotNo)),
                            ''
                        ) IS NOT NULL

                        AND
                        (
                            LTRIM(RTRIM(C0.LotNo))
                                = LTRIM(RTRIM(R.LocalLotNo))

                            OR
                            (
                                TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, R.LocalLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, C0.LotNo)
                                    = TRY_CONVERT(BIGINT, R.LocalLotNo)
                            )
                        )
                    )

                    /* Some historical cache rows store the actual SAP lot
                       in ReceivedLotNo instead of LotNo. */
                    OR
                    (
                        NULLIF(
                            LTRIM(RTRIM(C0.ReceivedLotNo)),
                            ''
                        ) IS NOT NULL

                        AND
                        (
                            LTRIM(RTRIM(C0.ReceivedLotNo))
                                = LTRIM(RTRIM(R.LocalLotNo))

                            OR
                            (
                                TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, R.LocalLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo)
                                    = TRY_CONVERT(BIGINT, R.LocalLotNo)
                            )
                        )
                    )

                    /* Keep the blank-lot aggregate only as the last fallback. */
                    OR
                    NULLIF(
                        LTRIM(RTRIM(C0.LotNo)),
                        ''
                    ) IS NULL
                )

                {$cacheDateCondition}

            ORDER BY
                CASE
                    /* First choice: the cache request lot itself matches. */
                    WHEN
                        NULLIF(LTRIM(RTRIM(C0.LotNo)), '') IS NOT NULL
                        AND
                        (
                            LTRIM(RTRIM(C0.LotNo))
                                = LTRIM(RTRIM(R.LocalLotNo))
                            OR
                            (
                                TRY_CONVERT(BIGINT, C0.LotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, R.LocalLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, C0.LotNo)
                                    = TRY_CONVERT(BIGINT, R.LocalLotNo)
                            )
                        )
                        THEN 0

                    /* Second choice: SAP's returned lot matches. */
                    WHEN
                        NULLIF(LTRIM(RTRIM(C0.ReceivedLotNo)), '') IS NOT NULL
                        AND
                        (
                            LTRIM(RTRIM(C0.ReceivedLotNo))
                                = LTRIM(RTRIM(R.LocalLotNo))
                            OR
                            (
                                TRY_CONVERT(BIGINT, C0.ReceivedLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, R.LocalLotNo) IS NOT NULL
                                AND TRY_CONVERT(BIGINT, C0.ReceivedLotNo)
                                    = TRY_CONVERT(BIGINT, R.LocalLotNo)
                            )
                        )
                        THEN 1

                    /* Last safe fallback: a base-key/blank-lot cache row. */
                    WHEN NULLIF(LTRIM(RTRIM(C0.LotNo)), '') IS NULL
                        THEN 2

                    ELSE 3
                END,

                CASE
                    WHEN ISNULL(
                        TRY_CONVERT(
                            DECIMAL(18, 3),
                            C0.ReceivedQty
                        ),
                        0
                    ) > 0
                        THEN 0
                    ELSE 1
                END,

                {$cacheReceivedAtOrder}
                {$cacheLastSyncedOrder}
        ) C
    ";
}

/*
|--------------------------------------------------------------------------
| Main paginated query
|--------------------------------------------------------------------------
*/

$pageFilterSql = '';

$queryParams = $params;

if (!$export) {
    $pageFilterSql = "
        WHERE RowNo BETWEEN ? AND ?
    ";

    $queryParams[] = $firstRow;
    $queryParams[] = $lastRow;
}

$sql = "
WITH FilteredRows AS
(
    SELECT
        ROW_NUMBER() OVER
        (
            ORDER BY
                H.RequestedAt DESC,
                H.RequestID DESC,
                L.RequestLineID ASC
        ) AS RowNo,

        H.RequestID,
        H.RequestNo,
        H.ITRNumber,
        H.NeededDate,
        H.Status AS HeaderStatus,
        H.Remarks,
        H.RequestedByUsername,
        H.RequestedAt,
        H.IssuedTraceNo,
        H.ClosedAt AS HeaderClosedAt,
        H.SAP_IT_DocEntry AS HeaderSAPDocEntry,

        L.RequestLineID,
        L.Status AS RequestLineStatus,
        L.SAP_IT_DocEntry AS LineSAPDocEntry,
        L.SAP_IT_LineNum,
        L.ItemCode,
        L.PartName,
        L.RequestedQty,
        L.IssuedQty AS LineIssuedQty,
        L.LotNo AS LineLotNo,

        {$requestWarehouseLotExpression}
            AS LineWarehouseLotNo

    FROM dbo.WarehouseIssueRequestHeader H

    INNER JOIN dbo.WarehouseIssueRequestLines L
        ON L.RequestID = H.RequestID

    WHERE {$whereSql}
),
PagedRows AS
(
    SELECT *
    FROM FilteredRows
    {$pageFilterSql}
)
SELECT
    B.RowNo,
    B.RequestID,
    B.RequestLineID,
    B.RequestNo,
    B.ITRNumber,
    B.NeededDate,

    CASE
        WHEN
            COALESCE(R.BaseIssuedQty, Q.CacheReceivedQty) IS NULL
            OR COALESCE(R.BaseIssuedQty, Q.CacheReceivedQty) <= 0
            THEN COALESCE(
                NULLIF(
                    LTRIM(RTRIM(B.RequestLineStatus)),
                    ''
                ),
                B.HeaderStatus
            )

        WHEN
            B.RequestedQty > 0
            AND COALESCE(R.BaseIssuedQty, Q.CacheReceivedQty) >= B.RequestedQty
            THEN 'ISSUED'

        ELSE 'PARTIAL'
    END AS IssueStatus,

    B.ItemCode,
    B.PartName,
    B.RequestedQty,

    COALESCE(
        R.BaseIssuedQty,
        Q.CacheReceivedQty
    ) AS IssuedQty,

    E.EffectiveReceivedQty AS SAPReceivedQty,

    CASE
        WHEN
            COALESCE(
                R.BaseIssuedQty,
                E.EffectiveReceivedQty
            ) IS NULL

            OR E.EffectiveReceivedQty IS NULL
            THEN NULL

        ELSE
            COALESCE(
                R.BaseIssuedQty,
                E.EffectiveReceivedQty
            ) - E.EffectiveReceivedQty
    END AS QtyVariance,

    COALESCE(
        NULLIF(
            LTRIM(RTRIM(R.LocalLotNo)),
            ''
        ),
        NULLIF(
            LTRIM(RTRIM(C.ReceivedLotNo)),
            ''
        ),
        NULLIF(
            LTRIM(RTRIM(C.LotNo)),
            ''
        )
    ) AS LotNo,

    R.WarehouseLotNo,

    B.RequestedByUsername,
    B.RequestedAt,
    ITX.IssuedAt AS IssuedAt,

    CASE
        WHEN V.LocalReceiveValid = 1
            THEN COALESCE(
                NULLIF(
                    LTRIM(RTRIM(TL.ReceivedByUsername)),
                    ''
                ),
                CASE
                    WHEN Q.CacheReceivedQty > 0
                        THEN C.BarcodeUser
                    ELSE NULL
                END,
                ''
            )

        WHEN Q.CacheReceivedQty > 0
            THEN C.BarcodeUser

        ELSE ''
    END AS ScannedBy,

    CASE
        WHEN V.LocalReceiveValid = 1
            THEN TL.ReceiverArea
        ELSE ''
    END AS ScannedArea,

    CASE
        WHEN
            V.LocalReceiveValid = 1
            AND TL.LocalReceivedAt IS NOT NULL
            THEN TL.LocalReceivedAt

        WHEN
            Q.CacheReceivedQty > 0
            AND C.ReceivedAt IS NOT NULL
            AND C.ReceivedAt >= B.RequestedAt
            AND
            (
                ITX.IssuedAt IS NULL
                OR C.ReceivedAt >= ITX.IssuedAt
            )
            THEN C.ReceivedAt

        ELSE NULL
    END AS ScannedAt,

    CASE
        /* Same priority used by the issuer report: local receiver first. */
        WHEN V.LocalReceiveValid = 1
            THEN COALESCE(
                NULLIF(
                    LTRIM(RTRIM(TL.VerificationStatus)),
                    ''
                ),
                'RECEIVED'
            )

        WHEN Q.CacheReceivedQty > 0
            THEN
                CASE
                    WHEN UPPER(LTRIM(RTRIM(ISNULL(C.ScanStatus, '')))) IN
                    (
                        'CLOSED',
                        'COMPLETED',
                        'MATCHED'
                    )
                        THEN C.ScanStatus

                    WHEN
                        COALESCE(
                            R.BaseIssuedQty,
                            TRY_CONVERT(DECIMAL(18, 3), B.RequestedQty)
                        ) > 0

                        AND Q.CacheReceivedQty < COALESCE(
                            R.BaseIssuedQty,
                            TRY_CONVERT(DECIMAL(18, 3), B.RequestedQty)
                        )
                        THEN 'SAP PARTIAL'

                    ELSE 'SAP_RECEIVED'
                END

        WHEN UPPER(LTRIM(RTRIM(ISNULL(C.ScanStatus, ''))))
                = 'NOT RECEIVED IN SAP'
            THEN 'NOT RECEIVED IN SAP'

        ELSE 'NOT CONFIRMED'
    END AS ReceiveStatus,

    CASE
        WHEN
            V.LocalReceiveValid = 1

            AND UPPER(
                LTRIM(
                    RTRIM(
                        ISNULL(
                            TL.VerificationStatus,
                            ''
                        )
                    )
                )
            ) IN
            (
                'RECEIVED',
                'CLOSED',
                'COMPLETED',
                'MATCHED'
            )
            THEN B.HeaderClosedAt

        WHEN
            Q.CacheReceivedQty > 0

            AND UPPER(
                LTRIM(
                    RTRIM(
                        ISNULL(C.ScanStatus, '')
                    )
                )
            ) IN
            (
                'CLOSED',
                'COMPLETED',
                'MATCHED'
            )
            THEN C.ReceivedAt

        ELSE NULL
    END AS ClosedAt,

    B.Remarks,
    C.LastSyncedAt AS CacheLastSyncedAt

FROM PagedRows B

{$traceApply}

{$issuanceApply}

CROSS APPLY
(
    SELECT
        COALESCE(
            NULLIF(TL.TraceIssuedQty, 0),
            NULLIF(ITX.Quantity, 0),
            NULLIF(
                TRY_CONVERT(
                    DECIMAL(18, 3),
                    B.LineIssuedQty
                ),
                0
            )
        ) AS BaseIssuedQty,

        COALESCE(
            NULLIF(
                LTRIM(RTRIM(TL.LotNo)),
                ''
            ),
            NULLIF(
                LTRIM(RTRIM(ITX.LotNo)),
                ''
            ),
            NULLIF(
                LTRIM(RTRIM(B.LineLotNo)),
                ''
            )
        ) AS LocalLotNo,

        COALESCE(
            NULLIF(
                LTRIM(RTRIM(TL.WarehouseLotNo)),
                ''
            ),
            NULLIF(
                LTRIM(RTRIM(ITX.WarehouseLotNo)),
                ''
            ),
            NULLIF(
                LTRIM(
                    RTRIM(
                        B.LineWarehouseLotNo
                    )
                ),
                ''
            )
        ) AS WarehouseLotNo,

        COALESCE(
            ITX.IssuedAt,
            B.RequestedAt
        ) AS RequestEventAt
) R

CROSS APPLY
(
    SELECT
        CASE
            WHEN
                /* Reject an old receiver timestamp from a previous issue. */
                (
                    TL.LocalReceivedAt IS NULL
                    OR TL.LocalReceivedAt >= R.RequestEventAt
                )

                AND
                (
                    UPPER(
                        LTRIM(
                            RTRIM(
                                ISNULL(TL.VerificationStatus, '')
                            )
                        )
                    ) IN
                    (
                        'RECEIVED',
                        'CLOSED',
                        'COMPLETED',
                        'MATCHED'
                    )

                    OR ISNULL(
                        TRY_CONVERT(
                            DECIMAL(18, 3),
                            TL.LocalReceivedQty
                        ),
                        0
                    ) > 0

                    OR NULLIF(
                        LTRIM(RTRIM(TL.ReceivedByUsername)),
                        ''
                    ) IS NOT NULL

                    OR TL.LocalReceivedAt IS NOT NULL
                )
                THEN 1
            ELSE 0
        END AS LocalReceiveValid
) V

{$cacheApply}

CROSS APPLY
(
    SELECT
        CASE
            WHEN ISNULL(
                TRY_CONVERT(
                    DECIMAL(18, 3),
                    C.ReceivedQty
                ),
                0
            ) <= 0
                THEN NULL

            WHEN
                COALESCE(
                    R.BaseIssuedQty,
                    TRY_CONVERT(
                        DECIMAL(18, 3),
                        B.RequestedQty
                    )
                ) > 0

                AND TRY_CONVERT(
                    DECIMAL(18, 3),
                    C.ReceivedQty
                ) > COALESCE(
                    R.BaseIssuedQty,
                    TRY_CONVERT(
                        DECIMAL(18, 3),
                        B.RequestedQty
                    )
                )
                THEN COALESCE(
                    R.BaseIssuedQty,
                    TRY_CONVERT(
                        DECIMAL(18, 3),
                        B.RequestedQty
                    )
                )

            ELSE TRY_CONVERT(
                DECIMAL(18, 3),
                C.ReceivedQty
            )
        END AS CacheReceivedQty
) Q

/* Match issuer report behavior: local receiver quantity wins; SAP cache is fallback. */
CROSS APPLY
(
    SELECT
        CASE
            WHEN
                V.LocalReceiveValid = 1
                AND ISNULL(
                    TRY_CONVERT(
                        DECIMAL(18, 3),
                        TL.LocalReceivedQty
                    ),
                    0
                ) > 0
                THEN
                    CASE
                        WHEN
                            COALESCE(
                                R.BaseIssuedQty,
                                TRY_CONVERT(
                                    DECIMAL(18, 3),
                                    B.RequestedQty
                                )
                            ) > 0

                            AND TRY_CONVERT(
                                DECIMAL(18, 3),
                                TL.LocalReceivedQty
                            ) > COALESCE(
                                R.BaseIssuedQty,
                                TRY_CONVERT(
                                    DECIMAL(18, 3),
                                    B.RequestedQty
                                )
                            )
                            THEN COALESCE(
                                R.BaseIssuedQty,
                                TRY_CONVERT(
                                    DECIMAL(18, 3),
                                    B.RequestedQty
                                )
                            )

                        ELSE TRY_CONVERT(
                            DECIMAL(18, 3),
                            TL.LocalReceivedQty
                        )
                    END

            ELSE Q.CacheReceivedQty
        END AS EffectiveReceivedQty
) E

ORDER BY B.RowNo
";

$rows = fetch_all(
    $conn,
    $sql,
    $queryParams
);

/*
|--------------------------------------------------------------------------
| Latest local cache synchronization
|--------------------------------------------------------------------------
*/

$latestCacheSync = '';

if (
    $hasCache &&
    request_report_has_column(
        $schema,
        'RawmatTraceScanPlusCache',
        'LastSyncedAt'
    )
) {
    $latestCacheResult = fetch_one(
        $conn,
        "
        SELECT
            MAX(LastSyncedAt) AS LatestSync
        FROM dbo.RawmatTraceScanPlusCache
        "
    );

    $latestCacheSync = request_report_cell(
        $latestCacheResult['LatestSync'] ?? ''
    );
}

/*
|--------------------------------------------------------------------------
| Report columns
|--------------------------------------------------------------------------
*/

$columns = [
    'Request No',
    'ITR/IT',
    'Needed Date',
    'Issue Status',
    'Part Number',
    'Part Name',
    'Requested Qty',
    'Issued Qty',
    'Received Qty',
    'Variance',
    'GRPO Lot No',
    'WH Lot No',
    'Requested By',
    'Request Created At',
    'Issued At',
    'Received By',
    'Receive Area',
    'Received At',
    'Receive Status',
    'Closed At',
    'Remarks'
];

/*
|--------------------------------------------------------------------------
| Excel export
|--------------------------------------------------------------------------
*/

if ($export) {
    $filename =
        'requestor_requests_' .
        $dateFrom .
        '_to_' .
        $dateTo .
        '.xls';

    header(
        'Content-Type: application/vnd.ms-excel; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header('Pragma: no-cache');
    header('Expires: 0');

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Requestor Requests</title>
    </head>
    <body>

    <table border="1">
        <thead>
        <tr>
            <?php foreach ($columns as $column): ?>
                <th>
                    <?= request_report_excel_cell($column) ?>
                </th>
            <?php endforeach; ?>
        </tr>
        </thead>

        <tbody>

        <?php if (empty($rows)): ?>

            <tr>
                <td colspan="<?= count($columns) ?>">
                    No records found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?= request_report_excel_cell(
                            $row['RequestNo'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ITRNumber'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            request_report_date_cell(
                                $row['NeededDate'] ?? ''
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['IssueStatus'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ItemCode'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['PartName'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            request_report_number(
                                $row['RequestedQty'] ?? ''
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            request_report_number(
                                $row['IssuedQty'] ?? ''
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            request_report_number(
                                $row['SAPReceivedQty'] ?? ''
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            request_report_number(
                                $row['QtyVariance'] ?? ''
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['LotNo'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['WarehouseLotNo'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['RequestedByUsername'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['RequestedAt'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['IssuedAt'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ScannedBy'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ScannedArea'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ScannedAt'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ReceiveStatus'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['ClosedAt'] ?? ''
                        ) ?>
                    </td>

                    <td>
                        <?= request_report_excel_cell(
                            $row['Remarks'] ?? ''
                        ) ?>
                    </td>
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

/*
|--------------------------------------------------------------------------
| URLs and display totals
|--------------------------------------------------------------------------
*/

$exportQuery = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'q' => $search,
    'export' => 'excel'
];

$exportUrl =
    'pages/requestor/requestor_report.php?' .
    http_build_query($exportQuery);

$showingFrom = $totalRows > 0
    ? $firstRow
    : 0;

$showingTo = min(
    $lastRow,
    $totalRows
);

?>
<!doctype html>
<html lang="en">

<head>
    <title>Requestor Report</title>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <base href="<?= h(app_path('')) ?>">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="assets/app-shell.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --sidebar-width: 250px;
            --body-bg: #f4f7fb;
            --border-soft: #e5eaf2;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--body-bg);
            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            overflow-x: hidden;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            width: calc(100% - var(--sidebar-width));
            margin-left: var(--sidebar-width);
            padding: 18px;
            overflow-x: hidden;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .page-title {
            margin-bottom: 4px;
            color: var(--text-dark);
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        .cache-information {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 7px;
            margin-top: 8px;
            color: #166534;
            font-size: 12px;
            font-weight: 700;
        }

        .cache-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
        }

        .content-card {
            overflow: hidden;
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow:
                0 12px 35px
                rgba(15, 23, 42, 0.06);
        }

        .content-card-header {
            padding: 16px 18px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-soft);
        }

        .content-card-title {
            margin: 0;
            color: var(--text-dark);
            font-size: 16px;
            font-weight: 800;
        }

        .content-card-subtitle {
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .content-card-body {
            padding: 18px;
        }

        .filter-box {
            padding: 14px;
            margin-bottom: 14px;
            background: #f8fafc;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
        }

        .form-label {
            margin-bottom: 6px;
            color: #374151;
            font-size: 13px;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            background-color: #ffffff;
            border: 1px solid #d9e2ef;
            border-radius: 11px;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #0d6efd;
            box-shadow:
                0 0 0 4px
                rgba(13, 110, 253, 0.12);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .report-table-wrap {
            max-height: 68vh;
            overflow: auto;
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 14px;
        }

        .report-table {
            min-width: 1850px;
            margin-bottom: 0;
            font-size: 10px;
        }

        .report-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: 8px 5px;
            background: #f8fafc;
            color: #374151;
            border-bottom: 1px solid #d8e0eb;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .report-table td {
            padding: 7px 5px;
            color: #111827;
            vertical-align: middle;
            white-space: nowrap;
        }

        .report-table tbody tr:hover {
            background: #eef6ff;
        }

        .part-name-cell,
        .remarks-cell {
            min-width: 220px;
            max-width: 260px;
            white-space: normal !important;
            line-height: 1.25;
        }

        .quantity-cell {
            text-align: right;
        }

        .empty-row {
            padding: 34px !important;
            color: #6b7280 !important;
            text-align: center;
        }

        .status-pill {
            display: inline-flex;
            max-width: 150px;
            align-items: center;
            justify-content: center;
            padding: 3px 7px;
            overflow: hidden;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-open,
        .status-pending,
        .status-pending_receive {
            background: #fef3c7;
            color: #92400e;
        }

        .status-not_confirmed {
            background: #e5e7eb;
            color: #374151;
        }

        .status-sap_partial,
        .status-sap_received {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-not_received_in_sap {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-issued,
        .status-received,
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

        .pagination {
            margin-bottom: 0;
        }

        .page-link {
            min-width: 38px;
            text-align: center;
        }

        @media (max-width: 900px) {
            .main-content {
                width: 100%;
                margin-left: 0;
                padding: 14px;
            }

            .page-header {
                flex-direction: column;
            }

            .report-table {
                font-size: 12px;
            }

            .report-table thead th {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>

<header class="sap-shellbar">
    <button
        class="shell-menu-btn"
        type="button"
        id="sidebarToggle"
        aria-label="Open navigation"
    >
        &#9776;
    </button>

    <div class="shell-logo" aria-hidden="true">
        <img
            src="image/nbc-bg-dashboard.jpg"
            alt="NBC Logo"
        >
    </div>

    <div class="shell-title-wrap">
        <div class="shell-title">
            NBC Rawmats Traceability
        </div>

        <div class="shell-subtitle">
            Requestor reporting
        </div>
    </div>
</header>

<div
    class="sidebar-backdrop"
    id="sidebarBackdrop"
></div>

<div class="app-layout">

    <?php app_sidebar('requestor_report'); ?>

    <main class="main-content">

        <div class="page-header">

            <div>
                <h4 class="page-title">
                    Requestor Report
                </h4>

                <div class="page-subtitle">
                    Request timeline: created, issued and received.
                    SAP receipts older than the current request are ignored.
                </div>

            </div>

            <div class="text-end">
                <span
                    class="badge bg-primary rounded-pill px-3 py-2"
                >
                    <?= number_format($totalRows) ?> line(s)
                </span>

                <div class="small text-muted mt-1">
                    Showing
                    <?= number_format($showingFrom) ?>
                    –
                    <?= number_format($showingTo) ?>
                </div>
            </div>

        </div>

        <div class="content-card">

            <div class="content-card-header">
                <h5 class="content-card-title">
                    Report Filters
                </h5>

                <div class="content-card-subtitle">
                    Filter local request records and export the
                    selected range.
                    <?php if ($latestCacheSync !== ''): ?>
                        ScanPlus cache last synced:
                        <?= h($latestCacheSync) ?>.
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card-body">

                <form
                    class="filter-box"
                    method="get"
                >
                    <div class="row g-2 align-items-end">

                        <div class="col-sm-6 col-lg-2">
                            <label
                                class="form-label"
                                for="date_from"
                            >
                                Date From
                            </label>

                            <input
                                class="form-control"
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= h($dateFrom) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-lg-2">
                            <label
                                class="form-label"
                                for="date_to"
                            >
                                Date To
                            </label>

                            <input
                                class="form-control"
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= h($dateTo) ?>"
                            >
                        </div>

                        <div class="col-sm-8 col-lg-4">
                            <label
                                class="form-label"
                                for="q"
                            >
                                Search
                            </label>

                            <input
                                class="form-control"
                                type="search"
                                id="q"
                                name="q"
                                value="<?= h($search) ?>"
                                placeholder="Request, ITR, item, part name or status"
                            >
                        </div>

                        <div class="col-sm-4 col-lg-1">
                            <label
                                class="form-label"
                                for="page_size"
                            >
                                Rows
                            </label>

                            <select
                                class="form-select"
                                id="page_size"
                                name="page_size"
                            >
                                <?php foreach (
                                    $allowedPageSizes as $size
                                ): ?>
                                    <option
                                        value="<?= $size ?>"
                                        <?= $pageSize === $size
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= $size ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-lg-1 d-grid">
                            <button
                                class="btn btn-primary"
                                type="submit"
                            >
                                Filter
                            </button>
                        </div>

                        <div class="col-sm-6 col-lg-2 d-grid">
                            <a
                                class="btn btn-success"
                                href="<?= h($exportUrl) ?>"
                            >
                                Export Excel
                            </a>
                        </div>

                    </div>
                </form>

                <div class="small text-muted mb-2">
                    <strong>Timeline:</strong> Request Created At &rarr; Issued At &rarr; Received At.
                    <strong>PARTIAL</strong> under Issue Status means only part of the requested quantity was issued;
                    it does not mean the issued quantity was only partially received.
                </div>

                <div class="report-table-wrap">

                    <table
                        class="table table-bordered table-striped align-middle report-table"
                    >
                        <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <th><?= h($column) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($rows)): ?>

                            <tr>
                                <td
                                    colspan="<?= count($columns) ?>"
                                    class="empty-row"
                                >
                                    No records found for the selected
                                    filters.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($rows as $row): ?>

                                <?php
                                $issueStatusClass =
                                    request_report_status_class(
                                        $row['IssueStatus'] ?? ''
                                    );

                                $receiveStatusClass =
                                    request_report_status_class(
                                        $row['ReceiveStatus'] ?? ''
                                    );
                                ?>

                                <tr>
                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['RequestNo'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ITRNumber'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_date_cell(
                                                $row['NeededDate'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="status-pill status-<?= h(
                                                $issueStatusClass
                                            ) ?>"
                                        >
                                            <?= h(
                                                request_report_cell(
                                                    $row['IssueStatus'] ?? ''
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ItemCode'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="part-name-cell">
                                        <?= h(
                                            request_report_cell(
                                                $row['PartName'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="quantity-cell">
                                        <?= h(
                                            request_report_number(
                                                $row['RequestedQty'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="quantity-cell">
                                        <?= h(
                                            request_report_number(
                                                $row['IssuedQty'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="quantity-cell">
                                        <?= h(
                                            request_report_number(
                                                $row['SAPReceivedQty'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="quantity-cell">
                                        <?= h(
                                            request_report_number(
                                                $row['QtyVariance'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['LotNo'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['WarehouseLotNo'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['RequestedByUsername']
                                                    ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['RequestedAt'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['IssuedAt'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ScannedBy'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ScannedArea'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ScannedAt'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="status-pill status-<?= h(
                                                $receiveStatusClass
                                            ) ?>"
                                        >
                                            <?= h(
                                                request_report_cell(
                                                    $row['ReceiveStatus']
                                                        ?? ''
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= h(
                                            request_report_cell(
                                                $row['ClosedAt'] ?? ''
                                            )
                                        ) ?>
                                    </td>

                                    <td class="remarks-cell">
                                        <?= h(
                                            request_report_cell(
                                                $row['Remarks'] ?? ''
                                            )
                                        ) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>
                    </table>

                </div>

                <?php if (!$export && $totalPages > 1): ?>

                    <div
                        class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3"
                    >
                        <div class="small text-muted">
                            Showing
                            <?= number_format($showingFrom) ?>
                            to
                            <?= number_format($showingTo) ?>
                            of
                            <?= number_format($totalRows) ?>
                            lines
                        </div>

                        <nav aria-label="Request report pagination">
                            <ul class="pagination pagination-sm">

                                <li
                                    class="page-item <?= $page <= 1
                                        ? 'disabled'
                                        : '' ?>"
                                >
                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            request_report_url([
                                                'page' => max(
                                                    1,
                                                    $page - 1
                                                )
                                            ])
                                        ) ?>"
                                    >
                                        Previous
                                    </a>
                                </li>

                                <?php
                                $pageStart = max(
                                    1,
                                    $page - 2
                                );

                                $pageEnd = min(
                                    $totalPages,
                                    $page + 2
                                );
                                ?>

                                <?php for (
                                    $pageNumber = $pageStart;
                                    $pageNumber <= $pageEnd;
                                    $pageNumber++
                                ): ?>

                                    <li
                                        class="page-item <?= $pageNumber
                                            === $page
                                                ? 'active'
                                                : '' ?>"
                                    >
                                        <a
                                            class="page-link"
                                            href="<?= h(
                                                request_report_url([
                                                    'page' => $pageNumber
                                                ])
                                            ) ?>"
                                        >
                                            <?= $pageNumber ?>
                                        </a>
                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $totalPages
                                        ? 'disabled'
                                        : '' ?>"
                                >
                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            request_report_url([
                                                'page' => min(
                                                    $totalPages,
                                                    $page + 1
                                                )
                                            ])
                                        ) ?>"
                                    >
                                        Next
                                    </a>
                                </li>

                            </ul>
                        </nav>
                    </div>

                <?php endif; ?>

            </div>
        </div>

    </main>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');

        if (sidebarBackdrop) {
            sidebarBackdrop.classList.add('show');
        }
    });
}

if (sidebarBackdrop && sidebar) {
    sidebarBackdrop.addEventListener('click', function () {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
    });
}
</script>

</body>
</html>
