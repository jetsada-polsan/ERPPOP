<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InternalBarcodeGenerator;
use App\Support\BarcodePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** บาร์โค้ดภายใน 13 หลักจาก SKU ใช้ในบริษัทเท่านั้น ไม่ใช่ EAN ของผู้ผลิต */
class InternalBarcodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sku_102399_becomes_0000001023992_and_passes_the_check_digit(): void
    {
        $generator = app(InternalBarcodeGenerator::class);
        $this->assertSame('0000001023992', $generator->fromSku('102399'));
        $this->assertTrue(app(BarcodePolicy::class)->isValidEan13('0000001023992'));

        $barcode = $generator->generate($this->product('102399'));
        $this->assertSame('0000001023992', $barcode->fresh()->barcode);
        $this->assertSame(BarcodePolicy::INTERNAL_13, $barcode->barcode_type);
        $this->assertSame(InternalBarcodeGenerator::FROM_SKU, $barcode->source);
    }

    public function test_pack_unit_uses_sku_plus_zeros_like_the_store_labels(): void
    {
        $generator = app(InternalBarcodeGenerator::class);
        $this->assertSame('1010010000005', $generator->packFromSku('101001', 0), 'ตรงกับป้ายหิ้วของ POPSTAR');

        $product = $this->product('101001');
        $pack = ProductUnit::create(['code' => 'H25', 'name' => 'หิ้ว', 'qty_per_base_unit' => 25]);
        $this->actingAs($this->manager())->post(route('products.barcodes.generate', $product), ['unit_id' => $pack->id])->assertSessionHasNoErrors();
        $this->actingAs($this->manager())->post(route('products.barcodes.generate', $product))->assertSessionHasNoErrors();
        $this->actingAs($this->manager())->post(route('products.barcodes.generate', $product), ['unit_id' => $pack->id])->assertSessionHasErrors('barcode');

        $packBarcode = $product->barcodes()->where('unit_id', $pack->id)->sole();
        $this->assertSame('1010010000005', $packBarcode->barcode);
        $this->assertSame(25.0, (float) $packBarcode->unit_factor);
        $this->assertSame('0000001010015', $product->barcodes()->where('unit_id', $product->base_unit_id)->value('barcode'));
    }

    public function test_new_sku_skips_numbers_whose_barcode_is_already_on_an_old_label(): void
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-GEN'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $category = ProductCategory::create(['code' => '105', 'name_th' => 'หมวดทดสอบ']);
        // ป้ายเก่าของสินค้าที่ย้ายหมวดไปแล้ว ยังฝังเลข 105001 อยู่
        $moved = $this->product('104250');
        ProductBarcode::create(['product_id' => $moved->id, 'barcode' => app(InternalBarcodeGenerator::class)->fromSku('105001'),
            'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true]);

        $sku = app(\App\Services\ProductSkuAllocator::class)->nextForCategory($category->id);

        $this->assertSame('105002', $sku, 'ข้าม 105001 เพราะบาร์โค้ดของเลขนี้อยู่บนป้ายสินค้าอื่น');
    }

    public function test_leading_zeros_of_the_sku_are_kept(): void
    {
        $code = app(InternalBarcodeGenerator::class)->fromSku('000123');
        $this->assertSame('000000000123', substr($code, 0, 12));
        $this->assertSame(13, strlen($code));
    }

    public function test_letters_or_long_sku_use_the_running_sequence_and_skip_taken_numbers(): void
    {
        $generator = app(InternalBarcodeGenerator::class);
        $this->assertNull($generator->fromSku('AB-12'));
        $this->assertNull($generator->fromSku('1234567890123'));

        $taken = $this->product('TAKEN');
        ProductBarcode::create(['product_id' => $taken->id, 'barcode' => '2990000000014', 'barcode_type' => BarcodePolicy::INTERNAL_13,
            'unit_id' => $taken->base_unit_id, 'unit_factor' => 1, 'is_active' => true]);

        $barcode = $generator->generate($this->product('AB-12'));
        $this->assertSame(InternalBarcodeGenerator::SEQUENCE, $barcode->source);
        $this->assertStringStartsWith('299', $barcode->barcode);
        $this->assertNotSame('2990000000014', $barcode->barcode);
        $this->assertTrue(app(BarcodePolicy::class)->isValidEan13($barcode->barcode));
    }

    public function test_a_sku_code_already_used_by_another_product_falls_back_to_the_sequence(): void
    {
        $other = $this->product('OTHER');
        ProductBarcode::create(['product_id' => $other->id, 'barcode' => '0000001023992', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $other->base_unit_id, 'unit_factor' => 1, 'is_active' => true]);

        $barcode = app(InternalBarcodeGenerator::class)->generate($this->product('102399'));
        $this->assertSame(InternalBarcodeGenerator::SEQUENCE, $barcode->source);
        $this->assertSame('0000001023992', ProductBarcode::where('product_id', $other->id)->value('barcode'), 'ของเดิมต้องไม่ถูกแตะ');
    }

    public function test_button_does_not_create_twice_and_keeps_existing_barcodes(): void
    {
        $product = $this->product('102400');
        $old = ProductBarcode::create(['product_id' => $product->id, 'barcode' => '8850000000003', 'barcode_type' => BarcodePolicy::EAN13_STANDARD,
            'unit_id' => $product->base_unit_id, 'unit_factor' => 1, 'is_active' => true]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('products.barcodes.generate', $product))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('products.barcodes.generate', $product))->assertSessionHasErrors('barcode');

        $this->assertSame(2, $product->barcodes()->count(), 'EAN ผู้ผลิตกับบาร์โค้ดภายในเป็นคนละรายการ');
        $this->assertSame('8850000000003', $old->fresh()->barcode);
        $this->assertSame(BarcodePolicy::EAN13_STANDARD, $old->fresh()->barcode_type);
    }

    public function test_new_product_gets_an_internal_barcode_from_its_sku(): void
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-GEN'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $category = ProductCategory::create(['code' => '102', 'name_th' => 'ทดสอบ']);

        $this->actingAs($this->manager())->post(route('products.store'), [
            'name_th' => 'สินค้าใหม่', 'product_category_id' => $category->id, 'base_unit_id' => $unit->id, 'default_price' => 10, 'negative_stock_policy' => 'block',
        ])->assertSessionHasNoErrors();

        $product = Product::where('name_th', 'สินค้าใหม่')->sole();
        $barcode = $product->barcodes()->sole();
        $this->assertSame(app(InternalBarcodeGenerator::class)->fromSku($product->sku_code), $barcode->barcode);
    }

    public function test_duplicate_barcode_is_refused_by_the_database(): void
    {
        $product = $this->product('DUP');
        $row = ['product_id' => $product->id, 'barcode' => '0000001023992', 'barcode_type' => BarcodePolicy::INTERNAL_13,
            'unit_id' => $product->base_unit_id, 'unit_factor' => 1, 'is_active' => true];
        ProductBarcode::create($row);
        $this->expectException(QueryException::class);
        ProductBarcode::create($row);
    }

    public function test_scanning_the_13_digit_internal_code_finds_the_product_with_its_unit(): void
    {
        $branch = Branch::create(['code' => 'BG', 'name_th' => 'สาขา', 'is_active' => true]);
        $product = $this->product('102399');
        app(InternalBarcodeGenerator::class)->generate($product);
        $this->actingAs($this->manager());

        $request = Request::create('/pos/products', 'GET', ['branch_id' => $branch->id, 'q' => '0000001023992', 'exact' => 1, 'lookup' => 'barcode']);
        $this->app->instance('request', $request);
        $found = app(PosController::class)->products($request)->getData(true);

        $this->assertSame($product->id, $found[0]['id']);
        $this->assertSame('0000001023992', $found[0]['matched_barcode']['barcode']);
        $this->assertSame($product->base_unit_id, $found[0]['matched_barcode']['unit_id']);
    }

    public function test_scanner_that_drops_the_leading_zero_still_finds_the_product(): void
    {
        $branch = Branch::create(['code' => 'BZ', 'name_th' => 'สาขา', 'is_active' => true]);
        $product = $this->product('102399');
        app(InternalBarcodeGenerator::class)->generate($product);
        $this->actingAs($this->manager());

        $request = Request::create('/pos/products', 'GET', ['branch_id' => $branch->id, 'q' => '000001023992', 'exact' => 1, 'lookup' => 'barcode']);
        $this->app->instance('request', $request);
        $found = app(PosController::class)->products($request)->getData(true);

        $this->assertSame($product->id, $found[0]['id']);
        $this->assertSame('0000001023992', $found[0]['matched_barcode']['barcode']);
    }

    public function test_generated_barcode_with_sales_can_be_disabled_but_not_deleted(): void
    {
        $user = $this->manager();
        $product = $this->product('102401');
        $barcode = app(InternalBarcodeGenerator::class)->generate($product);
        $branch = Branch::create(['code' => 'BS', 'name_th' => 'สาขา', 'is_active' => true]);
        $terminal = \App\Models\PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POSG', 'name' => 'POS']);
        $receipt = \App\Models\PosReceipt::create(['pos_terminal_id' => $terminal->id, 'receipt_no' => 'RG1', 'receipt_date' => '2026-10-01 10:00:00', 'net_sales' => 10, 'status' => 'completed']);
        DB::table('pos_receipt_items')->insert(['pos_receipt_id' => $receipt->id, 'seq' => 1, 'product_id' => $product->id, 'qty' => 1, 'unit_price' => 10, 'discount_amount' => 0, 'vat_amount' => 0, 'net_amount' => 10]);

        $this->actingAs($user)->delete(route('products.barcodes.destroy', [$product, $barcode]))->assertSessionHasErrors('barcode');
        $this->actingAs($user)->put(route('products.barcodes.update', [$product, $barcode]), [
            'barcode' => $barcode->barcode, 'barcode_type' => BarcodePolicy::INTERNAL_13, 'unit_id' => $product->base_unit_id, 'unit_factor' => 1, 'is_active' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($barcode->fresh()->is_active);
    }

    public function test_product_page_shows_sku_next_to_generated_barcode(): void
    {
        $product = $this->product('102399');
        app(InternalBarcodeGenerator::class)->generate($product);

        $this->actingAs($this->manager())->get(route('products.show', $product))->assertOk()
            ->assertSee('SKU 102399 → 0000001023992')->assertSee('สร้างจาก SKU')->assertSee('Internal EAN-13')
            ->assertSee('ไม่ใช่ EAN จากผู้ผลิต')->assertSee('สร้างบาร์โค้ดภายใน 13 หลัก');
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));

        return $user;
    }

    private function product(string $sku): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-GEN'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create(['sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true]);
    }
}
