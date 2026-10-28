<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * เริ่มโครงสาขาใหม่: เก็บ HQ + อำนาจเจริญ ให้อำนาจเจริญเป็น B001 แทนวารินที่ถูกลบ
 */
class ResetBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $amnat;

    private Branch $warin;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));

        Branch::whereNotIn('code', ['HQ'])->delete();
        Branch::firstOrCreate(['code' => 'HQ'], ['name_th' => 'สนญ.', 'is_active' => true]);
        $this->warin = Branch::create(['code' => 'B001', 'name_th' => 'สาขาวาริน', 'is_active' => true]);
        $this->amnat = Branch::create(['code' => 'B006', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        foreach ([$this->warin, $this->amnat] as $branch) {
            $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$branch->code, 'name' => 'คลัง '.$branch->code]);
            WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        }
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_it_keeps_hq_and_amnat_recodes_amnat_to_b001_and_removes_the_rest(): void
    {
        $this->freshBackup();
        $warinCashier = User::factory()->create(['username' => 'warin_probe', 'branch_id' => $this->warin->id, 'is_active' => true]);
        $amnatUser = User::factory()->create(['username' => 'amnat_probe', 'branch_id' => $this->amnat->id]);

        $this->artisan('erp:reset-branches', $this->resetOptions())->assertSuccessful();

        $this->assertSame(['B001', 'HQ'], Branch::orderBy('code')->pluck('code')->all());
        $this->assertSame('สาขาอำนาจเจริญ', Branch::where('code', 'B001')->value('name_th'));
        $this->assertSame($this->amnat->id, Branch::where('code', 'B001')->value('id'));
        // คลังวารินถูกลบ คลังอำนาจเจริญเปลี่ยนรหัสตาม (คลังกลางที่ไม่ผูกสาขาไม่แตะ)
        $this->assertSame([$this->amnat->id], Warehouse::where('code', 'WH-B001')->pluck('branch_id')->all());
        $this->assertFalse(Warehouse::where('code', 'WH-B006')->exists());
        $this->assertSame(0, Warehouse::where('branch_id', $this->warin->id)->count());

        // ผู้ใช้ของสาขาที่ลบยังอยู่ แต่ไม่มีสาขาและปิดใช้งาน
        $this->assertNull($warinCashier->fresh()->branch_id);
        $this->assertFalse((bool) $warinCashier->fresh()->is_active);
        $this->assertSame($this->amnat->id, $amnatUser->fresh()->branch_id);
    }

    public function test_it_stops_while_a_deleted_branch_still_has_documents(): void
    {
        $this->freshBackup();
        $type = DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        Document::create([
            'doc_number' => 'RB-1', 'document_type_id' => $type->id, 'branch_id' => $this->warin->id,
            'doc_date' => now()->toDateString(), 'status' => 'active', 'subtotal' => 1, 'total_amount' => 1,
            'created_by' => User::factory()->create(['username' => 'rb_doc'])->id,
        ]);

        $this->artisan('erp:reset-branches', $this->resetOptions())->expectsOutputToContain('documents')->assertFailed();

        $this->assertTrue(Branch::whereKey($this->warin->id)->exists());
        $this->assertSame('B006', $this->amnat->fresh()->code);
    }

    public function test_dry_run_and_missing_backup_change_nothing(): void
    {
        $this->artisan('erp:reset-branches', $this->resetOptions() + ['--dry-run' => true])->assertSuccessful();
        $this->artisan('erp:reset-branches', $this->resetOptions())->assertFailed();

        $this->assertTrue(Branch::whereKey($this->warin->id)->exists());
        $this->assertSame(1, Warehouse::where('branch_id', $this->warin->id)->count());
    }

    private function resetOptions(): array
    {
        return [
            '--keep' => ['HQ', 'B006'], '--rename' => ['B006:B001'],
            '--confirm-database' => DB::connection()->getDatabaseName(), '--force' => true,
        ];
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
