<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * เติมบาร์โค้ดที่ระบบเดิมใช้อยู่แต่ไฟล์นำเข้าไม่มี (ส่วนใหญ่คือรหัส 6 หลักที่แคชเชียร์พิมพ์ขาย)
 *
 * ไฟล์ TSV: sku_code, barcode, unit, factor — ใช้บาร์โค้ดตามที่ระบบเดิมมีจริง ไม่สร้างเลขใหม่
 * ไม่ตั้งราคาบาร์โค้ด (POS ใช้ราคาหลักของสินค้า) ยกเว้นรหัสชั่ง 801: POS Python คิดน้ำหนักจาก
 * ราคาบาร์โค้ด PLU ตรงๆ ถ้าว่างจะขายไม่ได้ จึงใส่ราคาหลัก (ราคาต่อหน่วยชั่ง) ให้; ไม่แตะบาร์โค้ดที่มีอยู่แล้ว
 * ถ้าบาร์โค้ดชนกับสินค้าอื่น หยุดทั้งชุด เพราะสแกนแล้วจะได้สินค้าผิดตัว
 */
class AddMissingBarcodes extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:add-missing-barcodes
        {file : TSV sku_code, barcode, unit, factor}
        {--dry-run : สรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Add barcodes the legacy system used that the import file left out';

    public function handle(BarcodePolicy $policy): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $rows = [];
        foreach (array_slice(preg_split('/\r\n|\n/', trim(file_get_contents($path))), 1) as $line) {
            [$sku, $barcode, $unit, $factor] = array_map('trim', array_pad(explode("\t", $line), 4, ''));
            if ($sku !== '' && $barcode !== '') {
                $rows[] = compact('sku', 'barcode', 'unit') + ['factor' => (float) $factor > 0 ? (float) $factor : 1.0];
            }
        }

        $products = Product::whereIn('sku_code', array_column($rows, 'sku'))->pluck('id', 'sku_code');
        $prices = Product::whereIn('sku_code', array_column($rows, 'sku'))->pluck('default_price', 'id');
        $existing = ProductBarcode::whereIn('barcode', array_column($rows, 'barcode'))->pluck('product_id', 'barcode');

        $add = [];
        $already = 0;
        $clashes = [];
        $noProduct = 0;
        foreach ($rows as $row) {
            $productId = $products[$row['sku']] ?? null;
            if ($productId === null) {
                $noProduct++;
            } elseif (isset($existing[$row['barcode']])) {
                $existing[$row['barcode']] === $productId ? $already++ : $clashes[] = "{$row['barcode']} ({$row['sku']})";
            } else {
                $add[] = $row + ['product_id' => $productId, 'type' => $this->type($policy, $row['barcode'])];
            }
        }

        $types = array_count_values(array_column($add, 'type'));
        $this->table(['รายการ', 'จำนวน'], [
            ['บาร์โค้ดในไฟล์', number_format(count($rows))],
            ['จะเพิ่ม', number_format(count($add))],
            ...array_map(fn ($type, $count) => ["  - {$type}", number_format($count)], array_keys($types), $types),
            ['มีอยู่แล้วกับสินค้าเดียวกัน (ข้าม)', number_format($already)],
            ['ไม่พบสินค้า (ข้าม)', number_format($noProduct)],
            ['ชนกับสินค้าอื่น', number_format(count($clashes))],
        ]);

        if ($clashes !== []) {
            $this->error('หยุด: บาร์โค้ดชนกับสินค้าอื่น '.implode(', ', array_slice($clashes, 0, 20)));

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันเพิ่มบาร์โค้ด '.count($add).' ตัว?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($add, $prices) {
                foreach ($add as $row) {
                    ProductBarcode::create([
                        'product_id' => $row['product_id'], 'barcode' => $row['barcode'], 'barcode_type' => $row['type'],
                        'unit_id' => $this->unit($row['unit'])->id, 'unit_factor' => $row['factor'], 'is_active' => true,
                        'price' => $row['type'] === BarcodePolicy::SCALE_PLU ? $prices[$row['product_id']] : null,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('เพิ่มบาร์โค้ด '.count($add).' ตัวแล้ว');

        return self::SUCCESS;
    }

    private function type(BarcodePolicy $policy, string $barcode): string
    {
        return match (true) {
            $policy->check(BarcodePolicy::SCALE_PLU, $barcode)['ok'] => BarcodePolicy::SCALE_PLU,
            $policy->check(BarcodePolicy::EAN13_STANDARD, $barcode)['ok'] => BarcodePolicy::EAN13_STANDARD,
            default => BarcodePolicy::CUSTOM,
        };
    }

    /** หน่วยใช้ชื่อเป็นตัวระบุ เหมือน erp:apply-unit-export */
    private function unit(string $name): ProductUnit
    {
        $name = mb_substr($name !== '' ? $name : 'หน่วย', 0, 100);

        return ProductUnit::where('name', $name)->first()
            ?? ProductUnit::create(['code' => 'U'.substr(md5($name), 0, 10), 'name' => $name, 'qty_per_base_unit' => 1]);
    }
}
