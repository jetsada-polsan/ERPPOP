<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLot;
use Illuminate\Support\Collection;

/**
 * ต้นทุนเฉลี่ยถ่วงน้ำหนัก (moving weighted average) ต่อสินค้า.
 * รับซื้อ -> อัปเดต average_cost; ขาย -> คิดต้นทุนขาย = จำนวน x average_cost.
 */
class CostingService
{
    /**
     * อัปเดตต้นทุนเฉลี่ยเมื่อรับสินค้าเข้า (เรียกก่อน increment สต๊อกจริง เพื่อใช้
     * ยอดคงเหลือ "ก่อนรับ" ในการถัวเฉลี่ย)
     */
    public function recordPurchase(int $productId, float $qty, float $unitCost): void
    {
        if ($qty <= 0) {
            return;
        }

        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (! $product) {
            return;
        }

        $onHand = (float) StockBalance::where('product_id', $productId)->sum('on_hand_qty');
        $oldCost = (float) $product->average_cost;

        // ของเดิมติดลบ/ศูนย์ = ใช้ราคาซื้อล่าสุดเป็นต้นทุน
        if ($onHand <= 0) {
            $newCost = $unitCost > 0 ? $unitCost : $oldCost;
        } else {
            $newCost = ($onHand * $oldCost + $qty * $unitCost) / ($onHand + $qty);
        }

        $product->update([
            'average_cost' => round($newCost, 4),
            'last_purchase_cost' => round($unitCost, 4),
            'last_purchase_cost_at' => now(),
        ]);
    }

    public function recordManufacturedReceipt(int $productId, float $qty, float $unitCost): void
    {
        if ($qty <= 0) {
            return;
        }
        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (! $product) {
            return;
        }
        $onHand = (float) StockBalance::where('product_id', $productId)->sum('on_hand_qty');
        $oldCost = (float) $product->average_cost;
        $newCost = $onHand <= 0
            ? $unitCost
            : (($onHand * $oldCost) + ($qty * $unitCost)) / ($onHand + $qty);
        $product->update(['average_cost' => round($newCost, 4)]);
    }

    /**
     * ต้นทุนต่อหน่วยของ "รายการที่ตัดออกจริงครั้งนี้" คำนวณจากมูลค่า Lot ที่ FIFO
     * ตัดจริง (allocations ที่ FifoStockService::issue() คืนมา) ไม่ใช่ต้นทุนเฉลี่ยสะสม
     * ของสินค้า ทำให้ COGS ตรงกับมูลค่า Lot ที่หายไปจริง สอดคล้องกับการตีมูลค่าสต๊อก
     * ปลายงวดของ InventoryCostCloseService (ก็อิง stock_lots เช่นกัน)
     * ส่วนที่ตัดไม่ได้จาก Lot จริง (อนุญาตสต๊อกติดลบ) ใช้ average_cost ปัจจุบันแทน
     * เพราะไม่มี Lot จริงรองรับให้อ้างอิง
     *
     * @param  Collection<int, array{lot: StockLot, qty: float}>  $allocations
     */
    public function unitCostFromAllocations(Collection $allocations, float $requestedQty, float $fallbackUnitCost): float
    {
        if ($requestedQty <= 0) {
            return 0.0;
        }

        $allocatedQty = (float) $allocations->sum('qty');
        $allocatedValue = (float) $allocations->sum(fn ($a) => (float) $a['qty'] * (float) $a['lot']->unit_cost);
        $shortQty = max(0.0, $requestedQty - $allocatedQty);

        return round(($allocatedValue + $shortQty * $fallbackUnitCost) / $requestedQty, 4);
    }

    public function purchaseUnitCost(Product $product, float $enteredPrice, bool $pricesIncludeVat, bool $claimInputVat, float $vatRate): float
    {
        if (! $product->is_vat || $vatRate <= 0) {
            return round($enteredPrice, 4);
        }

        if ($pricesIncludeVat) {
            return round($claimInputVat ? $enteredPrice * 100 / (100 + $vatRate) : $enteredPrice, 4);
        }

        return round($claimInputVat ? $enteredPrice : $enteredPrice * (100 + $vatRate) / 100, 4);
    }

    // ต้นทุนขายรวมของเอกสาร = ผลรวม (จำนวน x ต้นทุนเฉลี่ย) ของทุกรายการในเอกสาร
    public function cogsForDocument(Document $document): float
    {
        $document->loadMissing('stockDocument.items.product');
        if (! $document->stockDocument) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($document->stockDocument->items as $item) {
            // qty อาจติดลบ (แปรรูป) - COGS ขายใช้ค่าสัมบูรณ์เฉพาะขาออก
            $qty = abs((float) $item->qty);
            $total += $item->cost_amount !== null
                ? abs((float) $item->cost_amount)
                : $qty * (float) ($item->unit_cost ?? $item->product->average_cost ?? 0);
        }

        return round($total, 2);
    }
}
