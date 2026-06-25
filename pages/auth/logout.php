<?php
require_once __DIR__ . '/../../includes/auth.php';
session_destroy();
header('Location: ' . app_path('pages/auth/login.php'));
exit;
?>
