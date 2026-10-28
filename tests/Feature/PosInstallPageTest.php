<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * หน้าดาวน์โหลด/ผูกเครื่อง POS: บอกความพร้อมรายสาขา และขั้นติดตั้ง 4 ขั้น
 */
class PosInstallPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_without_a_connected_device_is_shown_as_not_ready_with_the_next_step(): void
    {
        $branch = $this->branch();
        Salesman::create(['branch_id' => $branch->id, 'code' => 'B901', 'name' => 'แคชเชียร์', 'is_active' => true, 'pos_pin_hash' => bcrypt('482917')]);

        $this->page()
            ->assertSee('data-testid="pos-readiness"', false)
            ->assertSee('ติดตั้งเครื่อง POS ใหม่ 4 ขั้น')
            ->assertSee('ผูกเครื่อง (ขั้น 2)')
            ->assertViewHas('posReadiness', fn ($rows) => collect($rows)->firstWhere('branch.code', 'B901')['cashiers_with_pin'] === 1);
    }

    public function test_a_branch_with_a_connected_device_and_a_pin_is_ready(): void
    {
        $branch = $this->branch();
        $user = User::factory()->create(['username' => 'b901_login', 'branch_id' => $branch->id]);
        Salesman::create(['branch_id' => $branch->id, 'code' => 'B901', 'name' => 'แคชเชียร์', 'is_active' => true, 'pos_pin_hash' => bcrypt('482917')]);
        PosDevice::create(['name' => 'เครื่อง 1', 'user_id' => $user->id, 'branch_id' => $branch->id, 'terminal_code' => 'T1', 'token_hash' => hash('sha256', 'x'), 'last_seen_at' => now()]);

        $this->page()->assertSee('พร้อมขาย');
    }

    public function test_the_page_warns_when_shifts_open_without_a_pin(): void
    {
        $this->branch();
        AppSetting::updateOrCreate(['key' => 'pos_passwordless_login'], ['value' => '1']);

        $this->page()->assertSee('โหมดเลือกชื่อไม่ใช้ PIN เปิดอยู่');
    }

    public function test_back_office_issues_a_temporary_pin_for_a_till_cashier_shown_once(): void
    {
        $branch = $this->branch();
        $cashier = Salesman::create(['branch_id' => $branch->id, 'code' => 'B901-2', 'name' => 'แคชเชียร์ เครื่อง 2', 'is_active' => true]);
        $this->page()->assertSee('data-testid="pos-cashiers"', false)->assertSee('B901-2')->assertSee('ยังไม่ตั้ง');

        $response = $this->post(route('settings.pos-cashiers.reset-pin', $cashier))
            ->assertRedirect()->assertSessionHas('cashier_pin_result');
        $pin = session('cashier_pin_result')['pin'];

        $cashier->refresh();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertTrue(Hash::check($pin, $cashier->pos_pin_hash));
        $this->assertTrue((bool) $cashier->must_change_pin);
        $this->assertTrue(AuditLog::where('action', 'cashier_pin_reset')->where('record_id', $cashier->id)->exists());
    }

    public function test_a_manager_linked_to_a_user_gets_the_pin_on_the_user_credential_too(): void
    {
        $branch = $this->branch();
        $user = User::factory()->create(['username' => 'b901_mgr', 'branch_id' => $branch->id]);
        $manager = Salesman::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'code' => 'M901', 'name' => 'หัวหน้า', 'is_active' => true]);
        $this->page();

        $this->post(route('settings.pos-cashiers.reset-pin', $manager));

        $pin = session('cashier_pin_result')['pin'];
        $this->assertTrue(Hash::check($pin, $user->fresh()->posCredential->pin_hash));
    }

    private function page()
    {
        $this->withoutVite();

        return $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('settings.index', ['tab' => 'pos-download']))->assertOk();
    }

    private function branch(): Branch
    {
        $branch = Branch::create(['code' => 'B901', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-B901', 'name' => 'คลัง']);
        $branch->update(['default_warehouse_location_id' => WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'หลัก'])->id]);

        return $branch;
    }
}
