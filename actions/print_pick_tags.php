<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/zebra_print.php';

require_role([ROLE_PICKER, ROLE_ADMIN]);

$wantsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

function picker_print_fail(string $message, int $code = 400): void
{
    global $wantsJson;

    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $message,
        ]);
        exit;
    }

    app_error($message, $code);
}

$items = json_decode($_POST['batch_items'] ?? '[]', true);

if (!is_array($items) || count($items) === 0) {
    picker_print_fail('No pick tags to print.', 400);
}

$printerKey = zebra_pick_printer_key($_POST['pick_printer'] ?? null);
$printerName = zebra_pick_printer_label_for_key($printerKey);

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
    picker_print_fail('No valid pick tags to print.', 400);
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
            picker_print_fail('Unable to create folder: ' . $dir, 500);
        }
    }

    if (!is_writable($dir)) {
        picker_print_fail('Folder is not writable: ' . $dir, 500);
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
$jobTempFile = $jobDir . DIRECTORY_SEPARATOR . 'pick_' . $jobId . '.tmp';

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
    'printer_key' => $printerKey,
    'printer_name' => $printerName,
    'failed_validation' => $failed,
    'items' => $saved,
];

$jobJson = json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($jobJson === false) {
    picker_print_fail('Unable to encode print job JSON.', 500);
}

$jobSaved = file_put_contents($jobTempFile, $jobJson, LOCK_EX);

if ($jobSaved === false || !@rename($jobTempFile, $jobFile)) {
    @unlink($jobTempFile);
    picker_print_fail('Unable to save print job file.', 500);
}

$jobFileReal = realpath($jobFile);

if ($jobFileReal === false || !is_file($jobFileReal)) {
    picker_print_fail('Print job file was saved but cannot be found.', 500);
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
    'Printer: ' . $printerName . ' (' . $printerKey . ')' . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/*
|--------------------------------------------------------------------------
| Return to picker immediately
|--------------------------------------------------------------------------
| Start the hidden print worker with this exact job file, then return immediately.
| If direct startup fails, fall back to the legacy Windows Scheduled Task.
*/
$taskName = 'Warehouse Picker Print Queue';
$launcherFile = $rootDir . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'run_picker_print_queue_hidden.vbs';
$directCmd = 'wscript.exe //B ' . zebra_cmd_arg($launcherFile) . ' ' . zebra_cmd_arg($jobFileReal);
$taskCmd = 'schtasks /Run /TN "' . $taskName . '"';
$directStarted = false;
$taskStarted = false;

if (is_file($launcherFile) && function_exists('popen')) {
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
    'Direct CMD: ' . $directCmd . PHP_EOL .
    'Direct Started: ' . ($directStarted ? 'YES' : 'NO') . PHP_EOL .
    'Fallback Task CMD: ' . $taskCmd . PHP_EOL .
    'Task Started: ' . ($taskStarted ? 'YES' : 'NO') . PHP_EOL .
    str_repeat('-', 80) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

$payload = [
    'ok' => true,
    'queued' => count($saved),
    'job_id' => $jobId,
    'printer_key' => $printerKey,
    'printer_name' => $printerName,
    'trigger_message' => $directStarted
        ? 'The print worker was started.'
        : ($taskStarted
            ? 'The print task was triggered.'
            : 'The print job was queued, but the print task could not be started automatically.'),
    'failed_validation' => count($failed),
];

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$query = http_build_query([
    'print_queued' => count($saved),
    'print_job' => $jobId,
    'print_printer' => $printerName,
    'print_trigger' => $payload['trigger_message']
]);

header('Location: ' . app_path('pages/picker/picker.php?' . $query));
exit;
?>
