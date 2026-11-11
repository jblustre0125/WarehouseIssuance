<?php

// CLI regression checks. Never sends bytes to an installed serial port.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'bluetooth');
define('ZEBRA_BLUETOOTH_COM_PORT', '');
require_once __DIR__ . '/../includes/zebra_print.php';

function bluetooth_check($condition, $description)
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

foreach (['COM1', 'COM10', 'COM123'] as $port) {
    bluetooth_check(zebra_bluetooth_com_port($port) === $port, 'Accept ' . $port);
}
bluetooth_check(zebra_bluetooth_com_port(' com10: ') === 'COM10', 'Normalize port case, whitespace, and colon');
foreach (['', 'COM0', 'COM01', 'LPT1', 'COM100000', 'COM10 & whoami', 'COM10"', "COM10\nCOM11", '\\\\localhost\\COM10'] as $port) {
    bluetooth_check(zebra_bluetooth_com_port($port) === '', 'Reject invalid port ' . json_encode($port));
}

$result = zebra_send_label_to_target('^XA^XZ', ['connection' => ' BLUETOOTH ']);
bluetooth_check(!$result['ok'] && strpos($result['message'], 'ZEBRA_BLUETOOTH_COM_PORT') !== false, 'Bluetooth routing reports missing configuration');
$result = zebra_send_to_bluetooth_printer('', 'COM99999');
bluetooth_check(!$result['ok'], 'Reject empty label');

$item = ['item_code' => 'RM-TEST', 'quantity' => 1, 'lot_no' => 'LOT-TEST', 'warehouse_lot_no' => 'WH-TEST'];
foreach ([
    'receiving' => zebra_print_receive_labels('TEST', [$item]),
    'picker' => zebra_print_picker_tags([$item], 'zebra'),
    'issuer' => zebra_print_issue_tags([$item], 'zebra'),
] as $workflow => $result) {
    bluetooth_check(!$result['ok'] && $result['printed'] === 0 && $result['failed'] === 1
        && strpos(implode(' ', $result['messages']), 'ZEBRA_BLUETOOTH_COM_PORT') !== false,
        $workflow . ' uses Bluetooth and reports transport failure');
}
$result = zebra_print_test_label();
bluetooth_check(!$result['ok'] && strpos($result['message'], 'ZEBRA_BLUETOOTH_COM_PORT') !== false, 'Test label uses Bluetooth');

if (PHP_OS_FAMILY === 'Windows' && function_exists('proc_open')) {
    // COM99999 is intentionally absent; the script checks existence before opening.
    $lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'warehouse_zebra_COM99999.lock', 'c');
    try {
        flock($lock, LOCK_EX);
        $result = zebra_send_to_bluetooth_printer('^XA^XZ', 'COM99999');
        bluetooth_check(!$result['ok'] && strpos($result['message'], 'busy') !== false, 'Concurrent print fails without opening a port');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_bt_*');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $result = zebra_send_label_to_target('^XA^XZ', ['connection' => 'bluetooth', 'com_port' => 'COM99999']);
        bluetooth_check(!$result['ok'] && strpos($result['message'], 'COM99999 was not found') !== false,
            'PowerShell reports missing port and releases lock, attempt ' . ($attempt + 1));
    }
    bluetooth_check($before === glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_bt_*'), 'Temporary files are cleaned after transport failure');
}

echo "Bluetooth regression checks passed. Physical printer verification is still required.\n";
