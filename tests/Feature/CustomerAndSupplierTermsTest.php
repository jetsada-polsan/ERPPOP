<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerTerm;
use App\Models\PriceTable;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAndSupplierTermsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private Supplier $supplier;
    private PriceTable $priceTable;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::firstOrCreate(['code' => 'B001'], ['name_th' => 'สำนักงานใหญ่']);

        $role = Role::firstOrCreate(['code' => 'ADMIN'], ['name' => 'ผู้ดูแลระบบ']);
        // Assign necessary permissions
        $perms = \App\Models\Permission::whereIn('code', [
            'sales.manage',
            'purchasing.manage',
        ])->get();
        $role->permissions()->syncWithoutDetaching($perms);

        $this->user = User::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->user->roles()->attach($role);

        $this->priceTable = PriceTable::create([
            'code' => 'PT-WHOLESALE',
            'name' => 'ตารางราคาขายส่ง',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'code' => 'CU-TEST-001',
            'name_th' => 'ลูกค้าร้านอาหารทดสอบ',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'code' => 'SU-TEST-001',
            'name_th' => 'ซัพพลายเออร์เนื้อสดทดสอบ',
            'withholding_tax_form' => 'pnd53',
            'is_active' => true,
        ]);
    }

    public function test_can_create_customer_term_with_all_fields(): void
    {
        $response = $this->actingAs($this->user)->post(route('customers.terms.store', $this->customer), [
            'name' => 'เครดิต 30 วัน',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 30,
            'trade_discount_percent' => 5.5,
            'cash_discount_percent' => 2.0,
            'is_default' => '1',
            'notes' => 'ข้อตกลงสำหรับลูกค้าร้านอาหาร VIP',
        ]);

        $response->assertRedirect(route('customers.show', ['customer' => $this->customer, 'tab' => 'terms']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('customer_terms', [
            'customer_id' => $this->customer->id,
            'name' => 'เครดิต 30 วัน',
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 30,
            'trade_discount_percent' => 5.5,
            'cash_discount_percent' => 2.0,
            'is_default' => 1,
            'notes' => 'ข้อตกลงสำหรับลูกค้าร้านอาหาร VIP',
        ]);
    }

    public function test_customer_term_one_default_rule(): void
    {
        // สร้างข้อตกลงแรกและตั้งเป็นค่าเริ่มต้น
        $term1 = CustomerTerm::create([
            'customer_id' => $this->customer->id,
            'name' => 'เงินสด',
            'credit_days' => 0,
            'is_default' => true,
        ]);
        $this->assertTrue($term1->fresh()->is_default);

        // สร้างข้อตกลงที่สองและตั้งเป็นค่าเริ่มต้นใหม่
        $this->actingAs($this->user)->post(route('customers.terms.store', $this->customer), [
            'name' => 'เครดิต 15 วัน',
            'credit_days' => 15,
            'is_default' => '1',
        ]);

        // ข้อตกลงแรกต้องถูกปลดค่าเริ่มต้นออกอัตโนมัติ (One Default Rule)
        $this->assertFalse($term1->fresh()->is_default);

        $term2 = CustomerTerm::where('customer_id', $this->customer->id)->where('name', 'เครดิต 15 วัน')->first();
        $this->assertNotNull($term2);
        $this->assertTrue($term2->is_default);
        $this->assertEquals($term2->id, $this->customer->defaultTerm()?->id);
    }

    public function test_can_update_customer_term(): void
    {
        $term = CustomerTerm::create([
            'customer_id' => $this->customer->id,
            'name' => 'เงินสดเดิม',
            'credit_days' => 0,
            'trade_discount_percent' => 0,
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->user)->put(route('customers.terms.update', [$this->customer, $term]), [
            'name' => 'N-เงินสด (ไม่คิดภาษี)',
            'credit_days' => 0,
            'trade_discount_percent' => 3.0,
            'cash_discount_percent' => 1.5,
            'is_default' => '1',
            'notes' => 'ปรับปรุงเป็นราคาไม่รวม VAT',
        ]);

        $response->assertRedirect(route('customers.show', ['customer' => $this->customer, 'tab' => 'terms']));
        $this->assertDatabaseHas('customer_terms', [
            'id' => $term->id,
            'name' => 'N-เงินสด (ไม่คิดภาษี)',
            'trade_discount_percent' => 3.0,
            'cash_discount_percent' => 1.5,
            'is_default' => 1,
        ]);

        $this->assertTrue($term->fresh()->isNoVat());
    }

    public function test_can_delete_customer_term(): void
    {
        $term = CustomerTerm::create([
            'customer_id' => $this->customer->id,
            'name' => 'ข้อตกลงชั่วคราว',
            'credit_days' => 7,
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->user)->delete(route('customers.terms.destroy', [$this->customer, $term]));
        $response->assertRedirect(route('customers.show', ['customer' => $this->customer, 'tab' => 'terms']));

        $this->assertDatabaseMissing('customer_terms', ['id' => $term->id]);
    }

    public function test_customer_term_validation_in_thai(): void
    {
        $response = $this->actingAs($this->user)->post(route('customers.terms.store', $this->customer), [
            'name' => '', // ว่างเปล่า
            'trade_discount_percent' => 150, // เกิน 100%
            'cash_discount_percent' => -5, // น้อยกว่า 0
            'credit_days' => -1, // ติดลบ
            'start_date' => '2026-12-31',
            'end_date' => '2026-01-01', // สิ้นสุดก่อนเริ่มต้น
        ]);

        $response->assertSessionHasErrors([
            'name' => 'กรุณากรอกชื่อข้อตกลง',
            'trade_discount_percent' => 'ส่วนลดการค้า (%) ต้องไม่เกิน 100%',
            'cash_discount_percent' => 'ส่วนลดเงินสด (%) ต้องไม่น้อยกว่า 0%',
            'credit_days' => 'จำนวนวันเครดิตต้องไม่น้อยกว่า 0 วัน',
            'end_date' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น',
        ]);
    }

    public function test_can_create_supplier_term_with_all_fields(): void
    {
        $response = $this->actingAs($this->user)->post(route('suppliers.terms.store', $this->supplier), [
            'name' => 'เครดิตเทอม 45 วัน',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 45,
            'trade_discount_percent' => 4.0,
            'cash_discount_percent' => 1.0,
            'is_default' => '1',
            'notes' => 'ข้อตกลงจัดซื้อจากฟาร์มหลัก',
        ]);

        $response->assertRedirect(route('suppliers.show', ['supplier' => $this->supplier, 'tab' => 'terms']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_terms', [
            'supplier_id' => $this->supplier->id,
            'name' => 'เครดิตเทอม 45 วัน',
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 45,
            'trade_discount_percent' => 4.0,
            'cash_discount_percent' => 1.0,
            'is_default' => 1,
            'notes' => 'ข้อตกลงจัดซื้อจากฟาร์มหลัก',
        ]);
    }

    public function test_supplier_term_one_default_rule(): void
    {
        $term1 = SupplierTerm::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'เงินสด',
            'credit_days' => 0,
            'is_default' => true,
        ]);
        $this->assertTrue($term1->fresh()->is_default);

        $this->actingAs($this->user)->post(route('suppliers.terms.store', $this->supplier), [
            'name' => 'เครดิต 30 วัน',
            'credit_days' => 30,
            'is_default' => '1',
        ]);

        $this->assertFalse($term1->fresh()->is_default);
        $term2 = SupplierTerm::where('supplier_id', $this->supplier->id)->where('name', 'เครดิต 30 วัน')->first();
        $this->assertNotNull($term2);
        $this->assertTrue($term2->is_default);
        $this->assertEquals($term2->id, $this->supplier->defaultTerm()?->id);
    }

    public function test_can_update_and_delete_supplier_term(): void
    {
        $term = SupplierTerm::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'N-เงินสดซัพพลายเออร์',
            'credit_days' => 0,
            'is_default' => false,
        ]);

        $this->assertTrue($term->isNoVat());

        $this->actingAs($this->user)->put(route('suppliers.terms.update', [$this->supplier, $term]), [
            'name' => 'เครดิต 60 วัน',
            'credit_days' => 60,
            'trade_discount_percent' => 2.5,
            'is_default' => '0',
        ]);

        $this->assertDatabaseHas('supplier_terms', [
            'id' => $term->id,
            'name' => 'เครดิต 60 วัน',
            'credit_days' => 60,
        ]);

        $this->actingAs($this->user)->delete(route('suppliers.terms.destroy', [$this->supplier, $term]));
        $this->assertDatabaseMissing('supplier_terms', ['id' => $term->id]);
    }

    public function test_supplier_withholding_tax_form(): void
    {
        // ทดสอบอัปเดตแบบภาษีหัก ณ ที่จ่ายผ่าน route เฉพาะ
        $response = $this->actingAs($this->user)->put(route('suppliers.withholding.update', $this->supplier), [
            'withholding_tax_form' => 'pnd3',
        ]);

        $response->assertRedirect(route('suppliers.show', ['supplier' => $this->supplier, 'tab' => 'terms']));
        $this->assertEquals('pnd3', $this->supplier->fresh()->withholding_tax_form);
        $this->assertEquals('ภ.ง.ด.3', $this->supplier->fresh()->withholdingTaxFormLabel());

        // รองรับภาษาไทย ภ.ง.ด.53
        $this->actingAs($this->user)->put(route('suppliers.withholding.update', $this->supplier), [
            'withholding_tax_form' => 'ภ.ง.ด.53',
        ]);
        $this->assertEquals('pnd53', $this->supplier->fresh()->withholding_tax_form);
        $this->assertEquals('ภ.ง.ด.53', $this->supplier->fresh()->withholdingTaxFormLabel());

        // รองรับ none
        $this->actingAs($this->user)->put(route('suppliers.withholding.update', $this->supplier), [
            'withholding_tax_form' => 'none',
        ]);
        $this->assertEquals('none', $this->supplier->fresh()->withholding_tax_form);
        $this->assertEquals('ไม่มี', $this->supplier->fresh()->withholdingTaxFormLabel());

        // ปฏิเสธค่าที่ไม่ถูกต้องพร้อมข้อความภาษาไทย
        $invalidResp = $this->actingAs($this->user)->put(route('suppliers.withholding.update', $this->supplier), [
            'withholding_tax_form' => 'invalid_wht',
        ]);
        $invalidResp->assertSessionHasErrors([
            'withholding_tax_form' => 'แบบภาษีหัก ณ ที่จ่ายต้องเป็น none, ภ.ง.ด.3 หรือ ภ.ง.ด.53',
        ]);
    }

    public function test_customer_show_page_renders_terms_tab(): void
    {
        CustomerTerm::create([
            'customer_id' => $this->customer->id,
            'name' => 'N-เงินสด VIP',
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 0,
            'trade_discount_percent' => 5.0,
            'cash_discount_percent' => 2.0,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)->get(route('customers.show', ['customer' => $this->customer, 'tab' => 'terms']));
        $response->assertOk();
        $response->assertSee('ข้อตกลง (Terms)');
        $response->assertSee('ข้อตกลงทางการค้า (Customer Terms)');
        $response->assertSee('N-เงินสด VIP');
        $response->assertSee('No VAT (N-)');
        $response->assertSee('ตารางราคาขายส่ง');
        $response->assertSee('ค่าเริ่มต้น');
    }

    public function test_supplier_show_page_renders_terms_and_withholding(): void
    {
        SupplierTerm::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'เครดิตเทอม 30 วัน',
            'price_table_id' => $this->priceTable->id,
            'credit_days' => 30,
            'trade_discount_percent' => 3.0,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)->get(route('suppliers.show', ['supplier' => $this->supplier, 'tab' => 'terms']));
        $response->assertOk();
        $response->assertSee('ข้อตกลง (Terms)');
        $response->assertSee('ภาษีหัก ณ ที่จ่าย');
        $response->assertSee('แบบภาษีหัก ณ ที่จ่าย (Withholding Tax Form)');
        $response->assertSee('ข้อตกลงการซื้อสินค้า (Supplier Terms)');
        $response->assertSee('เครดิตเทอม 30 วัน');
        $response->assertSee('30 วัน');
    }
}
