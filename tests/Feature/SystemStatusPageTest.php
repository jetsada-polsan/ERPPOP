<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PosReleasePruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemStatusPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_system_status_requires_settings_permission_and_displays_every_card(): void
    {
        $staff = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $staffRole = Role::create(['code' => 'STATUS_STAFF', 'name' => 'Status staff']);
        $staffRole->permissions()->attach(Permission::firstOrCreate(['code' => 'reports.view'], ['name' => 'ดูรายงาน'])->id);
        $staff->roles()->attach($staffRole->id);

        $this->actingAs($staff)->get('/system/status')->assertForbidden();

        $this->actingAs($this->settingsAdmin())->get('/system/status')
            ->assertOk()
            ->assertSee('สถานะระบบ')
            ->assertSee('พื้นที่ดิสก์')
            ->assertSee('หน่วยความจำ')
            ->assertSee('โหลด CPU')
            ->assertSee('ฐานข้อมูล')
            ->assertSee('Backup')
            ->assertSee('Queue')
            ->assertSee('ซิงค์ BPlus')
            ->assertSee('Token ซิงค์')
            ->assertSee('ข้อมูล B18')
            ->assertSee('pos-python-releases')
            ->assertSee('รีเฟรช');
    }

    public function test_sync_runs_show_duration_stuck_processing_and_tokens_never_show_hashes(): void
    {
        $tokenHash = hash('sha256', 'never-display-this-bplus-token');
        $tokenId = DB::table('bplus_sync_tokens')->insertGetId([
            'name' => 'สาขาทดสอบ', 'route_code' => 'R01', 'token_hash' => $tokenHash,
            'last_used_at' => now()->subMinutes(5), 'last_ip' => '10.20.30.40',
            'created_at' => now()->subDays(2), 'updated_at' => now()->subMinutes(5),
        ]);
        DB::table('bplus_sync_tokens')->insert([
            'name' => 'สาขาทดสอบสอง', 'route_code' => 'R02', 'token_hash' => hash('sha256', 'second-test-token'),
            'last_used_at' => null, 'last_ip' => null, 'revoked_at' => null,
            'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(1),
        ]);
        DB::table('bplus_sync_runs')->insert([
            ['bplus_sync_token_id' => $tokenId, 'route_code' => 'R01', 'status' => 'ok', 'received' => 8, 'erp_documents' => 8, 'problems' => 0, 'message' => 'บันทึกสำเร็จ', 'created_at' => now()->subMinutes(40), 'updated_at' => now()->subMinutes(37)],
            ['bplus_sync_token_id' => $tokenId, 'route_code' => 'R01', 'status' => 'processing', 'received' => 2, 'erp_documents' => 0, 'problems' => 0, 'message' => 'กำลังทำงาน', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        ]);

        $html = $this->actingAs($this->settingsAdmin())->get('/system/status')->assertOk()
            ->assertSee('status-bplus_runs')
            ->assertSee('180 วินาที')
            ->assertSee('processing เกิน 15 นาที')
            ->assertSee('สาขาทดสอบ')
            ->assertSee('ใช้งานอยู่ 2 ตัว')
            ->assertSee('id="status-bplus_tokens" class="system-status-card status-warning"', false)
            ->assertSee('10.20.30.40')
            ->getContent();

        $this->assertStringNotContainsString($tokenHash, $html);
        $this->assertStringNotContainsString('token_hash', $html);
    }

    public function test_failed_jobs_make_queue_card_yellow_and_b18_totals_are_rendered(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RefreshSummary']),
            'exception' => "RuntimeException: mock queue failure\nstack trace", 'failed_at' => now(),
        ]);

        $branchId = DB::table('branches')->insertGetId(['code' => 'STAT', 'name_th' => 'สาขาสถานะ', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $bookingType = DB::table('document_types')->where('code', 'BOOKING')->first();
        if (! $bookingType) {
            $bookingTypeId = DB::table('document_types')->insertGetId(['code' => 'BOOKING', 'name_th' => 'ใบจอง']);
        } else {
            $bookingTypeId = $bookingType->id;
        }
        $bookingId = DB::table('documents')->insertGetId([
            'document_type_id' => $bookingTypeId, 'branch_id' => $branchId, 'doc_number' => 'STAT-B18-001',
            'doc_date' => now()->toDateString(), 'status' => 'active', 'total_items' => 1,
            'total_amount' => 47.50, 'created_at' => now(), 'updated_at' => now(),
            'source_system' => 'bplus', 'source_key' => 918001,
        ]);
        $debitAccount = DB::table('chart_of_accounts')->insertGetId(['code' => 'STAT-D', 'name_th' => 'เดบิตทดสอบ', 'account_type' => 'asset']);
        $creditAccount = DB::table('chart_of_accounts')->insertGetId(['code' => 'STAT-C', 'name_th' => 'เครดิตทดสอบ', 'account_type' => 'revenue']);
        DB::table('gl_journals')->insert([
            ['document_id' => $bookingId, 'payment_document_id' => null, 'account_id' => $debitAccount, 'debit' => 47.50, 'credit' => 0, 'remark' => 'test', 'entry_date' => now()->toDateString()],
            ['document_id' => $bookingId, 'payment_document_id' => null, 'account_id' => $creditAccount, 'debit' => 0, 'credit' => 47.50, 'remark' => 'test', 'entry_date' => now()->toDateString()],
        ]);

        $this->actingAs($this->settingsAdmin())->get('/system/status')->assertOk()
            ->assertSee('id="status-queue" class="system-status-card status-warning"', false)
            ->assertSee('งานล้มเหลว 1 รายการ')
            ->assertSee('RefreshSummary')
            ->assertSee('RuntimeException: mock queue failure')
            ->assertSee('BOOKING')
            ->assertSee('47.50 บาท')
            ->assertSee('ยอดเดบิตเท่ากับเครดิต');
    }

    public function test_pruner_keeps_latest_and_manifest_referenced_installer_and_removes_old_files(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('');
        $this->populateReleaseFolders($root);
        $pruner = new PosReleasePruner($root);

        $deleted = $pruner->prune();
        $this->assertNotEmpty($deleted);
        $this->assertFileExists($root.'/pos-releases/POPSTAR-POS-1.0.0.exe');
        $this->assertFileExists($root.'/pos-releases/POPSTAR-POS-3.0.0.exe');
        $this->assertFileDoesNotExist($root.'/pos-releases/POPSTAR-POS-2.0.0.exe');
        $this->assertFileExists($root.'/pos-python-releases/JET-POS-3.0.0-setup.exe');
        $this->assertFileDoesNotExist($root.'/pos-python-releases/JET-POS-2.0.0-setup.exe');
        $this->assertFileExists($root.'/jet-pos-python-releases/JET-POS-Python-UAT-3.0.0.exe');
        $this->assertFileDoesNotExist($root.'/jet-pos-python-releases/JET-POS-Python-UAT-2.0.0.exe');
        $this->assertFileDoesNotExist($root.'/pos-python-releases/old.previous.exe');
        $this->assertFileDoesNotExist($root.'/pos-releases/orphan.sig');
        $this->assertFileDoesNotExist($root.'/pos-releases/POPSTAR-POS-2.0.0.exe.sig');
    }

    public function test_pruner_dry_run_reports_candidates_without_deleting_them(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('');
        $this->populateReleaseFolders($root);
        $this->app->instance(PosReleasePruner::class, new PosReleasePruner($root));

        $this->artisan('pos:prune-releases --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain('จบการตรวจแบบ dry-run')
            ->assertSuccessful();

        $this->assertFileExists($root.'/pos-releases/POPSTAR-POS-2.0.0.exe');
        $this->assertFileExists($root.'/pos-python-releases/old.previous.exe');
    }

    private function settingsAdmin(): User
    {
        $user = User::factory()->create([
            'username' => 'system-status-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'STATUS_ADMIN_'.Str::upper(Str::random(6)), 'name' => 'Status admin']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'จัดการตั้งค่า'])->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function populateReleaseFolders(string $root): void
    {
        foreach (['pos-releases', 'pos-python-releases', 'jet-pos-python-releases'] as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                mkdir($root.'/'.$directory, 0777, true);
            }
        }

        foreach (['1.0.0', '2.0.0', '3.0.0'] as $version) {
            file_put_contents($root.'/pos-releases/POPSTAR-POS-'.$version.'.exe', 'installer-'.$version);
            file_put_contents($root.'/pos-python-releases/JET-POS-'.$version.'-setup.exe', 'python-'.$version);
            file_put_contents($root.'/jet-pos-python-releases/JET-POS-Python-UAT-'.$version.'.exe', 'uat-'.$version);
        }
        file_put_contents($root.'/pos-releases/POPSTAR-POS-2.0.0.exe.sig', 'old signature');
        file_put_contents($root.'/pos-releases/orphan.sig', 'orphan signature');
        file_put_contents($root.'/pos-python-releases/old.previous.exe', 'old previous');
        file_put_contents($root.'/pos-releases/latest.json', json_encode([
            'platforms' => ['windows-x86_64' => ['url' => 'https://erp.example.test/download/pos/releases/POPSTAR-POS-1.0.0.exe']],
        ]));
    }
}
