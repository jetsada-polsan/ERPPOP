<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ลดแฟ้มสินค้าให้เหลือหมวดละ N ตัว โดยปิดการใช้งานตัวที่เกิน - ไม่ลบแถว
 *
 * ปิดแทนลบเพราะสินค้าถูกอ้างจากราคา บาร์โค้ด โปรโมชั่น และเอกสารเก่า ลบแล้วหน้าเหล่านั้น
 * จะพัง ส่วนตัวที่ปิดแล้วเปิดกลับได้จากหน้าสินค้า หรือรันคำสั่งนี้ด้วย --restore
 *
 * เลือกเก็บตามลำดับ: ยอดขายย้อนหลัง 365 วัน -> มีบาร์โค้ด -> รหัสสินค้า
 * สินค้าที่ยังมีสต๊อกคงเหลือจะถูกเก็บไว้เสมอแม้เกินโควตา (ปิดแล้วของในคลังจะขายไม่ได้)
 */
class PruneProducts extends Command
{
    protected $signature = 'erp:prune-products
        {--per-category=100 : จำนวนสินค้าที่เก็บไว้ต่อหมวด}
        {--dry-run : แสดงผลโดยไม่แก้ข้อมูล}
        {--restore : เปิดใช้งานสินค้าที่คำสั่งนี้เคยปิดกลับทั้งหมด}';

    protected $description = 'Keep the top N active products per category and deactivate the rest';

    /** ใส่ไว้ใน note เพื่อให้ --restore รู้ว่าตัวไหนถูกปิดโดยคำสั่งนี้ */
    public const MARK = '[pruned-by-erp:prune-products]';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('restore')) {
            $query = Product::where('is_active', false)->where('note', 'like', '%'.self::MARK.'%');
            $count = $query->count();
            if ($dryRun) {
                $this->info("DRY RUN: จะเปิดใช้งานกลับ {$count} รายการ");

                return self::SUCCESS;
            }
            $query->get()->each(fn (Product $product) => $product->update([
                'is_active' => true,
                'note' => trim(str_replace(self::MARK, '', (string) $product->note)) ?: null,
            ]));
            $this->info("เปิดใช้งานกลับ {$count} รายการ");

            return self::SUCCESS;
        }

        $keepPerCategory = max(1, (int) $this->option('per-category'));

        $sold = DB::table('stock_movements')
            ->where('movement_type', 'out')
            ->where('movement_date', '>=', now()->subDays(365)->toDateString())
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(qty) as sold_qty')
            ->pluck('sold_qty', 'product_id');
        $withBarcode = DB::table('product_barcodes')->where('is_active', true)->distinct()->pluck('product_id')->flip();
        $withStock = DB::table('stock_balances')->where('on_hand_qty', '>', 0)->distinct()->pluck('product_id')->flip();
        $categoryNames = DB::table('product_categories')->pluck('name_th', 'id');

        $products = Product::where('is_active', true)->get(['id', 'sku_code', 'name_th', 'product_category_id', 'note']);

        $toDeactivate = collect();
        $rows = [];
        foreach ($products->groupBy(fn ($p) => $p->product_category_id ?? 0) as $categoryId => $group) {
            $ranked = $group->sortBy([
                fn ($a, $b) => ((float) ($sold[$b->id] ?? 0)) <=> ((float) ($sold[$a->id] ?? 0)),
                fn ($a, $b) => (int) isset($withBarcode[$b->id]) <=> (int) isset($withBarcode[$a->id]),
                fn ($a, $b) => strcmp((string) $a->sku_code, (string) $b->sku_code),
            ])->values();

            $extra = $ranked->slice($keepPerCategory);
            $keptForStock = $extra->filter(fn ($p) => isset($withStock[$p->id]));
            $off = $extra->reject(fn ($p) => isset($withStock[$p->id]));
            $toDeactivate = $toDeactivate->merge($off);

            $rows[] = [
                $categoryId ? ($categoryNames[$categoryId] ?? "#{$categoryId}") : '(ไม่ระบุหมวด)',
                number_format($group->count()),
                number_format(min($group->count(), $keepPerCategory) + $keptForStock->count()),
                number_format($keptForStock->count()),
                number_format($off->count()),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));
        $this->table(['หมวด', 'ใช้งานอยู่', 'คงไว้', 'คงไว้เพราะมีสต๊อก', 'จะปิดใช้งาน'], $rows);
        $this->line(sprintf('รวม: ใช้งานอยู่ %s · จะปิดใช้งาน %s', number_format($products->count()), number_format($toDeactivate->count())));

        if ($dryRun) {
            $this->info('DRY RUN: ยังไม่มีการแก้ข้อมูล');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toDeactivate) {
            foreach ($toDeactivate->chunk(500) as $chunk) {
                foreach ($chunk as $product) {
                    $product->update([
                        'is_active' => false,
                        'note' => trim(((string) $product->note).' '.self::MARK),
                    ]);
                }
            }
        });

        $this->info(sprintf('ปิดใช้งาน %s รายการแล้ว (เปิดกลับได้ด้วย --restore)', number_format($toDeactivate->count())));

        return self::SUCCESS;
    }
}
