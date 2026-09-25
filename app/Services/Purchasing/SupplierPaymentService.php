<?php

namespace App\Services\Purchasing;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Models\SupplierLedger;
use App\Models\Supplier;
use App\Support\DecimalMath;
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
        private readonly SupplierOpenItemService $openItems,
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

        $documentType = DocumentType::where('code', 'PAYMENT_VOUCHER')->firstOrFail();

        return DB::transaction(function () use ($data, $documentType, $amount) {
            $supplier = Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            $currentBalance = (float) (SupplierLedger::where('supplier_id', $supplier->id)->latest('id')->value('balance_after') ?? 0);
            if ($amount > $currentBalance + 0.0001) {
                throw new RuntimeException('ยอดชำระเกินยอดหนี้คงค้าง');
            }
            $rate = (float) ($data['withholding_rate'] ?? 0);
            $base = (float) ($data['withholding_base'] ?? 0);
            if ($rate < 0 || $rate >= 100 || $base < 0 || $base > $amount) {
                throw new RuntimeException('ฐานหรืออัตราภาษีหัก ณ ที่จ่ายไม่ถูกต้อง');
            }
            $tax = (float) DecimalMath::round(DecimalMath::divide(DecimalMath::multiply($base, $rate), 100), 2);
            if ($rate > 0 && ($tax <= 0 || ! preg_match('/^\d{13}$/', $supplier->tax_id ?? '')
                || ! in_array($data['withholding_form'] ?? '', ['PND3', 'PND53'], true)
                || empty($data['withholding_income_type']))) {
                throw new RuntimeException('ข้อมูลผู้ถูกหักภาษี แบบภาษี และประเภทเงินได้ต้องครบ');
            }
            $net = round($amount - $tax, 2);
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
                'amount' => $net,
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
                    'amount' => $net,
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

            // ตัดใบที่ค้างเรียงใบเก่าก่อน เพื่อให้ AP aging ลดลงตามจริง ไม่ใช่แค่ยอดรวมใน ledger
            $this->openItems->applyPayment((int) $data['supplier_id'], (float) $amount);

            if ($tax > 0) {
                DB::table('supplier_withholdings')->insert([
                    'payment_document_id' => $paymentDocument->id, 'certificate_no' => 'WHT-'.$document->doc_number,
                    'paid_on' => $document->doc_date->toDateString(), 'form' => $data['withholding_form'],
                    'income_type' => $data['withholding_income_type'], 'base_amount' => $base, 'rate' => $rate,
                    'tax_amount' => $tax, 'supplier_name' => $supplier->name_th, 'supplier_tax_id' => $supplier->tax_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->glPosting->postSupplierPayment($paymentDocument, $amount, $document->doc_date->toDateString(), $document->doc_number, $tax, $data['method']);

            return $document->fresh();
        });
    }
}
