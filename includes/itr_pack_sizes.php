<?php

function itr_pack_sizes_path()
{
    return __DIR__ . '/../data/itr_qty_per_pack_june_2026.json';
}

function itr_pack_sizes_json()
{
    $packSizes = [];
    $path = itr_pack_sizes_path();

    if (!is_file($path)) {
        return $packSizes;
    }

    $payload = json_decode((string)file_get_contents($path), true);

    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        return $packSizes;
    }

    foreach ($payload['items'] as $itemCode => $qtyPerPack) {
        $code = itr_pack_normalize_item_code($itemCode);

        if ($code === '' || !is_numeric($qtyPerPack) || (float)$qtyPerPack <= 0) {
            continue;
        }

        $packSizes[$code] = (float)$qtyPerPack;
    }

    return $packSizes;
}

function itr_pack_sizes_ensure_table($conn)
{
    $create = sqlsrv_query(
        $conn,
        "IF OBJECT_ID('dbo.RawMaterialQtyPerPack', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.RawMaterialQtyPerPack (
                ItemCode NVARCHAR(50) NOT NULL PRIMARY KEY,
                PartName NVARCHAR(255) NULL,
                QtyPerPack DECIMAL(18,3) NOT NULL,
                SourceName NVARCHAR(160) NULL,
                IsActive BIT NOT NULL DEFAULT 1,
                CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
                UpdatedAt DATETIME NULL,
                UpdatedByUsername NVARCHAR(60) NULL
            );
         END"
    );

    if ($create === false) {
        return false;
    }

    foreach ([
        'PartName' => 'NVARCHAR(255) NULL',
        'QtyPerPack' => 'DECIMAL(18,3) NOT NULL DEFAULT 0',
        'SourceName' => 'NVARCHAR(160) NULL',
        'IsActive' => 'BIT NOT NULL DEFAULT 1',
        'CreatedAt' => 'DATETIME NOT NULL DEFAULT GETDATE()',
        'UpdatedAt' => 'DATETIME NULL',
        'UpdatedByUsername' => 'NVARCHAR(60) NULL',
    ] as $column => $definition) {
        sqlsrv_query(
            $conn,
            "IF COL_LENGTH('dbo.RawMaterialQtyPerPack', '{$column}') IS NULL
             BEGIN
                ALTER TABLE dbo.RawMaterialQtyPerPack ADD {$column} {$definition};
             END"
        );
    }

    sqlsrv_query(
        $conn,
        "IF NOT EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'IX_RawMaterialQtyPerPack_Search'
              AND object_id = OBJECT_ID('dbo.RawMaterialQtyPerPack')
         )
         BEGIN
            CREATE INDEX IX_RawMaterialQtyPerPack_Search
            ON dbo.RawMaterialQtyPerPack(IsActive, ItemCode, QtyPerPack);
         END"
    );

    return true;
}

function itr_pack_sizes_seed_from_json($conn)
{
    if (!itr_pack_sizes_ensure_table($conn)) {
        return 0;
    }

    $existing = fetch_one($conn, "SELECT COUNT(*) AS Cnt FROM dbo.RawMaterialQtyPerPack");
    if ((int)($existing['Cnt'] ?? 0) > 0) {
        return 0;
    }

    $packSizes = itr_pack_sizes_json();
    $inserted = 0;

    foreach ($packSizes as $itemCode => $qtyPerPack) {
        $stmt = sqlsrv_query(
            $conn,
            "MERGE dbo.RawMaterialQtyPerPack AS T
             USING (
                SELECT ? AS ItemCode, ? AS QtyPerPack, ? AS SourceName
             ) AS S
             ON T.ItemCode = S.ItemCode
             WHEN NOT MATCHED THEN
                INSERT (ItemCode, QtyPerPack, SourceName, IsActive, UpdatedByUsername)
                VALUES (S.ItemCode, S.QtyPerPack, S.SourceName, 1, 'system-seed');",
            [$itemCode, $qtyPerPack, 'June 2026 Excel SUMMARY']
        );

        if ($stmt !== false && sqlsrv_rows_affected($stmt) > 0) {
            $inserted++;
        }
    }

    return $inserted;
}

function itr_pack_sizes_can_maintain($user = null)
{
    $user = $user ?: (function_exists('current_user') ? current_user() : []);
    $role = strtolower((string)($user['role'] ?? ''));
    $fullName = strtolower(trim((string)($user['full_name'] ?? '')));

    return $role === ROLE_ADMIN ||
        $fullName === 'edwin sanchez' ||
        (strpos($fullName, 'edwin') !== false && strpos($fullName, 'sanchez') !== false);
}

