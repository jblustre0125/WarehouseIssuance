<?php

/**
 * NBC Issued Material: 3.0 x 2.5 inches, 203 DPI (576 x 508 dots).
 * Place at includes/issuer_tag.php; loaded by existing zebra_print.php.
 * Only issuer-label functions are changed.
 */

function zebra_issue_part_number(array $item, array $master = [])
{
    foreach ([$item['parts_code'] ?? '', $item['part_number'] ?? '', $master['parts_code'] ?? '', $master['part_number'] ?? ''] as $value) {
        if (trim((string)$value) !== '') return trim((string)$value);
    }
    return '';
}

function zebra_issue_tag_fields(array $item)
{
    $request = trim((string)($item['request_no'] ?? ''));
    $itr = trim((string)($item['itr_number'] ?? ''));
    $po = strtolower(trim((string)($item['source_type'] ?? ''))) === 'purchase_order'
        || stripos($request, 'PO ') === 0;
    return [
        'payload' => zebra_pick_qr_payload($item), // unchanged scanner format
        'item_code' => trim((string)($item['item_code'] ?? '')),
        'part_number' => zebra_issue_part_number($item),
        'warehouse_lot_no' => trim((string)($item['warehouse_lot_no'] ?? '')),
        'lot_no' => trim((string)($item['lot_no'] ?? '')),
        'part_name' => trim((string)($item['part_name'] ?? '')),
        'quantity' => trim((string)($item['quantity'] ?? '0')),
        'uom' => trim((string)($item['uom'] ?? 'PCS')),
        'reference' => zebra_pick_reference_barcode_value($item),
        'reference_text' => $po ? $request : implode(' / ', array_filter([
            $request,
            $itr !== '' ? 'ITR ' . $itr : ''
        ], static fn($v) => $v !== '')),
    ];
}

/** Render every issuer QR at version 7 so all four codes have equal dimensions. */
function zebra_issue_qr_bitmap($text, $size)
{
    $text = (string)$text;
    $size = (int)$size;
    if ($text === '') throw new RuntimeException('Cannot render an empty QR.');
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('PHP GD is required.');
    if (!class_exists(\BaconQrCode\Encoder\Encoder::class)) throw new RuntimeException('QR Code library is not installed.');
    $matrix = \BaconQrCode\Encoder\Encoder::encode(
        $text,
        \BaconQrCode\Common\ErrorCorrectionLevel::M(),
        'UTF-8',
        \BaconQrCode\Common\Version::getVersionForNumber(7)
    )->getMatrix();
    $count = $matrix->getWidth();
    $module = (int)floor($size / ($count + 8));
    if ($module < 2) throw new RuntimeException('QR data is too long for a 112-dot QR at 203 DPI.');
    $offset = (int)floor(($size - $count * $module) / 2);
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if ($matrix->get($col, $row) === 1) {
                $x = $offset + $col * $module;
                $y = $offset + $row * $module;
                imagefilledrectangle($image, $x, $y, $x + $module - 1, $y + $module - 1, $black);
            }
        }
    }
    return $image;
}

/** Same exact bitmap for ZPL and PNG. */
function zebra_issue_qr_zpl($x, $y, $size, $text)
{
    $image = zebra_issue_qr_bitmap($text, $size);
    $rowBytes = (int)ceil($size / 8);
    $hex = '';
    for ($row = 0; $row < $size; $row++) {
        for ($byte = 0; $byte < $rowBytes; $byte++) {
            $value = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $col = $byte * 8 + $bit;
                if ($col < $size && (imagecolorat($image, $col, $row) & 0xffffff) === 0) {
                    $value |= 1 << (7 - $bit);
                }
            }
            $hex .= sprintf('%02X', $value);
        }
    }
    imagedestroy($image);
    $bytes = $rowBytes * $size;
    return "^FO{$x},{$y}^GFA,{$bytes},{$bytes},{$rowBytes},{$hex}^FS\r\n";
}

/** Four identical 112-dot QR tiles, 18 dots between adjacent tiles. */
function zebra_issue_tag_qrs(array $fields)
{
    return [
        ['x' => 37,  'y' => 309, 'size' => 112, 'label' => 'ISSUED QR',       'caption' => '', 'value' => $fields['payload']],
        ['x' => 167, 'y' => 309, 'size' => 112, 'label' => 'PART NUMBER QR',  'caption' => $fields['part_number'], 'value' => $fields['part_number']],
        ['x' => 297, 'y' => 309, 'size' => 112, 'label' => 'ITEM CODE QR',    'caption' => $fields['item_code'], 'value' => $fields['item_code']],
        ['x' => 427, 'y' => 309, 'size' => 112, 'label' => 'WH LOT QR',       'caption' => $fields['warehouse_lot_no'], 'value' => $fields['warehouse_lot_no']],
    ];
}

