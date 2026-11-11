# NBC Warehouse tablet app 2.0.1

An Android 8.0+ WebView app for the WarehouseIssuance system, with a warehouse-and-cartons launcher icon. The app header is removed. Back, Reload, Printer, and Server are available from the floating menu button at the bottom right. This is a new standalone project; the empty legacy `app/build` folders are not used.

## Install and configure

1. Install `dist/NBC-Warehouse-2.0.1.apk` on the tablets. This debug-signed testing build updates version 2.0.0 with the same package ID, `com.nbcp.warehouse.tablet`, and preserves its server and printer preferences.
2. On first launch, save the production address: `http://192.168.23.64/WarehouseIssuance/`.
3. For workstation testing, open the floating menu and tap **Server → Testing**, enter the workstation's full network URL (for example `http://WORKSTATION-IP/WarehouseIssuance/`), and save. The app remembers separate production and testing addresses. `localhost` points to the tablet and is rejected.
4. Pair the Bluetooth-enabled Zebra QLn320 in the tablet's Android Bluetooth settings.
5. Open the floating menu and tap **Printer → Select tablet Bluetooth printer**, allow Nearby devices permission when requested, and select the paired QLn320. Repeat on each tablet.
6. Log in as usual, select **Zebra QLn320** in the picker or issuer page, and print. Issuer single-label, batch, and reprint actions are supported.

**Printer → Use server / Wi-Fi printer** restores the existing server connection. Nitto printing continues through the server. The server's `ZEBRA_PRINT_CONNECTION` does not need to change for tablet Bluetooth.

Deploy the accompanying website update ZIP before using the app: `assets/tablet-printer.js`, `includes/tablet_print.php`, `actions/print_pick_tags.php`, `api/issuer/print_issue_tags.php`, `api/tablet_print_capabilities.php`, and both picker and issuer pages. Without these changes, the server cannot hand label data back to the tablet. Version 2.0.1 checks server compatibility before submitting any Bluetooth print job. This app does not replace server-triggered receiving auto-printing.

## Printing behavior

The same authenticated website endpoints validate the items and generate the existing ZPL layouts. Tablet Bluetooth requests return label bytes without launching a Windows printer or print worker. The app sends those bytes directly over Bluetooth Classic SPP on the tablet, one batch at a time, with the configured delay between labels. Up to 100 labels can be submitted per batch.

The app excludes BLE-only devices and barcode scanners from printer selection. Before sending labels, it reads the printer model, verifies QLn320/ZPL support, checks printer status, and requires an available printed-label counter. Each label is counted as printed only after the printer counter advances by one and its status is idle. Paper-out, open cover, pause, missing replies, and unconfirmed transmission are failures. This follows [Zebra's status and odometer guidance](https://techdocs.zebra.com/link-os/2-12/bestpractices/content/index.html).

Connection, writes, and confirmation have timeouts. Errors report how many labels were confirmed; neither the app nor its JavaScript bridge automatically retries a batch or falls back to another printer. Check for complete or partial labels before retrying. Confirmation verifies printer-reported activity; inspect the label for print quality and correct content.

Use floating menu **Printer > Check selected printer** for a read-only connection/status check. **Print one test label** prints a single diagnostic label without saving an issuance or requiring SAP item data. The QLn320 must be powered on and paired with each actual warehouse tablet; pairing a barcode scanner is insufficient. Printer language settings are not changed automatically.

The Android app bundles the printing client from the repository's `assets/tablet-printer.js` and injects it at document start for the configured origin. This also intercepts print calls from cached older pages. An old server or app cannot be used for verified Bluetooth printing; the user gets an error before any job submission.

Keep Android System WebView updated. The bridge uses AndroidX WebKit's origin-restricted message listener and accepts main-frame requests only from the configured warehouse origin and app path. External links open outside the app; file access, third-party cookies, backups, and mixed content are disabled. TLS certificate errors are not bypassed. HTTP is enabled for the supplied intranet URL; HTTPS is recommended when the server supports it.

## Build and verify

Open this folder within the repository in Android Studio, or use JDK 17+ with Android SDK 35. The build copies the shared printing client from `../assets/tablet-printer.js`:

```powershell
$env:ANDROID_HOME = 'C:\Users\YOUR-USER\AppData\Local\Android\Sdk'
.\gradlew.bat :app:assembleDebug :app:lintDebug :app:testDebugUnitTest --no-problems-report
```

The APK is generated in `app/build/outputs/apk/debug/app-debug.apk`. Use your organization's signing key for a production release; no private signing key is included in this project. Future updates must retain this package ID and use the same signing key as the installed version.

From the PHP repository root:

```powershell
php tools/test_tablet_labels.php
node tools/test_tablet_printer.cjs
```

The automated checks cover URL restrictions, label bytes/limits/layouts, browser/Nitto/server compatibility, permission denial, outdated servers/apps, cached client scripts, partial transmissions, printer identity/language/status, and printed-counter verification without automatic retry. A simulated successful write with no counter advance must fail.
