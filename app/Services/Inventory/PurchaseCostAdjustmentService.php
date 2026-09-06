<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\Product;
use App\Models\PurchaseCostAdjustment;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ปรับต้นทุนซื้อย้อนหลัง - ใช้เมื่อกรอกราคาต้นทุนผิดตอนรับของ หรือใบแจ้งหนี้จริงมาทีหลัง
 * ราคาต่างจากที่บันทึกไว้ตอนรับเข้าคลัง
 *
 * ขอบเขตที่ตั้งใจจำกัดไว้ (สำคัญ อ่านก่อนแก้โค้ดนี้):
 * - แก้เฉพาะ stock_lots.unit_cost ของ Lot ที่มาจากใบซื้อนี้เท่านั้น "ไม่" แตะ
 *   documents.total_amount / stock_document_items.unit_cost / supplier_ledger / GL
 *   ที่โพสต์ไปแล้วตอนบันทึกใบซื้อเลย - ยอดหนี้เจ้าหนี้และบัญชีที่ผูกกับใบซื้อเดิมคงเดิม
 *   เสมอ (ถ้าราคาที่ตกลงกับซัพพลายเออร์เปลี่ยนจริง ต้องไปทำใบลดหนี้/เพิ่มหนี้แยกต่างหาก
 *   ตามขั้นตอนบัญชีปกติ ฟีเจอร์นี้แก้แค่ "มูลค่าสต๊อกคงเหลือ" เท่านั้น)
 * - ปรับ products.average_cost แบบ "nudge" ตามสัดส่วนจำนวนคงเหลือของ Lot นี้ต่อยอด
 *   คงเหลือทั้งหมดของสินค้า (สอดคล้องกับวิธีคำนวณ moving average เดิมใน CostingService
 *   ที่ไม่เคยคำนวณใหม่จาก Lot ทั้งหมดอยู่แล้ว) เป็นค่าประมาณที่เหมาะกับระบบ moving
 *   average ไม่ใช่การคำนวณใหม่ทั้งหมดแบบแม่นยำ 100% (ต้อง replay ธุรกรรมทั้งหมดตั้งแต่
 *   Lot นี้เกิด ซึ่งไม่มีโครงสร้างรองรับในระบบตอนนี้)
 * - จำนวนที่ตัดสต๊อกออกไปแล้ว (consumed_qty) "ไม่" ย้อนแก้ต้นทุนขาย/เอกสารเก่าที่ตัด
 *   FIFO ไปแล้วเด็ดขาด (ห้ามแก้ยอดขายย้อนหลัง) เก็บมูลค่าผลต่างไว้ใน
 *   unconfirmed_variance_amount เป็นข้อมูลอ้างอิงให้บัญชีตัดสินใจเองว่าจะปรับผ่าน
 *   journal แยกหรือไม่
 * - เคารพงวดที่ปิดต้นทุนแล้ว (InventoryCostCloseGuard) เหมือนการเคลื่อนไหวสต๊อกอื่นๆ
 */
class PurchaseCostAdjustmentService
{
    public function __construct(private readonly InventoryCostCloseGuard $costCloseGuard) {}

    /**
     * จับคู่รายการในใบซื้อกับ Lot ที่เกิดจากใบซื้อนั้นแบบตำแหน่งต่อตำแหน่ง (seq ตรงกับ
     * ลำดับที่ FifoStockService::receive() ถูกเรียกตอนสร้างใบซื้อใน PurchaseService)
     * เพราะ stock_document_items ไม่ได้เก็บ stock_lot_id ย้อนกลับไว้โดยตรง
     *
     * @return Collection<int, array{item: object, lot: StockLot}>
     */
    public function preview(Document $purchase): Collection
    {
        $this->assertPurchase($purchase);

        $items = $purchase->stockDocument->items()->orderBy('seq')->with('product')->get();
        $lots = StockLot::where('source_document_id', $purchase->id)->orderBy('id')->get();

        if ($items->count() !== $lots->count()) {
            throw new RuntimeException('จำนวนรายการในใบซื้อกับ Lot สต๊อกไม่ตรงกัน (ข้อมูลอาจถูกแก้ไขนอกระบบ) กรุณาตรวจสอบด้วยตนเองก่อนปรับต้นทุน');
        }

        return $items->values()->map(fn ($item, int $i) => ['item' => $item, 'lot' => $lots[$i]]);
    }

