<?php

declare(strict_types=1);

/**
 * Issued Material Label Preview Generator
 *
 * CLI only.
 *
 * Generates:
 * - issuer-qr-tag.png
 * - issuer-qr-tag.zpl
 * - issuer-qr-tag-no-wh-lot.png
 * - issuer-qr-tag-no-wh-lot.zpl
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PICK_TAG_IMAGE_SCALE', 2);

require_once __DIR__ . '/../includes/zebra_print.php';

// Output directory.
$outputDir = $argv[1]
    ?? (__DIR__ . '/../docs/issuer-tag');

if (!is_dir($outputDir)) {
    if (
        !mkdir($outputDir, 0775, true)
        && !is_dir($outputDir)
    ) {
        throw new RuntimeException(
            'Unable to create preview folder.'
        );
    }
}

// Sample data.
$sample = [
    'item_code' => 'RM-100245',
    'part_number' => 'PN-67890',
    'part_name' => 'AUTOMOTIVE CONNECTOR HOUSING',
    'quantity' => '500',
    'uom' => 'PCS',
    'lot_no' => 'GRPO-20261022-01',
    'warehouse_lot_no' => 'WH-20261022-001',
    'request_no' => 'REQ-000123',
    'itr_number' => '125001234',
];

// Two label versions.
$labels = [
    'issuer-qr-tag' => $sample,

    'issuer-qr-tag-no-wh-lot' => array_merge(
        $sample,
        [
            'warehouse_lot_no' => '',
        ]
    ),
];

foreach ($labels as $name => $item) {
    echo 'Generating: ' . $name . PHP_EOL;

    // Generate PNG.
    $pngPath = $outputDir . '/' . $name . '.png';

    $result = zebra_issue_label_png(
        $item,
        $pngPath
    );

    if (!$result['ok']) {
        throw new RuntimeException(
            $result['message']
        );
    }

    echo $result['message'] . PHP_EOL;

    // Generate ZPL.
    $zplPath = $outputDir . '/' . $name . '.zpl';

    $zpl = zebra_issue_label_zpl($item);

    if (file_put_contents($zplPath, $zpl) === false) {
        throw new RuntimeException(
            'Unable to save ZPL: ' . $zplPath
        );
    }

    echo 'ZPL generated: ' . $zplPath . PHP_EOL;
}

echo PHP_EOL;
echo 'All label previews generated successfully.' . PHP_EOL;
echo 'Output directory: ' . realpath($outputDir) . PHP_EOL;
