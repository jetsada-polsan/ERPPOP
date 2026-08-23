<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\DocumentBook;
use Illuminate\Support\Facades\DB;

/**
 * ออกเลขที่เอกสารที่คนอ่านออก แยกตามชนิดเอกสาร + สาขา + วัน
 * เช่น "BK000120260630001" = ใบจองใบแรกของสาขา 0001 วันนี้
 *
 * เดิมนับด้วย COUNT(*) ของเอกสารวันนั้นแล้วบวกหนึ่ง ซึ่งไม่ atomic —
 * สอง transaction ที่วิ่งพร้อมกันนับได้เลขเดียวกันแล้วชนกันที่ unique index
 * ทดสอบจริงบน PostgreSQL: 10 process × 5 เอกสาร สำเร็จ 8 ล้มเหลว 42 (84%)
 *
 * ตอนนี้ใช้ตาราง `document_sequences` แทน: ล็อกแถวของ (scope, วัน) ก่อนออกเลข
 * ล็อกถูกถือไว้จนกว่า transaction ที่ครอบอยู่จะ commit จึงกันชนได้จริง
 * และล็อกแยกตามชนิดเอกสาร+สาขา สาขาอื่นหรือเอกสารคนละชนิดไม่บล็อกกัน
 */
class DocumentNumberGenerator
{
    private const PREFIXES = [
        'BOOKING' => 'BK',
        'CREDIT_SALE' => 'DS',
        'CASH_SALE' => 'CS',
        'SALE_RETURN' => 'CN',
        'CREDIT_NOTE' => 'RN',
        'DEBIT_NOTE' => 'DN',
        'PURCHASE' => 'PO',
        'STOCK_TRANSFER' => 'TF',
        'STOCK_REQUISITION' => 'DR',
        'STOCK_REQUISITION_RETURN' => 'IR',
        'STOCK_DAMAGE' => 'DD',
        'STOCK_TRANSFORM' => 'DT',
        'PRODUCTION_RECEIPT' => 'IP',
        'STOCK_ADJUSTMENT' => 'AJ',
        'PAYMENT_VOUCHER' => 'PV',
        'RECEIPT' => 'RR',
        'QUOTATION' => 'QT',
        'EXPENSE' => 'EV',
    ];

    // เลขที่ตามสมุดเอกสาร (BPlus): ใช้ prefix ของเล่ม + นับเฉพาะเอกสารในเล่มนั้น
    public function nextInBook(DocumentBook $book, int $branchId): string
    {
        return $this->format($book->prefix, $branchId, 'BOOK:'.$book->id.':'.$branchId);
    }

    public function next(string $documentTypeCode, int $branchId): string
    {
        return $this->format(self::PREFIXES[$documentTypeCode] ?? 'DC', $branchId, $documentTypeCode.':'.$branchId);
    }

    // ตารางที่ออกเลขเอง ไม่ได้อยู่ใน documents — ใช้ตัวนับชุดเดียวกันเพื่อไม่ให้ซ้ำ
    public function nextStockCount(int $branchId): string
    {
        return $this->format('SC', $branchId, 'STOCK_COUNT:'.$branchId);
    }

    public function nextBillingNote(int $branchId): string
    {
        return $this->format('BL', $branchId, 'BILLING_NOTE:'.$branchId);
    }

    public function nextPurchaseOrder(int $branchId): string
    {
        return $this->format('PR', $branchId, 'PURCHASE_ORDER:'.$branchId);
    }

    public function nextQuotation(int $branchId): string
    {
        return $this->format('QT', $branchId, 'QUOTATION:'.$branchId);
    }

    private function format(string $prefix, int $branchId, string $scope): string
    {
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        return sprintf('%s%s%s%03d', $prefix, $branchCode, $today, $this->nextSequence($scope, $today));
    }

    /**
     * จองเลขถัดไปแบบล็อกแถว — จุดเดียวที่กันเลขซ้ำของทั้งระบบ
     *
     * ถ้าถูกเรียกจากใน transaction ที่ครอบอยู่ (ซึ่งเป็นกรณีปกติ) ล็อกจะถูกถือ
     * จนกว่า transaction นั้นจะจบ ทำให้คนที่มาทีหลังรอจริง ไม่ใช่ได้เลขเดียวกันไป
     */
    private function nextSequence(string $scope, string $period): int
    {
        return DB::transaction(function () use ($scope, $period) {
            $row = $this->lockSequence($scope, $period);

            if (! $row) {
                // ต้องเป็น insertOrIgnore (ON CONFLICT DO NOTHING) ไม่ใช่ insert แล้ว catch
                // เพราะบน PostgreSQL พอคำสั่งใดใน transaction ผิดพลาด ทั้ง transaction จะถูก
                // abort ทันที (SQLSTATE 25P02) คำสั่งถัดไปทำอะไรไม่ได้เลยแม้จะดักข้อผิดพลาดไว้
                // — MySQL กับ SQLite ไม่เป็นแบบนี้ จึงมองไม่เห็นถ้าทดสอบแค่บน SQLite
                DB::table('document_sequences')->insertOrIgnore([
                    'scope' => $scope, 'period' => $period, 'last_number' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = $this->lockSequence($scope, $period);
            }

            $next = (int) $row->last_number + 1;
            DB::table('document_sequences')->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    private function lockSequence(string $scope, string $period): ?object
    {
        return DB::table('document_sequences')
            ->where('scope', $scope)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();
    }
}
