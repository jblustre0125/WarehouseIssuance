<?php
require_once __DIR__ . '/../includes/db_connect.php';
echo '<h2>Testing Database Connections</h2>';
try { $c=get_whpokayoke_connection(); echo '<p style="color:green">WHPOKAYOKE connection successful.</p>'; sqlsrv_close($c); } catch(Exception $e) { echo '<p style="color:red">WHPOKAYOKE failed: '.htmlspecialchars($e->getMessage()).'</p>'; }
try { $c=get_erp_connection(); echo '<p style="color:green">SAP B1 ERP connection successful.</p>'; sqlsrv_close($c); } catch(Exception $e) { echo '<p style="color:red">SAP B1 failed: '.htmlspecialchars($e->getMessage()).'</p>'; }
?>
