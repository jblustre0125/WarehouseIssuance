<?php

/*
    Optional Composer autoload for Endroid QR Code.
    This file still runs even if Composer is not installed, but NITTO QR rendering
    will show "QR LIBRARY MISSING" until endroid/qr-code is installed.
*/
foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php'
] as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require_once $autoloadFile;
        break;
    }
}


function zebra_print_enabled()
{
    return defined('ZEBRA_PRINT_ENABLED') && ZEBRA_PRINT_ENABLED;
}

function zebra_pick_print_enabled()
{
    return defined('PICK_TAG_PRINT_ENABLED') ? (bool)PICK_TAG_PRINT_ENABLED : zebra_print_enabled();
}

function zebra_printer_share()
{
    return defined('ZEBRA_PRINTER_SHARE') ? trim((string)ZEBRA_PRINTER_SHARE) : '';
}

function zebra_pick_printer_share()
{
    if (defined('PICK_TAG_PRINTER_SHARE')) {
        return trim((string)PICK_TAG_PRINTER_SHARE);
    }

    if (defined('PICK_TAG_PRINTER_NAME')) {
        return trim((string)PICK_TAG_PRINTER_NAME);
    }

    return zebra_printer_share();
}

function zebra_pick_printer_queue()
{
    if (defined('PICK_TAG_PRINTER_QUEUE')) {
        return trim((string)PICK_TAG_PRINTER_QUEUE);
    }

    return zebra_pick_printer_name();
}

function zebra_pick_printer_name()
{
    if (defined('PICK_TAG_PRINTER_NAME')) {
        return trim((string)PICK_TAG_PRINTER_NAME);
    }

    $share = zebra_pick_printer_share();

    return $share !== '' ? $share : 'NITTO DURA-SL-400';
}

function zebra_print_connection()
{
    return defined('ZEBRA_PRINT_CONNECTION') ? strtolower(trim((string)ZEBRA_PRINT_CONNECTION)) : 'windows_share';
}

function zebra_pick_print_connection()
{
    return defined('PICK_TAG_PRINT_CONNECTION') ? strtolower(trim((string)PICK_TAG_PRINT_CONNECTION)) : zebra_print_connection();
}

function zebra_printer_host()
{
    return defined('ZEBRA_PRINTER_HOST') ? trim((string)ZEBRA_PRINTER_HOST) : '';
}

function zebra_pick_printer_host()
{
    return defined('PICK_TAG_PRINTER_HOST') ? trim((string)PICK_TAG_PRINTER_HOST) : zebra_printer_host();
}

function zebra_printer_port()
{
    return defined('ZEBRA_PRINTER_PORT') ? (int)ZEBRA_PRINTER_PORT : 9100;
}

function zebra_pick_printer_port()
{
    return defined('PICK_TAG_PRINTER_PORT') ? (int)PICK_TAG_PRINTER_PORT : zebra_printer_port();
}

function zebra_label_end_zpl()
{
    $mode = defined('ZEBRA_LABEL_END_MODE') ? strtolower(trim((string)ZEBRA_LABEL_END_MODE)) : 'tear_off';

    if ($mode === 'cut') {
        return "^MMC\r\n";
    }

    $tearOffDots = defined('ZEBRA_TEAR_OFF_DOTS') ? (int)ZEBRA_TEAR_OFF_DOTS : 0;

    return "^MMT\r\n"
        . "^TA{$tearOffDots}\r\n";
}

function zebra_label_delay_seconds()
{
    /*
        Delay between every printed tag.
        Default is 5 seconds as requested.
        You can override it in config.php using:
            define('ZEBRA_LABEL_DELAY_SECONDS', 5);
    */
    return defined('ZEBRA_LABEL_DELAY_SECONDS') ? max(0, (int)ZEBRA_LABEL_DELAY_SECONDS) : 5;
}

function zebra_pick_label_delay_seconds()
{
    /*
        Backward compatibility with the old picker-only setting.
        If ZEBRA_PICK_LABEL_DELAY_SECONDS exists, it overrides the general delay for pick tags.
    */
    if (defined('PICK_TAG_LABEL_DELAY_SECONDS')) {
        return max(0, (int)PICK_TAG_LABEL_DELAY_SECONDS);
    }

    if (defined('ZEBRA_PICK_LABEL_DELAY_SECONDS')) {
        return max(0, (int)ZEBRA_PICK_LABEL_DELAY_SECONDS);
    }

    return zebra_label_delay_seconds();
}

function zebra_pick_max_label_bytes()
{
    return defined('PICK_TAG_MAX_LABEL_BYTES') ? max(0, (int)PICK_TAG_MAX_LABEL_BYTES) : 0;
}

function zebra_pick_batch_max_bytes()
{
    return defined('PICK_TAG_BATCH_MAX_BYTES') ? max(0, (int)PICK_TAG_BATCH_MAX_BYTES) : 0;
}

function zebra_pick_batch_cooldown_seconds()
{
    return defined('PICK_TAG_BATCH_COOLDOWN_SECONDS') ? max(0, (int)PICK_TAG_BATCH_COOLDOWN_SECONDS) : zebra_pick_label_delay_seconds();
}

function zebra_pick_width_hundredths()
{
    return defined('PICK_TAG_WIDTH_HUNDREDTHS') ? max(1, (int)PICK_TAG_WIDTH_HUNDREDTHS) : 300;
}

function zebra_pick_height_hundredths()
{
    return defined('PICK_TAG_HEIGHT_HUNDREDTHS') ? max(1, (int)PICK_TAG_HEIGHT_HUNDREDTHS) : 300;
}

function zebra_pick_image_scale()
{
    return defined('PICK_TAG_IMAGE_SCALE') ? max(1, min(3, (int)PICK_TAG_IMAGE_SCALE)) : 2;
}

function zebra_pick_image_png_compression()
{
    return defined('PICK_TAG_IMAGE_PNG_COMPRESSION') ? max(0, min(9, (int)PICK_TAG_IMAGE_PNG_COMPRESSION)) : 6;
}

function zebra_pick_printer_key($value = null)
{
    $key = strtolower(trim((string)($value ?? '')));

    if ($key === '' && defined('PICK_TAG_DEFAULT_PRINTER')) {
        $key = strtolower(trim((string)PICK_TAG_DEFAULT_PRINTER));
    }

    if (in_array($key, ['zebra', 'qln320', 'qnl320', 'zebra_qln320'], true)) {
        return 'zebra';
    }

    return 'nitto';
}

function zebra_pick_printer_label_for_key($printerKey = null)
{
    return zebra_pick_printer_key($printerKey) === 'zebra'
        ? 'Zebra QLn320'
        : zebra_pick_printer_name();
}

function zebra_cmd_arg($value)
{
    $value = (string)$value;
    $value = str_replace('"', '\"', $value);

    return '"' . $value . '"';
}

