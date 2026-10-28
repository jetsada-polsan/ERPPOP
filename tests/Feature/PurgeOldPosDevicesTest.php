<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ล้างเครื่อง/จุดขายทดสอบเก่า เก็บเครื่องจริงที่ระบุ และปิดเล่ม POS ของสาขาที่ไม่มีแล้ว
 */
class PurgeOldPosDevicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_the_named_device_and_removes_old_ones_with_their_logs(): void
    {
        File::ensureDirectoryExists(storage_path('app/backups'));
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));

        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'pp_user']);
        $make = fn ($code) => PosDevice::create(['name' => $code, 'user_id' => $user->id, 'branch_id' => $branch->id, 'terminal_code' => $code, 'token_hash' => hash('sha256', $code)]);
        $real = $make('POS-B001-01');
        $old = $make('POS-UAT-OLD');
        DB::table('pos_api_idempotency')->insert(['idempotency_key' => 'k1', 'pos_device_id' => $old->id, 'endpoint' => 'checkout', 'status_code' => 200, 'response_body' => '{}']);
        PosTerminal::create(['branch_id' => $branch->id, 'code' => 'WEB-OLD', 'name' => 'จุดขายเก่า']);
        $type = DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ขายสด', 'stock_effect' => 'out']);
        $gone = DocumentBook::create(['code' => 'PS99', 'name' => 'ขายสด POS สาขาที่ปิดไปแล้ว', 'document_type_id' => $type->id, 'prefix' => 'PS', 'is_default' => false, 'is_active' => true]);
        $mine = DocumentBook::create(['code' => 'PS98', 'name' => 'ขายสด POS สาขาอำนาจเจริญ (0007)', 'document_type_id' => $type->id, 'prefix' => 'PS', 'is_default' => false, 'is_active' => true]);

        $this->artisan('erp:purge-old-pos-devices', ['--keep' => ['POS-B001-01'], '--force' => true])->assertSuccessful();

        $this->assertSame([$real->id], PosDevice::pluck('id')->all());
        $this->assertSame(0, DB::table('pos_api_idempotency')->count());
        $this->assertFalse(PosTerminal::where('code', 'WEB-OLD')->exists());
        $this->assertFalse((bool) $gone->fresh()->is_active);
        $this->assertTrue((bool) $mine->fresh()->is_active);
        File::cleanDirectory(storage_path('app/backups'));
    }
}
