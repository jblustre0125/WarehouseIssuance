<?php
// NBC Central managed bridge
// Optional Warehouse SSO: native password login remains independent of Central.
$GLOBALS['nbc_application_key'] = 'warehouse';
$nbcWarehousePath = '/' . basename(__DIR__);
$GLOBALS['nbc_native_login_url'] = $nbcWarehousePath . '/pages/auth/login.php';
$nbcWarehouseFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
if (strcasecmp($nbcWarehouseFile, str_replace('\\', '/', __DIR__ . '/central-sso.php')) === 0) return;
$nbcWarehouseNativeRoute = (bool)preg_match('~/pages/auth/(?:login|logout)\.php$~i', $nbcWarehouseFile);
$nbcWarehouseSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$nbcWarehouseSsoName = 'NBC_APP_WAREHOUSE';
$nbcWarehouseStart = static function (string $name) use ($nbcWarehousePath, $nbcWarehouseSecure): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_name($name);
    session_set_cookie_params(['lifetime' => 0, 'path' => $nbcWarehousePath, 'secure' => $nbcWarehouseSecure, 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) { http_response_code(503); exit('Warehouse cannot start a session.'); }
};
if (isset($_COOKIE[$nbcWarehouseSsoName])) {
    $nbcWarehouseStart($nbcWarehouseSsoName);
    if (!$nbcWarehouseNativeRoute && isset($_SESSION['nbc_central_identity'])) {
        foreach (['C:/Xampp/nbc-bridges/guard.php', 'C:/Xampp/htdocs/nbc-central/integrations/guard.php'] as $nbcWarehouseGuard) {
            if (is_file($nbcWarehouseGuard)) {
                require $nbcWarehouseGuard;
                // The delegated guard validates a Central-origin session on
                // every protected request; native auth still enforces roles.
                return;
            }
        }
    }
    // Explicit native login/logout, unavailable Central code, or an empty SSO
    // session returns to the application's independent authentication flow.
    $_SESSION = [];
    session_destroy();
    setcookie($nbcWarehouseSsoName, '', ['expires' => time() - 3600, 'path' => $nbcWarehousePath, 'secure' => $nbcWarehouseSecure, 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[$nbcWarehouseSsoName]);
}
$nbcWarehouseNativeStorage = session_save_path();
if (str_contains($nbcWarehouseNativeStorage, ';')) $nbcWarehouseNativeStorage = substr($nbcWarehouseNativeStorage, strrpos($nbcWarehouseNativeStorage, ';') + 1);
if ($nbcWarehouseNativeStorage === '') $nbcWarehouseNativeStorage = sys_get_temp_dir();
$nbcWarehouseNativeStorage = rtrim($nbcWarehouseNativeStorage, '/\\') . '/warehouse-native-sessions';
if (!is_dir($nbcWarehouseNativeStorage) && !@mkdir($nbcWarehouseNativeStorage, 0700, true) && !is_dir($nbcWarehouseNativeStorage)) {
    http_response_code(503); exit('Warehouse cannot open its private native session storage.');
}
// Separate server-side storage prevents sessions belonging to other legacy
// applications from being replayed through Warehouse's native cookie name.
ini_set('session.save_handler', 'files');
session_save_path($nbcWarehouseNativeStorage);
$nbcWarehouseStart('WAREHOUSE_NATIVE');
// PHP session storage can be shared across cookie names. An SSO session ID
// replayed as a native cookie must never become password-authenticated access.
if (isset($_SESSION['nbc_central_identity']) || isset($_SESSION['nbc_central_user'])) {
    $_SESSION = [];
    session_regenerate_id(true);
}
// No Central runtime/database/API is loaded for native sign-in or its sessions.
