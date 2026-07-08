<?php
require_once __DIR__ . '/../includes/auth.php';
require_role([ROLE_ISSUER, ROLE_ADMIN]);
require_once __DIR__ . '/../api/issuer/lot_balance_lib.php';

if (!function_exists('issuer_wants_json_response')) {
    function issuer_wants_json_response()
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return (($_POST['ajax'] ?? '') === '1') ||
            strpos($accept, 'application/json') !== false ||
            $requestedWith === 'xmlhttprequest';
    }
}

function issuer_save_lot_fail($message)
{
    http_response_code(400);

    if (issuer_wants_json_response()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $message
        ]);
        exit;
    }

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Lot Validation Failed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="alert alert-danger shadow-sm">
        <h5 class="alert-heading">Issuance blocked</h5>
        <p class="mb-3"><?= h($message) ?></p>
        <a class="btn btn-outline-danger" href="../pages/issuer/issuer.php">Back to Issuer</a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

$items = json_decode((string)($_POST['batch_items'] ?? '[]'), true);

if (!is_array($items) || count($items) === 0) {
    issuer_save_lot_fail('No items were submitted.');
}

$erp = get_erp_connection();
$whp = get_whpokayoke_connection();
$validation = issuer_validate_batch_lot_balances($erp, $whp, $items);

if (!$validation['ok']) {
    issuer_save_lot_fail($validation['message'] ?? 'Lot balance validation failed.');
}

require __DIR__ . '/save_issue.php';
?>
