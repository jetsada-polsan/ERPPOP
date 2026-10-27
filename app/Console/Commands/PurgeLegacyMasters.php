<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ล้างลูกค้า พนักงานขาย และร่องรอยที่ผูกกับระบบเดิม (BPlus/MSSQL) ก่อนนำเข้าชุดใหม่
 *
 * - ลูกค้า: ลบจริงทั้งหมด (รวมที่ซ่อนไว้) พร้อมที่อยู่ ผู้ติดต่อ สมาชิก CRM ที่ผูกอยู่
 * - พนักงานขาย: ลบทั้งหมด ผู้ใช้/เครื่อง POS/เขตขายที่ชี้อยู่ถูกตั้งเป็นว่าง (ตัวผู้ใช้ยังอยู่)
 * - ระบบเดิม: ตารางจับคู่ legacy และค่าตั้งค่าสรุปจาก backoffice เดิม
 *
 * ด่านกันเหมือน erp:purge-products: ธุรกรรมต้องว่าง, backup สด, พิมพ์ชื่อฐานยืนยัน, transaction เดียว
 * ไม่แตะ: ผู้ขาย (ซัพพลายเออร์) ผู้ใช้ สาขา ผังบัญชี รายงาน ประเภทสินค้า
 */
class PurgeLegacyMasters extends Command
{
    use RequiresFreshBackup;

    /** ตารางที่ล้างทั้งตาราง เพราะเป็นข้อมูลจับคู่กับระบบเดิมล้วน */
    private const LEGACY_TABLES = ['legacy_table_mappings', 'legacy_mappings'];

    /** ค่าตั้งค่าที่ดึงมาจากระบบเดิม */
    private const LEGACY_SETTINGS = 'legacy_backoffice_%';

