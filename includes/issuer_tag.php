<?php

/* Issuer-only 3 x 3 inch layout. Coordinates fit the 576-dot Zebra width. */
function zebra_issue_tag_fields(array $item)
{
    $requestNo = trim((string)($item['request_no'] ?? ''));
    $itrNumber = trim((string)($item['itr_number'] ?? ''));
    $isPurchaseOrder = strtolower(trim((string)($item['source_type'] ?? ''))) === 'purchase_order'
        || stripos($requestNo, 'PO ') === 0;
    return [
        'payload' => zebra_pick_qr_payload($item),
        'item_code' => trim((string)($item['item_code'] ?? '')),
        // Encode the complete lot value, never the shortened display text.
        'warehouse_lot_no' => trim((string)($item['warehouse_lot_no'] ?? '')),
        'lot_no' => trim((string)($item['lot_no'] ?? '')),
        'part_name' => trim((string)($item['part_name'] ?? '')),
        'quantity' => trim((string)($item['quantity'] ?? '')),
        'uom' => trim((string)($item['uom'] ?? '')),
        'reference' => zebra_pick_reference_barcode_value($item),
        'reference_label' => $isPurchaseOrder ? 'REFERENCE / PO' : 'REFERENCE / REQ-ITR',
        'reference_text' => $isPurchaseOrder ? $requestNo : trim($requestNo . ' ' . $itrNumber),
    ];
}

/* Integer modules and a four-module quiet zone keep all three QRs crisp. */
function zebra_issue_qr_bitmap($text, $size)
{
    $qr = zebra_build_endroid_qrcode($text, $size, 0);
    if ($qr === null || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('The QR library and PHP GD are required.');
    }
    $result = (new \Endroid\QrCode\Writer\PngWriter())->write($qr);
    $matrix = $result->getMatrix();
    $count = $matrix->getBlockCount();
    $module = (int)floor($size / ($count + 8));
    if ($module < 2) {
        throw new RuntimeException('QR value is too long for the issuer tag.');
    }
    $offset = (int)floor(($size - $count * $module) / 2);
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if ($matrix->getBlockValue($row, $col)) {
                $x = $offset + $col * $module;
                $y = $offset + $row * $module;
                imagefilledrectangle($image, $x, $y, $x + $module - 1, $y + $module - 1, $black);
            }
        }
    }
    return $image;
}

/* Use the same QR bitmap for Zebra and Nitto, including literal ^ and ~. */
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

function zebra_issue_tag_qrs(array $fields)
{
    $qrs = [
        [28, 344, 194, 'PAYLOAD QR', $fields['payload']],
        [238, 344, 146, 'ITEM QR', $fields['item_code']],
    ];
    if ($fields['warehouse_lot_no'] !== '') {
        $qrs[] = [400, 344, 146, 'WH LOT QR', $fields['warehouse_lot_no']];
    }
    return $qrs;
}

