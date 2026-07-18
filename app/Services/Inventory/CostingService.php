<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\Product;
use App\Models\StockBalance;

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

        $product = Product::find($productId);
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

        $product->update(['average_cost' => round($newCost, 4)]);
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
            $total += $qty * (float) ($item->product->average_cost ?? 0);
        }

        return round($total, 2);
    }
}
