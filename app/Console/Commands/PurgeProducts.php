<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ลบแฟ้มสินค้าทั้งหมด (ลบจริง ไม่ใช่ซ่อน) เพื่อนำเข้าชุดใหม่แทน
 *
 * ทำไมต้องลบจริง: ตัวนำเข้าที่ศูนย์ตั้งต้นระบบปฏิเสธรหัสเดิมแม้สินค้าจะถูกซ่อน (withTrashed)
 * และบาร์โค้ดห้ามซ้ำ สินค้าเก่าที่ค้างอยู่จะกันการนำเข้าชุดใหม่
 *
 * ด่านกัน:
 *   1. ธุรกรรม (บิล สต๊อก ใบสั่งซื้อ ฯลฯ) ต้องไม่อ้างสินค้าอยู่ — ล้างด้วย erp:reset-transactions ก่อน
 *   2. ต้องมี backup สด (ไม่เกิน 2 ชม. checksum ผ่าน) และพิมพ์ชื่อฐานข้อมูลยืนยัน
 *   3. ลบข้อมูลประกอบสินค้า (บาร์โค้ด ราคา ยอดคงเหลือ สูตรผลิต ฯลฯ) ก่อนตัวสินค้า ใน transaction เดียว
 *
 * ไม่แตะ: หมวดสินค้า หน่วยนับ ตารางราคา (หัวตาราง) ลูกค้า ผู้ขาย
 */
class PurgeProducts extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:purge-products
        {--confirm-database= : ชื่อฐานข้อมูลที่ต้องการลบ ต้องพิมพ์ให้ตรง}
        {--dry-run : แสดงสิ่งที่จะลบโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Delete every product and its catalog data so a new product file can be imported';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        // ตารางที่มี FK ชี้มาที่ products แยกเป็นธุรกรรม (ต้องว่าง) กับข้อมูลประกอบ (ลบไปด้วย)
        $references = $this->foreignKeysTo('products');
        $transactional = ErpResetTransactions::TRANSACTIONAL;
        $blocking = [];
        $catalog = [];
        foreach ($references as [$table, $column]) {
            $rows = DB::table($table)->whereNotNull($column)->count();
            if (in_array($table, $transactional, true)) {
                if ($rows > 0) {
                    $blocking[] = [$table, $column, number_format($rows)];
                }
                continue;
            }
            $catalog[$table][] = $column;
        }

        if ($blocking !== []) {
            $this->error('หยุด: ยังมีธุรกรรมอ้างสินค้าอยู่ ลบสินค้าแล้วเอกสารเหล่านี้จะชี้ไปที่ของที่ไม่มีอยู่');
            $this->table(['ตาราง', 'คอลัมน์', 'แถว'], $blocking);
            $this->line("ล้างธุรกรรมก่อน: php artisan erp:reset-transactions --confirm-database={$database}");

            return self::FAILURE;
        }

        // ข้อมูลประกอบที่มีตารางอื่นชี้มาอีกชั้น (เช่น สูตรผลิต <- รายการสูตร) ต้องลบลูกก่อนแม่
        $order = $this->deleteOrder(array_keys($catalog));
        if ($outside = $this->outsideReferences($order)) {
            $this->error('หยุด: มีตารางอื่นอ้างข้อมูลประกอบสินค้าที่จะลบ');
            $this->table(['ตารางที่อ้าง', 'อ้างไปที่', 'แถว'], $outside);

            return self::FAILURE;
        }

        $rows = [];
        foreach ($order as $table) {
            $count = $this->scopedCount($table, $catalog[$table] ?? []);
            if ($count > 0) {
                $rows[] = [$table, number_format($count)];
            }
        }
        $products = DB::table('products')->count();
        $rows[] = ['products (รวมที่ซ่อนไว้)', number_format($products)];

        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['ตาราง', 'แถวที่จะลบ'], $rows);
        $this->line('คงไว้: หมวดสินค้า หน่วยนับ หัวตารางราคา ลูกค้า ผู้ขาย');

        if ($dryRun) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("ยืนยันลบสินค้า {$products} รายการ และข้อมูลประกอบทั้งหมด?", false)) {
            $this->info('ยกเลิก ไม่มีอะไรถูกลบ');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($order, $catalog) {
                foreach ($order as $table) {
                    $columns = $catalog[$table] ?? [];
                    $query = DB::table($table);
                    // ตารางที่ชี้มาที่สินค้าโดยตรง ลบเฉพาะแถวที่ผูกสินค้า; ตารางลูกชั้นถัดไปลบทั้งหมดที่เหลือ
                    if ($columns !== []) {
                        $query->where(fn ($w) => array_map(fn ($c) => $w->orWhereNotNull($c), $columns));
                    }
                    $query->delete();
                }
                DB::table('products')->delete();
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกลบ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("ลบสินค้า {$products} รายการและข้อมูลประกอบแล้ว — นำเข้าชุดใหม่ที่หน้า ศูนย์ตั้งต้นระบบ");

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string, 1:string}> [ตาราง, คอลัมน์] ที่มี FK ชี้มาที่ $target */
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

    /**
     * ลำดับลบ: ตารางที่ถูกตารางอื่นในชุดชี้มา ต้องลบหลังตารางที่ชี้
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function deleteOrder(array $tables): array
    {
        $ordered = [];
        $pending = $tables;
        for ($guard = 0; $pending !== [] && $guard < 50; $guard++) {
            foreach ($pending as $index => $table) {
                $stillReferenced = collect($this->foreignKeysTo($table))
                    ->contains(fn ($ref) => in_array($ref[0], $pending, true) && $ref[0] !== $table);
                if (! $stillReferenced) {
                    $ordered[] = $table;
                    unset($pending[$index]);
                }
            }
        }

        return array_merge($ordered, array_values($pending));
    }

    /** @return array<int, array<int, string>> ตารางนอกชุดที่มีข้อมูลชี้มาที่ตารางในชุด */
    private function outsideReferences(array $tables): array
    {
        $blocking = [];
        foreach ($tables as $table) {
            foreach ($this->foreignKeysTo($table) as [$referrer, $column]) {
                if (in_array($referrer, $tables, true) || $referrer === 'products') {
                    continue;
                }
                $rows = DB::table($referrer)->whereNotNull($column)->count();
                if ($rows > 0) {
                    $blocking[] = [$referrer, $table, number_format($rows)];
                }
            }
        }

        return $blocking;
    }

    /** @param  array<int, string>  $columns */
    private function scopedCount(string $table, array $columns): int
    {
        $query = DB::table($table);
        if ($columns !== []) {
            $query->where(fn ($w) => array_map(fn ($c) => $w->orWhereNotNull($c), $columns));
        }

        return $query->count();
    }
}
