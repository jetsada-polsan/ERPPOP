<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ข้อ 17: ชื่อภาษาไทยที่เพี้ยน (mojibake) ในแฟ้มหลัก
 *
 * ชื่อผู้จำหน่ายบางรายถูกอ่าน UTF-8 เป็น Windows-874 แล้วเก็บซ้ำ ไบต์ 0x80–0x9F หายไปตอนนั้น
 * (ก ข ค ง จ ช ฯลฯ) แปลงกลับด้วยสูตรไม่ได้ครบ จึงเอาชื่อจริงจากระบบเดิมมาแทนตามรหัส
 * แก้เฉพาะแถวที่ยังเพี้ยนอยู่ ชื่อที่คนแก้มือแล้วไม่ทับ
 *
 * หน่วยนับเพี้ยนชุดเก่า (ก่อนนำเข้าใหม่) ไม่มีสินค้าหรือบาร์โค้ดใช้แล้ว ลบได้ด้วย --drop-unused-units
 */
class RepairMasterNames extends Command
{
    use RequiresFreshBackup;

    /**
     * ไทย UTF-8 ที่ถูกอ่านเป็น 874 ทุกตัวอักษรขึ้นต้นด้วย "เธ" หรือ "เน" ข้อความเพี้ยนจึงมี "เธ" ถี่มาก
     * คำไทยปกติแทบไม่มี "เธ" ซ้ำ (เช่น "เธอ") — ต้องมี "เธ" 2 ครั้งขึ้นไปและรวม "เธ"/"เน" 3 ครั้งขึ้นไป
     * กันชื่อจริงอย่าง "เนื้อ" หรือ "เนสท์เล่" โดนแก้
     */
    private static function garbled(?string $text): bool
    {
        $text = (string) $text;

        return mb_substr_count($text, 'เธ') + mb_substr_count($text, 'เน') >= 3 && mb_substr_count($text, 'เธ') >= 2;
    }

    protected $signature = 'erp:repair-master-names
        {--suppliers= : TSV รหัส<TAB>ชื่อ จากระบบเดิม}
        {--drop-unused-units : ลบหน่วยนับชื่อเพี้ยนที่ไม่มีอะไรใช้}
        {--dry-run : สรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Replace garbled Thai supplier names from a legacy export and drop unused garbled units';

    public function handle(): int
    {
        $supplierFixes = [];
        if ($file = $this->option('suppliers')) {
            if (! is_file($file)) {
                $this->error("ไม่พบไฟล์ {$file}");

                return self::FAILURE;
            }
            $names = [];
            foreach (preg_split('/\r\n|\n/', trim(file_get_contents($file))) as $line) {
                [$code, $name] = array_map('trim', array_pad(explode("\t", $line, 2), 2, ''));
                if ($code !== '' && $name !== '' && ! self::garbled($name)) {
                    $names[$code] = $name;
                }
            }
            foreach (DB::table('suppliers')->whereIn('code', array_keys($names))->get(['id', 'code', 'name_th']) as $supplier) {
                if (self::garbled($supplier->name_th)) {
                    $supplierFixes[] = ['id' => $supplier->id, 'code' => $supplier->code, 'name' => mb_substr($names[$supplier->code], 0, 250)];
                }
            }
        }

        $stillGarbled = DB::table('suppliers')->get(['code', 'name_th'])
            ->filter(fn ($s) => self::garbled($s->name_th))
            ->reject(fn ($s) => in_array($s->code, array_column($supplierFixes, 'code'), true))
            ->pluck('code')->all();

        $units = $this->option('drop-unused-units')
            ? DB::table('product_units as u')
                ->whereNotExists(fn ($q) => $q->from('products')->whereColumn('products.base_unit_id', 'u.id'))
                ->whereNotExists(fn ($q) => $q->from('product_barcodes')->whereColumn('product_barcodes.unit_id', 'u.id'))
                ->get(['u.id', 'u.code', 'u.name'])
                ->filter(fn ($u) => self::garbled($u->name))
                ->pluck('id')->all()
            : [];

        $this->table(['ผู้จำหน่าย', 'ชื่อใหม่'], array_map(fn ($f) => [$f['code'], $f['name']], $supplierFixes));
        $this->line('ผู้จำหน่ายที่ยังเพี้ยนและไม่มีในไฟล์: '.($stillGarbled === [] ? '-' : implode(', ', $stillGarbled)));
        if ($this->option('drop-unused-units')) {
            $this->line('หน่วยนับชื่อเพี้ยนที่ไม่มีใครใช้ จะลบ: '.number_format(count($units)));
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if ($supplierFixes === [] && $units === []) {
            $this->info('ไม่มีอะไรต้องแก้');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันแก้ชื่อ '.count($supplierFixes).' ราย และลบหน่วย '.count($units).' รายการ?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($supplierFixes, $units) {
                foreach ($supplierFixes as $fix) {
                    DB::table('suppliers')->where('id', $fix['id'])->update(['name_th' => $fix['name'], 'updated_at' => now()]);
                }
                DB::table('product_units')->whereIn('id', $units)->delete();
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('แก้ชื่อผู้จำหน่าย '.count($supplierFixes).' ราย ลบหน่วยนับเพี้ยน '.count($units).' รายการ');

        return self::SUCCESS;
    }
}
