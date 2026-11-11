<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_item_batch.php';
require_once __DIR__ . '/../../includes/zebra_print.php';
require_once __DIR__ . '/../../includes/item_locations.php';

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
$itemCodes = [];
$missingPartCodes = [];
$erp = get_erp_connection();

foreach ($items as $item) {
    if (is_array($item)) {
        $itemCode = trim((string)($item['item_code'] ?? ''));

        if ($itemCode !== '') {
            $itemCodes[] = $itemCode;
            if (zebra_issue_part_number($item) === '') {
                $missingPartCodes[$itemCode] = true;
            }
        }
    }
}

$batchStatuses = sap_item_batch_statuses($erp, $itemCodes);
$itemLocations = $missingPartCodes === [] ? [] : item_locations_by_codes($whp, array_keys($missingPartCodes));

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

    $batchStatus = $batchStatuses[$itemCode] ?? sap_item_batch_status($erp, $itemCode);

    if (!$batchStatus['managed']) {
        $errors[] = 'Line ' . ($idx + 1) . ': ' . $batchStatus['message'];
        continue;
    }

    /*
        WH Lot No is optional for issuer printing.
        The GRPO lot is still required because it is part of the QR payload.
        A supplied WH lot is encoded separately in the WH LOT QR.
    */

    $printItems[] = [
        'item_code' => $itemCode,
        'parts_code' => zebra_issue_part_number($item, $itemLocations[$itemCode] ?? []),
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

if (($_POST['print_transport'] ?? '') === 'tablet_bluetooth') {
    if ($printerKey !== 'zebra') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Tablet Bluetooth printing requires Zebra QLn320.']);
        exit;
    }
    require_once __DIR__ . '/../../includes/tablet_print.php';
    $payload = zebra_prepare_tablet_labels($printItems, true);
    if (!$payload['ok']) {
        http_response_code(400);
    }
    echo json_encode($payload);
    exit;
}

$result = zebra_print_issue_tags($printItems, $printerKey);

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
