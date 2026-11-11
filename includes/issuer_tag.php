<?php

/**
 * NBC ISSUED MATERIAL LABEL
 * File: includes/issuer_tag.php
 *
 * 3 x 3-inch label for Zebra / NITTO
 * Designed for 203 DPI
 *
 * Layout:
 *  - ISSUED MATERIAL heading
 *  - Request / ITR reference
 *  - Code 128 barcode
 *  - Part description
 *  - Quantity and GRPO lot
 *  - Four QR codes in a 2 x 2 arrangement:
 *      1. Issued QR
 *      2. Part Number QR
 *      3. Item Code QR
 *      4. Warehouse Lot QR
 *
 * Dependencies from zebra_print.php:
 *  - zebra_pick_qr_payload()
 *  - zebra_pick_reference_barcode_value()
 *  - zebra_zpl_text()
 *  - zebra_label_setup_zpl()
 *  - zebra_build_endroid_qrcode()
 *  - zebra_pick_image_scale()
 *  - zebra_pick_image_png_compression()
 *  - zebra_draw_code128()
 *  - zebra_font_path()
 *
 * This file is included by zebra_print.php.
 */


/* ==========================================================
   1. PART NUMBER
   ========================================================== */

function zebra_issue_part_number(array $item, array $master = [])
{
    foreach (
        [
            $item['parts_code'] ?? '',
            $item['part_number'] ?? '',
            $master['parts_code'] ?? '',
            $master['part_number'] ?? ''
        ] as $value
    ) {
        $value = trim((string)$value);

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}


/* ==========================================================
   2. NORMALIZE LABEL DATA
   ========================================================== */

function zebra_issue_tag_fields(array $item)
{
    $requestNo = trim((string)($item['request_no'] ?? ''));
    $itrNumber = trim((string)($item['itr_number'] ?? ''));

    $sourceType = strtolower(
        trim((string)($item['source_type'] ?? ''))
    );

    $isPurchaseOrder =
        $sourceType === 'purchase_order'
        || stripos($requestNo, 'PO ') === 0;

    $referenceText = $isPurchaseOrder
        ? $requestNo
        : implode(' / ', array_filter([
            $requestNo,
            $itrNumber !== '' ? 'ITR ' . $itrNumber : ''
        ]));

    return [
        // Preserve existing picker-compatible payload.
        'payload' => zebra_pick_qr_payload($item),

        'item_code' => trim(
            (string)($item['item_code'] ?? '')
        ),

        'part_number' => zebra_issue_part_number($item),

        'warehouse_lot_no' => trim(
            (string)($item['warehouse_lot_no'] ?? '')
        ),

        'lot_no' => trim(
            (string)($item['lot_no'] ?? '')
        ),

        'part_name' => trim(
            (string)($item['part_name'] ?? '')
        ),

        'quantity' => trim(
            (string)($item['quantity'] ?? '0')
        ),

        'uom' => trim(
            (string)($item['uom'] ?? 'PCS')
        ),

        'reference' => zebra_pick_reference_barcode_value($item),

        'reference_label' => $isPurchaseOrder
            ? 'REFERENCE / PO'
            : 'REFERENCE / REQ-ITR',

        'reference_text' => $referenceText
    ];
}


/* ==========================================================
   3. QR CODE BITMAP
   ========================================================== */

/**
 * Generates a monochrome QR bitmap.
 *
 * The bitmap is shared by:
 *  - Zebra ZPL
 *  - NITTO PNG printing
 *
 * This keeps QR payloads identical.
 */
function zebra_issue_qr_bitmap($text, $size)
{
    $text = (string)$text;
    $size = max(1, (int)$size);

    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException(
            'PHP GD extension is required.'
        );
    }

    $qr = zebra_build_endroid_qrcode(
        $text,
        $size,
        0
    );

    if ($qr === null) {
        throw new RuntimeException(
            'QR library is not available.'
        );
    }

    $writer = new \Endroid\QrCode\Writer\PngWriter();
    $result = $writer->write($qr);

    $matrix = $result->getMatrix();
    $count = $matrix->getBlockCount();

    // Reserve a quiet zone of at least 4 modules.
    $module = (int)floor(
        $size / ($count + 8)
    );

    if ($module < 2) {
        throw new RuntimeException(
            'QR value is too long for the issuer label.'
        );
    }

    $qrSize = $count * $module;

    $offset = (int)floor(
        ($size - $qrSize) / 2
    );

    $image = imagecreatetruecolor(
        $size,
        $size
    );

    if ($image === false) {
        throw new RuntimeException(
            'Unable to create QR bitmap.'
        );
    }

    $white = imagecolorallocate(
        $image,
        255,
        255,
        255
    );

    $black = imagecolorallocate(
        $image,
        0,
        0,
        0
    );

    imagefill(
        $image,
        0,
        0,
        $white
    );

    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if ($matrix->getBlockValue($row, $col)) {
                $x = $offset + $col * $module;
                $y = $offset + $row * $module;

                imagefilledrectangle(
                    $image,
                    $x,
                    $y,
                    $x + $module - 1,
                    $y + $module - 1,
                    $black
                );
            }
        }
    }

    return $image;
}


