<?php

function itr_pack_sizes_path()
{
    return __DIR__ . '/../data/itr_qty_per_pack_june_2026.json';
}

function itr_pack_sizes_cache_token()
{
    $path = itr_pack_sizes_path();

    return is_file($path) ? (string)filemtime($path) : 'missing';
}

function itr_pack_sizes()
{
    static $packSizes = null;

    if ($packSizes !== null) {
        return $packSizes;
    }

    $packSizes = [];
    $path = itr_pack_sizes_path();

    if (!is_file($path)) {
        return $packSizes;
    }

    $payload = json_decode((string)file_get_contents($path), true);

    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        return $packSizes;
    }

    foreach ($payload['items'] as $itemCode => $qtyPerPack) {
        $code = itr_pack_normalize_item_code($itemCode);

        if ($code === '' || !is_numeric($qtyPerPack) || (float)$qtyPerPack <= 0) {
            continue;
        }

        $packSizes[$code] = (float)$qtyPerPack;
    }

    return $packSizes;
}

function itr_pack_normalize_item_code($itemCode)
{
    $code = strtoupper(trim((string)$itemCode));

    if ($code !== '' && ctype_digit($code)) {
        $code = str_pad($code, 11, '0', STR_PAD_LEFT);
    }

    return $code;
}

function itr_qty_per_pack_for_item($itemCode)
{
    $packSizes = itr_pack_sizes();
    $code = itr_pack_normalize_item_code($itemCode);

    return $packSizes[$code] ?? 0.0;
}

?>
