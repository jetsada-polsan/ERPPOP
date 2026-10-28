<?php

namespace App\Services\Purchasing;

use App\Models\Cheque;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentAllocation;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Services\Accounting\GlPostingService;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
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
     * ถ้าส่ง allocations มา (เลือกใบซื้อค้างจ่ายเอง) ยอดจ่าย = ผลรวมของ allocations
     * และตัดเฉพาะใบเหล่านั้น ถ้าไม่ส่ง ตัดใบเก่าสุดก่อนตามยอด amount เหมือนเดิม
     *
     * @param  array{supplier_id:int, branch_id:int, method:string, amount?:float, allocations?: array<int, array{supplier_open_item_id:int, amount:float}>}  $data
     */
    public function create(array $data): Document
    {
        $allocations = array_values(array_filter(
            $data['allocations'] ?? [],
            fn ($row) => (float) ($row['amount'] ?? 0) > 0,
        ));
        $amount = $allocations !== []
            ? round(array_sum(array_map(fn ($row) => round((float) $row['amount'], 2), $allocations)), 2)
            : (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('ยอดชำระต้องมากกว่า 0');
        }

        $documentType = DocumentType::where('code', 'PAYMENT_VOUCHER')->firstOrFail();

        return DB::transaction(function () use ($data, $documentType, $amount, $allocations) {
            $supplier = Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            if ($allocations !== []) {
                // ตรวจรายใบก่อน ข้อความผิดพลาดจะได้บอกได้ว่าใบไหน ไม่ใช่แค่ "เกินยอดหนี้"
                $this->openItems->assertAllocatable($supplier->id, collect($allocations)
                    ->mapWithKeys(fn ($row) => [(int) $row['supplier_open_item_id'] => round((float) $row['amount'], 2)])->all());
            }
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
                'bank_account_id' => $this->bankAccountId($data),
                'amount' => $net,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
            ]);

            // จ่ายด้วยเช็ค -> ลงทะเบียนเช็คจ่ายอัตโนมัติ (สถานะ: ออกเช็ค รอตัดบัญชี)
            if ($data['method'] === 'cheque') {
                Cheque::create([
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

            // ตัดใบที่ค้าง: ตามที่ผู้ใช้เลือก หรือเรียงใบเก่าก่อน เพื่อให้ AP aging ลดลงตามจริง
            // แล้วจำไว้ว่าตัดใบไหนเท่าไร ใช้ตอนยกเลิกใบจ่ายและตอบว่าใบซื้อจ่ายด้วยใบจ่ายไหน
            $applied = $allocations !== []
                ? $this->openItems->applyToItems((int) $data['supplier_id'], $allocations)
                : $this->openItems->applyPaymentFifo((int) $data['supplier_id'], (float) $amount);
            $whtLeft = $tax;
            $lastItemId = array_key_last($applied);
            foreach ($applied as $itemId => $allocated) {
                // ภาษีหัก ณ ที่จ่ายเฉลี่ยตามสัดส่วนยอดที่ตัด ใบสุดท้ายรับเศษ
                $wht = $itemId === $lastItemId ? $whtLeft : round($tax * $allocated / $amount, 2);
                $whtLeft = round($whtLeft - $wht, 2);
                PaymentAllocation::create([
                    'payment_document_id' => $paymentDocument->id,
                    'supplier_open_item_id' => $itemId,
                    'allocated_amount' => $allocated,
                    'wht_amount' => $wht,
                ]);
            }

            if ($tax > 0) {
                DB::table('supplier_withholdings')->insert([
                    'payment_document_id' => $paymentDocument->id, 'certificate_no' => 'WHT-'.$document->doc_number,
                    'paid_on' => $document->doc_date->toDateString(), 'form' => $data['withholding_form'],
                    'income_type' => $data['withholding_income_type'], 'base_amount' => $base, 'rate' => $rate,
                    'tax_amount' => $tax, 'supplier_name' => $supplier->name_th, 'supplier_tax_id' => $supplier->tax_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->glPosting->postSupplierPayment($paymentDocument, $amount, $document->doc_date->toDateString(), $document->doc_number, $tax, $data['method'], $this->bankAccountId($data));

            return $document->fresh();
        });
    }

    /** บัญชีธนาคารที่ผู้ใช้เลือก ใช้เฉพาะโอน/เช็ค (เงินสดไม่เกี่ยว) */
    private function bankAccountId(array $data): ?int
    {
        return ($data['method'] ?? 'cash') !== 'cash' && ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
    }
}
