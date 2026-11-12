<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\GlJournal;
use App\Models\PaymentDocument;
use App\Services\Inventory\CostingService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Append-only double-entry posting. Document-level locks serialize retries;
 * component batches retain originals and append reversals when values change.
 */
class GlPostingService
{
    public function postCustomerReceipt(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark, string $method = 'cash', ?int $bankAccountId = null): void
    {
        // รับโอน/เช็คไม่ได้เข้าลิ้นชัก — เดิมลงเงินสดทุกวิธี ยอดเงินสดในบัญชีเกินจริง ยอดธนาคารขาด
        $cashAccount = $method === 'cash'
            ? ChartOfAccount::where('default_role', ChartOfAccount::ROLE_CASH)->first()
            : $this->bankAccountFor($paymentDocument->branch_id, $bankAccountId);
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
     * หัก ณ ที่จ่าย (ฝั่งลูกหนี้ — ลูกค้าหักภาษีเรา): บันทึกเป็นสินทรัพย์ (Dr WHT Receivable / Cr AR)
     */
    public function postCustomerWhtDeduction(PaymentDocument $paymentDocument, float $whtAmount, string $entryDate, string $remark): void
    {
        if ($whtAmount <= 0) {
            return;
        }
        $whtAccount = $this->role(ChartOfAccount::ROLE_WHT_RECEIVABLE);
        $arAccount = $this->role(ChartOfAccount::ROLE_AR);
        if (! $whtAccount || ! $arAccount) {
            throw new RuntimeException('ผังบัญชีภาษีหัก ณ ที่จ่าย (wht_receivable) ยังตั้งค่าไม่ครบ ไม่สามารถรับชำระโดยไม่ลง GL ได้');
        }
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $whtAccount->id,
            'debit' => $whtAmount,
            'credit' => 0,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $arAccount->id,
            'debit' => 0,
            'credit' => $whtAmount,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
    }

    /**
     * ส่วนลดเงินสด (ส่วนลดจ่ายชำระเร็ว): ลงบัญชีรับคืน/ส่วนลดจ่าย (Dr Sales Return / Cr AR) ไม่ปรับ VAT
     */
    public function postCustomerDiscount(PaymentDocument $paymentDocument, float $discountAmount, string $entryDate, string $remark): void
    {
        if ($discountAmount <= 0) {
            return;
        }
        $discountAccount = $this->role(ChartOfAccount::ROLE_SALES_RETURN);
        $arAccount = $this->role(ChartOfAccount::ROLE_AR);
        if (! $discountAccount || ! $arAccount) {
            throw new RuntimeException('ผังบัญชีส่วนลดจ่าย (sales_return) ยังตั้งค่าไม่ครบ ไม่สามารถรับชำระโดยไม่ลง GL ได้');
        }
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $discountAccount->id,
            'debit' => $discountAmount,
            'credit' => 0,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
        GlJournal::create([
            'payment_document_id' => $paymentDocument->id,
            'account_id' => $arAccount->id,
            'debit' => 0,
            'credit' => $discountAmount,
            'remark' => $remark,
            'entry_date' => $entryDate,
        ]);
    }

    /**
     * ผลต่างเศษสตางค์ขาด/เกิน: ลงบัญชีเศษสตางค์ (Dr หรือ Cr) คู่กับลูกหนี้การค้า (AR)
     * - ขาด (Shortfall, roundingAmount < 0): Dr เศษสตางค์ / Cr ลูกหนี้
     * - เกิน (Excess, roundingAmount > 0): Dr ลูกหนี้ / Cr เศษสตางค์
     */
    public function postCustomerRounding(PaymentDocument $paymentDocument, float $roundingAmount, string $entryDate, string $remark): void
    {
        if (abs($roundingAmount) < 0.001) {
            return;
        }

        $roundingAccount = ChartOfAccount::where('name_th', 'like', '%เศษสตางค์%')
            ->orWhere('name_en', 'like', '%rounding%')
            ->orWhere('default_role', 'rounding_diff')
            ->first()
            ?? ChartOfAccount::where('default_role', ChartOfAccount::ROLE_EXPENSE)->first()
            ?? ChartOfAccount::where('code', '5020')->first();

        $arAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AR)->first();

        if (! $roundingAccount || ! $arAccount) {
            throw new RuntimeException('ผังบัญชีสำหรับเศษสตางค์หรือลูกหนี้ยังไม่ครบ ไม่สามารถลง GL ได้');
        }

        $amount = round(abs($roundingAmount), 2);

        if ($roundingAmount < 0) {
            GlJournal::create([
                'payment_document_id' => $paymentDocument->id,
                'account_id' => $roundingAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'remark' => $remark.' (เศษสตางค์ขาด)',
                'entry_date' => $entryDate,
            ]);
            GlJournal::create([
                'payment_document_id' => $paymentDocument->id,
                'account_id' => $arAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'remark' => $remark.' (เศษสตางค์ขาด)',
                'entry_date' => $entryDate,
            ]);
        } else {
            GlJournal::create([
                'payment_document_id' => $paymentDocument->id,
                'account_id' => $arAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'remark' => $remark.' (เศษสตางค์เกิน)',
                'entry_date' => $entryDate,
            ]);
            GlJournal::create([
                'payment_document_id' => $paymentDocument->id,
                'account_id' => $roundingAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'remark' => $remark.' (เศษสตางค์เกิน)',
                'entry_date' => $entryDate,
            ]);
        }
    }

