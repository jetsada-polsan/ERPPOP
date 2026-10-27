<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * เติมหน่วยนับ ตัวคูณ และ VAT ให้สินค้าที่นำเข้าแล้ว จากตารางหน้า "สินค้าและราคา" ของเว็บระบบเดิม
 *
 * ไฟล์ TSV: sku_code, barcode, cost, price, vat(0/1), unit, unit_sub
 *   unit      ชื่อหน่วยของบาร์โค้ดนั้น เช่น "กล่องx10"
 *   unit_sub  "= 10 แพ็ค" แปลว่า 1 หน่วยนี้ = 10 หน่วยฐาน (แพ็ค); ว่าง = หน่วยฐานเอง
 *
 * หน่วยฐานของสินค้าต้องตรงกันทุกบาร์โค้ด ถ้าขัดกัน (เช่น แถวกิโลกรัมแต่บอก = 5 กิโลกรัม)
 * ข้ามหน่วยของสินค้านั้นทั้งตัวแล้วรายงาน ให้คนตั้งเอง — หน่วยผิดแปลว่าตัดสต๊อกผิด
 * VAT ใช้ได้เสมอเพราะไม่ขึ้นกับหน่วย
 */
class ApplyUnitExport extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:apply-unit-export
        {file : TSV จากหน้า สินค้าและราคา ของระบบเดิม}
        {--dry-run : ตรวจและสรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Set units, pack factors and VAT on imported products from the legacy price page export';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $bySku = [];
        foreach (array_slice(preg_split('/\r\n|\n/', trim(file_get_contents($path))), 1) as $line) {
            [$sku, $barcode, , , $vat, $unit, $sub] = array_pad(explode("\t", $line), 7, '');
            if ($sku !== '' && $barcode !== '') {
                $bySku[trim($sku)][] = ['barcode' => trim($barcode), 'vat' => trim($vat) === '1', 'unit' => trim($unit), 'sub' => trim($sub)];
            }
        }

        $products = Product::whereIn('sku_code', array_keys($bySku))->get()->keyBy('sku_code');
        $barcodes = ProductBarcode::whereIn('barcode', array_merge(...array_map(fn ($rows) => array_column($rows, 'barcode'), array_values($bySku))))
            ->get()->keyBy('barcode');

        $plans = [];
        $conflicts = [];
        $missing = 0;
        foreach ($bySku as $sku => $rows) {
            $product = $products->get($sku);
            if (! $product) {
                $missing++;
                continue;
            }
            $plan = ['product' => $product, 'vat' => $rows[0]['vat'], 'base' => null, 'barcodes' => []];
            $bases = [];
            foreach ($rows as $row) {
                $parsed = $this->parse($row['unit'], $row['sub']);
                if ($parsed === null) {
                    $bases['?'] = true;
                    continue;
                }
                $bases[$parsed['base']] = true;
                if ($barcode = $barcodes->get($row['barcode'])) {
                    if ($barcode->product_id === $product->id) {
                        $plan['barcodes'][] = ['model' => $barcode, 'unit' => $parsed['unit'], 'factor' => $parsed['factor']];
                    }
                }
            }
            if (count($bases) === 1 && ! isset($bases['?'])) {
                $plan['base'] = array_key_first($bases);
            } else {
                $conflicts[] = $sku;
                $plan['barcodes'] = [];
            }
            $plans[] = $plan;
        }

        $packs = array_sum(array_map(fn ($p) => count(array_filter($p['barcodes'], fn ($b) => $b['factor'] != 1)), $plans));
        $this->table(['รายการ', 'จำนวน'], [
            ['สินค้าในไฟล์ที่เจอในระบบ', number_format(count($plans))],
            ['สินค้าในไฟล์ที่ไม่มีในระบบ', number_format($missing)],
            ['สินค้าที่ตั้ง VAT', number_format(count(array_filter($plans, fn ($p) => $p['vat'])))],
            ['สินค้าที่ตั้งหน่วยฐาน', number_format(count(array_filter($plans, fn ($p) => $p['base'] !== null)))],
            ['บาร์โค้ดที่ตั้งหน่วย', number_format(array_sum(array_map(fn ($p) => count($p['barcodes']), $plans)))],
            ['  - เป็นลัง/แพ็ก (ตัวคูณมากกว่า 1)', number_format($packs)],
            ['สินค้าที่หน่วยขัดกัน ข้ามหน่วย (ตั้ง VAT อย่างเดียว)', number_format(count($conflicts))],
        ]);
        if ($conflicts !== []) {
            $this->line('หน่วยขัดกัน ต้องตั้งเอง: '.implode(', ', $conflicts));
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันตั้งหน่วยและ VAT?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plans) {
                foreach ($plans as $plan) {
                    $changes = ['is_vat' => $plan['vat']];
                    if ($plan['base'] !== null) {
                        $changes['base_unit_id'] = $this->unit($plan['base'])->id;
                    }
                    $plan['product']->update($changes);
                    foreach ($plan['barcodes'] as $barcode) {
                        $barcode['model']->update(['unit_id' => $this->unit($barcode['unit'])->id, 'unit_factor' => $barcode['factor']]);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('ตั้งหน่วยและ VAT แล้ว');

        return self::SUCCESS;
    }

    /**
     * "กล่องx10" + "= 10 แพ็ค" -> หน่วย กล่องx10 ตัวคูณ 10 ฐาน แพ็ค; ไม่มี sub -> หน่วยฐานเอง
     *
     * @return array{unit:string, factor:float, base:string}|null
     */
    private function parse(string $unit, string $sub): ?array
    {
        $unit = trim(preg_replace('/\s*คลัง:.*$/u', '', $unit));
        if ($unit === '') {
            return null;
        }
        if ($sub === '') {
            return ['unit' => $unit, 'factor' => 1.0, 'base' => $unit];
        }
        if (preg_match('/^=?\s*([\d.,]+)\s*(.+)$/u', $sub, $match) !== 1) {
            return null;
        }
        $factor = (float) str_replace(',', '', $match[1]);
        $base = trim($match[2]);
        if ($factor <= 0 || $base === $unit) {
            return null; // "กิโลกรัม = 5 กิโลกรัม" อ่านไม่ออกว่าหมายถึงอะไร
        }

        return ['unit' => $unit, 'factor' => $factor, 'base' => $base];
    }

    /** หน่วยใช้ชื่อเป็นตัวระบุ รหัสสร้างจากชื่อ (รหัสยาวได้ 20 ตัว) */
    private function unit(string $name): ProductUnit
    {
        $name = mb_substr($name, 0, 100);

        return ProductUnit::where('name', $name)->first()
            ?? ProductUnit::create(['code' => 'U'.substr(md5($name), 0, 10), 'name' => $name, 'qty_per_base_unit' => 1]);
    }
}
