<?php

namespace App\Console\Commands;

use App\Models\PriceTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ล้างตารางราคาซ้ำ (production 12 พ.ย.: 19 ตาราง ชื่อสาขาเดียวกันสองชุด รหัส 8–11/20 กับ 1006–1009/1003)
 * และผูกแต่ละตารางกับตาราง BPlus ต้นทาง (bplus_key) เพื่อให้ bplus:sync-prices แมปได้ตรง
 *
 * ค่าเริ่มต้นแสดงผลอย่างเดียว ต้องใส่ --apply ถึงจะเขียน · ไม่ลบตาราง ปิดใช้งานแทน (ประวัติยังอ้างได้)
 */
class NormalizePriceTables extends Command
{
    protected $signature = 'prices:normalize-tables {--apply : บันทึกจริง (ไม่ใส่ = แสดงผลอย่างเดียว)}';

    protected $description = 'ล้างตารางราคาซ้ำ ย้ายการอ้างอิงไปตารางหลัก และผูกรหัสตาราง BPlus';

    /** รหัสตาราง ERP => [BPlus key, รวม VAT?] */
    private const KEYS = [
        '0' => ['0', true], '1' => ['1', false], '2' => ['2', true], '3' => ['3', false], '4' => ['4', true],
        '5' => ['5', true], '7' => ['7', true], '8' => ['1006', true], '9' => ['1007', true], '10' => ['1008', true],
        '11' => ['1009', true], '20' => ['1003', true], '1001' => ['1001', true], '1002' => ['1002', true],
    ];

    /** ตารางซ้ำที่นำเข้าด้วย BPlus key => รหัสตาราง ERP ที่ใช้ต่อ */
    private const DUPLICATES = ['1003' => '20', '1006' => '8', '1007' => '9', '1008' => '10', '1009' => '11'];

    /** ตาราง/คอลัมน์ที่อ้าง price_table_id (นอกจาก product_prices ซึ่งต้องรวมแถวแบบกันชน unique) */
    private const REFERENCES = [
        'branches', 'customer_terms', 'supplier_terms', 'price_tag_templates', 'scheduled_price_changes',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'โหมดบันทึกจริง' : 'โหมดแสดงผล (ยังไม่บันทึก) — ใส่ --apply เพื่อบันทึก');
        $tables = PriceTable::orderBy('id')->get()->keyBy(fn ($t) => (string) $t->code);

        $run = function () use ($tables, $apply) {
            foreach (self::DUPLICATES as $dupCode => $keepCode) {
                $dup = $tables->get($dupCode);
                $keep = $tables->get($keepCode);
                if (! $dup || ! $keep) {
                    continue;
                }
                $this->line("ตารางซ้ำ {$dup->code} {$dup->name} -> ใช้ {$keep->code} {$keep->name}");
                foreach (self::REFERENCES as $table) {
                    // ฐานจริงบางตารางไม่มีคอลัมน์นี้ (production_orders บน production 12 พ.ย.) ข้ามไป
                    if (! Schema::hasColumn($table, 'price_table_id')) {
                        continue;
                    }
                    try {
                        $count = DB::table($table)->where('price_table_id', $dup->id)->count();
                    } catch (\Illuminate\Database\QueryException) {
                        $this->warn("  ข้าม {$table}: อ่านคอลัมน์ price_table_id ไม่ได้");

                        continue;
                    }
                    if ($count) {
                        $this->line("  ย้าย {$table}: {$count} แถว");
                        if ($apply) {
                            DB::table($table)->where('price_table_id', $dup->id)->update(['price_table_id' => $keep->id]);
                        }
                    }
                }
                $moved = 0;
                $kept = 0;
                foreach (DB::table('product_prices')->where('price_table_id', $dup->id)->get() as $row) {
                    $exists = DB::table('product_prices')->where('price_table_id', $keep->id)
                        ->where('product_id', $row->product_id)
                        ->where(fn ($q) => $row->unit_id === null ? $q->whereNull('unit_id') : $q->where('unit_id', $row->unit_id))
                        ->exists();
                    $exists ? $kept++ : $moved++;
                    if ($apply && ! $exists) {
                        DB::table('product_prices')->where('id', $row->id)->update(['price_table_id' => $keep->id]);
                    }
                }
                if ($moved || $kept) {
                    $this->line("  ราคา: ย้าย {$moved} แถว · ตารางที่ใช้ต่อมีอยู่แล้ว (คงของเดิม) {$kept} แถว");
                }
                $this->line('  ปิดใช้งานตารางซ้ำ');
                if ($apply) {
                    $dup->update(['is_active' => false, 'is_default' => false]);
                }
            }

            foreach (self::KEYS as $code => [$key, $vat]) {
                $table = $tables->get((string) $code);
                if ($table && ($table->bplus_key !== $key || $table->vat_included !== $vat)) {
                    $this->line("ผูก {$table->code} {$table->name} -> BPlus {$key}".($vat ? '' : ' (ไม่รวม VAT)'));
                    if ($apply) {
                        $table->update(['bplus_key' => $key, 'vat_included' => $vat]);
                    }
                }
            }
        };

        $apply ? DB::transaction($run) : $run();
        $this->info($apply ? 'บันทึกแล้ว' : 'จบโหมดแสดงผล ไม่มีอะไรถูกเปลี่ยน');

        return self::SUCCESS;
    }
}
