<?php

require_once __DIR__ . '/../../includes/auth.php';

require_role([
    ROLE_ISSUER,
    ROLE_ADMIN
]);

header(
    'Content-Type: application/json; charset=utf-8'
);


/*
|--------------------------------------------------------------------------
| OPTIONAL DATABASE FILES
|--------------------------------------------------------------------------
|
| Your auth.php may already load the WHPOKAYOKE database connection.
| These are only loaded when they actually exist.
|
*/

$possibleDbFiles = [
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../../includes/database.php',
    __DIR__ . '/../../includes/connection.php',
    __DIR__ . '/../../includes/db_connection.php'
];

foreach ($possibleDbFiles as $file) {

    if (is_file($file)) {
        require_once $file;
    }
}


/*
|--------------------------------------------------------------------------
| FIND SQL SERVER CONNECTION
|--------------------------------------------------------------------------
*/

function resolveWarehouseSqlConnection()
{
    /*
     * Try known global connection variables first.
     */

    $globalNames = [
        'conn',
        'connection',
        'dbConn',
        'dbConnection',
        'whConn',
        'whConnection',
        'whpokayokeConn',
        'warehouseConn'
    ];

    foreach ($globalNames as $name) {

        if (
            isset($GLOBALS[$name]) &&
            $GLOBALS[$name]
        ) {
            return $GLOBALS[$name];
        }
    }


    /*
     * Try known connection helper functions.
     */

    $functions = [
        'get_whpokayoke_connection',
        'getWhpokayokeConnection',
        'get_warehouse_connection',
        'getWarehouseConnection',
        'get_db_connection',
        'getDbConnection',
        'db_connection'
    ];

    foreach ($functions as $function) {

        if (!function_exists($function)) {
            continue;
        }

        try {

            $connection =
                $function();

            if ($connection) {
                return $connection;
            }
        } catch (Throwable $e) {
            /*
             * Try the next known function.
             */
        }
    }

    throw new RuntimeException(
        'WHPOKAYOKE SQL Server connection was not found. ' .
            'Use the same database connection include/helper used by your other issuer API files.'
    );
}


/*
|--------------------------------------------------------------------------
| SQL SERVER HELPERS
|--------------------------------------------------------------------------
*/

function sqlServerErrorsText(): string
{
    if (!function_exists('sqlsrv_errors')) {
        return '';
    }

    $errors =
        sqlsrv_errors(
            SQLSRV_ERR_ALL
        );

    if (!$errors) {
        return '';
    }

    $messages = [];

    foreach ($errors as $error) {

        $messages[] =
            trim(
                (string) (
                    $error['message'] ??
                    ''
                )
            );
    }

    return implode(
        ' | ',
        array_filter(
            $messages
        )
    );
}


function getTableColumns(
    $conn,
    string $tableName
): array {

    $sql = "
        SELECT
            COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            TABLE_SCHEMA = 'dbo'
            AND TABLE_NAME = ?
    ";

    $stmt =
        sqlsrv_query(
            $conn,
            $sql,
            [
                $tableName
            ]
        );

    if ($stmt === false) {

        throw new RuntimeException(
            'Unable to inspect dbo.' .
                $tableName .
                '. ' .
                sqlServerErrorsText()
        );
    }

    $columns = [];

    while (
        $row =
        sqlsrv_fetch_array(
            $stmt,
            SQLSRV_FETCH_ASSOC
        )
    ) {

        $column =
            trim(
                (string) (
                    $row['COLUMN_NAME'] ??
                    ''
                )
            );

        if ($column === '') {
            continue;
        }

        $columns[strtolower(
                $column
            )] = $column;
    }

    return $columns;
}


function findColumn(
    array $columns,
    array $candidates
): ?string {

    foreach ($candidates as $candidate) {

        $key =
            strtolower(
                $candidate
            );

        if (
            isset(
                $columns[$key]
            )
        ) {

            return $columns[$key];
        }
    }

    return null;
}


function sqlSelectColumn(
    string $tableAlias,
    array $columns,
    array $candidateColumns,
    string $outputAlias,
    string $defaultSql = 'NULL'
): string {

    $column =
        findColumn(
            $columns,
            $candidateColumns
        );

    if (!$column) {

        return
            $defaultSql .
            ' AS [' .
            $outputAlias .
            ']';
    }

    return
        $tableAlias .
        '.[' .
        $column .
        '] AS [' .
        $outputAlias .
        ']';
}


function tableExists(
    $conn,
    string $tableName
): bool {

    $sql = "
        SELECT
            COUNT(*) AS Cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE
            TABLE_SCHEMA = 'dbo'
            AND TABLE_NAME = ?
    ";

    $stmt =
        sqlsrv_query(
            $conn,
            $sql,
            [
                $tableName
            ]
        );

    if ($stmt === false) {
        return false;
    }

    $row =
        sqlsrv_fetch_array(
            $stmt,
            SQLSRV_FETCH_ASSOC
        );

    return ((int) (
            $row['Cnt'] ??
            0
        )) > 0;
}


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