/* ==========================================================
   4. QR BITMAP TO ZPL
   ========================================================== */

function zebra_issue_qr_zpl($x, $y, $size, $text)
{
    $image = zebra_issue_qr_bitmap(
        $text,
        $size
    );

    $rowBytes = (int)ceil($size / 8);
    $hex = '';

    for ($row = 0; $row < $size; $row++) {
        for ($byte = 0; $byte < $rowBytes; $byte++) {
            $value = 0;

            for ($bit = 0; $bit < 8; $bit++) {
                $col = $byte * 8 + $bit;

                if (
                    $col < $size
                    && (imagecolorat(
                        $image,
                        $col,
                        $row
                    ) & 0xFFFFFF) === 0
                ) {
                    $value |= 1 << (7 - $bit);
                }
            }

            $hex .= sprintf(
                '%02X',
                $value
            );
        }
    }

    imagedestroy($image);

    $bytes = $rowBytes * $size;

    return "^FO{$x},{$y}"
        . "^GFA,{$bytes},{$bytes},{$rowBytes},{$hex}"
        . "^FS\r\n";
}


/* ==========================================================
   5. FOUR QR LAYOUT
   ========================================================== */

/**
 * Each element:
 *  x, y, size, title, payload, caption
 */
function zebra_issue_tag_qrs(array $fields)
{
    return [
        [
            101,
            293,
            112,
            'ISSUED QR',
            $fields['payload'],
            'ITEM + QTY + GRPO LOT'
        ],

        [
            365,
            293,
            112,
            'PART NUMBER QR',
            $fields['part_number'],
            $fields['part_number']
        ],

        [
            101,
            466,
            108,
            'ITEM CODE QR',
            $fields['item_code'],
            $fields['item_code']
        ],

        [
            367,
            466,
            108,
            'WAREHOUSE LOT QR',
            $fields['warehouse_lot_no'],
            $fields['warehouse_lot_no']
        ]
    ];
}


/* ==========================================================
   6. CENTERED ZPL TEXT
   ========================================================== */

function zebra_issue_zpl_center(
    $y,
    $value,
    $height,
    $charWidth,
    $x = 25,
    $width = 526
) {
    $value = zebra_zpl_text(
        $value,
        100
    );

    return "^FO{$x},{$y}"
        . "^A0N,{$height},{$charWidth}"
        . "^FB{$width},1,0,C,0"
        . "^FD{$value}^FS\r\n";
}


/* ==========================================================
   7. GENERATE ISSUED MATERIAL ZPL
   ========================================================== */

