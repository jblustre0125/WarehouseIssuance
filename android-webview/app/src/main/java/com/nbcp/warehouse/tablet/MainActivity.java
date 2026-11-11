package com.nbcp.warehouse.tablet;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.AlertDialog;
import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothClass;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothManager;
import android.bluetooth.BluetoothSocket;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.Gravity;
import android.view.View;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageButton;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.PopupMenu;
import android.widget.TextView;
import android.widget.Toast;

import androidx.webkit.JavaScriptReplyProxy;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.ByteArrayOutputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

public final class MainActivity extends Activity {
    private static final String PRODUCTION_URL = "http://192.168.23.64/WarehouseIssuance/";
    private static final UUID SPP = UUID.fromString("00001101-0000-1000-8000-00805f9b34fb");
    private static final int CONNECT_PERMISSION = 41;
    private final ExecutorService printerWorker = Executors.newSingleThreadExecutor();
    private final ScheduledExecutorService watchdog = Executors.newSingleThreadScheduledExecutor();
    private final AtomicBoolean printing = new AtomicBoolean(false);
    private volatile BluetoothSocket activeSocket;
    private volatile boolean destroyed;
    private SharedPreferences prefs;
    private ServerAddress server;
    private WebView web;
    private ImageButton menu;
    private TextView offline;
    private ProgressBar progress;
    private LinearLayout root;
    private Runnable permissionGranted;
    private Runnable permissionDenied;
    private boolean preparing;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        prefs = getSharedPreferences("warehouse", MODE_PRIVATE);
        createLayout();
        String savedUrl = prefs.getString("server_url", "");
        if (savedUrl.isEmpty()) {
            offline.setText(R.string.welcome);
            offline.setVisibility(View.VISIBLE);
            showServerSettings();
        } else {
            try {
                server = new ServerAddress(savedUrl);
                if (configureWebView()) web.loadUrl(server.baseUrl);
            } catch (IllegalArgumentException error) {
                showServerSettings();
            }
        }
        refreshConnection();
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private Button button(String label, Runnable action) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextSize(13);
        button.setAllCaps(false);
        button.setMinHeight(dp(48));
        button.setOnClickListener(view -> action.run());
        return button;
    }

    private void createLayout() {
        FrameLayout screen = new FrameLayout(this);
        screen.setBackgroundColor(Color.rgb(23, 51, 79));
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.rgb(23, 51, 79));
        screen.setOnApplyWindowInsetsListener((view, insets) -> {
            view.setPadding(insets.getSystemWindowInsetLeft(), insets.getSystemWindowInsetTop(),
                    insets.getSystemWindowInsetRight(), insets.getSystemWindowInsetBottom());
            return insets;
        });
        screen.addView(root, new FrameLayout.LayoutParams(-1, -1));
        setContentView(screen);

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        root.addView(progress, new LinearLayout.LayoutParams(-1, dp(3)));
        offline = new TextView(this);
        offline.setBackgroundColor(Color.rgb(243, 246, 250));
        offline.setGravity(Gravity.CENTER);
        offline.setPadding(dp(24), dp(24), dp(24), dp(24));
        offline.setTextSize(17);
        offline.setVisibility(View.GONE);
        root.addView(offline, new LinearLayout.LayoutParams(-1, 0, 1));
        web = new WebView(this);
        web.setBackgroundColor(Color.WHITE);
        root.addView(web, new LinearLayout.LayoutParams(-1, 0, 1));

        menu = new ImageButton(this);
        menu.setImageResource(R.drawable.ic_menu);
        menu.setBackgroundResource(R.drawable.menu_background);
        menu.setPadding(dp(16), dp(16), dp(16), dp(16));
        menu.setContentDescription("Warehouse menu");
        menu.setElevation(dp(8));
        FrameLayout.LayoutParams menuPosition = new FrameLayout.LayoutParams(dp(56), dp(56), Gravity.BOTTOM | Gravity.END);
        menuPosition.setMargins(dp(18), dp(18), dp(18), dp(18));
        screen.addView(menu, menuPosition);
        menu.setOnClickListener(view -> {
            PopupMenu choices = new PopupMenu(this, menu, Gravity.END);
            choices.getMenu().add(0, 1, 0, "Back");
            choices.getMenu().add(0, 2, 1, "Reload");
            choices.getMenu().add(0, 3, 2, "Printer");
            choices.getMenu().add(0, 4, 3, "Server");
            choices.setOnMenuItemClickListener(item -> {
                switch (item.getItemId()) {
                    case 1: goBack(); break;
                    case 2:
                        if (printing.get()) toast("Wait for printing to finish.");
                        else if (server != null) web.reload();
                        break;
                    case 3: showPrinterMenu(); break;
                    case 4: showServerSettings(); break;
                    default: return false;
                }
                return true;
            });
            choices.show();
        });
    }

    @SuppressLint("SetJavaScriptEnabled")
    private boolean configureWebView() {
        WebSettings settings = web.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setUserAgentString(settings.getUserAgentString() + " NBCWarehouse/2.0.1");
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);

        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)
                && WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            WebViewCompat.addWebMessageListener(web, "WarehouseTablet", Collections.singleton(server.origin),
                    (view, message, sourceOrigin, isMainFrame, reply) -> {
                        if (!isMainFrame || !server.allows(view.getUrl())) return;
                        handleBridge(message.getData(), reply);
                    });
            try (java.io.InputStream script = getAssets().open("tablet-printer.js")) {
                ByteArrayOutputStream bytes = new ByteArrayOutputStream();
                byte[] buffer = new byte[4096];
                int read;
                while ((read = script.read(buffer)) != -1) bytes.write(buffer, 0, read);
                WebViewCompat.addDocumentStartJavaScript(web, new String(bytes.toByteArray(), StandardCharsets.UTF_8), Collections.singleton(server.origin));
            } catch (IOException error) {
                toast("The tablet printing client could not be loaded. Reinstall the app.");
                return false;
            }
        } else {
            offline.setText(R.string.update_webview);
            offline.setVisibility(View.VISIBLE);
            web.setVisibility(View.GONE);
            return false;
        }
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int value) {
                progress.setProgress(value);
                progress.setVisibility(value < 100 ? View.VISIBLE : View.GONE);
            }
        });
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (printing.get()) { toast("Wait for printing to finish."); return true; }
                String url = request.getUrl().toString();
                if (server.allows(url)) return false;
                if (request.isForMainFrame() && request.hasGesture()
                        && (url.startsWith("https://") || url.startsWith("http://"))) {
                    try { startActivity(new Intent(Intent.ACTION_VIEW, request.getUrl())); }
                    catch (Exception ignored) { toast("No browser is available for this link."); }
                }
                return true;
            }
            @Override public void onPageStarted(WebView view, String url, android.graphics.Bitmap icon) {
                if (!server.allows(url)) { view.stopLoading(); return; }
                offline.setVisibility(View.GONE);
                web.setVisibility(View.VISIBLE);
            }
            @Override public void onPageFinished(WebView view, String url) {
                CookieManager.getInstance().flush();
            }
            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (!request.isForMainFrame()) return;
                offline.setText(R.string.offline);
                offline.setVisibility(View.VISIBLE);
                web.setVisibility(View.GONE);
                progress.setVisibility(View.GONE);
            }
        });
        return true;
    }

    private void showServerSettings() {
        if (printing.get()) { toast("Wait for printing to finish."); return; }
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(24), dp(8), dp(24), 0);
        TextView help = new TextView(this);
        help.setText(R.string.server_help);
        form.addView(help);
        EditText input = new EditText(this);
        input.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_URI);
        input.setSingleLine(true);
        input.setHint("http://SERVER-IP/WarehouseIssuance/");
        input.setText(prefs.getString("server_url", PRODUCTION_URL));
        final String[] profile = {prefs.getString("server_profile", "production")};
        LinearLayout profiles = new LinearLayout(this);
        profiles.addView(button("Production", () -> {
            profile[0] = "production";
            input.setText(prefs.getString("production_url", PRODUCTION_URL));
        }), new LinearLayout.LayoutParams(0, -2, 1));
        profiles.addView(button("Testing", () -> {
            profile[0] = "testing";
            input.setText(prefs.getString("testing_url", ""));
        }), new LinearLayout.LayoutParams(0, -2, 1));
        form.addView(profiles);
        form.addView(input);
        AlertDialog dialog = new AlertDialog.Builder(this).setTitle("Warehouse server")
                .setView(form).setPositiveButton("Save", null).setNegativeButton("Cancel", null).create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            try {
                ServerAddress address = new ServerAddress(input.getText().toString());
                prefs.edit().putString("server_url", address.baseUrl)
                        .putString("server_profile", profile[0])
                        .putString(profile[0] + "_url", address.baseUrl).apply();
                // Recreate so an old origin never retains access to the native bridge.
                dialog.dismiss();
                recreate();
            } catch (IllegalArgumentException error) {
                input.setError(error.getMessage() == null ? "Enter a valid http:// or https:// app address." : error.getMessage());
            }
        }));
        dialog.show();
    }

    private void refreshConnection() {
        if (menu != null) menu.setTooltipText(printerSummary());
    }

    private String printerSummary() {
        if (printing.get()) return "Printer operation in progress";
        if (!prefs.getBoolean("tablet_bluetooth", true)) return "Zebra: server / Wi-Fi printer";
        return "Tablet Bluetooth: " + prefs.getString("printer_name", "no printer selected");
    }

    private void showPrinterMenu() {
        if (printing.get() || preparing) { toast("Wait for the current printer operation."); return; }
        new AlertDialog.Builder(this).setTitle(printerSummary())
                .setItems(new String[]{"Select tablet Bluetooth printer", "Use server / Wi-Fi printer", "Pair a device in Android settings", "Check selected printer", "Print one test label"}, (dialog, item) -> {
                    if (item == 0) withBluetoothPermission(() -> selectPairedPrinter(null), () -> toast("Nearby devices permission is required for Bluetooth printing."));
                    else if (item == 1) { prefs.edit().putBoolean("tablet_bluetooth", false).apply(); refreshConnection(); }
                    else if (item == 2) openBluetoothSettings();
                    else withBluetoothPermission(() -> runPrinterDiagnostic(item == 4), () -> toast("Allow Nearby devices permission to check the printer."));
                }).setNegativeButton("Close", null).show();
    }

    private void withBluetoothPermission(Runnable granted, Runnable denied) {
        if (Build.VERSION.SDK_INT >= 31 && checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
            if (permissionGranted != null) { denied.run(); return; }
            permissionGranted = granted;
            permissionDenied = denied;
            requestPermissions(new String[]{Manifest.permission.BLUETOOTH_CONNECT}, CONNECT_PERMISSION);
        } else granted.run();
    }

    @Override public void onRequestPermissionsResult(int code, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(code, permissions, results);
        if (code != CONNECT_PERMISSION) return;
        Runnable next = results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED ? permissionGranted : permissionDenied;
        permissionGranted = null;
        permissionDenied = null;
        if (next != null) next.run();
    }

    private BluetoothAdapter adapter() {
        BluetoothManager manager = getSystemService(BluetoothManager.class);
        return manager == null ? null : manager.getAdapter();
    }

    @SuppressLint("MissingPermission")
    private void selectPairedPrinter(java.util.function.Consumer<Boolean> selected) {
        BluetoothAdapter adapter = adapter();
        if (adapter == null || !adapter.isEnabled()) {
            toast("Turn on Bluetooth and pair the QLn320 in Android settings.");
            if (selected != null) selected.accept(false);
            openBluetoothSettings();
            return;
        }
        List<BluetoothDevice> devices = new ArrayList<>();
        for (BluetoothDevice device : adapter.getBondedDevices()) if (printerCandidate(device)) devices.add(device);
        devices.sort(Comparator.comparing(device -> (device.getName() == null ? "" : device.getName()) + device.getAddress()));
        String[] names = new String[devices.size()];
        for (int i = 0; i < devices.size(); i++) {
            BluetoothDevice device = devices.get(i);
            names[i] = (device.getName() == null ? "Paired device" : device.getName()) + "\n" + device.getAddress();
        }
        AlertDialog.Builder printerChooser = new AlertDialog.Builder(this).setTitle(devices.isEmpty() ? "Pair the QLn320 first" : "Select the paired QLn320");
        if (devices.isEmpty()) printerChooser.setMessage("No paired Classic Bluetooth printer was found. Barcode scanners are not printers. Pair the QLn320 in Android settings.");
        printerChooser
                .setItems(names, (dialog, index) -> {
                    BluetoothDevice device = devices.get(index);
                    prefs.edit().putString("printer_address", device.getAddress())
                            .putString("printer_name", device.getName() == null ? device.getAddress() : device.getName())
                            .putBoolean("tablet_bluetooth", true).apply();
                    refreshConnection();
                    if (selected != null) selected.accept(true);
                }).setNegativeButton("Cancel", (dialog, which) -> { if (selected != null) selected.accept(false); })
                .setNeutralButton("Pair new device", (dialog, which) -> {
                    if (selected != null) selected.accept(false);
                    openBluetoothSettings();
                }).setOnCancelListener(dialog -> { if (selected != null) selected.accept(false); }).show();
    }

    private void openBluetoothSettings() {
        try { startActivity(new Intent(Settings.ACTION_BLUETOOTH_SETTINGS)); }
        catch (Exception ignored) { toast("Open Android settings to pair the printer."); }
    }

    @SuppressLint("MissingPermission")
    private boolean printerCandidate(BluetoothDevice device) {
        BluetoothClass kind = device.getBluetoothClass();
        return device.getType() != BluetoothDevice.DEVICE_TYPE_LE
                && (kind == null || kind.getMajorDeviceClass() != BluetoothClass.Device.Major.PERIPHERAL);
    }

    private void handleBridge(String text, JavaScriptReplyProxy reply) {
        String id = null;
        try {
            if (text == null || text.length() > 3000000) return;
            JSONObject request = new JSONObject(text);
            id = request.getString("id");
            if (id.length() > 64) return;
            String action = request.getString("action");
            if (action.equals("prepare")) prepare(id, reply);
            else if (action.equals("print")) print(id, request, reply);
            else respond(reply, id, false, "Unsupported printer operation.", 0, 0);
        } catch (Exception error) {
            preparing = false;
            if (id != null) respond(reply, id, false, "The tablet printer request could not be processed. Check printer settings and permissions.", 0, 0);
            else toast("Invalid tablet printer request.");
        }
    }

    @SuppressLint("MissingPermission")
    private void prepare(String id, JavaScriptReplyProxy reply) {
        if (printing.get() || preparing) { respond(reply, id, false, "The tablet printer is busy.", 0, 0); return; }
        if (!prefs.getBoolean("tablet_bluetooth", true)) { respond(reply, id, true, "Use server printing.", 0, 0); return; }
        preparing = true;
        withBluetoothPermission(() -> {
            BluetoothAdapter adapter = adapter();
            String address = prefs.getString("printer_address", "");
            if (adapter != null && adapter.isEnabled() && BluetoothAdapter.checkBluetoothAddress(address)
                    && adapter.getRemoteDevice(address).getBondState() == BluetoothDevice.BOND_BONDED
                    && printerCandidate(adapter.getRemoteDevice(address))) {
                preparing = false;
                respond(reply, id, true, "Printer selected.", 0, 0);
            } else {
                selectPairedPrinter(ok -> {
                    preparing = false;
                    respond(reply, id, ok, ok ? "Printer selected." : "Pair and select the QLn320 from floating menu > Printer. A barcode scanner cannot print labels.", 0, 0);
                });
            }
        }, () -> {
            preparing = false;
            respond(reply, id, false, "Allow Nearby devices permission in Android settings to print via Bluetooth.", 0, 0);
        });
    }

    private void print(String id, JSONObject request, JavaScriptReplyProxy reply) {
        final LabelBatch batch;
        try {
            JSONArray encoded = request.getJSONArray("labels");
            List<String> labels = new ArrayList<>();
            for (int i = 0; i < encoded.length(); i++) labels.add(encoded.getString(i));
            batch = new LabelBatch(labels, request.optInt("delayMs", 0));
        } catch (Exception error) {
            respond(reply, id, false, "Invalid or oversized Bluetooth print batch.", 0, 0);
            return;
        }
        if (!prefs.getBoolean("tablet_bluetooth", true) || !printing.compareAndSet(false, true)) {
            respond(reply, id, false, "Bluetooth printing is unavailable or busy.", 0, batch.labels.size());
            return;
        }
        refreshConnection();
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        printerWorker.execute(() -> sendBatch(id, batch, reply));
    }

    private void runPrinterDiagnostic(boolean testLabel) {
        if (!printing.compareAndSet(false, true)) { toast("The printer is busy."); return; }
        LabelBatch batch = null;
        if (testLabel) {
            String zpl = "^XA^PW576^LL609^FO40,60^A0N,36,36^FDBLUETOOTH TEST^FS^FO40,120^A0N,24,24^FDNBC Warehouse tablet^FS^FO40,180^BQN,2,6^FDLA,TABLET-BLUETOOTH-TEST^FS^PQ1^XZ\r\n";
            batch = new LabelBatch(Collections.singletonList(java.util.Base64.getEncoder().encodeToString(zpl.getBytes(StandardCharsets.UTF_8))), 0);
        }
        final LabelBatch selectedBatch = batch;
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        refreshConnection();
        printerWorker.execute(() -> sendBatch("diagnostic", selectedBatch, null));
    }

    @SuppressLint("MissingPermission")
    private void sendBatch(String id, LabelBatch batch, JavaScriptReplyProxy reply) {
        int printed = 0;
        String message;
        boolean ok = false;
        BluetoothSocket socket = null;
        ScheduledFuture<?> timeout = null;
        try {
            BluetoothAdapter adapter = adapter();
            String address = prefs.getString("printer_address", "");
            if (adapter == null || !adapter.isEnabled() || !BluetoothAdapter.checkBluetoothAddress(address)) {
                throw new IOException("Select a paired printer and turn on Bluetooth.");
            }
            BluetoothDevice device = adapter.getRemoteDevice(address);
            if (device.getBondState() != BluetoothDevice.BOND_BONDED) throw new IOException("The selected printer is no longer paired.");
            if (!printerCandidate(device)) throw new IOException("The selected device is a scanner or a BLE-only device. Select the QLn320 Classic Bluetooth printer.");
            socket = device.createRfcommSocketToServiceRecord(SPP);
            activeSocket = socket;
            if (destroyed) throw new IOException("The app was closed.");
            timeout = closeAfterTimeout(socket, 20);
            socket.connect();
            timeout.cancel(false);
            timeout = closeAfterTimeout(socket, 45);
            ZebraPrinterSession printer = new ZebraPrinterSession(socket.getInputStream(), socket.getOutputStream());
            String model = printer.verifyReady();
            timeout.cancel(false);
            for (byte[] label : batch == null ? Collections.<byte[]>emptyList() : batch.labels) {
                if (destroyed || Thread.currentThread().isInterrupted()) throw new IOException("Printing was interrupted.");
                timeout = closeAfterTimeout(socket, 60);
                printer.printAndConfirm(label);
                timeout.cancel(false);
                printed++;
                if (printed < batch.labels.size() && batch.delayMs > 0) Thread.sleep(batch.delayMs);
            }
            ok = true;
            message = batch == null ? model + " replied, accepts ZPL, and reports ready. No label was printed."
                    : model + " confirmed " + printed + " printed label(s) via tablet Bluetooth.";
        } catch (Exception error) {
            message = "Printer check / printing stopped. Confirmed labels: " + printed
                    + ". " + (error.getMessage() == null ? "Connection failed." : error.getMessage())
                    + " Check the printer for complete or partial labels before retrying.";
        } finally {
            if (timeout != null) timeout.cancel(false);
            if (socket != null) try { socket.close(); } catch (IOException ignored) { }
            activeSocket = null;
            printing.set(false);
        }
        final int completed = printed;
        final boolean success = ok;
        final String resultMessage = message;
        runOnUiThread(() -> {
            if (destroyed) return;
            getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            refreshConnection();
            if (reply == null) new AlertDialog.Builder(this).setTitle(success ? "Printer result" : "Printer error")
                    .setMessage(resultMessage).setPositiveButton("OK", null).show();
            else respond(reply, id, success, resultMessage, completed, batch.labels.size() - completed);
        });
    }

    private ScheduledFuture<?> closeAfterTimeout(BluetoothSocket socket, int seconds) {
        return watchdog.schedule(() -> {
            try { socket.close(); } catch (IOException ignored) { }
        }, seconds, TimeUnit.SECONDS);
    }

    private void respond(JavaScriptReplyProxy reply, String id, boolean ok, String message, int printed, int failed) {
        if (destroyed) return;
        try {
            JSONObject result = new JSONObject();
            result.put("id", id).put("ok", ok).put("message", message).put("printed", printed).put("failed", failed)
                    .put("protocol", "confirmed_print_v1").put("confirmed", ok && printed > 0)
                    .put("transport", prefs.getBoolean("tablet_bluetooth", true) ? "tablet_bluetooth" : "server")
                    .put("printer_name", prefs.getString("printer_name", "Zebra QLn320") + " (tablet Bluetooth)");
            if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) reply.postMessage(result.toString());
        } catch (Exception ignored) { /* The requesting page may have been closed. */ }
    }

    private void goBack() {
        if (printing.get()) { toast("Wait for printing to finish."); return; }
        if (web.canGoBack()) web.goBack();
    }

    @Override public void onBackPressed() {
        if (printing.get()) { toast("Wait for printing to finish."); return; }
        if (web.canGoBack()) web.goBack(); else super.onBackPressed();
    }

    private void toast(String message) { Toast.makeText(this, message, Toast.LENGTH_LONG).show(); }

    @Override protected void onDestroy() {
        destroyed = true;
        BluetoothSocket socket = activeSocket;
        if (socket != null) try { socket.close(); } catch (IOException ignored) { }
        printerWorker.shutdownNow();
        watchdog.shutdownNow();
        if (web != null) { web.stopLoading(); web.destroy(); }
        super.onDestroy();
    }
}
