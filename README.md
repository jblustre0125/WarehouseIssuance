# NBC Rawmat Traceability App

This is a read-only SAP B1 traceability layer for raw material issuance and receiving.

## What it does

- Reads SAP B1 item master `OITM` only for item validation and part name lookup.
- Saves actual warehouse issuance lot scans in your own WHPOKAYOKE database.
- Generates a `TraceNo` and printable QR after issuance.
- Production scans the Trace QR and confirms actual received lot and quantity.
- Dashboard compares issued lot/qty vs received lot/qty.

## Folder Structure

- `index.php` - root entry point and role-based redirect.
- `includes/` - shared auth, config, database connection, and printer helpers.
- `pages/` - user-facing pages grouped by role or purpose.
- `actions/` - form submit/write handlers.
- `api/` - JSON endpoints used by the pages.
- `database/` - schema scripts.
- `image/` - current image assets.
- `tools/` - local diagnostics.

Key routes:

- `pages/auth/login.php` - login page.
- `pages/requestor/requestor.php` - requestor issue request page.
- `pages/picker/picker.php` - warehouse picker pick-tag printing page.
- `pages/issuer/issuer.php` - warehouse issuer page.
- `pages/dashboard/verification_dashboard.php` - dashboard.
- `pages/reports/view_transactions.php` - raw issue/receive transaction logs.
- `pages/admin/admin_users.php` - user management.

## Installation

1. Copy folder to your PHP/IIS/Apache web root.
2. Edit `includes/config.php` with your WHPOKAYOKE DB and SAP B1 read-only DB credentials.
3. Run `database/schema.sql` on the WHPOKAYOKE database.
4. Open `tools/test_db_connections.php` to verify DB access.
6. Login using:

```text
Username: admin
Password: admin123
```

7. Change the admin password immediately.
8. Create picker, issuer, and requestor users.

## Recommended process

1. Requestor creates an issue request.
2. Warehouse picker loads the request, finds the actual lot number, and prints pick barcode tags.
3. Warehouse issuer loads the request and scans the picker barcode payload: `(01)SAP ItemCode(17)Qty(10)Lot No`.
4. System saves issuance and closes or partially closes the app request.
5. Receiving is completed through SAP IT/ITR.
6. Requestor can request the remaining SAP ITR open quantity again when balance remains.
7. Management checks the dashboard.

## Label auto-printing

For Android tablets using the new WebView app, see [NBC Warehouse tablet app](android-webview/README.md). It prints directly from the tablet to a paired QLn320 over Bluetooth and includes a warehouse app logo. The following TCP and Windows Bluetooth settings apply to printers connected to the XAMPP server.

For the Zebra QLn320 Wi-Fi printer, send raw ZPL directly to the printer IP and port shown on the printer screen.

In `config.php`:

```php
define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'tcp');
define('ZEBRA_PRINTER_HOST', '192.168.20.247');
define('ZEBRA_PRINTER_PORT', 6101);
```

The XAMPP server must be able to reach the printer over the network.

For Bluetooth, pair the Bluetooth-enabled QLn320 with the **Windows computer running XAMPP**. In Windows Bluetooth settings, find the printer's **outgoing Serial Port Profile (SPP) COM port** (for example `COM10`). Zebra documents this pairing method in its [Windows Bluetooth setup guidance](https://support.zebra.com/article/000014632).

Update the existing settings in `includes/config.php`:

```php
define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'bluetooth');
define('ZEBRA_BLUETOOTH_COM_PORT', 'COM10'); // Replace with your outgoing port
define('ZEBRA_BLUETOOTH_BAUD_RATE', 115200);
define('ZEBRA_BLUETOOTH_TIMEOUT_SECONDS', 20);
```

Select **Zebra QLn320** in the picker or issuer screen as usual. Receiving, picker, issuer, and Zebra test labels use this connection setting. Bluetooth sends the same raw ZPL and label layout. Wi-Fi remains available by setting `ZEBRA_PRINT_CONNECTION` back to `tcp`.

Bluetooth requires Windows PowerShell and PHP `proc_open()`. The printer must be powered on, in range, and paired for the Windows account running XAMPP or its print worker. A printer paired to a browser user's phone or a different PC is not available to the server. If the port is missing or busy, check Windows pairing and close other programs using it. A successful send confirms the bytes were handed to the serial connection; verify the physical label. After a timeout or transmission error, check for a partial label before retrying; the app does not automatically resend Bluetooth labels.

