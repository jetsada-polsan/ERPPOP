<?php

namespace App\Services\Sales;

use App\Models\CustomerLedger;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ใบลดหนี้ (credit) / ใบเพิ่มหนี้ (debit): ปรับยอดหนี้ลูกค้าแบบการเงินล้วน
 * ไม่แตะสต๊อก. Credit note ต้องอ้างอิงใบขายเชื่อค้างชำระ (ลดยอดค้างของใบนั้น);
 * debit note อาจอ้างอิงหรือไม่ก็ได้ (สร้างรายการหนี้ใหม่ให้ลูกค้า).
 */
class CreditDebitNoteService
{
    private const DEFAULT_CREDIT_DAYS = 30;

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly \App\Services\Accounting\GlPostingService $glPosting,
    ) {}

    /**
     * @param array{
     *   type:string, customer_id:int, branch_id:int, amount:float, reason:string,
     *   open_item_id:?int
     * } $data
     */
    public function create(array $data): Document
    {
        $isCredit = $data['type'] === 'credit';
        $typeCode = $isCredit ? 'CREDIT_NOTE' : 'DEBIT_NOTE';
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('จำนวนเงินต้องมากกว่า 0');
        }

        $openItem = null;
        if (! empty($data['open_item_id'])) {
            $openItem = CustomerOpenItem::where('id', $data['open_item_id'])
                ->where('customer_id', $data['customer_id'])
                ->first();
            if (! $openItem) {
                throw new RuntimeException('ไม่พบใบขายเชื่ออ้างอิงของลูกค้ารายนี้');
            }
        }

        // ใบลดหนี้: ต้องอ้างอิงใบค้าง และลดได้ไม่เกินยอดค้าง
        if ($isCredit) {
            if (! $openItem) {
                throw new RuntimeException('ใบลดหนี้ต้องอ้างอิงใบขายเชื่อที่ค้างชำระ');
            }
            if ($amount > (float) $openItem->balance_amount + 0.01) {
                throw new RuntimeException('ยอดลดหนี้เกินยอดค้างของเอกสาร '.$openItem->document->doc_number);
            }
        }

        $documentType = DocumentType::where('code', $typeCode)->firstOrFail();

        return DB::transaction(function () use ($data, $isCredit, $typeCode, $amount, $openItem, $documentType) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next($typeCode, $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'],
                'reference' => $openItem?->document->doc_number,
                'status' => 'active',
                'total_items' => 1,
                'total_amount' => $amount,
                'remark' => $data['reason'],
            ]);

            $lastBalance = (float) (CustomerLedger::where('customer_id', $data['customer_id'])
                ->latest('id')->value('balance_after') ?? 0);

            if ($isCredit) {
                // ลดยอดค้างของใบอ้างอิง
                $newBalance = round((float) $openItem->balance_amount - $amount, 2);
                $openItem->update([
                    'net_amount' => round((float) $openItem->net_amount - $amount, 2),
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0.01 ? CustomerOpenItem::STATUS_PAID : $openItem->status,
                ]);

                CustomerLedger::create([
                    'customer_id' => $data['customer_id'],
                    'document_id' => $document->id,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'balance_after' => round($lastBalance - $amount, 2),
                    'entry_date' => now()->toDateString(),
                ]);
            } else {
                // ใบเพิ่มหนี้: สร้างรายการหนี้ใหม่ให้ลูกค้า
                CustomerOpenItem::create([
                    'customer_id' => $data['customer_id'],
                    'document_id' => $document->id,
                    'gross_amount' => $amount,
                    'net_amount' => $amount,
                    'balance_amount' => $amount,
                    'due_date' => now()->addDays((int) (\App\Models\AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))->toDateString(),
                    'status' => CustomerOpenItem::STATUS_OPEN,
                ]);

                CustomerLedger::create([
                    'customer_id' => $data['customer_id'],
                    'document_id' => $document->id,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'balance_after' => round($lastBalance + $amount, 2),
                    'entry_date' => now()->toDateString(),
                ]);
            }

            $isCredit
                ? $this->glPosting->postCreditNote($document)
                : $this->glPosting->postDebitNote($document);

            return $document->fresh();
        });
    }
}
