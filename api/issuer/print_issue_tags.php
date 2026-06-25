<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lot_balance_lib.php';

$items = json_decode((string)($_POST['batch_items'] ?? '[]'), true);

if (!is_array($items) || count($items) === 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'No tags to print.'
    ]);
    exit;
}

$erp = get_erp_connection();
$whp = get_whpokayoke_connection();
$validation = issuer_validate_batch_lot_balances($erp, $whp, $items);

if (!$validation['ok']) {
    echo json_encode($validation);
    exit;
}

$loadedZebra = false;
$zebraFiles = [
    __DIR__ . '/../../includes/zebra_print.php',
    __DIR__ . '/../../includes/zebra.php',
    __DIR__ . '/../../includes/zebra_printer.php',
    __DIR__ . '/../../includes/zebra_helper.php',
    __DIR__ . '/../../zebra_print.php'
];

foreach ($zebraFiles as $file) {
    if (is_file($file)) {
        require_once $file;
        $loadedZebra = true;
        break;
    }
}

if (!$loadedZebra || !function_exists('zebra_print_pick_labels')) {
    echo json_encode([
        'ok' => false,
        'message' => 'Zebra print helper was not found. Put your Zebra helper in includes/zebra_print.php or update api/issuer/print_issue_tags.php to the correct path.'
    ]);
    exit;
}

$printItems = [];

foreach ($items as $item) {
    $warehouseLotNo = trim((string)($item['warehouse_lot_no'] ?? ''));

    if ($warehouseLotNo === '') {
        echo json_encode([
            'ok' => false,
            'message' => 'Warehouse lot number is required for every printed tag.'
        ]);
        exit;
    }

    $printItems[] = [
        'item_code' => trim((string)($item['item_code'] ?? '')),
        'part_name' => trim((string)($item['part_name'] ?? '')),
        'quantity' => trim((string)($item['quantity'] ?? '')),
        'lot_no' => trim((string)($item['lot_no'] ?? '')),
        'warehouse_lot_no' => $warehouseLotNo,
        'uom' => trim((string)($item['uom'] ?? '')),
        'request_no' => trim((string)($item['request_no'] ?? '')),
        'itr_number' => trim((string)($item['itr_number'] ?? '')),
        'itr_doc_num' => trim((string)($item['itr_doc_num'] ?? '')),
        'doc_num' => trim((string)($item['itr_number'] ?? '')),
        'source_type' => 'issue'
    ];
}

$result = zebra_print_pick_labels($printItems);

echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok'])
        ? 'Tags were sent to Zebra printer. Printed: ' . (int)($result['printed'] ?? 0) . '.'
        : 'Unable to print tags.',
    'printed' => (int)($result['printed'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'messages' => $result['messages'] ?? [],
    'validation' => $validation
]);
exit;
?>
