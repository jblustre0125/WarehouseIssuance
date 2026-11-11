<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ZEBRA_PRINT_ENABLED', true);
require_once __DIR__ . '/../includes/tablet_print.php';
function tablet_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$item = ['item_code' => 'RM-TEST', 'quantity' => 3, 'lot_no' => 'LOT-TEST', 'warehouse_lot_no' => 'WH-TEST', 'part_name' => 'Tablet test'];
foreach ([false, true] as $issuer) {
    $payload = zebra_prepare_tablet_labels([$item], $issuer);
    tablet_check($payload['ok'] && $payload['transport'] === 'tablet_bluetooth' && $payload['printed'] === 0,
        'Preparing ' . ($issuer ? 'issuer' : 'picker') . ' labels does not claim a physical print');
    $expected = $issuer ? zebra_issue_label_zpl($item) : zebra_pick_label_zpl($item);
    tablet_check(base64_decode($payload['labels'][0], true) === $expected, 'Tablet receives the existing label layout byte for byte');
    tablet_check(substr_count(base64_decode($payload['labels'][0]), '^PQ1') === 1, 'One label per item, independent of material quantity');
}
tablet_check(!zebra_prepare_tablet_labels([])['ok'], 'Empty batch rejected');
tablet_check(!zebra_prepare_tablet_labels(array_fill(0, 101, $item))['ok'], 'Over 100 labels rejected');
tablet_check(zebra_prepare_tablet_labels(array_fill(0, 100, $item))['label_count'] === 100, '100-label batch supported');
echo "Tablet label checks passed.\n";
