<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

$u = current_user();
$role = strtolower((string)($u['role'] ?? ''));

if ($role === ROLE_ISSUER) {
    header('Location: ' . app_path('pages/issuer/issuer.php'));
    exit;
}

if ($role === ROLE_PICKER || $role === ROLE_WAREHOUSE) {
    header('Location: ' . app_path('pages/picker/picker.php'));
    exit;
}

if ($role === ROLE_REQUESTOR) {
    header('Location: ' . app_path('pages/requestor/requestor.php'));
    exit;
}

if ($role === ROLE_RECEIVER) {
    header('Location: ' . app_path('pages/dashboard/verification_dashboard.php'));
    exit;
}

if ($role === ROLE_SAP_ENCODER) {
    header('Location: ' . app_path('pages/sap_encoder/report.php'));
    exit;
}

if ($role === ROLE_ADMIN) {
    header('Location: ' . app_path('pages/dashboard/verification_dashboard.php'));
    exit;
}

header('Location: ' . app_path('pages/auth/login.php'));
exit;
?>
