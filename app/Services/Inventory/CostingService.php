<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Moving weighted average for product analysis and actual FIFO/FEFO lot cost
 * for posted COGS and inventory valuation.
 */
class CostingService
{
    /**
     * อัปเดตต้นทุนเฉลี่ยเมื่อรับสินค้าเข้า (เรียกก่อน increment สต๊อกจริง เพื่อใช้
     * ยอดคงเหลือ "ก่อนรับ" ในการถัวเฉลี่ย)
     */
    public function recordPurchase(int $productId, int|float|string $qty, int|float|string $unitCost): void
    {
        if (DecimalMath::compare($qty, 0) <= 0) {
            return;
        }

        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (! $product) {
            return;
        }

        $onHand = StockBalance::where('product_id', $productId)->sum('on_hand_qty');
        $oldCost = $product->average_cost;

        // ของเดิมติดลบ/ศูนย์ = ใช้ราคาซื้อล่าสุดเป็นต้นทุน
        if (DecimalMath::compare($onHand, 0) <= 0) {
            $newCost = DecimalMath::compare($unitCost, 0) > 0 ? $unitCost : $oldCost;
        } else {
            $oldValue = DecimalMath::multiply($onHand, $oldCost);
            $receivedValue = DecimalMath::multiply($qty, $unitCost);
            $newCost = DecimalMath::divide(
                DecimalMath::add($oldValue, $receivedValue),
                DecimalMath::add($onHand, $qty),
            );
        }

        $product->update([
            'average_cost' => DecimalMath::round($newCost, DecimalMath::COST_SCALE),
            'last_purchase_cost' => DecimalMath::round($unitCost, DecimalMath::COST_SCALE),
            'last_purchase_cost_at' => now(),
        ]);
    }

    public function recordManufacturedReceipt(int $productId, int|float|string $qty, int|float|string $unitCost): void
    {
        if (DecimalMath::compare($qty, 0) <= 0) {
            return;
        }
        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (! $product) {
            return;
        }
        $onHand = StockBalance::where('product_id', $productId)->sum('on_hand_qty');
        $oldCost = $product->average_cost;
        $newCost = DecimalMath::compare($onHand, 0) <= 0
            ? $unitCost
            : DecimalMath::divide(
                DecimalMath::add(
                    DecimalMath::multiply($onHand, $oldCost),
                    DecimalMath::multiply($qty, $unitCost),
                ),
                DecimalMath::add($onHand, $qty),
            );
        $product->update(['average_cost' => DecimalMath::round($newCost, DecimalMath::COST_SCALE)]);
    }

    /**
     * ต้นทุนต่อหน่วยของ "รายการที่ตัดออกจริงครั้งนี้" คำนวณจากมูลค่า Lot ที่ FIFO
     * ตัดจริง (allocations ที่ FifoStockService::issue() คืนมา) ไม่ใช่ต้นทุนเฉลี่ยสะสม
     * ของสินค้า ทำให้ COGS ตรงกับมูลค่า Lot ที่หายไปจริง สอดคล้องกับการตีมูลค่าสต๊อก
     * ปลายงวดของ InventoryCostCloseService (ก็อิง stock_lots เช่นกัน)
     * ส่วนที่ตัดไม่ได้จาก Lot จริง (อนุญาตสต๊อกติดลบ) ใช้ average_cost ปัจจุบันแทน
     * เพราะไม่มี Lot จริงรองรับให้อ้างอิง
     *
     * @param  Collection<int, array{lot: StockLot, qty: int|float|string}>  $allocations
     */
    public function unitCostFromAllocations(Collection $allocations, int|float|string $requestedQty, int|float|string $fallbackUnitCost): string
    {
        if (DecimalMath::compare($requestedQty, 0) <= 0) {
            return DecimalMath::round(0, DecimalMath::COST_SCALE);
        }

        $allocatedQty = DecimalMath::sum($allocations->pluck('qty'));
        $allocatedValue = DecimalMath::sum($allocations->map(
            fn ($allocation) => DecimalMath::multiply($allocation['qty'], $allocation['lot']->unit_cost)
        ));
        $shortQty = DecimalMath::compare($requestedQty, $allocatedQty) > 0
            ? DecimalMath::subtract($requestedQty, $allocatedQty)
            : '0';

        return DecimalMath::divide(
            DecimalMath::add($allocatedValue, DecimalMath::multiply($shortQty, $fallbackUnitCost)),
            $requestedQty,
        );
    }

    public function purchaseUnitCost(Product $product, int|float|string $enteredPrice, bool $pricesIncludeVat, bool $claimInputVat, int|float|string $vatRate): string
    {
        if (! $product->is_vat || DecimalMath::compare($vatRate, 0) <= 0) {
            return DecimalMath::round($enteredPrice, DecimalMath::COST_SCALE);
        }

        if ($pricesIncludeVat) {
            return DecimalMath::round(
                $claimInputVat
                    ? DecimalMath::divide(DecimalMath::multiply($enteredPrice, 100), DecimalMath::add(100, $vatRate))
                    : $enteredPrice,
                DecimalMath::COST_SCALE,
            );
        }

        return DecimalMath::round(
            $claimInputVat
                ? $enteredPrice
                : DecimalMath::divide(DecimalMath::multiply($enteredPrice, DecimalMath::add(100, $vatRate)), 100),
            DecimalMath::COST_SCALE,
        );
    }

    // ต้นทุนขายรวมของเอกสาร = ผลรวม (จำนวน x ต้นทุนเฉลี่ย) ของทุกรายการในเอกสาร
    public function cogsForDocument(Document $document): float
    {
        $document->loadMissing('stockDocument.items.product');
        if (! $document->stockDocument) {
            return 0.0;
        }

        $values = [];
        foreach ($document->stockDocument->items as $item) {
            $qty = DecimalMath::of($item->qty)->abs();
            $values[] = $item->cost_amount !== null
                ? DecimalMath::of($item->cost_amount)->abs()
                : $qty->multipliedBy(DecimalMath::of($item->unit_cost ?? $item->product->average_cost ?? 0));
        }

        return (float) DecimalMath::sum($values, DecimalMath::DISPLAY_MONEY_SCALE);
    }
}
