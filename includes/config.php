<?php
// Copy this file to config.php and edit the values.
// Do not commit or share config.php with real passwords.

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
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
| Application URL
|--------------------------------------------------------------------------
*/

define('APP_BASE_URL', 'http://localhost/rawmat_traceability_app');

/*
|--------------------------------------------------------------------------
| Zebra Receiving QR Printer
|--------------------------------------------------------------------------
| For the Zebra QLn320 Wi-Fi printer, use direct TCP/IP raw ZPL printing.
| Printer screen shown:
|   IP Addr: 192.168.20.247
|   Port:    6101
*/

define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'tcp');
define('ZEBRA_PRINTER_HOST', '192.168.20.247');
define('ZEBRA_PRINTER_PORT', 6101);

/*
| tear_off = for QLn320 tear bar
| cut      = only for printers with cutter hardware
*/
define('ZEBRA_LABEL_END_MODE', 'tear_off');
define('ZEBRA_TEAR_OFF_DOTS', 80);

/*
|--------------------------------------------------------------------------
| NITTO DURA-SL-400 Picker Tag Printer
|--------------------------------------------------------------------------
| Picker tag printing uses the Nitto DURA-SL-400 printer specifically.
| This printer uses Windows driver image printing, not raw ZPL.
*/

define('PICK_TAG_PRINT_CONNECTION', 'windows_driver');
define('PICK_TAG_DEFAULT_PRINTER', 'nitto'); // nitto or zebra
define('PICK_TAG_PRINTER_NAME', 'NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_QUEUE', '\\\\Nbcp-lt-042\\NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_SHARE', '\\\\Nbcp-lt-042\\NITTO DURA-SL-400');

define('PICK_TAG_WIDTH_HUNDREDTHS', 300);  // 3.00 inches
define('PICK_TAG_HEIGHT_HUNDREDTHS', 300); // 3.00 inches
define('PICK_TAG_IMAGE_SCALE', 1);         // 1 = 609px/203 DPI, 2 = 1218px/high-res
define('PICK_TAG_IMAGE_PNG_COMPRESSION', 6);

/*
| Pause between pick tags so the picker can tear each tag off.
*/
define('PICK_TAG_LABEL_DELAY_SECONDS', 0);

/*
|--------------------------------------------------------------------------
| IMPORTANT FOR NITTO WINDOWS DRIVER PRINTING
|--------------------------------------------------------------------------
| For PICK_TAG_PRINT_CONNECTION = windows_driver, the label is generated as PNG.
| PNG/image labels are normally much larger than raw ZPL labels.
|
| Your error was:
|   1982115 bytes, above the 32768-byte limit
|
| So these must be 0 to avoid skipping the NITTO image label.
| 0 = no byte limit.
*/

define('PICK_TAG_MAX_LABEL_BYTES', 0);
define('PICK_TAG_BATCH_MAX_BYTES', 0);
define('PICK_TAG_BATCH_COOLDOWN_SECONDS', 0);

/*
|--------------------------------------------------------------------------
| Fallback for Windows Shared Printers
|--------------------------------------------------------------------------
| Use this only if you switch back to windows_share printing.
|--------------------------------------------------------------------------
|
| define('ZEBRA_PRINT_CONNECTION', 'windows_share');
| define('ZEBRA_PRINTER_SHARE', '\\\\PCNAME\\ZebraRawmat');
|
*/

?>
