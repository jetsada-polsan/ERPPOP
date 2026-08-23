<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ฝากเงินสดเข้าธนาคาร และถอนเงินสดจากธนาคาร
 *
 * เป็นแหล่งที่หกของสมุดเงินสด ซึ่งเดิมไม่มีเลย ทำให้เงินสดที่เอาไปฝากธนาคาร
 * หายจากสมุดเงินสดแบบไม่มีร่องรอย และยอดคงเหลือสูงกว่าของจริงไปเรื่อย ๆ
 *
 * ฝาก  : เงินสดออกจากลิ้นชัก เข้าบัญชีธนาคาร  → Dr ธนาคาร / Cr เงินสด
 * ถอน  : เงินสดเข้าลิ้นชัก ออกจากบัญชีธนาคาร  → Dr เงินสด / Cr ธนาคาร
 */
class CashTransferService
{
    public const DEPOSIT = 'CASH_DEPOSIT';

    public const WITHDRAWAL = 'CASH_WITHDRAWAL';

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
        private readonly CashBookPostingService $cashBook,
    ) {}

    /**
     * @param  array{branch_id:int, bank_account_id:int, amount:float, transfer_date:string, reference?:?string, remark?:?string}  $data
     */
    public function create(string $type, array $data): Document
    {
        if (! in_array($type, [self::DEPOSIT, self::WITHDRAWAL], true)) {
            throw new RuntimeException('ประเภทการโอนเงินสดไม่ถูกต้อง');
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('จำนวนเงินต้องมากกว่า 0');
        }

        return DB::transaction(function () use ($type, $data, $amount) {
            $documentType = DocumentType::where('code', $type)->firstOrFail();
            $bankAccount = BankAccount::findOrFail($data['bank_account_id']);
            $isDeposit = $type === self::DEPOSIT;

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next($type, (int) $data['branch_id']),
                'doc_date' => $data['transfer_date'],
                'reference' => $data['reference'] ?? null,
                'status' => 'active',
                'total_items' => 1,
                'total_amount' => $amount,
                'remark' => trim(($isDeposit ? 'ฝากเข้า ' : 'ถอนจาก ').$bankAccount->bank_name.' '.$bankAccount->account_no.' '.($data['remark'] ?? '')),
                'created_by' => auth()->id(),
            ]);

            $this->glPosting->postCashTransfer($document, $amount, $isDeposit);

            $this->cashBook->post([
                'branch_id' => (int) $data['branch_id'],
                'entry_date' => $data['transfer_date'],
                'description' => ($isDeposit ? 'ฝากเงินสดเข้าธนาคาร ' : 'ถอนเงินสดจากธนาคาร ').$document->doc_number,
                'cash_in' => $isDeposit ? 0 : $amount,
                'cash_out' => $isDeposit ? $amount : 0,
                'source_type' => CashBookPostingService::SOURCE_BANK_TRANSFER,
                'source_id' => $document->id,
                'source_key' => CashBookPostingService::SOURCE_BANK_TRANSFER.':'.$document->id,
            ]);

            return $document->fresh();
        });
    }
}
