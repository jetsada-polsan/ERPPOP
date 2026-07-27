<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\PosController;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosHeldBill;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\ProductUnit;
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

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($alice->id, (int) $device->fresh()->active_cashier_id);
        $this->assertNotNull($device->fresh()->cashier_verified_at);
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
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-'.$code, 'name' => 'POS '.$code]);

        return [$branch, $cashier, $user, $this->openShift($branch, $terminal, $cashier, $code)];
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
            'user_id' => User::factory()->create([
                'username' => 'dev_'.strtolower($branch->code).'_'.uniqid(),
                'branch_id' => $branch->id,
                'salesman_id' => $cashier->id,
            ])->id,
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
