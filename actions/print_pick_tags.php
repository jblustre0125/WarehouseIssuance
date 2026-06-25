<?php
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

$pageTitle = 'Pick Tags Ready';
$backUrl = 'pages/picker/picker.php';
$zebraPrintResult = zebra_print_pick_labels($saved);

include __DIR__ . '/../pages/results/print_pick_result.php';
?>
