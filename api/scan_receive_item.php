<?php
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN]);
header('Content-Type: application/json; charset=utf-8');

function json_out($payload)
{
    echo json_encode($payload);
    exit;
}

function extract_receive_token($raw)
{
    $raw = trim((string)$raw);

    if ($raw === '') {
        return '';
    }

    $parts = parse_url($raw);

    if (is_array($parts) && isset($parts['query'])) {
        parse_str($parts['query'], $query);

        if (!empty($query['token'])) {
            return trim((string)$query['token']);
        }
    }

    if (preg_match('/token=([A-Fa-f0-9]+)/', $raw, $m)) {
        return $m[1];
    }

    return $raw;
}

function normalize_area($value)
{
    return strtoupper(trim((string)$value));
}

function update_trace_header_status($conn, $traceId)
{
    $pending = fetch_one(
        $conn,
        "SELECT COUNT(*) AS Cnt
         FROM RawmatTraceLines
         WHERE TraceID = ?
           AND VerificationStatus = 'PENDING_RECEIVE'",
        [$traceId]
    );

    $bad = fetch_one(
        $conn,
        "SELECT COUNT(*) AS Cnt
         FROM RawmatTraceLines
         WHERE TraceID = ?
           AND VerificationStatus <> 'MATCHED'",
        [$traceId]
    );

    $newStatus = ((int)($pending['Cnt'] ?? 0) > 0)
        ? 'PARTIAL_RECEIVED'
        : (((int)($bad['Cnt'] ?? 0) > 0) ? 'VARIANCE' : 'MATCHED');

    sqlsrv_query(
        $conn,
        'UPDATE RawmatTraceHeader SET Status = ? WHERE TraceID = ?',
        [$newStatus, $traceId]
    );

    return $newStatus;
}

$token = extract_receive_token($_POST['token'] ?? $_GET['token'] ?? '');
$mode = strtolower(trim((string)($_POST['mode'] ?? 'scan')));

if ($token === '') {
    json_out([
        'ok' => false,
        'message' => 'Item QR token is required.'
    ]);
}

$conn = get_whpokayoke_connection();
$u = current_user();

$receiverArea = trim((string)($u['receiver_area'] ?? ''));

if ($receiverArea === '') {
    json_out([
        'ok' => false,
        'status' => 'NO_RECEIVER_AREA',
        'message' => 'Your account has no assigned receiver area. QR not accepted.'
    ]);
}

$line = fetch_one(
    $conn,
    'SELECT
         L.*,
         H.TraceNo,
         H.TraceID,
         H.ITRNumber,
         H.DestinationArea
     FROM RawmatTraceLines L
     INNER JOIN RawmatTraceHeader H ON H.TraceID = L.TraceID
     WHERE L.ReceiveToken = ?',
    [$token]
);

if (!$line) {
    json_out([
        'ok' => false,
        'message' => 'Item QR not found or not valid for receiving.'
    ]);
}

$lineId = (int)$line['TraceLineID'];
$traceId = (int)$line['TraceID'];
$traceNo = (string)$line['TraceNo'];

$itemDestination = trim((string)($line['DestinationArea'] ?? ''));

$linePayload = [
    'trace_no' => $traceNo,
    'item_code' => $line['ItemCode'],
    'part_name' => $line['PartName'],
    'lot_no' => $line['LotNo'],
    'issued_lot_no' => $line['LotNo'],
    'issued_qty' => (float)$line['IssuedQty'],
    'receiver_area' => $receiverArea,
    'destination_area' => $itemDestination,
    'status' => $line['VerificationStatus']
];

if ($itemDestination === '') {
    json_out([
        'ok' => false,
        'status' => 'NO_DESTINATION_AREA',
        'message' => 'This trace has no destination area assigned. QR not accepted.',
        'line' => $linePayload
    ]);
}

