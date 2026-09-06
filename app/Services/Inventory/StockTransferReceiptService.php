<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReceiptItem;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ตรวจรับสินค้าโอนย้ายด้วยการสแกนบาร์โค้ดที่ปลายทาง - เทียบจำนวนที่สแกนจริงกับ
 * จำนวนในใบโอนย้าย (ที่อนุมัติ/ตัดสต๊อกไปแล้วผ่าน StockTransferService::approve())
 * เพื่อจับความผิดพลาดระหว่างขนส่ง/หยิบของผิด
 *
 * เจตนา: เป็นชั้นหลักฐาน/ควบคุมเพิ่มเติมเท่านั้น "ไม่" แก้ไข stock_balances เอง
 * เพราะการแก้สต๊อกอัตโนมัติจากการสแกน (ซึ่งพนักงานคนเดียวทำได้) เสี่ยงเกินไปสำหรับ
 * ข้อมูลที่กระทบยอดคงคลัง/ต้นทุน - พบส่วนต่างจริงต้องให้พนักงานไปสร้าง "ใบปรับสต๊อก"
 * (stock-adjustments) แยกต่างหากตามขั้นตอนปกติ ซึ่งมีผู้อนุมัติอีกคนตรวจซ้ำ
 * (segregation of duties เดียวกับที่ใช้ทั้งระบบ)
 */
class StockTransferReceiptService
{
    /**
     * เปิดใบตรวจรับใหม่ (snapshot จำนวนตามเอกสาร) หรือคืนใบที่เปิดค้างอยู่แล้ว/ปิดแล้ว.
     */
    public function openOrGet(Document $document): StockTransferReceipt
    {
        if ($document->documentType?->code !== 'STOCK_TRANSFER') {
            throw new RuntimeException('ไม่ใช่เอกสารโอนย้ายสต็อก');
        }
        if ($document->status !== 'active') {
            throw new RuntimeException('ต้องเป็นใบโอนย้ายที่อนุมัติ/โอนสต๊อกแล้วเท่านั้นถึงจะตรวจรับได้');
        }

        $existing = StockTransferReceipt::where('document_id', $document->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($document) {
            $receipt = StockTransferReceipt::create([
                'document_id' => $document->id,
                'status' => 'checking',
                'created_by' => auth()->id(),
            ]);

            $items = $document->stockDocument()->with('items')->first()?->items ?? collect();
            if ($items->isEmpty()) {
                throw new RuntimeException('ไม่พบรายการสินค้าของใบโอนย้ายนี้');
            }

            // รวมจำนวนต่อสินค้า เผื่อใบโอนมีสินค้าเดียวกันหลายแถว (คนละ lot/แถว)
            $grouped = $items->groupBy('product_id')
                ->map(fn ($rows) => DecimalMath::sum($rows->pluck('qty'), DecimalMath::QUANTITY_SCALE));

            foreach ($grouped as $productId => $qty) {
                StockTransferReceiptItem::create([
                    'stock_transfer_receipt_id' => $receipt->id,
                    'product_id' => $productId,
                    'expected_qty' => $qty,
                ]);
            }

            return $receipt->fresh();
        });
    }

    /**
     * บันทึกยอดที่สแกนได้ (client ส่งค่าล่าสุดของแต่ละแถวมาทั้งชุด เหมือนใบตรวจนับ).
     *
     * @param  array<int, array{id:int, scanned_qty: ?float}>  $rows
     */
    public function saveScans(StockTransferReceipt $receipt, array $rows): int
    {
        if (! $receipt->isEditable()) {
            throw new RuntimeException('ใบนี้ยืนยันปิดแล้ว แก้ไขไม่ได้');
        }

        $updated = 0;
        foreach ($rows as $row) {
            $updated += StockTransferReceiptItem::where('stock_transfer_receipt_id', $receipt->id)
                ->where('id', $row['id'])
                ->update(['scanned_qty' => $row['scanned_qty'] ?? null]);
        }

        return $updated;
    }

    /**
     * ปิดใบตรวจรับ - ต้องสแกน/กรอกครบทุกรายการก่อน คืนรายการที่ยอดไม่ตรง (ถ้ามี)
     * ให้ผู้เรียกไปแจ้งเตือน/ชี้ทางให้สร้างใบปรับสต๊อกต่อเอง.
     *
     * @return Collection<int, StockTransferReceiptItem>
     */
    public function complete(StockTransferReceipt $receipt, ?string $note = null): Collection
    {
        if (! $receipt->isEditable()) {
            throw new RuntimeException('ใบนี้ยืนยันปิดแล้ว');
        }

        $items = $receipt->items()->with('product:id,sku_code')->get();
        $unscanned = $items->filter(fn ($i) => $i->scanned_qty === null);
        if ($unscanned->isNotEmpty()) {
            $skus = $unscanned->map(fn ($i) => $i->product->sku_code)->implode(', ');
            throw new RuntimeException("ยังสแกน/กรอกไม่ครบ ({$unscanned->count()} รายการ): {$skus}");
        }

        $receipt->update([
            'status' => 'completed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
            'note' => $note,
        ]);

        return $items->filter(fn ($i) => DecimalMath::compare($i->scanned_qty, $i->expected_qty) !== 0)->values();
    }
}
