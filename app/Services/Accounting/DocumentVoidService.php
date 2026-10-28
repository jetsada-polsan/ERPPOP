<?php

namespace App\Services\Accounting;

use App\Models\CashBook;
use App\Models\CustomerLedger;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\PaymentAllocation;
use App\Models\PaymentDocument;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\SupplierLedger;
use App\Models\SupplierOpenItem;
use App\Services\Inventory\FifoStockService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ยกเลิกเอกสารหลังบ้านพร้อมกลับรายการทุกขา ในทรานแซกชันเดียว
 *
 *   ใบขายเชื่อ / ใบขายสด : คืนสต๊อก, ปิดลูกหนี้ (เชื่อ), กลับ GL, กลับสมุดเงินสด (สด)
 *   ใบรับคืน            : ตัดสต๊อกที่รับคืนออก, คืนยอดลูกหนี้, กลับ GL
 *   ใบซื้อ               : ตัดล็อตที่รับเข้าออก, ปิดเจ้าหนี้, กลับ GL
 *   ใบเสร็จรับชำระ        : คืนยอดลูกหนี้ที่ตัดไป, กลับ GL, กลับสมุดเงินสด
 *
 * ห้ามยกเลิกเมื่อ: งวดบัญชีปิด, มีเอกสารปลายทางอ้างอยู่ (รับชำระ ใบวางบิล ใบรับคืน ใบลดหนี้),
 * ของที่รับเข้าถูกขาย/โอนไปแล้ว หรือเป็นบิลจาก POS (ต้องยกเลิกที่ POS เพื่อให้ยอดกะถูก)
 * ไม่ลบอะไร — สถานะเป็น cancelled และมีรายการกลับให้ตรวจย้อนได้ พร้อม audit log
 */
class DocumentVoidService
{
    public const VOIDABLE = ['CREDIT_SALE', 'CASH_SALE', 'SALE_RETURN', 'PURCHASE', 'RECEIPT'];

    public function __construct(
        private readonly GlPostingService $gl,
        private readonly FifoStockService $fifo,
        private readonly AccountingPeriodGuard $periods,
    ) {}

    /** เหตุผลที่ยกเลิกไม่ได้ (ว่าง = ยกเลิกได้) ใช้ทั้งซ่อนปุ่มและตรวจซ้ำตอนยกเลิก */
    public function blockers(Document $document): array
    {
        $code = $document->documentType?->code;
        if (! in_array($code, self::VOIDABLE, true)) {
            return ['เอกสารชนิดนี้ยกเลิกจากหน้านี้ไม่ได้'];
        }
        if (in_array($document->status, ['cancelled', 'void'], true)) {
            return ['เอกสารนี้ถูกยกเลิกแล้ว'];
        }

        $blockers = [];
        if (DB::table('pos_receipts')->where('document_id', $document->id)->exists()
            || DB::table('pos_receipt_returns')->where('document_id', $document->id)->exists()) {
            $blockers[] = 'เป็นเอกสารจาก POS ให้ยกเลิกหรือรับคืนที่เครื่อง POS เพื่อให้ยอดกะถูกต้อง';
        }
        if (Document::where('source_document_id', $document->id)->whereNotIn('status', ['cancelled', 'void'])->exists()) {
            $blockers[] = 'มีเอกสารอ้างถึงใบนี้อยู่ (ใบรับคืน/ใบลดหนี้/ใบเพิ่มหนี้) ยกเลิกใบปลายทางก่อน';
        }

        $openItem = CustomerOpenItem::where('document_id', $document->id)->first();
        if ($code === 'CREDIT_SALE' && $openItem) {
            if (PaymentAllocation::where('customer_open_item_id', $openItem->id)
                ->whereHas('paymentDocument', fn ($q) => $q->whereNotIn('status', ['cancelled', 'void', 'bounced']))->exists()) {
                $blockers[] = 'มีการรับชำระใบนี้แล้ว ยกเลิกใบเสร็จก่อน';
            }
            if (DB::table('billing_note_items')->join('billing_notes', 'billing_notes.id', '=', 'billing_note_items.billing_note_id')
                ->where('billing_note_items.customer_open_item_id', $openItem->id)->where('billing_notes.status', '!=', 'cancelled')->exists()) {
                $blockers[] = 'ใบนี้อยู่ในใบวางบิล ยกเลิกใบวางบิลก่อน';
            }
        }

        if ($code === 'PURCHASE') {
            $supplierItem = SupplierOpenItem::where('source_document_id', $document->id)->first();
            if ($supplierItem && (float) $supplierItem->paid_amount > 0.0001) {
                $blockers[] = 'จ่ายชำระใบซื้อนี้แล้ว ยกเลิกใบจ่ายชำระก่อน';
            }
            if ($this->receivedLotsUsed($document)) {
                $blockers[] = 'สินค้าที่รับเข้าจากใบนี้ถูกขายหรือโอนไปแล้ว ยกเลิกไม่ได้ ให้ทำใบลดหนี้ผู้ขาย/ส่งคืนแทน';
            }
        }

        if ($code === 'SALE_RETURN' && $this->receivedLotsUsed($document, 'return_in')) {
            $blockers[] = 'สินค้าที่รับคืนจากใบนี้ถูกขายหรือตัดไปแล้ว ยกเลิกไม่ได้';
        }

        if ($code === 'RECEIPT') {
            $payment = PaymentDocument::where('document_id', $document->id)->first();
            if (! $payment || $payment->party_type !== 'customer') {
                $blockers[] = 'ยกเลิกได้เฉพาะใบเสร็จรับชำระลูกหนี้';
            } elseif (DB::table('cheques')->where('payment_document_id', $payment->id)->whereNotIn('status', ['pending', 'received'])->exists()) {
                $blockers[] = 'เช็คของใบเสร็จนี้นำฝากหรือผ่านแล้ว ให้ใช้เมนูเช็คเด้งแทน';
            }
        }

        return $blockers;
    }