    /**
     * @param  array<int, array{stock_lot_id:int, new_unit_cost: float|string}>  $rows
     * @return Collection<int, PurchaseCostAdjustment>
     */
    public function adjust(Document $purchase, array $rows, string $reason, ?int $userId): Collection
    {
        $this->assertPurchase($purchase);

        $validLotIds = StockLot::where('source_document_id', $purchase->id)->pluck('id');

        return DB::transaction(function () use ($rows, $reason, $userId, $validLotIds): Collection {
            $created = collect();
            foreach ($rows as $row) {
                $lotId = (int) $row['stock_lot_id'];
                if (! $validLotIds->contains($lotId)) {
                    throw new RuntimeException("Lot #{$lotId} ไม่ได้มาจากใบซื้อนี้");
                }

                $lot = StockLot::whereKey($lotId)->lockForUpdate()->firstOrFail();
                $oldCost = $lot->unit_cost;
                $newCost = DecimalMath::round($row['new_unit_cost'], DecimalMath::COST_SCALE);

                if (DecimalMath::compare($newCost, 0) < 0) {
                    throw new RuntimeException('ต้นทุนใหม่ต้องไม่ติดลบ');
                }
                if (DecimalMath::compare($oldCost, $newCost) === 0) {
                    continue; // ไม่มีการเปลี่ยนแปลงจริง ข้ามไปไม่บันทึกหลักฐานเปล่าๆ
                }

                $this->costCloseGuard->assertOpen($lot->received_date, 'new_unit_cost');

                $remainingQty = $lot->remaining_qty;
                $consumedQty = DecimalMath::subtract($lot->initial_qty, $remainingQty, DecimalMath::QUANTITY_SCALE);
                $deltaPerUnit = DecimalMath::subtract($newCost, $oldCost);
                $remainingValueDelta = DecimalMath::multiply($deltaPerUnit, $remainingQty);
                $consumedValueDelta = DecimalMath::multiply($deltaPerUnit, $consumedQty);

                $lot->update(['unit_cost' => $newCost]);

                if (DecimalMath::compare($remainingQty, 0) > 0) {
                    $product = Product::whereKey($lot->product_id)->lockForUpdate()->first();
                    $onHand = StockBalance::where('product_id', $lot->product_id)->sum('on_hand_qty');
                    if ($product && DecimalMath::compare($onHand, 0) > 0) {
                        $newAverage = DecimalMath::add($product->average_cost, DecimalMath::divide($remainingValueDelta, $onHand));
                        $product->update(['average_cost' => DecimalMath::round($newAverage, DecimalMath::COST_SCALE)]);
                    }
                }

                $created->push(PurchaseCostAdjustment::create([
                    'document_id' => $lot->source_document_id,
                    'stock_lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'old_unit_cost' => $oldCost,
                    'new_unit_cost' => $newCost,
                    'remaining_qty_adjusted' => $remainingQty,
                    'consumed_qty' => $consumedQty,
                    'unconfirmed_variance_amount' => $consumedValueDelta,
                    'reason' => $reason,
                    'created_by' => $userId,
                ]));
            }

            if ($created->isEmpty()) {
                throw new RuntimeException('ไม่มีรายการที่เปลี่ยนแปลงต้นทุนจริง');
            }

            return $created;
        });
    }

    private function assertPurchase(Document $purchase): void
    {
        if ($purchase->documentType?->code !== 'PURCHASE') {
            throw new RuntimeException('ไม่ใช่เอกสารใบซื้อ');
        }
    }
}
