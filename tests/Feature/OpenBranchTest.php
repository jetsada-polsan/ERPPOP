<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เปิดสาขาใหม่คำสั่งเดียว: สาขา + คลังเริ่มต้น + user + แคชเชียร์ต่อเครื่อง + หัวหน้า
 */
class OpenBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        Branch::create(['code' => 'B001', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true, 'negative_stock_policy' => 'block']);
    }

    public function test_it_opens_a_branch_ready_for_three_tills(): void
    {
        $this->artisan('erp:open-branch', ['code' => 'b002', 'name' => 'สาขาทดสอบสอง', '--user' => 'test2', '--tills' => 3, '--like' => 'B001'])
            ->assertSuccessful();

        $branch = Branch::where('code', 'B002')->sole();
        $this->assertTrue($branch->hasDefaultWarehouse());
        $this->assertSame('WH-B002', Warehouse::where('branch_id', $branch->id)->value('code'));
        $this->assertSame('block', $branch->negative_stock_policy);

        $user = User::where('username', 'test2')->sole();
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertTrue((bool) $user->must_change_password);

        $this->assertSame(['B002', 'B002-2', 'B002-3', 'M002'], Salesman::where('branch_id', $branch->id)->orderBy('code')->pluck('code')->all());
        $this->assertSame($user->id, Salesman::where('code', 'M002')->value('user_id'));
        $this->assertSame('แคชเชียร์ สาขาทดสอบสอง เครื่อง 2', Salesman::where('code', 'B002-2')->value('name'));
    }

    public function test_an_existing_branch_code_or_dry_run_creates_nothing(): void
    {
        $this->artisan('erp:open-branch', ['code' => 'B001', 'name' => 'ซ้ำ', '--user' => 'dup1'])->assertFailed();
        $this->artisan('erp:open-branch', ['code' => 'B003', 'name' => 'สาม', '--user' => 'test3', '--dry-run' => true])->assertSuccessful();

        $this->assertFalse(Branch::where('code', 'B003')->exists());
        $this->assertFalse(User::where('username', 'test3')->exists());
        $this->assertFalse(User::where('username', 'dup1')->exists());
    }
}
