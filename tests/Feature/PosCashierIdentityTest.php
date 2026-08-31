<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\PosController;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosHeldBill;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosCashierIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_login_binds_the_cashier_to_the_device(): void
    {
        [$branch, $alice] = $this->branchWithCashier('BIND', 'ALICE');
        $alice->forceFill(['pos_pin_hash' => Hash::make('4821')])->save();
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/cashier/login', 'POST', ['code' => $alice->code, 'pin' => '4821']);
        $request->attributes->set('pos_device', $device);

        $response = app(PosApiController::class)->cashierLogin($request);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertSame(120000, $payload['offline_credential']['iterations']);
        $this->assertNotEmpty($payload['offline_credential']['salt']);
        $this->assertNotEmpty($payload['offline_credential']['verifier']);
        $this->assertNotEmpty($payload['offline_credential']['expires_at']);
        $this->assertSame($alice->id, (int) $device->fresh()->active_cashier_id);
        $this->assertNotNull($device->fresh()->cashier_verified_at);

        // ต้องไล่ย้อนได้ว่าใครลงเครื่องไหนตอนไหน แม้ยังไม่มีการขาย
        $audit = AuditLog::where('action', 'cashier_login')->sole();
        $this->assertSame($alice->user_id, (int) $audit->record_id);
        $this->assertSame($device->id, $audit->new_values['device_id']);
    }

    public function test_pin_only_login_identifies_the_single_cashier_on_the_device_branch(): void
    {
        [$branch, $alice] = $this->branchWithCashier('PINONLY', 'ALICE');
        $alice->forceFill(['pos_pin_hash' => Hash::make('482165')])->save();
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/cashier/login', 'POST', ['pin' => '482165']);
        $request->attributes->set('pos_device', $device);

        $response = app(PosApiController::class)->cashierLogin($request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($alice->user->username, $response->getData(true)['cashier']['code']);
    }

    public function test_login_accepts_the_username_of_the_user_linked_to_the_cashier(): void
    {
        [$branch, $alice, $user] = $this->branchWithCashier('USERNAME', 'ALICE');
        $alice->forceFill(['pos_pin_hash' => Hash::make('4821')])->save();
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/cashier/login', 'POST', ['code' => $user->username, 'pin' => '4821']);
        $request->attributes->set('pos_device', $device);

        $payload = app(PosApiController::class)->cashierLogin($request)->getData(true);

        $this->assertTrue($payload['success'], json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertSame($alice->id, (int) $payload['cashier']['id']);
    }

    public function test_pos_settings_authorization_accepts_an_active_admin_with_settings_permission(): void
    {
        [$branch, $alice] = $this->branchWithCashier('ADMINSET', 'ALICE');
        $device = $this->device($branch, $alice);
        $admin = User::factory()->create([
            'username' => 'pos_admin_'.uniqid(),
            'password' => Hash::make('admin-secret'),
            'is_active' => true,
        ]);
        $role = Role::create([
            'code' => 'pos-settings-admin-'.uniqid(),
            'name' => 'POS settings admin',
        ]);
        $role->permissions()->attach(Permission::firstOrCreate(
            ['code' => 'settings.manage'],
            ['name' => 'จัดการตั้งค่า']
        )->id);
        $admin->roles()->attach($role->id);

        $request = Request::create('/api/pos/admin/authorize', 'POST', [
            'username' => $admin->username,
            'password' => 'admin-secret',
        ]);
        $request->attributes->set('pos_device', $device);

        $response = app(PosApiController::class)->authorizeAdmin($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($admin->username, $response->getData(true)['admin']['username']);
        $this->assertSame(1, AuditLog::where('action', 'pos_admin_settings_authorized')->count());
    }

    public function test_pos_settings_authorization_rejects_a_cashier_without_admin_permission(): void
    {
        [$branch, $alice, $cashierUser] = $this->branchWithCashier('CASHSET', 'ALICE');
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/admin/authorize', 'POST', [
            'username' => $cashierUser->username,
            'password' => 'password',
        ]);
        $request->attributes->set('pos_device', $device);

        $response = app(PosApiController::class)->authorizeAdmin($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, AuditLog::where('action', 'pos_admin_settings_authorized')->count());
    }

    public function test_passwordless_mode_still_limits_cashier_selection_to_the_device_branch(): void
    {
        AppSetting::set('pos_passwordless_login', '1');
        [$branch, $alice] = $this->branchWithCashier('NOPIN', 'ALICE');
        $otherBranch = Branch::create(['code' => 'OTHER', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $otherCashier = Salesman::create(['branch_id' => $otherBranch->id, 'code' => 'OTHER-1', 'name' => 'คนอื่น', 'is_active' => true]);
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/cashier/login', 'POST', ['cashier_id' => $alice->id]);
        $request->attributes->set('pos_device', $device);
        $response = app(PosApiController::class)->cashierLogin($request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($alice->id, (int) $device->fresh()->active_cashier_id);

        $otherRequest = Request::create('/api/pos/cashier/login', 'POST', ['cashier_id' => $otherCashier->id]);
        $otherRequest->attributes->set('pos_device', $device);
        $this->assertSame(422, app(PosApiController::class)->cashierLogin($otherRequest)->getStatusCode());
    }

    public function test_device_uses_its_assigned_user_when_another_cashier_shares_a_pin(): void
    {
        [$branch, $alice] = $this->branchWithCashier('DUPPIN', 'ALICE');
        $bob = Salesman::create([
            'branch_id' => $branch->id,
            'code' => 'BOB-DUPPIN',
            'name' => 'แคชเชียร์ บ๊อบ',
            'is_active' => true,
            'pos_pin_hash' => Hash::make('482165'),
        ]);
        $this->linkCashierUser($bob, $branch);
        $alice->forceFill(['pos_pin_hash' => Hash::make('482165')])->save();
        $device = $this->device($branch, $alice);

        $request = Request::create('/api/pos/cashier/login', 'POST', ['pin' => '482165']);
        $request->attributes->set('pos_device', $device);

        $response = app(PosApiController::class)->cashierLogin($request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($alice->id, (int) $response->getData(true)['cashier']['id']);
        $this->assertSame($alice->id, (int) $device->fresh()->active_cashier_id);

        $other = Request::create('/api/pos/cashier/login', 'POST', [
            'pin' => '482165', 'cashier_id' => $bob->id,
        ]);
        $other->attributes->set('pos_device', $device);
        $this->assertSame(422, app(PosApiController::class)->cashierLogin($other)->getStatusCode());
    }

    public function test_device_cannot_sell_under_another_cashier_after_pin_login(): void
    {
        [$branch, $alice, $user] = $this->branchWithCashier('SPOOF', 'ALICE');
        $bob = Salesman::create(['branch_id' => $branch->id, 'code' => 'BOB', 'name' => 'บ๊อบ', 'is_active' => true]);
        $terminal = PosTerminal::where('branch_id', $branch->id)->firstOrFail();
        // บ๊อบมีกะเปิดอยู่จริง ถ้าไม่มีการผูกตัวตน คำขอนี้จะผ่าน
        $bobShift = $this->openShift($branch, $terminal, $bob, 'SPOOF-BOB');
        $device = $this->device($branch, $alice);
        $device->markCashierVerified($alice);

        $response = $this->holdBill($device, $user, $branch, $bobShift, $bob);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, PosHeldBill::count());
    }

    public function test_device_can_still_sell_under_the_verified_cashier(): void
    {
        [$branch, $alice, $user, $aliceShift] = $this->branchWithCashier('OWN', 'ALICE');
        $device = $this->device($branch, $alice);
        $device->markCashierVerified($alice);

        $response = $this->holdBill($device, $user, $branch, $aliceShift, $alice);

        $this->assertTrue($response->getData(true)['success']);
    }

    public function test_expired_verification_falls_back_instead_of_locking_the_terminal(): void
    {
        [$branch, $alice, $user, $aliceShift] = $this->branchWithCashier('EXPIRE', 'ALICE');
        $device = $this->device($branch, $alice);
        $device->forceFill([
            'active_cashier_id' => $alice->id,
            'cashier_verified_at' => now()->subHours(20),
        ])->saveQuietly();

        $this->assertNull($device->fresh()->verifiedCashierId());
        $this->assertTrue($this->holdBill($device, $user, $branch, $aliceShift, $alice)->getData(true)['success']);
    }

    public function test_strict_mode_requires_a_pin_before_selling(): void
    {
        AppSetting::set('pos_require_cashier_pin', '1');
        [$branch, $alice, $user, $aliceShift] = $this->branchWithCashier('STRICT', 'ALICE');
        $device = $this->device($branch, $alice);

        $this->assertSame(422, $this->holdBill($device, $user, $branch, $aliceShift, $alice)->getStatusCode());

        $device->markCashierVerified($alice);
        $this->assertTrue($this->holdBill($device, $user, $branch, $aliceShift, $alice)->getData(true)['success']);
    }

    public function test_web_user_without_a_salesman_cannot_sell_under_a_disabled_cashier(): void
    {
        [$branch, $alice, , $aliceShift] = $this->branchWithCashier('WEB', 'ALICE');
        $manager = User::factory()->create([
            'username' => 'manager_'.uniqid(),
            'branch_id' => $branch->id,
            'salesman_id' => null,
        ]);
        $alice->update(['is_active' => false]);
        $this->actingAs($manager);

        $request = Request::create('/pos/held-bills', 'POST', $this->holdPayload($branch, $aliceShift, $alice));
        $this->app->instance('request', $request);

        $this->assertSame(422, app(PosController::class)->holdBill($request)->getStatusCode());
    }

    public function test_user_whose_salesman_was_disabled_can_no_longer_sell(): void
    {
        [$branch, $alice, $user, $aliceShift] = $this->branchWithCashier('LEFT', 'ALICE');
        $alice->update(['is_active' => false]);
        $this->actingAs($user);

        $request = Request::create('/pos/held-bills', 'POST', $this->holdPayload($branch, $aliceShift, $alice));
        $this->app->instance('request', $request);

        $this->assertSame(422, app(PosController::class)->holdBill($request)->getStatusCode());
    }

    /** @return array{Branch,Salesman,User,PosShift} */
    private function branchWithCashier(string $code, string $cashierCode): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $cashier = Salesman::create([
            'branch_id' => $branch->id,
            'code' => $cashierCode.'-'.$code,
            'name' => 'แคชเชียร์ '.$cashierCode,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'username' => 'ident_'.strtolower($code).'_'.uniqid(),
            'branch_id' => $branch->id,
            'salesman_id' => $cashier->id,
        ]);
        $this->linkCashierUser($cashier, $branch, $user);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-'.$code, 'name' => 'POS '.$code]);

        return [$branch, $cashier, $user, $this->openShift($branch, $terminal, $cashier, $code)];
    }

    private function linkCashierUser(Salesman $cashier, Branch $branch, ?User $user = null): User
    {
        $user ??= User::factory()->create([
            'username' => 'cashier_'.strtolower($branch->code).'_'.uniqid(),
            'branch_id' => $branch->id,
            'salesman_id' => $cashier->id,
            'is_active' => true,
        ]);
        $cashier->update(['user_id' => $user->id]);

        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS']);
        $role = Role::firstOrCreate(['code' => 'POS_SELL_TEST_ROLE'], ['name' => 'แคชเชียร์ทดสอบ']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function openShift(Branch $branch, PosTerminal $terminal, Salesman $cashier, string $code): PosShift
    {
        return PosShift::create([
            'branch_id' => $branch->id,
            'pos_terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_no' => 'SHIFT-'.$code,
            'opened_at' => now(),
            'opening_cash' => 1000,
            'expected_cash' => 1000,
            'status' => 'open',
        ]);
    }

    private function device(Branch $branch, Salesman $cashier): PosDevice
    {
        return PosDevice::create([
            'name' => 'POS '.$branch->code,
            'user_id' => $cashier->user_id,
            'branch_id' => $branch->id,
            'token_hash' => hash('sha256', 'token-'.$branch->code),
        ]);
    }

    private function holdPayload(Branch $branch, PosShift $shift, Salesman $cashier): array
    {
        return [
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'label' => 'บิลพัก',
            'total_amount' => 100,
            'payload' => ['cart' => [['id' => $this->product()->id, 'qty' => 1]]],
        ];
    }

    private function product(): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::firstOrCreate(['sku_code' => 'IDENT-P1'], [
            'name_th' => 'สินค้าทดสอบ',
            'base_unit_id' => $unit->id,
            'default_price' => 100,
            'average_cost' => 60,
            'is_vat' => true,
            'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
    }

    private function holdBill(PosDevice $device, User $user, Branch $branch, PosShift $shift, Salesman $cashier)
    {
        $request = Request::create('/api/pos/held-bills', 'POST', $this->holdPayload($branch, $shift, $cashier));
        $request->attributes->set('pos_device', $device);
        $this->actingAs($user);

        // enforcedCashierId() อ่าน request() ตัวกลาง ไม่ใช่ตัวที่ส่งเข้า action
        // ถ้าไม่ผูกตัวนี้ เทสต์จะไปวิ่งเส้นทางเว็บแทนเส้นทางเครื่อง POS
        $this->app->instance('request', $request);

        return app(PosController::class)->holdBill($request);
    }
}