function zebra_zpl_text($value, $maxLength = 42)
{
    $value = trim((string)$value);

    /*
        Remove ZPL control characters from printable text.
        These characters can break the ZPL format.
    */
    $value = str_replace(["\r", "\n", '^', '~'], ' ', $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function zebra_normalize_print_target($printerShare)
{
    $target = trim((string)$printerShare);

    /*
        Accept:
            LPT1
            LPT1:
            COM1
            COM1:
            ZebraRawmat
            \\localhost\ZebraRawmat
            \\NBCP-DT-009\ZebraRawmat
    */

    if (preg_match('/^LPT[1-9]$/i', $target)) {
        return strtoupper($target) . ':';
    }

    if (preg_match('/^LPT[1-9]:$/i', $target)) {
        return strtoupper($target);
    }

    if (preg_match('/^COM[1-9]$/i', $target)) {
        return strtoupper($target) . ':';
    }

    if (preg_match('/^COM[1-9]:$/i', $target)) {
        return strtoupper($target);
    }

    if (strpos($target, '\\\\') === 0) {
        return $target;
    }

    return '\\\\localhost\\' . $target;
}

function zebra_receive_itr_barcode_value($traceNo, array $item)
{
    $candidates = [
        $item['itr_number'] ?? '',
        $item['itr_doc_num'] ?? '',
        $item['doc_num'] ?? '',
        $item['request_no'] ?? '',
        $traceNo
    ];

    foreach ($candidates as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }

    return trim((string)$traceNo);
}

function zebra_receive_label_zpl($traceNo, array $item)
{
    /*
        Receiving QR now uses the SAME payload format as picker:
        (01)itemcode(17)qty(10)lot
    */
    $payload = zebra_zpl_text(zebra_pick_qr_payload($item), 220);
    $itrBarcode = zebra_zpl_text(zebra_receive_itr_barcode_value($traceNo, $item), 60);

    $itemCode = zebra_zpl_text($item['item_code'] ?? '', 40);
    $partName = zebra_zpl_text($item['part_name'] ?? '', 70);
    $lotNo = zebra_zpl_text($item['lot_no'] ?? '', 45);
    $qty = zebra_zpl_text($item['quantity'] ?? '', 22);
    $uom = zebra_zpl_text($item['uom'] ?? '', 20);
    $traceNo = zebra_zpl_text($traceNo, 45);

    /*
        3 inch receiving tag for Zebra QLn320 / 203 DPI.

        QR centering note:
        The QR border is 190 x 190 dots.
        The QR is shifted upward so the top and bottom margins look more balanced inside the border.
        If your printer firmware renders the QR slightly different,
        adjust only the ^FO62,286 line by 5-dot steps.
    */
    return "^XA\r\n"
        . "^CI28\r\n"
        . "^PW576\r\n"
        . "^LL700\r\n"
        . "^LH0,0\r\n"
        . "^LS0\r\n"
        . zebra_label_end_zpl()
        . "^PR2\r\n"
        . "^MD6\r\n"

        /* Outer border */
        . "^FO20,14^GB536,660,2^FS\r\n"

        /* Header */
        . "^FO28,28^A0N,28,28^FDNBC RAWMATS TRACEABILITY^FS\r\n"
        . "^FO28,62^A0N,20,20^FDRECEIVING QR TAG^FS\r\n"
        . "^FO28,88^GB520,2,2^FS\r\n"

        /* ITR barcode section */
        . "^FO28,104^A0N,18,18^FDITR NUMBER^FS\r\n"
        . "^FO28,128^A0N,28,28^FD{$itrBarcode}^FS\r\n"
        . "^FO28,162^BY2,2,62^BCN,62,Y,N,N^FD{$itrBarcode}^FS\r\n"
        . "^FO28,250^GB520,2,2^FS\r\n"

        /* QR section - centered inside assigned border */
        . "^FO28,268^A0N,18,18^FDRECEIVE PAYLOAD^FS\r\n"
        . "^FO28,296^GB190,190,1^FS\r\n"
        . "^FO62,268^BQN,2,5^FDLA,{$payload}^FS\r\n"

        /* Item details on right */
        . "^FO238,268^A0N,18,18^FDITEM CODE^FS\r\n"
        . "^FO238,292^FB290,2,3,L^A0N,28,28^FD{$itemCode}^FS\r\n"

        . "^FO238,360^A0N,18,18^FDQTY^FS\r\n"
        . "^FO300,346^FB210,1,0,L^A0N,44,44^FD{$qty}^FS\r\n"

        . "^FO238,424^A0N,18,18^FDLOT NO^FS\r\n"
        . "^FO238,448^FB290,2,3,L^A0N,28,28^FD{$lotNo}^FS\r\n"

        /* Part name */
        . "^FO28,506^GB520,2,2^FS\r\n"
        . "^FO28,524^A0N,18,18^FDPART NAME^FS\r\n"
        . "^FO28,548^FB510,2,3,L^A0N,22,22^FD{$partName}^FS\r\n"

        /* Small payload text */
        . "^FO28,648^A0N,14,14^FD{$payload}^FS\r\n"
        . "^XZ\r\n";
}

function zebra_pick_qr_payload(array $item)
{
    $itemCode = trim((string)($item['item_code'] ?? ''));
    $qty = trim((string)($item['quantity'] ?? ''));
    $lotNo = trim((string)($item['lot_no'] ?? ''));

    return '(01)' . $itemCode . '(17)' . $qty . '(10)' . $lotNo;
}

function zebra_pick_reference_barcode_value(array $item)
{
    $requestNo = trim((string)($item['request_no'] ?? ''));
    $itrNumber = trim((string)($item['itr_number'] ?? ''));
    $itrDocNum = trim((string)($item['itr_doc_num'] ?? ''));
    $docNum = trim((string)($item['doc_num'] ?? ''));
    $sourceType = strtolower(trim((string)($item['source_type'] ?? '')));

    /*
        For purchase order picker tags, the Reference column is like:
            PO 120002132
        The barcode must contain only:
            120002132
    */
    if ($sourceType === 'purchase_order' || stripos($requestNo, 'PO ') === 0) {
        if (preg_match('/^PO\s+(.+)$/i', $requestNo, $m)) {
            return trim($m[1]);
        }

        return $requestNo;
    }

    /*
        For normal request / ITR picker tags, barcode only the ITR number.
    */
    foreach ([$itrNumber, $itrDocNum, $docNum, $requestNo] as $value) {
        if ($value !== '') {
            return $value; 
        }
    }

    return '';
}

function zebra_pick_label_zpl(array $item)
{
    $payload = zebra_zpl_text(zebra_pick_qr_payload($item), 220);
    $referenceBarcode = zebra_zpl_text(zebra_pick_reference_barcode_value($item), 60);
    $itemCode = zebra_zpl_text($item['item_code'] ?? '', 40);
    $partName = zebra_zpl_text($item['part_name'] ?? '', 72);
    $lotNo = zebra_zpl_text($item['lot_no'] ?? '', 45);
    $warehouseLotNo = zebra_zpl_text($item['warehouse_lot_no'] ?? '', 45);
    $qty = zebra_zpl_text($item['quantity'] ?? '', 22);
    $uom = zebra_zpl_text($item['uom'] ?? '', 20);
    $requestNoRaw = trim((string)($item['request_no'] ?? ''));
    $itrNumberRaw = trim((string)($item['itr_number'] ?? ''));
    $sourceType = strtolower(trim((string)($item['source_type'] ?? '')));

    $isPurchaseOrder = $sourceType === 'purchase_order' || stripos($requestNoRaw, 'PO ') === 0;
    $referenceLabel = $isPurchaseOrder ? 'REFERENCE / PO' : 'REFERENCE / REQ-ITR';
    $referenceText = $isPurchaseOrder ? $requestNoRaw : trim($requestNoRaw . ' ' . $itrNumberRaw);
    $referenceText = zebra_zpl_text($referenceText, 65);

    if ($referenceBarcode === '') {
        $referenceBarcode = $referenceText;
    }

    if ($warehouseLotNo !== '') {
        $lotBlock = "^FO238,416^A0N,18,18^FDGRPO LOT NO^FS\r\n"
            . "^FO238,438^FB290,2,3,L^A0N,25,25^FD{$lotNo}^FS\r\n"
            . "^FO238,492^A0N,18,18^FDWH LOT NO^FS\r\n"
            . "^FO238,514^FB290,1,0,L^A0N,25,25^FD{$warehouseLotNo}^FS\r\n";
        $partBlock = "^FO28,558^GB520,2,2^FS\r\n"
            . "^FO28,574^A0N,18,18^FDPART NAME^FS\r\n"
            . "^FO28,598^FB510,2,3,L^A0N,20,20^FD{$partName}^FS\r\n";
    } else {
        $lotBlock = "^FO238,432^A0N,18,18^FDLOT NO^FS\r\n"
            . "^FO238,456^FB290,2,3,L^A0N,28,28^FD{$lotNo}^FS\r\n";
        $partBlock = "^FO28,506^GB520,2,2^FS\r\n"
            . "^FO28,524^A0N,18,18^FDPART NAME^FS\r\n"
            . "^FO28,548^FB510,2,3,L^A0N,22,22^FD{$partName}^FS\r\n";
    }

    /*
        3 inch picker tag for Zebra QLn320 / 203 DPI.

        QR centering note:
        The QR border is 190 x 190 dots.
        The QR is shifted upward so the top and bottom margins look more balanced inside the border.
        This matches the receiving QR payload box, with the QR moved higher for better vertical centering.
    */
    return "^XA\r\n"
        . "^CI28\r\n"
        . "^PW576\r\n"
        . "^LL700\r\n"
        . "^LH0,0\r\n"
        . "^LS0\r\n"
        . zebra_label_end_zpl()
        . "^PR2\r\n"
        . "^MD6\r\n"

        /* Outer border */
        . "^FO20,14^GB536,660,2^FS\r\n"

        /* Header */
        . "^FO28,28^A0N,28,28^FDNBC RAWMATS TRACEABILITY^FS\r\n"
        . "^FO28,62^A0N,20,20^FDPICKER TAG^FS\r\n"
        . "^FO28,88^GB520,2,2^FS\r\n"

        /* Reference barcode section */
        . "^FO28,104^A0N,19,19^FD{$referenceLabel}^FS\r\n"
        . "^FO28,130^A0N,30,30^FD{$referenceText}^FS\r\n"
        . "^FO28,170^BY2,2,62^BCN,62,Y,N,N^FD{$referenceBarcode}^FS\r\n"
        . "^FO28,258^GB520,2,2^FS\r\n"

        /* QR section - item/qty/lot payload */
        . "^FO28,276^A0N,19,19^FDQR PAYLOAD^FS\r\n"
        . "^FO28,304^GB190,190,1^FS\r\n"
        . "^FO62,276^BQN,2,5^FDLA,{$payload}^FS\r\n"

        /* Item details on right */
        . "^FO238,276^A0N,18,18^FDITEM CODE^FS\r\n"
        . "^FO238,300^FB290,2,3,L^A0N,28,28^FD{$itemCode}^FS\r\n"

        . "^FO238,368^A0N,18,18^FDQTY {$uom}^FS\r\n"
        . "^FO300,354^FB210,1,0,L^A0N,44,44^FD{$qty}^FS\r\n"

        . $lotBlock

        /* Part name */
        . $partBlock

        /* Small payload text */
        . "^FO28,648^A0N,14,14^FD{$payload}^FS\r\n"
        . "^XZ\r\n";
}

function zebra_code128_patterns()
{
    return [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112'
    ];
}

function zebra_code128_b_values($value)
{
    $value = (string)$value;
    $values = [104];
    $checksum = 104;
    $weight = 1;
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $ord = ord($value[$i]);

        if ($ord < 32 || $ord > 126) {
            $ord = 32;
        }

        $code = $ord - 32;
        $values[] = $code;
        $checksum += $code * $weight;
        $weight++;
    }

    $values[] = $checksum % 103;
    $values[] = 106;

    return $values;
}

function zebra_draw_code128($image, $x, $y, $width, $height, $value, $label = '')
{
    $value = zebra_zpl_text($value, 96);
    $patterns = zebra_code128_patterns();
    $values = zebra_code128_b_values($value);
    $moduleCount = 0;

    foreach ($values as $code) {
        $pattern = $patterns[$code] ?? '';
        $moduleCount += array_sum(array_map('intval', str_split($pattern)));
    }

    if ($moduleCount <= 0) {
        return;
    }

    $quiet = 10;
    $moduleWidth = max(1, (int)floor(($width - ($quiet * 2)) / $moduleCount));
    $barcodeWidth = $moduleCount * $moduleWidth;
    $cursor = $x + (int)floor(($width - $barcodeWidth) / 2);
    $black = imagecolorallocate($image, 0, 0, 0);
    $white = imagecolorallocate($image, 255, 255, 255);

    imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $white);

    foreach ($values as $code) {
        $pattern = $patterns[$code] ?? '';
        $isBar = true;

        for ($i = 0, $len = strlen($pattern); $i < $len; $i++) {
            $barWidth = (int)$pattern[$i] * $moduleWidth;

            if ($isBar && $barWidth > 0) {
                /* Slightly thicken the bars for the NITTO driver print. */
                imagefilledrectangle($image, $cursor, $y, $cursor + $barWidth, $y + $height - 18, $black);
            }

            $cursor += $barWidth;
            $isBar = !$isBar;
        }
    }

    $text = $label !== '' ? $label : $value;
    $font = 2;
    $textWidth = imagefontwidth($font) * strlen($text);
    $tx = $x + max(0, (int)(($width - $textWidth) / 2));
    imagestring($image, $font, $tx, $y + $height - 15, $text, $black);
    imagestring($image, $font, $tx + 1, $y + $height - 15, $text, $black);
}

