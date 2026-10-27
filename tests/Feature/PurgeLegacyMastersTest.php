<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ล้างลูกค้า พนักงานขาย และข้อมูลจับคู่ระบบเดิม — ผู้ใช้และผู้ขายต้องยังอยู่
 */
class PurgeLegacyMastersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_it_removes_customers_and_salesmen_but_keeps_users_and_suppliers(): void
    {
        $this->freshBackup();
        [$user, $customer] = $this->seedMasters();
        $customer->delete(); // ที่ซ่อนไว้ก็ต้องหายด้วย

        $this->artisan('erp:purge-legacy-masters', ['--confirm-database' => $this->database(), '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(0, DB::table('customer_addresses')->count());
        $this->assertSame(0, DB::table('salesmen')->count());
        // บัญชี LINE ของสมาชิกที่ผูกลูกค้าหายตาม; สมาชิกที่ไม่ผูกลูกค้ายังอยู่พร้อมบัญชี LINE
        $this->assertSame(['LINE-FREE'], DB::table('member_line_accounts')->pluck('line_user_id')->all());
        $this->assertSame(['M-FREE'], DB::table('members')->pluck('member_code')->all());
        $this->assertSame(0, DB::table('legacy_table_mappings')->count());
        $this->assertSame(0, DB::table('app_settings')->where('key', 'like', 'legacy_backoffice_%')->count());
        $this->assertNull($user->fresh()->salesman_id);
        $this->assertTrue(Supplier::where('code', 'SUP-LG')->exists());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedMasters();

        $this->artisan('erp:purge-legacy-masters', ['--confirm-database' => $this->database(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertTrue(Customer::where('code', 'C-LG')->exists());
        $this->assertTrue(Salesman::where('code', 'S1')->exists());
    }

    public function test_it_refuses_while_transactions_reference_customers(): void
    {
        $this->freshBackup();
        [, $customer] = $this->seedMasters();
        DB::table('customer_ledger')->insert($this->ledgerRow($customer->id));

        $this->artisan('erp:purge-legacy-masters', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('erp:reset-transactions')
            ->assertFailed();

        $this->assertTrue(Customer::where('code', 'C-LG')->exists());
    }

    public function test_it_needs_a_fresh_backup(): void
    {
        $this->seedMasters();

        $this->artisan('erp:purge-legacy-masters', ['--confirm-database' => $this->database(), '--force' => true])
            ->assertFailed();

        $this->assertTrue(Customer::where('code', 'C-LG')->exists());
    }

    /** @return array{0:User, 1:Customer} */
    private function seedMasters(): array
    {
        $branch = Branch::create(['code' => 'LG', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $salesman = Salesman::create(['branch_id' => $branch->id, 'code' => 'S1', 'name' => 'พนักงานทดสอบ', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'legacy_probe', 'salesman_id' => $salesman->id]);
        $customer = Customer::create(['code' => 'C-LG', 'name_th' => 'ลูกค้าทดสอบ', 'is_active' => true]);
        CustomerAddress::create(['customer_id' => $customer->id, 'address_line' => 'ที่อยู่ทดสอบ', 'is_default' => true]);
        $linked = DB::table('members')->insertGetId(['member_code' => 'M-LG', 'name' => 'สมาชิกทดสอบ', 'customer_id' => $customer->id]);
        $free = DB::table('members')->insertGetId(['member_code' => 'M-FREE', 'name' => 'สมาชิกอิสระ']);
        DB::table('member_line_accounts')->insert([
            ['member_id' => $linked, 'line_user_id' => 'LINE-LG'],
            ['member_id' => $free, 'line_user_id' => 'LINE-FREE'],
        ]);
        Supplier::create(['code' => 'SUP-LG', 'name_th' => 'ผู้ขายทดสอบ', 'is_active' => true]);
        DB::table('legacy_table_mappings')->insert($this->mappingRow());
        DB::table('app_settings')->insert(['key' => 'legacy_backoffice_summary_test', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $customer];
    }

    private function mappingRow(): array
    {
        $columns = collect(DB::getSchemaBuilder()->getColumns('legacy_table_mappings'))
            ->reject(fn ($c) => $c['name'] === 'id' || $c['nullable'] || $c['default'] !== null);

        return $columns->mapWithKeys(fn ($c) => [$c['name'] => str_contains($c['type_name'], 'int') ? 1 : 'probe'])->all();
    }

    private function ledgerRow(int $customerId): array
    {
        $columns = collect(DB::getSchemaBuilder()->getColumns('customer_ledger'))
            ->reject(fn ($c) => $c['name'] === 'id' || $c['nullable'] || $c['default'] !== null);

        return $columns->mapWithKeys(fn ($c) => [$c['name'] => match (true) {
            $c['name'] === 'customer_id' => $customerId,
            str_contains($c['type_name'], 'date') || str_contains($c['type_name'], 'time') => now()->toDateString(),
            str_contains($c['type_name'], 'int') || str_contains($c['type_name'], 'numeric') || str_contains($c['type_name'], 'decimal') => 0,
            default => 'probe',
        }])->all();
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
