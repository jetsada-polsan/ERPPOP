<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ตั้งราคาหลักตามรายการที่เจ้าของกำหนด (ตัวที่ erp:fix-base-unit-prices ไม่เดาให้)
 *
 * ไฟล์ TSV: sku_code, price — ถ้าราคาหลักเดิมเป็นราคาลังของบาร์โค้ดตัวคูณ > 1
 * ต้นทุนก็เป็นต้นทุนลังด้วย จึงหารด้วยตัวคูณนั้นให้เป็นต้นทุนหน่วยฐาน
 */
class SetProductPrices extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:set-product-prices
        {file : TSV sku_code, price}
        {--dry-run : สรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Set product base prices from an owner-approved list';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $prices = [];
        foreach (array_slice(preg_split('/\r\n|\n/', trim(file_get_contents($path))), 1) as $line) {
            [$sku, $price] = array_map('trim', array_pad(explode("\t", $line), 2, ''));
            if ($sku !== '' && is_numeric($price) && (float) $price > 0) {
                $prices[$sku] = (float) $price;
            }
        }

        $products = Product::whereIn('sku_code', array_keys($prices))->get()->keyBy('sku_code');
        $missing = array_diff(array_keys($prices), $products->keys()->all());
        if ($missing !== []) {
            $this->error('ไม่พบสินค้า: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $plans = [];
        foreach ($products as $sku => $product) {
            $pack = ProductBarcode::where('product_id', $product->id)->where('unit_factor', '>', 1)->get()
                ->first(fn ($b) => round((float) $b->price, 2) === round((float) $product->default_price, 2));
            $plans[] = [
                'product' => $product,
                'price' => $prices[$sku],
                'cost' => $pack ? round((float) $product->average_cost / (float) $pack->unit_factor, 4) : (float) $product->average_cost,
            ];
        }

        $this->table(['รหัส', 'สินค้า', 'ราคาเดิม', 'ราคาใหม่', 'ต้นทุนเดิม', 'ต้นทุนใหม่'], array_map(fn ($p) => [
            $p['product']->sku_code, mb_substr($p['product']->name_th, 0, 30),
            number_format((float) $p['product']->default_price, 2), number_format($p['price'], 2),
            number_format((float) $p['product']->average_cost, 2), number_format($p['cost'], 2),
        ], $plans));

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันตั้งราคา '.count($plans).' รายการ?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plans) {
                foreach ($plans as $plan) {
                    $plan['product']->update(['default_price' => $plan['price'], 'average_cost' => $plan['cost']]);
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('ตั้งราคา '.count($plans).' รายการแล้ว');

        return self::SUCCESS;
    }
}