function zebra_font_path()
{
    /*
        Use TrueType bold font for thermal label printing.
        Do not ship Windows fonts with the project. This function only references
        fonts already installed on the Windows/XAMPP server, or your own font
        copied into assets/fonts.
    */
    $candidates = [
        __DIR__ . '/../assets/fonts/arialbd.ttf',
        __DIR__ . '/../assets/fonts/Arial_Bold.ttf',
        __DIR__ . '/../assets/fonts/calibrib.ttf',
        __DIR__ . '/assets/fonts/arialbd.ttf',
        __DIR__ . '/assets/fonts/Arial_Bold.ttf',
        __DIR__ . '/assets/fonts/calibrib.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/Arialbd.ttf',
        'C:/Windows/Fonts/calibrib.ttf',
        'C:/Windows/Fonts/CalibriB.ttf',
    ];

    foreach ($candidates as $font) {
        if (is_file($font)) {
            return $font;
        }
    }

    return '';
}

function zebra_gd_font_size($font)
{
    /*
        Font sizes are based on the original 1218px render. Scale them down
        when generating a lighter 609px image so the physical layout stays stable.
    */
    $scaleFactor = zebra_pick_image_scale() / 2;
    $map = [
        1 => 16,
        2 => 18,
        3 => 22,
        4 => 26,
        5 => 28,
        6 => 34,
        7 => 40,
        8 => 46,
        9 => 54,
    ];

    $size = $map[(int)$font] ?? 22;

    return max(8, (int)round($size * $scaleFactor));
}

function zebra_gd_text($image, $x, $y, $text, $font = 3)
{
    $black = imagecolorallocate($image, 0, 0, 0);
    $text = zebra_zpl_text($text, 120);
    $fontPath = zebra_font_path();

    if ($fontPath !== '' && function_exists('imagettftext')) {
        $size = zebra_gd_font_size($font);

        /*
            imagettftext() uses baseline Y, so add the font size.
            Draw twice to make text stronger on low-density thermal transfer.
        */
        imagettftext($image, $size, 0, $x, $y + $size, $black, $fontPath, $text);
        imagettftext($image, $size, 0, $x + 1, $y + $size, $black, $fontPath, $text);
        return;
    }

    /* Fallback if FreeType/TTF is not available. */
    imagestring($image, $font, $x, $y, $text, $black);
    imagestring($image, $font, $x + 1, $y, $text, $black);
}

function zebra_gd_text_heavy($image, $x, $y, $text, $font = 3)
{
    $black = imagecolorallocate($image, 0, 0, 0);
    $text = zebra_zpl_text($text, 120);
    $fontPath = zebra_font_path();

    if ($fontPath !== '' && function_exists('imagettftext')) {
        $size = zebra_gd_font_size($font);

        /* Extra-bold effect for important values. */
        imagettftext($image, $size, 0, $x, $y + $size, $black, $fontPath, $text);
        imagettftext($image, $size, 0, $x + 1, $y + $size, $black, $fontPath, $text);
        imagettftext($image, $size, 0, $x, $y + $size + 1, $black, $fontPath, $text);
        imagettftext($image, $size, 0, $x + 1, $y + $size + 1, $black, $fontPath, $text);
        return;
    }

    imagestring($image, $font, $x, $y, $text, $black);
    imagestring($image, $font, $x + 1, $y, $text, $black);
    imagestring($image, $font, $x, $y + 1, $text, $black);
    imagestring($image, $font, $x + 1, $y + 1, $text, $black);
}

