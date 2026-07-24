<?php

function item_locations_ensure_table($conn)
{
    $create = sqlsrv_query(
        $conn,
        "IF OBJECT_ID('dbo.RawMaterialItemLocations', 'U') IS NULL
         BEGIN
            CREATE TABLE dbo.RawMaterialItemLocations (
                ItemCode NVARCHAR(50) NOT NULL PRIMARY KEY,
                PartsCode NVARCHAR(120) NULL,
                ItemName NVARCHAR(255) NULL,
                LocationCode NVARCHAR(120) NULL,
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
        'PartsCode' => 'NVARCHAR(120) NULL',
        'ItemName' => 'NVARCHAR(255) NULL',
        'LocationCode' => 'NVARCHAR(120) NULL',
        'IsActive' => 'BIT NOT NULL DEFAULT 1',
        'CreatedAt' => 'DATETIME NOT NULL DEFAULT GETDATE()',
        'UpdatedAt' => 'DATETIME NULL',
        'UpdatedByUsername' => 'NVARCHAR(60) NULL',
    ] as $column => $definition) {
        sqlsrv_query(
            $conn,
            "IF COL_LENGTH('dbo.RawMaterialItemLocations', '{$column}') IS NULL
             BEGIN
                ALTER TABLE dbo.RawMaterialItemLocations ADD {$column} {$definition};
             END"
        );
    }

    sqlsrv_query(
        $conn,
        "IF NOT EXISTS (
            SELECT 1
            FROM sys.indexes
            WHERE name = 'IX_RawMaterialItemLocations_Search'
              AND object_id = OBJECT_ID('dbo.RawMaterialItemLocations')
         )
         BEGIN
            CREATE INDEX IX_RawMaterialItemLocations_Search
            ON dbo.RawMaterialItemLocations(IsActive, LocationCode, PartsCode, ItemName);
         END"
    );

    return true;
}

function item_locations_can_maintain($user = null)
{
    $user = $user ?: (function_exists('current_user') ? current_user() : []);
    $role = strtolower((string)($user['role'] ?? ''));
    $username = strtolower(trim((string)($user['username'] ?? '')));
    $fullName = strtolower(trim((string)($user['full_name'] ?? '')));

    return $role === ROLE_ADMIN ||
        $username === '2111-002' ||
        $fullName === 'michael banaban' ||
        $fullName === 'edwin sanchez' ||
        (strpos($fullName, 'edwin') !== false && strpos($fullName, 'sanchez') !== false);
}

function item_locations_require_maintainer()
{
    require_login();

    if (!item_locations_can_maintain(current_user())) {
        http_response_code(403);
        die('Access denied.');
    }
}

function item_locations_by_codes($conn, array $itemCodes)
{
    if (empty($itemCodes) || !item_locations_ensure_table($conn)) {
        return [];
    }

    $codes = [];

    foreach ($itemCodes as $itemCode) {
        $itemCode = trim((string)$itemCode);

        if ($itemCode !== '') {
            $codes[$itemCode] = true;
        }
    }

    $codes = array_keys($codes);

    if (empty($codes)) {
        return [];
    }

    $locations = [];

    foreach (array_chunk($codes, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $rows = fetch_all(
            $conn,
            "SELECT ItemCode, PartsCode, ItemName, LocationCode
             FROM dbo.RawMaterialItemLocations
             WHERE IsActive = 1
               AND ItemCode IN ({$placeholders})",
            $chunk
        );

        foreach ($rows as $row) {
            $locations[(string)$row['ItemCode']] = [
                'item_code' => (string)$row['ItemCode'],
                'parts_code' => (string)($row['PartsCode'] ?? ''),
                'item_name' => (string)($row['ItemName'] ?? ''),
                'location_code' => (string)($row['LocationCode'] ?? ''),
            ];
        }
    }

    return $locations;
}

function item_location_for_code($conn, $itemCode)
{
    $locations = item_locations_by_codes($conn, [$itemCode]);
    return $locations[trim((string)$itemCode)] ?? null;
}

?>
