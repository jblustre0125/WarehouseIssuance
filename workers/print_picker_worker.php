<?php

declare(strict_types=1);

/*
    Background worker for picker tag printing.
    Called by: actions/print_pick_tags.php
*/

if (PHP_SAPI !== 'cli') {
    exit("This worker can only run from command line.\n");
}

ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$storageDir = __DIR__ . '/../storage';
$logDir = $storageDir . '/print_logs';
$doneDir = $storageDir . '/print_done';
$errorDir = $storageDir . '/print_errors';

foreach ([$storageDir, $logDir, $doneDir, $errorDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

$bootstrapLog = $logDir . '/picker_worker_bootstrap_' . date('Ymd') . '.log';

function picker_worker_boot_log(string $file, string $message): void
{
    file_put_contents(
        $file,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function picker_worker_log(string $logFile, string $message): void
{
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

$jobFile = $argv[1] ?? '';
$jobFile = trim($jobFile, " \t\n\r\0\x0B\"'");

picker_worker_boot_log($bootstrapLog, 'Worker started');
picker_worker_boot_log($bootstrapLog, 'PHP: ' . PHP_BINARY);
picker_worker_boot_log($bootstrapLog, 'CWD: ' . getcwd());
picker_worker_boot_log($bootstrapLog, 'DIR: ' . __DIR__);
picker_worker_boot_log($bootstrapLog, 'ARGV: ' . json_encode($argv ?? []));
picker_worker_boot_log($bootstrapLog, 'Job file: ' . $jobFile);
picker_worker_boot_log($bootstrapLog, 'Job file exists: ' . (is_file($jobFile) ? 'YES' : 'NO'));

$job = null;
$jobId = basename($jobFile, '.json');
$logFile = $logDir . '/picker_print_' . date('Ymd') . '.log';

if ($jobFile === '' || !is_file($jobFile)) {
    picker_worker_boot_log($bootstrapLog, 'FAILED: Print job file not found: ' . $jobFile);
    exit(1);
}

try {
    /*
        Load job first so even if config/print code fails,
        we can still create a proper failed JSON result.
    */
    $rawJob = (string)file_get_contents($jobFile);
    $job = json_decode($rawJob, true);

    if (!is_array($job) || empty($job['items']) || !is_array($job['items'])) {
        throw new RuntimeException('Invalid print job file.');
    }

    $jobId = (string)($job['job_id'] ?? basename($jobFile, '.json'));
    $logFile = $logDir . '/picker_print_' . date('Ymd') . '.log';

    picker_worker_log($logFile, 'START Job ' . $jobId . ' | Tags: ' . count($job['items']));

    /*
        IMPORTANT:
        These are inside try/catch now.
        If config.php or zebra_print.php has an error,
        the worker will create pick_JOB_failed.json.
    */
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/zebra_print.php';

    picker_worker_log(
        $logFile,
        'ENV Job ' . $jobId
        . ' | Computer: ' . (getenv('COMPUTERNAME') ?: '')
        . ' | User: ' . (getenv('USERNAME') ?: '')
        . ' | Printer: ' . (function_exists('zebra_pick_printer_name') ? zebra_pick_printer_name() : '')
    );

    if (!function_exists('zebra_print_picker_tags')) {
        throw new RuntimeException('Function zebra_print_picker_tags() does not exist. Check includes/zebra_print.php.');
    }

    $lockFile = $storageDir . '/nitto_picker_print.lock';
    $lockHandle = fopen($lockFile, 'c');

    if (!$lockHandle) {
        throw new RuntimeException('Unable to create lock file.');
    }

    flock($lockHandle, LOCK_EX);

    try {
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
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    /*
        Move original job file after result file is created.
    */
    if (is_file($jobFile)) {
        $processedJobFile = $doneDir . '/pick_' . $jobId . '_submitted.json';
        @rename($jobFile, $processedJobFile);
    }

    exit(0);

} catch (Throwable $e) {
    picker_worker_log($logFile, 'ERROR Job ' . $jobId . ' | ' . $e->getMessage());

    $failedCount = 1;
    if (is_array($job) && isset($job['items']) && is_array($job['items'])) {
        $failedCount = count($job['items']);
    }

    $printerName = '';
    if (function_exists('zebra_pick_printer_name')) {
        $printerName = zebra_pick_printer_name();
    }

    $resultData = [
        'job_id' => $jobId,
        'status' => 'FAILED',
        'created_at' => is_array($job) ? ($job['created_at'] ?? null) : null,
        'finished_at' => date('Y-m-d H:i:s'),
        'result' => [
            'enabled' => true,
            'ok' => false,
            'printed' => 0,
            'failed' => $failedCount,
            'printer_name' => $printerName,
            'bytes_sent' => 0,
            'messages' => [
                'Print worker error: ' . $e->getMessage(),
            ],
        ],
        'job' => $job,
        'exception' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ],
    ];

    file_put_contents(
        $errorDir . '/pick_' . $jobId . '_failed.json',
        json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    file_put_contents(
        $errorDir . '/pick_' . $jobId . '_error.txt',
        $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
        LOCK_EX
    );

    if (is_file($jobFile)) {
        $processedJobFile = $doneDir . '/pick_' . $jobId . '_submitted.json';
        @rename($jobFile, $processedJobFile);
    }

    exit(1);
}