<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * เจ้าของ 12 พ.ย.: POS แต่ละสาขาขายราคาตามตารางของสาขาตัวเอง (เช่น นาทีทองเจริญศรีใน BPlus 1006)
 * สินค้าที่ตารางสาขาไม่มี -> ราคาเดิม · สาขาที่ไม่ผูกตาราง -> ราคาเดิมทุกบาท
 */
class BranchPriceTableTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Product $other;

    private Branch $shop;

    private Branch $plain;

    private ProductUnit $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->user->roles()->attach(Role::where('code', 'GM')->value('id'));
        $base = ProductUnit::create(['code' => 'EA-BP', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->pack = ProductUnit::create(['code' => 'PK-BP', 'name' => 'แพ็ค', 'qty_per_base_unit' => 10]);
        $this->product = Product::create(['sku_code' => '102093', 'name_th' => 'สินค้ามีราคาสาขา', 'base_unit_id' => $base->id,
            'default_price' => 79, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true]);
        $this->other = Product::create(['sku_code' => '102094', 'name_th' => 'สินค้าไม่มีราคาสาขา', 'base_unit_id' => $base->id,
            'default_price' => 50, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true]);
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => '1020930000009', 'unit_id' => $base->id,
            'unit_factor' => 1, 'price' => 79, 'is_active' => true]);
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => '0000001020930', 'unit_id' => $this->pack->id,
            'unit_factor' => 10, 'price' => 790, 'is_active' => true]);

        $pos = PriceTable::create(['code' => 'POS', 'name' => 'ราคาปลีก POS', 'is_default' => true, 'is_active' => true]);
        $branchTable = PriceTable::create(['code' => 'BR-JRS', 'name' => 'ราคาสาขาเจริญศรี', 'is_default' => false, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->product->id, 'price_table_id' => $branchTable->id, 'unit_id' => null, 'price' => 59, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->product->id, 'price_table_id' => $branchTable->id, 'unit_id' => $this->pack->id, 'price' => 560, 'is_active' => true]);

        $this->shop = Branch::create(['code' => 'JRS', 'name_th' => 'ตลาดเจริญศรี', 'is_active' => true, 'price_table_id' => $branchTable->id]);
        $this->plain = Branch::create(['code' => 'B01', 'name_th' => 'สาขาปกติ', 'is_active' => true, 'price_table_id' => $pos->id]);
    }

    private function posPrice(Branch $branch, string $q, string $lookup): array
    {
        $this->actingAs($this->user);
        $request = Request::create('/pos/products', 'GET', ['branch_id' => $branch->id, 'q' => $q, 'exact' => 1, 'lookup' => $lookup]);
        $this->app->instance('request', $request);
        $row = app(PosController::class)->products($request)->getData(true)[0];

        return [(float) $row['pos_price'], collect($row['barcodes'])->pluck('price', 'barcode')->map(fn ($p) => (float) $p)->all()];
    }

    private function bill(Branch $branch, Product $product, float $price, ?string $barcode = null): float
    {
        $line = ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $price];
        if ($barcode) {
            $line['barcode'] = $barcode;
        }

        return app(PosPricingGuard::class)->validate(['branch_id' => $branch->id, 'vat_mode' => 'included', 'items' => [$line]], $this->user);
    }

    public function test_till_of_a_branch_with_its_own_table_gets_branch_prices_for_sku_and_every_barcode(): void
    {
        [$skuPrice] = $this->posPrice($this->shop, '102093', 'sku');
        [$scanPrice, $barcodes] = $this->posPrice($this->shop, '1020930000009', 'barcode');
        [$packPrice] = $this->posPrice($this->shop, '0000001020930', 'barcode');

        $this->assertSame([59.0, 59.0, 560.0], [$skuPrice, $scanPrice, $packPrice]);
        $this->assertSame(['1020930000009' => 59.0, '0000001020930' => 560.0], $barcodes, 'ราคาบาร์โค้ดที่เครื่องเก็บออฟไลน์ต้องเป็นราคาสาขา');
        $this->assertSame(50.0, $this->posPrice($this->shop, '102094', 'sku')[0], 'สินค้าที่ตารางสาขาไม่มี ใช้ราคาเดิม');
    }

    public function test_branch_without_its_own_table_keeps_todays_prices(): void
    {
        [$skuPrice, $barcodes] = $this->posPrice($this->plain, '102093', 'sku');

        $this->assertSame(79.0, $skuPrice);
        $this->assertSame(['1020930000009' => 79.0, '0000001020930' => 790.0], $barcodes);
    }

    public function test_erp_accepts_branch_price_bills_and_rejects_another_branchs_price(): void
    {
        $this->assertSame(59.0, $this->bill($this->shop, $this->product, 59));
        $this->assertSame(59.0, $this->bill($this->shop, $this->product, 59, '1020930000009'));
        $this->assertSame(560.0, $this->bill($this->shop, $this->product, 560, '0000001020930'));
        $this->assertSame(79.0, $this->bill($this->plain, $this->product, 79, '1020930000009'));

        $this->expectException(RuntimeException::class);
        $this->bill($this->plain, $this->product, 59, '1020930000009');
    }

    public function test_normalize_command_previews_then_merges_duplicate_tables_and_keeps_history(): void
    {
        $named = PriceTable::create(['code' => '8', 'name' => 'ราคาขายสาขาตลาดเจริญศรี', 'is_default' => false, 'is_active' => true]);
        $dup = PriceTable::create(['code' => '1006', 'name' => 'สินค้านาทีทองเจริญศรี', 'is_default' => false, 'is_active' => true]);
        $branch = Branch::create(['code' => 'JR2', 'name_th' => 'เจริญศรี 2', 'is_active' => true, 'price_table_id' => $dup->id]);
        ProductPrice::create(['product_id' => $this->product->id, 'price_table_id' => $dup->id, 'unit_id' => null, 'price' => 59, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->other->id, 'price_table_id' => $dup->id, 'unit_id' => null, 'price' => 45, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->other->id, 'price_table_id' => $named->id, 'unit_id' => null, 'price' => 44, 'is_active' => true]);

        $this->artisan('prices:normalize-tables')->assertSuccessful();
        $this->assertSame($dup->id, (int) $branch->fresh()->price_table_id, 'ไม่ใส่ --apply ต้องไม่เปลี่ยนอะไร');
        $this->assertTrue($dup->fresh()->is_active);

        $this->artisan('prices:normalize-tables', ['--apply' => true])->assertSuccessful();
        $this->assertSame($named->id, (int) $branch->fresh()->price_table_id);
        $this->assertFalse($dup->fresh()->is_active, 'ตารางซ้ำปิดใช้งาน ไม่ลบ');
        $this->assertSame('1006', $named->fresh()->bplus_key);
        $this->assertSame(0, bccomp((string) ProductPrice::where('price_table_id', $named->id)->where('product_id', $this->product->id)->value('price'), '59', 2), 'ราคาที่ตารางหลักยังไม่มี ย้ายมา');
        $this->assertSame(0, bccomp((string) ProductPrice::where('price_table_id', $named->id)->where('product_id', $this->other->id)->value('price'), '44', 2), 'ราคาที่ตารางหลักมีอยู่แล้ว คงของเดิม');
    }

    public function test_price_table_page_lists_every_table_with_what_it_is_used_for(): void
    {
        $this->actingAs($this->user)->get(route('price-tables.index'))
            ->assertOk()
            ->assertSee('POS ทุกสาขา (หลัก)')
            ->assertSee('ราคาสาขาเจริญศรี')
            ->assertSee('สาขา JRS ตลาดเจริญศรี');
    }
}
