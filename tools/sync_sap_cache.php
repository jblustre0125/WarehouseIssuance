<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sap_cache.php';

const SAP_CACHE_FAST_REFRESH_SECONDS = 60;
const SAP_CACHE_MEDIUM_REFRESH_SECONDS = 120;
const SAP_CACHE_SLOW_REFRESH_SECONDS = 300;
const SAP_CACHE_HEAVY_REFRESH_SECONDS = 1800;

$syncMode = strtolower(trim((string)($argv[1] ?? 'light')));
$includeHeavyTasks = in_array($syncMode, ['heavy', 'all'], true);

function sync_log($message)
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function sync_lock_path()
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sap_cache_sync.lock';
}

function sync_acquire_lock()
{
    $lockPath = sync_lock_path();
    $dir = dirname($lockPath);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $handle = fopen($lockPath, 'c+');

    if (!$handle) {
        sync_log('Unable to create SAP cache lock file.');
        return null;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        $age = file_exists($lockPath) ? time() - (int)filemtime($lockPath) : 0;
        $staleSeconds = defined('SAP_CACHE_LOCK_STALE_SECONDS') ? (int)SAP_CACHE_LOCK_STALE_SECONDS : 900;
        $note = $age > $staleSeconds ? ' Lock file is older than expected; check for a stuck PHP process.' : '';
        sync_log('Another SAP cache refresh is already running. Skipping this run.' . $note);
        fclose($handle);
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, getmypid() . ' ' . date('Y-m-d H:i:s'));
    fflush($handle);

    return $handle;
}

