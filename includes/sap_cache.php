<?php

const SAP_CACHE_DEFAULT_TTL_SECONDS = 300;

function sap_cache_config_bool($name, $default = false)
{
    if (!defined($name)) {
        return (bool)$default;
    }

    $value = constant($name);

    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function sap_cache_browser_live_queries_enabled()
{
    return sap_cache_config_bool('SAP_BROWSER_LIVE_QUERIES_ENABLED', true);
}

function sap_cache_live_queries_enabled()
{
    return PHP_SAPI === 'cli' || sap_cache_browser_live_queries_enabled();
}

function sap_cache_stale_reads_enabled()
{
    return sap_cache_config_bool('SAP_CACHE_ALLOW_STALE_READS', true);
}

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

function sap_cache_get($conn, $cacheKey, $allowExpired = false, $maxExpiredAgeSeconds = null)
{
    if (!sap_cache_table_ready($conn)) {
        return null;
    }

    $params = [$cacheKey];
    $whereExpiry = 'AND ExpiresAt > GETDATE()';

    if ($allowExpired) {
        $whereExpiry = '';

        if ($maxExpiredAgeSeconds !== null) {
            $whereExpiry = 'AND ExpiresAt > DATEADD(second, ?, GETDATE())';
            $params[] = -max(1, (int)$maxExpiredAgeSeconds);
        }
    }

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
        $params
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
        'stale' => $row['ExpiresAt'] instanceof DateTimeInterface ? $row['ExpiresAt'] <= new DateTimeImmutable() : strtotime((string)($row['ExpiresAt'] ?? '')) <= time(),
        'cached_at' => $cachedAt,
        'expires_at' => $expiresAt,
        'key' => $cacheKey
    ];

    return $payload;
}

function sap_cache_get_latest_by_scope($conn, $scope, $maxStaleSeconds = null)
{
    if (!sap_cache_table_ready($conn)) {
        return null;
    }

    $params = [trim((string)$scope)];
    $whereExpiry = '';

    if ($maxStaleSeconds !== null) {
        $whereExpiry = 'AND ExpiresAt > DATEADD(second, ?, GETDATE())';
        $params[] = -max(60, (int)$maxStaleSeconds);
    } elseif (!sap_cache_stale_reads_enabled()) {
        $whereExpiry = 'AND ExpiresAt > GETDATE()';
    }

    $row = fetch_one(
        $conn,
        "SELECT TOP 1
            CacheKey,
            ScopeName,
            PayloadJson,
            CachedAt,
            ExpiresAt
         FROM dbo.SapDataCache
         WHERE ScopeName = ?
           AND PayloadJson IS NOT NULL
           AND LTRIM(RTRIM(PayloadJson)) <> ''
           {$whereExpiry}
         ORDER BY CachedAt DESC",
        $params
    );

    if (!$row) {
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
        'latest_scope_hit' => true,
        'stale' => $row['ExpiresAt'] instanceof DateTimeInterface ? $row['ExpiresAt'] <= new DateTimeImmutable() : strtotime((string)($row['ExpiresAt'] ?? '')) <= time(),
        'cached_at' => $cachedAt,
        'expires_at' => $expiresAt,
        'key' => (string)($row['CacheKey'] ?? ''),
        'scope' => (string)($row['ScopeName'] ?? $scope)
    ];

    return $payload;
}

function sap_cache_max_stale_seconds()
{
    if (!defined('SAP_CACHE_MAX_STALE_SECONDS')) {
        return 21600;
    }

    return max(60, (int)constant('SAP_CACHE_MAX_STALE_SECONDS'));
}

function sap_cache_get_preferred($conn, $cacheKey, $maxStaleSeconds = null)
{
    if (sap_cache_should_refresh()) {
        return null;
    }

    $cached = sap_cache_get($conn, $cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    if (!sap_cache_stale_reads_enabled()) {
        return null;
    }

    $maxStaleSeconds = $maxStaleSeconds === null
        ? sap_cache_max_stale_seconds()
        : max(60, (int)$maxStaleSeconds);

    return sap_cache_get($conn, $cacheKey, true, $maxStaleSeconds);
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

    if (!in_array($value, ['1', 'true', 'yes', 'force'], true)) {
        return false;
    }

    if (PHP_SAPI === 'cli') {
        return true;
    }

    return sap_cache_browser_live_queries_enabled() && sap_cache_config_bool('SAP_BROWSER_MANUAL_REFRESH_ENABLED', false);
}

function sap_cache_live_disabled_payload($message = '')
{
    return [
        'ok' => false,
        'message' => $message !== ''
            ? $message
            : 'Live SAP queries are disabled for browser requests. Please wait for the scheduled SAP cache refresh.',
        '_cache' => [
            'hit' => false,
            'live_queries_enabled' => false
        ]
    ];
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
