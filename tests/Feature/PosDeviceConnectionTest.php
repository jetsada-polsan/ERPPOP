<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ด่านแรกของ POS desktop: /api/pos/ping ต้องบอกความจริงเรื่องสาขาและสิทธิ์
 * เพราะทุกอย่างที่ตามมา (ดึงสินค้า เปิดกะ ขาย) อ้าง branch_id จาก ping ตัวนี้
 */
class PosDeviceConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_reports_the_branch_bound_to_the_device_not_the_user(): void
    {
        $deviceBranch = $this->branch('DEVBR');
        $userBranch = $this->branch('USRBR');
        $user = $this->cashierUser($userBranch);
        [$device, $token] = PosDevice::issue([
            'name' => 'POS ทดสอบ',
            'user_id' => $user->id,
            'branch_id' => $deviceBranch->id,
        ]);

        $response = $this->withToken($token)->getJson('/api/pos/ping');

        $response->assertOk();
        // ย้ายสาขาให้ user ทีหลังต้องไม่ทำให้เครื่องยิง branch_id คนละตัวกับที่เซิร์ฟเวอร์บังคับ
        $response->assertJsonPath('branch_id', $deviceBranch->id);
        $response->assertJsonPath('branch_name', $deviceBranch->name_th);
        $this->assertSame($device->id, $response->json('device.id'));
    }

    public function test_ping_falls_back_to_the_user_branch_when_the_device_has_none(): void
    {
        $branch = $this->branch('FALLBK');
        $user = $this->cashierUser($branch);
        [, $token] = PosDevice::issue(['name' => 'POS ไม่ผูกสาขา', 'user_id' => $user->id, 'branch_id' => null]);

        $this->withToken($token)->getJson('/api/pos/ping')
            ->assertOk()
            ->assertJsonPath('branch_id', $branch->id);
    }

    public function test_branch_scoped_seller_can_use_only_the_assigned_branch_when_device_branch_is_unset(): void
    {
        $branch = $this->branch('SCOPED');
        $otherBranch = $this->branch('OTHER');
        $user = User::factory()->create([
            'username' => 'scopedpos_'.uniqid(),
            'branch_id' => $branch->id,
        ]);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell']);
        $role = Role::create(['code' => 'POS_BRANCH_'.uniqid(), 'name' => 'Branch POS Seller']);
        $role->permissions()->attach($permission->id);
        $user->branchRoles()->attach($role->id, ['branch_id' => $branch->id, 'is_active' => true]);
        [, $fallbackToken] = PosDevice::issue([
            'name' => 'เครื่องใช้สาขาจากบัญชี', 'user_id' => $user->id, 'branch_id' => null,
        ]);
        [, $otherToken] = PosDevice::issue([
            'name' => 'เครื่องคนละสาขา', 'user_id' => $user->id, 'branch_id' => $otherBranch->id,
        ]);

        $this->withToken($fallbackToken)->getJson('/api/pos/ping')
            ->assertOk()
            ->assertJsonPath('branch_id', $branch->id);
        $this->withToken($otherToken)->getJson('/api/pos/ping')->assertForbidden();
    }

    public function test_ping_returns_no_branch_when_neither_device_nor_user_has_one(): void
    {
        $user = $this->cashierUser(null);
        [, $token] = PosDevice::issue(['name' => 'POS ลอย', 'user_id' => $user->id, 'branch_id' => null]);

        // ต้องคืน null ตรง ๆ ให้ POS เตือนพนักงานได้ ไม่ใช่ไปพังตอนเปิดกะ
        $this->withToken($token)->getJson('/api/pos/ping')
            ->assertOk()
            ->assertJsonPath('branch_id', null);
    }

    public function test_device_without_pos_sell_permission_cannot_connect(): void
    {
        $branch = $this->branch('NOSELL');
        $user = User::factory()->create(['username' => 'nosell_'.uniqid(), 'branch_id' => $branch->id]);
        [, $token] = PosDevice::issue(['name' => 'POS ไม่มีสิทธิ์', 'user_id' => $user->id, 'branch_id' => $branch->id]);

        $this->withToken($token)->getJson('/api/pos/ping')->assertStatus(403);
    }

    public function test_branch_manager_view_permission_does_not_grant_selling_device_access(): void
    {
        $ownBranch = $this->branch('OWNBR');
        $user = User::factory()->create([
            'username' => 'branchmgr_'.uniqid(),
            'branch_id' => $ownBranch->id,
        ]);
        $user->roles()->attach(Role::where('code', 'BRANCH_MGR')->firstOrFail()->id);
        [, $token] = PosDevice::issue([
            'name' => 'เครื่องสาขาผู้จัดการ', 'user_id' => $user->id, 'branch_id' => $ownBranch->id,
        ]);

        // ผู้จัดการเปิดดู POS ได้ แต่ถ้าจะขายต้องผูกเครื่องกับบัญชีผู้ขาย
        // การใส่ GM เพื่อข้ามข้อกำหนดนี้จะให้สิทธิ์กว้างเกินความจำเป็น
        $this->withToken($token)->getJson('/api/pos/ping')->assertForbidden();
    }

    public function test_it_manager_can_use_pos_devices_across_branches_without_general_manager_role(): void
    {
        $branch = $this->branch('GMTEST');
        $user = User::factory()->create(['username' => 'itpos_'.uniqid(), 'branch_id' => null]);
        $user->roles()->attach(Role::where('code', 'IT_MGR')->firstOrFail()->id);
        [, $token] = PosDevice::issue([
            'name' => 'เครื่องทดสอบ IT', 'user_id' => $user->id, 'branch_id' => $branch->id,
        ]);

        $this->withToken($token)->getJson('/api/pos/ping')
            ->assertOk()
            ->assertJsonPath('branch_id', $branch->id);
    }

    public function test_revoked_or_unknown_token_cannot_connect(): void
    {
        $branch = $this->branch('REVOKE');
        $user = $this->cashierUser($branch);
        [$device, $token] = PosDevice::issue(['name' => 'POS เพิกถอน', 'user_id' => $user->id, 'branch_id' => $branch->id]);

        $this->withToken($token)->getJson('/api/pos/ping')->assertOk();

        $device->forceFill(['revoked_at' => now()])->save();

        $this->withToken($token)->getJson('/api/pos/ping')->assertStatus(401);
        $this->getJson('/api/pos/ping')->assertStatus(401);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
    }

    private function cashierUser(?Branch $branch): User
    {
        $user = User::factory()->create([
            'username' => 'poscon_'.uniqid(),
            'branch_id' => $branch?->id,
        ]);
        $role = Role::firstOrCreate(['code' => 'POS_SELLER'], ['name' => 'POS Seller']);
        $role->permissions()->syncWithoutDetaching(
            Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell'])->id
        );
        $user->roles()->attach($role->id);

        return $user;
    }
}
