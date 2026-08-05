<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
| Keep real passwords outside source control. Set the environment variables
| on the server, or replace CHANGE_ME only in the private local config.php.
*/

define('DB_HOST_WHP', '192.168.20.230');
define('DB_USER_WHP', 'sa');
define('DB_PASS_WHP', 'Nbc12#');
define('DB_NAME_WHP', 'WHPOKAYOKE');

define('DB_HOST_ERP', 'erpserver');
define('DB_USER_ERP', 'sa');
define('DB_PASS_ERP', '1q2w#E$R');
define('DB_NAME_ERP', 'NBCP_Final_Live');

/*
|--------------------------------------------------------------------------
| SAP Query Load Control
|--------------------------------------------------------------------------
*/

define('SAP_BROWSER_LIVE_QUERIES_ENABLED', false);
define('SAP_BROWSER_MANUAL_REFRESH_ENABLED', false);
define('SAP_CACHE_ALLOW_STALE_READS', true);
define('SAP_CACHE_MAX_STALE_SECONDS', 600);
define('SAP_CACHE_LOCK_STALE_SECONDS', 900);


define('APP_BASE_URL', 'http://localhost/rawmat_traceability_app');

/*
|--------------------------------------------------------------------------
| Zebra QLn320 Receiving / Picker Printer
|--------------------------------------------------------------------------
| The application sends raw ZPL directly over TCP. Windows printer-driver
| paper settings do not control these jobs.
*/

define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'tcp');
define('ZEBRA_PRINTER_HOST', '192.168.20.174');
define('ZEBRA_PRINTER_PORT', 6101);

/*
|--------------------------------------------------------------------------
| Zebra 3 x 3-inch Label
|--------------------------------------------------------------------------
| QLn320 resolution: 203 DPI.
| 3 inches x 203 dots/inch is approximately 609 dots.
| The safe printable width is 576 dots.
*/

define('ZEBRA_PRINT_DPI', 203);
define('ZEBRA_MEDIA_WIDTH_DOTS', 609);
define('ZEBRA_MEDIA_HEIGHT_DOTS', 609);
define('ZEBRA_LABEL_WIDTH_DOTS', 576);
define('ZEBRA_LABEL_HEIGHT_DOTS', 609);

/*
|--------------------------------------------------------------------------
| Zebra Media Mode
|--------------------------------------------------------------------------
| Use continuous for the journal-style roll shown in the photo. The PHP
| helper will send ^MNN and ^LL609 for every raw TCP label.
|
| Change to mark only when a valid black registration mark is present at
| every label boundary and is reliably detected by the printer.
*/

define('ZEBRA_MEDIA_TRACKING', 'continuous');
define('ZEBRA_USE_PRINTER_CALIBRATION', false);
define('ZEBRA_FORCE_LABEL_LENGTH', true);
define('ZEBRA_BLACK_MARK_OFFSET_DOTS', 0);

/*
|--------------------------------------------------------------------------
| Zebra Safe Content and Positioning
|--------------------------------------------------------------------------
*/

define('ZEBRA_CONTENT_WIDTH_DOTS', 552);
define('ZEBRA_CONTENT_HEIGHT_DOTS', 580);
define('ZEBRA_LABEL_LEFT_DOTS', 8);
define('ZEBRA_LABEL_TOP_DOTS', 12); // About 1.5 mm top space at 203 DPI

/*
|--------------------------------------------------------------------------
| Zebra Print Handling
|--------------------------------------------------------------------------
*/

define('ZEBRA_LABEL_END_MODE', 'tear_off');
define('ZEBRA_TEAR_OFF_DOTS', 0);
define('ZEBRA_PRINT_ORIENTATION', 'normal');
define('ZEBRA_PRINT_SPEED', 3);
define('ZEBRA_DARKNESS', 15);

/*
|--------------------------------------------------------------------------
| Zebra TCP Settings
|--------------------------------------------------------------------------
*/

define('ZEBRA_CONNECT_TIMEOUT_SECONDS', 5);
define('ZEBRA_WRITE_TIMEOUT_SECONDS', 15);
define('ZEBRA_PRINT_RETRY_COUNT', 2);
define('ZEBRA_PRINT_RETRY_DELAY_SECONDS', 1);

/*
|--------------------------------------------------------------------------
| NITTO DURA-SL-400 Picker Tag Printer
|--------------------------------------------------------------------------
*/

define('PICK_TAG_PRINT_CONNECTION', 'windows_driver');
define('PICK_TAG_DEFAULT_PRINTER', 'nitto'); // nitto or zebra

define('PICK_TAG_PRINTER_NAME', 'NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_QUEUE', '\\\\Nbcp-lt-042\\NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_SHARE', '\\\\Nbcp-lt-042\\NITTO DURA-SL-400');

define('PICK_TAG_WIDTH_HUNDREDTHS', 300);
define('PICK_TAG_HEIGHT_HUNDREDTHS', 300);
define('PICK_TAG_IMAGE_SCALE', 1);
define('PICK_TAG_IMAGE_PNG_COMPRESSION', 6);

define('PICK_TAG_DRIVER_RELAY_ENABLED', false);
define('PICK_TAG_DRIVER_RELAY_INBOX', '\\\\Nbcp-lt-042\\NittoPrintRelay\\inbox');
define('PICK_TAG_DRIVER_RELAY_PRINTER', 'NITTO DURA-SL-400');

define('PICK_TAG_LABEL_DELAY_SECONDS', 0);
define('PICK_TAG_MAX_LABEL_BYTES', 0);
define('PICK_TAG_BATCH_MAX_BYTES', 0);
define('PICK_TAG_BATCH_COOLDOWN_SECONDS', 0);

/*
|--------------------------------------------------------------------------
| Configuration Validation
|--------------------------------------------------------------------------
*/

if (ZEBRA_PRINT_ENABLED) {
    if (ZEBRA_PRINT_CONNECTION === 'tcp' && trim(ZEBRA_PRINTER_HOST) === '') {
        throw new RuntimeException('Zebra printer host is not configured.');
    }

    if (ZEBRA_PRINTER_PORT < 1 || ZEBRA_PRINTER_PORT > 65535) {
        throw new RuntimeException('Invalid Zebra printer port.');
    }

    if (ZEBRA_LABEL_WIDTH_DOTS <= 0 || ZEBRA_LABEL_HEIGHT_DOTS <= 0) {
        throw new RuntimeException('Invalid Zebra label dimensions.');
    }

    if (!in_array(ZEBRA_MEDIA_TRACKING, ['continuous', 'mark', 'web', 'auto'], true)) {
        throw new RuntimeException('Invalid Zebra media-tracking mode.');
    }
}