Picker tags can be sent to either the Nitto DURA-SL-400 or the Zebra QLn320 from the picker screen.
The default selected picker-tag printer is controlled by:

```php
define('PICK_TAG_DEFAULT_PRINTER', 'nitto'); // nitto or zebra
```

Nitto picker tags are configured separately:

```php
define('PICK_TAG_PRINT_CONNECTION', 'windows_driver');
define('PICK_TAG_DEFAULT_PRINTER', 'nitto');
define('PICK_TAG_PRINTER_NAME', 'NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_SHARE', 'NITTO DURA-SL-400');
define('PICK_TAG_WIDTH_HUNDREDTHS', 300);
define('PICK_TAG_HEIGHT_HUNDREDTHS', 300);
define('PICK_TAG_LABEL_DELAY_SECONDS', 2);
define('PICK_TAG_MAX_LABEL_BYTES', 32768);
define('PICK_TAG_BATCH_MAX_BYTES', 131072);
define('PICK_TAG_BATCH_COOLDOWN_SECONDS', 2);
```

When Nitto is selected, the picker-tag setup renders each 3 inch by 3 inch tag as a PNG and prints it through the installed Windows printer driver named `NITTO DURA-SL-400`. When Zebra QLn320 is selected, the same picker tag data is printed as raw ZPL through the normal `ZEBRA_PRINT_CONNECTION` settings. Picker printing sends one label at a time and pauses after the configured byte budget to avoid overflowing printer memory.

If you need to test raw label command printing, `PICK_TAG_PRINT_CONNECTION` can be set to `windows_share`, but that requires a shared printer path such as `\\localhost\NITTO DURA-SL-400` and only works when the printer understands the raw label command language.

By default, `actions/print_pick_tags.php` starts `tools/run_picker_print_queue_hidden.vbs` directly after queueing a job, so Nitto and Zebra jobs print automatically under the same Windows user running XAMPP. You can still create this scheduled task as a fallback:

- Task name: `Warehouse Picker Print Queue`
- Program/script: `wscript.exe`
- Arguments: `"C:\Xampp\htdocs\WarehouseIssuance\tools\run_picker_print_queue_hidden.vbs"`
- Start in: `C:\Xampp\htdocs\WarehouseIssuance`

Run the fallback task as a Windows user that can print to the configured Nitto printer. If a command window appears, the task is probably running `php.exe` directly; switch it to the `wscript.exe` launcher above. Print results and errors are written to `storage/print_done`, `storage/print_errors`, and `storage/print_logs`.

For a USB/Windows shared Zebra printer, use:

```php
define('ZEBRA_PRINT_CONNECTION', 'windows_share');
define('ZEBRA_PRINTER_SHARE', 'ZebraRawmat');
```

## Status meanings

- `ISSUED` - Issued by warehouse; receiving is handled through SAP IT/ITR.
- `PARTIAL` - App request still has remaining quantity to issue.
- `CANCELLED` - Request or line was cancelled.

## Notes

- This app does not modify SAP B1.
- SAP-heavy read endpoints cache their JSON result in WHPOKAYOKE `dbo.SapDataCache`.
- Add `refresh=1` to a SAP-backed API URL to bypass the cache and refresh it from SAP.
- Do not put real DB credentials in shared files.
- QR generation uses a CDN JavaScript library. If your intranet has no internet, download `qrcode.min.js` and change the script reference in `print_trace.php`.

## Background SAP cache sync

After running `database/schema.sql`, test the cache refresher from Command Prompt or PowerShell:

```bat
C:\Xampp\php\php.exe C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php
```

Then create a Windows Task Scheduler task:

- Program/script: `C:\Xampp\php\php.exe`
- Arguments: `C:\Xampp\htdocs\WarehouseIssuance\tools\sync_sap_cache.php`
- Start in: `C:\Xampp\htdocs\WarehouseIssuance`
- Trigger: every 2 to 5 minutes

The script refreshes SAP-backed caches in WHPOKAYOKE before users open the pages. Results are logged in `dbo.SapCacheSyncLog`.

To run both SAP cache sync and ScanPlus dashboard cache sync in one hidden scheduled task:

- Program/script: `wscript.exe`
- Arguments: `"C:\Xampp\htdocs\WarehouseIssuance\tools\run_all_cache_hidden.vbs"`
- Start in: `C:\Xampp\htdocs\WarehouseIssuance`
