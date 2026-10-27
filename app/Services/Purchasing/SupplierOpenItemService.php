<?php

namespace App\Services\Purchasing;

use App\Models\Document;
use App\Models\SupplierOpenItem;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        return round(array_sum($this->applyPaymentFifo($supplierId, $amount)), 4);
    }

    /**
     * เหมือน applyPayment() แต่คืนรายละเอียดว่าตัดใบไหนไปเท่าไร
     *
     * @return array<int, float> supplier_open_item_id => ยอดที่ตัด
     */
    public function applyPaymentFifo(int $supplierId, float $amount): array
    {
        if ($amount <= 0) {
            return [];
        }

        return DB::transaction(function () use ($supplierId, $amount) {
            $remaining = $amount;
            $applied = [];

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

                $take = min($remaining, (float) $item->balance_amount);
                if ($take <= 0) {
                    continue;
                }

                $this->reduce($item, $take);
                $applied[$item->id] = $take;
                $remaining = round($remaining - $take, 4);
            }

            return $applied;
        });
    }

    /**
     * ตัดยอดตามใบที่ผู้ใช้เลือกเอง (จ่ายเฉพาะบางใบ / จ่ายบางส่วนของใบ)
     *
     * ตรวจทุกบรรทัดก่อนตัดใบแรก: ใบต้องเป็นของผู้ขายรายนี้ ยังค้างอยู่ และยอดไม่เกินยอดค้าง
     *
     * @param  array<int, array{supplier_open_item_id:int, amount:float}>  $allocations
     * @return array<int, float> supplier_open_item_id => ยอดที่ตัด
     */
    public function applyToItems(int $supplierId, array $allocations): array
    {
        $wanted = collect($allocations)
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)
            ->mapWithKeys(fn ($row) => [(int) $row['supplier_open_item_id'] => round((float) $row['amount'], 2)]);

        if ($wanted->isEmpty()) {
            throw new RuntimeException('กรุณาเลือกใบซื้อที่จะจ่ายอย่างน้อย 1 ใบ');
        }

        return DB::transaction(function () use ($supplierId, $wanted) {
            $items = $this->assertAllocatable($supplierId, $wanted->all());

            $applied = [];
            foreach ($wanted as $itemId => $amount) {
                $this->reduce($items->get($itemId), $amount);
                $applied[$itemId] = $amount;
            }

            return $applied;
        });
    }

    /**
     * ตรวจว่าตัดยอดตามที่ขอได้ทุกบรรทัด (ของผู้ขายรายนี้ ยังค้าง ยอดไม่เกิน) โดยยังไม่แก้อะไร
     *
     * @param  array<int, float>  $wanted  supplier_open_item_id => ยอดที่จะตัด
     * @return Collection<int, SupplierOpenItem>
     */
    public function assertAllocatable(int $supplierId, array $wanted): Collection
    {
        $items = SupplierOpenItem::whereIn('id', array_keys($wanted))->lockForUpdate()->get()->keyBy('id');

        foreach ($wanted as $itemId => $amount) {
            $item = $items->get($itemId);
            if (! $item || (int) $item->supplier_id !== $supplierId) {
                throw new RuntimeException('มีใบซื้อที่เลือกไม่ใช่ของผู้จำหน่ายรายนี้');
            }
            if (! in_array($item->status, ['open', 'partial'], true)) {
                throw new RuntimeException("ใบ {$item->document_no} จ่ายครบไปแล้ว");
            }
            if ($amount > round((float) $item->balance_amount, 2) + 0.001) {
                throw new RuntimeException(sprintf(
                    'ใบ %s ค้างจ่าย %s บาท แต่จะจ่าย %s บาท',
                    $item->document_no,
                    number_format((float) $item->balance_amount, 2),
                    number_format($amount, 2),
                ));
            }
        }

        return $items;
    }

    private function reduce(SupplierOpenItem $item, float $amount): void
    {
        $newBalance = round((float) DecimalMath::subtract((string) $item->balance_amount, (string) $amount), 4);
        $item->update([
            'paid_amount' => DecimalMath::add((string) $item->paid_amount, (string) $amount),
            'balance_amount' => $newBalance,
            'status' => $newBalance <= 0.0001 ? 'cleared' : 'partial',
            'cleared_at' => $newBalance <= 0.0001 ? now() : null,
        ]);
    }
}
