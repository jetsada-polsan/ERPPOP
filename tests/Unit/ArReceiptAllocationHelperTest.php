<?php

namespace Tests\Unit;

use App\Support\ArReceiptAllocationHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ArReceiptAllocationHelperTest extends TestCase
{
    /**
     * 1. เคสจริง BPlus: บิล 1 (เก่ากว่า) 12,697.90 + บิล 2 2,665.00 รวม 15,362.90
     * ลูกค้าจ่าย 3,363.00: บิลแรกตัด 3,363.00 เหลือ 9,334.90 บิลสองเหลือ 2,665.00
     * ยอดคงเหลือรวมต้องเป็น 11,999.90 บาทพอดีถึงสตางค์ ไม่มี floating-point drift
     */
    public function test_real_bplus_case_allocates_oldest_bill_first_with_exact_satang_precision(): void
    {
        $bills = [
            [
                'id' => 101,
                'doc_number' => 'IV-202610-001',
                'doc_date' => '2026-10-01',
                'balance' => 12697.90,
            ],
            [
                'id' => 102,
                'doc_number' => 'IV-202610-002',
                'doc_date' => '2026-10-15',
                'balance' => 2665.00,
            ],
        ];

        $res = ArReceiptAllocationHelper::distribute($bills, 3363.00);

        $this->assertSame(15362.90, $res['total_debt']);
        $this->assertSame(3363.00, $res['total_allocated']);
        $this->assertSame(11999.90, $res['next_payment_remaining']);

        $allocMap = collect($res['allocations'])->keyBy('id');
        $this->assertSame(3363.00, $allocMap[101]['amount']);
        $this->assertSame(9334.90, $allocMap[101]['remaining']);

        $this->assertSame(0.00, $allocMap[102]['amount']);
        $this->assertSame(2665.00, $allocMap[102]['remaining']);
    }

    /**
     * 2. กรณีวันที่เท่ากัน ให้เรียงตามเลขที่เอกสาร doc_number น้อยไปมาก
     */
    public function test_same_date_bills_sort_by_document_number_ascending(): void
    {
        $bills = [
            [
                'id' => 2,
                'doc_number' => 'IV-002',
                'doc_date' => '2026-10-10',
                'balance' => 1000.00,
            ],
            [
                'id' => 1,
                'doc_number' => 'IV-001',
                'doc_date' => '2026-10-10',
                'balance' => 1000.00,
            ],
        ];

        $res = ArReceiptAllocationHelper::distribute($bills, 1500.00);

        $allocMap = collect($res['allocations'])->keyBy('id');
        $this->assertSame(1000.00, $allocMap[1]['amount'], 'IV-001 ต้องถูกตัดก่อนจนเต็ม');
        $this->assertSame(0.00, $allocMap[1]['remaining']);

        $this->assertSame(500.00, $allocMap[2]['amount'], 'IV-002 ได้ยอดที่เหลือ 500');
        $this->assertSame(500.00, $allocMap[2]['remaining']);
    }

    /**
     * 3. จ่ายเต็มจำนวน 2 บิล
     */
    public function test_exact_full_payment_clears_all_bills(): void
    {
        $bills = [
            ['id' => 1, 'doc_date' => '2026-10-01', 'balance' => 12697.90],
            ['id' => 2, 'doc_date' => '2026-10-05', 'balance' => 2665.00],
        ];

        $res = ArReceiptAllocationHelper::distribute($bills, 15362.90);

        $this->assertSame(15362.90, $res['total_allocated']);
        $this->assertSame(0.00, $res['next_payment_remaining']);
    }

    /**
     * 4. จ่ายเกินยอดหนี้ที่เลือก ต้องโยนข้อยกเว้น
     */
    public function test_paid_amount_exceeding_debt_throws_exception(): void
    {
        $bills = [
            ['id' => 1, 'balance' => 1000.00],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ยอดชำระ (1500.00) เกินยอดหนี้ที่เลือก (1000.00)');

        ArReceiptAllocationHelper::distribute($bills, 1500.00);
    }

    /**
     * 5. ช่องเศษขาด/เกิน: ขาด 1.00 บาท ปิดบิลได้พอดี
     */
    public function test_shortfall_rounding_within_one_baht_settles_bill(): void
    {
        $bills = [
            ['id' => 1, 'balance' => 2040.00],
        ];

        // ลูกค้าจ่าย 2,039.00 เศษขาด -1.00 บาท -> บิลถูกตัดเต็ม 2,040.00
        $res = ArReceiptAllocationHelper::distribute($bills, 2039.00, -1.00);

        $this->assertSame(2040.00, $res['total_allocated']);
        $this->assertSame(0.00, $res['next_payment_remaining']);
    }

    /**
     * 6. ช่องเศษขาด/เกิน: เกิน ±1.00 บาท ต้องถูกปฏิเสธ
     */
    public function test_rounding_outside_limit_throws_exception(): void
    {
        $bills = [
            ['id' => 1, 'balance' => 2040.00],
        ];

        $this->expectException(InvalidArgumentException::class);
        ArReceiptAllocationHelper::distribute($bills, 2038.99, -1.01);
    }
}
