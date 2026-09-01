<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ตาราง staging ของการนำเข้า POS เก่าถูก "กักบริเวณ" ตามที่เจ้าของโครงการสั่ง 2026-08-23
 *
 * ยังไม่ลบ (ต้อง backup + กำหนด retention/audit ก่อน) แต่ห้ามมีโค้ด, route หรือ job ใหม่
 * มาอ่านหรือเขียนตารางเหล่านี้อีก มิฉะนั้นข้อมูลชุดเก่าจะไหลกลับเข้ามาปนยอดของ ERP ใหม่
 *
 * เทสต์นี้คือด่านกันการเผลอ ไม่ใช่การตรวจข้อมูล
 */
class LegacyPosImportQuarantineTest extends TestCase
{
    private const QUARANTINED_TABLES = [
        'import_batches',
        'import_files',
        'import_errors',
        'imported_receipts',
        'imported_receipt_items',
        'imported_payments',
    ];

    /**
     * ไฟล์ที่ได้รับยกเว้น — อ้างถึงตารางเหล่านี้เพื่อ "ลบทิ้ง" ไม่ใช่เพื่ออ่านกลับเข้าระบบ
     * ซึ่งตรงข้ามกับเจตนาของการกักบริเวณ จึงไม่ถือว่าละเมิด
     */
    private const DISPOSAL_ALLOWED = [
        'app/Console/Commands/ErpResetTransactions.php',
    ];

    public function test_no_application_code_reads_the_quarantined_import_tables(): void
    {
        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            if (in_array(str_replace(base_path().'/', '', $file), self::DISPOSAL_ALLOWED, true)) {
                continue;
            }
            $contents = file_get_contents($file);
            foreach (self::QUARANTINED_TABLES as $table) {
                // Do not confuse a new namespaced table such as
                // accounting_import_batches with the quarantined legacy table.
                $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?!(?:[A-Za-z0-9_]))/';
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file).' -> '.$table;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'พบโค้ดที่อ้างถึงตาราง staging ของการนำเข้า POS เก่า ซึ่งถูกกักบริเวณไว้:',
            ...$offenders,
            'ถ้าจำเป็นต้องอ่านจริง ให้คุยกับเจ้าของโครงการก่อน และอย่าเพิ่มเข้าเส้นทางที่ผู้ใช้เรียกได้',
        ]));
    }

    /** ไฟล์ที่รันจริงในระบบ — migration ไม่นับ เพราะเป็นประวัติการสร้างตารางที่ลบย้อนหลังไม่ได้ */
    private function applicationFiles(): array
    {
        $files = [];
        foreach (['app', 'routes', 'resources/views'] as $directory) {
            $path = base_path($directory);
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
