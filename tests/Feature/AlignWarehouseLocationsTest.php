<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * รหัสพื้นที่เก็บของสาขาต้องตรงกับรหัสสาขา
 *
 * เดิมมีรหัสสองชุดซ้อนกัน และชุดสั้นใช้เลขเดียวกับรหัสสาขาเก่า ทำให้เลขหนึ่งตัว
 * หมายถึงสองที่ ซึ่งเป็นต้นเหตุที่เครื่อง POS ถูกผูกผิดสาขา
 */
class AlignWarehouseLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_branch_ends_up_selling_from_a_location_named_after_it(): void
    {
        $this->fixtures();

        $this->artisan('erp:align-locations', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertSuccessful();

        foreach (['HQ', 'B001', 'B003', 'B005'] as $code) {
            $branch = Branch::where('code', $code)->sole();
            $location = WarehouseLocation::find($branch->default_warehouse_location_id);
            $this->assertSame($code, $location->code, "สาขา {$code} ต้องขายจากพื้นที่รหัสเดียวกัน");
        }
    }

    public function test_a_branch_pointing_at_an_old_system_location_is_moved_to_the_new_one(): void
    {
        $this->fixtures();
        $b001 = Branch::where('code', 'B001')->sole();
        $before = WarehouseLocation::find($b001->default_warehouse_location_id);
        $this->assertSame('001', $before->code, 'เริ่มต้นชี้ไปพื้นที่ของระบบเก่า');

        $this->artisan('erp:align-locations', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertSuccessful();

        $after = WarehouseLocation::find($b001->fresh()->default_warehouse_location_id);
        $this->assertSame('B001', $after->code);
        $this->assertSame('คลังสาขาวาริน', $after->name, 'ต้องย้ายไปพื้นที่ชุดใหม่ ไม่ใช่แค่เปลี่ยนรหัสของเก่า');
    }

    public function test_old_locations_are_kept_but_marked_so_nobody_picks_them(): void
    {
        $this->fixtures();
        DB::table('stock_balances')->insert([
            'product_id' => $this->product(), 'warehouse_location_id' => WarehouseLocation::where('code', '001')->value('id'),
            'on_hand_qty' => 0, 'reserved_qty' => 0,
        ]);

        $this->artisan('erp:align-locations', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertSuccessful();

        // ลบไม่ได้เพราะ stock_balances อ้างอยู่ — เปลี่ยนชื่อให้เห็นชัดว่าเลิกใช้แทน
        $this->assertSame(1, WarehouseLocation::where('code', 'OLD-001')->count());
        $this->assertSame(0, WarehouseLocation::where('code', '001')->count());
        $this->assertSame(1, DB::table('stock_balances')->count(), 'ประวัติสต๊อกต้องไม่หาย');
    }

    public function test_the_unnamed_head_office_area_gets_a_code_and_a_name(): void
    {
        $this->fixtures();

        $this->artisan('erp:align-locations', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertSuccessful();

        $moved = WarehouseLocation::where('code', 'HQ-02')->sole();
        $this->assertStringContainsString('HONA', $moved->name, 'เก็บรหัสเดิมไว้ในชื่อ เพื่อให้สาวกลับได้');
    }

    public function test_it_refuses_when_a_branch_from_the_plan_is_missing(): void
    {
        $this->fixtures();
        Branch::where('code', 'B005')->delete();

        $this->artisan('erp:align-locations', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--force' => true,
        ])->assertFailed();

        // ไม่แตะอะไรเลยเมื่อแผนไม่ครบ
        $this->assertSame(1, WarehouseLocation::where('code', '001')->count());
    }

    private function product(): int
    {
        $unit = \App\Models\ProductUnit::firstOrCreate(['code' => 'EA-LOC'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return \App\Models\Product::create([
            'sku_code' => 'LOC-1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ])->id;
    }

    private function fixtures(): void
    {
        Branch::query()->delete();
        WarehouseLocation::query()->delete();
        $central = Warehouse::firstOrCreate(['code' => 'HO'], ['name' => 'คลังกลาง']);
        $shop = Warehouse::firstOrCreate(['code' => 'SHOP'], ['name' => 'หน้าร้าน']);

        $locations = [
            ['HQ', 'สำนักงานใหญ่ (คลังกลาง)', $central],
            ['HONA', null, $central],
            ['0001', 'คลังสาขาดอนกลาง', $shop],
            ['0002', 'คลังสาขาวาริน', $shop],
            ['0003', 'คลังสาขาปลาดุก', $shop],
            ['0004', 'คลังสาขาเจริญศรี', $shop],
            ['0006', 'คลังสาขาสุรินทร์', $shop],
            ['0007', 'คลังสาขาอำนาจเจริญ', $shop],
            ['001', 'หน้าร้าน วาริน', $shop],
            ['002', 'ห้วยวังนอง', $shop],
            ['009', 'บ้านปลาดุก', $shop],
            ['011', 'ตลาดดอนกลาง', $shop],
            ['012', 'สุรินทร์', $shop],
            ['016', 'ตลาดเจริญศรี', $shop],
        ];
        $byCode = [];
        foreach ($locations as [$code, $name, $warehouse]) {
            $byCode[$code] = WarehouseLocation::updateOrCreate(
                ['code' => $code],
                ['warehouse_id' => $warehouse->id, 'name' => $name],
            )->id;
        }

        // จำลองของจริง: บางสาขาชี้ชุดเก่า บางสาขาชี้ชุดใหม่ ปนกัน
        foreach ([
            ['HQ', 'สำนักงานใหญ่ (คลังกลาง)', 'HQ'],
            ['B001', 'สาขา-หน้าร้าน', '001'],
            ['B002', 'สาขา-ห้วยวังนอง', '002'],
            ['B003', 'สาขา-บ้านปลาดุก', '009'],
            ['B004', 'ตลาดดอนกลาง', '011'],
            ['B005', 'สาขาสุรินทร์', '0006'],
            ['B006', 'สาขาอำนาจเจริญ', '0007'],
            ['B007', 'สาขาตลาดเจริญศรี', '016'],
        ] as [$code, $name, $locationCode]) {
            Branch::create([
                'code' => $code, 'name_th' => $name, 'is_active' => true,
                'default_warehouse_location_id' => $byCode[$locationCode],
            ]);
        }
    }
}