function sync_run_api($route, $role = 'admin', $username = 'cache_sync', $userId = 0)
{
    $php = PHP_BINARY;
    $helper = __DIR__ . DIRECTORY_SEPARATOR . 'run_api_cache_refresh.php';
    $cmd = [$php, $helper, $route, $role, $username, (string)$userId];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $started = microtime(true);
    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__));

    if (!is_resource($process)) {
        return [
            'ok' => false,
            'route' => $route,
            'message' => 'Unable to start PHP refresh process.',
            'seconds' => 0,
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $seconds = round(microtime(true) - $started, 3);
    $payload = json_decode((string)$stdout, true);

    if ($exitCode !== 0) {
        return [
            'ok' => false,
            'route' => $route,
            'message' => trim($stderr) ?: trim($stdout) ?: 'Refresh process failed.',
            'seconds' => $seconds,
            'exit_code' => $exitCode,
        ];
    }

    if (!is_array($payload)) {
        return [
            'ok' => false,
            'route' => $route,
            'message' => 'Refresh returned invalid JSON: ' . substr(trim($stdout), 0, 300),
            'seconds' => $seconds,
        ];
    }

    return [
        'ok' => (bool)($payload['ok'] ?? false),
        'route' => $route,
        'message' => (string)($payload['message'] ?? 'OK'),
        'seconds' => $seconds,
        'count' => count($payload['documents'] ?? $payload['requests'] ?? $payload['stocks'] ?? $payload['lines'] ?? $payload['rows'] ?? []),
        'cache' => $payload['_cache'] ?? null,
    ];
}

function sync_record_start($conn, $scope)
{
    $stmt = sqlsrv_query(
        $conn,
        "INSERT INTO dbo.SapCacheSyncLog (ScopeName, Status)
         OUTPUT INSERTED.SyncID
         VALUES (?, 'RUNNING')",
        [$scope]
    );

    if ($stmt === false) {
        return 0;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    return (int)($row['SyncID'] ?? 0);
}

function sync_record_finish($conn, $syncId, $status, $message, $rowCount = null)
{
    if ($syncId <= 0) {
        return;
    }

    sqlsrv_query(
        $conn,
        "UPDATE dbo.SapCacheSyncLog
         SET FinishedAt = GETDATE(),
             Status = ?,
             Message = ?,
             [RowCount] = ?
         WHERE SyncID = ?",
        [$status, substr((string)$message, 0, 1000), $rowCount, $syncId]
    );
}

function sync_scope_name($route, $role, $username)
{
    return substr($route . ' [' . $role . ':' . $username . ']', 0, 80);
}

function sync_recent_success($conn, $scope, $withinSeconds)
{
    $withinSeconds = max(1, (int)$withinSeconds);
    $row = fetch_one(
        $conn,
        "SELECT TOP 1
            DATEDIFF(second, FinishedAt, GETDATE()) AS AgeSeconds
         FROM dbo.SapCacheSyncLog
         WHERE ScopeName = ?
           AND Status = 'SUCCESS'
           AND FinishedAt IS NOT NULL
         ORDER BY FinishedAt DESC",
        [$scope]
    );

    if (!$row) {
        return false;
    }

    return (int)($row['AgeSeconds'] ?? PHP_INT_MAX) < $withinSeconds;
}

function sync_task_selected($route, $interval, $syncMode, $includeHeavyTasks)
{
    if ($syncMode === 'all') {
        return true;
    }

    if ($syncMode === 'itr') {
        return strpos($route, 'api/get_open_itr_requests.php') === 0 ||
            strpos($route, 'api/requestor/list_sap_inventory_transfers.php') === 0;
    }

    if ($syncMode === 'po') {
        return strpos($route, 'api/picker/open_purchase_orders.php') === 0;
    }

    if ($syncMode === 'requests') {
        return strpos($route, 'api/get_open_issue_requests.php') === 0 ||
            strpos($route, 'api/requestor/list_requests.php') === 0;
    }

    if (strpos($route, 'api/stocks/list.php') === 0) {
        return true;
    }

    if (strpos($route, 'api/requestor/list_sap_inventory_transfers.php') === 0) {
        return true;
    }

    if (!$includeHeavyTasks && $interval >= SAP_CACHE_HEAVY_REFRESH_SECONDS) {
        return false;
    }

    return true;
}

$lockHandle = sync_acquire_lock();

if ($lockHandle === null) {
    exit(0);
}

$whp = get_whpokayoke_connection();

if (!sap_cache_table_ready($whp)) {
    sync_log('SapDataCache table does not exist. Run database/schema.sql first.');
    exit(1);
}

$tasks = [
    ['route' => 'api/get_open_issue_requests.php', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_FAST_REFRESH_SECONDS],
    ['route' => 'api/stocks/list.php?scope=issuer', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/stocks/list.php?scope=requestor', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/picker/open_purchase_orders.php', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    // Preload the first five small page-cache payloads for the picker report.
    ['route' => 'api/picker/open_grpo_receipts.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&page=1', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/picker/open_grpo_receipts.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&page=2', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/picker/open_grpo_receipts.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&page=3', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/picker/open_grpo_receipts.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&page=4', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/picker/open_grpo_receipts.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&page=5', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/get_open_itr_requests.php', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_FAST_REFRESH_SECONDS],
    ['route' => 'api/requestor/list_sap_inventory_transfers.php?max=50', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS],
    ['route' => 'api/requestor/list_requests.php', 'role' => 'admin', 'username' => 'cache_sync', 'interval' => SAP_CACHE_FAST_REFRESH_SECONDS],
];

$requestors = fetch_all(
    $whp,
    "SELECT UserID, Username, RequestorSection
     FROM dbo.AppUsers
     WHERE RoleName = 'requestor'
       AND IsActive = 1
       AND RequestorSection IS NOT NULL
       AND LTRIM(RTRIM(RequestorSection)) <> ''
     ORDER BY RequestorSection, Username"
);

$seenSections = [];

foreach ($requestors as $requestor) {
    $sectionKey = strtolower(trim((string)$requestor['RequestorSection']));

    if ($sectionKey === '' || isset($seenSections[$sectionKey])) {
        continue;
    }

    $seenSections[$sectionKey] = true;
    $userId = (int)$requestor['UserID'];
    $username = (string)$requestor['Username'];

    $tasks[] = ['route' => 'api/get_open_itr_requests.php', 'role' => 'requestor', 'username' => $username, 'user_id' => $userId, 'interval' => SAP_CACHE_FAST_REFRESH_SECONDS];
    $tasks[] = ['route' => 'api/stocks/list.php?scope=requestor', 'role' => 'requestor', 'username' => $username, 'user_id' => $userId, 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS];
    $tasks[] = ['route' => 'api/requestor/list_sap_inventory_transfers.php?max=50', 'role' => 'requestor', 'username' => $username, 'user_id' => $userId, 'interval' => SAP_CACHE_HEAVY_REFRESH_SECONDS];
    $tasks[] = ['route' => 'api/requestor/list_requests.php', 'role' => 'requestor', 'username' => $username, 'user_id' => $userId, 'interval' => SAP_CACHE_FAST_REFRESH_SECONDS];
}

sap_cache_purge_expired($whp);

$success = 0;
$failed = 0;

foreach ($tasks as $task) {
    $route = $task['route'];
    $role = $task['role'] ?? 'admin';
    $username = $task['username'] ?? 'cache_sync';
    $userId = (int)($task['user_id'] ?? 0);
    $interval = (int)($task['interval'] ?? SAP_CACHE_FAST_REFRESH_SECONDS);
    $scope = sync_scope_name($route, $role, $username);

    if (!sync_task_selected($route, $interval, $syncMode, $includeHeavyTasks)) {
        sync_log('Skipping cache outside ' . $syncMode . ' mode ' . $scope);
        continue;
    }

    if (sync_recent_success($whp, $scope, $interval)) {
        sync_log('Skipping recent cache ' . $scope . " (interval {$interval}s)");
        continue;
    }

    $syncId = sync_record_start($whp, $scope);

    sync_log('Refreshing ' . $scope);
    $result = sync_run_api($route, $role, $username, $userId);

    if ($result['ok']) {
        $success++;
        $message = 'OK in ' . $result['seconds'] . 's, count=' . $result['count'];
        sync_record_finish($whp, $syncId, 'SUCCESS', $message, $result['count']);
        sync_log('  ' . $message);
    } else {
        $failed++;
        $message = $result['message'] ?? 'Failed';
        sync_record_finish($whp, $syncId, 'FAILED', $message, null);
        sync_log('  FAILED: ' . $message);
    }
}

sync_log("Done. Success={$success}; Failed={$failed}");
exit($failed > 0 ? 1 : 0);

?>