if (normalize_area($receiverArea) !== normalize_area($itemDestination)) {
    json_out([
        'ok' => false,
        'status' => 'WRONG_RECEIVER_AREA',
        'message' => 'QR not accepted. This item is for ' . $itemDestination . ', but your receiver area is ' . $receiverArea . '.',
        'line' => $linePayload
    ]);
}

if ($mode === 'lookup') {
    json_out([
        'ok' => true,
        'status' => $line['VerificationStatus'],
        'message' => 'Item loaded for exception receiving.',
        'line' => $linePayload
    ]);
}

if (strtoupper((string)$line['VerificationStatus']) !== 'PENDING_RECEIVE') {
    json_out([
        'ok' => false,
        'duplicate' => true,
        'message' => 'This item was already received or closed.',
        'line' => $linePayload
    ]);
}

if ($mode === 'exception') {
    $receivedLot = trim((string)($_POST['received_lot_no'] ?? ''));
    $receivedQty = trim((string)($_POST['received_qty'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($receivedLot === '' || $receivedQty === '' || !is_numeric($receivedQty)) {
        json_out([
            'ok' => false,
            'message' => 'Exception receiving requires actual received lot and numeric quantity.'
        ]);
    }

    if ($remarks === '') {
        json_out([
            'ok' => false,
            'message' => 'Exception remarks are required.'
        ]);
    }

    $status = 'MATCHED';

    if (strtoupper($receivedLot) !== strtoupper((string)$line['LotNo'])) {
        $status = 'LOT_MISMATCH';
    }

    if ((float)$receivedQty !== (float)$line['IssuedQty']) {
        $status = $status === 'MATCHED'
            ? 'QTY_VARIANCE'
            : 'LOT_AND_QTY_VARIANCE';
    }
} else {
    $receivedLot = (string)$line['LotNo'];
    $receivedQty = (string)$line['IssuedQty'];
    $remarks = '';
    $status = 'MATCHED';
}

$ok = sqlsrv_query(
    $conn,
    'UPDATE RawmatTraceLines
     SET ReceivedLotNo = ?,
         ReceivedQty = ?,
         ReceivedByUsername = ?,
         ReceivedAt = GETDATE(),
         ReceivedScanAt = GETDATE(),
         ReceiverArea = ?,
         Remarks = ?,
         VerificationStatus = ?
     WHERE TraceLineID = ?',
    [
        $receivedLot,
        $receivedQty,
        $u['username'],
        $receiverArea,
        $remarks,
        $status,
        $lineId
    ]
);

if ($ok === false) {
    json_out([
        'ok' => false,
        'message' => sqlsrv_fail_message()
    ]);
}

sqlsrv_query(
    $conn,
    'INSERT INTO ReceivingTransactions
        (
            TraceNo,
            ItemCode,
            PartName,
            Quantity,
            LotNo,
            ReceiverArea,
            Remarks,
            ReceivedByUserID,
            ReceivedByUsername,
            DeviceHostname,
            DeviceIPAddress
        )
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [
        $traceNo,
        $line['ItemCode'],
        $line['PartName'],
        $receivedQty,
        $receivedLot,
        $receiverArea,
        $remarks,
        $u['id'],
        $u['username'],
        client_hostname(),
        client_ip()
    ]
);

$headerStatus = update_trace_header_status($conn, $traceId);

json_out([
    'ok' => true,
    'status' => $status,
    'header_status' => $headerStatus,
    'message' => $status === 'MATCHED'
        ? 'Item auto-received as matched.'
        : 'Exception received with variance.',
    'line' => [
        'trace_no' => $traceNo,
        'item_code' => $line['ItemCode'],
        'part_name' => $line['PartName'],
        'issued_lot_no' => $line['LotNo'],
        'issued_qty' => (float)$line['IssuedQty'],
        'received_lot_no' => $receivedLot,
        'received_qty' => (float)$receivedQty,
        'receiver_area' => $receiverArea,
        'destination_area' => $itemDestination
    ]
]);
?>