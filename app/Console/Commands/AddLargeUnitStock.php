<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Console\Command;

/**
 * เพิ่มสต๊อกทุกสินค้าที่เปิดใช้เป็นจำนวนหน่วยใหญ่สุดของสินค้านั้น
 * สร้างเป็นใบปรับสต๊อกรออนุมัติ 1 ใบ ยังไม่ขยับยอดจนกว่าจะกดอนุมัติ
 */
class AddLargeUnitStock extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:add-large-unit-stock
        {--branch=HQ : รหัสสาขาของคลัง}
        {--large-qty=10 : จำนวนหน่วยใหญ่ต่อสินค้า}
        {--dry-run : สรุปโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Prepare a stock adjustment adding N of each product\'s largest unit';

    public function handle(StockAdjustmentService $adjustments): int
    {
        $branch = Branch::where('code', $this->option('branch'))->first();
        if (! $branch?->default_warehouse_location_id) {
            $this->error('ไม่พบสาขาหรือสาขายังไม่มีคลังหลัก');

            return self::FAILURE;
        }
        $largeQty = (float) $this->option('large-qty');
        if ($largeQty <= 0) {
            $this->error('จำนวนหน่วยใหญ่ต้องมากกว่า 0');

            return self::FAILURE;
        }
        $location = $branch->default_warehouse_location_id;
        $balances = StockBalance::where('warehouse_location_id', $location)->pluck('on_hand_qty', 'product_id');

        $items = Product::with('barcodes')->where('is_active', true)->orderBy('sku_code')->get()->map(function (Product $product) use ($balances, $largeQty) {
            $factor = max(1, (float) $product->barcodes->max('unit_factor'));

            return [
                'product_id' => $product->id,
                'sku' => $product->sku_code,
                'factor' => $factor,
                'add' => $factor * $largeQty,
                'counted_qty' => (float) ($balances[$product->id] ?? 0) + $factor * $largeQty,
                'unit_cost' => (float) $product->average_cost,
            ];
        });

        $this->line("คลังสาขา {$branch->code} · สินค้า {$items->count()} รายการ · เพิ่มรวม ".number_format($items->sum('add'), 2).' หน่วยย่อย');
        $this->line('ไม่มีต้นทุน: '.$items->where('unit_cost', 0.0)->count().' รายการ');
        $this->table(['รหัส', 'ตัวคูณหน่วยใหญ่', 'เพิ่ม'], $items->take(10)->map(fn ($i) => [$i['sku'], $i['factor'], $i['add']])->all());

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('สร้างใบปรับสต๊อกรออนุมัติ?', false)) {
            return self::SUCCESS;
        }

        $document = $adjustments->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location,
            'remark' => "เพิ่มสต๊อก {$largeQty} หน่วยใหญ่ทุกสินค้า",
            'items' => $items->map(fn ($i) => ['product_id' => $i['product_id'], 'counted_qty' => $i['counted_qty'], 'unit_cost' => $i['unit_cost']])->all(),
        ]);
        $this->info("สร้าง {$document->doc_number} แล้ว เข้าไปตรวจและกดอนุมัติที่หน้าปรับสต๊อก");

        return self::SUCCESS;
    }
}
