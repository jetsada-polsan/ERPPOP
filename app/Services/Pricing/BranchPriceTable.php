<?php

namespace App\Services\Pricing;

use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use Illuminate\Support\Collection;

/**
 * ราคาตามตารางของสาขา (เจ้าของ 12 พ.ย.: POS แต่ละสาขาขายราคาของสาขาตัวเอง)
 *
 * ลำดับราคาที่ทั้ง PosController::products และ PosPricingGuard ใช้ร่วมกัน:
 *   ตารางของสาขา (หน่วยที่ยิง -> หน่วยฐาน) -> ราคาบาร์โค้ด -> ตารางหลัก -> default_price
 * สาขาที่ไม่ได้ผูกตาราง หรือผูกกับตารางหลัก -> ไม่มีแถวสาขา ราคาเท่าเดิมทุกบาท
 */
class BranchPriceTable
{
    /** ตารางของสาขาที่ใช้ override ได้ (ไม่ใช่ตารางหลัก และยังเปิดใช้งาน) */
    public function tableId(?int $branchId): ?int
    {
        if (! $branchId) {
            return null;
        }
        $tableId = Branch::whereKey($branchId)->value('price_table_id');
        if (! $tableId) {
            return null;
        }
        $table = PriceTable::whereKey($tableId)->first(['id', 'is_default', 'is_active']);

        return $table && ! $table->is_default && $table->is_active ? (int) $table->id : null;
    }

    /** @return Collection<int, Collection<int, ProductPrice>> แถวราคาของสาขา จัดกลุ่มตามสินค้า */
    public function rows(array $productIds, ?int $branchId): Collection
    {
        $tableId = $this->tableId($branchId);
        if (! $tableId || $productIds === []) {
            return collect();
        }

        return ProductPrice::where('price_table_id', $tableId)->whereIn('product_id', $productIds)
            ->where('is_active', true)->get(['product_id', 'unit_id', 'price'])->groupBy('product_id');
    }

    /**
     * ราคาสาขาของบรรทัดหนึ่ง
     * - ยิงบาร์โค้ดหน่วย U: แถวหน่วย U, ถ้าเป็นหน่วยฐาน (ตัวคูณ 1) ใช้แถวหน่วยฐานได้
     * - ไม่ได้ยิงบาร์โค้ด: แถวหน่วยฐาน (unit_id ว่าง) -> แถวหน่วยเล็กสุด
     */
    public function priceFor(?Collection $rowsForProduct, ?int $unitId = null, mixed $unitFactor = 1, ?int $baseUnitId = null): ?float
    {
        if (! $rowsForProduct || $rowsForProduct->isEmpty()) {
            return null;
        }
        if ($unitId !== null) {
            $exact = $rowsForProduct->firstWhere('unit_id', $unitId);
            if ($exact) {
                return (float) $exact->price;
            }
            $isBase = (float) $unitFactor === 1.0 || ($baseUnitId !== null && $unitId === $baseUnitId);
            if (! $isBase) {
                return null;
            }
        }
        $base = $rowsForProduct->firstWhere('unit_id', null)
            ?? ($baseUnitId !== null ? $rowsForProduct->firstWhere('unit_id', $baseUnitId) : null);
        if ($base) {
            return (float) $base->price;
        }
        if ($unitId !== null) {
            return null;
        }
        $factors = ProductUnit::whereIn('id', $rowsForProduct->pluck('unit_id')->filter())->pluck('qty_per_base_unit', 'id');
        $smallest = $rowsForProduct->sortBy(fn ($row) => (float) ($factors[$row->unit_id] ?? 1))->first();

        return $smallest ? (float) $smallest->price : null;
    }
}
