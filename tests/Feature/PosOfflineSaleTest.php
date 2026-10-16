<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Permission;
use App\Models\PosOfflineSale;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Web POS ขายออฟไลน์: คิวบิลส่งขึ้นมาทีหลังต้องไม่ซ้ำ ไม่หาย และไม่ข้ามกฎของ checkout
 */
class PosOfflineSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_sale_is_posted_through_the_normal_checkout(): void
    {
        $cashier = $this->userWith(['pos.use', 'pos.sell']);
        $seen = null;
        $this->mock(PosController::class)->shouldReceive('checkout')->once()
            ->andReturnUsing(function ($request) use (&$seen) {
                $seen = $request;

                return response()->json(['success' => true, 'receipt_no' => 'R-0001', 'receipt_id' => null]);
            });

        $uuid = (string) Str::uuid();
        $this->actingAs($cashier)->postJson(route('pos.offline-sales.sync'), ['sales' => [$this->sale($uuid)]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'posted')
            ->assertJsonPath('results.0.receipt_no', 'R-0001');

        $this->assertTrue($seen->attributes->get('pos_offline_sync'));
        $this->assertSame($uuid, $seen->input('offline_uuid'));
        $this->assertNotNull($seen->input('offline_sold_at'));
        $this->assertSame(PosOfflineSale::POSTED, PosOfflineSale::sole()->status);
    }

    public function test_resending_the_same_bill_never_creates_a_second_sale(): void
    {
        $cashier = $this->userWith(['pos.use', 'pos.sell']);
        $this->mock(PosController::class)->shouldReceive('checkout')->once()
            ->andReturn(response()->json(['success' => true, 'receipt_no' => 'R-0002']));

        $sale = $this->sale((string) Str::uuid());
        $this->actingAs($cashier)->postJson(route('pos.offline-sales.sync'), ['sales' => [$sale]])->assertOk();
        $this->actingAs($cashier)->postJson(route('pos.offline-sales.sync'), ['sales' => [$sale, $sale]])
            ->assertOk()
            ->assertJsonPath('results.0.receipt_no', 'R-0002')
            ->assertJsonPath('results.1.receipt_no', 'R-0002');

        $this->assertSame(1, PosOfflineSale::count());
    }

    public function test_rejected_bill_is_kept_for_review_instead_of_lost(): void
    {
        $cashier = $this->userWith(['pos.use', 'pos.sell']);
        $this->mock(PosController::class)->shouldReceive('checkout')->once()
            ->andReturn(response()->json(['success' => false, 'message' => 'ราคาต่ำกว่าที่อนุญาต'], 422));

        $this->actingAs($cashier)->postJson(route('pos.offline-sales.sync'), ['sales' => [$this->sale((string) Str::uuid())]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'needs_review')
            ->assertJsonPath('results.0.message', 'ราคาต่ำกว่าที่อนุญาต');

        $sale = PosOfflineSale::sole();
        $this->assertSame(PosOfflineSale::NEEDS_REVIEW, $sale->status);
        $this->assertSame(3, $sale->payload['items'][0]['qty']);
    }

    public function test_another_user_cannot_claim_someone_elses_bill(): void
    {
        $this->mock(PosController::class)->shouldReceive('checkout')->once()
            ->andReturn(response()->json(['success' => true, 'receipt_no' => 'R-0003']));
        $sale = $this->sale((string) Str::uuid());

        $this->actingAs($this->userWith(['pos.use', 'pos.sell']))->postJson(route('pos.offline-sales.sync'), ['sales' => [$sale]])->assertOk();
        $this->actingAs($this->userWith(['pos.use', 'pos.sell']))->postJson(route('pos.offline-sales.sync'), ['sales' => [$sale]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'conflict');
    }

    public function test_batch_is_limited_to_fifty_bills(): void
    {
        $sales = collect(range(1, 51))->map(fn () => $this->sale((string) Str::uuid()))->all();

        $this->actingAs($this->userWith(['pos.use', 'pos.sell']))
            ->postJson(route('pos.offline-sales.sync'), ['sales' => $sales])
            ->assertSessionHasErrors('sales');
        $this->assertSame(0, PosOfflineSale::count());
    }

    public function test_view_only_users_cannot_sync_sales(): void
    {
        $this->actingAs($this->userWith(['pos.use']))
            ->postJson(route('pos.offline-sales.sync'), ['sales' => [$this->sale((string) Str::uuid())]])
            ->assertForbidden();
    }

    public function test_ping_returns_a_fresh_csrf_token(): void
    {
        $this->actingAs($this->userWith(['pos.use']))->getJson(route('pos.ping'))
            ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['csrf_token']);
    }

    public function test_manager_retry_posts_in_the_cashiers_name_and_restores_the_manager(): void
    {
        $cashier = $this->userWith(['pos.use', 'pos.sell']);
        $manager = $this->userWith(['pos.use', 'pos.void', 'reports.view']);
        $sale = PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(), 'user_id' => $cashier->id, 'sold_at' => now(),
            'status' => PosOfflineSale::NEEDS_REVIEW, 'review_reason' => 'กะปิดแล้ว',
            'payload' => $this->sale('x')['payload'],
        ]);
        $actingAs = null;
        $this->mock(PosController::class)->shouldReceive('checkout')->once()
            ->andReturnUsing(function ($request) use (&$actingAs) {
                $actingAs = Auth::id();

                return response()->json(['success' => true, 'receipt_no' => 'R-0009']);
            });

        $this->actingAs($manager)->post(route('pos.offline-sales.retry', $sale), ['shift_id' => 77])->assertRedirect();

        $this->assertSame($cashier->id, $actingAs);
        $this->assertSame($manager->id, Auth::id());
        $sale->refresh();
        $this->assertSame(PosOfflineSale::POSTED, $sale->status);
        $this->assertSame(77, $sale->shift_id);
    }

    public function test_manager_can_close_a_bill_handled_by_hand(): void
    {
        $sale = PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(), 'sold_at' => now(),
            'status' => PosOfflineSale::NEEDS_REVIEW, 'payload' => ['items' => []],
        ]);

        $this->actingAs($this->userWith(['pos.use', 'pos.void', 'reports.view']))
            ->post(route('pos.offline-sales.resolve', $sale), ['note' => 'คีย์บิลใหม่แล้ว'])->assertRedirect();

        $this->assertSame(PosOfflineSale::RESOLVED, $sale->fresh()->status);
        $this->actingAs($this->userWith(['pos.use', 'reports.view']))->get(route('pos.offline-sales.index', ['status' => 'all']))
            ->assertOk()->assertSee('ปิดด้วยมือ');
    }

    /** ไม่ mock: ผ่าน PosController::checkout ตัวจริง (validation ของ sub-request ต้องทำงาน) */
    public function test_real_checkout_validation_failure_lands_in_review(): void
    {
        $this->actingAs($this->userWith(['pos.use', 'pos.sell']))
            ->postJson(route('pos.offline-sales.sync'), ['sales' => [$this->sale((string) Str::uuid())]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'needs_review');

        $sale = PosOfflineSale::sole();
        $this->assertNotEmpty($sale->review_reason);
        $this->assertNull($sale->receipt_no);
        $this->assertSame(0, \App\Models\PosReceipt::count());
    }

    private function sale(string $uuid): array
    {
        return [
            'offline_uuid' => $uuid,
            'sold_at' => now()->subMinutes(5)->toIso8601String(),
            'total' => 30,
            'payload' => [
                'branch_id' => 1, 'shift_id' => 1, 'cashier_id' => 1, 'method' => 'cash', 'cash_received' => 30,
                'items' => [['product_id' => 1, 'qty' => 3, 'unit_price' => 10]],
            ],
        ];
    }

    private function userWith(array $permissions): User
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['username' => 'offline-'.$n, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'OFF_'.$n, 'name' => 'Offline '.$n]);
        foreach ($permissions as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
