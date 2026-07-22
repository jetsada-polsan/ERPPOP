<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PosTransactionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_checkout_replays_one_completed_result_for_the_same_key(): void
    {
        [$device] = $this->device('ONE');
        $downstream = $this->mock(PosController::class);
        $downstream->shouldReceive('checkout')->once()->andReturn(response()->json([
            'success' => true, 'receipt_no' => 'CS-0001', 'receipt_id' => 10,
        ]));
        $controller = app(PosApiController::class);

        $first = $controller->checkout($this->apiRequest($device, 'POS-ONE:SALE:abc'));
        $replay = $controller->checkout($this->apiRequest($device, 'POS-ONE:SALE:abc'));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertDatabaseCount('pos_api_idempotency', 1);
        $this->assertDatabaseHas('pos_api_idempotency', [
            'idempotency_key' => 'POS-ONE:SALE:abc', 'state' => 'completed',
        ]);
    }

    public function test_desktop_checkout_rejects_key_reuse_with_changed_payload_or_device(): void
    {
        [$firstDevice] = $this->device('ONE');
        [$secondDevice] = $this->device('TWO');
        $downstream = $this->mock(PosController::class);
        $downstream->shouldReceive('checkout')->once()->andReturn(response()->json([
            'success' => true, 'receipt_no' => 'CS-0002',
        ]));
        $controller = app(PosApiController::class);
        $key = 'POS-ONE:SALE:shared';

        $controller->checkout($this->apiRequest($firstDevice, $key));
        $changed = $controller->checkout($this->apiRequest($firstDevice, $key, ['cash_received' => 999]));
        $otherDevice = $controller->checkout($this->apiRequest($secondDevice, $key));

        $this->assertSame(409, $changed->getStatusCode());
        $this->assertStringContainsString('คนละชุด', $changed->getData(true)['message']);
        $this->assertSame(409, $otherDevice->getStatusCode());
        $this->assertStringContainsString('POS อีกเครื่อง', $otherDevice->getData(true)['message']);
    }

    public function test_rejected_checkout_releases_the_key_for_a_corrected_retry(): void
    {
        [$device] = $this->device('RETRY');
        $downstream = $this->mock(PosController::class);
        $downstream->shouldReceive('checkout')->twice()->andReturn(
            response()->json(['success' => false, 'message' => 'ราคาเปลี่ยน'], 422),
            response()->json(['success' => true, 'receipt_no' => 'CS-0003'])
        );
        $controller = app(PosApiController::class);
        $key = 'POS-RETRY:SALE:abc';

        $failed = $controller->checkout($this->apiRequest($device, $key));
        $this->assertSame(422, $failed->getStatusCode());
        $this->assertDatabaseMissing('pos_api_idempotency', ['idempotency_key' => $key]);

        $success = $controller->checkout($this->apiRequest($device, $key));
        $this->assertSame(200, $success->getStatusCode());
        $this->assertDatabaseHas('pos_api_idempotency', ['idempotency_key' => $key, 'state' => 'completed']);
    }

    public function test_cashier_cannot_view_or_close_another_branch_shift(): void
    {
        [$ownBranch, $ownCashier, $ownShift] = $this->shift('OWN');
        [, , $otherShift] = $this->shift('OTHER');
        $user = User::factory()->create([
            'username' => 'pos_scope_'.uniqid(),
            'branch_id' => $ownBranch->id,
            'salesman_id' => $ownCashier->id,
        ]);
        $this->actingAs($user);
        $controller = app(PosController::class);

        $activeRequest = Request::create('/pos/shift', 'GET', [
            'branch_id' => $otherShift->branch_id, 'cashier_id' => $otherShift->cashier_id,
        ]);
        $active = $controller->activeShift($activeRequest)->getData(true);
        $this->assertSame($ownShift->id, $active['shift']['id']);

        $closeRequest = Request::create('/pos/shift/close', 'POST', [
            'shift_id' => $otherShift->id, 'counted_cash' => 0,
        ]);
        $closed = $controller->closeShift($closeRequest);
        $this->assertSame(403, $closed->getStatusCode());
        $this->assertSame('open', $otherShift->fresh()->status);
    }

    /** @return array{PosDevice,User} */
    private function device(string $code): array
    {
        $user = User::factory()->create(['username' => 'device_'.strtolower($code).'_'.uniqid()]);
        $device = PosDevice::create([
            'name' => 'POS '.$code,
            'user_id' => $user->id,
            'terminal_code' => 'POS-'.$code,
            'token_hash' => hash('sha256', 'token-'.$code.'-'.uniqid()),
        ]);

        return [$device, $user];
    }

    private function apiRequest(PosDevice $device, string $key, array $override = []): Request
    {
        $payload = array_replace([
            'branch_id' => 1,
            'shift_id' => 1,
            'cashier_id' => 1,
            'method' => 'cash',
            'payment_confirmed' => false,
            'cash_received' => 100,
            'items' => [['product_id' => 1, 'qty' => 1, 'unit_price' => 100]],
        ], $override);
        $request = Request::create('/api/pos/checkout', 'POST', $payload);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => $device->user);

        return $request;
    }

    /** @return array{Branch,Salesman,PosShift} */
    private function shift(string $code): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $cashier = Salesman::create(['branch_id' => $branch->id, 'code' => 'C-'.$code, 'name' => 'แคชเชียร์ '.$code, 'is_active' => true]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-'.$code, 'name' => 'POS '.$code]);
        $shift = PosShift::create([
            'branch_id' => $branch->id,
            'pos_terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_no' => 'SHIFT-'.$code,
            'opened_at' => now(),
            'opening_cash' => 0,
            'status' => 'open',
        ]);

        return [$branch, $cashier, $shift];
    }
}
