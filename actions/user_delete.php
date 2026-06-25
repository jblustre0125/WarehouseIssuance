<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(ROLE_ADMIN);
$id=(int)($_GET['id']??0);
if($id>0 && $id !== (int)current_user()['id']) { $conn=get_whpokayoke_connection(); sqlsrv_query($conn,'UPDATE AppUsers SET IsActive=0, UpdatedAt=GETDATE() WHERE UserID=?',[$id]); }
header('Location: ' . app_path('pages/admin/admin_users.php')); exit;
?>
