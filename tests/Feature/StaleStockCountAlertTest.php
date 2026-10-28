<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ข้อ 18: ใบตรวจนับค้างสถานะกำลังนับเกิน N วันต้องแจ้งเตือน ทั้งใน erp:health และหน้าตรวจนับ
 */
class StaleStockCountAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_warns_and_opens_a_monitor_event_for_a_count_left_open_too_long(): void
    {
        $this->openCount('SCB00120260926001', '2026-09-26 08:00:00');
        $this->openCount('SCB00120261027001', '2026-10-27 08:00:00');
        Carbon::setTestNow('2026-10-28 09:00:00');

        $this->artisan('erp:health')->expectsOutputToContain('SCB00120260926001');

        $this->assertSame('ตรวจนับค้าง', DB::table('monitor_events')->where('status', 'open')->where('severity', 'warning')->value('check_code'));
    }

    public function test_the_stock_count_page_lists_only_stale_counts(): void
    {
        $this->openCount('SCB00120260926001', '2026-09-26 08:00:00');
        $this->openCount('SCB00120261027001', '2026-10-27 08:00:00');
        Carbon::setTestNow('2026-10-28 09:00:00');
        $this->withoutVite();

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('stock-counts.index'))->assertOk()
            ->assertSee('data-testid="stale-counts"', false);
        $response->assertViewHas('staleCounts', fn ($counts) => $counts->pluck('doc_number')->all() === ['SCB00120260926001']);
    }

    private function openCount(string $number, string $openedAt): void
    {
        $branch = Branch::firstOrCreate(['code' => 'B001'], ['name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH-B001'], ['branch_id' => $branch->id, 'name' => 'คลัง']);
        $location = WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'MAIN'], ['name' => 'หลัก']);
        $count = StockCount::create(['doc_number' => $number, 'branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'status' => 'counting']);
        $count->forceFill(['created_at' => $openedAt])->save();
    }
}
