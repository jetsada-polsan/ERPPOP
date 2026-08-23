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
 *   php tools/legacy-analysis/mssql_readonly.php --file=query.sql --split   # แนะนำสำหรับไฟล์ยาว
 *   php tools/legacy-analysis/mssql_readonly.php --file=schema.sql --dirty   # metadata เท่านั้น
 *
 * ค่าเริ่มต้นคือ READ COMMITTED เสมอ — ลืมใส่ flag แล้วยังได้ตัวเลขที่เชื่อถือได้
 * --dirty เปลี่ยนเป็น READ UNCOMMITTED และจะถูกปฏิเสธทันทีถ้า query แตะตารางธุรกิจ
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

/**
 * คำสั่งตั้งค่า session ที่ยอมให้ผ่านได้ — เป็นค่าของ connection ตัวเอง ไม่แตะข้อมูล
 * และสามอันหลังคือสิ่งที่เจ้าของกำหนดให้ตั้งทุก query อยู่แล้ว
 */
const ALLOWED_SET = [
    '/^set\s+nocount\s+(on|off)$/i',
    '/^set\s+lock_timeout\s+\d+$/i',
    '/^set\s+deadlock_priority\s+low$/i',
    '/^set\s+transaction\s+isolation\s+level\s+read\s+(uncommitted|committed)$/i',
];

/**
 * READ UNCOMMITTED ใช้ได้เฉพาะกับ metadata เท่านั้น
 *
 * dirty read อ่านแถวที่ยังไม่ commit และอาจถูก rollback ทีหลัง ตัวเลขที่ได้จึงเอาไป
 * กระทบยอดไม่ได้ — ห้ามใช้กับจำนวนเอกสาร ยอดเงิน ต้นทุน สต๊อก หรือ UAT ใด ๆ
 * ตรวจโดยดูว่า query แตะตารางธุรกิจหรือไม่ ถ้าแตะจะไม่ยอมให้ใช้ uncommitted
 */
function assertMetadataOnly(string $sql): void
{
    preg_match_all('/\b(?:from|join)\s+([A-Za-z_][A-Za-z0-9_.\[\]]*)/i', stripComments($sql), $matches);
    foreach ($matches[1] as $table) {
        $name = strtolower(str_replace(['[', ']'], '', $table));
        if (str_starts_with($name, 'sys.') || str_starts_with($name, 'information_schema.')) {
            continue;
        }
        fail(
            "READ UNCOMMITTED ใช้กับตารางธุรกิจไม่ได้ (พบ \"{$table}\")\n".
            'ตัวเลขจาก dirty read เอาไปกระทบยอดไม่ได้ — ให้ตัด --dirty ออกเพื่อใช้ READ COMMITTED'
        );
    }
}

/** ตัด comment ออกก่อน เพื่อไม่ให้ซ่อนคำสั่งไว้ใน comment ได้ */
function stripComments(string $sql): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', ' ', $sql);
    $stripped = preg_replace('#--[^\n]*#', ' ', (string) $stripped);

    return trim((string) $stripped);
}

/**
 * ตรวจทีละ statement ไม่ใช่ตรวจทั้งไฟล์รวดเดียว
 * ไฟล์ inventory มีหลาย query ต่อกัน ถ้าตรวจรวมจะไม่รู้ว่าอันไหนผิด
 */
function assertReadOnly(string $sql): void
{
    $stripped = stripComments($sql);
    if ($stripped === '') {
        fail('ไม่มี SQL ให้รัน');
    }

    foreach (FORBIDDEN as $word) {
        $pattern = str_ends_with($word, '_')
            ? '/\b'.preg_quote($word, '/').'/i'
            : '/\b'.preg_quote($word, '/').'\b/i';
        if (preg_match($pattern, $stripped)) {
            fail("พบคำสั่งต้องห้าม \"{$word}\" ใน SQL — ปฏิเสธก่อนส่งไปยังเซิร์ฟเวอร์");
        }
    }

    foreach (preg_split('/;\s*/', $stripped) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        if (preg_match('/^(select|with)\b/i', $statement)) {
            continue;
        }
        foreach (ALLOWED_SET as $allowed) {
            if (preg_match($allowed, $statement)) {
                continue 2;
            }
        }
        $preview = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 60);
        fail("อนุญาตเฉพาะ SELECT, WITH และ SET ที่กำหนดไว้เท่านั้น — พบ: {$preview}");
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

$options = getopt('', ['file:', 'db:', 'json', 'split', 'dirty']);
// getopt ไม่กิน argv จึงต้องหา argument ตัวแรกที่ไม่ใช่ flag เอง
$positional = '';
foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--')) {
        $positional = $argument;
        break;
    }
}
$sql = isset($options['file'])
    ? (string) file_get_contents($options['file'])
    : $positional;
