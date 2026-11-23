<?php
require_once __DIR__ . '/../../includes/auth.php';
session_destroy();
header('Location: ' . whAuthUrl(app_path('pages/auth/login.php')));
exit;
?>
