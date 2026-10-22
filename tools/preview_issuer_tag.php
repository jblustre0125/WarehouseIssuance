<?php

/* CLI only: render sample files without accessing the database or a printer. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PICK_TAG_IMAGE_SCALE', 1);
require_once __DIR__ . '/../includes/zebra_print.php';

$outputDir = $argv[1] ?? (__DIR__ . '/../docs/issuer-tag');
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Unable to create preview folder.');
}

$sample = [
    'item_code' => 'RM-100245',
    'part_name' => 'AUTOMOTIVE CONNECTOR HOUSING',
    'quantity' => '500',
    'uom' => 'PCS',
    'lot_no' => 'GRPO-20261022-01',
    'warehouse_lot_no' => 'WH-20261022-001',
    'request_no' => 'REQ-000123',
    'itr_number' => '125001234',
];

foreach (['issuer-qr-tag' => $sample, 'issuer-qr-tag-no-wh-lot' => array_merge($sample, ['warehouse_lot_no' => ''])] as $name => $item) {
    $result = zebra_issue_label_png($item, $outputDir . '/' . $name . '.png');
    if (!$result['ok']) {
        throw new RuntimeException($result['message']);
    }
    file_put_contents($outputDir . '/' . $name . '.zpl', zebra_issue_label_zpl($item));
    echo $name . ': ' . $result['message'] . PHP_EOL;
}
