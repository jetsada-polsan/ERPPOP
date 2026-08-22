<?php

namespace App\Services\Accounting;

use App\Models\CashBook;
use App\Models\Document;
use App\Models\PosShift;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ทางเดินรายการเข้าสมุดเงินสด — ที่เดียวของทั้งระบบ
 *
 * กติกา:
 *  1. **idempotent** ทุกรายการมี `source_key` ที่คำนวณจากเอกสารต้นทาง ยิงซ้ำกี่ครั้งก็ได้แถวเดียว
 *     (จำเป็นเพราะ POS retry บิลเดิมได้ และงานปิดกะอาจถูกกดซ้ำ)
 *  2. **running_balance** คิดตอนบันทึกโดยล็อกแถวล่าสุดของสาขาไว้ก่อน กันสองรายการพร้อมกัน
 *     คำนวณยอดยกมาจากแถวเดียวกัน
 *  3. เงินสดจาก POS ลงที่ "ปิดกะ" ครั้งเดียว ไม่ลงตอนขายแต่ละบิล เพราะทุกบิล POS สร้างเอกสาร
 *     ขายสดผูกไว้ด้วย ถ้าลงทั้งสองที่จะกลายเป็นนับเงินซ้ำ
 */
class CashBookPostingService
{
    public const SOURCE_POS_SHIFT_CASH = 'pos_shift_cash';
    public const SOURCE_POS_SHIFT_VARIANCE = 'pos_shift_variance';
    public const SOURCE_CASH_SALE = 'cash_sale';
    public const SOURCE_CUSTOMER_PAYMENT = 'customer_payment';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_BANK_TRANSFER = 'bank_transfer';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    /**
     * บันทึกหนึ่งรายการลงสมุดเงินสด ถ้า source_key เดิมเคยลงแล้วจะคืนแถวเดิมโดยไม่สร้างซ้ำ
     *
     * @param  array{branch_id:int|null,entry_date:string,description:string,cash_in?:float,cash_out?:float,source_type:string,source_id?:int|null,source_key:string,pos_terminal_id?:int|null,pos_shift_id?:int|null,reason?:string|null,created_by?:int|null,approved_by?:int|null}  $entry
     */
    public function post(array $entry): CashBook
    {
        $cashIn = round((float) ($entry['cash_in'] ?? 0), 4);
        $cashOut = round((float) ($entry['cash_out'] ?? 0), 4);
        if ($cashIn < 0 || $cashOut < 0) {
            throw new InvalidArgumentException('สมุดเงินสดรับค่าติดลบไม่ได้ ให้สลับข้างรับ/จ่ายแทน');
        }
        if ($cashIn > 0 && $cashOut > 0) {
            throw new InvalidArgumentException('หนึ่งรายการต้องเป็นรับหรือจ่ายอย่างใดอย่างหนึ่ง');
        }
        if ($cashIn === 0.0 && $cashOut === 0.0) {
            throw new InvalidArgumentException('รายการสมุดเงินสดต้องมียอดรับหรือจ่าย');
        }

        return DB::transaction(function () use ($entry, $cashIn, $cashOut) {
            $existing = CashBook::where('source_key', $entry['source_key'])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            // ล็อกแถวล่าสุดของสาขาไว้ก่อนอ่านยอดยกมา ไม่งั้นสองรายการพร้อมกันจะได้ยอดยกมาเท่ากัน
            $previousBalance = (float) (CashBook::where('branch_id', $entry['branch_id'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('running_balance') ?? 0);

            return CashBook::create([
                'branch_id' => $entry['branch_id'],
                'entry_date' => $entry['entry_date'],
                'description' => $entry['description'],
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'running_balance' => DecimalMath::add($previousBalance, $cashIn - $cashOut),
                'source_type' => $entry['source_type'],
                'source_id' => $entry['source_id'] ?? null,
                'source_key' => $entry['source_key'],
                'pos_terminal_id' => $entry['pos_terminal_id'] ?? null,
                'pos_shift_id' => $entry['pos_shift_id'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'created_by' => $entry['created_by'] ?? auth()->id(),
                'approved_by' => $entry['approved_by'] ?? null,
                'approved_at' => isset($entry['approved_by']) ? now() : null,
            ]);
        });
    }

    /** ปิดกะ POS: เงินสดที่ขายได้ในกะ + ผลต่างเงินสดขาด/เกิน แยกเป็นคนละบรรทัดให้ตรวจได้ */
    public function postShiftClose(PosShift $shift, float $cashSales, float $cashDifference): void
    {
        $date = ($shift->closed_at ?? now())->toDateString();

        if (abs($cashSales) > 0.0001) {
            $this->post([
                'branch_id' => $shift->branch_id,
                'entry_date' => $date,
                'description' => 'เงินสดจากการขาย POS กะ '.$shift->shift_no,
                'cash_in' => max($cashSales, 0),
                'cash_out' => max(-$cashSales, 0),
                'source_type' => self::SOURCE_POS_SHIFT_CASH,
                'source_id' => $shift->id,
                'source_key' => self::SOURCE_POS_SHIFT_CASH.':'.$shift->id,
                'pos_terminal_id' => $shift->pos_terminal_id,
                'pos_shift_id' => $shift->id,
            ]);
        }

        if (abs($cashDifference) > 0.0001) {
            $this->post([
                'branch_id' => $shift->branch_id,
                'entry_date' => $date,
                'description' => ($cashDifference > 0 ? 'เงินสดเกิน' : 'เงินสดขาด').' กะ '.$shift->shift_no,
                'cash_in' => max($cashDifference, 0),
                'cash_out' => max(-$cashDifference, 0),
                'source_type' => self::SOURCE_POS_SHIFT_VARIANCE,
                'source_id' => $shift->id,
                'source_key' => self::SOURCE_POS_SHIFT_VARIANCE.':'.$shift->id,
                'pos_terminal_id' => $shift->pos_terminal_id,
                'pos_shift_id' => $shift->id,
                'reason' => $shift->closing_note,
            ]);
        }
    }

    /** ขายสดหลังบ้านที่รับเป็นเงินสด (บิล POS ไม่ผ่านทางนี้ — ลงตอนปิดกะแทน) */
    public function postCashSale(Document $document, float $cashAmount): void
    {
        if ($cashAmount <= 0) {
            return;
        }

        $this->post([
            'branch_id' => $document->branch_id,
            'entry_date' => $document->doc_date->toDateString(),
            'description' => 'ขายสด '.$document->doc_number,
            'cash_in' => $cashAmount,
            'source_type' => self::SOURCE_CASH_SALE,
            'source_id' => $document->id,
            'source_key' => self::SOURCE_CASH_SALE.':'.$document->id,
        ]);
    }

    /** รับชำระลูกหนี้ด้วยเงินสด */
    public function postCustomerPayment(Document $document, float $cashAmount): void
    {
        if ($cashAmount <= 0) {
            return;
        }

        $this->post([
            'branch_id' => $document->branch_id,
            'entry_date' => $document->doc_date->toDateString(),
            'description' => 'รับชำระลูกหนี้ '.$document->doc_number,
            'cash_in' => $cashAmount,
            'source_type' => self::SOURCE_CUSTOMER_PAYMENT,
            'source_id' => $document->id,
            'source_key' => self::SOURCE_CUSTOMER_PAYMENT.':'.$document->id,
        ]);
    }

    /** ค่าใช้จ่ายที่จ่ายเป็นเงินสด */
    public function postExpense(int $expenseId, ?int $branchId, string $entryDate, string $description, float $cashAmount): void
    {
        if ($cashAmount <= 0) {
            return;
        }

        $this->post([
            'branch_id' => $branchId,
            'entry_date' => $entryDate,
            'description' => $description,
            'cash_out' => $cashAmount,
            'source_type' => self::SOURCE_EXPENSE,
            'source_id' => $expenseId,
            'source_key' => self::SOURCE_EXPENSE.':'.$expenseId,
        ]);
    }
}
