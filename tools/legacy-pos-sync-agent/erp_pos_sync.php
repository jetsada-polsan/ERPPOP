<?php

declare(strict_types=1);

// Office-side agent: SELECT-only access to BPlus MSSQL, then HTTPS push to ERP.
// Run: php erp_pos_sync.php --pos=0005 --date=2026-07-30

$config = require __DIR__ . '/erp_pos_sync.config.php';
$isCli = PHP_SAPI === 'cli';
$options = $isCli ? getopt('', ['pos:', 'date:', 'dry-run']) : $_GET;
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
if (!preg_match('/^\d{4}$/', $posCode) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
    fwrite(STDERR, "Usage: php erp_pos_sync.php --pos=0005 --date=YYYY-MM-DD [--dry-run]\n");
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

$headers = fetchAll($conn, "SELECT h.* FROM H{$tableSuffix} h JOIN BRANCH br ON br.BR_KEY=h.PSH_POS WHERE br.BR_CODE=? AND CAST(h.PSH_DATE AS DATE)=? ORDER BY h.PSH_KEY", [$posCode, $saleDate]);
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