try {

    if (
        !function_exists(
            'sqlsrv_query'
        )
    ) {

        throw new RuntimeException(
            'Microsoft SQL Server PHP driver (sqlsrv) is not available.'
        );
    }


    $conn =
        resolveWarehouseSqlConnection();


    /*
     * Limit records so the tab remains fast.
     */

    $limit =
        isset($_GET['limit'])
        ? (int) $_GET['limit']
        : 300;

    $limit =
        max(
            1,
            min(
                $limit,
                1000
            )
        );


    /*
     * Your saved issuance table.
     */

    $transactionTable =
        'IssuanceTransactions';


    if (
        !tableExists(
            $conn,
            $transactionTable
        )
    ) {

        throw new RuntimeException(
            'dbo.IssuanceTransactions was not found.'
        );
    }


    $columns =
        getTableColumns(
            $conn,
            $transactionTable
        );


    /*
     * Detect your actual column names.
     */

    $traceColumn =
        findColumn(
            $columns,
            [
                'TraceNo',
                'TraceNumber',
                'TraceCode'
            ]
        );


    $itemColumn =
        findColumn(
            $columns,
            [
                'ItemCode',
                'SAPItemCode'
            ]
        );


    $issuedAtColumn =
        findColumn(
            $columns,
            [
                'IssuedAt',
                'IssueDate',
                'TransactionDate',
                'CreatedAt',
                'CreatedDate',
                'DateCreated'
            ]
        );


    $requestIdColumn =
        findColumn(
            $columns,
            [
                'IssueRequestID',
                'RequestID',
                'WarehouseIssueRequestID'
            ]
        );


    /*
     * These are the values required to rebuild
     * the already issued tag.
     */

    $select = [

        sqlSelectColumn(
            'I',
            $columns,
            [
                'TraceNo',
                'TraceNumber',
                'TraceCode'
            ],
            'trace_no',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'ItemCode',
                'SAPItemCode'
            ],
            'item_code',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'PartName',
                'ItemName',
                'Description',
                'PartDescription'
            ],
            'part_name',
            "CAST('' AS nvarchar(255))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'PartsCode',
                'PartCode'
            ],
            'parts_code',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'LocationCode',
                'Location',
                'BinCode'
            ],
            'location_code',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'Quantity',
                'IssueQty',
                'IssuedQty',
                'Qty'
            ],
            'quantity',
            '0'
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'LotNo',
                'GRPOLotNo',
                'BatchNo',
                'BatchNum'
            ],
            'lot_no',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'WarehouseLotNo',
                'WHLotNo',
                'WhLotNo',
                'WarehouseLot'
            ],
            'warehouse_lot_no',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'ITRNumber',
                'ITRDocNum',
                'DocNum',
                'TransferRequestNo'
            ],
            'itr_number',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'ITRDocEntry',
                'DocEntry',
                'TransferRequestEntry'
            ],
            'itr_doc_entry',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'ITRLineNum',
                'LineNum',
                'ITRLine',
                'TransferRequestLine'
            ],
            'itr_line_num',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'IssueRequestID',
                'RequestID',
                'WarehouseIssueRequestID'
            ],
            'request_id',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'IssueRequestLineID',
                'RequestLineID',
                'WarehouseIssueRequestLineID'
            ],
            'request_line_id',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'StockWhsCode',
                'WhsCode',
                'WarehouseCode',
                'SourceWarehouse'
            ],
            'stock_whs_code',
            "'01'"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'IssuedByUsername',
                'IssuedBy',
                'CreatedBy',
                'IssuerUsername',
                'Username'
            ],
            'issued_by',
            "CAST('' AS nvarchar(100))"
        ),

        sqlSelectColumn(
            'I',
            $columns,
            [
                'IssuedAt',
                'IssueDate',
                'TransactionDate',
                'CreatedAt',
                'CreatedDate',
                'DateCreated'
            ],
            'issued_at',
            'NULL'
        )
    ];


    /*
     * Try to retrieve RequestNo from
     * WarehouseIssueRequestHeader.
     */

    $headerTable =
        'WarehouseIssueRequestHeader';

    $joinSql = '';

    if (
        $requestIdColumn &&
        tableExists(
            $conn,
            $headerTable
        )
    ) {

        $headerColumns =
            getTableColumns(
                $conn,
                $headerTable
            );

        $headerRequestId =
            findColumn(
                $headerColumns,
                [
                    'RequestID',
                    'IssueRequestID',
                    'ID'
                ]
            );

        $headerRequestNo =
            findColumn(
                $headerColumns,
                [
                    'RequestNo',
                    'RequestNumber',
                    'RequestCode'
                ]
            );

        if (
            $headerRequestId &&
            $headerRequestNo
        ) {

            $joinSql = "
                LEFT JOIN dbo.[{$headerTable}] H
                    ON H.[{$headerRequestId}]
                    = I.[{$requestIdColumn}]
            ";

            $select[] =
                "H.[{$headerRequestNo}] AS [request_no]";
        } else {

            $select[] =
                "CAST('' AS nvarchar(100)) AS [request_no]";
        }
    } else {

        /*
         * Maybe RequestNo is already stored
         * in IssuanceTransactions.
         */

        $select[] =
            sqlSelectColumn(
                'I',
                $columns,
                [
                    'RequestNo',
                    'RequestNumber',
                    'IssueRequestNo'
                ],
                'request_no',
                "CAST('' AS nvarchar(100))"
            );
    }


    /*
     * ORDER BY
     */

    if ($issuedAtColumn) {

        $orderBy =
            "I.[{$issuedAtColumn}] DESC";
    } else {

        $idColumn =
            findColumn(
                $columns,
                [
                    'IssuanceTransactionID',
                    'TransactionID',
                    'IssuanceID',
                    'ID'
                ]
            );

        if ($idColumn) {

            $orderBy =
                "I.[{$idColumn}] DESC";
        } elseif ($traceColumn) {

            $orderBy =
                "I.[{$traceColumn}] DESC";
        } elseif ($itemColumn) {

            $orderBy =
                "I.[{$itemColumn}] ASC";
        } else {

            $orderBy =
                '(SELECT 0)';
        }
    }


    /*
     * FINAL QUERY
     */

    $sql = "
        SELECT TOP ({$limit})
            " .
        implode(
            ",\n",
            $select
        ) .
        "
        FROM dbo.[{$transactionTable}] I

        {$joinSql}

        ORDER BY
            {$orderBy}
    ";


    $stmt =
        sqlsrv_query(
            $conn,
            $sql
        );


    if ($stmt === false) {

        throw new RuntimeException(
            'Unable to load issued transactions. ' .
                sqlServerErrorsText()
        );
    }


    $rows = [];


    while (
        $row =
        sqlsrv_fetch_array(
            $stmt,
            SQLSRV_FETCH_ASSOC
        )
    ) {

        /*
         * SQLSRV normally returns datetime fields
         * as DateTime objects.
         */

        $issuedAt =
            $row['issued_at'] ??
            null;

        if (
            $issuedAt instanceof
            DateTimeInterface
        ) {

            $issuedAt =
                $issuedAt->format(
                    'Y-m-d H:i:s'
                );
        } elseif (
            $issuedAt !== null
        ) {

            $issuedAt =
                (string) $issuedAt;
        } else {

            $issuedAt = '';
        }


        $rows[] = [

            'trace_no' =>
            trim(
                (string) (
                    $row['trace_no'] ??
                    ''
                )
            ),

            'request_no' =>
            trim(
                (string) (
                    $row['request_no'] ??
                    ''
                )
            ),

            'item_code' =>
            trim(
                (string) (
                    $row['item_code'] ??
                    ''
                )
            ),

            'part_name' =>
            trim(
                (string) (
                    $row['part_name'] ??
                    ''
                )
            ),

            'parts_code' =>
            trim(
                (string) (
                    $row['parts_code'] ??
                    ''
                )
            ),

            'location_code' =>
            trim(
                (string) (
                    $row['location_code'] ??
                    ''
                )
            ),

            'quantity' =>
            (float) (
                $row['quantity'] ??
                0
            ),

            'lot_no' =>
            trim(
                (string) (
                    $row['lot_no'] ??
                    ''
                )
            ),

            'warehouse_lot_no' =>
            trim(
                (string) (
                    $row['warehouse_lot_no'] ??
                    ''
                )
            ),

            'itr_number' =>
            trim(
                (string) (
                    $row['itr_number'] ??
                    ''
                )
            ),

            'itr_doc_entry' =>
            trim(
                (string) (
                    $row['itr_doc_entry'] ??
                    ''
                )
            ),

            'itr_line_num' =>
            trim(
                (string) (
                    $row['itr_line_num'] ??
                    ''
                )
            ),

            'request_id' =>
            trim(
                (string) (
                    $row['request_id'] ??
                    ''
                )
            ),

            'request_line_id' =>
            trim(
                (string) (
                    $row['request_line_id'] ??
                    ''
                )
            ),

            'stock_whs_code' =>
            trim(
                (string) (
                    $row['stock_whs_code'] ??
                    '01'
                )
            ),

            'issued_by' =>
            trim(
                (string) (
                    $row['issued_by'] ??
                    ''
                )
            ),

            'issued_at' =>
            $issuedAt
        ];
    }


    echo json_encode(
        [
            'ok' => true,

            'count' =>
            count(
                $rows
            ),

            'issuances' =>
            $rows
        ],
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {

    http_response_code(
        500
    );

    echo json_encode(
        [
            'ok' => false,

            'message' =>
            $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
    );
}