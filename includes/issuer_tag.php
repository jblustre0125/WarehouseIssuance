<?php

/**
 * NBC ISSUED MATERIAL LABEL
 * File: includes/issuer_tag.php
 *
 * 3 x 3 inches / Zebra 203 DPI
 *
 * Changes:
 * - All four QR codes are 112 x 112 dots
 * - Removed "ITEM + QTY + GRPO LOT" caption
 * - Barcode horizontally centered
 * - Same positioning for ZPL and PNG
 * - Preserves original QR payload
 * - Preserves existing printer settings
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
   2. LABEL DATA
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
        // Keep the existing scanner-compatible QR format.
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

        'reference_text' => $referenceText
    ];
}


/* ==========================================================
   3. QR BITMAP
   ========================================================== */

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
            'Endroid QR library is unavailable.'
        );
    }

    $writer = new \Endroid\QrCode\Writer\PngWriter();

    $result = $writer->write($qr);
    $matrix = $result->getMatrix();

    $count = $matrix->getBlockCount();

    // Four-module quiet zone on every side.
    $module = (int)floor(
        $size / ($count + 8)
    );

    if ($module < 2) {
        throw new RuntimeException(
            'QR content is too long for the selected label size.'
        );
    }

    $offset = (int)floor(
        ($size - $count * $module) / 2
    );

    $image = imagecreatetruecolor(
        $size,
        $size
    );

    if ($image === false) {
        throw new RuntimeException(
            'Unable to generate QR image.'
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
   4. QR TO ZPL IMAGE
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

            $hex .= sprintf('%02X', $value);
        }
    }

    imagedestroy($image);

    $bytes = $rowBytes * $size;

    return "^FO{$x},{$y}"
        . "^GFA,{$bytes},{$bytes},{$rowBytes},{$hex}"
        . "^FS\r\n";
}


/* ==========================================================
   5. FOUR EQUAL-SIZE QR CODES
   ========================================================== */

/**
 * All QR codes:
 *   112 x 112 dots
 *
 * Physical layout:
 *
 *    ISSUED QR        PART NUMBER QR
 *      [ QR ]             [ QR ]
 *
 *    ITEM CODE QR     WAREHOUSE LOT QR
 *      [ QR ]             [ QR ]
 */

function zebra_issue_tag_qrs(array $fields)
{
    $size = 112;

    return [
        [
            96,
            285,
            $size,
            'ISSUED QR',
            $fields['payload'],
            ''
        ],

        [
            362,
            285,
            $size,
            'PART NUMBER QR',
            $fields['part_number'],
            $fields['part_number']
        ],

        [
            96,
            440,
            $size,
            'ITEM CODE QR',
            $fields['item_code'],
            $fields['item_code']
        ],

        [
            362,
            440,
            $size,
            'WAREHOUSE LOT QR',
            $fields['warehouse_lot_no'],
            $fields['warehouse_lot_no']
        ]
    ];
}


/* ==========================================================
   6. ZPL CENTERED TEXT
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
   7. CALCULATE BARCODE WIDTH FOR CENTERING
   ========================================================== */

/**
 * Uses the Code 128 B patterns already present
 * in zebra_print.php.
 *
 * Center position:
 *
 *    Label center = 576 / 2 = 288
 *
 *    X = 288 - (barcode width / 2)
 *
 * This prevents the barcode from always starting
 * at a fixed left coordinate.
 */

function zebra_issue_barcode_layout($value)
{
    $value = zebra_zpl_text($value, 96);

    $patterns = zebra_code128_patterns();
    $codes = zebra_code128_b_values($value);

    $modules = 0;

    foreach ($codes as $code) {
        $pattern = $patterns[$code] ?? '';

        foreach (str_split($pattern) as $digit) {
            $modules += (int)$digit;
        }
    }

    $availableWidth = 480;

    // Use two printer dots per module when possible.
    $moduleWidth = 2;

    if ($modules * 2 > $availableWidth) {
        $moduleWidth = 1;
    }

    $barcodeWidth = $modules * $moduleWidth;

    // Center against the existing 576-dot layout.
    $centerX = 288;

    $x = (int)round(
        $centerX - $barcodeWidth / 2
    );

    if ($x < 28) {
        $x = 28;
    }

    return [
        'x' => $x,
        'width' => $barcodeWidth,
        'module_width' => $moduleWidth
    ];
}


