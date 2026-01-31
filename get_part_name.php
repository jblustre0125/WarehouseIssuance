<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
$debug = [];
if (!isset($_GET['item_code'])) {
    echo json_encode(["part_name" => "", "debug" => "No item_code provided"]);
    exit;
}

$item_code = $_GET['item_code'];
$debug['item_code'] = $item_code;

$conn = get_erp_connection();
if ($conn === false) {
    $debug['conn_error'] = 'Connection failed';
    echo json_encode(["part_name" => "", "debug" => $debug]);
    exit;
}

$sql = "SELECT ItemName FROM OITM WHERE ItemCode = ?";
$params = [$item_code];
$debug['sql'] = $sql;
$debug['params'] = $params;
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    echo json_encode(["part_name" => $row['ItemName'], "debug" => $debug]);
} else {
    $debug['query_error'] = ($stmt === false) ? sqlsrv_errors() : 'No row found';
    echo json_encode(["part_name" => "", "debug" => $debug]);
}
