<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\GlJournal;
use App\Models\PaymentDocument;
use App\Services\Inventory\CostingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts double-entry GL journal lines (gl_journals, legacy: TRANPAYJ) for a
 * payment_document. The schema only journals payments (not sales/purchases/
 * stock - there's no account linkage on those tables), so this is intentionally
 * narrow: receipt = debit cash, credit AR; payment voucher = debit AP, credit
 * cash. Missing account mappings are fatal so an operational document can never
 * be committed without its required accounting entry.
 */
class GlPostingService
{
    public function postCustomerReceipt(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark, string $method = 'cash'): void
    {
        // รับโอน/เช็คไม่ได้เข้าลิ้นชัก — เดิมลงเงินสดทุกวิธี ยอดเงินสดในบัญชีเกินจริง ยอดธนาคารขาด
        $cashAccount = ChartOfAccount::where('default_role', $method === 'cash' ? ChartOfAccount::ROLE_CASH : ChartOfAccount::ROLE_BANK)->first();
        $arAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AR)->first();
        if (! $cashAccount || ! $arAccount) {
            throw new RuntimeException($method === 'cash'
                ? 'ผังบัญชีเงินสดหรือลูกหนี้ยังไม่ครบ ไม่สามารถรับชำระโดยไม่ลง GL ได้'
                : 'ผังบัญชีธนาคารหรือลูกหนี้ยังไม่ครบ ไม่สามารถรับชำระด้วยโอน/เช็คโดยไม่ลง GL ได้');
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

    /**
     * เช็ครับเด้ง: กลับรายการรับชำระของใบนั้นทั้งชุด (Dr ลูกหนี้ / Cr ธนาคาร) ลงวันที่เช็คเด้ง
     * ไม่ลบรายการเดิม ประวัติว่าเคยรับและเคยเด้งต้องตรวจย้อนได้ เรียกซ้ำก็ไม่กลับซ้ำ
     */
    public function reverseCustomerReceipt(PaymentDocument $paymentDocument, string $remark): void
    {
        $lines = GlJournal::where('payment_document_id', $paymentDocument->id)
            ->where('remark', 'not like', 'เช็คคืน:%')->get();
        if ($lines->isEmpty() || GlJournal::where('payment_document_id', $paymentDocument->id)
            ->where('remark', 'like', 'เช็คคืน:%')->exists()) {
            return;
        }
        foreach ($lines as $line) {
            GlJournal::create([
                'payment_document_id' => $paymentDocument->id,
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'remark' => 'เช็คคืน: '.$remark,
                'entry_date' => now()->toDateString(),
            ]);
        }
    }

    public function postSupplierPayment(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark, float $withholding = 0, string $method = 'cash'): void
    {
        $cashAccount = ChartOfAccount::where('default_role', $method === 'cash' ? ChartOfAccount::ROLE_CASH : ChartOfAccount::ROLE_BANK)->first();
        $apAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AP)->first();
        if (! $cashAccount || ! $apAccount) {
            throw new RuntimeException('ผังบัญชีเงินสดหรือเจ้าหนี้ยังไม่ครบ ไม่สามารถจ่ายชำระโดยไม่ลง GL ได้');
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
            'credit' => round($amount - $withholding, 2),
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
        if ($withholding > 0) {
            $account = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_WHT_PAYABLE)->sole();
            GlJournal::create(['payment_document_id' => $paymentDocument->id, 'account_id' => $account->id,
                'debit' => 0, 'credit' => $withholding, 'remark' => $remark, 'entry_date' => $entryDate]);
        }
    }

    // อัตรา VAT ปัจจุบัน (ไม่มี = 7%)
    private function vatRate(): float
    {
        $rate = DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent');

        return $rate !== null ? (float) $rate : 7.0;
    }

    /** @return array{subtotal:float,vat:float,total:float} */
    private function salesBreakdown(Document $document): array
    {
        $document->loadMissing('stockDocument.items.product');
        $rate = $this->vatRate();
        $subtotal = 0.0;
        $vat = 0.0;

        foreach ($document->stockDocument?->items ?? [] as $item) {
            $gross = abs((float) $item->qty * (float) $item->unit_price);
            if ($item->product?->is_vat && $rate > 0) {
                $base = round($gross * 100 / (100 + $rate), 4);
                $lineVat = round($gross - $base, 4);
                $subtotal += $base;
                $vat += $lineVat;
                $item->update(['vat_amount' => $lineVat]);
            } else {
                $subtotal += $gross;
                $item->update(['vat_amount' => 0]);
            }
        }

        $total = round($subtotal + $vat, 2);
        $subtotal = round($subtotal, 2);

        return ['subtotal' => $subtotal, 'vat' => round($total - $subtotal, 2), 'total' => $total];
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
            if (($line['debit'] ?? 0) == 0.0 && ($line['credit'] ?? 0) == 0.0) {
                continue;
            }
            $account = $this->role($line['role']);
            if (! $account) {
                throw new RuntimeException("ยังไม่ได้ผูกบัญชีเริ่มต้น [{$line['role']}] ไม่สามารถบันทึกเอกสารโดยไม่ลง GL ได้");
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
        $cogs = app(CostingService::class)->cogsForDocument($document);
        if ($cogs <= 0) {
            return;
        }

        $cogsAccount = $this->role(ChartOfAccount::ROLE_COGS);
        $inventoryAccount = $this->role(ChartOfAccount::ROLE_INVENTORY);
        if (! $cogsAccount || ! $inventoryAccount) {
            throw new RuntimeException('ผังบัญชีต้นทุนขายหรือสินค้าคงเหลือยังไม่ครบ ไม่สามารถลงต้นทุนขายได้');
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
        $amounts = $this->salesBreakdown($document);
        $total = (float) $document->total_amount;
        $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $amounts['vat']]);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_AR, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $amounts['subtotal']],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => $amounts['vat']],
        ], 'ขายเชื่อ '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number);
    }

    // ขายสด: Dr เงินสด / Cr รายได้ + ภาษีขาย + ต้นทุนขาย
    public function postCashSale(Document $document): void
    {
        $amounts = $this->salesBreakdown($document);
        $total = (float) $document->total_amount;
        $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $amounts['vat']]);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_CASH, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $amounts['subtotal']],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => $amounts['vat']],
        ], 'ขายสด '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number);
    }

    /**
     * การเปลี่ยนมูลค่าสินค้าคงเหลือที่ไม่ได้มาจากการซื้อหรือขาย
     * (ปรับปรุง ตรวจนับ ตัดชำรุด) — ของเกินเพิ่มสินค้าคงเหลือ ของขาดลดลง
     * อีกขาลงบัญชีผลต่าง เพื่อให้มูลค่าสต๊อกในบัญชีตรงกับของจริงเสมอ
     *
     * @param  float  $valueChange  บวก = มูลค่าสต๊อกเพิ่ม, ลบ = ลดลง
     */
    public function postInventoryAdjustment(Document $document, float $valueChange, string $label): void
    {
        $amount = round(abs($valueChange), 2);
        if ($amount < 0.01) {
            return;
        }

        $this->postDocument($document, $valueChange > 0
            ? [
                ['role' => ChartOfAccount::ROLE_INVENTORY, 'debit' => $amount],
                ['role' => ChartOfAccount::ROLE_INVENTORY_ADJUSTMENT, 'credit' => $amount],
            ]
            : [
                ['role' => ChartOfAccount::ROLE_INVENTORY_ADJUSTMENT, 'debit' => $amount],
                ['role' => ChartOfAccount::ROLE_INVENTORY, 'credit' => $amount],
            ], $label.' '.$document->doc_number);
    }

    /** ย้ายเงินระหว่างลิ้นชักกับบัญชีธนาคาร ไม่ใช่รายได้หรือค่าใช้จ่าย จึงกระทบแค่สองบัญชีนี้ */
    public function postCashTransfer(Document $document, float $amount, bool $isDeposit): void
    {
        $this->postDocument($document, $isDeposit
            ? [
                ['role' => ChartOfAccount::ROLE_BANK, 'debit' => $amount],
                ['role' => ChartOfAccount::ROLE_CASH, 'credit' => $amount],
            ]
            : [
                ['role' => ChartOfAccount::ROLE_CASH, 'debit' => $amount],
                ['role' => ChartOfAccount::ROLE_BANK, 'credit' => $amount],
            ], ($isDeposit ? 'ฝากเงินสดเข้าธนาคาร ' : 'ถอนเงินสดจากธนาคาร ').$document->doc_number);
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
        $base = (float) ($document->subtotal_amount ?? $total);
        $vat = (float) ($document->vat_amount ?? 0);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_INVENTORY, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_INPUT, 'debit' => $vat],
            ['role' => $isCredit ? ChartOfAccount::ROLE_AP : ChartOfAccount::ROLE_CASH, 'credit' => $total],
        ], 'ซื้อสินค้า '.$document->doc_number);
    }

    public function postExpense(
        Document $document,
        int $expenseAccountId,
        float $baseAmount,
        float $vatAmount,
        float $withholdingAmount,
        string $paymentMethod,
    ): void {
        $expenseAccount = ChartOfAccount::whereKey($expenseAccountId)->where('account_type', 'expense')->first();
        $paymentAccount = $this->role($paymentMethod === 'cash' ? ChartOfAccount::ROLE_CASH : ChartOfAccount::ROLE_BANK);
        $vatAccount = $vatAmount > 0 ? $this->role(ChartOfAccount::ROLE_VAT_INPUT) : null;
        $withholdingAccount = $withholdingAmount > 0 ? $this->role(ChartOfAccount::ROLE_WHT_PAYABLE) : null;

        if (! $expenseAccount || ! $paymentAccount || ($vatAmount > 0 && ! $vatAccount) || ($withholdingAmount > 0 && ! $withholdingAccount)) {
            throw new RuntimeException('ผังบัญชีสำหรับค่าใช้จ่าย ภาษีซื้อ ธนาคาร หรือภาษีหัก ณ ที่จ่ายยังตั้งค่าไม่ครบ');
        }

        $paidAmount = round($baseAmount + $vatAmount - $withholdingAmount, 2);
        $lines = [[$expenseAccount->id, $baseAmount, 0]];
        if ($vatAmount > 0) {
            $lines[] = [$vatAccount->id, $vatAmount, 0];
        }
        $lines[] = [$paymentAccount->id, 0, $paidAmount];
        if ($withholdingAmount > 0) {
            $lines[] = [$withholdingAccount->id, 0, $withholdingAmount];
        }

        foreach ($lines as [$accountId, $debit, $credit]) {
            GlJournal::create([
                'document_id' => $document->id,
                'account_id' => $accountId,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'remark' => 'ค่าใช้จ่าย '.$document->doc_number,
                'entry_date' => $document->doc_date->toDateString(),
            ]);
        }
    }

    // รับคืนสินค้า: Dr รับคืน + ภาษีขาย(กลับ) / Cr ลูกหนี้ (ขายเชื่อ) หรือเงินสด
    // (ขายสด) + กลับต้นทุนขาย (สินค้ากลับเข้าคลัง). $againstAr = คืนที่ลดลูกหนี้
    // VAT แยกรายสินค้าแบบเดียวกับตอนขาย สินค้ายกเว้น VAT (ของสด) ไม่กลับภาษีขาย
    // และเก็บยอดไว้ในเอกสารให้รายงานภาษีขายหักยอดรับคืนได้
    public function postSaleReturn(Document $document, bool $againstAr = true, string $refundMethod = 'cash'): void
    {
        $total = (float) $document->total_amount;
        $amounts = $this->salesBreakdown($document);
        $vat = round($total - $amounts['subtotal'], 2);
        $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $vat]);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $amounts['subtotal']],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => $vat],
            ['role' => $againstAr ? ChartOfAccount::ROLE_AR : ($refundMethod === 'transfer' ? ChartOfAccount::ROLE_BANK : ChartOfAccount::ROLE_CASH), 'credit' => $total],
        ], 'รับคืนสินค้า '.$document->doc_number);
        $this->appendCogs($document, $document->doc_number, reverse: true);
    }

    // ใบลดหนี้: Dr รับคืน/ส่วนลด + ภาษีขาย(กลับ) / Cr ลูกหนี้
    public function postCreditNote(Document $document): void
    {
        $total = (float) $document->total_amount;
        ['base' => $base, 'vat' => $vat] = $this->noteBreakdown($document);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => $vat],
            ['role' => ChartOfAccount::ROLE_AR, 'credit' => $total],
        ], 'ใบลดหนี้ '.$document->doc_number);
    }

    // ใบเพิ่มหนี้: Dr ลูกหนี้ / Cr รายได้ + ภาษีขาย
    public function postDebitNote(Document $document): void
    {
        $total = (float) $document->total_amount;
        ['base' => $base, 'vat' => $vat] = $this->noteBreakdown($document);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_AR, 'debit' => $total],
            ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => $vat],
        ], 'ใบเพิ่มหนี้ '.$document->doc_number);
    }

    /**
     * ใบลด/เพิ่มหนี้ไม่มีรายการสินค้า จึงแยก VAT ตามสัดส่วน VAT ของใบขายที่อ้างอิง
     * (ใบขายของสดล้วน = ไม่มี VAT ให้ปรับ) ไม่มีใบอ้างอิงจึงใช้อัตรามาตรฐาน
     * แล้วเก็บยอดลงเอกสาร — เดิมไม่เก็บ รายงานภาษีขายจึงไม่นับเอกสารพวกนี้เลย
     *
     * @return array{base:float,vat:float}
     */
    private function noteBreakdown(Document $document): array
    {
        $total = round((float) $document->total_amount, 2);
        $source = $document->reference
            ? Document::where('doc_number', $document->reference)->where('id', '!=', $document->id)->first()
            : null;

        if ($source && (float) $source->total_amount > 0) {
            if ($source->subtotal_amount === null && (float) $source->vat_amount == 0.0) {
                // ใบขายเก่าที่ยังไม่เคยแยก VAT ไว้ — คำนวณจากรายการสินค้าของใบนั้น
                $breakdown = $this->salesBreakdown($source);
                $source->update(['subtotal_amount' => $breakdown['subtotal'], 'vat_amount' => $breakdown['vat']]);
            }
            $vat = round($total * (float) $source->vat_amount / (float) $source->total_amount, 2);
        } else {
            $rate = $this->vatRate();
            $vat = $rate > 0 ? round($total - round($total * 100 / (100 + $rate), 2), 2) : 0.0;
        }
        $base = round($total - $vat, 2);
        $document->update(['subtotal_amount' => $base, 'vat_amount' => $vat]);

        return ['base' => $base, 'vat' => $vat];
    }

    /**
     * ค่าเสื่อมราคารายเดือน: Dr ค่าเสื่อมราคา / Cr ค่าเสื่อมราคาสะสม
     * เดิมคิดแค่ในทะเบียนทรัพย์สิน ไม่ลงบัญชี งบกำไรขาดทุนจึงไม่มีค่าเสื่อม
     * และมูลค่าทรัพย์สินในงบดุลสูงเกินจริง
     */
    public function postDepreciation(string $assetCode, float $amount, string $periodEnd): void
    {
        $expense = $this->role(ChartOfAccount::ROLE_DEPRECIATION_EXPENSE);
        $accumulated = $this->role(ChartOfAccount::ROLE_ACCUMULATED_DEPRECIATION);
        if (! $expense || ! $accumulated) {
            throw new RuntimeException('ยังไม่ได้ผูกบัญชีค่าเสื่อมราคาและค่าเสื่อมราคาสะสมในผังบัญชี คิดค่าเสื่อมโดยไม่ลงบัญชีไม่ได้');
        }
        $amount = round($amount, 2);
        $remark = 'ค่าเสื่อมราคา '.$assetCode.' งวด '.substr($periodEnd, 0, 7);
        GlJournal::create(['account_id' => $expense->id, 'debit' => $amount, 'credit' => 0, 'remark' => $remark, 'entry_date' => $periodEnd]);
        GlJournal::create(['account_id' => $accumulated->id, 'debit' => 0, 'credit' => $amount, 'remark' => $remark, 'entry_date' => $periodEnd]);
    }

    public function depreciationAccountsReady(): bool
    {
        return $this->role(ChartOfAccount::ROLE_DEPRECIATION_EXPENSE) !== null
            && $this->role(ChartOfAccount::ROLE_ACCUMULATED_DEPRECIATION) !== null;
    }
}
