<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sap_cache.php';
require_login();
$code = trim($_GET['code'] ?? '');
$result = null;
if ($code !== '') {
    if (!sap_cache_live_queries_enabled()) {
        $result = [
            'ok' => false,
            'message' => 'Live SAP item checks are disabled for browser requests. Use the normal cached item lookup or run a scheduled CLI cache refresh.'
        ];
    } else {
    $conn = get_erp_connection();
    $result = [];
    $result['oitm_itemcode'] = fetch_one($conn, "SELECT TOP 1 ItemCode, ItemName FROM OITM WHERE LTRIM(RTRIM(ItemCode)) = LTRIM(RTRIM(?))", [$code]);
    $hasCodeBars = fetch_one($conn, "SELECT 1 AS HasColumn FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='OITM' AND COLUMN_NAME='CodeBars'");
    $result['oitm_codebars_available'] = (bool)$hasCodeBars;
    $result['oitm_codebars'] = $hasCodeBars ? fetch_one($conn, "SELECT TOP 1 ItemCode, ItemName, CodeBars FROM OITM WHERE LTRIM(RTRIM(CodeBars)) = LTRIM(RTRIM(?))", [$code]) : null;
    $hasOBCD = fetch_one($conn, "SELECT 1 AS HasTable FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='OBCD'");
    $result['obcd_available'] = (bool)$hasOBCD;
    $result['obcd'] = $hasOBCD ? fetch_one($conn, "SELECT TOP 1 I.ItemCode, I.ItemName, B.BcdCode FROM OBCD B INNER JOIN OITM I ON I.ItemCode=B.ItemCode WHERE LTRIM(RTRIM(B.BcdCode)) = LTRIM(RTRIM(?))", [$code]) : null;
    }
}
?>
<!doctype html><html><head><title>Check SAP Item</title><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><?php navbar(''); ?><div class="container p-3"><div class="card shadow-sm"><div class="card-body"><h4 class="text-primary">Check SAP Item / Barcode</h4><form class="row g-2 mb-3"><div class="col-md-9"><input class="form-control" name="code" value="<?= h($code) ?>" placeholder="Enter scanned value or SAP ItemCode"></div><div class="col-md-3 d-grid"><button class="btn btn-primary">Check</button></div></form><?php if ($code !== ''): ?><pre class="bg-dark text-light p-3 rounded"><?= h(print_r($result, true)) ?></pre><?php endif; ?></div></div></div></body></html>
