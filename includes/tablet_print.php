<?php

require_once __DIR__ . '/zebra_print.php';

// Reuse the server's label layouts and endpoint validation, but never send to
// a server printer or queue when the Android app requests tablet Bluetooth.
function zebra_prepare_tablet_labels(array $items, bool $issuerTag = false): array
{
    if (!zebra_print_enabled()) {
        return ['ok' => false, 'enabled' => false, 'message' => 'Zebra printing is disabled.'];
    }
    if (count($items) < 1 || count($items) > 100) {
        return ['ok' => false, 'message' => 'Print between 1 and 100 tablet labels at a time.'];
    }

    $labels = [];
    $bytes = 0;
    foreach ($items as $item) {
        $zpl = $issuerTag ? zebra_issue_label_zpl($item) : zebra_pick_label_zpl($item);
        $bytes += strlen($zpl);
        if (strlen($zpl) > 65536 || $bytes > 2097152) {
            return ['ok' => false, 'message' => 'The tablet print batch is too large. Print fewer labels.'];
        }
        $labels[] = base64_encode($zpl);
    }

    return [
        'ok' => true,
        'enabled' => true,
        'transport' => 'tablet_bluetooth',
        'printer_key' => 'zebra',
        'printer_name' => 'Zebra QLn320 (tablet Bluetooth)',
        'labels' => $labels,
        'label_count' => count($labels),
        'label_delay_ms' => min(30000, max(0, zebra_pick_label_delay_seconds() * 1000)),
        'printed' => 0,
        'message' => 'Labels prepared for tablet Bluetooth.'
    ];
}
