<?php

namespace Tests\Feature;

use App\Console\Commands\ErpResetTransactions;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * คำสั่งล้างข้อมูลก่อน UAT — ตัวที่ลบข้อมูลจริงต้องถูกล้อมด้วยเทสต์ให้แน่นที่สุด
 */
class ErpResetTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupDirectory);
        File::cleanDirectory($this->backupDirectory);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory($this->backupDirectory);
        parent::tearDown();
    }

    public function test_it_clears_transactions_and_stock_but_keeps_every_master_record(): void
    {
        $this->freshBackup();
        $fixture = $this->seedTransactions();

        $this->artisan('erp:reset-transactions', [
            '--confirm-database' => $this->database(),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Document::count(), 'เอกสารต้องถูกล้าง');
        $this->assertSame(0, DB::table('gl_journals')->count(), 'รายการ GL ต้องถูกล้าง');
        $this->assertSame(0, DB::table('document_sequences')->count(), 'เลขที่เอกสารต้องเริ่มนับใหม่');
        $this->assertSame(0, DB::table('imported_receipts')->count(), 'staging นำเข้าเก่าต้องถูกล้าง');

        $balance = StockBalance::find($fixture['balance_id']);
        $this->assertNotNull($balance, 'แถวยอดคงเหลือต้องยังอยู่');
        $this->assertSame('0.00000000', (string) $balance->on_hand_qty, 'ยอดคงเหลือต้องเป็นศูนย์');
        $this->assertSame('0.00000000', (string) $balance->reserved_qty, 'ยอดจองต้องเป็นศูนย์');

        $this->assertSame(1, Product::count(), 'สินค้าต้องคงอยู่');
        $this->assertSame(1, Customer::count(), 'ลูกค้าต้องคงอยู่');
        $this->assertSame(1, Supplier::count(), 'ผู้ขายต้องคงอยู่');
        $this->assertSame(1, User::where('id', $fixture['user_id'])->count(), 'ผู้ใช้ต้องคงอยู่');
        $this->assertGreaterThan(0, Role::count(), 'บทบาทต้องคงอยู่');
        $this->assertSame(1, ReportDefinition::where('code', 'reset-probe')->count(), 'ทะเบียนรายงานต้องคงอยู่');
        $this->assertSame(1, AuditLog::count(), 'audit_logs ต้องคงอยู่');
        $this->assertSame(1, DB::table('app_settings')->where('key', 'reset-probe')->count(), 'ตั้งค่าระบบต้องคงอยู่');
        $this->assertTrue(Branch::where('code', 'RS')->exists(), 'สาขาต้องคงอยู่');
    }

    public function test_dry_run_reports_the_plan_without_touching_any_row(): void
    {
        $this->seedTransactions();
        $before = Document::count();

        $this->artisan('erp:reset-transactions', [
            '--confirm-database' => $this->database(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry-run: ไม่มีข้อมูลใดถูกแก้ไข')
            ->assertSuccessful();

        $this->assertSame($before, Document::count(), 'dry-run ห้ามลบเอกสาร');
        $this->assertSame('5.00000000', (string) StockBalance::first()->on_hand_qty, 'dry-run ห้ามแตะยอดคงเหลือ');
    }

    public function test_it_refuses_when_the_database_name_does_not_match(): void
    {
        $this->freshBackup();
        $this->seedTransactions();

        $this->artisan('erp:reset-transactions', ['--confirm-database' => 'ฐานอื่น', '--force' => true])
            ->assertFailed();

        $this->assertSame(1, Document::count(), 'พิมพ์ชื่อฐานผิดแล้วต้องไม่ลบอะไรเลย');
    }

    public function test_it_refuses_without_a_backup(): void
    {
        $this->seedTransactions();

        $this->artisan('erp:reset-transactions', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('ไม่พบไฟล์สำรอง')
            ->assertFailed();

        $this->assertSame(1, Document::count());
    }

    public function test_it_refuses_when_the_backup_is_older_than_two_hours(): void
    {
        $this->freshBackup(ageHours: 3);
        $this->seedTransactions();

        $this->artisan('erp:reset-transactions', ['--confirm-database' => $this->database(), '--force' => true])
            ->assertFailed();

        $this->assertSame(1, Document::count());
    }

    public function test_it_refuses_when_the_backup_checksum_does_not_match(): void
    {
        $backup = $this->freshBackup();
        File::put($backup.'.sha256', str_repeat('0', 64).'  '.basename($backup));
        $this->seedTransactions();

        $this->artisan('erp:reset-transactions', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('checksum ของ backup ไม่ตรง')
            ->assertFailed();

        $this->assertSame(1, Document::count());
    }

    public function test_a_foreign_key_from_outside_the_whitelist_stops_the_run_instead_of_cascading(): void
    {
        $this->freshBackup();
        $this->seedTransactions();

        // ตารางสมมุติที่อ้างถึง documents แต่ไม่ได้อยู่ใน whitelist
        Schema::create('reset_probe_attachments', function ($table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents');
        });
        DB::table('reset_probe_attachments')->insert(['document_id' => Document::first()->id]);

        $this->artisan('erp:reset-transactions', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('reset_probe_attachments')
            ->assertFailed();

        $this->assertSame(1, Document::count(), 'ต้องไม่ลบเอกสาร');
        $this->assertSame(1, DB::table('reset_probe_attachments')->count(), 'ห้าม cascade ไปลบตารางนอก whitelist');

        Schema::dropIfExists('reset_probe_attachments');
    }

    public function test_a_failure_midway_rolls_everything_back(): void
    {
        $this->freshBackup();
        $fixture = $this->seedTransactions();

        // ลงทะเบียนทับด้วยรุ่นที่พังหลังล้างเสร็จ เพื่อพิสูจน์ว่า transaction ย้อนคืนได้จริง
        $this->app[ConsoleKernel::class]->registerCommand(new class extends ErpResetTransactions
        {
            protected function truncate(array $tables): void
            {
                parent::truncate($tables);
                throw new RuntimeException('พังกลางคัน');
            }
        });

        $this->artisan('erp:reset-transactions', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('rollback')
            ->assertFailed();

        $this->assertSame(1, Document::count(), 'เอกสารต้องกลับมาครบ');
        $this->assertSame(1, DB::table('gl_journals')->count(), 'รายการ GL ต้องกลับมาครบ');
        $this->assertSame('5.00000000', (string) StockBalance::find($fixture['balance_id'])->on_hand_qty, 'ยอดคงเหลือต้องไม่ถูกแตะ');
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }

    /** @return array{user_id:int, balance_id:int} */
    private function seedTransactions(): array
    {
        $branch = Branch::create(['code' => 'RS', 'name_th' => 'สาขาทดสอบล้างข้อมูล', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-RS', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $unit = ProductUnit::create(['code' => 'EA-RS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'RS-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ]);
        $customer = Customer::create(['code' => 'CUS-RS', 'name_th' => 'ลูกค้าทดสอบ', 'is_active' => true]);
        Supplier::create(['code' => 'SUP-RS', 'name_th' => 'ผู้ขายทดสอบ', 'is_active' => true]);
        $type = DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        $user = User::factory()->create(['username' => 'reset-probe']);
        $account = ChartOfAccount::firstOrCreate(['code' => '1010'], ['name' => 'เงินสด', 'type' => 'asset']);

        $document = Document::create([
            'doc_number' => 'RS-RESET-001', 'document_type_id' => $type->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'doc_date' => now()->toDateString(), 'status' => 'active',
            'subtotal' => 100, 'total_amount' => 100, 'created_by' => $user->id,
        ]);
        DB::table('gl_journals')->insert([
            'document_id' => $document->id, 'account_id' => $account->id,
            'debit' => 100, 'credit' => 0, 'entry_date' => now()->toDateString(),
        ]);
        DB::table('document_sequences')->insert([
            'scope' => 'CS-RS', 'period' => '20260823', 'last_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $batchId = DB::table('import_batches')->insertGetId([
            'pos_code' => 'RS01', 'sale_date' => now()->toDateString(), 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('imported_receipts')->insert([
            'batch_id' => $batchId, 'pos_code' => 'RS01', 'receipt_no' => 'OLD-1',
            'receipt_date' => now()->toDateString(), 'net_amount' => 50, 'status' => 'posted', 'created_at' => now(),
        ]);

        $balance = StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 5, 'reserved_qty' => 2,
        ]);

        ReportDefinition::create([
            'code' => 'reset-probe', 'name' => 'รายงานทดสอบ', 'category' => 'sales', 'category_title' => 'ทดสอบ',
            'status' => 'available', 'enabled' => true,
        ]);
        DB::table('app_settings')->insert(['key' => 'reset-probe', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'reset_probe',
            'table_name' => 'documents', 'record_id' => $document->id,
        ]);

        return ['user_id' => $user->id, 'balance_id' => $balance->id];
    }

    /** สร้างไฟล์สำรองปลอมพร้อม checksum ที่ถูกต้อง */
    private function freshBackup(int $ageHours = 0): string
    {
        $path = $this->backupDirectory.'/erp-db-'.now()->format('Ymd-His').'.sql.gz';
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));

        if ($ageHours > 0) {
            touch($path, time() - ($ageHours * 3600));
        }

        return $path;
    }
}
