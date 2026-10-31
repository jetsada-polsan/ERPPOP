<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\CustomerLedger;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\Accounting\GlPostingService;
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
        private readonly GlPostingService $glPosting,
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

        // ใบลดหนี้: ต้องอ้างอิงใบขายเชื่อ (ค้างหรือชำระแล้วก็ได้) และลดได้ไม่เกินมูลค่าใบ
        // หักใบลดหนี้ที่ออกไปแล้ว ส่วนที่เกินยอดค้างต้องเลือกว่าเป็นเครดิตหรือคืนเงิน
        $settlement = null;
        if ($isCredit) {
            if (! $openItem) {
                throw new RuntimeException('ใบลดหนี้ต้องอ้างอิงใบขายเชื่อ');
            }
            $creditable = $this->creditableAmount($openItem);
            if ($amount > $creditable + 0.01) {
                throw new RuntimeException(sprintf(
                    'ยอดลดหนี้เกินมูลค่าที่ลดได้ของเอกสาร %s (ลดได้อีก %s บาท)',
                    $openItem->document->doc_number,
                    number_format($creditable, 2),
                ));
            }
            if ($amount > (float) $openItem->balance_amount + 0.01) {
                $settlement = $data['settlement'] ?? 'credit';
                if (! in_array($settlement, ['credit', 'refund'], true)) {
                    throw new RuntimeException('ส่วนที่เกินยอดค้างต้องเลือกเป็นเครดิตลูกค้าหรือคืนเงิน');
                }
            }
        }

        $documentType = DocumentType::where('code', $typeCode)->firstOrFail();

        return DB::transaction(function () use ($data, $isCredit, $typeCode, $amount, $openItem, $documentType, $settlement) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next($typeCode, $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'],
                'reference' => $openItem?->document->doc_number,
                'credit_settlement' => $settlement,
                'status' => $isCredit ? 'pending_approval' : 'active',
                'total_items' => 1,
                'total_amount' => $amount,
                'remark' => $data['reason'],
                'created_by' => auth()->id(),
            ]);

            if ($isCredit) {
                return $document->fresh();
            }

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
                    'due_date' => now()->addDays((int) (AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))->toDateString(),
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

    /**
     * มูลค่าที่ยังลดหนี้ได้ของใบขายหนึ่งใบ = มูลค่าสุทธิหลังลดหนี้ที่อนุมัติแล้ว (net_amount)
     * หักใบลดหนี้ที่ยังรออนุมัติ เพื่อกันลดซ้ำซ้อนระหว่างรอ
     */
    public function creditableAmount(CustomerOpenItem $openItem, ?int $exceptNoteId = null): float
    {
        $pending = (float) Document::whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_NOTE'))
            ->where('status', 'pending_approval')
            ->where('customer_id', $openItem->customer_id)
            ->where('reference', $openItem->document?->doc_number)
            ->when($exceptNoteId, fn ($q) => $q->whereKeyNot($exceptNoteId))
            ->sum('total_amount');

        return max(0.0, round((float) $openItem->net_amount - $pending, 2));
    }

    public function approveCredit(Document $document): Document
    {
        if ($document->created_by && $document->created_by === auth()->id() && ! auth()->user()?->hasPermission('system.admin')) {
            throw new RuntimeException('ผู้สร้างใบลดหนี้ไม่สามารถอนุมัติรายการของตนเอง');
        }

        return DB::transaction(function () use ($document): Document {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->load('documentType');
            if ($locked->documentType->code !== 'CREDIT_NOTE' || $locked->status !== 'pending_approval') {
                throw new RuntimeException('เอกสารนี้ไม่ใช่ใบลดหนี้ที่รออนุมัติ');
            }
            $sourceDocumentId = Document::where('doc_number', $locked->reference)->value('id');
            $openItem = CustomerOpenItem::where('customer_id', $locked->customer_id)->where('document_id', $sourceDocumentId)->lockForUpdate()->first();
            $amount = (float) $locked->total_amount;
            if (! $openItem || $amount > $this->creditableAmount($openItem, $locked->id) + 0.01) {
                throw new RuntimeException('มูลค่าที่ลดได้ของเอกสารต้นทางเปลี่ยนไป กรุณาตรวจใบลดหนี้ใหม่');
            }

            // ลดยอดค้างได้เท่าที่ค้างอยู่ ส่วนเกินเป็นเครดิตลูกค้าหรือคืนเงินตามที่เลือกไว้
            $reduce = round(min($amount, (float) $openItem->balance_amount), 2);
            $excess = round($amount - $reduce, 2);
            if ($excess > 0.01 && ! in_array($locked->credit_settlement, ['credit', 'refund'], true)) {
                throw new RuntimeException('ยอดค้างของใบต้นทางลดลงหลังออกใบลดหนี้ ต้องเลือกว่าส่วนเกินเป็นเครดิตหรือคืนเงิน');
            }
            $refund = $locked->credit_settlement === 'refund' ? $excess : 0.0;

            $newBalance = round((float) $openItem->balance_amount - $reduce, 2);
            $openItem->update([
                'net_amount' => round((float) $openItem->net_amount - $amount, 2), 'balance_amount' => $newBalance,
                'status' => $newBalance <= 0.01 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL,
            ]);
            // สมุดลูกหนี้: เงินที่คืนสดออกไปแล้วไม่ค้างเป็นเครดิต จึงลดยอดเฉพาะส่วนที่ไม่ได้คืนเงิน
            $ledgerCredit = round($amount - $refund, 2);
            $lastBalance = (float) (CustomerLedger::where('customer_id', $locked->customer_id)->latest('id')->value('balance_after') ?? 0);
            CustomerLedger::create([
                'customer_id' => $locked->customer_id, 'document_id' => $locked->id, 'entry_type' => 'credit',
                'amount' => $ledgerCredit, 'balance_after' => round($lastBalance - $ledgerCredit, 2), 'entry_date' => now()->toDateString(),
            ]);
            $this->glPosting->postCreditNote($locked, $refund);
            $locked->update(['status' => 'active']);

            return $locked->fresh();
        });
    }
}
