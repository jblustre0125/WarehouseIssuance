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
            'reason' => 'Missing item, qty, or lot.',
        ];
        continue;
    }

    if (!is_numeric($qty) || (float)$qty <= 0) {
        $failed[] = [
            'item' => $item,
            'reason' => 'Quantity must be greater than zero.',
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
|--------------------------------------------------------------------------
| Root folders
|--------------------------------------------------------------------------
*/
$rootDir = dirname(__DIR__);
$storageDir = $rootDir . DIRECTORY_SEPARATOR . 'storage';
$jobDir = $storageDir . DIRECTORY_SEPARATOR . 'print_jobs';
$logDir = $storageDir . DIRECTORY_SEPARATOR . 'print_logs';

/*
|--------------------------------------------------------------------------
| Create required folders
|--------------------------------------------------------------------------
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
|--------------------------------------------------------------------------
| Save print job file
|--------------------------------------------------------------------------
| The web system only creates the JSON job.
| The Windows Scheduled Task will process the job instantly.
*/
$jobId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$jobFile = $jobDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '.json';

$currentUser = current_user();

$jobData = [
    'job_id' => $jobId,
    'created_at' => date('Y-m-d H:i:s'),
    'created_by' => [
        'username' => $currentUser['username'] ?? '',
        'name' => $currentUser['name'] ?? '',
        'role' => $currentUser['role'] ?? '',
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
|--------------------------------------------------------------------------
| Log queued job
|--------------------------------------------------------------------------
*/
$queueLog = $logDir . DIRECTORY_SEPARATOR . 'worker_start_' . date('Ymd') . '.log';

file_put_contents(
    $queueLog,
    '[' . date('Y-m-d H:i:s') . '] Picker tag queued' . PHP_EOL .
    'Job ID: ' . $jobId . PHP_EOL .
    'Job file: ' . $jobFileReal . PHP_EOL .
    'Queued by: ' . ($currentUser['username'] ?? '') . PHP_EOL .
    'Printer: ' . zebra_pick_printer_name() . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
|--------------------------------------------------------------------------
| Instant print trigger
|--------------------------------------------------------------------------
| This triggers the Windows Scheduled Task immediately.
| The Scheduled Task runs as the Windows user that can access the printer.
*/
$taskName = 'Warehouse Picker Print Queue';
$taskCmd = 'schtasks /Run /TN "' . $taskName . '"';

$taskOutput = [];
$taskExitCode = 0;

exec($taskCmd . ' 2>&1', $taskOutput, $taskExitCode);

file_put_contents(
    $queueLog,
    'Trigger CMD: ' . $taskCmd . PHP_EOL .
    'Trigger Exit code: ' . $taskExitCode . PHP_EOL .
    'Trigger Output: ' . implode(PHP_EOL, $taskOutput) . PHP_EOL .
    str_repeat('-', 80) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
|--------------------------------------------------------------------------
| Return queued result
|--------------------------------------------------------------------------
| Do not check printed/failed immediately here.
| The Scheduled Task processes the print job separately.
*/
$pageTitle = 'Pick Tags Queued';
$backUrl = 'pages/picker/picker.php';

$messages = [
    count($saved) . ' picker tag(s) queued for printing.',
    'The print task was triggered and should print shortly.',
    'Job ID: ' . $jobId,
    'You may return to the picker page and continue working.',
];

if ($taskExitCode !== 0) {
    $messages[] = 'Warning: The print job was saved, but the scheduled task trigger returned an error.';
    $messages[] = 'Check storage/print_logs/worker_start_' . date('Ymd') . '.log';
}

if (count($failed) > 0) {
    $messages[] = count($failed) . ' item(s) failed validation before printing.';
}

$zebraPrintResult = [
    'enabled' => true,
    'ok' => true,
    'printed' => 0,
    'failed' => count($failed),
    'printer_name' => zebra_pick_printer_name(),
    'messages' => $messages,
];

include __DIR__ . '/../pages/results/print_pick_result.php';
exit;
?>