function zebra_issue_label_zpl(array $item)
{
    $f = zebra_issue_tag_fields($item);

    $text = static function ($value, $length = 80) {
        return zebra_zpl_text(
            $value,
            $length
        );
    };

    $reference = $text(
        $f['reference'],
        60
    );

    $zpl = "^XA\r\n";
    $zpl .= "^CI28\r\n";

    // Preserve existing Zebra media settings.
    $zpl .= zebra_label_setup_zpl();

    // Outer border.
    $zpl .= "^FO20,14^GB536,580,2^FS\r\n";

    // Main heading.
    $zpl .= zebra_issue_zpl_center(
        29,
        'ISSUED MATERIAL',
        43,
        38
    );

    // Reference number.
    $zpl .= zebra_issue_zpl_center(
        82,
        $f['reference_text'],
        25,
        22
    );

    // Barcode.
    $zpl .= "^FO100,116"
        . "^BY2,2,49"
        . "^BCN,49,N,N,N"
        . "^FD{$reference}^FS\r\n";

    // Part description.
    $zpl .= zebra_issue_zpl_center(
        178,
        $f['part_name'],
        23,
        19
    );

    // Quantity.
    $qtyText = 'QTY: '
        . $f['quantity']
        . ' '
        . $f['uom'];

    $zpl .= "^FO28,218"
        . "^A0N,22,19"
        . "^FD{$text($qtyText, 32)}^FS\r\n";

    // GRPO lot.
    $lotText = 'GRPO LOT: '
        . $f['lot_no'];

    $zpl .= "^FO224,218"
        . "^A0N,19,15"
        . "^FB324,1,0,R,0"
        . "^FD{$text($lotText, 48)}^FS\r\n";

    // Horizontal divider.
    $zpl .= "^FO28,251^GB520,2,2^FS\r\n";

    // First QR row headings.
    $zpl .= zebra_issue_zpl_center(
        266,
        'ISSUED QR',
        21,
        19,
        27,
        245
    );

    $zpl .= zebra_issue_zpl_center(
        266,
        'PART NUMBER QR',
        20,
        17,
        295,
        252
    );

    // Second QR row headings.
    $zpl .= zebra_issue_zpl_center(
        439,
        'ITEM CODE QR',
        21,
        18,
        27,
        245
    );

    $zpl .= zebra_issue_zpl_center(
        439,
        'WAREHOUSE LOT QR',
        19,
        16,
        295,
        252
    );

    // Draw all four QR codes and captions.
    foreach (zebra_issue_tag_qrs($f) as $index => $qr) {
        [$x, $y, $size, $label, $value, $caption] = $qr;

        if ($value !== '') {
            $zpl .= zebra_issue_qr_zpl(
                $x,
                $y,
                $size,
                $value
            );
        }

        // Captions under the QR.
        $captionY = $index < 2 ? 413 : 577;
        $columnX = ($index % 2 === 0) ? 27 : 295;
        $columnWidth = 252;

        if ($value === '') {
            $caption = 'N/A';
        }

        $zpl .= zebra_issue_zpl_center(
            $captionY,
            $text($caption, 60),
            $index < 2 ? 17 : 18,
            $index < 2 ? 14 : 16,
            $columnX,
            $columnWidth
        );
    }

    $zpl .= "^PQ1\r\n";
    $zpl .= "^XZ\r\n";

    return $zpl;
}


/* ==========================================================
   8. GD CENTERED TEXT
   ========================================================== */

function zebra_issue_gd_center(
    $image,
    $centerX,
    $baselineY,
    $value,
    $fontSize,
    $maxWidth
) {
    $value = trim((string)$value);

    if ($value === '') {
        return;
    }

    $black = imagecolorallocate(
        $image,
        0,
        0,
        0
    );

    $font = zebra_font_path();

    if (
        $font !== ''
        && function_exists('imagettftext')
    ) {
        $fontSize = (int)$fontSize;

        while ($fontSize > 8) {
            $box = imagettfbbox(
                $fontSize,
                0,
                $font,
                $value
            );

            $actualWidth = $box[2] - $box[0];

            if ($actualWidth <= $maxWidth) {
                break;
            }

            $fontSize--;
        }

        $box = imagettfbbox(
            $fontSize,
            0,
            $font,
            $value
        );

        $actualWidth = $box[2] - $box[0];

        $x = (int)round(
            $centerX - $actualWidth / 2 - $box[0]
        );

        imagettftext(
            $image,
            $fontSize,
            0,
            $x,
            (int)$baselineY,
            $black,
            $font,
            $value
        );

        return;
    }

    // Built-in PHP GD font fallback.
    $fontNo = 5;
    $width = imagefontwidth($fontNo) * strlen($value);

    $x = (int)round(
        $centerX - $width / 2
    );

    imagestring(
        $image,
        $fontNo,
        max(0, $x),
        (int)$baselineY - 15,
        $value,
        $black
    );
}


/* ==========================================================
   9. DRAW QR ON PNG CANVAS
   ========================================================== */

function zebra_issue_gd_qr(
    $image,
    $x,
    $y,
    $size,
    $value,
    $scale
) {
    if (trim((string)$value) === '') {
        return;
    }

    $qr = zebra_issue_qr_bitmap(
        $value,
        $size
    );

    imagecopyresized(
        $image,
        $qr,
        (int)round($x * $scale),
        (int)round($y * $scale),
        0,
        0,
        (int)round($size * $scale),
        (int)round($size * $scale),
        $size,
        $size
    );

    imagedestroy($qr);
}


