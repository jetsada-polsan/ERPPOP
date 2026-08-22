<?php

/**
 * ตัวรัน SELECT กับ SQL Server ระบบเดิม แบบอ่านอย่างเดียวโดยบังคับด้วยโค้ด
 * ---------------------------------------------------------------------
 * ใช้กับ 192.168.88.200 ซึ่งเป็นฐาน production ของระบบเก่า
 *
 * มาตรการที่บังคับไว้ในตัวสคริปต์ ไม่ได้อาศัยความระมัดระวังของคนรัน:
 *   1. ทุก statement ต้องขึ้นต้นด้วย SELECT หรือ WITH เท่านั้น อย่างอื่นถูกปฏิเสธก่อนส่ง
 *   2. คำสั่งเขียนทุกชนิด (INSERT/UPDATE/DELETE/MERGE/CREATE/ALTER/DROP/TRUNCATE/
 *      EXEC/BACKUP/RESTORE/DBCC/ATTACH/DETACH...) ถูกปฏิเสธแม้เขียนซ้อนอยู่กลาง query
 *   3. ตั้ง LOCK_TIMEOUT / DEADLOCK_PRIORITY LOW / READ UNCOMMITTED ทุกครั้งก่อนอ่าน
 *      เพื่อไม่ให้ไปล็อกหรือหน่วงงานจริงของระบบเดิม
 *
 * รหัสผ่าน: อ่านจาก macOS Keychain เท่านั้น ไม่รับทาง argument, ไม่เขียนลงไฟล์,
 * ไม่ echo ออกมา และไม่มีอยู่ใน repo — เจ้าของเป็นคนใส่เข้า Keychain เอง
 *
 *   security add-generic-password -a jetsada -s erppop-legacy-mssql -w
 *
 * วิธีใช้:
 *   php tools/legacy-analysis/mssql_readonly.php "SELECT @@SERVERNAME AS s, DB_NAME() AS d"
 *   php tools/legacy-analysis/mssql_readonly.php --file=query.sql [--db=ชื่อฐาน] [--json]
 */

const KEYCHAIN_SERVICE = 'erppop-legacy-mssql';
const KEYCHAIN_ACCOUNT = 'jetsada';
const HOST = '192.168.88.200';
const PORT = '1433';
const DEFAULT_DATABASE = 'master';

/** คำสั่งที่ห้ามปรากฏใน SQL ไม่ว่าตำแหน่งไหน */
const FORBIDDEN = [
    'insert', 'update', 'delete', 'merge', 'truncate',
    'create', 'alter', 'drop', 'grant', 'revoke', 'deny',
    'exec', 'execute', 'sp_', 'xp_', 'dbcc',
    'backup', 'restore', 'shrink', 'reindex', 'reconfigure',
    'attach', 'detach', 'kill', 'checkpoint', 'bulk',
    'openrowset', 'opendatasource', 'into',
];

function fail(string $message): never
{
    fwrite(STDERR, "หยุด: {$message}\n");
    exit(1);
}

/** ตรวจว่าเป็น SELECT ล้วนจริง — ตัด comment ออกก่อนเพื่อไม่ให้ซ่อนคำสั่งไว้ในนั้น */
function assertReadOnly(string $sql): void
{
    $stripped = preg_replace('#/\*.*?\*/#s', ' ', $sql);
    $stripped = preg_replace('#--[^\n]*#', ' ', (string) $stripped);
    $stripped = trim((string) $stripped);

    if ($stripped === '') {
        fail('ไม่มี SQL ให้รัน');
    }
    if (! preg_match('/^\s*(select|with)\b/i', $stripped)) {
        fail('อนุญาตเฉพาะ SELECT หรือ WITH เท่านั้น');
    }
    foreach (FORBIDDEN as $word) {
        $pattern = str_ends_with($word, '_')
            ? '/\b'.preg_quote($word, '/').'/i'
            : '/\b'.preg_quote($word, '/').'\b/i';
        if (preg_match($pattern, $stripped)) {
            fail("พบคำสั่งต้องห้าม \"{$word}\" ใน SQL — ปฏิเสธก่อนส่งไปยังเซิร์ฟเวอร์");
        }
    }
}

function password(): string
{
    $command = sprintf(
        'security find-generic-password -a %s -s %s -w 2>/dev/null',
        escapeshellarg(KEYCHAIN_ACCOUNT),
        escapeshellarg(KEYCHAIN_SERVICE),
    );
    $secret = trim((string) shell_exec($command));
    if ($secret === '') {
        fail(
            "ไม่พบรหัสผ่านใน Keychain\n".
            "ให้เจ้าของรันคำสั่งนี้เองในเทอร์มินัล แล้วพิมพ์รหัสผ่านตอนที่ถูกถาม:\n\n".
            '  security add-generic-password -a '.KEYCHAIN_ACCOUNT.' -s '.KEYCHAIN_SERVICE." -w\n\n".
            "รหัสผ่านจะอยู่ใน Keychain เท่านั้น ไม่ผ่านแชต ไม่ถูกบันทึกลงไฟล์ และไม่ขึ้น GitHub"
        );
    }

    return $secret;
}

/** แปลงข้อความจาก CP874 (ภาษาไทยของ BPlus) เป็น UTF-8 */
function decodeThai(mixed $value): mixed
{
    if (! is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    $converted = @iconv('CP874', 'UTF-8//IGNORE', $value);

    return $converted === false ? $value : $converted;
}

$options = getopt('', ['file:', 'db:', 'json']);
$sql = $options['file'] ?? null
    ? (string) file_get_contents($options['file'])
    : (string) ($argv[1] ?? '');
$database = $options['db'] ?? DEFAULT_DATABASE;

assertReadOnly($sql);

$pdo = new PDO(
    sprintf('dblib:host=%s:%s;dbname=%s;charset=UTF-8', HOST, PORT, $database),
    KEYCHAIN_ACCOUNT,
    password(),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30],
);

// ไม่ล็อก ไม่หน่วง และยอมแพ้ก่อนเสมอถ้าชน — ระบบเดิมยังมีคนใช้งานอยู่
$pdo->exec('SET LOCK_TIMEOUT 5000');
$pdo->exec('SET DEADLOCK_PRIORITY LOW');
$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

$statement = $pdo->query($sql);
$resultSet = 0;
do {
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        continue;
    }
    $rows = array_map(fn (array $row) => array_map(decodeThai(...), $row), $rows);

    if (isset($options['json'])) {
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        continue;
    }

    if ($resultSet > 0) {
        echo PHP_EOL;
    }
    echo implode("\t", array_keys($rows[0])), PHP_EOL;
    foreach ($rows as $row) {
        echo implode("\t", array_map(fn ($v) => $v === null ? 'NULL' : (string) $v, $row)), PHP_EOL;
    }
    $resultSet++;
} while ($statement->nextRowset());
