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

For the Zebra QLn320 Wi-Fi printer, send raw ZPL directly to the printer IP and port shown on the printer screen.

In `config.php`:

```php
define('ZEBRA_PRINT_ENABLED', true);
define('ZEBRA_PRINT_CONNECTION', 'tcp');
define('ZEBRA_PRINTER_HOST', '192.168.20.247');
define('ZEBRA_PRINTER_PORT', 6101);
```

The XAMPP server must be able to reach the printer over the network.

Picker tags are configured separately for the Nitto DURA-SL-400 printer:

```php
define('PICK_TAG_PRINT_CONNECTION', 'windows_driver');
define('PICK_TAG_PRINTER_NAME', 'NITTO DURA-SL-400');
define('PICK_TAG_PRINTER_SHARE', 'NITTO DURA-SL-400');
define('PICK_TAG_WIDTH_HUNDREDTHS', 300);
define('PICK_TAG_HEIGHT_HUNDREDTHS', 300);
define('PICK_TAG_LABEL_DELAY_SECONDS', 2);
define('PICK_TAG_MAX_LABEL_BYTES', 32768);
define('PICK_TAG_BATCH_MAX_BYTES', 131072);
define('PICK_TAG_BATCH_COOLDOWN_SECONDS', 2);
```

The picker-tag setup renders each 3 inch by 3 inch Nitto picker tag as a PNG and prints it through the installed Windows printer driver named `NITTO DURA-SL-400`. This applies only to picker tags. Picker printing sends one label at a time and pauses after the configured byte budget to avoid overflowing printer memory.

If you need to test raw label command printing, `PICK_TAG_PRINT_CONNECTION` can be set to `windows_share`, but that requires a shared printer path such as `\\localhost\NITTO DURA-SL-400` and only works when the printer understands the raw label command language.

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