function zebra_issue_zpl_center($y, $value, $height, $width, $x = 20, $boxWidth = 536)
{
    $value = zebra_zpl_text($value, 130);
    return "^FO{$x},{$y}^A0N,{$height},{$width}^FB{$boxWidth},1,0,C,0^FD{$value}^FS\r\n";
}

/**
 * Code 128 B symbology width in printer dots. Matches ZPL ^BC for regular
 * ASCII references; uses the existing code 128 patterns for the PNG preview.
 * The barcode, not its white field, is physically centered on x=288.
 */
function zebra_issue_barcode_layout($reference)
{
    $reference = zebra_zpl_text($reference, 60);
    $values = zebra_code128_b_values($reference);
    $patterns = zebra_code128_patterns();
    $modules = 0;
    foreach ($values as $code) {
        $pattern = $patterns[$code] ?? '';
        $modules += array_sum(array_map('intval', str_split($pattern)));
    }
    $moduleWidth = $modules * 2 <= 480 ? 2 : 1;
    $barWidth = $modules * $moduleWidth;
    if ($barWidth > 500) throw new RuntimeException('Reference barcode too long for a 3-inch label.');
    return ['x' => (int)floor((576 - $barWidth) / 2), 'width' => $barWidth, 'module' => $moduleWidth];
}

function zebra_issue_label_zpl(array $item)
{
    $f = zebra_issue_tag_fields($item);
    $ref = zebra_zpl_text($f['reference'], 60);
    $zpl = "^XA\r\n^CI28\r\n" . zebra_label_setup_zpl();

    // Scope the shorter media and centered origin to ISSUER only.
    // Use physically 3 x 2.5-inch stock and corresponding driver settings.
    $zpl .= "^PW576\r\n^LL508\r\n^LH0,0\r\n";
    $zpl .= "^FO12,12^GB552,484,2^FS\r\n";
    $zpl .= zebra_issue_zpl_center(24, 'ISSUED MATERIAL', 42, 37);
    $zpl .= zebra_issue_zpl_center(75, $f['reference_text'], 24, 20);

    if ($ref !== '') {
        $bar = zebra_issue_barcode_layout($ref);
        $zpl .= "^BY{$bar['module']},2,54\r\n";
        $zpl .= "^FO{$bar['x']},106^BCN,54,N,N,N^FD{$ref}^FS\r\n";
    }

    $zpl .= zebra_issue_zpl_center(177, $f['part_name'], 22, 17);
    $zpl .= "^FO26,217^A0N,20,17^FD" . zebra_zpl_text('QTY: ' . $f['quantity'] . ' ' . $f['uom'], 35) . "^FS\r\n";
    $zpl .= "^FO212,217^A0N,18,13^FB336,1,0,R,0^FD" . zebra_zpl_text('GRPO LOT: ' . $f['lot_no'], 65) . "^FS\r\n";
    $zpl .= "^FO24,248^GB528,2,2^FS\r\n";

    foreach (zebra_issue_tag_qrs($f) as $qr) {
        $colX = $qr['x'] - 9;

        $zpl .= zebra_issue_zpl_center(
            280,
            $qr['label'],
            17,
            13,
            $colX,
            130
        );

        if ($qr['value'] !== '') {
            $zpl .= zebra_issue_qr_zpl(
                $qr['x'],
                $qr['y'],
                $qr['size'],
                $qr['value']
            );
        }

        $caption = $qr['caption'];

        if ($caption === '' && $qr['label'] !== 'ISSUED QR') {
            $caption = 'N/A';
        }

        if ($caption !== '') {
            $zpl .= zebra_issue_zpl_center(
                435,
                $caption,
                15,
                11,
                $colX,
                130
            );
        }
    }

    return $zpl . "^PQ1\r\n^XZ\r\n";
}

