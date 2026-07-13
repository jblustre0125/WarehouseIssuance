<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
raw_material_qr_print_require_access();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $conn = get_whpokayoke_connection();

    $requiredColumns = ['ItemCode', 'PartsCode', 'ItemName', 'LocationCode', 'IsActive'];
    $placeholders = implode(',', array_fill(0, count($requiredColumns), '?'));

    $sql = "
        SELECT TOP 1
            c.TABLE_SCHEMA,
            c.TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS c
        WHERE c.COLUMN_NAME IN ($placeholders)
        GROUP BY c.TABLE_SCHEMA, c.TABLE_NAME
        HAVING COUNT(DISTINCT c.COLUMN_NAME) = ?
        ORDER BY
            CASE WHEN c.TABLE_SCHEMA = 'dbo' THEN 0 ELSE 1 END,
            c.TABLE_NAME
    ";

    $params = array_merge($requiredColumns, [count($requiredColumns)]);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new RuntimeException(sqlsrv_fail_message());
    }

    $table = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$table) {
        json_out([
            'ok' => false,
            'message' => 'No raw-material master table was found with ItemCode, PartsCode, ItemName, LocationCode, and IsActive columns.'
        ], 500);
    }

    $schema = str_replace(']', ']]', (string)$table['TABLE_SCHEMA']);
    $name = str_replace(']', ']]', (string)$table['TABLE_NAME']);
    $qualifiedTable = '[' . $schema . '].[' . $name . ']';

    $rowsStmt = sqlsrv_query(
        $conn,
        "SELECT
            CAST(ItemCode AS NVARCHAR(100)) AS ItemCode,
            CAST(PartsCode AS NVARCHAR(300)) AS PartsCode,
            CAST(ItemName AS NVARCHAR(200)) AS ItemName,
            CAST(LocationCode AS NVARCHAR(100)) AS LocationCode
         FROM {$qualifiedTable} WITH (NOLOCK)
         WHERE ISNULL(IsActive, 0) = 1
           AND LTRIM(RTRIM(ISNULL(CAST(ItemCode AS NVARCHAR(100)), ''))) <> ''
         ORDER BY LocationCode, ItemCode"
    );

    if ($rowsStmt === false) {
        throw new RuntimeException(sqlsrv_fail_message());
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($rowsStmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = [
            'item_code' => trim((string)($row['ItemCode'] ?? '')),
            'parts_code' => trim((string)($row['PartsCode'] ?? '')),
            'item_name' => trim((string)($row['ItemName'] ?? '')),
            'location_code' => trim((string)($row['LocationCode'] ?? '')),
        ];
    }

    json_out([
        'ok' => true,
        'source_table' => $table['TABLE_SCHEMA'] . '.' . $table['TABLE_NAME'],
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
