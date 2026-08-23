<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * บาร์โค้ด 4 ประเภท — ของเก่าต้องสแกนได้ต่อไปตามค่าที่เก็บไว้จริง
 */
class BarcodePolicyTest extends TestCase
{
    use RefreshDatabase;

    /** ข้อ 1 ของสเปก: ของเดิมที่ check digit ไม่ถูก ต้องยังสแกนเจอ */
    public function test_an_existing_invalid_thirteen_digit_code_still_scans_to_its_product(): void
    {
        $product = $this->product('LEGACY-BC');
        $barcode = '8850000000000';   // check digit ที่ถูกคือ 3
        $this->assertFalse($this->policy()->isValidEan13($barcode));

        $this->barcode($product, $barcode, BarcodePolicy::INTERNAL_13);

        $scanned = ProductBarcode::where('barcode', $barcode)->first();
        $this->assertNotNull($scanned, 'ของเดิมต้องสแกนเจอตามค่าที่เก็บไว้');
        $this->assertSame($product->id, $scanned->product_id);
        $this->assertSame($barcode, $scanned->barcode, 'ค่าบาร์โค้ดต้องไม่ถูกแก้');

        $checked = $this->policy()->check(BarcodePolicy::INTERNAL_13, $barcode);
        $this->assertTrue($checked['ok'], 'รหัสภายในไม่บังคับ check digit');
        $this->assertNotEmpty($checked['warnings'], 'แต่ต้องเตือนให้รู้');
    }

    /** ข้อ 2: EAN-13 ใหม่ที่ check digit ผิด ต้องถูกปฏิเสธก่อนบันทึก */
    public function test_a_new_standard_ean13_with_a_bad_check_digit_is_refused(): void
    {
        $checked = $this->policy()->check(BarcodePolicy::EAN13_STANDARD, '8850000000000');

        $this->assertFalse($checked['ok']);
        $this->assertStringContainsString('check digit', $checked['errors'][0]);
        $this->assertStringContainsString('3', $checked['errors'][0], 'ต้องบอกด้วยว่าที่ถูกคือเลขอะไร');
    }

    /** ข้อ 3: EAN-13 ที่ถูกต้อง บันทึกได้และสแกนเจอครั้งเดียว */
    public function test_a_valid_standard_ean13_saves_and_scans_exactly_once(): void
    {
        $product = $this->product('EAN-OK');
        $barcode = '8850000000003';

        $this->assertTrue($this->policy()->check(BarcodePolicy::EAN13_STANDARD, $barcode)['ok']);
        $this->barcode($product, $barcode, BarcodePolicy::EAN13_STANDARD);

        $this->assertSame(1, ProductBarcode::where('barcode', $barcode)->count());
    }

    /** ข้อ 4: ฉลากเครื่องชั่งถอดรหัสได้ถูก และสวมเป็นสินค้าอื่นไม่ได้ */
    public function test_a_scale_label_decodes_to_its_own_plu_and_cannot_stand_in_for_another_product(): void
    {
        $service = app(\App\Services\Inventory\ScaleBarcodeService::class);
        $label = $service->fromTotalPrice('800123', 125.50);

        $decoded = $service->decode($label);
        $this->assertSame('800123', $decoded['plu']);
        $this->assertSame(125.5, $decoded['price']);

        // เปลี่ยน PLU แล้ว check digit ไม่ตรง ถอดรหัสไม่ผ่าน สวมสินค้าอื่นไม่ได้
        $tampered = '800124'.substr($label, 6);
        $this->assertNull($service->decode($tampered), 'แก้ PLU แล้วต้องใช้ไม่ได้');

        $this->assertTrue($this->policy()->check(BarcodePolicy::SCALE_WEIGHT, $label)['ok']);
        $this->assertFalse($this->policy()->check(BarcodePolicy::SCALE_WEIGHT, '8850000000003')['ok'],
            'บาร์โค้ดธรรมดาไม่ใช่ฉลากเครื่องชั่ง');
    }