function zebra_gd_wrapped_text($image, $x, $y, $width, $lineHeight, $text, $font = 3, $maxLines = 2, $heavy = false)
{
    $text = trim((string)$text);
    $fontPath = zebra_font_path();

    if ($fontPath !== '' && function_exists('imagettftext')) {
        $size = zebra_gd_font_size($font);
        $averageCharWidth = max(5, (int)ceil($size * 0.62));
        $maxChars = max(1, (int)floor($width / $averageCharWidth));
    } else {
        $charWidth = imagefontwidth($font);
        $maxChars = max(1, (int)floor($width / $charWidth));
    }

    $lines = [];

    foreach (explode("\n", wordwrap($text, $maxChars, "\n", true)) as $line) {
        $line = trim($line);

        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) >= $maxLines) {
            break;
        }
    }

    foreach ($lines as $idx => $line) {
        if ($heavy) {
            zebra_gd_text_heavy($image, $x, $y + ($idx * $lineHeight), $line, $font);
        } else {
            zebra_gd_text($image, $x, $y + ($idx * $lineHeight), $line, $font);
        }
    }
}

function zebra_qr_gf_tables()
{
    static $tables = null;

    if ($tables !== null) {
        return $tables;
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;

    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;

        if ($x & 0x100) {
            $x ^= 0x11d;
        }
    }

    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }

    $tables = [$exp, $log];

    return $tables;
}

function zebra_qr_gf_mul($a, $b)
{
    if ($a === 0 || $b === 0) {
        return 0;
    }

    [$exp, $log] = zebra_qr_gf_tables();

    return $exp[$log[$a] + $log[$b]];
}

function zebra_qr_generator_poly($degree)
{
    [$exp] = zebra_qr_gf_tables();
    $poly = [1];

    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);

        for ($j = 0, $count = count($poly); $j < $count; $j++) {
            $next[$j] ^= zebra_qr_gf_mul($poly[$j], 1);
            $next[$j + 1] ^= zebra_qr_gf_mul($poly[$j], $exp[$i]);
        }

        $poly = $next;
    }

    return $poly;
}

function zebra_qr_reed_solomon(array $data, $degree)
{
    $generator = zebra_qr_generator_poly($degree);
    $remainder = array_fill(0, $degree, 0);

    foreach ($data as $byte) {
        $factor = $byte ^ $remainder[0];
        array_shift($remainder);
        $remainder[] = 0;

        for ($i = 0; $i < $degree; $i++) {
            $remainder[$i] ^= zebra_qr_gf_mul($generator[$i + 1], $factor);
        }
    }

    return $remainder;
}

function zebra_qr_append_bits(array &$bits, $value, $length)
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = ($value >> $i) & 1;
    }
}

function zebra_qr_bytes_to_bits(array $bytes)
{
    $bits = [];

    foreach ($bytes as $byte) {
        zebra_qr_append_bits($bits, $byte, 8);
    }

    return $bits;
}

function zebra_qr_fixed_v6_l_codewords($text)
{
    $text = (string)$text;

    if (strlen($text) > 134) {
        $text = substr($text, 0, 134);
    }

    $bits = [];
    zebra_qr_append_bits($bits, 4, 4); // Byte mode.
    zebra_qr_append_bits($bits, strlen($text), 8);

    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        zebra_qr_append_bits($bits, ord($text[$i]), 8);
    }

    $dataCodewords = 136;
    $capacityBits = $dataCodewords * 8;
    $terminator = min(4, $capacityBits - count($bits));

    for ($i = 0; $i < $terminator; $i++) {
        $bits[] = 0;
    }

    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }

    $data = [];

    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;

        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i + $j];
        }

        $data[] = $byte;
    }

    $pad = [0xec, 0x11];
    $padIdx = 0;

    while (count($data) < $dataCodewords) {
        $data[] = $pad[$padIdx % 2];
        $padIdx++;
    }

    $blocks = [
        array_slice($data, 0, 68),
        array_slice($data, 68, 68)
    ];
    $eccBlocks = [
        zebra_qr_reed_solomon($blocks[0], 18),
        zebra_qr_reed_solomon($blocks[1], 18)
    ];
    $result = [];

    for ($i = 0; $i < 68; $i++) {
        $result[] = $blocks[0][$i];
        $result[] = $blocks[1][$i];
    }

    for ($i = 0; $i < 18; $i++) {
        $result[] = $eccBlocks[0][$i];
        $result[] = $eccBlocks[1][$i];
    }

    return $result;
}

function zebra_qr_fixed_v3_l_codewords($text)
{
    $text = (string)$text;

    if (strlen($text) > 53) {
        $text = substr($text, 0, 53);
    }

    $bits = [];
    zebra_qr_append_bits($bits, 4, 4); // Byte mode.
    zebra_qr_append_bits($bits, strlen($text), 8);

    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        zebra_qr_append_bits($bits, ord($text[$i]), 8);
    }

    $dataCodewords = 55;
    $capacityBits = $dataCodewords * 8;
    $terminator = min(4, $capacityBits - count($bits));

    for ($i = 0; $i < $terminator; $i++) {
        $bits[] = 0;
    }

    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }

    $data = [];

    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;

        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i + $j];
        }

        $data[] = $byte;
    }

    $pad = [0xec, 0x11];
    $padIdx = 0;

    while (count($data) < $dataCodewords) {
        $data[] = $pad[$padIdx % 2];
        $padIdx++;
    }

    return array_merge($data, zebra_qr_reed_solomon($data, 15));
}

function zebra_qr_set(&$matrix, &$reserved, $x, $y, $value, $isReserved = true)
{
    $size = count($matrix);

    if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
        return;
    }

    $matrix[$y][$x] = (bool)$value;

    if ($isReserved) {
        $reserved[$y][$x] = true;
    }
}

function zebra_qr_add_finder(&$matrix, &$reserved, $x, $y)
{
    for ($dy = -1; $dy <= 7; $dy++) {
        for ($dx = -1; $dx <= 7; $dx++) {
            $xx = $x + $dx;
            $yy = $y + $dy;
            $on = ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6)
                && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
            zebra_qr_set($matrix, $reserved, $xx, $yy, $on, true);
        }
    }
}

function zebra_qr_add_alignment(&$matrix, &$reserved, $cx, $cy)
{
    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $on = max(abs($dx), abs($dy)) !== 1;
            zebra_qr_set($matrix, $reserved, $cx + $dx, $cy + $dy, $on, true);
        }
    }
}

function zebra_qr_bch_remainder($value, $poly, $degree)
{
    $value <<= $degree;

    for ($i = (int)floor(log($value, 2)); $i >= $degree; $i--) {
        if (($value >> $i) & 1) {
            $value ^= $poly << ($i - $degree);
        }
    }

    return $value;
}

function zebra_qr_add_format(&$matrix, &$reserved, $mask)
{
    $size = count($matrix);
    $data = (1 << 3) | $mask; // Error correction L, mask number.
    $bits = (($data << 10) | zebra_qr_bch_remainder($data, 0x537, 10)) ^ 0x5412;

    for ($i = 0; $i < 15; $i++) {
        $bit = (($bits >> $i) & 1) === 1;

        if ($i < 6) {
            zebra_qr_set($matrix, $reserved, 8, $i, $bit, true);
        } elseif ($i === 6) {
            zebra_qr_set($matrix, $reserved, 8, 7, $bit, true);
        } elseif ($i === 7) {
            zebra_qr_set($matrix, $reserved, 8, 8, $bit, true);
        } elseif ($i === 8) {
            zebra_qr_set($matrix, $reserved, 7, 8, $bit, true);
        } else {
            zebra_qr_set($matrix, $reserved, 14 - $i, 8, $bit, true);
        }

        if ($i < 8) {
            zebra_qr_set($matrix, $reserved, $size - 1 - $i, 8, $bit, true);
        } else {
            zebra_qr_set($matrix, $reserved, 8, $size - 15 + $i, $bit, true);
        }
    }
}

