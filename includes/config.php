<?php
// Copy this file to config.php and edit the values.
// Do not commit or share config.php with real passwords.

define('DB_HOST_WHP', '192.168.20.230');
define('DB_USER_WHP', 'sa');
define('DB_PASS_WHP', 'Nbc12#');
define('DB_NAME_WHP', 'WHPOKAYOKE');

define('DB_HOST_ERP', 'erpserver');
define('DB_USER_ERP', 'sa');
define('DB_PASS_ERP', '1q2w#E$R');
define('DB_NAME_ERP', 'TESTDB_NBCP_Final_Live');

define('APP_BASE_URL', 'http://localhost/rawmat_traceability_app');

// Zebra printing.
// For the Zebra QLn320 Wi-Fi printer, use direct TCP/IP raw ZPL printing.
// Printer screen shown:
//   IP Addr: 192.168.20.247
//   Port:    6101
define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'tcp');
define('ZEBRA_PRINTER_HOST', '192.168.20.247');
define('ZEBRA_PRINTER_PORT', 6101);
define('ZEBRA_LABEL_END_MODE', 'tear_off'); // tear_off for QLn320 tear bar, cut only for printers with cutter hardware.
define('ZEBRA_TEAR_OFF_DOTS', 80);
define('ZEBRA_PICK_LABEL_DELAY_SECONDS', 5); // Pause between pick tags so the picker can tear each tag off.

// Fallback for Windows shared printers:
// define('ZEBRA_PRINT_CONNECTION', 'windows_share');
// define('ZEBRA_PRINTER_SHARE', '\\\\PCNAME\\ZebraRawmat');
?>