    /** ข้อ 5: บาร์โค้ดซ้ำข้ามสินค้ายังถูกฐานปฏิเสธเหมือนเดิม */
    public function test_the_database_still_refuses_the_same_barcode_on_two_products(): void
    {
        $first = $this->product('DUP-1');
        $second = $this->product('DUP-2');
        $this->barcode($first, '8850000000010', BarcodePolicy::INTERNAL_13);

        $this->expectException(QueryException::class);
        $this->barcode($second, '8850000000010', BarcodePolicy::INTERNAL_13);
    }

    public function test_custom_codes_accept_shapes_that_are_not_ean_at_all(): void
    {
        $checked = $this->policy()->check(BarcodePolicy::CUSTOM, 'ABC-123/XY');

        $this->assertTrue($checked['ok'], 'ของเก่ามีได้หลายรูปแบบ ห้ามบังคับ');
        $this->assertSame([], $checked['errors']);
        $this->assertFalse($this->policy()->check(BarcodePolicy::CUSTOM, '   ')['ok'], 'แต่ว่างเปล่าไม่ได้');
    }

    public function test_existing_rows_were_typed_without_their_values_changing(): void
    {
        $product = $this->product('MIG-1');
        // จำลองของเดิมที่ถูก migration จัดประเภทให้
        DB::table('product_barcodes')->insert([
            'product_id' => $product->id, 'barcode' => '8850000000027', 'unit_id' => $product->base_unit_id,
            'unit_factor' => 1, 'is_active' => true, 'barcode_type' => BarcodePolicy::INTERNAL_13,
        ]);
        DB::table('product_barcodes')->insert([
            'product_id' => $product->id, 'barcode' => 'OLD-CODE-9', 'unit_id' => $product->base_unit_id,
            'unit_factor' => 1, 'is_active' => true, 'barcode_type' => BarcodePolicy::CUSTOM,
        ]);

        $this->assertSame(BarcodePolicy::INTERNAL_13, ProductBarcode::where('barcode', '8850000000027')->value('barcode_type'));
        $this->assertSame(BarcodePolicy::CUSTOM, ProductBarcode::where('barcode', 'OLD-CODE-9')->value('barcode_type'));
        $this->assertSame(0, ProductBarcode::where('barcode_type', BarcodePolicy::EAN13_STANDARD)->count(),
            'ห้ามเดาว่าของเดิมเป็น GS1 เพราะเราไม่รู้ว่าเลขไหนมาจาก GS1 จริง');
    }

    public function test_the_check_digit_calculator_matches_known_values(): void
    {
        $policy = $this->policy();

        $this->assertSame(3, $policy->checkDigit('885000000000'));
        $this->assertSame(7, $policy->checkDigit('590123412345'));
        $this->assertSame(-1, $policy->checkDigit('สั้นไป'), 'ข้อมูลไม่ครบต้องไม่คืนเลขที่ดูเหมือนใช้ได้');
    }

    public function test_the_save_screen_refuses_a_bad_standard_ean13_and_keeps_a_good_one(): void
    {
        $user = $this->staff('barcode-editor');
        $product = $this->product('WEB-1');

        $this->actingAs($user)
            ->post(route('products.barcodes.store', $product), [
                'barcode' => '8850000000000',
                'barcode_type' => BarcodePolicy::EAN13_STANDARD,
                'unit_id' => $product->base_unit_id,
                'unit_factor' => 1,
            ])
            ->assertSessionHasErrors('barcode');

        $this->assertSame(0, ProductBarcode::where('product_id', $product->id)->count(), 'ห้ามบันทึกของที่ไม่ผ่าน');

        $this->actingAs($user)
            ->post(route('products.barcodes.store', $product), [
                'barcode' => '8850000000003',
                'barcode_type' => BarcodePolicy::EAN13_STANDARD,
                'unit_id' => $product->base_unit_id,
                'unit_factor' => 1,
            ])
            ->assertSessionHasNoErrors();

        $saved = ProductBarcode::where('product_id', $product->id)->sole();
        $this->assertSame('8850000000003', $saved->barcode);
        $this->assertSame(BarcodePolicy::EAN13_STANDARD, $saved->barcode_type);
    }

