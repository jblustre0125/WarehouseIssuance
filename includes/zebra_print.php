<?php

function zebra_print_enabled()
{
    return defined('ZEBRA_PRINT_ENABLED') && ZEBRA_PRINT_ENABLED;
}

function zebra_printer_share()
{
    return defined('ZEBRA_PRINTER_SHARE') ? trim((string)ZEBRA_PRINTER_SHARE) : '';
}

function zebra_print_connection()
{
    return defined('ZEBRA_PRINT_CONNECTION') ? strtolower(trim((string)ZEBRA_PRINT_CONNECTION)) : 'windows_share';
}

function zebra_printer_host()
{
    return defined('ZEBRA_PRINTER_HOST') ? trim((string)ZEBRA_PRINTER_HOST) : '';
}

function zebra_printer_port()
{
    return defined('ZEBRA_PRINTER_PORT') ? (int)ZEBRA_PRINTER_PORT : 9100;
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
    if (defined('ZEBRA_PICK_LABEL_DELAY_SECONDS')) {
        return max(0, (int)ZEBRA_PICK_LABEL_DELAY_SECONDS);
    }

    return zebra_label_delay_seconds();
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

function zebra_send_to_windows_share($zpl, $printerShare)
{
    if ($printerShare === '') {
        return [
            'ok' => false,
            'message' => 'Zebra printer share is not configured.'
        ];
    }

    if (!function_exists('exec')) {
        return [
            'ok' => false,
            'message' => 'PHP exec() is disabled, so server-side Zebra printing cannot run.'
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
            'message' => 'Label sent to Zebra printer. Output: ' . $copyMessage . ' Target: ' . $target
        ];
    }

    if ($copyMessage === '') {
        $copyMessage = 'No output from Windows copy command.';
    }

    return [
        'ok' => false,
        'message' => 'Unable to send label to Zebra printer. Copy output: ' . $copyMessage . ' Target: ' . $target
    ];
}

function zebra_send_to_tcp_printer($zpl, $host, $port)
{
    $host = trim((string)$host);
    $port = (int)$port;

    if ($host === '' || $port <= 0) {
        return [
            'ok' => false,
            'message' => 'Zebra TCP printer host or port is not configured.'
        ];
    }

    if (!function_exists('fsockopen')) {
        return [
            'ok' => false,
            'message' => 'PHP fsockopen() is disabled, so TCP Zebra printing cannot run.'
        ];
    }

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);

    if (!$socket) {
        return [
            'ok' => false,
            'message' => 'Unable to connect to Zebra printer at ' . $host . ':' . $port . '. ' . trim($errstr . ' ' . $errno)
        ];
    }

    stream_set_timeout($socket, 5);
    $bytes = fwrite($socket, (string)$zpl);
    fflush($socket);
    fclose($socket);

    if ($bytes === false || $bytes <= 0) {
        return [
            'ok' => false,
            'message' => 'Connected to Zebra printer, but no ZPL bytes were sent.'
        ];
    }

    return [
        'ok' => true,
        'message' => 'Label sent to Zebra printer at ' . $host . ':' . $port . '. Bytes: ' . $bytes
    ];
}

function zebra_send_label($zpl)
{
    if (zebra_print_connection() === 'tcp') {
        return zebra_send_to_tcp_printer(
            $zpl,
            zebra_printer_host(),
            zebra_printer_port()
        );
    }

    return zebra_send_to_windows_share(
        $zpl,
        zebra_printer_share()
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

function zebra_print_pick_labels(array $items)
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
            $messages[] = 'Skipped pick label with missing item, qty, or lot.';
            continue;
        }

        $zpl = zebra_pick_label_zpl($item);
        $result = zebra_send_label($zpl);

        if ($result['ok']) {
            $printed++;
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
        'messages' => array_values(array_unique($messages))
    ];
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
