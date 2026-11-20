<?php
// NBC Central managed bridge
$GLOBALS['nbc_application_key'] = 'warehouse';
foreach (['C:/Xampp/nbc-bridges/entry.php', 'C:/Xampp/htdocs/nbc-central/integrations/bridge.php'] as $nbcEntry) {
    if (is_file($nbcEntry)) { require $nbcEntry; exit; }
}
header('Cache-Control: no-store');
header('Location: pages/auth/login.php', true, 303);
exit;