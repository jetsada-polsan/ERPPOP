<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\PosController;
use App\Models\PosDevice;
use App\Models\PosOfflineSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POS desktop (SQLite) ส่งบิลที่ ERP ไม่มีวันรับ (422) — ต้องไปอยู่หน้า "ต้องตรวจสอบ"
 * และบอกเครื่องให้หยุดยิงซ้ำ แทนที่จะวนลองใหม่ไปเรื่อย ๆ โดยไม่มีใครเห็น
 */
class PosDesktopRejectedSaleReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_rejection_is_kept_for_review_and_tells_the_till_to_stop(): void
    {
        $device = $this->device();
        $this->downstream(response()->json(['success' => false, 'message' => 'ราคาต่ำกว่าทุน'], 422), 2);
        $key = (string) Str::uuid();

        $first = app(PosApiController::class)->checkout($this->request($device, $key));
        $second = app(PosApiController::class)->checkout($this->request($device, $key));

        $this->assertSame(422, $first->getStatusCode());
        $this->assertTrue($first->getData(true)['needs_review']);
        $this->assertSame('ราคาต่ำกว่าทุน', $first->getData(true)['message']);
        $review = PosOfflineSale::sole();
        $this->assertSame(PosOfflineSale::SOURCE_DESKTOP, $review->source);
        $this->assertSame(PosOfflineSale::NEEDS_REVIEW, $review->status);
        $this->assertSame($device->id, $review->pos_device_id);
        $this->assertSame(2, $review->attempts);
        $this->assertEquals(30, (float) $review->offline_total);
        $this->assertSame(422, $second->getStatusCode());
    }

    public function test_successful_resend_closes_the_review_row(): void
    {
        $device = $this->device();
        $key = (string) Str::uuid();
        $mock = $this->mock(PosController::class);
        $mock->shouldReceive('checkout')->once()->andReturn(response()->json(['success' => false, 'message' => 'กะปิดแล้ว'], 422));
        $mock->shouldReceive('checkout')->once()->andReturn(response()->json(['success' => true, 'receipt_no' => 'R-77', 'receipt_id' => null]));

        app(PosApiController::class)->checkout($this->request($device, $key));
        $ok = app(PosApiController::class)->checkout($this->request($device, $key));

        $this->assertSame(200, $ok->getStatusCode());
        $review = PosOfflineSale::sole();
        $this->assertSame(PosOfflineSale::POSTED, $review->status);
        $this->assertSame('R-77', $review->receipt_no);
    }

    public function test_temporary_failures_are_left_for_the_till_to_retry(): void
    {
        $device = $this->device();
        $this->downstream(response()->json(['success' => false, 'message' => 'busy'], 503), 1);

        $response = app(PosApiController::class)->checkout($this->request($device, (string) Str::uuid()));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertArrayNotHasKey('needs_review', $response->getData(true));
        $this->assertSame(0, PosOfflineSale::count());
    }

    public function test_non_uuid_keys_from_old_tills_are_not_stored(): void
    {
        $device = $this->device();
        $this->downstream(response()->json(['success' => false, 'message' => 'x'], 422), 1);

        app(PosApiController::class)->checkout($this->request($device, 'POS-ONE:SALE:abc'));

        $this->assertSame(0, PosOfflineSale::count());
    }

    public function test_web_retry_refuses_desktop_bills(): void
    {
        $sale = PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(), 'source' => PosOfflineSale::SOURCE_DESKTOP, 'sold_at' => now(),
            'status' => PosOfflineSale::NEEDS_REVIEW, 'payload' => ['items' => []],
        ]);
        $manager = User::factory()->create(['username' => 'mgr-desk', 'is_active' => true, 'must_change_password' => false]);
        $role = \App\Models\Role::create(['code' => 'MGR_D', 'name' => 'mgr']);
        foreach (['pos.use', 'pos.void', 'reports.view'] as $code) {
            $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $manager->roles()->attach($role->id);

        $this->actingAs($manager)->post(route('pos.offline-sales.retry', $sale))->assertSessionHas('error');
        $this->assertSame(PosOfflineSale::NEEDS_REVIEW, $sale->fresh()->status);
        $this->actingAs($manager)->get(route('pos.offline-sales.index'))->assertOk()->assertSee('ส่งใหม่ได้ที่เครื่อง POS');
    }

    private function downstream($response, int $times): void
    {
        $this->mock(PosController::class)->shouldReceive('checkout')->times($times)->andReturn($response);
    }

    private function device(): PosDevice
    {
        $user = User::factory()->create(['username' => 'device_'.uniqid()]);

        return PosDevice::create([
            'name' => 'POS T1', 'user_id' => $user->id, 'terminal_code' => 'POS-T1',
            'token_hash' => hash('sha256', 'token-'.uniqid()),
        ]);
    }

    private function request(PosDevice $device, string $key): Request
    {
        $request = Request::create('/api/pos/checkout', 'POST', [
            'branch_id' => 1, 'shift_id' => 1, 'cashier_id' => 1, 'method' => 'cash', 'cash_received' => 30,
            'items' => [['product_id' => 1, 'qty' => 3, 'unit_price' => 10]],
        ]);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => $device->user);

        return $request;
    }
}
