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
    try {
        return zebra_issue_bitmap_zpl($x, $y, $image);
    } finally {
        imagedestroy($image);
    }
}

/** Raster lettering preserves Poppins on printers without installed fonts. */
function zebra_issue_bitmap_zpl($x, $y, $image)
{
    $width = imagesx($image);
    $height = imagesy($image);
    $rowBytes = (int)ceil($width / 8);
    $hex = '';
    $previous = null;
    for ($row = 0; $row < $height; $row++) {
        $line = '';
        for ($byte = 0; $byte < $rowBytes; $byte++) {
            $value = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $col = $byte * 8 + $bit;
                if ($col < $width && (imagecolorat($image, $col, $row) & 0xffffff) === 0) {
                    $value |= 1 << (7 - $bit);
                }
            }
            $line .= sprintf('%02X', $value);
        }
        // Zebra ASCII graphics: repeated rows and run counts save Bluetooth bytes.
        // https://cpws.zebra.com/cpws/docs/zpl/zpl_manual.pdf (Graphic Commands)
        if ($line === $previous) {
            $hex .= ':';
        } else {
            $trimmed = rtrim($line, '0');
            preg_match_all('/(.)\1*/', $trimmed, $runs);
            foreach ($runs[0] as $run) {
                $count = strlen($run);
                while ($count >= 400) { $hex .= 'z'; $count -= 400; }
                if ($count >= 20) { $hex .= chr(ord('g') + intdiv($count, 20) - 1); $count %= 20; }
                if ($count > 0 && strlen($run) > 1) $hex .= chr(ord('G') + $count - 1);
                $hex .= $run[0];
            }
            if ($trimmed !== $line) $hex .= ',';
        }
        $previous = $line;
    }
    $bytes = $rowBytes * $height;
    return "^FO{$x},{$y}^GFA,{$bytes},{$bytes},{$rowBytes},{$hex}^FS\r\n";
}

/** Four identical 112-dot QR tiles, 18 dots between adjacent tiles. */
function zebra_issue_tag_qrs(array $fields)
{
    return [
        ['x' => 37,  'y' => 333, 'size' => 112, 'label' => "ISSUED\nQR",       'caption' => '', 'value' => $fields['payload']],
        ['x' => 167, 'y' => 333, 'size' => 112, 'label' => "PART NUMBER\nQR",  'caption' => $fields['part_number'], 'value' => $fields['part_number']],
        ['x' => 297, 'y' => 333, 'size' => 112, 'label' => "ITEM CODE\nQR",    'caption' => $fields['item_code'], 'value' => $fields['item_code']],
        ['x' => 427, 'y' => 333, 'size' => 112, 'label' => "WH LOT\nQR",       'caption' => $fields['warehouse_lot_no'], 'value' => $fields['warehouse_lot_no']],
    ];
}

function zebra_issue_font_path($bold = false)
{
    $path = __DIR__ . '/../assets/fonts/Poppins-' . ($bold ? 'SemiBold' : 'Medium') . '.ttf';
    if (!is_file($path) || !function_exists('imagettftext')) {
        throw new RuntimeException('Issuer printing requires PHP GD/FreeType and the bundled Poppins fonts.');
    }
    return $path;
}

/** Wrap at a fixed readable size; shorten display text only, never QR data. */
function zebra_issue_text_lines($value, $font, $size, $width, $maxLines)
{
    $measure = static function ($text) use ($font, $size) {
        $b = imagettfbbox($size, 0, $font, $text);
        return $b[2] - $b[0];
    };
    $lines = [];
    foreach (explode("\n", str_replace(["\r", "\t"], ['', ' '], (string)$value)) as $paragraph) {
        $line = '';
        foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($line !== '' && $measure($line . $character) > $width) {
                $lines[] = trim($line);
                $line = '';
            }
            $line .= $character;
        }
        $lines[] = trim($line);
    }
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = preg_split('//u', $lines[$maxLines - 1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($last && $measure(implode('', $last) . '...') > $width) array_pop($last);
        $lines[$maxLines - 1] = rtrim(implode('', $last)) . '...';
    }
    return $lines;
}

function zebra_issue_text_bitmap($value, $width, $height, $maxLines = 1, $bold = false)
{
    $font = zebra_issue_font_path($bold);
    // Height is a physical printer-dot size, independent of PNG preview scale.
    $bounds = imagettfbbox(12, 0, $font, 'Ag');
    $size = 12 * $height / ($bounds[1] - $bounds[7]);
    $lineHeight = $height + 4;
    $image = imagecreatetruecolor($width, $lineHeight * $maxLines);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    foreach (zebra_issue_text_lines($value, $font, $size, $width - 4, $maxLines) as $i => $line) {
        if ($line === '') continue;
        $b = imagettfbbox($size, 0, $font, $line);
        $x = (int)floor(($width - ($b[2] - $b[0])) / 2) - $b[0];
        $baseline = (int)round($i * $lineHeight - $bounds[7] * $size / 12);
        imagettftext($image, $size, 0, $x, $baseline, $black, $font, $line);
    }
    // Use identical one-bit lettering for both thermal output paths.
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $gray = (($color >> 16) & 255) + (($color >> 8) & 255) + ($color & 255);
            imagesetpixel($image, $x, $y, $gray < 3 * 180 ? $black : $white);
        }
    }
    return $image;
}

