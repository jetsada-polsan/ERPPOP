<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\CashBook;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosPayment;
use App\Models\PosReceipt;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use App\Services\Accounting\CashBookPostingService;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosShiftFloatTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Salesman $cashier;

    private PosTerminal $terminal;

    private PosDevice $device;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'FLOAT', 'name_th' => 'Float', 'is_active' => true]);
        $this->cashier = Salesman::create(['code' => 'FLOAT', 'name' => 'Cashier', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->user = User::factory()->create(['is_active' => true, 'must_change_password' => false, 'branch_id' => $this->branch->id, 'salesman_id' => $this->cashier->id]);
        $this->cashier->update(['user_id' => $this->user->id]);
        $this->terminal = PosTerminal::create(['branch_id' => $this->branch->id, 'code' => 'T-FLOAT', 'name' => 'Till']);
        $this->device = PosDevice::create(['name' => 'Till', 'branch_id' => $this->branch->id, 'user_id' => $this->user->id,
            'terminal_code' => $this->terminal->code, 'token_hash' => hash('sha256', 'local-test'),
            'active_cashier_id' => $this->cashier->id, 'cashier_verified_at' => now()]);
        $this->actingAs($this->user);
    }

    private function shiftCall(string $action, array $data = []): array
    {
        $request = Request::create('/api/pos/shift', 'POST', $data);
        $request->attributes->set('pos_device', $this->device);
        $this->app->instance('request', $request);

        return app(PosController::class)->$action($request)->getData(true);
    }

    private function open(string $amount = '2000.00', ?string $note = null): array
    {
        return $this->shiftCall('openShift', ['branch_id' => $this->branch->id, 'cashier_id' => $this->cashier->id,
            'opening_cash' => $amount, 'opening_note' => $note])['shift'];
    }

    public function test_carry_variance_deposit_cents_and_cashbook_across_shifts(): void
    {
        $first = $this->open();
        $this->assertNull($first['carried_float'], 'ครั้งแรกของเครื่องยังไม่มีใครบันทึกเงินทอนส่งต่อ');
        $this->assertNull($first['opening_variance']);
        $this->assertArrayNotHasKey('expected_cash', $first);
        $receipt = PosReceipt::create(['pos_terminal_id' => $this->terminal->id, 'pos_shift_id' => $first['id'],
            'receipt_no' => 'FLOAT-1', 'receipt_date' => now(), 'net_sales' => '13432.75', 'status' => 'completed']);
        PosPayment::create(['pos_receipt_id' => $receipt->id, 'method' => 'cash', 'amount' => '13432.75']);
        $preview = $this->shiftCall('closeShift', ['shift_id' => $first['id'], 'counted_cash' => '15432.75',
            'float_kept' => '2000', 'preview' => true, 'expected_cash' => 1, 'deposit_amount' => 1]);
        $this->assertEquals('13432.75', $preview['shift']['deposit_amount']);
        $this->assertEquals(15432.75, $preview['shift']['expected_cash']);
        $this->assertEquals('open', PosShift::find($first['id'])->status);
        $this->assertSame(0, CashBook::count());
        $closed = $this->shiftCall('closeShift', ['shift_id' => $first['id'], 'counted_cash' => '15432.75',
            'float_kept' => 2000, 'deposit_reference' => 'BAG-1', 'expected_cash' => 0, 'deposit_amount' => 0])['shift'];
        $this->assertEquals(13432.75, $closed['deposit_amount']);
        $this->assertEquals(0, $closed['cash_difference']);
        $this->assertEquals(13432.75, CashBook::sum('cash_in'));
        app(CashBookPostingService::class)->postShiftClose(PosShift::find($first['id']), 13432.75, 0);
        $this->assertSame(1, CashBook::count());
        try {
            $this->open('1950');
            $this->fail('A missing opening variance reason must fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_note', $e->errors());
        }
        $second = $this->open('1950', 'รับเงินจริงขาด 50');
        $this->assertEquals(2000, $second['carried_float']);
        $this->assertEquals(-50, $second['opening_variance']);
        $secondClosed = $this->shiftCall('closeShift', ['shift_id' => $second['id'], 'counted_cash' => '1950'])['shift'];
        $this->assertEquals(1950, $secondClosed['float_kept']);
        $this->assertEquals(0, $secondClosed['deposit_amount']);
        $this->assertEquals(13432.75, CashBook::sum('cash_in'));
        $this->assertSame(2, CashBook::count());
        $this->assertEquals(50, CashBook::sum('cash_out'));
        $third = $this->open('1950');
        $this->assertEquals(1950, $third['carried_float']);

    }

    public function test_reject_float_above_count_and_subcent_count(): void
    {
        $shift = $this->open();
        foreach ([['counted_cash' => '1999.99', 'float_kept' => 2000], ['counted_cash' => '2000.001']] as $values) {
            try {
                $this->shiftCall('closeShift', ['shift_id' => $shift['id'], ...$values]);
                $this->fail('Invalid cash must fail');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame('open', PosShift::find($shift['id'])->status);
    }

    public function test_manager_visibility_uses_pin_cashier_not_device_owner(): void
    {
        $role = Role::create(['code' => 'FLOAT_MGR', 'name' => 'Manager']);
        $permission = Permission::firstOrCreate(['code' => 'pos.cash.manage'], ['name' => 'Cash management']);
        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);
        $shift = $this->open();
        $this->assertArrayHasKey('expected_cash', $shift);
        $other = User::factory()->create();
        $this->cashier->update(['user_id' => $other->id]);
        $payload = $this->shiftCall('activeShift', ['branch_id' => $this->branch->id, 'cashier_id' => $this->cashier->id])['shift'];
        $this->assertArrayNotHasKey('expected_cash', $payload);
        $this->assertArrayNotHasKey('cash_sales', $payload);
    }

    public function test_zero_count_carries_zero_and_other_terminal_cannot_close(): void
    {
        $shift = $this->open();
        $this->device->terminal_code = 'OTHER';
        $request = Request::create('/api/pos/shift/close', 'POST', ['shift_id' => $shift['id'], 'counted_cash' => 0]);
        $request->attributes->set('pos_device', $this->device);
        $this->app->instance('request', $request);
        $this->assertSame(403, app(PosController::class)->closeShift($request)->getStatusCode());
        $this->device->terminal_code = $this->terminal->code;
        $closed = $this->shiftCall('closeShift', ['shift_id' => $shift['id'], 'counted_cash' => '0'])['shift'];
        $this->assertEquals(0, $closed['float_kept']);
        $this->assertEquals(0, $closed['deposit_amount']);
        $next = $this->open('0');
        $this->assertEquals(0, $next['carried_float']);
    }

    public function test_z_report_has_two_copies_and_every_required_field(): void
    {
        $shift = $this->open('1950', 'รับเงินจริงขาด');
        $this->shiftCall('closeShift', ['shift_id' => $shift['id'], 'counted_cash' => '2500.25',
            'float_kept' => 2000, 'deposit_reference' => 'BAG-Z']);
        $request = Request::create('/pos/shift/z-report');
        $this->app->instance('request', $request);
        $html = app(PosController::class)->zReport($request, PosShift::find($shift['id']))->render();
        foreach (['ใบนำส่ง', 'ใบเก็บ', 'จำนวนยกเลิก', 'จำนวนรับคืน', 'ส่วนลดรวม', 'เงินทอนยกมา',
            'ส่วนต่างตอนรับกะ', 'รับเงินจริงขาด', 'เงินนำส่งปิดกะ', 'BAG-Z', '500.25', 'เงินทอนเก็บในลิ้นชัก', 'ผู้ส่ง', 'ผู้รับ (หัวหน้า)'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_non_blind_policy_and_cash_warning_are_server_controlled(): void
    {
        $this->terminal->update(['blind_count' => false, 'cash_warning_threshold' => 1999]);
        $shift = $this->open();
        $this->assertFalse($shift['blind_count']);
        $this->assertTrue($shift['cash_warning']);
        $this->assertEquals(2000, $shift['expected_cash']);
    }

    public function test_float_policy_requires_settings_permission_and_records_audit(): void
    {
        $this->user->update(['must_change_password' => false]);
        $values = ['default_float' => '2500.25', 'cash_warning_threshold' => '12000.50', 'blind_count' => false];
        $response = $this->postJson(route('pos-admin.terminals.float', $this->terminal), $values);
        $this->assertSame(403, $response->status());
        $role = Role::create(['code' => 'FLOAT_SETTINGS', 'name' => 'Settings']);
        $permission = Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'Settings']);
        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);
        $this->actingAs($this->user->fresh());
        $saved = $this->postJson(route('pos-admin.terminals.float', $this->terminal), $values);
        $this->assertSame(302, $saved->status(), $saved->getContent());
        $this->assertEquals('2500.25', $this->terminal->fresh()->default_float);
        $this->assertFalse($this->terminal->fresh()->blind_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos_float_policy_updated', 'record_id' => $this->terminal->id]);
        $invalid = $this->postJson(route('pos-admin.terminals.float', $this->terminal), [...$values, 'default_float' => '2.001']);
        $this->assertSame(302, $invalid->status());
        $invalid->assertSessionHasErrors('default_float');
        $this->assertEquals('2500.25', $this->terminal->fresh()->default_float);
    }

    public function test_carry_is_isolated_by_terminal_and_another_cashier_cannot_overlap_drawer(): void
    {
        $other = PosTerminal::create(['branch_id' => $this->branch->id, 'code' => 'T-OTHER', 'name' => 'Other']);
        PosShift::create(['branch_id' => $this->branch->id, 'pos_terminal_id' => $other->id,
            'cashier_id' => $this->cashier->id, 'shift_no' => 'OTHER-SHIFT', 'opened_at' => now(), 'closed_at' => now(),
            'opening_cash' => 7000, 'float_kept' => 7000, 'status' => 'closed']);
        $this->assertNull($this->open()['carried_float'], 'เงินทอนของเครื่องอื่นต้องไม่ยกมา');
        $second = Salesman::create(['code' => 'SECOND', 'name' => 'Second', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->device->active_cashier_id = $second->id;
        $this->expectException(ValidationException::class);
        $this->shiftCall('openShift', ['branch_id' => $this->branch->id, 'cashier_id' => $second->id, 'opening_cash' => 2000]);
    }

    public function test_http_api_hides_expected_until_a_valid_count_is_submitted(): void
    {
        $role = Role::create(['code' => 'FLOAT_SELL', 'name' => 'Cashier']);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'Sell']);
        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);
        $headers = ['Authorization' => 'Bearer local-test'];
        $opened = $this->postJson('/api/pos/shift/open', ['branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id, 'opening_cash' => '2000.00'], $headers)->assertOk();
        $id = $opened->json('shift.id');
        $this->assertArrayNotHasKey('expected_cash', $opened->json('shift'));
        $active = $this->getJson('/api/pos/shift?branch_id='.$this->branch->id.'&cashier_id='.$this->cashier->id, $headers)->assertOk();
        $this->assertArrayNotHasKey('expected_cash', $active->json('shift'));
        $this->postJson('/api/pos/shift/close', ['shift_id' => $id, 'preview' => true], $headers)->assertUnprocessable();
        $preview = $this->postJson('/api/pos/shift/close', ['shift_id' => $id, 'counted_cash' => '2010.25',
            'float_kept' => '2000.00', 'preview' => true, 'expected_cash' => 9999, 'deposit_amount' => 9999], $headers)->assertOk();
        $this->assertEquals(2000, $preview->json('shift.expected_cash'));
        $this->assertEquals(10.25, $preview->json('shift.cash_difference'));
        $this->assertEquals(10.25, $preview->json('shift.deposit_amount'));
        $this->assertSame('open', PosShift::find($id)->status);
    }

    public function test_migration_compiles_postgresql_ddl_without_a_database_connection(): void
    {
        $connection = new PostgresConnection(function () {
            throw new \RuntimeException('This DDL test must never connect to a database');
        }, 'unused', '', ['version' => '16.0']);
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_05_000001_add_shift_float_controls.php');
            $queries = $connection->pretend(fn () => $migration->up());
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('alter table "pos_terminals" add column "default_float" decimal(18, 2)', $sql);
            $this->assertStringContainsString("\"blind_count\" boolean not null default '1'", $sql);
            $this->assertStringContainsString('"float_kept" decimal(18, 2) null', $sql);
            $this->assertStringNotContainsString('update ', strtolower($sql));
        } finally {
            Schema::swap($original);
        }
    }

    public function test_cashbook_failure_rolls_back_close_and_float(): void
    {
        $shift = $this->open();
        $this->mock(CashBookPostingService::class, function ($mock) {
            $mock->shouldReceive('postShiftClose')->once()->andThrow(new \RuntimeException('posting failed'));
        });
        try {
            $this->shiftCall('closeShift', ['shift_id' => $shift['id'], 'counted_cash' => 2000]);
            $this->fail('Posting error must escape');
        } catch (\RuntimeException $e) {
            $this->assertSame('posting failed', $e->getMessage());
        }
        $this->assertSame('open', PosShift::find($shift['id'])->status);
        $this->assertNull(PosShift::find($shift['id'])->float_kept);
    }

    public function test_first_shift_after_go_live_never_books_a_fake_shortage(): void
    {
        // A shift closed before float tracking existed has no float_kept: custody is unknown.
        PosShift::create(['branch_id' => $this->branch->id, 'pos_terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id, 'shift_no' => 'LEGACY-1', 'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8), 'opening_cash' => 500, 'expected_cash' => 500,
            'counted_cash' => 500, 'status' => 'closed']);

        // The shop really starts with 1,000 while the till default is 2,000: no reason demanded, no cash-book line.
        $shift = $this->open('1000');
        $this->assertNull($shift['carried_float']);
        $this->assertNull($shift['opening_variance']);
        $this->shiftCall('closeShift', ['shift_id' => $shift['id'], 'counted_cash' => '1000', 'float_kept' => '1000']);
        $this->assertSame(0, CashBook::where('source_type', CashBookPostingService::SOURCE_POS_OPENING_VARIANCE)->count());
        $this->assertEquals(0, CashBook::sum('cash_out'));

        // From now on the handover is recorded, so the next shift is checked against it.
        $this->assertEquals(1000, $this->open('1000')['carried_float']);
    }
}
