<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * เลขกะเคยสร้างจากเวลาถึงหลักวินาที: เปิด 2 กะในสาขาเดียวกันภายในวินาทีเดียว
 * (เครื่อง POS หลายเครื่องกลับมาออนไลน์แล้วส่งกะที่เปิดตอนออฟไลน์ขึ้นพร้อมกัน)
 * ชน unique แล้ว ERP ตอบ 500 เจอจาก e2e POS Python -> Laravel
 */
class PosShiftNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_shifts_opened_in_the_same_second_get_different_numbers(): void
    {
        Carbon::setTestNow('2026-11-05 08:00:00');
        $branch = Branch::create(['code' => 'B006', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-SHIFT-A', 'name' => 'POS 1']);

        $first = $this->open($branch, 'C1');
        $second = $this->open($branch, 'C2');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(
            ['SHIFT-B006-20261105-0001', 'SHIFT-B006-20261105-0002'],
            PosShift::orderBy('id')->pluck('shift_no')->all(),
        );
    }

    public function test_numbering_continues_after_shifts_numbered_the_old_way(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาวาริน', 'is_active' => true]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-SHIFT-B', 'name' => 'POS 1']);
        $old = Salesman::create(['branch_id' => $branch->id, 'code' => 'OLD', 'name' => 'กะเก่า', 'is_active' => true]);
        PosShift::create([
            'branch_id' => $branch->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $old->id,
            'shift_no' => 'SHIFT-B001-20261105-083015', 'opened_at' => now(), 'opening_cash' => 0,
            'expected_cash' => 0, 'status' => 'closed',
        ]);

        $this->assertSame(200, $this->open($branch, 'NEW')->getStatusCode());
        $this->assertSame(2, PosShift::distinct()->count('shift_no'));
    }

    private function open(Branch $branch, string $code)
    {
        $cashier = Salesman::create(['branch_id' => $branch->id, 'code' => $code, 'name' => 'แคชเชียร์ '.$code, 'is_active' => true]);
        $user = User::factory()->create([
            'username' => 'shift_'.strtolower($code).'_'.uniqid(),
            'branch_id' => $branch->id,
            'salesman_id' => $cashier->id,
        ]);
        $cashier->update(['user_id' => $user->id]);
        $role = Role::firstOrCreate(['code' => 'POS_SHIFT_TEST'], ['name' => 'แคชเชียร์']);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS'])->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($user->fresh());

        $request = Request::create('/pos/shift/open', 'POST', [
            'branch_id' => $branch->id, 'cashier_id' => $cashier->id, 'opening_cash' => 1000,
        ]);
        $this->app->instance('request', $request);

        return app(PosController::class)->openShift($request);
    }
}
