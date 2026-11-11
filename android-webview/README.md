# NBC Warehouse tablet app 2.0

An Android 8.0+ WebView app for the WarehouseIssuance system, with a warehouse-and-cartons logo in its header and launcher icon. Back, Reload, Printer, and Server are available from the floating menu button at the bottom right. This is a new standalone project; the empty legacy `app/build` folders are not used.

## Install and configure

1. Install `dist/NBC-Warehouse-2.0.0.apk` on the tablets. This is a debug-signed testing build with a new package ID, `com.nbcp.warehouse.tablet`, so it can be installed beside the old WebView app.
2. On first launch, save the production address: `http://192.168.23.64/WarehouseIssuance/`.
3. For workstation testing, open the floating menu and tap **Server → Testing**, enter the workstation's full network URL (for example `http://WORKSTATION-IP/WarehouseIssuance/`), and save. The app remembers separate production and testing addresses. `localhost` points to the tablet and is rejected.
4. Pair the Bluetooth-enabled Zebra QLn320 in the tablet's Android Bluetooth settings.
5. Open the floating menu and tap **Printer → Select tablet Bluetooth printer**, allow Nearby devices permission when requested, and select the paired QLn320. Repeat on each tablet.
6. Log in as usual, select **Zebra QLn320** in the picker or issuer page, and print. Issuer single-label, batch, and reprint actions are supported.

**Printer → Use server / Wi-Fi printer** restores the existing server connection. Nitto printing continues through the server. The server's `ZEBRA_PRINT_CONNECTION` does not need to change for tablet Bluetooth.

Deploy the accompanying website changes before using the app: `assets/tablet-printer.js`, `includes/tablet_print.php`, `actions/print_pick_tags.php`, `api/issuer/print_issue_tags.php`, and both picker and issuer pages. Without these changes, the server cannot hand label data back to the tablet. This app does not replace server-triggered receiving auto-printing.

## Printing behavior

The same authenticated website endpoints validate the items and generate the existing ZPL layouts. Tablet Bluetooth requests return label bytes without launching a Windows printer or print worker. The app sends those bytes directly over Bluetooth Classic SPP on the tablet, one batch at a time, with the configured delay between labels. Up to 100 labels can be submitted per batch.

Connection and writes have timeouts. Errors report how many labels were sent; neither the app nor its JavaScript bridge automatically retries a batch or falls back to another printer. Check for complete or partial labels before retrying. A successful transmission confirms bytes were sent, not a physical paper or printer-status acknowledgement. Physical pairing and label output must be verified with a QLn320.

Keep Android System WebView updated. The bridge uses AndroidX WebKit's origin-restricted message listener and accepts main-frame requests only from the configured warehouse origin and app path. External links open outside the app; file access, third-party cookies, backups, and mixed content are disabled. TLS certificate errors are not bypassed. HTTP is enabled for the supplied intranet URL; HTTPS is recommended when the server supports it.

## Build and verify

Open this folder in Android Studio, or use JDK 17+ with Android SDK 35:

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

The automated checks cover URL restrictions, UTF-8 label bytes and limits, the existing label layouts, browser/Nitto/server compatibility, permission denial, server validation, and partial transmission failures without automatic retry.
