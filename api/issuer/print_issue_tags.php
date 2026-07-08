<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/zebra_print.php';

require_role([ROLE_ISSUER, ROLE_ADMIN]);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Method Not Allowed.'
    ]);
    exit;
}

$items = json_decode($_POST['batch_items'] ?? '[]', true);

if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'No issue tags to print.'
    ]);
    exit;
}

$printerKey = zebra_pick_printer_key($_POST['issue_printer'] ?? ($_POST['pick_printer'] ?? null));
$printItems = [];
$errors = [];

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        $errors[] = 'Line ' . ($idx + 1) . ' is invalid.';
        continue;
    }

    $itemCode = trim((string)($item['item_code'] ?? ''));
    $quantity = trim((string)($item['quantity'] ?? ''));
    $quantityNumber = (float)str_replace(',', '', $quantity);
    $grpoLotNo = trim((string)($item['lot_no'] ?? ''));
    $warehouseLotNo = trim((string)($item['warehouse_lot_no'] ?? ''));

    if ($itemCode === '' || $quantity === '' || $quantityNumber <= 0 || $grpoLotNo === '') {
        $errors[] = 'Line ' . ($idx + 1) . ' requires item code, quantity, and GRPO lot number.';
        continue;
    }

    /*
        WH Lot No is optional for issuer printing.
        The GRPO lot is still required because it is part of the QR payload.
    */

    $printItems[] = [
        'item_code' => $itemCode,
        'part_name' => trim((string)($item['part_name'] ?? '')),
        /*
            Quantity is printed as text and encoded in the QR only.
            It must NOT be used as Zebra copy count. zebra_print.php forces ^PQ1.
        */
        'quantity' => rtrim(rtrim(number_format($quantityNumber, 3, '.', ''), '0'), '.'),
        'uom' => trim((string)($item['uom'] ?? '')),
        'lot_no' => $grpoLotNo,
        'warehouse_lot_no' => $warehouseLotNo,
        'request_no' => trim((string)($item['request_no'] ?? '')),
        'itr_number' => trim((string)($item['itr_number'] ?? ($item['itr_doc_num'] ?? ''))),
        'itr_doc_num' => trim((string)($item['itr_doc_num'] ?? ($item['itr_number'] ?? ''))),
        'doc_num' => trim((string)($item['doc_num'] ?? ($item['itr_doc_num'] ?? ($item['itr_number'] ?? '')))),
        'source_type' => trim((string)($item['source_type'] ?? 'issue_request')),
    ];
}

if ($errors !== []) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => implode(' ', $errors)
    ]);
    exit;
}

$result = zebra_print_picker_tags($printItems, $printerKey);

if (empty($result['ok'])) {
    http_response_code(500);
}

echo json_encode([
    'ok' => !empty($result['ok']),
    'enabled' => !empty($result['enabled']),
    'printed' => (int)($result['printed'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'printer_key' => $result['printer_key'] ?? $printerKey,
    'printer_name' => $result['printer_name'] ?? zebra_pick_printer_label_for_key($printerKey),
    'bytes_sent' => (int)($result['bytes_sent'] ?? 0),
    'messages' => $result['messages'] ?? [],
    'message' => (!empty($result['ok']))
        ? ((int)($result['printed'] ?? 0) . ' issue tag(s) printed on ' . ($result['printer_name'] ?? zebra_pick_printer_label_for_key($printerKey)) . '.')
        : implode(' ', $result['messages'] ?? ['Unable to print issue tags.'])
]);
