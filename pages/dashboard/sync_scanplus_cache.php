<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
set_time_limit(300);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sap_cache.php';
require_once __DIR__ . '/../../includes/scanplus_lookup.php';

require_login();

header('Content-Type: text/plain; charset=utf-8');

echo "ScanPlus cache sync started...\n";
flush();

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');

if ($currentRole !== 'admin') {
    http_response_code(403);
    exit("Access denied. Current role: " . ($currentUser['role'] ?? 'unknown') . "\n");
}

$conn = get_whpokayoke_connection();

if (!$conn) {
    exit("Failed to connect to WH Pokayoke database.\n");
}

echo "Connected to WH Pokayoke database.\n";
flush();

if (!scanplus_cache_ensure($conn)) {
    exit("RawmatTraceScanPlusCache table is not available and could not be created.\n");
}

$limit = 500;

try {
    $rows = fetch_all(
        $conn,
        "SELECT TOP {$limit}
            H.SAP_IT_DocEntry AS HeaderSAPDocEntry,
            L.SAP_IT_DocEntry AS LineSAPDocEntry,
            L.SAP_IT_LineNum,
            L.ItemCode,
            L.LotNo,
            H.CreatedAt,
            L.TraceLineID
         FROM RawmatTraceHeader H
         INNER JOIN RawmatTraceLines L ON H.TraceID = L.TraceID
         WHERE ISNULL(L.SAP_IT_DocEntry, ISNULL(H.SAP_IT_DocEntry, 0)) <> 0
         ORDER BY H.CreatedAt DESC, L.TraceLineID DESC"
    );
} catch (Throwable $e) {
    exit("Main trace query failed: " . $e->getMessage() . "\n");
}

echo "Trace rows found: " . count($rows) . "\n";
flush();

$scanRefs = [];
$seen = [];

foreach ($rows as $row) {
    $docEntry = $row['LineSAPDocEntry'] ?? $row['HeaderSAPDocEntry'] ?? 0;
    $lineNum = $row['SAP_IT_LineNum'] ?? null;
    $itemCode = $row['ItemCode'] ?? '';
    $lotNo = $row['LotNo'] ?? '';

    if (scanplus_key($docEntry, $lineNum, $itemCode) === '') {
        continue;
    }

    $dedupeKey = (string)$docEntry . '|' . (string)$lineNum . '|' . (string)$itemCode . '|' . (string)$lotNo;
    if (isset($seen[$dedupeKey])) {
        continue;
    }
    $seen[$dedupeKey] = true;

    $scanRefs[] = [
        'doc_entry' => $docEntry,
        'line_num' => $lineNum,
        'item_code' => $itemCode,
        'lot_no' => $lotNo
    ];
}

echo "Valid unique ScanPlus refs: " . count($scanRefs) . "\n";
flush();

if (empty($scanRefs)) {
    exit("No ScanPlus references found. Nothing to sync.\n");
}

try {
    if (!sap_cache_live_queries_enabled()) {
        exit("Live SAP queries are disabled for browser requests. Run tools/sync_sap_cache.php or a scheduled CLI cache job instead.\n");
    }

    $erpConn = get_erp_connection();
    if (!$erpConn) {
        exit("Failed to connect to ERP database.\n");
    }

    echo "Connected to ERP database. Looking up ScanPlus data...\n";
    flush();

    $start = microtime(true);
    $scanplusRows = scanplus_lookup_by_itr_lines($erpConn, $scanRefs);
    echo "ScanPlus lookup completed in " . round(microtime(true) - $start, 3) . " sec. Results: " . count($scanplusRows) . "\n";
    flush();
} catch (Throwable $e) {
    exit("ScanPlus lookup failed: " . $e->getMessage() . "\n");
}

$updated = 0;
$missing = 0;

foreach ($scanRefs as $ref) {
    $scanKey = scanplus_key($ref['doc_entry'], $ref['line_num'], $ref['item_code']);
    $scanLotKey = scanplus_lot_key($ref['doc_entry'], $ref['line_num'], $ref['item_code'], $ref['lot_no']);
    $genericScan = $scanKey !== '' ? ($scanplusRows[$scanKey] ?? null) : null;

    if ($scanLotKey !== '') {
        $scan = $scanplusRows[$scanLotKey] ?? null;

        if (!$scan && strtoupper(trim((string)($genericScan['scan_status'] ?? ''))) === 'NOT RECEIVED IN SAP') {
            $scan = $genericScan;
        }
    } else {
        $scan = $genericScan;
    }

    if (!$scan) {
        $missing++;
        continue;
    }

    try {
        scanplus_cache_write($conn, $ref, $scan);
        $updated++;
    } catch (Throwable $e) {
        echo "Cache upsert failed for DocEntry {$ref['doc_entry']}, Item {$ref['item_code']}, Lot {$ref['lot_no']}: " . $e->getMessage() . "\n";
        flush();
    }
}

echo "ScanPlus cache sync completed.\n";
echo "Updated rows: {$updated}\n";
echo "No matching ScanPlus data: {$missing}\n";
