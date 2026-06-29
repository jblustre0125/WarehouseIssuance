<?php

declare(strict_types=1);

/*
    Background worker for picker tag printing.

    This file is called by:
        actions/print_pick_tags.php

    Example:
        php workers/print_picker_worker.php "storage/print_jobs/pick_xxxxx.json"
*/

if (PHP_SAPI !== 'cli') {
    exit("This worker can only run from command line.\n");
}

$jobFile = $argv[1] ?? '';

if ($jobFile === '' || !is_file($jobFile)) {
    exit("Print job file not found.\n");
}

/*
    Load config and print functions.
    Adjust config path only if your config.php is in another folder.
*/
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/zebra_print.php';
$job = json_decode((string)file_get_contents($jobFile), true);

if (!is_array($job) || empty($job['items']) || !is_array($job['items'])) {
    exit("Invalid print job file.\n");
}

$storageDir = __DIR__ . '/../storage';
$logDir = $storageDir . '/print_logs';
$doneDir = $storageDir . '/print_done';
$errorDir = $storageDir . '/print_errors';

foreach ([$storageDir, $logDir, $doneDir, $errorDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

$lockFile = $storageDir . '/nitto_picker_print.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle) {
    exit("Unable to create lock file.\n");
}

$jobId = (string)($job['job_id'] ?? basename($jobFile));
$logFile = $logDir . '/picker_print_' . date('Ymd') . '.log';

function picker_worker_log(string $logFile, string $message): void
{
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/*
    This lock is important:
    - Picker A can send 50 tags.
    - Picker B can also send tags.
    - Browser returns immediately.
    - But this worker prints one job at a time to avoid printer conflict.
*/
flock($lockHandle, LOCK_EX);

try {
    picker_worker_log($logFile, 'START Job ' . $jobId . ' | Tags: ' . count($job['items']));

    $result = zebra_print_picker_tags($job['items']);

    $ok = !empty($result['ok']);
    $status = $ok ? 'PRINTED' : 'FAILED';

    $resultData = [
        'job_id' => $jobId,
        'status' => $status,
        'created_at' => $job['created_at'] ?? null,
        'finished_at' => date('Y-m-d H:i:s'),
        'result' => $result,
        'job' => $job,
    ];

    $resultFile = ($ok ? $doneDir : $errorDir)
        . '/pick_' . $jobId . '_' . strtolower($status) . '.json';

    file_put_contents(
        $resultFile,
        json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    picker_worker_log(
        $logFile,
        'END Job ' . $jobId
        . ' | Status: ' . $status
        . ' | Printed: ' . ($result['printed'] ?? 0)
        . ' | Failed: ' . ($result['failed'] ?? 0)
    );

    /*
        Move original job file to done folder.
    */
    $processedJobFile = $doneDir . '/pick_' . $jobId . '_submitted.json';
    @rename($jobFile, $processedJobFile);

} catch (Throwable $e) {
    picker_worker_log($logFile, 'ERROR Job ' . $jobId . ' | ' . $e->getMessage());

    file_put_contents(
        $errorDir . '/pick_' . $jobId . '_error.txt',
        $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
        LOCK_EX
    );
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);