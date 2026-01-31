<?php
if (!defined("DB_HOST_WHP")) define("DB_HOST_WHP", "192.168.20.230");
if (!defined("DB_USER_WHP")) define("DB_USER_WHP", "sa");
if (!defined("DB_PASS_WHP")) define("DB_PASS_WHP", "Nbc12#");
if (!defined("DB_NAME_WHP")) define("DB_NAME_WHP", "WHPOKAYOKE");

// ERP server (SAP, where part names are looked up)
if (!defined("DB_HOST_ERP")) define("DB_HOST_ERP", "erpserver");
if (!defined("DB_USER_ERP")) define("DB_USER_ERP", "sa");
if (!defined("DB_PASS_ERP")) define("DB_PASS_ERP", '1q2w#E$R');
if (!defined("DB_NAME_ERP")) define("DB_NAME_ERP", "NBCP_Final_Live");

function get_whpokayoke_connection()
{
    $serverName = DB_HOST_WHP;
    $connectionOptions = [
        "Database" => DB_NAME_WHP,
        "Uid" => DB_USER_WHP,
        "PWD" => DB_PASS_WHP,
        "CharacterSet" => "UTF-8"
    ];
    $conn = sqlsrv_connect($serverName, $connectionOptions);
    if ($conn === false) {
        die("WHPOKAYOKE DB Connection failed: " . print_r(sqlsrv_errors(), true));
    }
    return $conn;
}

function get_erp_connection()
{
    $serverName = DB_HOST_ERP;
    $connectionOptions = [
        "Database" => DB_NAME_ERP,
        "Uid" => DB_USER_ERP,
        "PWD" => DB_PASS_ERP,
        "CharacterSet" => "UTF-8"
    ];
    $conn = sqlsrv_connect($serverName, $connectionOptions);
    if ($conn === false) {
        die("ERP DB Connection failed: " . print_r(sqlsrv_errors(), true));
    }
    return $conn;
}