/* ==========================================================
   8. ZPL ISSUED MATERIAL LABEL
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

    $barcode = zebra_issue_barcode_layout(
        $reference
    );

    $zpl = "^XA\r\n";
    $zpl .= "^CI28\r\n";

    // Existing printer setup.
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

    // Request / ITR.
    $zpl .= zebra_issue_zpl_center(
        82,
        $f['reference_text'],
        25,
        22
    );

    // Calculate exact Code 128 barcode position.
    $barcodeX = $barcode['x'];
    $barcodeModule = $barcode['module_width'];

    $zpl .= "^BY{$barcodeModule},2,49\r\n";

    $zpl .= "^FO{$barcodeX},116"
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

    // Divider.
    $zpl .= "^FO28,251^GB520,2,2^FS\r\n";

    // First row headings.
    $zpl .= zebra_issue_zpl_center(
        263,
        'ISSUED QR',
        19,
        17,
        27,
        245
    );

    $zpl .= zebra_issue_zpl_center(
        263,
        'PART NUMBER QR',
        19,
        16,
        295,
        252
    );

    // Second row headings.
    $zpl .= zebra_issue_zpl_center(
        420,
        'ITEM CODE QR',
        19,
        17,
        27,
        245
    );

    $zpl .= zebra_issue_zpl_center(
        420,
        'WAREHOUSE LOT QR',
        19,
        16,
        295,
        252
    );

    // Four equally sized QR codes.
    foreach (zebra_issue_tag_qrs($f) as $index => $qr) {
        [
            $x,
            $y,
            $size,
            $label,
            $value,
            $caption
        ] = $qr;

        if ($value !== '') {
            $zpl .= zebra_issue_qr_zpl(
                $x,
                $y,
                $size,
                $value
            );
        }

        // Do not display the issued QR caption.
        if ($index === 0) {
            continue;
        }

        if ($value === '') {
            $caption = 'N/A';
        }

        $captionY = $index < 2 ? 401 : 560;

        $columnX = ($index % 2 === 0)
            ? 27
            : 295;

        $zpl .= zebra_issue_zpl_center(
            $captionY,
            $text($caption, 60),
            17,
            14,
            $columnX,
            252
        );
    }

    $zpl .= "^PQ1\r\n";
    $zpl .= "^XZ\r\n";

    return $zpl;
}


/* ==========================================================
   9. GD CENTERED TEXT
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

    $fontNo = 5;

    $width = imagefontwidth($fontNo)
        * strlen($value);

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
   10. DRAW QR ON PNG CANVAS
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
   11. PNG ISSUED MATERIAL LABEL
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
                'Unable to create label image.'
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

        // Outer border.
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

        // Heading.
        $center(
            288,
            70,
            'ISSUED MATERIAL',
            33,
            518
        );

        // Reference.
        $center(
            288,
            105,
            $f['reference_text'],
            21,
            518
        );

        // Horizontally centered barcode.
        //
        // zebra_draw_code128() centers the bars
        // inside the supplied horizontal region.
        //
        // Center of region:
        // (28 + 520 / 2) = 288

        zebra_draw_code128(
            $image,
            $p(28),
            $p(115),
            $p(520),
            $p(52),
            $f['reference'],
            ''
        );

        // Description.
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

        // Divider.
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

        // QR titles.
        $center(
            152,
            280,
            'ISSUED QR',
            17,
            245
        );

        $center(
            418,
            280,
            'PART NUMBER QR',
            16,
            250
        );

        $center(
            152,
            435,
            'ITEM CODE QR',
            17,
            245
        );

        $center(
            418,
            435,
            'WAREHOUSE LOT QR',
            16,
            250
        );

        // Draw all four equal-size QR codes.
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

            // Remove the caption below Issued QR.
            if ($index === 0) {
                continue;
            }

            if ($value === '') {
                $caption = 'N/A';
            }

            $centerX = ($index % 2 === 0)
                ? 152
                : 418;

            $captionY = $index < 2
                ? 416
                : 578;

            $center(
                $centerX,
                $captionY,
                $caption,
                13,
                245
            );
        }

        // Create output directory if necessary.
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

        // Save PNG.
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
            'message' => 'Issued Material label generated successfully.'
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
