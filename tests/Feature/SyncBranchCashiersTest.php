<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Salesman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncBranchCashiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_cashiers_from_active_employees_only(): void
    {
        $branch = Branch::create(['code' => 'SYNC', 'name_th' => 'สาขาซิงค์', 'is_active' => true]);
        $this->employee($branch, 'E-001', 'สมชาย ใจดี', 'แคชเชียร์', 'Active');
        $this->employee($branch, 'E-002', 'สมหญิง รักงาน', 'แคชเชียร์', 'Active');
        $this->employee($branch, 'E-003', 'ลาออกแล้ว ไม่นับ', 'แคชเชียร์', 'Resigned');
        $this->employee($branch, 'E-004', 'ผู้จัดการ ไม่นับ', 'ผู้จัดการ', 'Active');

        $this->artisan('pos:sync-cashiers', ['branch' => 'SYNC'])->assertSuccessful();

        $codes = Salesman::where('branch_id', $branch->id)->pluck('code')->sort()->values()->all();
        $this->assertSame(['E-001', 'E-002'], $codes);
        $this->assertSame('สมชาย ใจดี', Salesman::where('code', 'E-001')->value('name'));
        $this->assertNull(Salesman::where('code', 'E-001')->value('pos_pin_hash'));
    }

    public function test_it_is_safe_to_run_twice_and_never_touches_existing_records(): void
    {
        $branch = Branch::create(['code' => 'TWICE', 'name_th' => 'สาขารันซ้ำ', 'is_active' => true]);
        $this->employee($branch, 'E-010', 'ชื่อใหม่ในทะเบียน', 'แคชเชียร์', 'Active');
        Salesman::create(['branch_id' => $branch->id, 'code' => 'E-010', 'name' => 'ชื่อเดิมที่แก้ไว้', 'is_active' => true]);

        $this->artisan('pos:sync-cashiers', ['branch' => 'TWICE'])->assertSuccessful();
        $this->artisan('pos:sync-cashiers', ['branch' => 'TWICE'])->assertSuccessful();

        $this->assertSame(1, Salesman::where('code', 'E-010')->count());
        $this->assertSame('ชื่อเดิมที่แก้ไว้', Salesman::where('code', 'E-010')->value('name'));
    }

    public function test_a_numeric_branch_code_never_resolves_to_another_branch_id(): void
    {
        $other = Branch::create(['code' => 'OTHER', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $target = Branch::create(['code' => (string) $other->id, 'name_th' => 'สาขาเป้าหมาย', 'is_active' => true]);
        $this->employee($target, 'E-030', 'คนของสาขาเป้าหมาย', 'แคชเชียร์', 'Active');
        $this->employee($other, 'E-031', 'คนของสาขาอื่น', 'แคชเชียร์', 'Active');

        $this->artisan('pos:sync-cashiers', ['branch' => (string) $other->id])->assertSuccessful();

        $this->assertSame(1, Salesman::where('branch_id', $target->id)->count());
        $this->assertSame(0, Salesman::where('branch_id', $other->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $branch = Branch::create(['code' => 'DRY', 'name_th' => 'สาขาลองดู', 'is_active' => true]);
        $this->employee($branch, 'E-020', 'ยังไม่บันทึก', 'แคชเชียร์', 'Active');

        $this->artisan('pos:sync-cashiers', ['branch' => 'DRY', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Salesman::where('branch_id', $branch->id)->count());
    }

    private function employee(Branch $branch, string $code, string $name, string $position, string $status): void
    {
        Employee::create([
            'branch_id' => $branch->id,
            'employee_code' => $code,
            'full_name' => $name,
            'position' => $position,
            'status' => $status,
        ]);
    }
}
