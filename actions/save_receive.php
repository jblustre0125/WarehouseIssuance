<?php
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN]);
$traceNo = trim($_POST['trace_no'] ?? '');
$items = json_decode($_POST['batch_items'] ?? '[]', true);
if ($traceNo === '' || !is_array($items) || count($items) === 0) app_error('Trace number and received items are required.', 400);
$conn = get_whpokayoke_connection();
$u = current_user();
$h = fetch_one($conn, 'SELECT * FROM RawmatTraceHeader WHERE TraceNo = ?', [$traceNo]);
if (!$h) app_error('Trace number not found.', 404);
$saved = []; $failed = [];
foreach ($items as $item) {
    $lineId = (int)($item['trace_line_id'] ?? 0);
    $receivedLot = trim($item['received_lot_no'] ?? '');
    $receivedQty = trim($item['received_qty'] ?? '');
    $remarks = trim($item['remarks'] ?? '');
    $line = fetch_one($conn, 'SELECT * FROM RawmatTraceLines WHERE TraceLineID = ? AND TraceID = ?', [$lineId, $h['TraceID']]);
    if (!$line) { $failed[] = ['item'=>$item,'reason'=>'Trace line not found']; continue; }
    if ($receivedLot === '' || $receivedQty === '') { $failed[] = ['item'=>$item,'reason'=>'Received lot and qty are required']; continue; }
    $status = 'MATCHED';
    if (strtoupper($receivedLot) !== strtoupper($line['LotNo'])) $status = 'LOT_MISMATCH';
    if ((float)$receivedQty !== (float)$line['IssuedQty']) $status = $status === 'MATCHED' ? 'QTY_VARIANCE' : 'LOT_AND_QTY_VARIANCE';
    $ok = sqlsrv_query($conn, 'UPDATE RawmatTraceLines SET ReceivedLotNo=?, ReceivedQty=?, ReceivedByUsername=?, ReceivedAt=GETDATE(), ReceiverArea=?, Remarks=?, VerificationStatus=? WHERE TraceLineID=?', [$receivedLot, $receivedQty, $u['username'], $u['receiver_area'] ?: '', $remarks, $status, $lineId]);
    if ($ok === false) { $failed[] = ['item'=>$item,'reason'=>sqlsrv_fail_message()]; continue; }
    sqlsrv_query($conn, 'INSERT INTO ReceivingTransactions (TraceNo, ItemCode, PartName, Quantity, LotNo, ReceiverArea, Remarks, ReceivedByUserID, ReceivedByUsername, DeviceHostname, DeviceIPAddress) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$traceNo, $line['ItemCode'], $line['PartName'], $receivedQty, $receivedLot, $u['receiver_area'] ?: 'Receiver', $remarks, $u['id'], $u['username'], client_hostname(), client_ip()]);
    $item['part_name'] = $line['PartName']; $item['item_code'] = $line['ItemCode']; $item['status'] = $status; $saved[] = $item;
}
$pending = fetch_one($conn, "SELECT COUNT(*) AS Cnt FROM RawmatTraceLines WHERE TraceID=? AND VerificationStatus='PENDING_RECEIVE'", [$h['TraceID']]);
$bad = fetch_one($conn, "SELECT COUNT(*) AS Cnt FROM RawmatTraceLines WHERE TraceID=? AND VerificationStatus<>'MATCHED'", [$h['TraceID']]);
$newStatus = ((int)$pending['Cnt'] > 0) ? 'PARTIAL_RECEIVED' : (((int)$bad['Cnt'] > 0) ? 'VARIANCE' : 'MATCHED');
sqlsrv_query($conn, 'UPDATE RawmatTraceHeader SET Status=? WHERE TraceID=?', [$newStatus, $h['TraceID']]);
$pageTitle = 'Receiving Save Complete'; $backUrl = 'pages/receiver/receiver.php';
include __DIR__ . '/../pages/results/save_receive_result.php';
?>
