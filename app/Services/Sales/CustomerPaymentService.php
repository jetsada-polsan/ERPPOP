<?php

namespace App\Services\Sales;

use App\Models\ChartOfAccount;
use App\Models\Cheque;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentAllocation;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Services\Accounting\CashBookPostingService;
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
        private readonly CashBookPostingService $cashBook,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     branch_id: int,
     *     salesman_id?: ?int,
     *     doc_date?: ?string,
     *     method?: string,
     *     payments?: array<int, array{method: string, amount: float, bank_account_id?: ?int, transfer_ref?: ?string, transfer_at?: ?string, cheque_no?: ?string, cheque_due_date?: ?string, cheque_bank?: ?string}>,
     *     allocations: array<int, array{customer_open_item_id: int, amount: float}>
     * }  $data
     */
    public function create(array $data): Document
    {
        $allocations = collect($data['allocations'] ?? [])->filter(fn ($a) => (float) ($a['amount'] ?? 0) > 0);
        if ($allocations->isEmpty()) {
            throw new RuntimeException('ต้องระบุยอดชำระอย่างน้อย 1 รายการ');
        }

        $itemIds = $allocations->pluck('customer_open_item_id');
        $rawItems = CustomerOpenItem::whereIn('id', $itemIds)->get()->keyBy('id');
        $openItems = collect();

        foreach ($allocations as $allocation) {
            $itemId = $allocation['customer_open_item_id'];
            $item = $rawItems->get($itemId);
            if (! $item) {
                throw new RuntimeException('ไม่พบรายการลูกหนี้ที่เลือก');
            }
            if ((int) $item->customer_id !== (int) $data['customer_id']) {
                throw new RuntimeException('มีรายการบิลที่ไม่ใช่ของลูกค้ารายนี้');
            }
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            $discount = round((float) ($allocation['discount_amount'] ?? 0), 2);
            $wht = round((float) ($allocation['wht_amount'] ?? 0), 2);

            if ($discount < 0 || $wht < 0 || round($discount + $wht, 2) > $amount + 0.001) {
                throw new RuntimeException('ยอดหัก ณ ที่จ่ายหรือส่วนลดเกินยอดตัดหนี้');
            }
            if ($amount > (float) $item->balance_amount + 0.01) {
                throw new RuntimeException("ยอดชำระเกินยอดค้างของเอกสาร {$item->document?->doc_number}");
            }
            $openItems->put($item->id, $item);
        }

        $totalAllocated = round((float) $allocations->sum(fn ($a) => (float) ($a['amount'] ?? 0)), 2);
        $totalDiscount = round((float) $allocations->sum(fn ($a) => (float) ($a['discount_amount'] ?? 0)), 2);
        $totalWht = round((float) $allocations->sum(fn ($a) => (float) ($a['wht_amount'] ?? 0)), 2);
        $totalNet = round($totalAllocated - $totalDiscount - $totalWht, 2);

        // Normalize payment lines: support multiple payments[] or legacy single method
        if (! empty($data['payments'])) {
            $payments = collect($data['payments'])
                ->filter(fn ($p) => (float) ($p['amount'] ?? 0) > 0)
                ->map(function ($p) {
                    $m = $p['method'] ?? 'cash';

                    return [
                        'method' => $m,
                        'amount' => round((float) ($p['amount'] ?? 0), 2),
                        'bank_account_id' => ($m !== 'cash' && ! empty($p['bank_account_id'])) ? (int) $p['bank_account_id'] : null,
                        'transfer_ref' => $m === 'transfer' ? ($p['transfer_ref'] ?? null) : null,
                        'transfer_at' => $m === 'transfer' ? ($p['transfer_at'] ?? null) : null,
                        'cheque_no' => $m === 'cheque' ? ($p['cheque_no'] ?? null) : null,
                        'cheque_due_date' => $m === 'cheque' ? ($p['cheque_due_date'] ?? null) : null,
                        'cheque_bank' => $m === 'cheque' ? ($p['cheque_bank'] ?? null) : null,
                    ];
                })
                ->values();
        } else {
            $m = $data['method'] ?? 'cash';
            $payments = collect([[
                'method' => $m,
                'amount' => $totalNet,
                'bank_account_id' => $this->bankAccountId($data),
                'transfer_ref' => $m === 'transfer' ? ($data['transfer_ref'] ?? null) : null,
                'transfer_at' => $m === 'transfer' ? ($data['transfer_at'] ?? null) : null,
                'cheque_no' => $m === 'cheque' ? ($data['cheque_no'] ?? null) : null,
                'cheque_due_date' => $m === 'cheque' ? ($data['cheque_due_date'] ?? null) : null,
                'cheque_bank' => $m === 'cheque' ? ($data['cheque_bank'] ?? null) : null,
            ]]);
        }

        if ($payments->isEmpty()) {
            throw new RuntimeException('ต้องระบุยอดรับชำระอย่างน้อย 1 รายการ');
        }

        $totalPaid = round((float) $payments->sum('amount'), 2);

        if (abs($totalPaid - $totalNet) > 0.009) {
            throw new RuntimeException('ยอดชำระรวม ('.number_format($totalPaid, 2).') ไม่เท่ากับยอดจัดสรร ('.number_format($totalNet, 2).')');
        }

        // วันที่ใบเสร็จเลือกย้อนหลังได้ ต้องไม่ตกในงวดบัญชีที่ปิดแล้ว (ตัวลงบัญชีเดิมตรวจแค่วันนี้)
        if (! empty($data['doc_date'])) {
            app(\App\Services\Accounting\AccountingPeriodGuard::class)->assertOpen((string) $data['doc_date'], (int) $data['branch_id']);
        }

        if ($totalWht > 0 && ! $this->glPosting->role(ChartOfAccount::ROLE_WHT_RECEIVABLE)) {
            throw new RuntimeException('ผังบัญชีภาษีหัก ณ ที่จ่าย (wht_receivable) ยังตั้งค่าไม่ครบ ไม่สามารถรับชำระโดยไม่ลง GL ได้');
        }

        if ($totalDiscount > 0 && ! $this->glPosting->role(ChartOfAccount::ROLE_SALES_RETURN)) {
            throw new RuntimeException('ผังบัญชีส่วนลดจ่าย (sales_return) ยังตั้งค่าไม่ครบ ไม่สามารถรับชำระโดยไม่ลง GL ได้');
        }

        $documentType = DocumentType::where('code', 'RECEIPT')->firstOrFail();

        return DB::transaction(function () use ($data, $documentType, $totalAllocated, $totalDiscount, $totalWht, $allocations, $openItems, $payments) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('RECEIPT', $data['branch_id']),
                'doc_date' => $data['doc_date'] ?? now()->toDateString(),
                'customer_id' => $data['customer_id'],
                'salesman_id' => ! empty($data['salesman_id']) ? (int) $data['salesman_id'] : null,
                'reference' => $data['wht_cert_no'] ?? null,
                'status' => 'active',
                'total_items' => $allocations->count(),
                'total_amount' => $totalAllocated,
            ]);

            $paymentDocument = PaymentDocument::create([
                'document_id' => $document->id,
                'party_type' => 'customer',
                'customer_id' => $data['customer_id'],
                'branch_id' => $data['branch_id'],
                'salesman_id' => ! empty($data['salesman_id']) ? (int) $data['salesman_id'] : null,
                'status' => 'active',
                'wht_cert_no' => $data['wht_cert_no'] ?? null,
                'wht_cert_date' => $data['wht_cert_date'] ?? null,
            ]);

            $seq = 1;
            $totalCash = 0.0;

            foreach ($payments as $pay) {
                $payMethod = $pay['method'];
                $payAmount = (float) $pay['amount'];
                $bankAccountId = $pay['bank_account_id'];

                PaymentLine::create([
                    'payment_document_id' => $paymentDocument->id,
                    'seq' => $seq++,
                    'method' => $payMethod,
                    'bank_account_id' => $bankAccountId,
                    'amount' => $payAmount,
                    'cheque_no' => $pay['cheque_no'] ?? null,
                    'cheque_due_date' => $pay['cheque_due_date'] ?? null,
                    'transfer_ref' => $pay['transfer_ref'] ?? null,
                    'transfer_at' => $pay['transfer_at'] ?? null,
                ]);

                // รับชำระด้วยเช็ค -> ลงทะเบียนเช็ครับอัตโนมัติ (สถานะ: ในมือ รอนำฝาก)
                if ($payMethod === 'cheque') {
                    Cheque::create([
                        'direction' => 'in',
                        'cheque_no' => $pay['cheque_no'] ?? $document->doc_number,
                        'bank_name' => $pay['cheque_bank'] ?? null,
                        'branch_id' => $data['branch_id'],
                        'amount' => $payAmount,
                        'cheque_date' => $pay['cheque_due_date'] ?? $document->doc_date->toDateString(),
                        'customer_id' => $data['customer_id'],
                        'payment_document_id' => $paymentDocument->id,
                        'status' => 'on_hand',
                    ]);
                }

                // เรียกตัวช่วยลงบัญชีเดิมต่อแถว: Dr เงินสด/ธนาคาร, Cr ลูกหนี้
                $this->glPosting->postCustomerReceipt(
                    $paymentDocument,
                    $payAmount,
                    $document->doc_date->toDateString(),
                    $document->doc_number,
                    $payMethod,
                    $bankAccountId
                );

                if ($payMethod === 'cash') {
                    $totalCash += $payAmount;
                }
            }

            // บันทึกบัญชีหัก ณ ที่จ่าย และ ส่วนลดเงินสด (เดบิต WHT/Discount, เครดิต AR)
            if ($totalWht > 0) {
                $this->glPosting->postCustomerWhtDeduction(
                    $paymentDocument,
                    $totalWht,
                    $document->doc_date->toDateString(),
                    $document->doc_number
                );
            }

            if ($totalDiscount > 0) {
                $this->glPosting->postCustomerDiscount(
                    $paymentDocument,
                    $totalDiscount,
                    $document->doc_date->toDateString(),
                    $document->doc_number
                );
            }

            foreach ($allocations as $allocation) {
                $item = $openItems->get($allocation['customer_open_item_id']);
                $amount = round((float) $allocation['amount'], 2);
                $discount = round((float) ($allocation['discount_amount'] ?? 0), 2);
                $wht = round((float) ($allocation['wht_amount'] ?? 0), 2);

                PaymentAllocation::create([
                    'payment_document_id' => $paymentDocument->id,
                    'customer_open_item_id' => $item->id,
                    'allocated_amount' => $amount,
                    'discount_amount' => $discount,
                    'wht_amount' => $wht,
                ]);

                $newBalance = round((float) $item->balance_amount - $amount, 4);
                $item->update([
                    'paid_amount' => (float) $item->paid_amount + $amount,
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0.01 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL,
                ]);
            }

            if ($totalCash > 0) {
                $this->cashBook->postCustomerPayment($document, (float) $totalCash);
            }

            return $document->fresh();
        });
    }

    /**
     * เช็ครับเด้ง: เดิมแค่เปลี่ยนสถานะเช็ค หนี้ของลูกค้ายังเป็น "จ่ายแล้ว" และบัญชีธนาคาร
     * ยังนับเงินที่ไม่เคยได้ ตอนนี้คืนยอดค้างให้ทุกบิลที่เช็คใบนี้เคยตัด กลับรายการบัญชี
     * และปิดใบเสร็จนั้น ส่วนเช็คที่ลงทะเบียนเองโดยไม่ผูกใบเสร็จ เปลี่ยนแค่สถานะเหมือนเดิม
     */
    public function bounceCheque(Cheque $cheque, ?string $reason = null): Cheque
    {
        return DB::transaction(function () use ($cheque, $reason): Cheque {
            $locked = Cheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();
            if ($locked->direction !== 'in' || $locked->isFinal()) {
                throw new RuntimeException('สถานะเช็คไม่ถูกต้อง');
            }
            $note = 'เช็คคืน '.now()->thaiDate().($reason ? ': '.$reason : '');
            $locked->update([
                'status' => 'bounced',
                'remark' => trim(($locked->remark ? $locked->remark.' | ' : '').$note),
            ]);

            $payment = $locked->payment_document_id
                ? PaymentDocument::whereKey($locked->payment_document_id)->lockForUpdate()->first()
                : null;
            if (! $payment || $payment->status === 'bounced') {
                return $locked->fresh();
            }

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

            $receipt = $payment->document;
            $this->glPosting->reverseCustomerReceipt($payment, ($receipt?->doc_number ?? 'เช็ค '.$locked->cheque_no).' เช็ค '.$locked->cheque_no);
            $payment->update(['status' => 'bounced']);
            $receipt?->update([
                'status' => 'void',
                'remark' => trim(($receipt->remark ? $receipt->remark.' | ' : '').$note.' เช็ค '.$locked->cheque_no),
            ]);

            return $locked->fresh();
        });
    }

    /** บัญชีธนาคารที่ผู้ใช้เลือก ใช้เฉพาะโอน/เช็ค (เงินสดไม่เกี่ยว) */
    private function bankAccountId(array $data): ?int
    {
        return ($data['method'] ?? 'cash') !== 'cash' && ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
    }
}