    /**
     * เช็ครับเด้ง: กลับรายการรับชำระของใบนั้นทั้งชุด (Dr ลูกหนี้ / Cr ธนาคาร) ลงวันที่เช็คเด้ง
     * ไม่ลบรายการเดิม ประวัติว่าเคยรับและเคยเด้งต้องตรวจย้อนได้ เรียกซ้ำก็ไม่กลับซ้ำ
     */
    public function reverseCustomerReceipt(PaymentDocument $paymentDocument, string $remark): void
    {
        DB::transaction(function () use ($paymentDocument, $remark) {
            $paymentDocument = PaymentDocument::whereKey($paymentDocument->id)->lockForUpdate()->firstOrFail();
            app(AccountingPeriodGuard::class)->assertOpen(now()->toDateString(), $paymentDocument->branch_id);
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
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'remark' => 'เช็คคืน: '.$remark,
                    'entry_date' => now()->toDateString(),
                ]);
            }

        });
    }

    /**
     * ยกเลิกใบเสร็จ/ใบจ่าย: กลับทุกบรรทัด GL ของ payment document ลงวันที่ยกเลิก
     * ไม่ลบรายการเดิม เรียกซ้ำไม่กลับซ้ำ (แยก prefix จากเช็คคืน)
     */
    public function reversePaymentDocument(PaymentDocument $paymentDocument, string $remark): void
    {
        DB::transaction(function () use ($paymentDocument, $remark) {
            $paymentDocument = PaymentDocument::whereKey($paymentDocument->id)->lockForUpdate()->firstOrFail();
            app(AccountingPeriodGuard::class)->assertOpen(now()->toDateString(), $paymentDocument->branch_id);
            $lines = GlJournal::where('payment_document_id', $paymentDocument->id)
                ->where('remark', 'not like', 'VOID REVERSAL:%')->get();
            if ($lines->isEmpty() || GlJournal::where('payment_document_id', $paymentDocument->id)
                ->where('remark', 'like', 'VOID REVERSAL:%')->exists()) {
                return;
            }
            foreach ($lines as $line) {
                GlJournal::create([
                    'payment_document_id' => $paymentDocument->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'remark' => 'VOID REVERSAL: '.$remark,
                    'entry_date' => now()->toDateString(),
                ]);
            }

        });
    }

    public function postSupplierPayment(PaymentDocument $paymentDocument, float $amount, string $entryDate, string $remark, float $withholding = 0, string $method = 'cash', ?int $bankAccountId = null): void
    {
        $cashAccount = $method === 'cash'
            ? ChartOfAccount::where('default_role', ChartOfAccount::ROLE_CASH)->first()
            : $this->bankAccountFor($paymentDocument->branch_id, $bankAccountId);
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
            'credit' => DecimalMath::subtract($amount, $withholding, 2),
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
        // เล่มไม่มีภาษี (DSN, CSN) และใบรับคืนของบิลเล่มนั้นไม่คิด VAT ทั้งใบ
        $noVat = (bool) ($document->documentBook?->no_vat || $document->sourceDocument?->documentBook?->no_vat);
        $rate = $noVat ? 0.0 : $this->vatRate();
        $subtotal = '0';
        $vat = '0';

        foreach ($document->stockDocument?->items ?? [] as $item) {
            $gross = (string) DecimalMath::of(DecimalMath::multiply($item->qty, $item->unit_price))->abs();
            if ($item->product?->is_vat && $rate > 0) {
                $base = DecimalMath::divide(DecimalMath::multiply($gross, 100), DecimalMath::add(100, $rate), 4);
                $lineVat = DecimalMath::subtract($gross, $base, 4);
                $subtotal = DecimalMath::add($subtotal, $base);
                $vat = DecimalMath::add($vat, $lineVat);
                $item->update(['vat_amount' => $lineVat]);
            } else {
                $subtotal = DecimalMath::add($subtotal, $gross);
                $item->update(['vat_amount' => 0]);
            }
        }

        $total = DecimalMath::add($subtotal, $vat, 2);
        $subtotal = DecimalMath::round($subtotal, 2);

        return ['subtotal' => $subtotal, 'vat' => DecimalMath::subtract($total, $subtotal, 2), 'total' => $total];
    }

    public function role(string $role): ?ChartOfAccount
    {
        return ChartOfAccount::where('default_role', $role)->first();
    }

    /**
     * บัญชี GL ของเงินฝากธนาคารสำหรับสาขาหนึ่ง
     *
     * ลำดับ: บัญชีธนาคารที่ผูกสาขานี้ -> บัญชีธนาคารกลาง (ไม่ระบุสาขา) -> role bank (1020)
     * นับเฉพาะบัญชีธนาคารที่ผูกบัญชี GL แล้ว สาขาที่ยังว่างจึงใช้บัญชีกลางต่อได้ไม่สะดุด
     * ผู้ใช้เลือกบัญชีเองได้ (ข้อ 12) — ใช้บัญชีนั้นถ้าผูก GL แล้ว
     */
    public function bankAccountFor(?int $branchId, ?int $bankAccountId = null): ?ChartOfAccount
    {
        if ($bankAccountId && ($chosen = BankAccount::with('chartOfAccount')->whereKey($bankAccountId)->whereNotNull('chart_of_account_id')->first())) {
            return $chosen->chartOfAccount;
        }
        $bank = BankAccount::query()
            ->whereNotNull('chart_of_account_id')
            ->where(fn ($query) => $query->whereNull('branch_id')
                ->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->with('chartOfAccount')
            ->get()
            ->sortBy(fn (BankAccount $account) => [$account->branch_id === null ? 1 : 0, $account->id])
            ->first();

        return $bank?->chartOfAccount ?? $this->role(ChartOfAccount::ROLE_BANK);
    }

    private function postDocument(Document $document, array $lines, string $remark): void
    {
        $resolved = [];
        foreach ($lines as $line) {
            if (DecimalMath::compare($line['debit'] ?? 0, 0) === 0 && DecimalMath::compare($line['credit'] ?? 0, 0) === 0) {
                continue;
            }
            // เงินเข้า/ออกธนาคารลงบัญชีของสาขาเอกสาร (ถ้าสาขาตั้งไว้)
            $account = $line['role'] === ChartOfAccount::ROLE_BANK
                ? $this->bankAccountFor($document->branch_id)
                : $this->role($line['role']);
            if (! $account) {
                throw new RuntimeException("ยังไม่ได้ผูกบัญชีเริ่มต้น [{$line['role']}] ไม่สามารถบันทึกเอกสารโดยไม่ลง GL ได้");
            }
            $resolved[] = [$account->id, DecimalMath::round($line['debit'] ?? 0, 2), DecimalMath::round($line['credit'] ?? 0, 2)];
        }

        $this->postResolved($document, $resolved, $remark);
    }

    /** Append-only replacement of one accounting component; document row serializes concurrent retries. */
    public function postResolved(Document $document, array $resolved, string $remark, string $component = 'main', ?string $postingDate = null): void
    {
        DB::transaction(function () use ($document, $resolved, $remark, $component, $postingDate) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $canonical = collect($resolved)->groupBy(fn ($line) => $line[0])->map(fn ($rows, $id) => [
                (int) $id, DecimalMath::sum($rows->pluck(1), 2), DecimalMath::sum($rows->pluck(2), 2),
            ])->sortKeys()->values()->all();
            if (DecimalMath::compare(DecimalMath::sum(array_column($canonical, 1)), DecimalMath::sum(array_column($canonical, 2))) !== 0) {
                throw new RuntimeException('GL debit/credit ไม่สมดุล');
            }
            foreach ($canonical as [$accountId, $debit, $credit]) {
                if (DecimalMath::compare($debit, 0) < 0 || DecimalMath::compare($credit, 0) < 0) {
                    throw new RuntimeException('GL ต้องไม่มียอดเดบิตหรือเครดิตติดลบ');
                }
            }
            $fingerprint = hash('sha256', json_encode($canonical));
            $latest = DB::table('gl_posting_batches')->where('document_id', $document->id)
                ->where('component', $component)->orderByDesc('id')->first();
            if (in_array($document->status, ['cancelled', 'void'], true)
                || GlJournal::where('document_id', $document->id)->where('remark', 'like', 'VOID REVERSAL:%')->exists()
                || DB::table('gl_posting_batches')->where('document_id', $document->id)->where('component', 'void')->exists()) {
                throw new RuntimeException('เอกสารกลับรายการแล้ว ไม่สามารถลงบัญชีใหม่');
            }
            if ($latest && $latest->fingerprint === $fingerprint) {
                return;
            }
            $old = $latest
                ? GlJournal::where('posting_batch_id', $latest->id)->whereNull('reversal_of_id')->get()
                : GlJournal::where('document_id', $document->id)->whereNull('posting_batch_id')
                    ->whereNull('reversal_of_id')
                    ->where(function ($q) use ($component) {
                        if ($component === 'cogs') {
                            $q->where('remark', 'like', 'ต้นทุนขาย %')->orWhere('remark', 'like', 'กลับต้นทุนขาย %');
                        } elseif ($component === 'main') {
                            $q->where('remark', 'not like', 'ต้นทุนขาย %')->where('remark', 'not like', 'กลับต้นทุนขาย %');
                        } else {
                            $q->whereRaw('1 = 0');
                        }
                    })->get();
            // Replacement and reversals use today's open period; first posting uses document date.
            $date = $postingDate ?? (($latest || $old->isNotEmpty()) ? now()->toDateString() : $document->doc_date->toDateString());
            app(AccountingPeriodGuard::class)->assertOpen($date, $document->branch_id);
            $batchId = DB::table('gl_posting_batches')->insertGetId([
                'document_id' => $document->id, 'component' => $component, 'fingerprint' => $fingerprint,
                'entry_date' => $date, 'created_at' => now(),
            ]);
            foreach ($old as $line) {
                // Existing legacy reversals must not be reversed twice.
                if (GlJournal::where('reversal_of_id', $line->id)->exists()) {
                    continue;
                }
                GlJournal::create([
                    'document_id' => $document->id, 'posting_batch_id' => $batchId, 'reversal_of_id' => $line->id,
                    'account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit,
                    'remark' => 'REPOST REVERSAL: '.$remark, 'entry_date' => $date,
                ]);
            }
            foreach ($canonical as [$accountId, $debit, $credit]) {
                if (DecimalMath::compare($debit, 0) === 0 && DecimalMath::compare($credit, 0) === 0) {
                    continue;
                }
                GlJournal::create([
                    'document_id' => $document->id, 'posting_batch_id' => $batchId,
                    'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit,
                    'remark' => $remark, 'entry_date' => $date,
                ]);
            }
        });
    }

    // ต้นทุนขาย: ขาย = Dr ต้นทุนขาย / Cr สินค้าคงเหลือ; รับคืน (reverse) = ตรงข้าม
    // แนบเข้าไปในเอกสารเดิม โดยไม่ล้าง (append) รายการที่ post ไว้ก่อน
    private function appendCogs(Document $document, string $remark, bool $reverse = false): void
    {
        $cogs = app(CostingService::class)->cogsForDocument($document);

        if (DecimalMath::compare($cogs, 0) <= 0) {
            $this->postResolved($document, [], 'ต้นทุนขาย '.$remark, 'cogs');

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

        $this->postResolved($document, [
            [$cogsAccount->id, $cogsDr, $cogsCr], [$inventoryAccount->id, $invDr, $invCr],
        ], $label, 'cogs');
    }

    // ขายเชื่อ: Dr ลูกหนี้ / Cr รายได้ + ภาษีขาย (ราคารวม VAT) + ต้นทุนขาย
    public function postCreditSale(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $amounts = $this->salesBreakdown($document);
            $total = $document->total_amount;
            $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $amounts['vat']]);
            $this->postDocument($document, [
                ['role' => ChartOfAccount::ROLE_AR, 'debit' => $total],
                ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $amounts['subtotal']],
                ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => $amounts['vat']],
            ], 'ขายเชื่อ '.$document->doc_number);
            $this->appendCogs($document, $document->doc_number);

        });
    }

    public function prepareSaleTax(Document $document): void
    {
        $amounts = $this->salesBreakdown($document);
        $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $amounts['vat']]);
    }

    // ขายสด: Dr เงินสด / Cr รายได้ + ภาษีขาย + ต้นทุนขาย
    public function postCashSale(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $amounts = $this->salesBreakdown($document);
            $total = $document->total_amount;
            $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $amounts['vat']]);
            $receipt = DB::table('pos_receipts')->where('document_id', $document->id)->first();
            if ($receipt) {
                $settlement = $this->posSettlementLines($document, $receipt->id);
                $revenue = $this->role(ChartOfAccount::ROLE_SALES_REVENUE);
                $vat = $this->role(ChartOfAccount::ROLE_VAT_OUTPUT);
                if (! $revenue || (DecimalMath::compare($amounts['vat'], 0) > 0 && ! $vat)) {
                    throw new RuntimeException('ผังบัญชีรายได้/ภาษีขายไม่ครบ');
                }
                $settlement[] = [$revenue->id, 0, $amounts['subtotal']];
                if ($vat) {
                    $settlement[] = [$vat->id, 0, $amounts['vat']];
                }
                $this->postResolved($document, $settlement, 'ขาย POS '.$document->doc_number);
            } else {
                $this->postDocument($document, [
                    ['role' => ChartOfAccount::ROLE_CASH, 'debit' => $total],
                    ['role' => ChartOfAccount::ROLE_SALES_REVENUE, 'credit' => $amounts['subtotal']],
                    ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'credit' => $amounts['vat']],
                ], 'ขายสด '.$document->doc_number);
            }
            $this->appendCogs($document, $document->doc_number);

        });
    }

    public function posSettlementLines(Document $document, int $receiptId, bool $createClearing = true): array
    {
        $payments = DB::table('pos_payments')->where('pos_receipt_id', $receiptId)->orderBy('id')->get();
        if ($payments->isEmpty() || DecimalMath::compare(DecimalMath::sum($payments->pluck('amount'), 2), DecimalMath::round($document->total_amount, 2)) !== 0) {
            throw new RuntimeException('ยอดชำระ POS ไม่ตรงยอดเอกสาร ไม่สามารถลงบัญชีได้');
        }

        return $payments->map(function ($payment) use ($document, $createClearing) {
            if (DecimalMath::compare($payment->amount, 0) < 0) {
                throw new RuntimeException('ยอดชำระติดลบ');
            }
            $role = match ($payment->method) {
                'cash' => ChartOfAccount::ROLE_CASH,
                'transfer', 'qr' => ChartOfAccount::ROLE_POS_TRANSFER,
                'credit_card' => ChartOfAccount::ROLE_POS_CARD,
                'cheque' => ChartOfAccount::ROLE_POS_CHEQUE,
                default => throw new RuntimeException('ไม่รู้จักช่องทางชำระ POS'),
            };
            $account = $this->role($role);
            if (! $account && $role !== ChartOfAccount::ROLE_CASH) {
                // Separate outstanding settlements from cash/bank until finance reconciles them.
                $account = $this->role(ChartOfAccount::ROLE_POS_CLEARING);
                if (! $account && ! $createClearing) {
                    $account = new ChartOfAccount(['default_role' => ChartOfAccount::ROLE_POS_CLEARING]);
                    $account->id = -1; // read-only preview of a not-yet-created clearing account
                }
                $account ??= ChartOfAccount::firstOrCreate(['code' => 'POS-CLEARING'], [
                    'name_th' => 'พักรับชำระ POS รอกระทบยอด', 'account_type' => 'asset',
                    'default_role' => ChartOfAccount::ROLE_POS_CLEARING,
                ]);
                if ($account->default_role !== ChartOfAccount::ROLE_POS_CLEARING) {
                    throw new RuntimeException('รหัส POS-CLEARING ถูกใช้งานอื่น กรุณาผูกบัญชีพัก POS');
                }
                if ($createClearing) {
                    Log::warning('POS settlement uses clearing', ['method' => $payment->method, 'document_id' => $document->id]);
                }
            }
            if (! $account) {
                throw new RuntimeException('ยังไม่ได้ผูกบัญชีเงินสด');
            }

            return [$account->id, DecimalMath::round($payment->amount, 2), '0.00'];
        })->all();
    }

    /** Historical reclassification preserves posted tax/revenue and COGS, never recalculates VAT. */
    public function reclassifyPosSettlement(Document $document, int $receiptId, bool $apply): bool
    {
        return DB::transaction(function () use ($document, $receiptId, $apply) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('pos_receipts')->where('id', $receiptId)->where('document_id', $document->id)
                ->where('status', 'completed')->first();
            if (! $receipt || $document->status !== 'active') {
                throw new RuntimeException('จัดประเภทได้เฉพาะบิล POS ที่เสร็จสมบูรณ์และยังไม่ยกเลิก');
            }
            $query = GlJournal::where('gl_journals.document_id', $document->id)
                ->leftJoin('gl_posting_batches as b', 'b.id', '=', 'gl_journals.posting_batch_id')
                ->where(fn ($q) => $q->where('b.component', 'main')->orWhere(fn ($legacy) => $legacy->whereNull('posting_batch_id')
                    ->where('remark', 'not like', 'ต้นทุนขาย %')->where('remark', 'not like', 'กลับต้นทุนขาย %')));
            $old = $query->get(['gl_journals.*']);
            if ($old->isEmpty()) {
                throw new RuntimeException('ไม่มี GL เดิมให้จัดประเภทใหม่');
            }
            $lines = $this->posSettlementLines($document, $receiptId, $apply);
            $net = $old->groupBy('account_id')->map(fn ($group) => DecimalMath::subtract(
                DecimalMath::sum($group->pluck('debit')), DecimalMath::sum($group->pluck('credit')), 2));
            foreach ($net as $id => $value) {
                if (DecimalMath::compare($value, 0) < 0) {
                    $lines[] = [(int) $id, 0, DecimalMath::subtract(0, $value, 2)];
                }
            }
            if (DecimalMath::compare(DecimalMath::sum(array_column($lines, 1)), DecimalMath::sum(array_column($lines, 2))) !== 0) {
                throw new RuntimeException('GL รายได้เดิมไม่ตรงบิล ต้องตรวจสอบด้วยนักบัญชี');
            }
            $target = collect($lines)->groupBy(fn ($l) => $l[0])->map(fn ($group) => DecimalMath::subtract(
                DecimalMath::sum($group->pluck(1)), DecimalMath::sum($group->pluck(2)), 2));
            $changed = $net->keys()->merge($target->keys())->unique()->contains(fn ($id) => DecimalMath::compare($net[$id] ?? 0, $target[$id] ?? 0) !== 0);
            if ($apply && $changed) {
                $this->postResolved($document, $lines, 'จัดประเภทวิธีชำระ POS '.$document->doc_number);
            }

            return $changed;
        });
    }

    /**
     * การเปลี่ยนมูลค่าสินค้าคงเหลือที่ไม่ได้มาจากการซื้อหรือขาย
     * (ปรับปรุง ตรวจนับ ตัดชำรุด) — ของเกินเพิ่มสินค้าคงเหลือ ของขาดลดลง
     * อีกขาลงบัญชีผลต่าง เพื่อให้มูลค่าสต๊อกในบัญชีตรงกับของจริงเสมอ
     *
     * @param  float  $valueChange  บวก = มูลค่าสต๊อกเพิ่ม, ลบ = ลดลง
     */
    public function postInventoryAdjustment(Document $document, int|float|string $valueChange, string $label): void
    {
        $amount = DecimalMath::round((string) DecimalMath::of($valueChange)->abs(), 2);
        if (DecimalMath::compare($amount, '0.01') < 0) {
            return;
        }

        $this->postDocument($document, DecimalMath::compare($valueChange, 0) > 0
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
        DB::transaction(function () use ($document, $remark) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (DB::table('gl_posting_batches')->where('document_id', $document->id)->where('component', 'void')->exists()
                || GlJournal::where('document_id', $document->id)->where('remark', 'like', 'VOID REVERSAL:%')->exists()) {
                return;
            }
            $date = now()->toDateString();
            app(AccountingPeriodGuard::class)->assertOpen($date, $document->branch_id);
            $lines = GlJournal::where('document_id', $document->id)->get();
            if ($lines->isEmpty()) {
                return;
            }
            $batchId = DB::table('gl_posting_batches')->insertGetId([
                'document_id' => $document->id, 'component' => 'void', 'fingerprint' => hash('sha256', 'void'),
                'entry_date' => $date, 'created_at' => now(),
            ]);
            // Reverse net balance by account, including earlier replacement reversals.
            foreach ($lines->groupBy('account_id') as $accountId => $group) {
                $net = DecimalMath::subtract(DecimalMath::sum($group->pluck('debit')), DecimalMath::sum($group->pluck('credit')), 2);
                if (DecimalMath::compare($net, 0) === 0) {
                    continue;
                }
                GlJournal::create([
                    'document_id' => $document->id, 'posting_batch_id' => $batchId, 'account_id' => $accountId,
                    'debit' => DecimalMath::compare($net, 0) < 0 ? DecimalMath::subtract(0, $net, 2) : 0,
                    'credit' => DecimalMath::compare($net, 0) > 0 ? $net : 0,
                    'remark' => 'VOID REVERSAL: '.$remark, 'entry_date' => $date,
                ]);
            }
        });
    }

    // ซื้อ: Dr สินค้าคงเหลือ + ภาษีซื้อ / Cr เจ้าหนี้ (เครดิต) หรือเงินสด
    public function postPurchase(Document $document, bool $isCredit = true): void
    {
        $total = $document->total_amount;
        $base = $document->subtotal_amount ?? $total;
        $vat = $document->vat_amount ?? 0;
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
        $paymentAccount = $paymentMethod === 'cash'
            ? $this->role(ChartOfAccount::ROLE_CASH)
            : $this->bankAccountFor($document->branch_id);
        $vatAccount = $vatAmount > 0 ? $this->role(ChartOfAccount::ROLE_VAT_INPUT) : null;
        $withholdingAccount = $withholdingAmount > 0 ? $this->role(ChartOfAccount::ROLE_WHT_PAYABLE) : null;

        if (! $expenseAccount || ! $paymentAccount || ($vatAmount > 0 && ! $vatAccount) || ($withholdingAmount > 0 && ! $withholdingAccount)) {
            throw new RuntimeException('ผังบัญชีสำหรับค่าใช้จ่าย ภาษีซื้อ ธนาคาร หรือภาษีหัก ณ ที่จ่ายยังตั้งค่าไม่ครบ');
        }

        $paidAmount = DecimalMath::subtract(DecimalMath::add($baseAmount, $vatAmount), $withholdingAmount, 2);
        $lines = [[$expenseAccount->id, $baseAmount, 0]];
        if ($vatAmount > 0) {
            $lines[] = [$vatAccount->id, $vatAmount, 0];
        }
        $lines[] = [$paymentAccount->id, 0, $paidAmount];
        if ($withholdingAmount > 0) {
            $lines[] = [$withholdingAccount->id, 0, $withholdingAmount];
        }

        $this->postResolved($document, $lines, 'ค่าใช้จ่าย '.$document->doc_number);
    }

    // รับคืนสินค้า: Dr รับคืน + ภาษีขาย(กลับ) / Cr ลูกหนี้ (ขายเชื่อ) หรือเงินสด
    // (ขายสด) + กลับต้นทุนขาย (สินค้ากลับเข้าคลัง). $againstAr = คืนที่ลดลูกหนี้
    // VAT แยกรายสินค้าแบบเดียวกับตอนขาย สินค้ายกเว้น VAT (ของสด) ไม่กลับภาษีขาย
    // และเก็บยอดไว้ในเอกสารให้รายงานภาษีขายหักยอดรับคืนได้
    public function postSaleReturn(Document $document, bool $againstAr = true, string $refundMethod = 'cash'): void
    {
        DB::transaction(function () use ($document, $againstAr, $refundMethod) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $total = $document->total_amount;
            $amounts = $this->salesBreakdown($document);
            $vat = DecimalMath::subtract($total, $amounts['subtotal'], 2);
            $document->update(['subtotal_amount' => $amounts['subtotal'], 'vat_amount' => $vat]);
            $this->postDocument($document, [
                ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $amounts['subtotal']],
                ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => $vat],
                ['role' => $againstAr ? ChartOfAccount::ROLE_AR : ($refundMethod === 'transfer' ? ChartOfAccount::ROLE_BANK : ChartOfAccount::ROLE_CASH), 'credit' => $total],
            ], 'รับคืนสินค้า '.$document->doc_number);
            $this->appendCogs($document, $document->doc_number, reverse: true);

        });
    }

    // ใบลดหนี้: Dr รับคืน/ส่วนลด + ภาษีขาย(กลับ) / Cr ลูกหนี้
    /**
     * ใบลดหนี้: Dr รับคืน/ส่วนลด + ภาษีขาย / Cr ลูกหนี้
     *
     * $refund = ส่วนที่คืนเป็นเงินสดให้ลูกค้า (ใบขายต้นทางชำระไปแล้ว) ส่วนนั้นลง Cr เงินสดแทนลูกหนี้
     * ส่วนที่เหลือลง Cr ลูกหนี้ - ถ้าเกินยอดค้างจะกลายเป็นยอดเครดิตของลูกค้าไว้หักใบถัดไป
     */
    public function postCreditNote(Document $document, float $refund = 0.0): void
    {
        $total = $document->total_amount;
        $refund = DecimalMath::round(DecimalMath::compare($refund, 0) < 0 ? 0 : (DecimalMath::compare($refund, $total) > 0 ? $total : $refund), 2);
        ['base' => $base, 'vat' => $vat] = $this->noteBreakdown($document);
        $this->postDocument($document, [
            ['role' => ChartOfAccount::ROLE_SALES_RETURN, 'debit' => $base],
            ['role' => ChartOfAccount::ROLE_VAT_OUTPUT, 'debit' => $vat],
            ['role' => ChartOfAccount::ROLE_AR, 'credit' => DecimalMath::subtract($total, $refund, 2)],
            ['role' => ChartOfAccount::ROLE_CASH, 'credit' => $refund],
        ], 'ใบลดหนี้ '.$document->doc_number);
    }

    // ใบเพิ่มหนี้: Dr ลูกหนี้ / Cr รายได้ + ภาษีขาย
    public function postDebitNote(Document $document): void
    {
        $total = $document->total_amount;
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
        $total = DecimalMath::round($document->total_amount, 2);
        $source = $document->reference
            ? Document::where('doc_number', $document->reference)->where('id', '!=', $document->id)->first()
            : null;

        if ($source && DecimalMath::compare($source->total_amount, 0) > 0) {
            if ($source->subtotal_amount === null && DecimalMath::compare($source->vat_amount, 0) === 0) {
                // ใบขายเก่าที่ยังไม่เคยแยก VAT ไว้ — คำนวณจากรายการสินค้าของใบนั้น
                $breakdown = $this->salesBreakdown($source);
                $source->update(['subtotal_amount' => $breakdown['subtotal'], 'vat_amount' => $breakdown['vat']]);
            }
            $vat = DecimalMath::divide(DecimalMath::multiply($total, $source->vat_amount), $source->total_amount, 2);
        } else {
            $rate = $this->vatRate();
            $vat = $rate > 0 ? DecimalMath::subtract($total, DecimalMath::divide(DecimalMath::multiply($total, 100), DecimalMath::add(100, $rate), 2), 2) : '0.00';
        }
        $base = DecimalMath::subtract($total, $vat, 2);
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
        $amount = DecimalMath::round($amount, 2);
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
