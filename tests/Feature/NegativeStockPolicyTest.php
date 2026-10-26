<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Sales\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * สินค้าคงเหลือ GL 1140-00 ติดลบเพราะ POS ตัดต้นทุนของที่ไม่เคยรับเข้าระบบ
 * (PosImportPostingService เคยบังคับ allowNegative = true เสมอ).
 *
 * ตอนนี้แต่ละสาขาเลือกได้ว่าจะ "ห้ามขายติดลบ" หรือ "ยอมให้ติดลบ" และมี command
 * ไล่ดูรายการที่ติดลบอยู่พร้อมตั้งยอดยกมาให้กลับมาเป็นศูนย์.
 */
class NegativeStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_set_to_block_refuses_to_sell_below_zero(): void
    {
        [$branch, $location, $product] = $this->masters();
        $branch->update(['negative_stock_policy' => 'block']);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 2, 'reserved_qty' => 0,
        ]);

        $this->expectException(RuntimeException::class);

        // แม้ส่ง allow_negative_stock มาและสินค้าตั้ง policy เป็น allow สาขาก็ต้องปิดประตู
        app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'allow_negative_stock' => true,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 50]],
        ]);
    }

    public function test_branch_set_to_allow_still_lets_the_sale_through(): void
    {
        [$branch, $location, $product] = $this->masters();
        $branch->update(['negative_stock_policy' => 'allow']);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 2, 'reserved_qty' => 0,
        ]);

        app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'allow_negative_stock' => true,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 50]],
        ]);

        $this->assertSame(-3.0, (float) StockBalance::where('product_id', $product->id)->value('on_hand_qty'));
    }

    public function test_settings_page_saves_a_policy_per_branch(): void
    {
        [$branch] = $this->masters();
        $other = Branch::create(['code' => 'B002', 'name_th' => 'สาขาสอง', 'is_active' => true]);
        $this->signIn();

        $this->get('/settings/stock-policies')->assertOk()->assertSee('นโยบายสต๊อกติดลบ');

        $this->post('/settings/stock-policies', [
            'policies' => [$branch->id => 'block', $other->id => 'allow'],
        ])->assertRedirect(route('settings.stock-policies'));

        $this->assertSame('block', $branch->fresh()->negative_stock_policy);
        $this->assertSame('allow', $other->fresh()->negative_stock_policy);
        $this->assertFalse($branch->fresh()->allowsNegativeStock());
        $this->assertTrue($other->fresh()->allowsNegativeStock());
    }

    public function test_report_lists_negative_stock_and_dry_run_changes_nothing(): void
    {
        [, $location, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => -4, 'reserved_qty' => 0,
        ]);

        $this->artisan('erp:stock-negative-report', ['--fix-opening' => true, '--dry-run' => true])
            ->expectsOutputToContain('NOVAT-1')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(-4.0, (float) StockBalance::where('product_id', $product->id)->value('on_hand_qty'));
        $this->assertDatabaseCount('stock_lots', 0);
        $this->assertDatabaseCount('opening_balance_runs', 0);
        $this->assertSame(0, DB::table('gl_journals')->where('remark', 'ยอดยกมา stock')->count());
    }

    public function test_fix_opening_brings_the_balance_back_to_zero_and_keeps_lots_consistent(): void
    {
        [, $location, $product] = $this->masters();
        $product->update(['average_cost' => 12.5]);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => -4, 'reserved_qty' => 0,
        ]);

        $this->artisan('erp:stock-negative-report', ['--fix-opening' => true])->assertSuccessful();

        $this->assertSame(0.0, (float) StockBalance::where('product_id', $product->id)->value('on_hand_qty'));

        // ของที่ขายไปแล้วไม่ได้กลับมา: lot ยกมาต้องใช้หมดแล้ว เพื่อให้ lot กับ balance ตรงกัน
        $lot = DB::table('stock_lots')->where('product_id', $product->id)->first();
        $this->assertNotNull($lot);
        $this->assertSame(4.0, (float) $lot->initial_qty);
        $this->assertSame(0.0, (float) $lot->remaining_qty);
        $this->assertSame(12.5, (float) $lot->unit_cost);
        $this->assertSame(
            0.0,
            (float) DB::table('stock_lots')->where('product_id', $product->id)->sum('remaining_qty'),
        );

        // ต้องมี movement ไว้ตามรอยว่าใครตั้งยอดยกมาเท่าไร
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => 'opening',
            'qty' => 4,
        ]);

        // GL 1140-00 ต้องถูกดึงกลับขึ้นมา: Dr สินค้าคงเหลือ / Cr บัญชีพักยกยอด = 4 x 12.5
        $this->assertSame(50.0, $this->accountSum(ChartOfAccount::ROLE_INVENTORY, 'debit'));
        $this->assertSame(50.0, $this->accountSum(ChartOfAccount::ROLE_OPENING_BALANCE, 'credit'));
        $this->assertDatabaseHas('opening_balance_runs', ['kind' => 'stock_negative', 'line_count' => 1]);
    }

    public function test_fix_opening_cannot_run_twice_for_the_same_branch_and_day(): void
    {
        [, $location, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => -1, 'reserved_qty' => 0,
        ]);
        $this->artisan('erp:stock-negative-report', ['--fix-opening' => true, '--as-of' => '2026-10-27'])->assertSuccessful();

        // ติดลบใหม่อีกรอบในวันเดียวกัน -> ต้องปฏิเสธ ไม่ลง GL ซ้ำ
        StockBalance::where('product_id', $product->id)->update(['on_hand_qty' => -2]);
        $this->artisan('erp:stock-negative-report', ['--fix-opening' => true, '--as-of' => '2026-10-27'])
            ->expectsOutputToContain('ยกซ้ำไม่ได้')
            ->assertFailed();

        $this->assertSame(1, DB::table('opening_balance_runs')->count());
    }

    public function test_branch_ownership_follows_the_default_location_when_warehouse_has_no_branch(): void
    {
        // บนฐานจริง warehouses.branch_id เป็น NULL ทั้งหมด เจ้าของคลังรู้ได้จาก
        // branches.default_warehouse_location_id อย่างเดียว
        [$branch, $location, $product] = $this->masters();
        DB::table('warehouses')->update(['branch_id' => null]);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => -3, 'reserved_qty' => 0,
        ]);

        $this->artisan('erp:stock-negative-report', ['--branch' => 'HQ'])
            ->expectsOutputToContain('NOVAT-1')
            ->assertSuccessful();

        $this->signIn();
        $this->get('/settings/stock-policies')->assertOk()->assertSee('1 รายการ');

        $this->artisan('erp:stock-negative-report', ['--branch' => 'HQ', '--fix-opening' => true])->assertSuccessful();
        $this->assertSame(0.0, (float) StockBalance::where('product_id', $product->id)->value('on_hand_qty'));
        $this->assertDatabaseHas('opening_balance_runs', ['branch_id' => $branch->id, 'kind' => 'stock_negative']);
    }

    private function accountSum(string $role, string $side): float
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');

        return (float) DB::table('gl_journals')->where('account_id', $accountId)->sum($side);
    }

    public function test_report_writes_a_csv_that_excel_can_read_in_thai(): void
    {
        [, $location, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => -2, 'reserved_qty' => 0,
        ]);
        $path = storage_path('app/negative-stock-test.csv');

        $this->artisan('erp:stock-negative-report', ['--csv' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents, 'ต้องมี BOM ไม่อย่างนั้น Excel อ่านภาษาไทยเพี้ยน');
        $this->assertStringContainsString('NOVAT-1', $contents);
        $this->assertStringContainsString('สินค้าไม่คิด VAT', $contents);

        @unlink($path);
    }

    public function test_opening_balance_helper_ignores_balances_that_are_not_negative(): void
    {
        [, $location, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 7, 'reserved_qty' => 0,
        ]);

        $lot = app(FifoStockService::class)->openingBalanceForNegative($product->id, $location->id, 10);

        $this->assertNull($lot);
        $this->assertSame(7.0, (float) StockBalance::where('product_id', $product->id)->value('on_hand_qty'));
    }

    /** @return array{0: Branch, 1: WarehouseLocation, 2: Product} */
    private function masters(): array
    {
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-HQ', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'NOVAT-1', 'name_th' => 'สินค้าไม่คิด VAT', 'base_unit_id' => $unit->id,
            'default_price' => 50, 'average_cost' => 10, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        return [$branch->fresh(), $location, $product];
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['username' => 'policy_tester']));
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
