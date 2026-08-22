<?php

namespace App\Services\Purchasing;

use App\Models\Document;
use App\Models\SupplierOpenItem;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;

/**
 * ยอดเจ้าหนี้ค้างชำระรายใบ — เกิดอัตโนมัติเมื่อยืนยันใบซื้อเชื่อ/ใบกำกับซื้อ
 * และลดลงเมื่อจ่ายชำระเจ้าหนี้ AP aging อ่านจากตารางนี้เท่านั้น
 *
 * supplier_ledger ยังเดินต่อเหมือนเดิมในฐานะสมุดเดินบัญชี (ยอดรวมต่อผู้ขาย)
 * แต่บอกไม่ได้ว่าเงินก้อนไหนมาจากใบไหน จึงใช้ทำ aging ไม่ได้
 */
class SupplierOpenItemService
{
    /** เปิดยอดค้างจากใบซื้อเชื่อหนึ่งใบ — ใบเดิมเปิดซ้ำไม่ได้ */
    public function openFromPurchase(Document $document, float $amount, ?string $paymentTerms = null, ?string $dueDate = null): ?SupplierOpenItem
    {
        if ($amount <= 0 || ! $document->supplier_id) {
            return null;
        }

        return SupplierOpenItem::firstOrCreate(
            ['supplier_id' => $document->supplier_id, 'document_no' => $document->doc_number],
            [
                'source_document_id' => $document->id,
                'document_date' => $document->doc_date->toDateString(),
                'due_date' => $dueDate,
                'original_amount' => $amount,
                'paid_amount' => 0,
                'balance_amount' => $amount,
                'status' => 'open',
                'payment_terms' => $paymentTerms,
            ],
        );
    }

    /**
     * ตัดยอดจ่ายชำระเข้ากับใบที่ค้าง เรียงใบเก่าก่อน (FIFO) แล้วคืนยอดที่ตัดได้จริง
     * ส่วนที่เหลือจากการตัด (จ่ายเกินยอดค้าง) คืนกลับไปให้ผู้เรียกจัดการ
     */
    public function applyPayment(int $supplierId, float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($supplierId, $amount) {
            $remaining = $amount;

            $items = SupplierOpenItem::where('supplier_id', $supplierId)
                ->whereIn('status', ['open', 'partial'])
                ->orderBy('due_date')
                ->orderBy('document_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (float) $item->balance_amount);
                if ($applied <= 0) {
                    continue;
                }

                $newBalance = round((float) DecimalMath::subtract((string) $item->balance_amount, (string) $applied), 4);
                $item->update([
                    'paid_amount' => DecimalMath::add((string) $item->paid_amount, (string) $applied),
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0.0001 ? 'cleared' : 'partial',
                    'cleared_at' => $newBalance <= 0.0001 ? now() : null,
                ]);

                $remaining = round($remaining - $applied, 4);
            }

            return round($amount - $remaining, 4);
        });
    }
}
