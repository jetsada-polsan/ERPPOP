<?php

namespace App\Services\Sales;

use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentAllocation;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Services\Accounting\GlPostingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a payment received from a customer (รับชำระหนี้) and allocates it
 * against one or more of their open AR items (customer_open_items), reducing
 * balance_amount and flipping status to partial/paid. This is the missing piece
 * that closes the loop opened by CreditSaleService - without it, AR balances
 * just accumulate forever.
 */
class CustomerPaymentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
    ) {}

    /**
     * @param  array{customer_id:int, branch_id:int, method:string, allocations: array<int, array{customer_open_item_id:int, amount:float}>}  $data
     */
    public function create(array $data): Document
    {
        $allocations = collect($data['allocations'])->filter(fn ($a) => (float) $a['amount'] > 0);
        if ($allocations->isEmpty()) {
            throw new RuntimeException('ต้องระบุยอดชำระอย่างน้อย 1 รายการ');
        }

        $openItems = CustomerOpenItem::whereIn('id', $allocations->pluck('customer_open_item_id'))
            ->where('customer_id', $data['customer_id'])
            ->get()
            ->keyBy('id');

        foreach ($allocations as $allocation) {
            $item = $openItems->get($allocation['customer_open_item_id']);
            if (! $item) {
                throw new RuntimeException('ไม่พบรายการลูกหนี้ที่เลือก');
            }
            if ((float) $allocation['amount'] > (float) $item->balance_amount + 0.01) {
                throw new RuntimeException("ยอดชำระเกินยอดค้างของเอกสาร {$item->document->doc_number}");
            }
        }

        $documentType = DocumentType::where('code', 'RECEIPT')->firstOrFail();
        $totalAmount = $allocations->sum('amount');

        return DB::transaction(function () use ($data, $documentType, $totalAmount, $allocations, $openItems) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('RECEIPT', $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'],
                'status' => 'active',
                'total_items' => $allocations->count(),
                'total_amount' => $totalAmount,
            ]);

            $paymentDocument = PaymentDocument::create([
                'document_id' => $document->id,
                'party_type' => 'customer',
                'customer_id' => $data['customer_id'],
                'branch_id' => $data['branch_id'],
                'status' => 'active',
            ]);

            PaymentLine::create([
                'payment_document_id' => $paymentDocument->id,
                'seq' => 1,
                'method' => $data['method'],
                'amount' => $totalAmount,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
            ]);

            // รับชำระด้วยเช็ค -> ลงทะเบียนเช็ครับอัตโนมัติ (สถานะ: ในมือ รอนำฝาก)
            if ($data['method'] === 'cheque') {
                \App\Models\Cheque::create([
                    'direction' => 'in',
                    'cheque_no' => $data['cheque_no'] ?? $document->doc_number,
                    'bank_name' => $data['cheque_bank'] ?? null,
                    'branch_id' => $data['branch_id'],
                    'amount' => $totalAmount,
                    'cheque_date' => $data['cheque_due_date'] ?? now()->toDateString(),
                    'customer_id' => $data['customer_id'],
                    'payment_document_id' => $paymentDocument->id,
                    'status' => 'on_hand',
                ]);
            }

            foreach ($allocations as $allocation) {
                $item = $openItems->get($allocation['customer_open_item_id']);
                $amount = (float) $allocation['amount'];

                PaymentAllocation::create([
                    'payment_document_id' => $paymentDocument->id,
                    'customer_open_item_id' => $item->id,
                    'allocated_amount' => $amount,
                ]);

                $newBalance = round((float) $item->balance_amount - $amount, 4);
                $item->update([
                    'paid_amount' => (float) $item->paid_amount + $amount,
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0.01 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL,
                ]);
            }

            $this->glPosting->postCustomerReceipt($paymentDocument, (float) $totalAmount, $document->doc_date->toDateString(), $document->doc_number);

            return $document->fresh();
        });
    }
}
