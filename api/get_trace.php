<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN]);
$conn = get_whpokayoke_connection();
$traceNo = trim($_GET['trace_no'] ?? '');
if ($traceNo === '') { echo json_encode(['found'=>false,'message'=>'Trace number is required']); exit; }
$h = fetch_one($conn, 'SELECT * FROM RawmatTraceHeader WHERE TraceNo = ?', [$traceNo]);
if (!$h) { echo json_encode(['found'=>false,'message'=>'Trace number not found']); exit; }
$lines = fetch_all($conn, 'SELECT TraceLineID, ItemCode, PartName, LotNo, IssuedQty, ReceivedLotNo, ReceivedQty, VerificationStatus FROM RawmatTraceLines WHERE TraceID = ? ORDER BY TraceLineID', [$h['TraceID']]);
echo json_encode(['found'=>true,'header'=>['TraceNo'=>$h['TraceNo'],'ITRNumber'=>$h['ITRNumber'],'Status'=>$h['Status'],'CreatedByUsername'=>$h['CreatedByUsername'],'CreatedAt'=>$h['CreatedAt'] ? $h['CreatedAt']->format('Y-m-d H:i:s') : ''],'lines'=>$lines]);
?>
