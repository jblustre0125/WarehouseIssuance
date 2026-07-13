<?php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

const ROLE_ISSUER = 'issuer';
const ROLE_PICKER = 'picker';
const ROLE_WAREHOUSE = 'warehouse';
const ROLE_REQUESTOR = 'requestor';
const ROLE_RECEIVER = 'receiver';
const ROLE_ADMIN = 'admin';
const ROLE_SAP_ENCODER = 'sap_encoder';

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function current_user() { return $_SESSION['user'] ?? null; }
function app_path($path = '')
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/(pages|actions|api|tools)(/.*)?$#', '', $script);
    $base = preg_replace('#/[^/]*\.php$#', '', $base);
    return rtrim($base, '/') . '/' . ltrim((string)$path, '/');
}
function require_login() { if (!current_user()) { header('Location: ' . app_path('pages/auth/login.php')); exit; } }
function require_role($roles)
{
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!role_can_access($_SESSION['user']['role'] ?? '', $roles)) { http_response_code(403); die('Access denied.'); }
}
function role_can_access($userRole, $roles)
{
    $userRole = strtolower((string)$userRole);
    $roles = is_array($roles) ? $roles : [$roles];
    $roles = array_map(static fn($role) => strtolower((string)$role), $roles);

    if (in_array($userRole, $roles, true)) {
        return true;
    }

    if ($userRole === ROLE_WAREHOUSE) {
        return in_array(ROLE_PICKER, $roles, true) || in_array(ROLE_ISSUER, $roles, true);
    }

    return false;
}
function raw_material_qr_print_can_access($user = null)
{
    $user = $user ?: (function_exists('current_user') ? current_user() : []);
    $role = strtolower((string)($user['role'] ?? ''));
    $username = strtolower(trim((string)($user['username'] ?? '')));
    $fullName = strtolower(trim((string)($user['full_name'] ?? '')));

    return role_can_access($role, [ROLE_ISSUER, ROLE_ADMIN]) ||
        $username === '2111-002' ||
        in_array($fullName, ['michael banaban', 'edwin sanchez'], true);
}
function raw_material_qr_print_require_access()
{
    require_login();

    if (!raw_material_qr_print_can_access(current_user())) {
        http_response_code(403);
        die('Access denied.');
    }
}
function role_is_warehouse_staff($role)
{
    return in_array(strtolower((string)$role), [ROLE_PICKER, ROLE_ISSUER, ROLE_WAREHOUSE], true);
}
function role_label($role)
{
    return [
        ROLE_ISSUER => 'Issuer - Warehouse',
        ROLE_PICKER => 'Picker - Warehouse',
        ROLE_WAREHOUSE => 'Warehouse',
        ROLE_REQUESTOR => 'Requestor - Production',
        ROLE_RECEIVER => 'Receiver',
        ROLE_SAP_ENCODER => 'SAP Encoder',
        ROLE_ADMIN => 'Admin'
    ][strtolower((string)$role)] ?? $role;
}
function receiver_areas()
{
    return ['Cut and Crimp','Levercom/Steering/Hazard','Contact Switch/MR Switch/Hotmelt','Sub-Assy','Kitting'];
}
function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? ''; }
function client_hostname()
{
    $ip = client_ip();
    $host = $ip ? @gethostbyaddr($ip) : '';
    return $host && $host !== $ip ? $host : '';
}
function app_url($path)
{
    $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
    return $base . '/' . ltrim($path, '/');
}
function navbar($active = '')
{
    $u = current_user();
    $role = strtolower((string)($u['role'] ?? ''));
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= h(app_path('index.php')) ?>">NBC Rawmat Traceability</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="topNav">
            <?php if ($u): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link <?= $active === 'home' ? 'active' : '' ?>" href="<?= h(app_path('index.php')) ?>">Home</a></li>
                    <?php if (role_can_access($role, [ROLE_PICKER, ROLE_ADMIN])): ?><li class="nav-item"><a class="nav-link <?= $active === 'picker' ? 'active' : '' ?>" href="<?= h(app_path('pages/picker/picker.php')) ?>">Pick Tags</a></li><li class="nav-item"><a class="nav-link <?= $active === 'picker_report' ? 'active' : '' ?>" href="<?= h(app_path('pages/picker/picker_report.php')) ?>">Picker Report</a></li><?php endif; ?>
                    <?php if (role_can_access($role, [ROLE_ISSUER, ROLE_ADMIN])): ?><li class="nav-item"><a class="nav-link <?= $active === 'issuer' ? 'active' : '' ?>" href="<?= h(app_path('pages/issuer/issuer.php')) ?>">Issue Scan</a></li><li class="nav-item"><a class="nav-link <?= $active === 'issuer_report' ? 'active' : '' ?>" href="<?= h(app_path('pages/issuer/issuer_scan_report.php')) ?>">Issue Report</a></li><?php endif; ?>
                    <?php if (role_can_access($role, [ROLE_REQUESTOR, ROLE_ADMIN])): ?><li class="nav-item"><a class="nav-link <?= $active === 'requestor' ? 'active' : '' ?>" href="<?= h(app_path('pages/requestor/requestor.php')) ?>">Issue Request</a></li><li class="nav-item"><a class="nav-link <?= $active === 'requestor_report' ? 'active' : '' ?>" href="<?= h(app_path('pages/requestor/requestor_report.php')) ?>">Request Report</a></li><?php endif; ?>
                    <li class="nav-item"><a class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= h(app_path('pages/dashboard/verification_dashboard.php')) ?>">Verification</a></li>
                    <li class="nav-item"><a class="nav-link <?= $active === 'transactions' ? 'active' : '' ?>" href="<?= h(app_path('pages/reports/view_transactions.php')) ?>">Transactions</a></li>
                    <?php if ($role === ROLE_ADMIN): ?><li class="nav-item"><a class="nav-link <?= $active === 'admin' ? 'active' : '' ?>" href="<?= h(app_path('pages/admin/admin_users.php')) ?>">Users</a></li><?php endif; ?>
                </ul>
                <span class="navbar-text me-3"><?= h($u['full_name'] ?: $u['username']) ?> (<?= h(role_label($u['role'])) ?>)</span>
                <a class="btn btn-sm btn-outline-light" href="<?= h(app_path('pages/auth/logout.php')) ?>">Logout</a>
            <?php endif; ?>
            </div>
        </div>
    </nav>
    <?php
}
?>