    public function test_an_existing_invalid_code_can_be_saved_again_as_an_internal_code(): void
    {
        $user = $this->staff('barcode-keeper');
        $product = $this->product('WEB-2');
        $existing = $this->barcode($product, '8850000000000', BarcodePolicy::INTERNAL_13);

        $this->actingAs($user)
            ->put(route('products.barcodes.update', [$product, $existing]), [
                'barcode' => '8850000000000',
                'barcode_type' => BarcodePolicy::INTERNAL_13,
                'unit_id' => $product->base_unit_id,
                'unit_factor' => 1,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        // ค่าเดิมต้องอยู่ครบ ระบบห้ามแก้หลักสุดท้ายให้เอง
        $this->assertSame('8850000000000', $existing->fresh()->barcode);
    }

    /** ข้อ 6: บิลที่ sync กลับมาต้องถูกตรวจซ้ำว่าบาร์โค้ดยังเป็นของสินค้าตัวเดิม */
    public function test_a_queued_sale_whose_barcode_now_belongs_to_another_product_is_refused(): void
    {
        $sold = $this->product('SYNC-1');
        $other = $this->product('SYNC-2');
        $this->barcode($other, '8850000000034', BarcodePolicy::INTERNAL_13);

        $controller = new \ReflectionMethod(\App\Http\Controllers\PosController::class, 'barcodeBelongsToAnotherProduct');
        $controller->setAccessible(true);
        $instance = app(\App\Http\Controllers\PosController::class);

        // เครื่องออฟไลน์ถือแคตตาล็อกเก่า ส่งบาร์โค้ดที่ตอนนี้เป็นของสินค้าอื่นมาขาย
        $message = $controller->invoke($instance, [
            ['product_id' => $sold->id, 'barcode' => '8850000000034', 'barcode_type' => BarcodePolicy::INTERNAL_13],
        ]);
        $this->assertNotNull($message, 'ต้องจับได้ ไม่งั้นสต๊อกตัดผิดตัว');
        $this->assertStringContainsString('sync แคตตาล็อกใหม่', $message);

        // ตัวเดิมที่ตรงกันต้องผ่าน
        $this->assertNull($controller->invoke($instance, [
            ['product_id' => $other->id, 'barcode' => '8850000000034', 'barcode_type' => BarcodePolicy::INTERNAL_13],
        ]));

        // ป้ายเครื่องชั่งไม่ได้ลงทะเบียนเป็นบาร์โค้ด ต้องไม่ถูกตรวจข้อนี้
        $this->assertNull($controller->invoke($instance, [
            ['product_id' => $sold->id, 'barcode' => '800123012550', 'barcode_type' => BarcodePolicy::SCALE_WEIGHT],
        ]));
    }

    /** ผู้ใช้ที่มีสิทธิ์แก้แฟ้มสินค้าจริง */
    private function staff(string $username): \App\Models\User
    {
        $user = \App\Models\User::factory()->create([
            'username' => $username,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = \App\Models\Role::create(['code' => 'R_'.strtoupper($username), 'name' => $username]);
        $role->permissions()->sync([
            \App\Models\Permission::firstOrCreate(['code' => 'masterdata.manage'], ['name' => 'จัดการแฟ้มหลัก'])->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function policy(): BarcodePolicy
    {
        return app(BarcodePolicy::class);
    }

    private function product(string $sku): Product
    {
        static $sequence = 0;
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-BC'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create([
            'sku_code' => $sku, 'name_th' => 'สินค้า '.++$sequence, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ]);
    }

    private function barcode(Product $product, string $barcode, string $type): ProductBarcode
    {
        return ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => $barcode, 'barcode_type' => $type,
            'unit_id' => $product->base_unit_id, 'unit_factor' => 1, 'is_active' => true,
        ]);
    }
}
