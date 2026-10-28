<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ข้อ 17: รายงานสินค้าชื่อเดียวกันแต่คนละรหัส เป็น CSV ให้คนตัดสินว่าจะรวมหรือไม่
 * อ่านอย่างเดียว ไม่รวมให้อัตโนมัติ — ชื่อซ้ำอาจเป็นคนละขนาดบรรจุที่ตั้งชื่อไม่ครบ
 * เทียบชื่อแบบตัดช่องว่างทั้งหมด เพื่อจับ "สินค้า  A" กับ "สินค้า A"
 */
class ReportDuplicateProductNames extends Command
{
    protected $signature = 'erp:report-duplicate-product-names
        {--output= : ไฟล์ CSV (ค่าเริ่มต้น storage/app/reports/duplicate-product-names-วันที่.csv)}
        {--active-only : เฉพาะสินค้าที่เปิดใช้งาน}';

    protected $description = 'Write a CSV of products that share a name under different codes';

    public function handle(): int
    {
        $products = DB::table('products as p')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.product_category_id')
            ->whereNull('p.deleted_at')
            ->when($this->option('active-only'), fn ($q) => $q->where('p.is_active', true))
            ->orderBy('p.sku_code')
            ->get(['p.sku_code', 'p.name_th', 'p.default_price', 'p.is_active', 'c.code as category']);

        $groups = $products->groupBy(fn ($p) => mb_strtolower(preg_replace('/\s+/u', '', (string) $p->name_th)))
            ->filter(fn ($group) => $group->count() > 1);

        $path = $this->option('output') ?: storage_path('app/reports/duplicate-product-names-'.now()->format('Ymd').'.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['กลุ่ม', 'รหัสสินค้า', 'ชื่อสินค้า', 'ประเภท', 'ราคาหลัก', 'สถานะ', 'ตัดสินใจ (รวมเข้ากับรหัส / เก็บแยก)']);
        $groupNo = 0;
        foreach ($groups as $group) {
            $groupNo++;
            foreach ($group as $product) {
                fputcsv($handle, [$groupNo, $product->sku_code, $product->name_th, $product->category,
                    number_format((float) $product->default_price, 2, '.', ''), $product->is_active ? 'เปิด' : 'ปิด', '']);
            }
        }
        fclose($handle);

        $this->info(sprintf('ชื่อซ้ำ %s กลุ่ม (%s รหัส) → %s', number_format($groups->count()), number_format($groups->flatten(1)->count()), $path));

        return self::SUCCESS;
    }
}
