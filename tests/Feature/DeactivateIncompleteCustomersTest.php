<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeactivateIncompleteCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_change_customer_status(): void
    {
        $incomplete = Customer::create(['code' => 'NO-NAME-1', 'name_th' => 'NO-NAME-1', 'is_active' => true]);
        Customer::create(['code' => 'NAMED-1', 'name_th' => 'ลูกค้าปกติ', 'is_active' => true]);

        $this->artisan('erp:deactivate-incomplete-customers')
            ->expectsOutputToContain('ลูกค้าชื่อไม่สมบูรณ์ที่กำลังใช้งาน: 1 ราย')
            ->expectsOutputToContain('dry-run: ไม่มีข้อมูลใดถูกแก้ไข')
            ->assertSuccessful();

        $this->assertTrue($incomplete->fresh()->is_active);
    }

    public function test_apply_deactivates_incomplete_customer_but_preserves_the_record(): void
    {
        $incomplete = Customer::create(['code' => 'NO-NAME-2', 'name_th' => 'NO-NAME-2', 'is_active' => true]);
        $named = Customer::create(['code' => 'NAMED-2', 'name_th' => 'ลูกค้าปกติ', 'is_active' => true]);

        $this->artisan('erp:deactivate-incomplete-customers', [
            '--apply' => true,
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFalse($incomplete->fresh()->is_active);
        $this->assertTrue($named->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'deactivate')->where('record_id', $incomplete->id)->count());
    }

    public function test_apply_refuses_a_different_database_name(): void
    {
        $incomplete = Customer::create(['code' => 'NO-NAME-3', 'name_th' => 'NO-NAME-3', 'is_active' => true]);

        $this->artisan('erp:deactivate-incomplete-customers', [
            '--apply' => true,
            '--confirm-database' => 'wrong-db',
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue($incomplete->fresh()->is_active);
    }
}
