<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\GlJournal;
use App\Models\PaymentDocument;

/**
 * Posts double-entry GL journal lines (gl_journals, legacy: TRANPAYJ) for a
 * payment_document. The schema only journals payments (not sales/purchases/
 * stock - there's no account linkage on those tables), so this is intentionally
 * narrow: receipt = debit cash, credit AR; payment voucher = debit AP, credit
 * cash. Silently does nothing if the relevant default_role accounts haven't
 * been configured yet in chart_of_accounts - payments still work without GL
 * lines, the accountant just won't see them in the general journal until the
 * accounts are set up.
 */
class GlPostingService
{
    public function postCustomerReceipt(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark): void
    {
        $cashAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_CASH)->first();
        $arAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AR)->first();
        if (! $cashAccount || ! $arAccount) {
            return;
        }

        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $cashAccount->id,
            'debit' => $amount,
            'credit' => 0,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $arAccount->id,
            'debit' => 0,
            'credit' => $amount,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
    }

    public function postSupplierPayment(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark): void
    {
        $cashAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_CASH)->first();
        $apAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AP)->first();
        if (! $cashAccount || ! $apAccount) {
            return;
        }

        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $apAccount->id,
            'debit' => $amount,
            'credit' => 0,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => $amount,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
    }

    // อัตรา VAT ปัจจุบัน (ไม่มี = 7%)
    private function vatRate(): float
    {
        $rate = \Illuminate\Support\Facades\DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent');

        return $rate !== null ? (float) $rate : 7.0;
    }

    private function role(string $role): ?ChartOfAccount
    {
        return ChartOfAccount::where('default_role', $role)->first();
    }

    /**
     * สร้างคู่ debit/credit ให้ครบ - ถ้าบัญชี role ไหนยังไม่ได้ตั้งจะข้ามทั้งชุด
     * (เอกสารยังบันทึกได้ปกติ นักบัญชีแค่ยังไม่เห็นใน GL จนกว่าจะตั้งผังบัญชี)
     *
     * @param  array<int, array{role:string, debit?:float, credit?:float}>  $lines
     */
    private function postDocument(Document $document, array $lines, string $remark): void
    {
        $resolved = [];
        foreach ($lines as $line) {
            $account = $this->role($line['role']);
            if (! $account) {
                return; // ผังบัญชียังไม่ครบ - ข้ามทั้งเอกสารเพื่อไม่ให้ GL ไม่ดุล
            }
            $resolved[] = [$account->id, round($line['debit'] ?? 0, 2), round($line['credit'] ?? 0, 2)];
        }

        // ล้างรายการเดิมของเอกสารนี้ก่อน (กันโพสต์ซ้ำ)
        GlJournal::where('document_id', $document->id)->delete();

        foreach ($resolved as [$accountId, $debit, $credit]) {
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }
            GlJournal::create([
                'document_id' => $document->id,
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'remark' => $remark,
                'entry_date' => $document->doc_date->toDateString(),
            ]);
        }
    }

    // ต้นทุนขาย: ขาย = Dr ต้นทุนขาย / Cr สินค้าคงเหลือ; รับคืน (reverse) = ตรงข้าม
    // แนบเข้าไปในเอกสารเดิม โดยไม่ล้าง (append) รายการที่ post ไว้ก่อน
    private function appendCogs(Document $document, string $remark, bool $reverse = false): void
    {
        $cogs = app(\App\Services\Inventory\CostingService::class)->cogsForDocument($document);
        if ($cogs <= 0) {
            return;
        }

        $cogsAccount = $this->role(ChartOfAccount::ROLE_COGS);
        $inventoryAccount = $this->role(ChartOfAccount::ROLE_INVENTORY);
        if (! $cogsAccount || ! $inventoryAccount) {
            return;
        }

        // ขาย: Dr COGS / Cr Inventory | รับคืน: Dr Inventory / Cr COGS (สินค้ากลับเข้าคลัง)
        [$cogsDr, $cogsCr, $invDr, $invCr] = $reverse ? [0, $cogs, $cogs, 0] : [$cogs, 0, 0, $cogs];
        $label = ($reverse ? 'กลับต้นทุนขาย ' : 'ต้นทุนขาย ').$remark;

        GlJournal::create([
            'document_id' => $document->id, 'account_id' => $cogsAccount->id,
            'debit' => $cogsDr, 'credit' => $cogsCr, 'remark' => $label,
            'entry_date' => $document->doc_date->toDateString(),
        ]);
        GlJournal::create([
            'document_id' => $document->id, 'account_id' => $inventoryAccount->id,
            'debit' => $invDr, 'credit' => $invCr, 'remark' => $label,
            'entry_date' => $document->doc_date->toDateString(),
        ]);
    }

    // ขายเชื่อ: Dr ลูกหนี้ / Cr รายได้ + ภาษีขาย (ราคารวม VAT) + ต้นทุนขาย
    public function postCreditSale(Document $document): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_AR, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => round($total - $base, 2)],
        ], 'ขายเชื่อ '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number);
    }

    // ขายสด: Dr เงินสด / Cr รายได้ + ภาษีขาย + ต้นทุนขาย
    public function postCashSale(Document $document): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_CASH, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => round($total - $base, 2)],
        ], 'ขายสด '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number);
    }

    public function reverseDocument(Document $document, string $remark): void
    {
        $lines = GlJournal::where('document_id', $document->id)
            ->where('remark', 'not like', 'VOID REVERSAL:%')
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $alreadyReversed = GlJournal::where('document_id', $document->id)
            ->where('remark', 'like', 'VOID REVERSAL:%')
            ->exists();
        if ($alreadyReversed) {
            return;
        }

        foreach ($lines as $line) {
            GlJournal::create([
                'document_id' => $document->id,
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'remark' => 'VOID REVERSAL: '.$remark,
                'entry_date' => now()->toDateString(),
            ]);
        }
    }

    // ซื้อ: Dr สินค้าคงเหลือ + ภาษีซื้อ / Cr เจ้าหนี้ (เครดิต) หรือเงินสด
    public function postPurchase(Document $document, bool $isCredit = true): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_INVENTORY, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_INPUT, 'debit' => round($total - $base, 2)],
            ['role' => $isCredit ? ChartOfAccount::ROLE_AP : ChartOfAccount::ROLE_CASH, 'credit' => $total],
        ], 'ซื้อสินค้า '.$document->doc_number);
    }

    // รับคืนสินค้า: Dr รับคืน + ภาษีขาย(กลับ) / Cr ลูกหนี้ (ขายเชื่อ) หรือเงินสด
    // (ขายสด) + กลับต้นทุนขาย (สินค้ากลับเข้าคลัง). $againstAr = คืนที่ลดลูกหนี้
    public function postSaleReturn(Document $document, bool $againstAr = true): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => round($total - $base, 2)],
            ['role' => $againstAr ? ChartOfAccount::ROLE_AR : ChartOfAccount::ROLE_CASH, 'credit' => $total],
        ], 'รับคืนสินค้า '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number, reverse: true);
    }

    // ใบลดหนี้: Dr รับคืน/ส่วนลด + ภาษีขาย(กลับ) / Cr ลูกหนี้
    public function postCreditNote(Document $document): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => round($total - $base, 2)],
            ['role' => ChartOfAccount::ROLE_AR, 'credit' => $total],
        ], 'ใบลดหนี้ '.$document->doc_number);
    }

    // ใบเพิ่มหนี้: Dr ลูกหนี้ / Cr รายได้ + ภาษีขาย
    public function postDebitNote(Document $document): void
    {
        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $this->vatRate()), 2);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_AR, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => round($total - $base, 2)],
        ], 'ใบเพิ่มหนี้ '.$document->doc_number);
    }
}
