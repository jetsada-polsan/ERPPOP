<?php

namespace App\Services\Purchasing;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Models\SupplierLedger;
use App\Services\Accounting\GlPostingService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a payment made to a supplier (จ่ายชำระหนี้เจ้าหนี้), reducing their
 * running balance. Unlike the customer side, suppliers don't have discrete open
 * items here (supplier_ledger is a running balance, not per-invoice tracking) -
 * a payment is just a "debit" entry against the same ledger PurchaseService
 * "credits" when goods are received on credit.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
    ) {}

    /**
     * @param  array{supplier_id:int, branch_id:int, method:string, amount:float}  $data
     */
    public function create(array $data): Document
    {
        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('ยอดชำระต้องมากกว่า 0');
        }

        $currentBalance = (float) (SupplierLedger::where('supplier_id', $data['supplier_id'])
            ->orderByDesc('id')
            ->value('balance_after') ?? 0);

        if ($amount > $currentBalance + 0.01) {
            throw new RuntimeException('ยอดชำระเกินยอดหนี้คงค้าง');
        }

        $documentType = DocumentType::where('code', 'PAYMENT_VOUCHER')->firstOrFail();

        return DB::transaction(function () use ($data, $documentType, $amount, $currentBalance) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('PAYMENT_VOUCHER', $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'supplier_id' => $data['supplier_id'],
                'status' => 'active',
                'total_items' => 1,
                'total_amount' => $amount,
            ]);

            $paymentDocument = PaymentDocument::create([
                'document_id' => $document->id,
                'party_type' => 'supplier',
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'status' => 'active',
            ]);

            PaymentLine::create([
                'payment_document_id' => $paymentDocument->id,
                'seq' => 1,
                'method' => $data['method'],
                'amount' => $amount,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
            ]);

            // จ่ายด้วยเช็ค -> ลงทะเบียนเช็คจ่ายอัตโนมัติ (สถานะ: ออกเช็ค รอตัดบัญชี)
            if ($data['method'] === 'cheque') {
                \App\Models\Cheque::create([
                    'direction' => 'out',
                    'cheque_no' => $data['cheque_no'] ?? $document->doc_number,
                    'bank_name' => $data['cheque_bank'] ?? null,
                    'branch_id' => $data['branch_id'],
                    'amount' => $amount,
                    'cheque_date' => $data['cheque_due_date'] ?? now()->toDateString(),
                    'supplier_id' => $data['supplier_id'],
                    'payment_document_id' => $paymentDocument->id,
                    'status' => 'issued',
                ]);
            }

            SupplierLedger::create([
                'supplier_id' => $data['supplier_id'],
                'document_id' => $document->id,
                'entry_type' => 'debit',
                'amount' => $amount,
                'balance_after' => round($currentBalance - $amount, 4),
                'entry_date' => now()->toDateString(),
            ]);

            $this->glPosting->postSupplierPayment($paymentDocument, $amount, $document->doc_date->toDateString(), $document->doc_number);

            return $document->fresh();
        });
    }
}
