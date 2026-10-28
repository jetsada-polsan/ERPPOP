<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * แก้ราคาหลักของสินค้าที่ได้ราคาลัง/แพ็กมาแทนราคาหน่วยฐาน
 *
 * erp:import-popstar-export ใช้แถวแรกเป็นราคาหลักเมื่อไม่มีบาร์โค้ดเท่ารหัสสินค้า แถวแรกมักเป็นลัง
 * (101115: ราคาหลัก 745 = หิ้ว*5 แต่ต่อ กก. คือ 149) บาร์โค้ดที่ไม่มีราคาของตัวเอง เช่นรหัส 6 หลัก
 * จึงคิดราคาลังต่อ 1 หน่วยฐาน
 *
 * ราคาหลักใหม่ = ราคาบาร์โค้ดตัวคูณ 1 ถ้ามีราคาเดียว; ต้นทุน = ต้นทุนเดิม / ตัวคูณของบาร์โค้ดที่ราคาเท่าราคาหลักเดิม
 * สินค้าที่บาร์โค้ดตัวคูณ 1 มีหลายราคา ข้ามและรายงานให้คนตั้งเอง
 */
class FixBaseUnitPrices extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:fix-base-unit-prices
        {--dry-run : สรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Reset product prices that hold a pack price instead of the base-unit price';

    public function handle(): int
    {
        $barcodes = ProductBarcode::get(['product_id', 'unit_factor', 'price'])->groupBy('product_id');
        $fixes = [];
        $ambiguous = [];
        foreach (Product::get(['id', 'sku_code', 'default_price', 'average_cost', 'is_active']) as $product) {
            $own = $barcodes->get($product->id, collect());
            $basePrices = $own->filter(fn ($b) => (float) $b->unit_factor == 1.0 && (float) $b->price > 0)
                ->map(fn ($b) => round((float) $b->price, 2))->unique()->values();
            $current = round((float) $product->default_price, 2);
            if ($basePrices->isEmpty() || $basePrices->contains($current)) {
                continue;
            }
            if ($basePrices->count() > 1) {
                $ambiguous[] = $product->sku_code;
                continue;
            }
            $pack = $own->first(fn ($b) => (float) $b->unit_factor > 1 && round((float) $b->price, 2) === $current);
            $fixes[] = [
                'product' => $product,
                'price' => $basePrices->first(),
                'cost' => $pack ? round((float) $product->average_cost / (float) $pack->unit_factor, 4) : null,
            ];
        }

        $active = count(array_filter($fixes, fn ($f) => $f['product']->is_active));
        $this->table(['รายการ', 'จำนวน'], [
            ['สินค้าที่จะแก้ราคาหลัก', number_format(count($fixes))],
            ['  - เปิดใช้งานอยู่', number_format($active)],
            ['  - แก้ต้นทุนเป็นต่อหน่วยฐานด้วย', number_format(count(array_filter($fixes, fn ($f) => $f['cost'] !== null)))],
            ['ราคาหน่วยฐานมีหลายราคา ต้องตั้งเอง', number_format(count($ambiguous))],
        ]);
        foreach (array_slice($fixes, 0, 10) as $fix) {
            $this->line(sprintf('  %s: %s -> %s', $fix['product']->sku_code, number_format((float) $fix['product']->default_price, 2), number_format($fix['price'], 2)));
        }
        if ($ambiguous !== []) {
            $this->line('ต้องตั้งเอง: '.implode(', ', $ambiguous));
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันแก้ราคาหลัก '.count($fixes).' รายการ?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($fixes) {
                foreach ($fixes as $fix) {
                    $fix['product']->update(array_filter([
                        'default_price' => $fix['price'],
                        'average_cost' => $fix['cost'],
                    ], fn ($value) => $value !== null));
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('แก้ราคาหลัก '.count($fixes).' รายการแล้ว');

        return self::SUCCESS;
    }
}