function itr_pack_part_names_by_codes(array $itemCodes)
{
    $codes = [];

    foreach ($itemCodes as $itemCode) {
        $code = itr_pack_normalize_item_code($itemCode);

        if ($code !== '') {
            $codes[$code] = true;
        }
    }

    if (empty($codes) || !function_exists('get_erp_connection')) {
        return [];
    }

    $erp = get_erp_connection();

    if (
        !fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OITM'") ||
        !fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OITM' AND COLUMN_NAME = 'ItemName'")
    ) {
        return [];
    }

    $names = [];

    foreach (array_chunk(array_keys($codes), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $rows = fetch_all(
            $erp,
            "SELECT
                ItemCode,
                COALESCE(CAST(ItemName AS NVARCHAR(255)), '') AS PartName
             FROM OITM
             WHERE ItemCode IN ({$placeholders})
                OR RIGHT(REPLICATE('0', 11) + LTRIM(RTRIM(CAST(ItemCode AS NVARCHAR(50)))), 11) IN ({$placeholders})",
            array_merge($chunk, $chunk)
        );

        foreach ($rows as $row) {
            $code = itr_pack_normalize_item_code($row['ItemCode'] ?? '');
            $name = trim((string)($row['PartName'] ?? ''));

            if ($code !== '' && $name !== '') {
                $names[$code] = $name;
            }
        }
    }

    return $names;
}

function itr_pack_item_codes_matching_part_name($query, $limit = 500)
{
    $query = trim((string)$query);
    $limit = max(1, min(1000, (int)$limit));

    if ($query === '' || !function_exists('get_erp_connection')) {
        return [];
    }

    $erp = get_erp_connection();

    if (
        !fetch_one($erp, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'OITM'") ||
        !fetch_one($erp, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'OITM' AND COLUMN_NAME = 'ItemName'")
    ) {
        return [];
    }

    $rows = fetch_all(
        $erp,
        "SELECT TOP {$limit} ItemCode
         FROM OITM
         WHERE ItemName LIKE ?
         ORDER BY ItemCode",
        ['%' . $query . '%']
    );

    $codes = [];

    foreach ($rows as $row) {
        $code = itr_pack_normalize_item_code($row['ItemCode'] ?? '');

        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return $codes;
}

function itr_pack_sizes_require_maintainer()
{
    require_login();

    if (!itr_pack_sizes_can_maintain(current_user())) {
        http_response_code(403);
        die('Access denied.');
    }
}

function itr_pack_sizes_cache_token()
{
    $path = itr_pack_sizes_path();
    $fileToken = is_file($path) ? (string)filemtime($path) : 'missing';

    if (function_exists('get_whpokayoke_connection')) {
        $conn = get_whpokayoke_connection();

        if ($conn && itr_pack_sizes_ensure_table($conn)) {
            $row = fetch_one(
                $conn,
                "SELECT
                    COUNT(*) AS Cnt,
                    MAX(COALESCE(UpdatedAt, CreatedAt)) AS LastChanged
                 FROM dbo.RawMaterialQtyPerPack"
            );

            if ($row) {
                $lastChanged = $row['LastChanged'] ?? '';
                if ($lastChanged instanceof DateTimeInterface) {
                    $lastChanged = $lastChanged->format('YmdHis');
                }

                return 'db:' . (int)($row['Cnt'] ?? 0) . ':' . (string)$lastChanged . ':file:' . $fileToken;
            }
        }
    }

    return $fileToken;
}

function itr_pack_sizes()
{
    static $packSizes = null;

    if ($packSizes !== null) {
        return $packSizes;
    }

    $packSizes = [];

    if (function_exists('get_whpokayoke_connection')) {
        $conn = get_whpokayoke_connection();

        if ($conn && itr_pack_sizes_ensure_table($conn)) {
            $rows = fetch_all(
                $conn,
                "SELECT ItemCode, QtyPerPack
                 FROM dbo.RawMaterialQtyPerPack
                 WHERE IsActive = 1
                   AND QtyPerPack > 0"
            );

            foreach ($rows as $row) {
                $code = itr_pack_normalize_item_code($row['ItemCode'] ?? '');
                $qtyPerPack = (float)($row['QtyPerPack'] ?? 0);

                if ($code !== '' && $qtyPerPack > 0) {
                    $packSizes[$code] = $qtyPerPack;
                }
            }
        }
    }

    if (!empty($packSizes)) {
        return $packSizes;
    }

    return itr_pack_sizes_json();
}

function itr_pack_normalize_item_code($itemCode)
{
    $code = strtoupper(trim((string)$itemCode));

    if ($code !== '' && ctype_digit($code)) {
        $code = str_pad($code, 11, '0', STR_PAD_LEFT);
    }

    return $code;
}

function itr_qty_per_pack_for_item($itemCode)
{
    $packSizes = itr_pack_sizes();
    $code = itr_pack_normalize_item_code($itemCode);

    return $packSizes[$code] ?? 0.0;
}

?>
