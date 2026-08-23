<?php

namespace App\Services\Purchasing;

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseQuoteItem;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * บันทึกและเลือกใบเสนอราคา
 *
 * เดิมพอถึงขั้นสั่งซื้อจะเลือกผู้ขายแล้วดึงราคาตามข้อตกลงมาเลย ไม่มีที่เก็บว่าเทียบกับใครบ้าง
 * พอสั่งไปแล้วจึงตอบไม่ได้ว่าทำไมถึงเลือกเจ้านี้ ซึ่งเป็นคำถามแรกที่ผู้ตรวจสอบถาม
 *
 * เลือกเจ้าที่แพงกว่าได้ แต่ต้องบันทึกเหตุผล — เพราะบางทีถูกที่สุดไม่ใช่ดีที่สุด
 * (ส่งช้า เครดิตสั้น คุณภาพต่างกัน) สิ่งที่ห้ามคือเลือกแล้วอธิบายไม่ได้
 */
class PurchaseQuoteService
{
    /**
     * @param  array<int, array{purchase_order_item_id:int, unit_price:float, note?:?string}>  $lines
     */
    public function record(PurchaseOrder $order, int $supplierId, array $lines, array $meta = []): PurchaseQuote
    {
        if ($lines === []) {
            throw new RuntimeException('ใบเสนอราคาต้องมีรายการอย่างน้อย 1 รายการ');
        }
        if (in_array($order->status, ['ordered', 'cancelled'], true)) {
            throw new RuntimeException('ใบขอซื้อนี้สั่งซื้อหรือยกเลิกไปแล้ว บันทึกใบเสนอราคาเพิ่มไม่ได้');
        }

        return DB::transaction(function () use ($order, $supplierId, $lines, $meta) {
            $allowedItemIds = $order->items()->pluck('id')->all();

            $quote = PurchaseQuote::updateOrCreate(
                ['purchase_order_id' => $order->id, 'supplier_id' => $supplierId],
                [
                    'valid_until' => $meta['valid_until'] ?? null,
                    'reference' => $meta['reference'] ?? null,
                    'note' => $meta['note'] ?? null,
                    'quoted_by' => auth()->id(),
                ],
            );

            $quote->items()->delete();
            $total = [];
            foreach ($lines as $line) {
                $itemId = (int) $line['purchase_order_item_id'];
                if (! in_array($itemId, $allowedItemIds, true)) {
                    throw new RuntimeException('ใบเสนอราคามีรายการที่ไม่ได้อยู่ในใบขอซื้อนี้');
                }
                $price = round((float) $line['unit_price'], 4);
                if ($price < 0) {
                    throw new RuntimeException('ราคาเสนอติดลบไม่ได้');
                }
                PurchaseQuoteItem::create([
                    'purchase_quote_id' => $quote->id,
                    'purchase_order_item_id' => $itemId,
                    'unit_price' => $price,
                    'note' => $line['note'] ?? null,
                ]);
                $qty = $order->items->firstWhere('id', $itemId)?->qty ?? 0;
                $total[] = DecimalMath::multiply($qty, $price);
            }

            $quote->update(['total_amount' => DecimalMath::sum($total)]);

            return $quote->fresh('items');
        });
    }

    /** เลือกใบเสนอราคาหนึ่งใบ แล้วเอาราคาไปใส่ใบขอซื้อให้พร้อมสั่งซื้อ */
    public function select(PurchaseQuote $quote, ?string $reason = null): PurchaseQuote
    {
        return DB::transaction(function () use ($quote, $reason) {
            $order = PurchaseOrder::whereKey($quote->purchase_order_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'approved') {
                throw new RuntimeException('เลือกใบเสนอราคาได้เฉพาะใบขอซื้อที่อนุมัติแล้วและยังไม่สั่งซื้อ');
            }

            $cheapest = PurchaseQuote::where('purchase_order_id', $order->id)
                ->orderBy('total_amount')->first();
            // เลือกเจ้าที่ไม่ใช่ถูกที่สุดต้องมีเหตุผล ไม่งั้นตอบผู้ตรวจสอบไม่ได้
            if ($cheapest && $cheapest->id !== $quote->id && blank($reason)) {
                throw new RuntimeException('เลือกผู้ขายที่ไม่ใช่ราคาต่ำสุด ต้องระบุเหตุผล');
            }

            PurchaseQuote::where('purchase_order_id', $order->id)
                ->update(['is_selected' => false, 'selected_by' => null, 'selected_at' => null]);

            $quote->update([
                'is_selected' => true,
                'selection_reason' => $reason,
                'selected_by' => auth()->id(),
                'selected_at' => now(),
            ]);

            foreach ($quote->items as $line) {
                $order->items()->where('id', $line->purchase_order_item_id)
                    ->update(['unit_price' => $line->unit_price]);
            }
            $order->update(['supplier_id' => $quote->supplier_id, 'total_amount' => $quote->total_amount]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'branch_id' => $order->branch_id,
                'action' => 'purchase_quote_selected',
                'table_name' => 'purchase_quotes',
                'record_id' => $quote->id,
                'old_values' => ['cheapest_quote_id' => $cheapest?->id, 'cheapest_total' => $cheapest?->total_amount],
                'new_values' => [
                    'supplier_id' => $quote->supplier_id,
                    'total_amount' => $quote->total_amount,
                    'reason' => $reason,
                ],
            ]);

            return $quote->fresh();
        });
    }

    /** ตารางเทียบราคาสำหรับหน้าจอและรายงาน */
    public function comparison(PurchaseOrder $order): array
    {
        $quotes = PurchaseQuote::with(['supplier', 'items'])
            ->where('purchase_order_id', $order->id)
            ->orderBy('total_amount')
            ->get();

        $cheapestTotal = (float) ($quotes->first()->total_amount ?? 0);

        return [
            'quotes' => $quotes,
            'cheapest_id' => $quotes->first()?->id,
            'spread' => $quotes->count() > 1
                ? round((float) $quotes->last()->total_amount - $cheapestTotal, 2)
                : 0.0,
        ];
    }
}
