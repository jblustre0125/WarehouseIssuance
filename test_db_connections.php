<?php
require_once 'db_connect.php';

echo "<h2>Testing Database Connections</h2>";

// Test WHPOKAYOKE connection
try {
    $conn_whp = get_whpokayoke_connection();
    if ($conn_whp) {
        echo "<p style='color:green;'>WHPOKAYOKE connection successful!</p>";
        sqlsrv_close($conn_whp);
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>WHPOKAYOKE connection failed: " . $e->getMessage() . "</p>";
}

// Test ERP connection
try {
    $conn_erp = get_erp_connection();
    if ($conn_erp) {
        echo "<p style='color:green;'>ERP (SAP) connection successful!</p>";
        sqlsrv_close($conn_erp);
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>ERP (SAP) connection failed: " . $e->getMessage() . "</p>";
}
