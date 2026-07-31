<?php

declare(strict_types=1);

// Office-side agent: SELECT-only access to BPlus MSSQL, then HTTPS push to ERP.
// Run: php erp_pos_sync.php --pos=0005 --date=2026-07-30
// Audit only: php erp_pos_sync.php --summary --date=2026-07-30

$config = require __DIR__ . '/erp_pos_sync.config.php';
$isCli = PHP_SAPI === 'cli';
$options = $isCli ? getopt('', ['pos:', 'date:', 'dry-run', 'summary', 'backoffice-summary']) : $_GET;
if (! $isCli) {
    $provided = (string) ($_SERVER['HTTP_X_LEGACY_AGENT_KEY'] ?? '');
    if (! hash_equals((string) $config['agent_access_key'], $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: application/json; charset=utf-8');
}
$posCode = (string) ($options['pos'] ?? '');
$saleDate = (string) ($options['date'] ?? '');
$summaryOnly = isset($options['summary']) || (! $isCli && ($options['summary'] ?? '') === '1');
$backofficeSummary = isset($options['backoffice-summary']) || (! $isCli && ($options['backoffice_summary'] ?? '') === '1');
if ((! $summaryOnly && ! $backofficeSummary && !preg_match('/^\d{4}$/', $posCode)) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
    fwrite(STDERR, "Usage: php erp_pos_sync.php --pos=0005 --date=YYYY-MM-DD [--dry-run] | --summary --date=YYYY-MM-DD\n");
    exit(2);
}

$tableSuffix = str_replace('-', '', substr($saleDate, 0, 7));
foreach (['H'.$tableSuffix, 'D'.$tableSuffix, 'P'.$tableSuffix] as $table) {
    if (!preg_match('/^[HDP]20\d{4}$/', $table)) {
        throw new RuntimeException('Unsafe monthly table name.');
    }
}

$conn = @odbc_connect($config['odbc_dsn'], $config['odbc_user'], $config['odbc_password']);
if (!$conn) {
    throw new RuntimeException('ODBC connect failed: '.odbc_errormsg());
}

function fetchAll($conn, string $sql, array $params = []): array {
    $stmt = odbc_prepare($conn, $sql);
    if (!$stmt || !odbc_execute($stmt, $params)) {
        throw new RuntimeException('MSSQL SELECT failed: '.odbc_errormsg($conn));
    }
    $rows = [];
    while (($row = odbc_fetch_array($stmt)) !== false) {
        $rows[] = utf8($row);
    }
    return $rows;
}
function utf8(mixed $value): mixed {
    if (is_array($value)) return array_map('utf8', $value);
    if (!is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) return $value;
    $converted = @iconv('CP874', 'UTF-8//IGNORE', $value);
    return $converted === false ? $value : $converted;
}

// This mirrors the legacy POS daily-sales report: active POS receipts only.
// It is deliberately SELECT-only and returns no individual customer data.
if ($summaryOnly) {
    $summaryRows = fetchAll($conn, "
        SELECT
            CASE LEFT(CAST(h.PSH_NO AS VARCHAR(50)), 5)
                WHEN '00010' THEN 'PS1'
                WHEN '00020' THEN 'PS2'
                WHEN '00030' THEN 'PS3'
                WHEN '00040' THEN 'PS4'
                WHEN '00050' THEN 'PS5'
                ELSE LEFT(CAST(h.PSH_NO AS VARCHAR(50)), 5)
            END AS pos_group,
            br.BR_CODE AS branch_code,
            h.PSH_POS AS legacy_branch_key,
            COUNT(*) AS receipt_count,
            SUM(ISNULL(h.PSH_B4_TDSC, 0)) AS before_discount,
            SUM(ISNULL(h.PSH_DSC_PCNTV, 0) + ISNULL(h.PSH_DSC_BAHTV, 0) + ISNULL(h.PSH_CPN_PCNTV, 0)
              + ISNULL(h.PSH_CPN_BAHTV, 0) + ISNULL(h.PSH_TCK_BAHTV, 0) + ISNULL(h.PSH_MBP_BAHTV, 0)) AS discount_amount,
            SUM(ISNULL(h.PSH_N_SV, 0) + ISNULL(h.PSH_N_NV, 0) + ISNULL(h.PSH_N_VAT, 0)) AS gross_amount,
            SUM(ISNULL(h.PSH_N_VAT, 0)) AS vat_amount,
            SUM(ISNULL(h.PSH_N_SV, 0) + ISNULL(h.PSH_N_NV, 0)) AS net_amount,
            SUM(ISNULL(h.PSH_CHARGE, 0)) AS charge_amount
        FROM H{$tableSuffix} h
        LEFT JOIN BRANCH br ON br.BR_KEY = h.PSH_POS
        WHERE h.PSH_TYPE = 1
          AND ISNULL(h.PSH_STATUS, 0) = 0
          AND CAST(h.PSH_DATE AS DATE) = ?
          AND LEFT(CAST(h.PSH_NO AS VARCHAR(50)), 5) IN ('00010','00020','00030','00040','00050')
        GROUP BY LEFT(CAST(h.PSH_NO AS VARCHAR(50)), 5), br.BR_CODE, h.PSH_POS
        ORDER BY pos_group
    ", [$saleDate]);
    @odbc_close($conn);
    echo json_encode(['sale_date' => $saleDate, 'source' => 'legacy_pos_daily_sales', 'rows' => $summaryRows], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

if ($backofficeSummary) {
    $documents = fetchAll($conn, "
        SELECT dt.DT_DOCCODE AS doc_code, dt.DT_PROPERTIES AS doc_properties,
            COUNT(*) AS document_count, SUM(ISNULL(di.DI_AMOUNT, 0)) AS amount
        FROM DOCINFO di
        JOIN DOCTYPE dt ON dt.DT_KEY = di.DI_DT
        WHERE di.DI_ACTIVE = 0
          AND CAST(di.DI_DATE AS DATE) = ?
          AND (dt.DT_DOCCODE IN ('DS', 'DSN') OR dt.DT_PROPERTIES = 207)
        GROUP BY dt.DT_DOCCODE, dt.DT_PROPERTIES
        ORDER BY dt.DT_DOCCODE
    ", [$saleDate]);
    $total = fetchAll($conn, "
        SELECT COUNT(*) AS document_count, SUM(ISNULL(di.DI_AMOUNT, 0)) AS amount
        FROM DOCINFO di
        JOIN DOCTYPE dt ON dt.DT_KEY = di.DI_DT
        WHERE di.DI_ACTIVE = 0
          AND CAST(di.DI_DATE AS DATE) = ?
          AND (dt.DT_DOCCODE IN ('DS', 'DSN') OR dt.DT_PROPERTIES = 207)
    ", [$saleDate])[0] ?? ['document_count' => 0, 'amount' => 0];
    @odbc_close($conn);
    $payload = ['sale_date' => $saleDate, 'documents' => $documents, 'total' => $total];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $config['shared_secret']);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Legacy-Sync-Timestamp: {$timestamp}\r\nX-Legacy-Sync-Signature: {$signature}\r\n", 'content' => $body, 'timeout' => 120, 'ignore_errors' => true]]);
    $response = file_get_contents(rtrim($config['erp_url'], '/').'/api/legacy-backoffice/summary', false, $context);
    if ($response === false) throw new RuntimeException('ERP endpoint did not respond.');
    echo $response.PHP_EOL;
    exit(0);
}

$headers = fetchAll($conn, "SELECT h.* FROM H{$tableSuffix} h
    JOIN BRANCH br ON br.BR_KEY=h.PSH_POS
    WHERE br.BR_CODE=?
      AND CAST(h.PSH_DATE AS DATE)=?
      AND h.PSH_TYPE=1
      AND ISNULL(h.PSH_STATUS, 0)=0
    ORDER BY h.PSH_KEY", [$posCode, $saleDate]);
$keys = array_map(fn(array $row) => (int) $row['PSH_KEY'], $headers);
$items = $payments = [];
if ($keys !== []) {
    $in = implode(',', array_fill(0, count($keys), '?'));
    $items = fetchAll($conn, "SELECT d.*, sm.SKU_CODE AS RESOLVED_SKU_CODE, gm.GOODS_CODE AS RESOLVED_GOODS_CODE FROM D{$tableSuffix} d LEFT JOIN SKUMASTER sm ON sm.SKU_KEY=d.PSD_SKU LEFT JOIN GOODSMASTER gm ON gm.GOODS_KEY=d.PSD_GOODS WHERE d.PSD_PSH IN ({$in}) ORDER BY d.PSD_PSH,d.PSD_KEY", $keys);
    $payments = fetchAll($conn, "SELECT * FROM P{$tableSuffix} WHERE PSP_PSH IN ({$in}) ORDER BY PSP_PSH,PSP_KEY", $keys);
}
$types = [];
foreach (fetchAll($conn, 'SELECT PMT_KEY, PMT_NAME FROM PAYMENTTYPE') as $row) $types[(int) $row['PMT_KEY']] = trim((string) $row['PMT_NAME']);
@odbc_close($conn);

$payload = ['pos_code' => $posCode, 'sale_date' => $saleDate, 'receipts' => $headers, 'items' => $items, 'payments' => $payments, 'payment_type_names' => $types];
if (isset($options['dry-run']) || (! $isCli && ($options['dry_run'] ?? '') === '1')) {
    echo json_encode(['receipts' => count($headers), 'items' => count($items), 'payments' => count($payments)], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, $config['shared_secret']);
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Legacy-Sync-Timestamp: {$timestamp}\r\nX-Legacy-Sync-Signature: {$signature}\r\n", 'content' => $body, 'timeout' => 120, 'ignore_errors' => true]]);
$response = file_get_contents(rtrim($config['erp_url'], '/').'/api/legacy-pos/sync', false, $context);
if ($response === false) throw new RuntimeException('ERP endpoint did not respond.');
echo $response.PHP_EOL;