$database = $options['db'] ?? DEFAULT_DATABASE;

assertReadOnly($sql);

// dirty read อนุญาตเฉพาะตอนอ่าน metadata และต้องขอมาเองเท่านั้น
$dirty = isset($options['dirty']);
if ($dirty) {
    assertMetadataOnly($sql);
}

$pdo = new PDO(
    sprintf('dblib:host=%s:%s;dbname=%s;charset=UTF-8', HOST, PORT, $database),
    KEYCHAIN_ACCOUNT,
    password(),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30],
);

// ไม่ล็อกนาน ไม่หน่วง และยอมแพ้ก่อนเสมอถ้าชน — ระบบเดิมยังมีคนใช้งานอยู่
$pdo->exec('SET LOCK_TIMEOUT 5000');
$pdo->exec('SET DEADLOCK_PRIORITY LOW');
$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ '.($dirty ? 'UNCOMMITTED' : 'COMMITTED'));
fwrite(STDERR, 'isolation: READ '.($dirty ? 'UNCOMMITTED (metadata เท่านั้น)' : 'COMMITTED')."\n");

function emit(array $rows, bool $asJson): void
{
    if ($rows === []) {
        echo "(ไม่มีแถว)", PHP_EOL;

        return;
    }
    $rows = array_map(fn (array $row) => array_map(decodeThai(...), $row), $rows);

    if ($asJson) {
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

        return;
    }
    echo implode("\t", array_keys($rows[0])), PHP_EOL;
    foreach ($rows as $row) {
        echo implode("\t", array_map(fn ($v) => $v === null ? 'NULL' : (string) $v, $row)), PHP_EOL;
    }
}

$asJson = isset($options['json']);

/*
 * --split: รันทีละ statement
 *
 * ไฟล์ inventory ยาวและบาง query อ้างชื่อคอลัมน์ที่ยังเป็นข้อสันนิษฐาน
 * ถ้ารันรวดเดียวแล้วพลาดกลางทาง จะเสียผลของ query ก่อนหน้าไปด้วย
 * โหมดนี้จึงรันแยกและรายงานเฉพาะอันที่พลาด แล้วไปต่อ
 */
if (isset($options['split'])) {
    $statements = array_values(array_filter(
        array_map('trim', preg_split('/;\s*\n/', stripComments($sql))),
        fn (string $statement) => $statement !== '',
    ));

    $failed = 0;
    foreach ($statements as $index => $statement) {
        $label = sprintf('--- [%d/%d] %s', $index + 1, count($statements),
            mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 70));
        echo $label, PHP_EOL;
        try {
            emit($pdo->query($statement)->fetchAll(PDO::FETCH_ASSOC), $asJson);
        } catch (PDOException $exception) {
            $failed++;
            echo 'ผิดพลาด: ', $exception->getMessage(), PHP_EOL;
        }
        echo PHP_EOL;
    }
    fwrite(STDERR, sprintf("รันทั้งหมด %d คำสั่ง ผิดพลาด %d\n", count($statements), $failed));
    exit($failed > 0 ? 2 : 0);
}

$statement = $pdo->query($sql);
$resultSet = 0;
do {
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        continue;
    }
    if ($resultSet > 0) {
        echo PHP_EOL;
    }
    emit($rows, $asJson);
    $resultSet++;
} while ($statement->nextRowset());
