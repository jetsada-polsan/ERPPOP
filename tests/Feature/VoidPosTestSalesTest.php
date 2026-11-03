<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ล้างบิลทดสอบก่อนเปิดร้าน: ยกเลิกเฉพาะบิลก่อนวันที่กำหนดของสาขาที่เลือก ไม่แตะบิลนำเข้าจาก BPlus */
class VoidPosTestSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_pre_launch_bills_of_the_chosen_branch_are_voided(): void
    {
        $b001 = Branch::create(['code' => 'B001', 'name_th' => 'อำนาจ', 'is_active' => true]);
        $other = Branch::create(['code' => 'B002', 'name_th' => 'อื่น', 'is_active' => true]);
        $till = PosTerminal::create(['branch_id' => $b001->id, 'code' => 'POS-B001-01', 'name' => '1']);
        $otherTill = PosTerminal::create(['branch_id' => $other->id, 'code' => 'POS-B002-01', 'name' => '1']);
        $bill = fn ($terminal, $no, $date, $extra = []) => PosReceipt::create(['pos_terminal_id' => $terminal->id, 'receipt_no' => $no, 'receipt_date' => $date, 'net_sales' => 20, 'status' => 'completed'] + $extra);

        $test = $bill($till, 'T1', '2026-11-03 14:20:00');
        $live = $bill($till, 'L1', '2026-11-05 09:00:00');
        $legacy = $bill($till, 'BP1', '2026-10-01 10:00:00');
        \Illuminate\Support\Facades\DB::table('pos_receipts')->where('id', $legacy->id)->update(['is_legacy_import' => true]);
        $otherBranch = $bill($otherTill, 'O1', '2026-11-03 10:00:00');

        $this->artisan('erp:void-pos-test-sales', ['--before' => '2026-11-05', '--branch' => ['B001']])->assertSuccessful();
        $this->assertSame('completed', $test->fresh()->status, 'ไม่ใส่ --apply ต้องไม่แก้อะไร');

        $this->artisan('erp:void-pos-test-sales', ['--before' => '2026-11-05', '--branch' => ['B001'], '--apply' => true])->assertSuccessful();
        $this->assertSame('void', $test->fresh()->status);
        $this->assertSame('completed', $live->fresh()->status);
        $this->assertSame('completed', $legacy->fresh()->status);
        $this->assertSame('completed', $otherBranch->fresh()->status);
    }
}