function zebra_issue_png_center($image, $value, $centerX, $top, $fontSize, $maxWidth, $scale)
{
    $value = zebra_zpl_text($value, 130);

    if ($value === '') return;

    $black = imagecolorallocate($image, 0, 0, 0);
    $fontPath = function_exists('zebra_font_path') ? zebra_font_path() : '';

    $cx = (int)round($centerX * $scale);
    $top = (int)round($top * $scale);
    $mw = (int)round($maxWidth * $scale);

    if ($fontPath !== '' && function_exists('imagettftext')) {
        $size = max(8, (int)round($fontSize * $scale * 0.70));

        do {
            $bounds = imagettfbbox($size, 0, $fontPath, $value);
            $textW = $bounds[2] - $bounds[0];

            if ($textW <= $mw || $size <= 7) break;

            $size--;
        } while (true);

        $x = $cx - (int)round($textW / 2) - $bounds[0];
        $baseline = $top - $bounds[7];

        imagettftext(
            $image,
            $size,
            0,
            $x,
            $baseline,
            $black,
            $fontPath,
            $value
        );
    } else {
        $font = 3;
        $width = imagefontwidth($font) * strlen($value);

        imagestring(
            $image,
            $font,
            $cx - (int)floor($width / 2),
            $top,
            $value,
            $black
        );
    }
}

function zebra_issue_label_png(array $item, $path)
{
    $image = null;

    try {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD is required.');
        }

        $s = zebra_pick_image_scale();
        $p = static fn($v): int => (int)round($v * $s);

        $image = imagecreatetruecolor($p(576), $p(508));

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        $f = zebra_issue_tag_fields($item);

        $center = static function ($x, $y, $value, $size, $w = 520) use ($image, $s) {
            zebra_issue_png_center(
                $image,
                $value,
                $x,
                $y,
                $size,
                $w,
                $s
            );
        };

        imagesetthickness($image, max(1, $p(2)));

        imagerectangle(
            $image,
            $p(12),
            $p(12),
            $p(564),
            $p(496),
            $black
        );

        imagesetthickness($image, 1);

        $center(288, 24, 'ISSUED MATERIAL', 42);
        $center(288, 75, $f['reference_text'], 24);

        // Center the Code 128 barcode.
        if ($f['reference'] !== '') {
            $bar = zebra_issue_barcode_layout($f['reference']);

            zebra_draw_code128(
                $image,
                $p($bar['x']),
                $p(106),
                $p($bar['width']),
                $p(72),
                $f['reference'],
                ' '
            );
        }

        $center(288, 177, $f['part_name'], 22);

        $center(
            113,
            217,
            'QTY: ' . $f['quantity'] . ' ' . $f['uom'],
            20,
            190
        );

        $center(
            386,
            217,
            'GRPO LOT: ' . $f['lot_no'],
            18,
            330
        );

        imagefilledrectangle(
            $image,
            $p(24),
            $p(248),
            $p(552),
            $p(249),
            $black
        );

        // Four QR codes horizontally.
        foreach (zebra_issue_tag_qrs($f) as $qr) {
            $cx = $qr['x'] + $qr['size'] / 2;

            $center(
                $cx,
                280,
                $qr['label'],
                17,
                128
            );

            if ($qr['value'] !== '') {
                $bitmap = zebra_issue_qr_bitmap(
                    $qr['value'],
                    $qr['size']
                );

                imagecopyresized(
                    $image,
                    $bitmap,
                    $p($qr['x']),
                    $p($qr['y']),
                    0,
                    0,
                    $p($qr['size']),
                    $p($qr['size']),
                    $qr['size'],
                    $qr['size']
                );

                imagedestroy($bitmap);
            }

            $caption = $qr['caption'];

            if ($caption === '' && $qr['label'] !== 'ISSUED QR') {
                $caption = 'N/A';
            }

            if ($caption !== '') {
                $center(
                    $cx,
                    435,
                    $caption,
                    15,
                    126
                );
            }
        }

        $folder = dirname((string)$path);

        if (
            !is_dir($folder)
            && !mkdir($folder, 0775, true)
            && !is_dir($folder)
        ) {
            throw new RuntimeException(
                'Unable to create output folder.'
            );
        }

        if (!imagepng(
            $image,
            $path,
            zebra_pick_image_png_compression()
        )) {
            throw new RuntimeException(
                'Unable to save PNG preview.'
            );
        }

        return [
            'ok' => true,
            'message' => 'Issued Material 3 x 2.5-inch label rendered.'
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage()
        ];
    } finally {
        if ($image !== null) {
            imagedestroy($image);
        }
    }
}