function zebra_qr_matrix($text)
{
    $text = (string)$text;
    $version = strlen($text) <= 53 ? 3 : 6;
    $size = 17 + ($version * 4);
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    zebra_qr_add_finder($matrix, $reserved, 0, 0);
    zebra_qr_add_finder($matrix, $reserved, $size - 7, 0);
    zebra_qr_add_finder($matrix, $reserved, 0, $size - 7);

    $alignmentCenter = $version === 3 ? 22 : 34;
    zebra_qr_add_alignment($matrix, $reserved, $alignmentCenter, $alignmentCenter);

    for ($i = 8; $i < $size - 8; $i++) {
        zebra_qr_set($matrix, $reserved, $i, 6, $i % 2 === 0, true);
        zebra_qr_set($matrix, $reserved, 6, $i, $i % 2 === 0, true);
    }

    zebra_qr_set($matrix, $reserved, 8, (4 * $version) + 9, true, true);

    for ($i = 0; $i < 9; $i++) {
        zebra_qr_set($matrix, $reserved, 8, $i, $matrix[$i][8] ?? false, true);
        zebra_qr_set($matrix, $reserved, $i, 8, $matrix[8][$i] ?? false, true);
    }

    $bits = zebra_qr_bytes_to_bits($version === 3 ? zebra_qr_fixed_v3_l_codewords($text) : zebra_qr_fixed_v6_l_codewords($text));
    $idx = 0;
    $up = true;

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }

        for ($vert = 0; $vert < $size; $vert++) {
            $y = $up ? ($size - 1 - $vert) : $vert;

            for ($col = 0; $col < 2; $col++) {
                $x = $right - $col;

                if ($reserved[$y][$x]) {
                    continue;
                }

                $bit = $idx < count($bits) ? (bool)$bits[$idx] : false;

                if ((($x + $y) % 2) === 0) {
                    $bit = !$bit;
                }

                zebra_qr_set($matrix, $reserved, $x, $y, $bit, false);
                $idx++;
            }
        }

        $up = !$up;
    }

    zebra_qr_add_format($matrix, $reserved, 0);

    return $matrix;
}

function zebra_endroid_error_correction_medium()
{
    if (class_exists('\\Endroid\\QrCode\\ErrorCorrectionLevel')) {
        foreach (['Medium', 'MEDIUM', 'M', 'Low', 'LOW', 'L'] as $name) {
            $constant = '\\Endroid\\QrCode\\ErrorCorrectionLevel::' . $name;
            if (defined($constant)) {
                return constant($constant);
            }
        }
    }

    foreach ([
        '\\Endroid\\QrCode\\ErrorCorrectionLevel\\ErrorCorrectionLevelM',
        '\\Endroid\\QrCode\\ErrorCorrectionLevel\\ErrorCorrectionLevelL'
    ] as $class) {
        if (class_exists($class)) {
            return new $class();
        }
    }

    return null;
}

function zebra_endroid_round_block_margin()
{
    if (class_exists('\\Endroid\\QrCode\\RoundBlockSizeMode')) {
        foreach (['Margin', 'MARGIN', 'Enlarge', 'ENLARGE'] as $name) {
            $constant = '\\Endroid\\QrCode\\RoundBlockSizeMode::' . $name;
            if (defined($constant)) {
                return constant($constant);
            }
        }
    }

    foreach ([
        '\\Endroid\\QrCode\\RoundBlockSizeMode\\RoundBlockSizeModeMargin',
        '\\Endroid\\QrCode\\RoundBlockSizeMode\\RoundBlockSizeModeEnlarge'
    ] as $class) {
        if (class_exists($class)) {
            return new $class();
        }
    }

    return null;
}

function zebra_endroid_encoding_utf8()
{
    $class = '\\Endroid\\QrCode\\Encoding\\Encoding';

    if (class_exists($class)) {
        return new $class('UTF-8');
    }

    return null;
}

