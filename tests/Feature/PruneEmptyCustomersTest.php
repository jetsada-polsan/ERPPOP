<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneEmptyCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_identifies_only_empty_orphan_customers_without_changing_data(): void
    {
        $empty = Customer::create(['code' => 'EMPTY-1', 'name_th' => 'EMPTY-1', 'is_active' => true]);
        $withContact = Customer::create(['code' => 'CONTACT-1', 'name_th' => 'CONTACT-1', 'is_active' => true]);
        $withContact->contacts()->create(['phone' => '0812345678']);

        $this->artisan('erp:prune-empty-customers')
            ->expectsOutputToContain('ลบได้จริง: 1')
            ->expectsOutputToContain('dry-run: ไม่มีข้อมูลใดถูกแก้ไข')
            ->assertSuccessful();

        $this->assertTrue(Customer::withTrashed()->whereKey($empty->id)->exists());
        $this->assertTrue(Customer::withTrashed()->whereKey($withContact->id)->exists());
    }

    public function test_purge_keeps_customers_referenced_by_any_foreign_key_table(): void
    {
        $empty = Customer::create(['code' => 'EMPTY-2', 'name_th' => 'EMPTY-2', 'is_active' => true]);
        $referenced = Customer::create(['code' => 'REFERENCED-1', 'name_th' => 'REFERENCED-1', 'is_active' => true]);
        Schema::create('customer_cleanup_probe', function ($table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
        });
        DB::table('customer_cleanup_probe')->insert(['customer_id' => $referenced->id]);

        $this->artisan('erp:prune-empty-customers', [
            '--purge' => true,
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])
            ->expectsOutputToContain('customer_cleanup_probe')
            ->assertSuccessful();

        $this->assertFalse(Customer::withTrashed()->whereKey($empty->id)->exists());
        $this->assertTrue(Customer::withTrashed()->whereKey($referenced->id)->exists());
        $this->assertSame(1, AuditLog::where('action', 'purge')->where('record_id', $empty->id)->count());

        Schema::dropIfExists('customer_cleanup_probe');
    }

    public function test_purge_refuses_when_database_name_does_not_match(): void
    {
        $empty = Customer::create(['code' => 'EMPTY-3', 'name_th' => 'EMPTY-3', 'is_active' => true]);

        $this->artisan('erp:prune-empty-customers', [
            '--purge' => true,
            '--confirm-database' => 'not-this-database',
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue(Customer::withTrashed()->whereKey($empty->id)->exists());
    }
}
