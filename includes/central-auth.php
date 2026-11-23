<?php
/** Optional Central SSO. Native sessions remain application owned. */
declare(strict_types=1);

function whSecure(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function whCookiePath(): string
{
    return '/' . basename(dirname(__DIR__));
}

function whAuthContext(): string
{
    return session_name() === 'WH_STAFF_SSO' ? 'central' : 'native';
}

function whAuthMode(string $mode): void
{
    setcookie('WH_AUTH_MODE', $mode, ['path' => whCookiePath(), 'secure' => whSecure(),
        'httponly' => true, 'samesite' => 'Lax']);
}

function whProtectedPath(string $relative): bool
{
    $relative = strtolower($relative);
    if (in_array($relative, ['index.php', 'tools/sync_scanplus_cache.php'], true)) return true;
    if (!preg_match('~^(pages|actions|api)/.+\.php$~D', $relative)) return false;
    return !in_array($relative, ['pages/auth/login.php', 'api/get_part_name.php',
        'api/issuer/lot_balance_lib.php', 'actions/save_tag.php', 'pages/tags/view_tags.php'], true);
}

function whAuthUrl(string $path): string
{
    if (str_starts_with($path, '?')) $path = (string)($_SERVER['SCRIPT_NAME'] ?? '') . $path;
    $parts = parse_url($path);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) return $path;
    $page = $parts['path'] ?? '';
    $prefix = whCookiePath() . '/';
    $relative = str_starts_with($page, $prefix) ? substr($page, strlen($prefix)) : $page;
    if (!whProtectedPath($relative) && $relative !== 'pages/auth/login.php') return $path;
    parse_str($parts['query'] ?? '', $query);
    $query['wh_auth'] = $relative === 'pages/auth/login.php' ? 'native' : whAuthContext();
    return $page . '?' . http_build_query($query) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

function whAuthField(): string
{
    return '<input type="hidden" name="wh_auth" value="' . whAuthContext() . '">';
}

function whRemoteRoot(): ?string
{
    $configured = getenv('WH_REMOTE_ROOT');
    if (is_string($configured) && $configured !== '') return rtrim($configured, '/\\');
    $root = dirname(__DIR__, 3) . '/nbc-bridges';
    return is_file($root . '/config.local.php') ? $root : null;
}

function whCentralRuntime(): string
{
    if (is_file(dirname(__DIR__) . '/.warehouse-sso-disabled')) throw new RuntimeException('Central sign-in is disabled.');
    $remote = whRemoteRoot();
    $root = $remote ?? (getenv('WH_CENTRAL_ROOT') ?: dirname(__DIR__, 2) . '/nbc-central');
    $files = $remote !== null ? ['runtime.php'] : ['app/runtime.php', 'integrations/session.php'];
    $GLOBALS['nbc_application_key'] = 'warehouse';
    foreach ($files as $file) {
        if (!is_file($root . '/' . $file)) throw new RuntimeException('Central connector is unavailable.');
        require_once $root . '/' . $file;
    }
    if ((\NbcCentral\applications()['warehouse']['cookie_path'] ?? '') !== whCookiePath()) {
        throw new RuntimeException('Central application path does not match this installation.');
    }
    return $remote !== null ? 'remote' : 'local';
}

function whStartCentral(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('A session already started before staff SSO.');
    $storage = session_save_path();
    if (str_contains($storage, ';')) $storage = substr($storage, strrpos($storage, ';') + 1);
    $storage = rtrim($storage !== '' ? $storage : sys_get_temp_dir(), '/\\') . '/wh-staff-sso-v1';
    if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) throw new RuntimeException('Staff SSO storage is unavailable.');
    ini_set('session.save_handler', 'files');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_save_path($storage);
    session_name('WH_STAFF_SSO');
    $cookie = $_COOKIE['WH_STAFF_SSO'] ?? '';
    session_id(is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{1,256}$/D', $cookie) ? $cookie : '');
    session_set_cookie_params(['lifetime' => 0, 'path' => whCookiePath(), 'secure' => whSecure(),
        'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new RuntimeException('Staff SSO session is unavailable.');
}

function whEndCentral(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'WH_STAFF_SSO') {
        $_SESSION = [];
        session_destroy();
    }
    setcookie('WH_STAFF_SSO', '', ['expires' => time() - 3600, 'path' => whCookiePath(),
        'secure' => whSecure(), 'httponly' => true, 'samesite' => 'Lax']);
    whAuthMode('native');
}

function whCentralLogoutTarget(): string
{
    // Written only after a validated handoff; no user-supplied return URL.
    $target = $_SESSION['wh_central_return_url'] ?? '';
    if (is_string($target) && preg_match('~^/(?!/)[^\\\\\x00-\x20\x7f]*$~D', $target)) return $target;
    if (($_SESSION['wh_central_transport'] ?? '') === 'remote' && is_string($target)
        && filter_var($target, FILTER_VALIDATE_URL) && !preg_match('~[\\\\\x00-\x20\x7f]~', $target)) {
        $parts = parse_url($target);
        if (($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/index.php')) return $target;
    }
    return whCookiePath() . '/pages/auth/login.php';
}

function whCentralFailure(Throwable $error, string $phase, int $status = 503): never
{
    $transport = $error instanceof \NbcCentral\BridgeApiException
        ? ' curl=' . $error->curlNumber . ' http=' . $error->httpStatus . ' tls=' . $error->tlsResult : '';
    error_log('Warehouse SSO ' . $phase . ' failed (' . get_class($error) . ', '
        . basename($error->getFile()) . ':' . $error->getLine() . ').' . $transport);
    whEndCentral();
    http_response_code($status);
    header('Cache-Control: no-store');
    $relative = whRequestRelative() ?? '';
    if (str_starts_with(strtolower($relative), 'api/') || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Warehouse Central sign-in could not be validated. Open Warehouse again from Central or use direct login.',
            'login_url'=>whCookiePath().'/pages/auth/login.php']);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $base = htmlspecialchars(whCookiePath() . '/', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Warehouse sign-in</title>'
        . '<main><h1>Warehouse SSO could not be validated</h1><p>Open Warehouse again from NBC Central, or use direct access.</p>'
        . '<a href="' . $base . 'pages/auth/login.php">Direct Warehouse login</a> '
        . '<a href="' . $base . 'index.php?wh_auth=native">Direct access</a></main></html>';
    exit;
}

function whCallbackRequest(): bool
{
    return strcasecmp(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? '')),
        str_replace('\\', '/', dirname(__DIR__)) . '/central-sso.php') === 0;
}

function whRequestRelative(): ?string
{
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/';
    return strncasecmp($file, $root, strlen($root)) === 0 ? substr($file, strlen($root)) : null;
}

function whCentralConnection(): mixed
{
    require_once __DIR__ . '/db_connect.php';
    static $connection = null;
    if ($connection === null) $connection = get_whpokayoke_connection(true);
    return $connection;
}

function whRefreshCentralAccount(string $username): bool
{
    $connection = whCentralConnection();
    $row = fetch_one($connection, 'SELECT TOP 1 UserID, Username, FullName, RoleName, ReceiverArea, DeviceHostname, DeviceIPAddress FROM AppUsers WHERE Username = ? AND IsActive = 1', [$username]);
    if (!$row) return false;
    $role = strtolower((string)$row['RoleName']);
    if (!in_array($role, ['issuer', 'picker', 'warehouse', 'requestor', 'receiver', 'admin', 'sap_encoder'], true)) return false;
    $_SESSION['user'] = ['id' => $row['UserID'], 'username' => $row['Username'], 'full_name' => $row['FullName'],
        'role' => $role, 'receiver_area' => $row['ReceiverArea'] ?? '',
        'device_hostname' => $row['DeviceHostname'] ?? '', 'device_ip' => $row['DeviceIPAddress'] ?? ''];
    return true;
}

/** Context selection only: the chosen session still needs full authentication.
 * Same-origin referrers cover older tablet capability calls without URL params.
 */
function whReferrerContext(): ?string
{
    $parts = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $scheme = whSecure() ? 'https' : 'http';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!str_contains($host, ':')) $host .= ':' . ($scheme === 'https' ? 443 : 80);
    $origin = strtolower($parts['host']) . ':' . ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
    if (($parts['scheme'] ?? '') !== $scheme || $origin !== $host
        || !str_starts_with($parts['path'] ?? '', whCookiePath() . '/')) return null;
    parse_str($parts['query'] ?? '', $query);
    return in_array($query['wh_auth'] ?? null, ['central', 'native'], true) ? $query['wh_auth'] : null;
}

function whSelectCentralSession(): bool
{
    $relative = whRequestRelative();
    // CLI jobs, native sign-in and public helpers never depend on Central.
    if (PHP_SAPI === 'cli' || $relative === null || !whProtectedPath($relative)) return false;
    $context = $_GET['wh_auth'] ?? $_POST['wh_auth'] ?? whReferrerContext() ?? $_COOKIE['WH_AUTH_MODE'] ?? 'native';
    if ($context !== 'central') return false;
    $phase = 'session';
    try {
        whStartCentral();
        header('Cache-Control: no-store');
        if (strtolower($relative) === 'pages/auth/logout.php') {
            $target = whCentralLogoutTarget();
            whEndCentral();
            header('Location: ' . $target, true, 303);
            exit;
        }
        $identity = $_SESSION['nbc_central_identity'] ?? null;
        if (!is_array($identity)) whCentralFailure(new RuntimeException('Missing Warehouse identity.'), 'identity', 403);
        $phase = 'runtime';
        $transport = whCentralRuntime();
        if ($transport !== ($_SESSION['wh_central_transport'] ?? '')) throw new RuntimeException('Connector changed.');
        if ($transport === 'remote') { $phase = 'https'; \NbcCentral\bridgeRequireHttps(); }
        $phase = 'grant';
        if (!\NbcCentral\centralIdentityIsValid($identity, 'warehouse')) whCentralFailure(new RuntimeException('Central access revoked.'), $phase, 403);
        $phase = 'account';
        if (!whRefreshCentralAccount((string)$identity['local_username'])) whCentralFailure(new RuntimeException('Mapped active account unavailable.'), $phase, 403);
        return true;
    } catch (Throwable $error) {
        whCentralFailure($error, $phase, $phase === 'https' ? 403 : 503);
    }
}

/** Resume an existing authenticated legacy native session without moving it.
 * New/native production sessions keep the original PHP session configuration.
 */
function whResumeLegacyNative(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $cookie = $_COOKIE['NBC_NATIVE_WAREHOUSE'] ?? '';
    if (!is_string($cookie) || !preg_match('/^[A-Za-z0-9,-]{1,256}$/D', $cookie)) return;
    $originalPath = session_save_path();
    $originalName = session_name();
    $originalId = session_id();
    $originalParams = session_get_cookie_params();
    $originalStrict = ini_get('session.use_strict_mode');
    $storage = str_contains($originalPath, ';') ? substr($originalPath, strrpos($originalPath, ';') + 1) : $originalPath;
    $storage = rtrim($storage !== '' ? $storage : sys_get_temp_dir(), '/\\') . '/nbc-warehouse-native-sessions';
    if (ini_get('session.save_handler') !== 'files' || !is_file($storage . '/sess_' . $cookie)) return;
    session_save_path($storage);
    session_name('NBC_NATIVE_WAREHOUSE');
    session_id($cookie);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime'=>0,'path'=>whCookiePath(),'secure'=>whSecure(),'httponly'=>true,'samesite'=>'Lax']);
    if (session_start() && is_array($_SESSION['user'] ?? null) && !isset($_SESSION['nbc_central_identity'])) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    session_id($originalId);
    session_name($originalName);
    session_save_path($originalPath);
    session_set_cookie_params($originalParams);
    ini_set('session.use_strict_mode', $originalStrict);
}
