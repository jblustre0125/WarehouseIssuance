<?php

const SAP_CACHE_DEFAULT_TTL_SECONDS = 300;

function sap_cache_table_ready($conn)
{
    return (bool)fetch_one(
        $conn,
        "SELECT 1 AS HasTable
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = 'SapDataCache'"
    );
}

function sap_cache_make_key($scope, array $parts = [])
{
    ksort($parts);

    return strtolower(trim((string)$scope)) . ':' . hash('sha256', json_encode($parts));
}

function sap_cache_get($conn, $cacheKey, $allowExpired = false)
{
    if (!sap_cache_table_ready($conn)) {
        return null;
    }

    $whereExpiry = $allowExpired ? '' : 'AND ExpiresAt > GETDATE()';

    $row = fetch_one(
        $conn,
        "SELECT TOP 1
            CacheKey,
            ScopeName,
            PayloadJson,
            CachedAt,
            ExpiresAt
         FROM dbo.SapDataCache
         WHERE CacheKey = ?
           {$whereExpiry}",
        [$cacheKey]
    );

    if (!$row || trim((string)($row['PayloadJson'] ?? '')) === '') {
        return null;
    }

    $payload = json_decode((string)$row['PayloadJson'], true);

    if (!is_array($payload)) {
        return null;
    }

    $cachedAt = $row['CachedAt'] instanceof DateTimeInterface
        ? $row['CachedAt']->format('Y-m-d H:i:s')
        : (string)($row['CachedAt'] ?? '');

    $expiresAt = $row['ExpiresAt'] instanceof DateTimeInterface
        ? $row['ExpiresAt']->format('Y-m-d H:i:s')
        : (string)($row['ExpiresAt'] ?? '');

    $payload['_cache'] = [
        'hit' => true,
        'cached_at' => $cachedAt,
        'expires_at' => $expiresAt,
        'key' => $cacheKey
    ];

    return $payload;
}

function sap_cache_put($conn, $scope, $cacheKey, array $payload, $ttlSeconds = SAP_CACHE_DEFAULT_TTL_SECONDS)
{
    if (!sap_cache_table_ready($conn)) {
        return false;
    }

    $ttlSeconds = max(30, (int)$ttlSeconds);
    $payload['_cache'] = [
        'hit' => false,
        'cached_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        'key' => $cacheKey
    ];

    $json = json_encode($payload);

    if ($json === false) {
        return false;
    }

    $stmt = sqlsrv_query(
        $conn,
        "MERGE dbo.SapDataCache AS T
         USING (
            SELECT
                ? AS CacheKey,
                ? AS ScopeName,
                CAST(? AS NVARCHAR(MAX)) AS PayloadJson,
                DATEADD(second, ?, GETDATE()) AS ExpiresAt
         ) AS S
            ON T.CacheKey = S.CacheKey
         WHEN MATCHED THEN
            UPDATE SET
                ScopeName = S.ScopeName,
                PayloadJson = S.PayloadJson,
                CachedAt = GETDATE(),
                ExpiresAt = S.ExpiresAt
         WHEN NOT MATCHED THEN
            INSERT (CacheKey, ScopeName, PayloadJson, CachedAt, ExpiresAt)
            VALUES (S.CacheKey, S.ScopeName, S.PayloadJson, GETDATE(), S.ExpiresAt);",
        [$cacheKey, $scope, $json, $ttlSeconds]
    );

    return $stmt !== false;
}

function sap_cache_should_refresh()
{
    $value = strtolower(trim((string)($_GET['refresh'] ?? $_POST['refresh'] ?? '')));

    return in_array($value, ['1', 'true', 'yes', 'force'], true);
}

function sap_cache_json_out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function sap_cache_remember_json($whp, $scope, array $parts, $ttlSeconds, callable $producer)
{
    $cacheKey = sap_cache_make_key($scope, $parts);
    $forceRefresh = sap_cache_should_refresh();

    if (!$forceRefresh) {
        $cached = sap_cache_get($whp, $cacheKey);

        if ($cached !== null) {
            sap_cache_json_out($cached);
        }
    }

    $payload = $producer();

    if (is_array($payload) && ($payload['ok'] ?? false)) {
        sap_cache_put($whp, $scope, $cacheKey, $payload, $ttlSeconds);
    }

    $payload['_cache'] = [
        'hit' => false,
        'cached_at' => date('Y-m-d H:i:s'),
        'key' => $cacheKey,
        'stored' => sap_cache_table_ready($whp) && (($payload['ok'] ?? false) === true)
    ];

    sap_cache_json_out($payload);
}

function sap_cache_purge_expired($conn)
{
    if (!sap_cache_table_ready($conn)) {
        return false;
    }

    $stmt = sqlsrv_query(
        $conn,
        "DELETE FROM dbo.SapDataCache
         WHERE ExpiresAt < DATEADD(day, -1, GETDATE())"
    );

    return $stmt !== false;
}

?>
