<?php
/** App-owned optional Central callback; never opens the native session. */
declare(strict_types=1);
require_once __DIR__ . '/includes/central-auth.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    if ($method !== 'POST' && !($method === 'GET' && whRemoteRoot() !== null)) {
        http_response_code(405);
        header('Allow: POST');
        exit('Open Warehouse from NBC Central, or use the direct staff login.');
    }
    $transport = whCentralRuntime();
    if ($transport === 'remote') {
        if ($method !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            exit('Open Warehouse from NBC Central.');
        }
        if (!whSecure() && !isset($_GET['code']) && !isset($_GET['state']) && !isset($_GET['error'])) {
            header('Location: ' . \NbcCentral\bridgeApp()['callback'], true, 303);
            exit;
        }
        \NbcCentral\bridgeRequireHttps(true);
        whStartCentral();
        if (!isset($_GET['code']) && !isset($_GET['state']) && !isset($_GET['error'])) {
            $target = \NbcCentral\bridgeBegin();
            session_write_close();
            header('Location: ' . $target, true, 303);
            exit;
        }
        $verifier = \NbcCentral\bridgeConsumeState($_GET);
        $identity = \NbcCentral\bridgeApi(['action' => 'exchange', 'code' => $_GET['code'],
            'verifier' => $verifier, 'callback' => \NbcCentral\bridgeApp()['callback']]);
        if (($identity['active'] ?? false) !== true || !is_int($identity['user']['id'] ?? null)
            || $identity['user']['id'] < 1 || !is_string($identity['local_username'] ?? null) || $identity['local_username'] === ''
            || !is_string($identity['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $identity['token'])) {
            throw new RuntimeException('Invalid Central identity.');
        }
        $centralIdentity = ['app_key' => 'warehouse', 'user_id' => $identity['user']['id'],
            'local_username' => $identity['local_username'], 'token' => $identity['token']];
    } else {
        $ticket = $_POST['ticket'] ?? '';
        $binding = $_COOKIE['NBC_SSO_BIND'] ?? '';
        if (!is_string($ticket) || !is_string($binding)) throw new RuntimeException('Invalid ticket.');
        $identity = \NbcCentral\consumeTicket($ticket, 'warehouse', $binding);
        whStartCentral();
        $centralIdentity = ['app_key' => 'warehouse', 'user_id' => (int)$identity['user_id'],
            'local_username' => (string)$identity['local_username'], 'session_hash' => $identity['session_hash']];
    }
    $_SESSION = [];
    session_regenerate_id(true);
    require_once __DIR__ . '/includes/auth.php';
    if (!whRefreshCentralAccount($centralIdentity['local_username'])) throw new RuntimeException('Mapped active Warehouse account unavailable.');
    $_SESSION['nbc_central_identity'] = $centralIdentity;
    $_SESSION['wh_central_transport'] = $transport;
    $_SESSION['wh_central_return_url'] = \NbcCentral\url();
    whAuthMode('central');
    sqlsrv_query(whCentralConnection(), 'UPDATE AppUsers SET LastLoginAt = GETDATE(), LastLoginHostname = ?, LastLoginIPAddress = ? WHERE UserID = ?', [client_hostname(), client_ip(), current_user()['id']]);
    $target = whAuthUrl(app_path('index.php'));
    session_write_close();
    header('Location: ' . $target, true, 303);
} catch (Throwable $error) {
    whCentralFailure($error, 'handoff', $error instanceof \NbcCentral\BridgeApiException ? 503 : 403);
}