    protected $signature = 'erp:purge-legacy-masters
        {--confirm-database= : ชื่อฐานข้อมูลที่ต้องการลบ ต้องพิมพ์ให้ตรง}
        {--dry-run : แสดงสิ่งที่จะลบโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Delete every customer, salesman and legacy-system link before importing new master data';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();

        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $customerRefs = $this->foreignKeysTo('customers');
        $salesmanRefs = $this->foreignKeysTo('salesmen');

        $blocking = [];
        foreach ([...$customerRefs, ...$salesmanRefs] as [$table, $column]) {
            if (in_array($table, ErpResetTransactions::TRANSACTIONAL, true)
                && ($rows = DB::table($table)->whereNotNull($column)->count()) > 0) {
                $blocking[] = [$table, $column, number_format($rows)];
            }
        }
        if ($blocking !== []) {
            $this->error('หยุด: ยังมีธุรกรรมอ้างลูกค้า/พนักงานขายอยู่');
            $this->table(['ตาราง', 'คอลัมน์', 'แถว'], $blocking);
            $this->line("ล้างธุรกรรมก่อน: php artisan erp:reset-transactions --confirm-database={$database}");

            return self::FAILURE;
        }

        // ข้อมูลประกอบลูกค้า (ที่อยู่ ผู้ติดต่อ สมาชิก CRM) ลบตาม; ตารางที่ชี้พนักงานขายตั้งเป็นว่าง
        $customerChildren = array_values(array_filter($customerRefs, fn ($ref) => ! in_array($ref[0], ErpResetTransactions::TRANSACTIONAL, true)));
        $salesmanLinks = array_values(array_filter($salesmanRefs, fn ($ref) => ! in_array($ref[0], ErpResetTransactions::TRANSACTIONAL, true)));

        // ชั้นหลาน เช่น บัญชี LINE ของสมาชิกที่ผูกลูกค้า: ลบตามเฉพาะแถวที่ชี้ไปยังแถวที่จะลบ
        // ถ้าเป็นตารางธุรกรรมแปลว่ายังไม่ได้ล้าง ให้หยุด
        $childTables = array_column($customerChildren, 0);
        $grandchildren = [];
        foreach ($customerChildren as [$table, $parentColumn]) {
            foreach ($this->foreignKeysTo($table) as [$referrer, $column]) {
                if (in_array($referrer, $childTables, true)) {
                    continue;
                }
                $scoped = DB::table($referrer)->whereIn($column, DB::table($table)->whereNotNull($parentColumn)->select('id'))->count();
                if ($scoped > 0 && in_array($referrer, ErpResetTransactions::TRANSACTIONAL, true)) {
                    $this->error("หยุด: {$referrer}.{$column} ยังอ้าง {$table} อยู่ {$scoped} แถว");

                    return self::FAILURE;
                }
                $grandchildren[] = [$referrer, $column, $table, $parentColumn];
            }
        }

        // ตารางลูกที่ถูกตารางลูกอื่นชี้ (เช่น โอกาสขาย <- กิจกรรม) ต้องลบทีหลัง
        usort($customerChildren, fn ($a, $b) => $this->referencedBy($a[0], $childTables) <=> $this->referencedBy($b[0], $childTables));

        $rows = [];
        foreach ($grandchildren as [$table, $column, $parent, $parentColumn]) {
            $rows[] = ["{$table} (ลบ ที่ผูก {$parent})", number_format(DB::table($table)->whereIn($column, DB::table($parent)->whereNotNull($parentColumn)->select('id'))->count())];
        }
        foreach ($customerChildren as [$table, $column]) {
            $rows[] = ["{$table} (ลบ)", number_format(DB::table($table)->whereNotNull($column)->count())];
        }
        $rows[] = ['customers (ลบ รวมที่ซ่อนไว้)', number_format(DB::table('customers')->count())];
        foreach ($salesmanLinks as [$table, $column]) {
            $rows[] = ["{$table}.{$column} (ตั้งเป็นว่าง)", number_format(DB::table($table)->whereNotNull($column)->count())];
        }
        $rows[] = ['salesmen (ลบ)', number_format(DB::table('salesmen')->count())];
        foreach (self::LEGACY_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $rows[] = ["{$table} (ล้าง)", number_format(DB::table($table)->count())];
            }
        }
        $rows[] = ['app_settings legacy_backoffice_* (ลบ)', number_format(DB::table('app_settings')->where('key', 'like', self::LEGACY_SETTINGS)->count())];

        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['รายการ', 'แถว'], $rows);
        $this->line('คงไว้: ผู้ขาย(ซัพพลายเออร์) ผู้ใช้ สาขา ผังบัญชี รายงาน ประเภทสินค้า');
        $this->line('ค่า MSSQL_SOURCE_* / LEGACY_POS_SYNC_SECRET ใน .env ต้องลบเองบนเซิร์ฟเวอร์');

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('ยืนยันลบลูกค้า พนักงานขาย และข้อมูลระบบเดิมทั้งหมด?', false)) {
            $this->info('ยกเลิก ไม่มีอะไรถูกลบ');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($grandchildren, $customerChildren, $salesmanLinks) {
                foreach ($grandchildren as [$table, $column, $parent, $parentColumn]) {
                    DB::table($table)->whereIn($column, DB::table($parent)->whereNotNull($parentColumn)->select('id'))->delete();
                }
                foreach ($customerChildren as [$table, $column]) {
                    DB::table($table)->whereNotNull($column)->delete();
                }
                DB::table('customers')->delete();
                foreach ($salesmanLinks as [$table, $column]) {
                    DB::table($table)->whereNotNull($column)->update([$column => null]);
                }
                DB::table('salesmen')->delete();
                foreach (self::LEGACY_TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
                DB::table('app_settings')->where('key', 'like', self::LEGACY_SETTINGS)->delete();
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกลบ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('ล้างลูกค้า พนักงานขาย และข้อมูลระบบเดิมแล้ว');

        return self::SUCCESS;
    }

    private function referencedBy(string $table, array $tables): int
    {
        return collect($this->foreignKeysTo($table))->filter(fn ($ref) => in_array($ref[0], $tables, true))->count();
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function foreignKeysTo(string $target): array
    {
        $found = [];
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
            try {
                $foreignKeys = Schema::getForeignKeys($table);
            } catch (Throwable) {
                continue;
            }
            foreach ($foreignKeys as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === $target && $table !== $target) {
                    $found[] = [$table, $foreignKey['columns'][0]];
                }
            }
        }

        return $found;
    }
}
