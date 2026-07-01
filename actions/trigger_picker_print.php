<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/zebra_print.php';

require_role([ROLE_PICKER, ROLE_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

function picker_trigger_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$jobId = trim((string)($_POST['job_id'] ?? $_GET['job_id'] ?? ''));

if (!preg_match('/^\d{14}_[a-f0-9]{8}$/i', $jobId)) {
    picker_trigger_json([
        'ok' => false,
        'message' => 'Invalid print job id.',
    ], 400);
}

$rootDir = dirname(__DIR__);
$storageDir = $rootDir . DIRECTORY_SEPARATOR . 'storage';
$jobFile = $storageDir . DIRECTORY_SEPARATOR . 'print_jobs' . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '.json';
$logDir = $storageDir . DIRECTORY_SEPARATOR . 'print_logs';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$queueLog = $logDir . DIRECTORY_SEPARATOR . 'worker_start_' . date('Ymd') . '.log';
$launcherFile = $rootDir . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'run_picker_print_queue_hidden.vbs';
$taskName = 'Warehouse Picker Print Queue';
$directCmd = 'wscript.exe //B ' . zebra_cmd_arg($launcherFile) . ' ' . zebra_cmd_arg($jobFile);
$taskCmd = 'schtasks /Run /TN "' . $taskName . '"';
$directStarted = false;
$taskStarted = false;

if (is_file($jobFile) && function_exists('popen')) {
    $handle = @popen($directCmd, 'r');

    if (is_resource($handle)) {
        @pclose($handle);
        $directStarted = true;
    }
}

if (!$directStarted && function_exists('popen')) {
    $handle = @popen($taskCmd, 'r');

    if (is_resource($handle)) {
        @pclose($handle);
        $taskStarted = true;
    }
}

file_put_contents(
    $queueLog,
    '[' . date('Y-m-d H:i:s') . '] Picker print trigger' . PHP_EOL .
    'Job ID: ' . $jobId . PHP_EOL .
    'Job file: ' . $jobFile . PHP_EOL .
    'Job file exists: ' . (is_file($jobFile) ? 'YES' : 'NO') . PHP_EOL .
    'Direct CMD: ' . $directCmd . PHP_EOL .
    'Direct Started: ' . ($directStarted ? 'YES' : 'NO') . PHP_EOL .
    'Fallback Task CMD: ' . $taskCmd . PHP_EOL .
    'Fallback Task Started: ' . ($taskStarted ? 'YES' : 'NO') . PHP_EOL .
    str_repeat('-', 80) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

picker_trigger_json([
    'ok' => $directStarted || $taskStarted,
    'job_id' => $jobId,
    'direct_started' => $directStarted,
    'task_started' => $taskStarted,
    'message' => $directStarted
        ? 'The print worker was started.'
        : ($taskStarted ? 'The scheduled print task was triggered.' : 'Unable to start the print worker.'),
], $directStarted || $taskStarted ? 200 : 500);

?>