/* ==========================================================
   10. GENERATE ISSUED MATERIAL PNG PREVIEW
   ========================================================== */

function zebra_issue_label_png(array $item, $path)
{
    $image = null;

    try {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException(
                'PHP GD extension is required.'
            );
        }

        $scale = zebra_pick_image_scale();

        $p = static function ($value) use ($scale): int {
            return (int)round($value * $scale);
        };

        $image = imagecreatetruecolor(
            $p(609),
            $p(609)
        );

        if ($image === false) {
            throw new RuntimeException(
                'Unable to create issuer tag image.'
            );
        }

        $white = imagecolorallocate(
            $image,
            255,
            255,
            255
        );

        $black = imagecolorallocate(
            $image,
            0,
            0,
            0
        );

        imagefill(
            $image,
            0,
            0,
            $white
        );

        $f = zebra_issue_tag_fields($item);

        // Centered text helper.
        $center = static function (
            $x,
            $y,
            $value,
            $size,
            $maxWidth = 520
        ) use ($image, $p) {
            zebra_issue_gd_center(
                $image,
                $p($x),
                $p($y),
                $value,
                $p($size),
                $p($maxWidth)
            );
        };

        // Outer label border.
        imagesetthickness(
            $image,
            $p(2)
        );

        imagerectangle(
            $image,
            $p(20),
            $p(14),
            $p(556),
            $p(594),
            $black
        );

        imagesetthickness(
            $image,
            1
        );

        // Main heading.
        $center(
            288,
            70,
            'ISSUED MATERIAL',
            33,
            518
        );

        // REQ / ITR reference.
        $center(
            288,
            105,
            $f['reference_text'],
            21,
            518
        );

        // Code 128 barcode.
        zebra_draw_code128(
            $image,
            $p(60),
            $p(115),
            $p(456),
            $p(52),
            $f['reference'],
            ''
        );

        // Item description.
        $center(
            288,
            203,
            $f['part_name'],
            18,
            520
        );

        // Quantity.
        $center(
            125,
            239,
            'QTY: '
                . $f['quantity']
                . ' '
                . $f['uom'],
            16,
            190
        );

        // GRPO lot.
        $center(
            398,
            239,
            'GRPO LOT: ' . $f['lot_no'],
            14,
            315
        );

        // Horizontal divider.
        imagesetthickness(
            $image,
            $p(2)
        );

        imageline(
            $image,
            $p(28),
            $p(251),
            $p(548),
            $p(251),
            $black
        );

        imagesetthickness(
            $image,
            1
        );

        // First QR row headings.
        $center(
            157,
            285,
            'ISSUED QR',
            17,
            245
        );

        $center(
            421,
            285,
            'PART NUMBER QR',
            16,
            250
        );

        // Second QR row headings.
        $center(
            157,
            457,
            'ITEM CODE QR',
            17,
            245
        );

        $center(
            421,
            457,
            'WAREHOUSE LOT QR',
            16,
            250
        );

        // Four QR codes.
        foreach (zebra_issue_tag_qrs($f) as $index => $qrData) {
            [
                $x,
                $y,
                $size,
                $label,
                $value,
                $caption
            ] = $qrData;

            if ($value !== '') {
                zebra_issue_gd_qr(
                    $image,
                    $x,
                    $y,
                    $size,
                    $value,
                    $scale
                );
            }

            $centerX = ($index % 2 === 0)
                ? 157
                : 421;

            $captionY = ($index < 2)
                ? 427
                : 588;

            if ($value === '') {
                $caption = 'N/A';
            }

            $center(
                $centerX,
                $captionY,
                $caption,
                ($index < 2) ? 13 : 14,
                245
            );
        }

        // Ensure directory exists.
        $directory = dirname($path);

        if (!is_dir($directory)) {
            if (
                !mkdir($directory, 0775, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create output directory.'
                );
            }
        }

        // Save PNG preview.
        if (!imagepng(
            $image,
            $path,
            zebra_pick_image_png_compression()
        )) {
            throw new RuntimeException(
                'Unable to save issuer label PNG.'
            );
        }

        return [
            'ok' => true,
            'message' => 'Issued Material 3 x 3-inch label rendered.'
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