function zebra_build_endroid_qrcode($text, $sizePx, $margin)
{
    $qrClass = '\\Endroid\\QrCode\\QrCode';

    if (!class_exists($qrClass)) {
        return null;
    }

    $encoding = zebra_endroid_encoding_utf8();
    $ecc = zebra_endroid_error_correction_medium();
    $roundBlockSizeMode = zebra_endroid_round_block_margin();

    /*
        Endroid v4/v5 style usually supports QrCode::create().
        Use it first when available because it avoids constructor-version issues.
    */
    if (method_exists($qrClass, 'create')) {
        $qrCode = $qrClass::create((string)$text);

        if ($encoding !== null && method_exists($qrCode, 'setEncoding')) {
            $qrCode = $qrCode->setEncoding($encoding);
        }

        if ($ecc !== null && method_exists($qrCode, 'setErrorCorrectionLevel')) {
            $qrCode = $qrCode->setErrorCorrectionLevel($ecc);
        }

        if (method_exists($qrCode, 'setSize')) {
            $qrCode = $qrCode->setSize((int)$sizePx);
        }

        if (method_exists($qrCode, 'setMargin')) {
            $qrCode = $qrCode->setMargin((int)$margin);
        }

        if ($roundBlockSizeMode !== null && method_exists($qrCode, 'setRoundBlockSizeMode')) {
            $qrCode = $qrCode->setRoundBlockSizeMode($roundBlockSizeMode);
        }

        return $qrCode;
    }

    /*
        Endroid v6 style uses constructor parameters.
        Build constructor arguments by parameter name so it works without the Builder helper.
    */
    try {
        $reflection = new ReflectionClass($qrClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $qrClass((string)$text);
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if ($name === 'data') {
                $args[] = (string)$text;
            } elseif ($name === 'encoding') {
                $args[] = $encoding;
            } elseif ($name === 'errorCorrectionLevel') {
                $args[] = $ecc;
            } elseif ($name === 'size') {
                $args[] = (int)$sizePx;
            } elseif ($name === 'margin') {
                $args[] = (int)$margin;
            } elseif ($name === 'roundBlockSizeMode') {
                $args[] = $roundBlockSizeMode;
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return $reflection->newInstanceArgs($args);
    } catch (Throwable $e) {
        return null;
    }
}

function zebra_create_standard_qr_png_string($text, $sizePx)
{
    $writerClass = '\\Endroid\\QrCode\\Writer\\PngWriter';

    if (!class_exists($writerClass) || !class_exists('\\Endroid\\QrCode\\QrCode')) {
        return null;
    }

    try {
        $qrCode = zebra_build_endroid_qrcode((string)$text, (int)$sizePx, 16);

        if ($qrCode === null) {
            return null;
        }

        $writer = new $writerClass();

        if (method_exists($writer, 'write')) {
            $result = $writer->write($qrCode);

            if (is_object($result) && method_exists($result, 'getString')) {
                return $result->getString();
            }
        }

        if (method_exists($writer, 'writeString')) {
            return $writer->writeString($qrCode);
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function zebra_draw_qr($image, $x, $y, $sizePx, $text)
{
    $text = (string)$text;

    $black = imagecolorallocate($image, 0, 0, 0);
    $white = imagecolorallocate($image, 255, 255, 255);

    imagefilledrectangle($image, $x, $y, $x + $sizePx, $y + $sizePx, $white);

    $qrPngString = zebra_create_standard_qr_png_string($text, $sizePx);

    if ($qrPngString === null) {
        imagestring($image, 3, $x + 10, $y + 10, 'QR LIBRARY MISSING', $black);
        imagestring($image, 2, $x + 10, $y + 30, 'Run: composer require endroid/qr-code', $black);
        return;
    }

    $qrPng = imagecreatefromstring($qrPngString);

    if (!$qrPng) {
        imagestring($image, 3, $x + 10, $y + 10, 'QR IMAGE ERROR', $black);
        return;
    }

    $srcW = imagesx($qrPng);
    $srcH = imagesy($qrPng);

    imagecopyresampled($image, $qrPng, $x, $y, 0, 0, $sizePx, $sizePx, $srcW, $srcH);
    imagedestroy($qrPng);
}

function zebra_pick_label_png(array $item, $path)
{
    if (!function_exists('imagecreatetruecolor')) {
        return [
            'ok' => false,
            'message' => 'PHP GD is not enabled, so the Nitto driver label image cannot be rendered.'
        ];
    }

    /*
        Design coordinates are based on a 609px / 203 DPI 3-inch label.
        Use PICK_TAG_IMAGE_SCALE = 2 only if the printer needs the older
        1218px high-resolution image; scale 1 spools much faster on shared queues.
    */
    $s = zebra_pick_image_scale();
    $width = 609 * $s;
    $height = 609 * $s;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);

    imagealphablending($image, false);
    imagesavealpha($image, false);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);

    $payload = zebra_pick_qr_payload($item);
    $referenceBarcode = zebra_pick_reference_barcode_value($item);
    $itemCode = zebra_zpl_text($item['item_code'] ?? '', 40);
    $partName = zebra_zpl_text($item['part_name'] ?? '', 58);
    $lotNo = zebra_zpl_text($item['lot_no'] ?? '', 35);
    $warehouseLotNo = zebra_zpl_text($item['warehouse_lot_no'] ?? '', 35);
    $qty = zebra_zpl_text($item['quantity'] ?? '', 18);
    $uom = zebra_zpl_text($item['uom'] ?? '', 12);
    $requestNo = trim((string)($item['request_no'] ?? ''));
    $itrNumber = trim((string)($item['itr_number'] ?? ''));
    $sourceType = strtolower(trim((string)($item['source_type'] ?? '')));

    $isPurchaseOrder = $sourceType === 'purchase_order' || stripos($requestNo, 'PO ') === 0;
    $referenceLabel = $isPurchaseOrder ? 'REFERENCE / PO' : 'REFERENCE / REQ-ITR';
    $referenceText = $isPurchaseOrder ? $requestNo : trim($requestNo . ' ' . $itrNumber);
    $referenceText = zebra_zpl_text($referenceText, 50);

    if ($referenceBarcode === '') {
        $referenceBarcode = $referenceText;
    }

    /* Helper scale function. Design coordinates are based on a 609px layout. */
    $p = static fn($v): int => (int)round($v * $s);

    /* Outer border and header. */
    imagesetthickness($image, $p(3));
    imagerectangle($image, $p(12), $p(12), $p(597), $p(597), $black);

    zebra_gd_text_heavy($image, $p(24), $p(20), 'NBC RAWMATS TRACEABILITY', 3);
    zebra_gd_text_heavy($image, $p(24), $p(50), 'PICKER TAG - 3 X 3', 2);
    zebra_gd_text_heavy($image, $p(410), $p(50), 'NITTO DURA-SL-400', 2);
    imagesetthickness($image, $p(2));
    imageline($image, $p(24), $p(76), $p(585), $p(76), $black);

    /* Reference barcode section. */
    zebra_gd_text_heavy($image, $p(24), $p(88), $referenceLabel, 2);
    zebra_gd_wrapped_text($image, $p(24), $p(112), $p(540), $p(22), $referenceText, 4, 1, true);

    /* Bigger barcode. Avoid very tiny HRI text below. */
    zebra_draw_code128($image, $p(34), $p(150), $p(540), $p(82), $referenceBarcode, $referenceBarcode);

    imagesetthickness($image, $p(2));
    imageline($image, $p(24), $p(250), $p(585), $p(250), $black);

    /* Main PAYLOAD QR. */
    zebra_gd_text_heavy($image, $p(24), $p(264), 'PAYLOAD QR', 2);
    imagefilledrectangle($image, $p(24), $p(292), $p(294), $p(562), $white);
    imagesetthickness($image, $p(2));
    imagerectangle($image, $p(24), $p(292), $p(294), $p(562), $black);
    zebra_draw_qr($image, $p(34), $p(302), $p(250), $payload);

    /* Main values block. Keep item code here only so it is not redundant. */
    zebra_gd_text_heavy($image, $p(316), $p(264), 'ITEM CODE', 2);
    zebra_gd_wrapped_text($image, $p(316), $p(292), $p(108), $p(28), $itemCode, 4, 1, true);

    zebra_gd_text_heavy($image, $p(316), $p(372), 'QTY ' . $uom, 2);
    zebra_gd_wrapped_text($image, $p(316), $p(402), $p(108), $p(32), $qty, 5, 1, true);

    if ($warehouseLotNo !== '') {
        zebra_gd_text_heavy($image, $p(316), $p(486), 'GRPO LOT', 2);
        zebra_gd_wrapped_text($image, $p(316), $p(515), $p(108), $p(26), $lotNo, 4, 1, true);
        zebra_gd_text_heavy($image, $p(316), $p(555), 'WH LOT', 2);
        zebra_gd_wrapped_text($image, $p(380), $p(555), $p(200), $p(24), $warehouseLotNo, 3, 1, true);
    } else {
        zebra_gd_text_heavy($image, $p(316), $p(486), 'LOT NO', 2);
        zebra_gd_wrapped_text($image, $p(316), $p(518), $p(108), $p(28), $lotNo, 4, 1, true);
    }

    /* Small ITEM QR only. Remove the duplicate item code text below it. */
    zebra_gd_text_heavy($image, $p(438), $p(264), 'ITEM QR', 2);
    imagefilledrectangle($image, $p(430), $p(292), $p(584), $p(446), $white);
    imagesetthickness($image, $p(2));
    imagerectangle($image, $p(430), $p(292), $p(584), $p(446), $black);
    zebra_draw_qr($image, $p(440), $p(302), $p(134), $itemCode);

    /* Part name section. */
    imagesetthickness($image, $p(2));
    imageline($image, $p(24), $p(555), $p(585), $p(555), $black);
    zebra_gd_text_heavy($image, $p(24), $p(566), 'PART NAME', 2);
    zebra_gd_wrapped_text($image, $p(120), $p(566), $p(455), $p(24), $partName, 3, 1, true);

    $ok = imagepng($image, $path, zebra_pick_image_png_compression());
    imagedestroy($image);

    if (!$ok) {
        return [
            'ok' => false,
            'message' => 'Unable to create Nitto picker label image.'
        ];
    }

    return [
        'ok' => true,
        'message' => 'Nitto picker 3-inch label image rendered at ' . $width . 'x' . $height . 'px.'
    ];
}

function zebra_test_label_zpl()
{
    return "^XA\r\n"
        . "^CI28\r\n"
        . "^PW600\r\n"
        . "^LL400\r\n"
        . "^LH0,0\r\n"
        . "^LS0\r\n"
        . "^PR2\r\n"
        . "^MD6\r\n"
        . "^FO40,40^GB520,320,2^FS\r\n"
        . "^FO70,70^A0N,38,38^FDZEBRA TEST PRINT^FS\r\n"
        . "^FO70,130^A0N,26,26^FDIf this prints, raw ZPL is working.^FS\r\n"
        . "^FO70,180^BQN,2,6^FDLA,TEST-QR-12345^FS\r\n"
        . "^FO270,210^A0N,30,30^FDTEST-QR-12345^FS\r\n"
        . "^XZ\r\n";
}

function zebra_send_to_windows_share($zpl, $printerShare, $printerLabel = 'Zebra printer')
{
    $printerLabel = trim((string)$printerLabel);

    if ($printerLabel === '') {
        $printerLabel = 'printer';
    }

    if ($printerShare === '') {
        return [
            'ok' => false,
            'message' => $printerLabel . ' share is not configured.'
        ];
    }

    if (!function_exists('exec')) {
        return [
            'ok' => false,
            'message' => 'PHP exec() is disabled, so server-side ' . $printerLabel . ' printing cannot run.'
        ];
    }

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_' . uniqid('', true) . '.zpl';

    /*
        Normalize line endings for Windows.
    */
    $zpl = str_replace(["\r\n", "\r"], "\n", (string)$zpl);
    $zpl = str_replace("\n", "\r\n", $zpl);

    if (file_put_contents($tempFile, $zpl, LOCK_EX) === false) {
        return [
            'ok' => false,
            'message' => 'Unable to create temporary ZPL file.'
        ];
    }

    $target = zebra_normalize_print_target($printerShare);

    /*
        For mapped LPT/COM ports, do not use the Windows print command.
        Use binary copy only.
    */
    $copyCmd = 'cmd /c copy /B ' . zebra_cmd_arg($tempFile) . ' ' . zebra_cmd_arg($target);

    $copyOutput = [];
    $copyExitCode = 0;

    exec($copyCmd . ' 2>&1', $copyOutput, $copyExitCode);

    $copyMessage = trim(implode(' ', $copyOutput));

    @unlink($tempFile);

    $copyFailedText =
        stripos($copyMessage, 'access is denied') !== false ||
        stripos($copyMessage, '0 file') !== false ||
        stripos($copyMessage, 'not a recognized device') !== false ||
        stripos($copyMessage, 'cannot find') !== false ||
        stripos($copyMessage, 'error') !== false;

    if ($copyExitCode === 0 && !$copyFailedText && stripos($copyMessage, '1 file') !== false) {
        return [
            'ok' => true,
            'message' => 'Label sent to ' . $printerLabel . '. Output: ' . $copyMessage . ' Target: ' . $target . '. Bytes: ' . strlen((string)$zpl)
        ];
    }

    if ($copyMessage === '') {
        $copyMessage = 'No output from Windows copy command.';
    }

    return [
        'ok' => false,
        'message' => 'Unable to send label to ' . $printerLabel . '. Copy output: ' . $copyMessage . ' Target: ' . $target
    ];
}

function zebra_send_to_windows_queue($zpl, $printerName, $printerLabel = 'printer')
{
    $printerName = trim((string)$printerName);
    $printerLabel = trim((string)$printerLabel);

    if ($printerLabel === '') {
        $printerLabel = 'printer';
    }

    if ($printerName === '') {
        return [
            'ok' => false,
            'message' => $printerLabel . ' queue name is not configured.'
        ];
    }

    if (!function_exists('exec')) {
        return [
            'ok' => false,
            'message' => 'PHP exec() is disabled, so server-side ' . $printerLabel . ' printing cannot run.'
        ];
    }

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_' . uniqid('', true) . '.zpl';
    $zpl = str_replace(["\r\n", "\r"], "\n", (string)$zpl);
    $zpl = str_replace("\n", "\r\n", $zpl);

    if (file_put_contents($tempFile, $zpl, LOCK_EX) === false) {
        return [
            'ok' => false,
            'message' => 'Unable to create temporary ZPL file.'
        ];
    }

    $printCmd = 'cmd /c print /D:' . zebra_cmd_arg($printerName) . ' ' . zebra_cmd_arg($tempFile);
    $printOutput = [];
    $printExitCode = 0;

    exec($printCmd . ' 2>&1', $printOutput, $printExitCode);
    $printMessage = trim(implode(' ', $printOutput));

    @unlink($tempFile);

    $failedText =
        stripos($printMessage, 'unable to initialize') !== false ||
        stripos($printMessage, 'invalid') !== false ||
        stripos($printMessage, 'cannot find') !== false ||
        stripos($printMessage, 'access is denied') !== false ||
        stripos($printMessage, 'error') !== false;

    if ($printExitCode === 0 && !$failedText) {
        return [
            'ok' => true,
            'message' => 'Label sent to ' . $printerLabel . ' queue "' . $printerName . '". Output: ' . ($printMessage !== '' ? $printMessage : 'Print command completed.') . ' Bytes: ' . strlen((string)$zpl)
        ];
    }

    if ($printMessage === '') {
        $printMessage = 'No output from Windows print command.';
    }

    return [
        'ok' => false,
        'message' => 'Unable to send label to ' . $printerLabel . ' queue "' . $printerName . '". Print output: ' . $printMessage
    ];
}

function zebra_send_image_to_windows_driver($imagePath, $printerName, $printerLabel = 'printer', $paperWidthHundredths = 400, $paperHeightHundredths = 400, $fallbackPrinterName = '')
{
    $imagePath = trim((string)$imagePath);
    $printerName = trim((string)$printerName);
    $fallbackPrinterName = trim((string)$fallbackPrinterName);
    $printerLabel = trim((string)$printerLabel);

    if ($printerLabel === '') {
        $printerLabel = 'printer';
    }

    if ($imagePath === '' || !is_file($imagePath)) {
        return [
            'ok' => false,
            'message' => 'Rendered label image was not found.'
        ];
    }

    if ($printerName === '') {
        return [
            'ok' => false,
            'message' => $printerLabel . ' queue name is not configured.'
        ];
    }

    if (!function_exists('exec')) {
        return [
            'ok' => false,
            'message' => 'PHP exec() is disabled, so server-side ' . $printerLabel . ' driver printing cannot run.'
        ];
    }

    $script = realpath(__DIR__ . '/../tools/print_image_to_windows_printer.ps1');

    if ($script === false || !is_file($script)) {
        return [
            'ok' => false,
            'message' => 'Nitto Windows driver print script was not found.'
        ];
    }

    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
        . zebra_cmd_arg($script)
        . ' -ImagePath ' . zebra_cmd_arg($imagePath)
        . ' -PrinterName ' . zebra_cmd_arg($printerName)
        . ' -FallbackPrinterName ' . zebra_cmd_arg($fallbackPrinterName)
        . ' -PaperWidthHundredths ' . (int)$paperWidthHundredths
        . ' -PaperHeightHundredths ' . (int)$paperHeightHundredths;

    $startedAt = microtime(true);
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);
    $elapsedSeconds = round(microtime(true) - $startedAt, 3);

    $message = trim(implode(' ', $output));

    if ($exitCode === 0) {
        return [
            'ok' => true,
            'message' => 'Rendered label sent to ' . $printerLabel . ' through Windows driver in ' . $elapsedSeconds . 's. Output: ' . ($message !== '' ? $message : 'Print command completed.') . ' Bytes: ' . filesize($imagePath)
        ];
    }

    if ($message === '') {
        $message = 'No output from PowerShell print command.';
    }

    return [
        'ok' => false,
        'message' => 'Unable to print rendered label on ' . $printerLabel . ' after ' . $elapsedSeconds . 's. PowerShell output: ' . $message
    ];
}

function zebra_send_to_tcp_printer($zpl, $host, $port, $printerLabel = 'Zebra printer')
{
    $host = trim((string)$host);
    $port = (int)$port;
    $printerLabel = trim((string)$printerLabel);

    if ($printerLabel === '') {
        $printerLabel = 'printer';
    }

    if ($host === '' || $port <= 0) {
        return [
            'ok' => false,
            'message' => $printerLabel . ' TCP host or port is not configured.'
        ];
    }

    if (!function_exists('fsockopen')) {
        return [
            'ok' => false,
            'message' => 'PHP fsockopen() is disabled, so TCP ' . $printerLabel . ' printing cannot run.'
        ];
    }

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);

    if (!$socket) {
        return [
            'ok' => false,
            'message' => 'Unable to connect to ' . $printerLabel . ' at ' . $host . ':' . $port . '. ' . trim($errstr . ' ' . $errno)
        ];
    }

    stream_set_timeout($socket, 5);
    $payload = (string)$zpl;
    $payloadLength = strlen($payload);
    $bytes = 0;

    while ($bytes < $payloadLength) {
        $written = fwrite($socket, substr($payload, $bytes));

        if ($written === false || $written <= 0) {
            break;
        }

        $bytes += $written;
    }

    fflush($socket);
    fclose($socket);

    if ($bytes <= 0) {
        return [
            'ok' => false,
            'message' => 'Connected to ' . $printerLabel . ', but no label bytes were sent.'
        ];
    }

    if ($bytes < $payloadLength) {
        return [
            'ok' => false,
            'message' => 'Only ' . $bytes . ' of ' . $payloadLength . ' label bytes were sent to ' . $printerLabel . '.'
        ];
    }

    return [
        'ok' => true,
        'message' => 'Label sent to ' . $printerLabel . ' at ' . $host . ':' . $port . '. Bytes: ' . $bytes
    ];
}

function zebra_send_label_to_target($zpl, array $target)
{
    $connection = strtolower(trim((string)($target['connection'] ?? zebra_print_connection())));
    $printerLabel = trim((string)($target['printer_label'] ?? 'Zebra printer'));

    if ($connection === 'tcp') {
        return zebra_send_to_tcp_printer(
            $zpl,
            $target['host'] ?? zebra_printer_host(),
            $target['port'] ?? zebra_printer_port(),
            $printerLabel
        );
    }

    if ($connection === 'windows_queue') {
        return zebra_send_to_windows_queue(
            $zpl,
            $target['queue'] ?? ($target['share'] ?? zebra_printer_share()),
            $printerLabel
        );
    }

    return zebra_send_to_windows_share(
        $zpl,
        $target['share'] ?? zebra_printer_share(),
        $printerLabel
    );
}

function zebra_send_label($zpl)
{
    return zebra_send_label_to_target($zpl, [
        'connection' => zebra_print_connection(),
        'host' => zebra_printer_host(),
        'port' => zebra_printer_port(),
        'share' => zebra_printer_share(),
        'printer_label' => 'Zebra printer'
    ]);
}

function zebra_send_pick_label($zpl)
{
    return zebra_send_label_to_target($zpl, [
        'connection' => zebra_pick_print_connection(),
        'host' => zebra_pick_printer_host(),
        'port' => zebra_pick_printer_port(),
        'share' => zebra_pick_printer_share(),
        'queue' => zebra_pick_printer_queue(),
        'printer_label' => zebra_pick_printer_name() . ' picker printer'
    ]);
}

function zebra_send_pick_label_image($imagePath)
{
    return zebra_send_image_to_windows_driver(
        $imagePath,
        zebra_pick_printer_queue(),
        zebra_pick_printer_name() . ' picker printer',
        zebra_pick_width_hundredths(),
        zebra_pick_height_hundredths(),
        zebra_pick_printer_share()
    );
}

function zebra_print_receive_labels($traceNo, array $items)
{
    if (!zebra_print_enabled()) {
        return [
            'enabled' => false,
            'ok' => false,
            'printed' => 0,
            'failed' => 0,
            'messages' => [
                'Zebra auto-print is disabled.'
            ]
        ];
    }

    $printed = 0;
    $failed = 0;
    $messages = [];

    $totalItems = count($items);

    foreach ($items as $idx => $item) {
        if (empty($item['item_code']) || empty($item['quantity']) || empty($item['lot_no'])) {
            $failed++;
            $messages[] = 'Skipped receiving label with missing item, qty, or lot.';
            continue;
        }

        $zpl = zebra_receive_label_zpl($traceNo, $item);
        $result = zebra_send_label($zpl);

        if ($result['ok']) {
            $printed++;
        } else {
            $failed++;
        }

        $messages[] = $result['message'];

        $delaySeconds = zebra_label_delay_seconds();

        if ($delaySeconds > 0 && $idx < $totalItems - 1) {
            sleep($delaySeconds);
        }
    }

    return [
        'enabled' => true,
        'ok' => $failed === 0,
        'printed' => $printed,
        'failed' => $failed,
        'messages' => array_values(array_unique($messages))
    ];
}

function zebra_print_pick_labels_to_target(array $items, $usePickerPrinter, $printerKey = null)
{
    $usePickerPrinter = (bool)$usePickerPrinter;
    $printerKey = $usePickerPrinter ? zebra_pick_printer_key($printerKey) : 'zebra';
    $useNittoPicker = $usePickerPrinter && $printerKey === 'nitto';
    $printerName = $useNittoPicker ? zebra_pick_printer_name() : 'Zebra QLn320';

    if ($useNittoPicker ? !zebra_pick_print_enabled() : !zebra_print_enabled()) {
        return [
            'enabled' => false,
            'ok' => false,
            'printed' => 0,
            'failed' => 0,
            'printer_name' => $printerName,
            'printer_key' => $printerKey,
            'messages' => [
                $useNittoPicker ? 'Picker tag auto-print is disabled.' : 'Zebra auto-print is disabled.'
            ]
        ];
    }

    $printed = 0;
    $failed = 0;
    $messages = [];
    $bytesSent = 0;
    $batchBytes = 0;
    $maxLabelBytes = $useNittoPicker ? zebra_pick_max_label_bytes() : 0;
    $batchMaxBytes = $useNittoPicker ? zebra_pick_batch_max_bytes() : 0;
    $batchCooldownSeconds = $useNittoPicker ? zebra_pick_batch_cooldown_seconds() : 0;
    $useWindowsDriver = $useNittoPicker && zebra_pick_print_connection() === 'windows_driver';

    $totalItems = count($items);

    foreach ($items as $idx => $item) {
        if (empty($item['item_code']) || empty($item['quantity']) || empty($item['lot_no'])) {
            $failed++;
            $messages[] = 'Skipped pick label with missing item, qty, or lot.';
            continue;
        }

        $zpl = null;
        $imagePath = '';

        if ($useWindowsDriver) {
            $imagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nitto_picker_' . uniqid('', true) . '.png';
            $renderStartedAt = microtime(true);
            $renderResult = zebra_pick_label_png($item, $imagePath);
            $renderSeconds = round(microtime(true) - $renderStartedAt, 3);

            if (empty($renderResult['ok'])) {
                $failed++;
                $messages[] = ($renderResult['message'] ?? 'Unable to render Nitto picker label image.') . ' Render time: ' . $renderSeconds . 's.';
                continue;
            }

            $labelBytes = is_file($imagePath) ? (int)filesize($imagePath) : 0;
            $messages[] = 'Rendered Nitto picker label image in ' . $renderSeconds . 's. Bytes: ' . $labelBytes;
        } else {
            $zpl = zebra_pick_label_zpl($item);
            $labelBytes = strlen((string)$zpl);
        }

        if (!$useWindowsDriver && $maxLabelBytes > 0 && $labelBytes > $maxLabelBytes) {
            if ($imagePath !== '') {
                @unlink($imagePath);
            }

            $failed++;
            $messages[] = 'Skipped pick label because it is ' . $labelBytes . ' bytes, above the ' . $maxLabelBytes . '-byte limit.';
            continue;
        }

        if ($batchMaxBytes > 0 && $batchBytes > 0 && ($batchBytes + $labelBytes) > $batchMaxBytes) {
            if ($batchCooldownSeconds > 0) {
                sleep($batchCooldownSeconds);
            }

            $messages[] = 'Paused picker printing after ' . $batchBytes . ' bytes to avoid printer memory overflow.';
            $batchBytes = 0;
        }

        if ($useWindowsDriver) {
            $result = zebra_send_pick_label_image($imagePath);
            @unlink($imagePath);
        } else {
            $result = $useNittoPicker ? zebra_send_pick_label($zpl) : zebra_send_label($zpl);
        }

        if ($result['ok']) {
            $printed++;
            $bytesSent += $labelBytes;
            $batchBytes += $labelBytes;
        } else {
            $failed++;
        }

        $messages[] = $result['message'];

        $delaySeconds = zebra_pick_label_delay_seconds();

        if ($delaySeconds > 0 && $idx < $totalItems - 1) {
            sleep($delaySeconds);
        }
    }

    return [
        'enabled' => true,
        'ok' => $failed === 0,
        'printed' => $printed,
        'failed' => $failed,
        'printer_name' => $printerName,
        'printer_key' => $printerKey,
        'bytes_sent' => $bytesSent,
        'messages' => array_values(array_unique($messages))
    ];
}

function zebra_print_pick_labels(array $items)
{
    return zebra_print_pick_labels_to_target($items, false, 'zebra');
}

function zebra_print_picker_tags(array $items, $printerKey = null)
{
    return zebra_print_pick_labels_to_target($items, true, $printerKey);
}

function zebra_print_test_label()
{
    if (!zebra_print_enabled()) {
        return [
            'enabled' => false,
            'ok' => false,
            'message' => 'Zebra auto-print is disabled.'
        ];
    }

    return zebra_send_label(zebra_test_label_zpl());
}

?>
