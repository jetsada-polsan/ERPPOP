<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosPinChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_set_pin_is_flagged_and_does_not_bind_the_device(): void
    {
        [$cashier, $device] = $this->cashierWithDevice('START');
        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => '1234'])->assertSuccessful();

        $result = $this->login($device, $cashier->code, '1234')->getData(true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['must_change_pin']);
        $this->assertNull($result['offline_credential']);
        // ยังขายในชื่อคนนี้ไม่ได้ เพราะแอดมินก็รู้ PIN
        $this->assertNull($device->fresh()->active_cashier_id);
    }

    public function test_changing_the_pin_clears_the_flag_and_binds_the_device(): void
    {
        [$cashier, $device] = $this->cashierWithDevice('CHANGE');
        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => '1234'])->assertSuccessful();

        $response = $this->changePin($device, $cashier->code, '1234', '860531');

        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['must_change_pin']);
        $this->assertSame($cashier->id, $payload['cashier']['id']);
        $this->assertNotEmpty($payload['offline_credential']['credential_version']);
        $this->assertFalse($cashier->fresh()->must_change_pin);
        $this->assertNotNull($cashier->fresh()->pin_changed_at);
        $this->assertNotNull($cashier->fresh()->pos_credential_version);
        $this->assertSame($cashier->id, (int) $device->fresh()->active_cashier_id);
    }

    public function test_the_new_pin_replaces_the_old_one(): void
    {
        [$cashier, $device] = $this->cashierWithDevice('REPLACE');
        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => '1234'])->assertSuccessful();
        $this->changePin($device, $cashier->code, '1234', '860531');

        $this->assertSame(422, $this->login($device, $cashier->code, '1234')->getStatusCode());

        $result = $this->login($device, $cashier->code, '860531')->getData(true);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['must_change_pin']);
        $this->assertNotEmpty($result['offline_credential']['credential_version']);
        $this->assertSame($cashier->id, (int) $device->fresh()->active_cashier_id);
    }

    public function test_a_wrong_current_pin_cannot_change_anything(): void
    {
        [$cashier, $device] = $this->cashierWithDevice('WRONG');
        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => '1234'])->assertSuccessful();

        $response = $this->changePin($device, $cashier->code, '9999', '860531');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($cashier->fresh()->must_change_pin);
        $this->assertTrue(Hash::check('1234', $cashier->fresh()->pos_pin_hash));
    }

    public function test_permanent_flag_skips_the_forced_change(): void
    {
        [$cashier, $device] = $this->cashierWithDevice('PERM');
        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => '860531', '--permanent' => true])
            ->assertSuccessful();

        $result = $this->login($device, $cashier->code, '860531')->getData(true);

        $this->assertFalse($result['must_change_pin']);
        $this->assertSame($cashier->id, (int) $device->fresh()->active_cashier_id);
    }

    public function test_a_non_numeric_pin_is_rejected(): void
    {
        [$cashier] = $this->cashierWithDevice('BADPIN');

        $this->artisan('pos:pin', ['salesman' => $cashier->code, 'pin' => 'pop@erp'])->assertFailed();

        $this->assertNull($cashier->fresh()->pos_pin_hash);
    }

    public function test_admin_cannot_assign_a_pin_already_used_in_the_branch(): void
    {
        [$first] = $this->cashierWithDevice('DUPLICATE');
        $this->artisan('pos:pin', ['salesman' => $first->code, 'pin' => '482165', '--permanent' => true])
            ->assertSuccessful();

        $second = Salesman::create([
            'branch_id' => $first->branch_id,
            'code' => 'C-DUPLICATE-2',
            'name' => 'แคชเชียร์ ซ้ำ',
            'is_active' => true,
        ]);

        $this->artisan('pos:pin', ['salesman' => $second->code, 'pin' => '482165'])
            ->assertFailed();

        $this->assertNull($second->fresh()->pos_pin_hash);
    }

    public function test_shared_pin_command_sets_a_temporary_pin_for_active_cashiers(): void
    {
        [$first] = $this->cashierWithDevice('SHARED');
        $second = Salesman::create([
            'branch_id' => $first->branch_id,
            'code' => 'C-SHARED-2',
            'name' => 'แคชเชียร์ ร่วม',
            'is_active' => true,
        ]);

        $this->artisan('pos:shared-pin', ['pin' => '1234', '--branch' => 'SHARED'])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('1234', $first->fresh()->pos_pin_hash));
        $this->assertTrue(Hash::check('1234', $second->fresh()->pos_pin_hash));
        $this->assertFalse($first->fresh()->must_change_pin);
    }

    /** @return array{Salesman,PosDevice} */
    private function cashierWithDevice(string $code): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $cashier = Salesman::create([
            'branch_id' => $branch->id,
            'code' => 'C-'.$code,
            'name' => 'แคชเชียร์ '.$code,
            'is_active' => true,
        ]);
        $device = PosDevice::create([
            'name' => 'POS '.$code,
            'user_id' => User::factory()->create([
                'username' => 'pin_'.strtolower($code).'_'.uniqid(),
                'branch_id' => $branch->id,
            ])->id,
            'branch_id' => $branch->id,
            'token_hash' => hash('sha256', 'pin-token-'.$code),
        ]);

        return [$cashier, $device];
    }

    private function login(PosDevice $device, string $code, string $pin)
    {
        $request = Request::create('/api/pos/cashier/login', 'POST', ['code' => $code, 'pin' => $pin]);
        $request->attributes->set('pos_device', $device);

        return app(PosApiController::class)->cashierLogin($request);
    }

    private function changePin(PosDevice $device, string $code, string $current, string $new)
    {
        $request = Request::create('/api/pos/cashier/pin', 'POST', [
            'code' => $code,
            'current_pin' => $current,
            'new_pin' => $new,
        ]);
        $request->attributes->set('pos_device', $device);

        return app(PosApiController::class)->changeCashierPin($request);
    }
}
