<?php

namespace Tests\Feature;

use App\Console\Commands\ClassifyBarcodes;
use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\BarcodePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SKU คือรหัสอ้างอิงสินค้า บาร์โค้ดคือรหัสที่ยิงขาย ต้องแยกกันและไม่แก้ของเดิมจาก BPlus */
class BarcodeSkuSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_sku_is_refused_by_the_database(): void
    {
        $this->product('SKU-DUP');
        $this->expectException(QueryException::class);
        $this->product('SKU-DUP');
    }

    public function test_duplicate_barcode_across_products_is_refused_with_a_thai_message(): void
    {
        $first = $this->product('A1');
        $second = $this->product('A2');
        $this->barcode($first, '208078', BarcodePolicy::CUSTOM);

        $this->actingAs($this->manager())->post(route('products.barcodes.store', $second), [
            'barcode' => '208078', 'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $second->base_unit_id, 'unit_factor' => 1,
        ])->assertSessionHasErrors(['barcode' => 'บาร์โค้ดนี้มีอยู่แล้วในสินค้าอื่น']);
        $this->assertSame(0, $second->barcodes()->count());
    }

    public function test_six_digit_code_is_internal_and_skips_ean13_rules(): void
    {
        $policy = app(BarcodePolicy::class);
        $this->assertTrue($policy->check(BarcodePolicy::CUSTOM, '208078')['ok']);
        $this->assertSame(BarcodePolicy::CUSTOM, ClassifyBarcodes::classify('208078', '999999', $policy));
        $this->assertSame('internal', BarcodePolicy::GROUPS[BarcodePolicy::CUSTOM]);

        $product = $this->product('B1');
        $this->actingAs($this->manager())->post(route('products.barcodes.store', $product), [
            'barcode' => '208078', 'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $product->base_unit_id, 'unit_factor' => 1,
        ])->assertSessionHasNoErrors();
    }

    public function test_ean13_check_digit_is_enforced(): void
    {
        $policy = app(BarcodePolicy::class);
        $this->assertTrue($policy->check(BarcodePolicy::EAN13_STANDARD, '8850000000003')['ok']);
        $this->assertFalse($policy->check(BarcodePolicy::EAN13_STANDARD, '8850000000000')['ok']);
        $this->assertFalse($policy->check(BarcodePolicy::EAN13_STANDARD, '885000000003')['ok'], '12 หลักไม่ใช่ EAN-13');
    }

    public function test_legacy_barcode_keeps_leading_zeros(): void
    {
        $product = $this->product('C1');
        $this->actingAs($this->manager())->post(route('products.barcodes.store', $product), [
            'barcode' => '0000002080789', 'barcode_type' => BarcodePolicy::LEGACY, 'unit_id' => $product->base_unit_id, 'unit_factor' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('0000002080789', ProductBarcode::where('product_id', $product->id)->value('barcode'));
        $this->assertSame(BarcodePolicy::LEGACY, ClassifyBarcodes::classify('A-0012', 'C1', app(BarcodePolicy::class)));
        // ป้ายภายในของร้านที่ขึ้นต้น 0 ไม่ใช่ EAN ผู้ผลิต แม้ check digit จะถูก
        $this->assertSame(BarcodePolicy::INTERNAL_13, ClassifyBarcodes::classify('0000001022049', '102204', app(BarcodePolicy::class)));
        $this->assertSame(BarcodePolicy::EAN13_STANDARD, ClassifyBarcodes::classify('8850000000003', 'X', app(BarcodePolicy::class)));
        $this->assertSame(BarcodePolicy::INTERNAL_13, ClassifyBarcodes::classify('1030120000009', '103012', app(BarcodePolicy::class)), 'ป้ายหน่วยใหญ่ SKU+000000');
    }

    public function test_scale_plu_needs_a_scale_profile(): void
    {
        $product = $this->product('D1');
        $payload = ['barcode' => '801099', 'barcode_type' => BarcodePolicy::SCALE_PLU, 'unit_id' => $product->base_unit_id, 'unit_factor' => 1];
        $this->actingAs($this->manager())->post(route('products.barcodes.store', $product), $payload)->assertSessionHasErrors('barcode');

        $profileId = DB::table('scale_barcode_profiles')->value('id');
        $this->assertNotNull($profileId, 'migration ต้องมีโปรไฟล์ตั้งต้น');
        $this->actingAs($this->manager())->post(route('products.barcodes.store', $product), $payload + ['scale_barcode_profile_id' => $profileId])
            ->assertSessionHasNoErrors();
    }

    public function test_classify_command_only_relabels_default_rows_and_never_changes_values(): void
    {
        $product = $this->product('E1');
        $short = $this->barcode($product, '208078', BarcodePolicy::CUSTOM);
        $ean = $this->barcode($product, '8850000000003', BarcodePolicy::CUSTOM);
        $odd = $this->barcode($product, '0000002080780X', BarcodePolicy::CUSTOM);
        $set = $this->barcode($product, '8850000000010', BarcodePolicy::INTERNAL_13);

        $this->artisan('erp:classify-barcodes')->assertSuccessful();
        $this->assertSame(BarcodePolicy::CUSTOM, $ean->fresh()->barcode_type, 'ไม่ใส่ --apply ต้องไม่บันทึก');

        $this->artisan('erp:classify-barcodes', ['--apply' => true])->assertSuccessful();
        $this->assertSame(BarcodePolicy::CUSTOM, $short->fresh()->barcode_type);
        $this->assertSame(BarcodePolicy::EAN13_STANDARD, $ean->fresh()->barcode_type);
        $this->assertSame(BarcodePolicy::LEGACY, $odd->fresh()->barcode_type);
        $this->assertSame(BarcodePolicy::INTERNAL_13, $set->fresh()->barcode_type, 'แถวที่ตั้งประเภทไว้แล้วห้ามแตะ');
        $this->assertSame('0000002080780X', $odd->fresh()->barcode);
    }

    public function test_pos_finds_by_sku_and_by_each_barcode_with_its_own_unit_and_factor(): void
    {
        $user = $this->manager();
        $branch = Branch::create(['code' => 'BT1', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $product = $this->product('F1');
        $pack = ProductUnit::create(['code' => 'PK12', 'name' => 'แพ็ค', 'qty_per_base_unit' => 12]);
        $this->barcode($product, '208078', BarcodePolicy::CUSTOM);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '8850000000003', 'barcode_type' => BarcodePolicy::EAN13_STANDARD,
            'unit_id' => $pack->id, 'unit_factor' => 12, 'price' => 110, 'is_active' => true]);
        $this->actingAs($user);

        $find = function (string $q, string $lookup) use ($branch) {
            $request = Request::create('/pos/products', 'GET', ['branch_id' => $branch->id, 'q' => $q, 'exact' => 1, 'lookup' => $lookup]);
            $this->app->instance('request', $request);

            return app(PosController::class)->products($request)->getData(true);
        };

        $this->assertSame($product->id, $find('F1', 'sku')[0]['id']);

        $single = $find('208078', 'barcode')[0];
        $this->assertSame(1.0, (float) $single['matched_barcode']['unit_factor']);

        $case = $find('8850000000003', 'barcode')[0];
        $this->assertSame($pack->id, $case['matched_barcode']['unit_id']);
        $this->assertSame(12.0, (float) $case['matched_barcode']['unit_factor']);
        $this->assertSame(110.0, (float) $case['pos_price']);
    }

    public function test_barcode_with_sales_history_cannot_be_deleted_but_unused_one_can(): void
    {
        $user = $this->manager();
        $sold = $this->product('G1');
        $soldBarcode = $this->barcode($sold, '0000002080789', BarcodePolicy::LEGACY);
        // เคยขายผ่าน POS แล้ว
        $branch = Branch::create(['code' => 'BH', 'name_th' => 'สาขา', 'is_active' => true]);
        $terminal = \App\Models\PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POSH', 'name' => 'POS']);
        $receipt = \App\Models\PosReceipt::create(['pos_terminal_id' => $terminal->id, 'receipt_no' => 'RH1', 'receipt_date' => '2026-10-01 10:00:00', 'net_sales' => 10, 'status' => 'completed']);
        DB::table('pos_receipt_items')->insert(['pos_receipt_id' => $receipt->id, 'seq' => 1, 'product_id' => $sold->id, 'qty' => 1, 'unit_price' => 10, 'discount_amount' => 0, 'vat_amount' => 0, 'net_amount' => 10]);

        $this->actingAs($user)->delete(route('products.barcodes.destroy', [$sold, $soldBarcode]))->assertSessionHasErrors('barcode');
        $this->assertNotNull($soldBarcode->fresh());

        $fresh = $this->product('G2');
        $unused = $this->barcode($fresh, '208079', BarcodePolicy::CUSTOM);
        $this->actingAs($user)->delete(route('products.barcodes.destroy', [$fresh, $unused]))->assertSessionHasNoErrors();
        $this->assertNull($unused->fresh());
    }

    public function test_product_page_shows_sku_and_barcode_table(): void
    {
        $product = $this->product('H1');
        $this->barcode($product, '208078', BarcodePolicy::CUSTOM);
        ProductBarcode::where('product_id', $product->id)->update(['unit_factor' => 1.25]);

        $this->actingAs($this->manager())->get(route('products.show', $product))->assertOk()
            ->assertSee('รหัสสินค้า (SKU)')->assertSee('barcode-badge is-internal', false)->assertSee('1.25')->assertDontSee('1.2500');
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));

        return $user;
    }

    private function product(string $sku): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-SKU'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create(['sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true]);
    }

    private function barcode(Product $product, string $barcode, string $type): ProductBarcode
    {
        return ProductBarcode::create(['product_id' => $product->id, 'barcode' => $barcode, 'barcode_type' => $type,
            'unit_id' => $product->base_unit_id, 'unit_factor' => 1, 'is_active' => true]);
    }
}
