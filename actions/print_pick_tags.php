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

/*
    Locate background worker.
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
    Worker start log.
*/
$startLog = $logDir . DIRECTORY_SEPARATOR . 'worker_start_' . date('Ymd') . '.log';

file_put_contents(
    $startLog,
    '[' . date('Y-m-d H:i:s') . '] Preparing worker' . PHP_EOL .
    'Job ID: ' . $jobId . PHP_EOL .
    'PHP: ' . $phpBinary . PHP_EOL .
    'Worker: ' . $workerFile . PHP_EOL .
    'Job file: ' . $jobFile . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
    Start background worker.

    IMPORTANT:
    Use cmd start instead of PowerShell Start-Process.
    PowerShell quoting caused:
    "A positional parameter cannot be found..."
*/
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $cmd =
        'cmd /c start "" /B '
        . '"' . $phpBinary . '" '
        . '"' . $workerFile . '" '
        . '"' . $jobFile . '"';

    $output = [];
    $exitCode = 0;

    exec($cmd . ' 2>&1', $output, $exitCode);

    file_put_contents(
        $startLog,
        'CMD: ' . $cmd . PHP_EOL .
        'Exit code: ' . $exitCode . PHP_EOL .
        'Output: ' . implode(' ', $output) . PHP_EOL .
        str_repeat('-', 80) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
} else {
    $cmd =
        escapeshellarg($phpBinary) . ' ' .
        escapeshellarg($workerFile) . ' ' .
        escapeshellarg($jobFile) .
        ' > /dev/null 2>&1 &';

    exec($cmd);

    file_put_contents(
        $startLog,
        'CMD: ' . $cmd . PHP_EOL .
        str_repeat('-', 80) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/*
    Return immediately.
    Actual printing continues in the background worker.
*/
$pageTitle = 'Pick Tags Queued';
$backUrl = 'pages/picker/picker.php';

$zebraPrintResult = [
    'enabled' => true,
    'ok' => true,
    'printed' => 0,
    'failed' => count($failed),
    'printer_name' => zebra_pick_printer_name(),
    'messages' => [
        count($saved) . ' picker tag(s) were queued for background printing.',
        'You may return to the picker page and continue working.',
        'The worker will print the tags one by one.',
        'Job ID: ' . $jobId,
        'Check storage/print_logs for worker result.'
    ],
];

include __DIR__ . '/../pages/results/print_pick_result.php';
?>