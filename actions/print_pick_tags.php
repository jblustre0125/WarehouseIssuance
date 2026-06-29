<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/zebra_print.php';

require_role([ROLE_PICKER, ROLE_ADMIN]);

$items = json_decode($_POST['batch_items'] ?? '[]', true);

if (!is_array($items) || count($items) === 0) {
    app_error('No pick tags to print.', 400);
}

$saved = [];
$failed = [];

foreach ($items as $item) {
    $itemCode = trim((string)($item['item_code'] ?? ''));
    $partName = trim((string)($item['part_name'] ?? ''));
    $qty = trim((string)($item['quantity'] ?? ''));
    $lotNo = trim((string)($item['lot_no'] ?? ''));

    if ($itemCode === '' || $qty === '' || $lotNo === '') {
        $failed[] = [
            'item' => $item,
            'reason' => 'Missing item, qty, or lot.'
        ];
        continue;
    }

    if (!is_numeric($qty) || (float)$qty <= 0) {
        $failed[] = [
            'item' => $item,
            'reason' => 'Quantity must be greater than zero.'
        ];
        continue;
    }

    $item['item_code'] = $itemCode;
    $item['part_name'] = $partName;
    $item['quantity'] = $qty;
    $item['lot_no'] = $lotNo;
    $item['qr_payload'] = zebra_pick_qr_payload($item);
    $item['picked_by'] = current_user()['username'] ?? '';

    $saved[] = $item;
}

if (count($saved) === 0) {
    app_error('No valid pick tags to print.', 400);
}

/*
    Root folders.
*/
$rootDir = dirname(__DIR__);
$storageDir = $rootDir . DIRECTORY_SEPARATOR . 'storage';
$jobDir = $storageDir . DIRECTORY_SEPARATOR . 'print_jobs';
$logDir = $storageDir . DIRECTORY_SEPARATOR . 'print_logs';

/*
    Create required folders.
*/
foreach ([$storageDir, $jobDir, $logDir] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            app_error('Unable to create folder: ' . $dir, 500);
        }
    }

    if (!is_writable($dir)) {
        app_error('Folder is not writable: ' . $dir, 500);
    }
}

/*
    Save print job file.
*/
$jobId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$jobFile = $jobDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '.json';

$jobData = [
    'job_id' => $jobId,
    'created_at' => date('Y-m-d H:i:s'),
    'created_by' => [
        'username' => current_user()['username'] ?? '',
        'name' => current_user()['name'] ?? '',
        'role' => current_user()['role'] ?? '',
    ],
    'total_tags' => count($saved),
    'failed_validation' => $failed,
    'items' => $saved,
];

$jobSaved = file_put_contents(
    $jobFile,
    json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($jobSaved === false) {
    app_error('Unable to save print job file.', 500);
}

$jobFileReal = realpath($jobFile);

if ($jobFileReal === false || !is_file($jobFileReal)) {
    app_error('Print job file was saved but cannot be found.', 500);
}

/*
    Locate worker.
*/
$workerFile = realpath($rootDir . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'print_picker_worker.php');

if ($workerFile === false || !is_file($workerFile)) {
    app_error('Print worker file was not found: workers/print_picker_worker.php', 500);
}

/*
    Locate XAMPP PHP CLI.
*/
$phpBinary = 'C:\\Xampp\\php\\php.exe';

if (!is_file($phpBinary)) {
    $phpBinary = 'C:\\xampp\\php\\php.exe';
}

if (!is_file($phpBinary)) {
    $phpBinary = PHP_BINARY;
}

if (!is_file($phpBinary)) {
    app_error('PHP CLI executable was not found.', 500);
}

/*
    Logs.
*/
$startLog = $logDir . DIRECTORY_SEPARATOR . 'worker_start_' . date('Ymd') . '.log';
$autoLog = $logDir . DIRECTORY_SEPARATOR . 'auto_worker_' . date('Ymd') . '.log';

file_put_contents(
    $startLog,
    '[' . date('Y-m-d H:i:s') . '] Preparing worker' . PHP_EOL .
    'Job ID: ' . $jobId . PHP_EOL .
    'PHP: ' . $phpBinary . PHP_EOL .
    'Worker: ' . $workerFile . PHP_EOL .
    'Job file: ' . $jobFileReal . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
    IMPORTANT FIX:
    Do not use cmd /c start /B for now.

    We run the worker directly so automatic printing really executes.
    The page will wait until the worker finishes, but this is the safest
    way to confirm automatic printing works.
*/
$cmd =
    '"' . $phpBinary . '" ' .
    '"' . $workerFile . '" ' .
    '"' . $jobFileReal . '"';

$output = [];
$exitCode = 0;

exec($cmd . ' >> "' . $autoLog . '" 2>&1', $output, $exitCode);

file_put_contents(
    $startLog,
    'CMD: ' . $cmd . PHP_EOL .
    'Exit code: ' . $exitCode . PHP_EOL .
    'Output: ' . implode(PHP_EOL, $output) . PHP_EOL .
    str_repeat('-', 80) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
    Check final result files.
*/
$doneDir = $storageDir . DIRECTORY_SEPARATOR . 'print_done';
$errorDir = $storageDir . DIRECTORY_SEPARATOR . 'print_errors';

$printedFile = $doneDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '_printed.json';
$failedFile = $errorDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '_failed.json';
$errorTextFile = $errorDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '_error.txt';

$workerOk = is_file($printedFile);
$workerFailed = is_file($failedFile) || is_file($errorTextFile);

$messages = [];

if ($workerOk) {
    $messages[] = count($saved) . ' picker tag(s) were printed successfully.';
    $messages[] = 'Job ID: ' . $jobId;
} elseif ($workerFailed) {
    $messages[] = 'Picker tag printing failed.';
    $messages[] = 'Job ID: ' . $jobId;
    $messages[] = is_file($failedFile)
        ? 'Check this file: storage/print_errors/pick_' . $jobId . '_failed.json'
        : 'Check this file: storage/print_errors/pick_' . $jobId . '_error.txt';
} else {
    $messages[] = 'Worker finished but no printed/failed result file was found.';
    $messages[] = 'Job ID: ' . $jobId;
    $messages[] = 'Check storage/print_logs/auto_worker_' . date('Ymd') . '.log';
}

if (count($failed) > 0) {
    $messages[] = count($failed) . ' item(s) failed validation before printing.';
}

/*
    Show result page.
*/
$pageTitle = $workerOk ? 'Pick Tags Printed' : 'Pick Tags Print Result';
$backUrl = 'pages/picker/picker.php';

$zebraPrintResult = [
    'enabled' => true,
    'ok' => $workerOk,
    'printed' => $workerOk ? count($saved) : 0,
    'failed' => $workerOk ? count($failed) : count($saved),
    'printer_name' => zebra_pick_printer_name(),
    'messages' => $messages,
];

include __DIR__ . '/../pages/results/print_pick_result.php';
?>
