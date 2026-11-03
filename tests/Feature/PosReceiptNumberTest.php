<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\PosReceipt;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Salesman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** สองเครื่องในสาขาเดียวกันต้องไม่ได้เลขบิลซ้ำกัน */
class PosReceiptNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_till_numbers_its_own_bills_without_colliding(): void
    {
        $this->travelTo('2026-11-03 14:20:00');
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $cashier = Salesman::create(['branch_id' => $branch->id, 'code' => 'C1', 'name' => 'แคชเชียร์', 'is_active' => true]);
        $next = function (string $code) use ($branch, $cashier) {
            $terminal = PosTerminal::firstOrCreate(['code' => $code], ['branch_id' => $branch->id, 'name' => $code]);
            $shift = PosShift::create(['branch_id' => $branch->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id, 'shift_no' => 'S-'.$code.'-'.PosShift::count(), 'opened_at' => now(), 'opening_cash' => 0, 'expected_cash' => 0, 'status' => 'open']);
            $method = new \ReflectionMethod(PosController::class, 'nextPosReceiptNo');
            $no = $method->invoke(app(PosController::class), $shift);
            PosReceipt::create(['pos_terminal_id' => $terminal->id, 'pos_shift_id' => $shift->id, 'receipt_no' => $no, 'receipt_date' => now(), 'net_sales' => 1, 'status' => 'completed']);

            return $no;
        };

        $this->assertSame('CSB001-01-20261103-001', $next('POS-B001-01'));
        $this->assertSame('CSB001-01-20261103-002', $next('POS-B001-01'));
        $this->assertSame('CSB001-02-20261103-001', $next('POS-B001-02'));
        $this->assertSame('CSB001-WEB-20261103-001', $next('WEB-B001'));
    }
}