    public function void(Document $document, string $reason, ?int $userId): Document
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('ต้องระบุเหตุผลการยกเลิก');
        }

        return DB::transaction(function () use ($document, $reason, $userId) {
            $locked = Document::with('documentType')->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->periods->assertOpen($locked->doc_date, $locked->branch_id);
            if ($blockers = $this->blockers($locked)) {
                throw new RuntimeException(implode(' / ', $blockers));
            }

            $label = $locked->doc_number.' - '.$reason;
            match ($locked->documentType->code) {
                'CREDIT_SALE' => $this->voidCreditSale($locked),
                'CASH_SALE' => $this->voidCashSale($locked, $label),
                'SALE_RETURN' => $this->voidSaleReturn($locked),
                'PURCHASE' => $this->voidPurchase($locked),
                'RECEIPT' => $this->voidReceipt($locked, $label),
            };
            $this->gl->reverseDocument($locked, $label);

            $old = ['status' => $locked->status, 'total_amount' => (float) $locked->total_amount];
            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'remark' => trim(($locked->remark ? $locked->remark.' | ' : '').'ยกเลิก: '.$reason),
            ])->save();

            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'branch_id' => $locked->branch_id,
                'action' => 'void',
                'table_name' => 'documents',
                'record_id' => $locked->id,
                'old_values' => json_encode($old, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['status' => 'cancelled', 'reason' => $reason, 'doc_number' => $locked->doc_number], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    private function voidCreditSale(Document $document): void
    {
        $this->fifo->restoreDocumentIssues($document->id, 'void_in');
        $item = CustomerOpenItem::where('document_id', $document->id)->lockForUpdate()->first();
        if ($item) {
            $this->reverseCustomerLedger($document, (float) $item->balance_amount, 'credit');
            $item->update(['balance_amount' => 0, 'status' => 'cancelled']);
        }
    }

    private function voidCashSale(Document $document, string $label): void
    {
        $this->fifo->restoreDocumentIssues($document->id, 'void_in');
        $this->reverseCashBook(CashBookPostingService::SOURCE_CASH_SALE, $document, $label);
    }

    private function voidSaleReturn(Document $document): void
    {
        // ของที่รับคืนกลับออกไปจากล็อตที่สร้างตอนรับคืน
        $this->removeReceivedLots($document, 'return_in', 'void_out');

        $ledger = CustomerLedger::where('document_id', $document->id)->where('entry_type', 'credit')->first();
        $source = $document->source_document_id ? CustomerOpenItem::where('document_id', $document->source_document_id)->lockForUpdate()->first() : null;
        if ($ledger && $source) {
            $balance = DecimalMath::add($source->balance_amount, $ledger->amount);
            $source->update([
                'balance_amount' => $balance,
                'status' => DecimalMath::compare($source->paid_amount, '0.01') <= 0 ? CustomerOpenItem::STATUS_OPEN : CustomerOpenItem::STATUS_PARTIAL,
            ]);
            $this->reverseCustomerLedger($document, (float) $ledger->amount, 'debit');
        }
    }

    private function voidPurchase(Document $document): void
    {
        $this->removeReceivedLots($document, 'in', 'void_out');

        $ledger = SupplierLedger::where('document_id', $document->id)->where('entry_type', 'credit')->first();
        if ($ledger) {
            $previous = SupplierLedger::where('supplier_id', $ledger->supplier_id)->orderByDesc('id')->lockForUpdate()->value('balance_after') ?? 0;
            SupplierLedger::create([
                'supplier_id' => $ledger->supplier_id, 'document_id' => $document->id, 'entry_type' => 'debit',
                'amount' => $ledger->amount, 'balance_after' => DecimalMath::subtract($previous, $ledger->amount),
                'entry_date' => now()->toDateString(),
            ]);
        }
        SupplierOpenItem::where('source_document_id', $document->id)->update(['balance_amount' => 0, 'status' => 'cancelled']);
    }

    private function voidReceipt(Document $document, string $label): void
    {
        $payment = PaymentDocument::where('document_id', $document->id)->lockForUpdate()->firstOrFail();
        foreach (PaymentAllocation::where('payment_document_id', $payment->id)->get() as $allocation) {
            $item = CustomerOpenItem::whereKey($allocation->customer_open_item_id)->lockForUpdate()->first();
            if (! $item) {
                continue;
            }
            $amount = (float) $allocation->allocated_amount;
            $paid = max(0, round((float) $item->paid_amount - $amount, 4));
            $item->update([
                'paid_amount' => $paid,
                'balance_amount' => round((float) $item->balance_amount + $amount, 4),
                'status' => $paid <= 0.01 ? CustomerOpenItem::STATUS_OPEN : CustomerOpenItem::STATUS_PARTIAL,
            ]);
        }
        $this->gl->reversePaymentDocument($payment, $label);
        $payment->update(['status' => 'cancelled']);
        DB::table('cheques')->where('payment_document_id', $payment->id)->update(['status' => 'cancelled']);
        $this->reverseCashBook(CashBookPostingService::SOURCE_CUSTOMER_PAYMENT, $document, $label);
    }

    /** ล็อตที่เอกสารนี้รับเข้าถูกใช้ไปบางส่วนแล้วหรือยัง */
    private function receivedLotsUsed(Document $document, string $movementType = 'in'): bool
    {
        $lotIds = StockMovement::where('document_id', $document->id)->where('movement_type', $movementType)->pluck('stock_lot_id')->filter();

        return StockLot::whereIn('id', $lotIds)->get()->contains(fn ($lot) => DecimalMath::compare($lot->remaining_qty, $lot->initial_qty) !== 0);
    }

    private function removeReceivedLots(Document $document, string $receivedType, string $movementType): void
    {
        $receipts = StockMovement::where('document_id', $document->id)->where('movement_type', $receivedType)->lockForUpdate()->get();
        foreach ($receipts as $movement) {
            $lot = $movement->stock_lot_id ? StockLot::whereKey($movement->stock_lot_id)->lockForUpdate()->first() : null;
            if ($lot && DecimalMath::compare($lot->remaining_qty, $movement->qty) < 0) {
                throw new RuntimeException('สินค้าที่รับเข้าถูกใช้ไปแล้ว ยกเลิกไม่ได้');
            }
            $lot?->update(['remaining_qty' => DecimalMath::subtract($lot->remaining_qty, $movement->qty, DecimalMath::QUANTITY_SCALE)]);
            DB::table('stock_balances')->where('product_id', $movement->product_id)
                ->where('warehouse_location_id', $movement->warehouse_location_id)
                ->decrement('on_hand_qty', $movement->qty);
            StockMovement::create([
                'product_id' => $movement->product_id, 'warehouse_location_id' => $movement->warehouse_location_id,
                'document_id' => $document->id, 'stock_lot_id' => $movement->stock_lot_id,
                'movement_type' => $movementType, 'qty' => $movement->qty, 'movement_date' => now()->toDateString(),
            ]);
        }
    }

    private function reverseCustomerLedger(Document $document, float $amount, string $entryType): void
    {
        if ($amount <= 0 || ! $document->customer_id || ! CustomerLedger::where('document_id', $document->id)->exists()) {
            return;
        }
        $previous = CustomerLedger::where('customer_id', $document->customer_id)->orderByDesc('id')->lockForUpdate()->value('balance_after') ?? 0;
        CustomerLedger::create([
            'customer_id' => $document->customer_id, 'document_id' => $document->id, 'entry_type' => $entryType,
            'amount' => $amount,
            'balance_after' => $entryType === 'credit' ? DecimalMath::subtract($previous, $amount) : DecimalMath::add($previous, $amount),
            'entry_date' => now()->toDateString(),
        ]);
    }

    private function reverseCashBook(string $sourceType, Document $document, string $label): void
    {
        $entry = CashBook::where('source_key', $sourceType.':'.$document->id)->first();
        if (! $entry || (float) $entry->cash_in <= 0) {
            return;
        }
        app(CashBookPostingService::class)->post([
            'branch_id' => $entry->branch_id,
            'entry_date' => now()->toDateString(),
            'description' => 'ยกเลิก '.$label,
            'cash_out' => (float) $entry->cash_in,
            'source_type' => $sourceType,
            'source_id' => $document->id,
            'source_key' => $sourceType.':'.$document->id.':void',
        ]);
    }
}
