<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** กดปุ่มในหน้าเว็บแล้วโดนปฏิเสธ (422) ต้องกลับหน้าเดิมพร้อมเหตุผลภาษาไทย ไม่ใช่หน้า error ภาษาอังกฤษ */
class FriendlyAbortTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_an_uncounted_stock_count_returns_with_a_thai_reason(): void
    {
        $branch = Branch::create(['code' => 'FA1', 'name_th' => 'สาขา', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WFA', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LFA', 'name' => 'หลัก']);
        $count = StockCount::create([
            'doc_number' => 'SC-FA-1', 'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_date' => now()->toDateString(), 'status' => 'counting',
        ]);
        $page = route('stock-counts.show', $count);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->withHeader('referer', $page)
            ->post(route('stock-counts.submit', $count))
            ->assertRedirect($page)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ยังไม่มีรายการที่นับจริง'));
    }
}
