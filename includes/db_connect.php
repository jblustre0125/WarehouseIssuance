<?php
$config = __DIR__ . '/config.php';
if (!file_exists($config)) {
    die('Missing config.php. Copy config.example.php to config.php and edit database settings.');
}
require_once $config;

function sqlsrv_options($database, $user, $password)
{
    return [
        'Database' => $database,
        'Uid' => $user,
        'PWD' => $password,
        'CharacterSet' => 'UTF-8',
        'Encrypt' => 'yes',
        'TrustServerCertificate' => 'yes'
    ];
}

function get_whpokayoke_connection()
{
    $conn = sqlsrv_connect(DB_HOST_WHP, sqlsrv_options(DB_NAME_WHP, DB_USER_WHP, DB_PASS_WHP));
    if ($conn === false) die('WHPOKAYOKE DB Connection failed: ' . print_r(sqlsrv_errors(), true));
    return $conn;
}

function get_erp_connection()
{
    $conn = sqlsrv_connect(DB_HOST_ERP, sqlsrv_options(DB_NAME_ERP, DB_USER_ERP, DB_PASS_ERP));
    if ($conn === false) die('SAP B1 DB Connection failed: ' . print_r(sqlsrv_errors(), true));
    return $conn;
}

function app_error($message, $code = 500)
{
    http_response_code($code);
    die(htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'));
}

function fetch_one($conn, $sql, $params = [])
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ?: null;
}

function fetch_all($conn, $sql, $params = [])
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    return $rows;
}

function sqlsrv_fail_message()
{
    $err = sqlsrv_errors();
    return is_array($err) ? print_r($err, true) : 'Database error';
}
?>
