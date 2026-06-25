<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$u = current_user();
if ($u['role'] === ROLE_ISSUER) header('Location: ' . app_path('pages/issuer/issuer.php'));
elseif ($u['role'] === ROLE_PICKER) header('Location: ' . app_path('pages/picker/picker.php'));
elseif ($u['role'] === ROLE_REQUESTOR) header('Location: ' . app_path('pages/requestor/requestor.php'));
elseif ($u['role'] === ROLE_RECEIVER) header('Location: ' . app_path('pages/dashboard/verification_dashboard.php'));
elseif ($u['role'] === ROLE_ADMIN) header('Location: ' . app_path('pages/dashboard/verification_dashboard.php'));
exit;
?>