function zebra_issue_label_zpl(array $item)
{
    $f = zebra_issue_tag_fields($item);
    $text = static fn($value, $length = 80) => zebra_zpl_text($value, $length);
    $reference = $text($f['reference'], 60);
    $zpl = "^XA\r\n^CI28\r\n" . zebra_label_setup_zpl()
        . "^FO20,14^GB536,580,2^FS\r\n"
        . "^FO28,28^A0N,28,28^FDNBC RAWMATS TRACEABILITY^FS\r\n"
        . "^FO28,62^A0N,20,20^FDISSUER QR TAG - 3 X 3^FS\r\n"
        . "^FO28,88^GB520,2,2^FS\r\n"
        . "^FO28,104^A0N,18,18^FD{$f['reference_label']}^FS\r\n"
        . "^FO28,130^FB520,1,0,L^A0N,28,28^FD{$text($f['reference_text'], 50)}^FS\r\n"
        . "^FO28,170^BY2,2,52^BCN,52,Y,N,N^FD{$reference}^FS\r\n"
        . "^FO28,250^GB520,2,2^FS\r\n"
        . "^FO28,262^A0N,16,16^FDITEM CODE^FS\r\n"
        . "^FO28,282^FB350,1,0,L^A0N,24,24^FD{$text($f['item_code'], 40)}^FS\r\n"
        . "^FO400,262^A0N,16,16^FDQTY {$text($f['uom'], 12)}^FS\r\n"
        . "^FO400,282^FB146,1,0,L^A0N,24,24^FD{$text($f['quantity'], 18)}^FS\r\n";
    foreach (zebra_issue_tag_qrs($f) as [$x, $y, $size, $label, $value]) {
        $zpl .= "^FO{$x},320^A0N,16,16^FD{$label}^FS\r\n";
        $zpl .= zebra_issue_qr_zpl($x, $y, $size, $value);
    }
    $whDisplay = $f['warehouse_lot_no'] !== '' ? $f['warehouse_lot_no'] : '-';
    return $zpl
        . "^FO238,500^A0N,16,16^FDGRPO LOT NO^FS\r\n"
        . "^FO238,522^FB146,2,2,L^A0N,16,16^FD{$text($f['lot_no'])}^FS\r\n"
        . "^FO400,500^A0N,16,16^FDWH LOT NO^FS\r\n"
        . "^FO400,522^FB146,2,2,L^A0N,16,16^FD{$text($whDisplay)}^FS\r\n"
        . "^FO28,566^GB520,2,2^FS\r\n"
        . "^FO28,574^A0N,14,14^FDPART NAME^FS\r\n"
        . "^FO120,574^FB426,1,0,L^A0N,14,14^FD{$text($f['part_name'], 58)}^FS\r\n"
        . "^PQ1\r\n^XZ\r\n";
}

function zebra_issue_label_png(array $item, $path)
{
    $image = null;
    try {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD is required to render the issuer tag.');
        }
        $s = zebra_pick_image_scale();
        $p = static fn($v): int => (int)round($v * $s);
        $image = imagecreatetruecolor($p(609), $p(609));
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $f = zebra_issue_tag_fields($item);
        $draw = static function ($x, $y, $value, $font = 2, $width = 520, $lines = 1) use ($image, $p) {
            zebra_gd_wrapped_text($image, $p($x), $p($y), $p($width), $p(20), $value, $font, $lines, true);
        };
        imagesetthickness($image, $p(2));
        imagerectangle($image, $p(20), $p(14), $p(556), $p(594), $black);
        $draw(28, 28, 'NBC RAWMATS TRACEABILITY', 3);
        $draw(28, 62, 'ISSUER QR TAG - 3 X 3', 2);
        foreach ([88, 250, 566] as $y) {
            imageline($image, $p(28), $p($y), $p(548), $p($y), $black);
        }
        $draw(28, 104, $f['reference_label']);
        $draw(28, 130, $f['reference_text'], 4);
        zebra_draw_code128($image, $p(28), $p(170), $p(520), $p(66), $f['reference']);
        $draw(28, 262, 'ITEM CODE');
        $draw(28, 282, $f['item_code'], 4, 350);
        $draw(400, 262, 'QTY ' . $f['uom'], 2, 146);
        $draw(400, 282, $f['quantity'], 4, 146);
        foreach (zebra_issue_tag_qrs($f) as [$x, $y, $size, $label, $value]) {
            $draw($x, 320, $label, 2, $size);
            // Scale the same dot grid without interpolation for driver output.
            $qr = zebra_issue_qr_bitmap($value, $size);
            imagecopyresized($image, $qr, $p($x), $p($y), 0, 0, $p($size), $p($size), $size, $size);
            imagedestroy($qr);
        }
        $draw(238, 500, 'GRPO LOT NO', 2, 146);
        $draw(238, 522, $f['lot_no'], 2, 146, 2);
        $draw(400, 500, 'WH LOT NO', 2, 146);
        $draw(400, 522, $f['warehouse_lot_no'] !== '' ? $f['warehouse_lot_no'] : '-', 2, 146, 2);
        $draw(28, 574, 'PART NAME', 1, 90);
        $draw(120, 574, $f['part_name'], 1, 426);
        if (!imagepng($image, $path, zebra_pick_image_png_compression())) {
            throw new RuntimeException('Unable to save the issuer tag image.');
        }
        return ['ok' => true, 'message' => 'Issuer 3-inch QR tag rendered.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        if ($image !== null) {
            imagedestroy($image);
        }
    }
}
