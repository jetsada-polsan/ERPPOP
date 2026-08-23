<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MasterCutoverRun;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\MasterDataCutoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * เปลี่ยนรหัสสาขาและสินค้าเป็นชุดใหม่ — บาร์โค้ดที่พิมพ์ติดสินค้าไปแล้วต้องยังสแกนได้
 */
class MasterDataCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_renumbered_and_the_old_ones_kept_for_mapping(): void
    {
        $this->branch('HO');
        $branch = $this->branch('LEGACY-7');
        $product = $this->product('OLD-SKU-1');
        $expected = collect($this->service()->planBranches())->firstWhere('id', $branch->id);

        $this->service()->apply();

        $branch->refresh();
        $this->assertMatchesRegularExpression('/^B\d{3}$/', $branch->code);
        $this->assertSame($expected['new'], $branch->code, 'ต้องได้รหัสตรงกับที่แผนบอกไว้');
        $this->assertSame('LEGACY-7', $branch->legacy_branch_code, 'รหัสเดิมต้องเก็บไว้เทียบรายงานเก่า');

        $product->refresh();
        $this->assertSame('P000001', $product->sku_code);
        $this->assertSame('OLD-SKU-1', $product->legacy_sku);
    }

    public function test_barcodes_are_untouched_and_still_scan_to_the_same_product(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $product = $this->product('OLD-2');
        DB::table('product_barcodes')->insert([
            'product_id' => $product->id, 'barcode' => '8850001234567', 'unit_id' => $product->base_unit_id,
            'unit_factor' => 1, 'is_active' => true,
        ]);
        $before = DB::table('product_barcodes')->where('barcode', '8850001234567')->first();

        $this->service()->apply();

        $after = DB::table('product_barcodes')->where('barcode', '8850001234567')->first();
        $this->assertEquals($before, $after, 'แถวบาร์โค้ดต้องไม่ถูกแตะเลย');

        $verified = $this->service()->verifyBarcodes(['8850001234567']);
        $this->assertSame(1, $verified['resolved']);
        $this->assertSame([], $verified['failed']);

        $scanned = DB::table('product_barcodes')
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->where('barcode', '8850001234567')->first(['products.sku_code', 'products.legacy_sku']);
        $this->assertSame('P000001', $scanned->sku_code, 'สแกนแล้วต้องได้สินค้าตัวเดิมที่ถือรหัสใหม่');
        $this->assertSame('OLD-2', $scanned->legacy_sku);
    }

    public function test_the_schema_itself_refuses_a_barcode_on_two_products(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $first = $this->product('A-1');
        $second = $this->product('A-2');

        DB::table('product_barcodes')->insert([
            'product_id' => $first->id, 'barcode' => '8850000000017', 'unit_id' => $first->base_unit_id,
            'unit_factor' => 1, 'is_active' => true,
        ]);

        // ซ้ำข้ามสินค้าเกิดไม่ได้เลย ฐานกันไว้ก่อนจะถึง preflight
        $this->expectException(QueryException::class);
        DB::table('product_barcodes')->insert([
            'product_id' => $second->id, 'barcode' => '8850000000017', 'unit_id' => $second->base_unit_id,
            'unit_factor' => 1, 'is_active' => true,
        ]);
    }

    public function test_a_bad_ean13_check_digit_is_reported_but_never_corrected(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $product = $this->product('EAN-1');
        DB::table('product_barcodes')->insert([
            'product_id' => $product->id, 'barcode' => '8850000000000', 'unit_id' => $product->base_unit_id,
            'unit_factor' => 1, 'is_active' => true,
        ]);

        $service = $this->service();
        $this->assertFalse($service->isValidEan13('8850000000000'));
        $this->assertTrue($service->isValidEan13('8850000000003'));

        $warning = collect($service->preflight())->firstWhere('issue', 'EAN-13 check digit ไม่ถูก');
        $this->assertNotNull($warning);
        $this->assertSame('เตือน', $warning['level'], 'บาร์โค้ดผิดไม่ควรบล็อก cutover');

        $service->apply();

        $this->assertSame('8850000000000', DB::table('product_barcodes')->where('product_id', $product->id)->value('barcode'),
            'ห้ามแก้บาร์โค้ดให้เอง ของที่พิมพ์ติดสินค้าไปแล้วเปลี่ยนตามไม่ได้');
    }

    public function test_nothing_is_written_when_preflight_blocks(): void
    {
        $this->branch('B001');   // รหัสใหม่ถูกใช้ไปแล้ว = ต้องหยุด
        $product = $this->product('B-1');

        try {
            $this->service()->apply();
        } catch (RuntimeException) {
            // คาดไว้แล้ว
        }

        $this->assertSame('B-1', $product->fresh()->sku_code, 'รหัสต้องไม่ขยับ');
        $this->assertSame(0, MasterCutoverRun::count());
    }

    public function test_a_new_code_that_already_exists_is_refused(): void
    {
        $this->branch('B001');

        $problems = collect($this->service()->preflight());
        $this->assertTrue($problems->contains(fn ($row) => $row['issue'] === 'รหัสสาขาใหม่ชนของเดิม'));
    }

    public function test_the_cutover_cannot_be_run_twice(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $this->product('C-1');

        $this->service()->apply();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ทำ cutover');
        $this->service()->apply();
    }

    public function test_deleted_products_still_consume_a_number_so_codes_are_never_reused(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $first = $this->product('D-1');
        $second = $this->product('D-2');
        $first->delete();

        $this->service()->apply();

        // สินค้าที่ถูกลบยังกินเลข P000001 ไป ตัวที่เหลือจึงเป็น P000002 ไม่ใช่ P000001
        $this->assertSame('P000002', $second->fresh()->sku_code);
        $this->assertSame('P000001', Product::withTrashed()->find($first->id)->sku_code);
    }

    public function test_products_without_a_barcode_are_reported_as_a_warning_not_a_blocker(): void
    {
        $this->branch('HO');
        $this->branch('LEGACY-1');
        $this->product('E-1');

        $problems = collect($this->service()->preflight());
        $warning = $problems->firstWhere('issue', 'สินค้าไม่มีบาร์โค้ด');

        $this->assertNotNull($warning);
        $this->assertSame('เตือน', $warning['level'], 'ไม่มีบาร์โค้ดไม่ควรบล็อก cutover');
        $result = $this->service()->apply();
        $this->assertSame(1, $result['products'], 'cutover ต้องเดินต่อได้แม้สินค้าไม่มีบาร์โค้ด');
    }

    public function test_head_office_gets_hq_and_storefronts_start_at_b001(): void
    {
        Branch::query()->delete();
        foreach (['0003', 'HO', '0001', '0002'] as $code) {
            $this->branch($code);
        }

        $plan = collect($this->service()->planBranches());

        $this->assertSame(['0001', '0002', '0003', 'HO'], $plan->pluck('legacy')->all(), 'เรียงตามรหัสเดิม');
        $this->assertSame('HQ', $plan->firstWhere('legacy', 'HO')['new'], 'HO คือสำนักงานใหญ่');
        $this->assertNull($plan->firstWhere('legacy', '0001')['new'], '0001 ซ้ำกับสำนักงานใหญ่ ต้องถูกยุบ');

        // หน้าร้านเริ่ม B001 โดยไม่นับสำนักงานใหญ่เข้าไปในลำดับ
        $this->assertSame(['B001', 'B002'], $plan->whereIn('legacy', ['0002', '0003'])->pluck('new')->all());
    }

    public function test_merging_a_duplicate_head_office_moves_its_users_to_hq(): void
    {
        Branch::query()->delete();
        $hq = $this->branch('HO');
        $duplicate = $this->branch('0001');
        $this->branch('0002');
        $this->product('M-1');

        $user = \App\Models\User::factory()->create(['username' => 'merge-probe', 'branch_id' => $duplicate->id]);

        $this->service()->apply();

        $this->assertSame('HQ', $hq->fresh()->code);
        $this->assertSame($hq->id, $user->fresh()->branch_id, 'ผู้ใช้ต้องย้ายไป HQ ไม่ใช่ค้างอยู่กับสาขาที่ปิดไปแล้ว');

        $duplicate->refresh();
        $this->assertFalse((bool) $duplicate->is_active, 'สาขาซ้ำต้องถูกปิด');
        $this->assertSame('0001', $duplicate->legacy_branch_code);
    }

    public function test_the_central_warehouse_becomes_head_office_property(): void
    {
        Branch::query()->delete();
        $hq = $this->branch('HO');
        $this->branch('0002');
        $this->product('WH-1');

        $warehouse = Warehouse::create(['code' => 'WH-CENTRAL', 'name' => 'คลังกลาง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'HQ-MAIN', 'name' => 'พื้นที่กลาง']);
        $hq->update(['default_warehouse_location_id' => $location->id]);
        $this->assertNull($warehouse->branch_id, 'ก่อน cutover คลังไม่ได้ผูกกับสาขาไหนเลย');

        $this->service()->apply();

        $this->assertSame($hq->id, $warehouse->fresh()->branch_id, 'คลังกลางต้องเป็นของสำนักงานใหญ่');
        $this->assertSame('HQ', $hq->fresh()->code);
    }

    public function test_tills_are_numbered_within_their_branch_and_test_devices_retired(): void
    {
        Branch::query()->delete();
        $hq = $this->branch('HO');
        $shop = $this->branch('0002');
        $this->product('POS-1');

        $first = $this->device('POS-0002-01', 'แคชเชียร์ 1', $shop->id);
        $second = $this->device('POS-0002-02', 'แคชเชียร์ 2', $shop->id);
        $atHq = $this->device('POS-HO-01', 'เครื่องสำนักงาน', $hq->id);
        $test = $this->device('POS-LOCAL-01', 'เครื่องทดสอบ', $shop->id);

        $this->service()->apply();

        // สาขาเดียวกันไล่ลำดับต่อกัน ไม่ใช่เลขวิ่งข้ามสาขา
        $this->assertSame('POS-B001-01', $this->terminalCode($first));
        $this->assertSame('POS-B001-02', $this->terminalCode($second));
        $this->assertSame('POS-HQ-01', $this->terminalCode($atHq));

        $this->assertNotNull(DB::table('pos_devices')->where('id', $test)->value('revoked_at'),
            'เครื่องทดสอบต้องถูกยกเลิก ไม่ให้บิลทดสอบปนกับยอดขายจริง');
        $this->assertSame('POS-LOCAL-01', $this->terminalCode($test), 'เครื่องที่ยกเลิกไม่ต้องรันเลขใหม่');
    }

    public function test_a_till_on_the_merged_branch_is_numbered_under_hq(): void
    {
        Branch::query()->delete();
        $this->branch('HO');
        $duplicate = $this->branch('0001');
        $this->branch('0002');
        $this->product('POS-2');

        $device = $this->device('POS-0001-01', 'เครื่องสำนักงานใหญ่', $duplicate->id);

        $this->service()->apply();

        $this->assertSame('POS-HQ-01', $this->terminalCode($device),
            'สาขาถูกยุบเข้า HQ เครื่องจึงต้องได้รหัสของ HQ ไม่ใช่ค้างอยู่กับสาขาที่ปิดไปแล้ว');
    }

    public function test_the_printer_config_follows_the_till_to_its_new_code(): void
    {
        Branch::query()->delete();
        $this->branch('HO');
        $shop = $this->branch('0002');
        $this->product('POS-3');
        $this->device('POS-0002-01', 'แคชเชียร์', $shop->id);
        DB::table('pos_terminals')->insert(['code' => 'POS-0002-01', 'name' => 'เครื่องพิมพ์หน้าร้าน']);

        $this->service()->apply();

        $this->assertSame(0, DB::table('pos_terminals')->where('code', 'POS-0002-01')->count());
        $this->assertSame(1, DB::table('pos_terminals')->where('code', 'POS-B001-01')->count(),
            'pos_terminals ผูกด้วยสตริง ถ้าไม่เปลี่ยนตาม ตั้งค่าเครื่องพิมพ์จะหลุดจากเครื่อง');
    }

    private function device(string $terminalCode, string $name, int $branchId): int
    {
        static $sequence = 0;
        $user = \App\Models\User::factory()->create(['username' => 'pos-owner-'.++$sequence]);

        return DB::table('pos_devices')->insertGetId([
            'name' => $name, 'terminal_code' => $terminalCode, 'branch_id' => $branchId,
            'user_id' => $user->id, 'token_hash' => hash('sha256', $terminalCode),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function terminalCode(int $deviceId): string
    {
        return (string) DB::table('pos_devices')->where('id', $deviceId)->value('terminal_code');
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['code' => $code, 'name_th' => 'สาขาทดสอบ '.$code, 'is_active' => true]);
    }

    private function service(): MasterDataCutoverService
    {
        return app(MasterDataCutoverService::class);
    }

    private function product(string $sku): Product
    {
        static $sequence = 0;
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-CUT'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create([
            'sku_code' => $sku, 'name_th' => 'สินค้า '.++$sequence, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ]);
    }
}