/** Shared text placement keeps the PNG/Nitto and Zebra lettering identical. */
function zebra_issue_tag_texts(array $f)
{
    $texts = [
        [24, 25, 528, 36, 1, true, 'ISSUED MATERIAL'],
        [24, 80, 528, 23, 1, false, $f['reference_text']],
        [24, 190, 528, 20, 2, true, $f['part_name']],
        [24, 241, 170, 18, 1, true, 'QTY: ' . $f['quantity'] . ' ' . $f['uom']],
        [202, 241, 350, 18, 1, true, 'GRPO LOT: ' . $f['lot_no']],
    ];
    foreach (zebra_issue_tag_qrs($f) as $qr) {
        $texts[] = [$qr['x'] - 6, 285, 124, 16, 2, true, $qr['label']];
        $caption = $qr['caption'];
        if ($caption === '' && $qr['label'] !== "ISSUED\nQR") $caption = 'N/A';
        if ($caption !== '') $texts[] = [$qr['x'] - 6, 452, 124, 16, 2, false, $caption];
    }
    return $texts;
}

function zebra_issue_barcode_layout($reference)
{
    $values = zebra_code128_b_values($reference);
    $patterns = zebra_code128_patterns();
    $modules = 0;
    foreach ($values as $code) {
        $modules += array_sum(array_map('intval', str_split($patterns[$code])));
    }
    $moduleWidth = $modules * 2 <= 480 ? 2 : 1;
    $barWidth = $modules * $moduleWidth;
    if ($barWidth + 20 * $moduleWidth > 528) {
        throw new RuntimeException('Reference barcode too long for a 3-inch label.');
    }
    return ['x' => (int)floor((576 - $barWidth) / 2), 'width' => $barWidth, 'module' => $moduleWidth];
}

function zebra_issue_barcode_bitmap($reference)
{
    $bar = zebra_issue_barcode_layout($reference);
    $image = imagecreatetruecolor($bar['width'], 58);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    $cursor = 0;
    $patterns = zebra_code128_patterns();
    foreach (zebra_code128_b_values($reference) as $code) {
        foreach (str_split($patterns[$code]) as $i => $module) {
            $width = (int)$module * $bar['module'];
            if ($i % 2 === 0) imagefilledrectangle($image, $cursor, 0, $cursor + $width - 1, 57, $black);
            $cursor += $width;
        }
    }
    return $image;
}

/** Visit one bitmap at a time so large batches do not retain GD resources. */
function zebra_issue_each_bitmap(array $item, callable $draw)
{
    $f = zebra_issue_tag_fields($item);
    foreach (zebra_issue_tag_texts($f) as [$x, $y, $width, $height, $lines, $bold, $value]) {
        $bitmap = zebra_issue_text_bitmap($value, $width, $height, $lines, $bold);
        try { $draw($x, $y, $bitmap); } finally { imagedestroy($bitmap); }
    }
    if ($f['reference'] !== '') {
        $bar = zebra_issue_barcode_layout($f['reference']);
        $bitmap = zebra_issue_barcode_bitmap($f['reference']);
        try { $draw($bar['x'], 116, $bitmap); } finally { imagedestroy($bitmap); }
    }
    foreach (zebra_issue_tag_qrs($f) as $qr) {
        if ($qr['value'] === '') continue;
        $bitmap = zebra_issue_qr_bitmap($qr['value'], $qr['size']);
        try { $draw($qr['x'], $qr['y'], $bitmap); } finally { imagedestroy($bitmap); }
    }
}

function zebra_issue_label_zpl(array $item)
{
    $zpl = "^XA\r\n^CI28\r\n" . zebra_label_setup_zpl()
        . "^PW576\r\n^LL508\r\n^LH0,0\r\n"
        . "^FO12,12^GB552,484,2^FS\r\n"
        . "^FO24,272^GB528,2,2^FS\r\n";
    zebra_issue_each_bitmap($item, static function ($x, $y, $image) use (&$zpl) {
        $zpl .= zebra_issue_bitmap_zpl($x, $y, $image);
    });
    return $zpl . "^PQ1\r\n^XZ\r\n";
}

function zebra_issue_label_png(array $item, $path)
{
    $image = null;
    $output = null;
    try {
        $image = imagecreatetruecolor(576, 508);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        imagesetthickness($image, 2);
        imagerectangle($image, 12, 12, 563, 495, $black);
        imagesetthickness($image, 1);
        imagefilledrectangle($image, 24, 272, 551, 273, $black);
        zebra_issue_each_bitmap($item, static function ($x, $y, $bitmap) use ($image) {
            imagecopy($image, $bitmap, $x, $y, 0, 0, imagesx($bitmap), imagesy($bitmap));
        });
        $scale = zebra_pick_image_scale();
        $output = imagecreatetruecolor(576 * $scale, 508 * $scale);
        imagecopyresized($output, $image, 0, 0, 0, 0, 576 * $scale, 508 * $scale, 576, 508);
        $folder = dirname((string)$path);
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException('Unable to create output folder.');
        }
        if (!imagepng($output, $path, zebra_pick_image_png_compression())) {
            throw new RuntimeException('Unable to save PNG preview.');
        }
        return ['ok' => true, 'message' => 'Issued Material 3 x 2.5-inch Poppins label rendered.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        if ($image !== null) imagedestroy($image);
        if ($output !== null) imagedestroy($output);
    }
}
