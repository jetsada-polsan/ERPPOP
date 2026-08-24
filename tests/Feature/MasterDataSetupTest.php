<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ศูนย์ตั้งต้นระบบ — Excel เป็นตัวจัดข้อมูล แล้วอัปโหลดเข้า ERP
 *
 * กติกาที่ต้องไม่พังคือ "เพิ่มใหม่เท่านั้น" ของเดิมห้ามถูกแก้ และไฟล์ที่มีข้อผิดพลาด
 * ต้องไม่เขียนอะไรลงฐานแม้แต่แถวเดียว เพราะคนที่อัปโหลดคือคนที่จัดข้อมูลใน Excel
 * ไม่ใช่คนที่จะไปไล่ลบของที่เข้าไปครึ่ง ๆ ได้
 */
class MasterDataSetupTest extends TestCase
{
    use RefreshDatabase;

    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'setup-').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'upload.csv', 'text/csv', null, true);
    }

    private function manager(): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'setup-'.++$sequence, 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'SETUP_'.$sequence, 'name' => 'setup '.$sequence]);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'masterdata.manage'], ['name' => 'จัดการแฟ้มหลัก'])->id);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function upload(string $type, array $rows, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager())
            ->post(route('master-data-setup.preview', $type), ['file' => $this->csv($rows)]);
    }

    private function applyPending(User $user)
    {
        $pending = session('master_data_setup_preview');

        return $this->actingAs($user)
            ->post(route('master-data-setup.apply', $pending['type']), ['token' => $pending['token']]);
    }

    private function fixtures(): void
    {
        ProductCategory::firstOrCreate(['code' => '101'], ['name_th' => 'หมูหมัก']);
        ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        Branch::firstOrCreate(['code' => 'B001'], ['name_th' => 'สาขาหนึ่ง', 'is_active' => true]);
    }

    // ---------- 1 ----------

    public function test_a_new_category_is_added_and_an_existing_one_is_left_alone(): void
    {
        ProductCategory::create(['code' => '101', 'name_th' => 'ชื่อเดิมห้ามเปลี่ยน']);
        $user = $this->manager();

        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['101', 'ชื่อใหม่ที่ไม่ควรถูกเขียนทับ', 'New'],
            ['102', 'เนื้อหมัก', 'Marinated beef'],
        ], $user);
        $this->applyPending($user);

        $this->assertSame('ชื่อเดิมห้ามเปลี่ยน', ProductCategory::where('code', '101')->value('name_th'));
        $this->assertSame('เนื้อหมัก', ProductCategory::where('code', '102')->value('name_th'));
    }

    // ---------- 2 ----------

    public function test_new_products_take_a_running_sku_from_their_category(): void
    {
        $this->fixtures();
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['OLD-1', 'หมูสามชั้น', '101', 'KG', '189', '120', '1', '1', '', ''],
            ['OLD-2', 'หมูบด', '101', 'KG', '147', '95', '1', '1', '', ''],
        ], $user);
        $this->applyPending($user);

        $this->assertSame('101001', Product::where('legacy_sku', 'OLD-1')->value('sku_code'));
        $this->assertSame('101002', Product::where('legacy_sku', 'OLD-2')->value('sku_code'));
        // legacy_sku เป็นแค่ที่อ้างอิงกลับไปของเดิม ไม่ใช่รหัสที่ระบบใช้
        $this->assertSame('OLD-1', Product::where('sku_code', '101001')->value('legacy_sku'));
    }

    // ---------- 3 ----------

    public function test_a_product_whose_legacy_code_is_already_here_is_skipped(): void
    {
        $this->fixtures();
        $unit = ProductUnit::where('code', 'KG')->sole();
        Product::create([
            'sku_code' => '101001', 'legacy_sku' => 'OLD-1', 'name_th' => 'ชื่อเดิมห้ามเปลี่ยน',
            'product_category_id' => ProductCategory::where('code', '101')->value('id'),
            'base_unit_id' => $unit->id, 'default_price' => 100, 'average_cost' => 60,
            'is_vat' => true, 'is_active' => true,
        ]);
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['OLD-1', 'ชื่อใหม่ที่ไม่ควรถูกเขียนทับ', '101', 'KG', '999', '999', '1', '1', '', ''],
        ], $user);
        $this->applyPending($user);

        $this->assertSame(1, Product::where('legacy_sku', 'OLD-1')->count());
        $this->assertSame('ชื่อเดิมห้ามเปลี่ยน', Product::where('legacy_sku', 'OLD-1')->value('name_th'));
        $this->assertEqualsWithDelta(100, (float) Product::where('legacy_sku', 'OLD-1')->value('default_price'), 0.01);
    }

    // ---------- 4 ----------

    public function test_a_duplicate_barcode_never_leaves_a_product_behind(): void
    {
        $this->fixtures();
        $unit = ProductUnit::where('code', 'KG')->sole();
        $existing = Product::create([
            'sku_code' => '101001', 'legacy_sku' => 'HAS-BC', 'name_th' => 'สินค้าเดิม',
            'product_category_id' => ProductCategory::where('code', '101')->value('id'),
            'base_unit_id' => $unit->id, 'default_price' => 10, 'average_cost' => 5,
            'is_vat' => true, 'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $existing->id, 'barcode' => '8850000000003',
            'barcode_type' => BarcodePolicy::EAN13_STANDARD, 'unit_id' => $unit->id,
            'unit_factor' => 1, 'is_active' => true,
        ]);
        $before = Product::count();
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['NEW-1', 'สินค้าใหม่', '101', 'KG', '50', '30', '1', '1', '8850000000003', 'EAN13_STANDARD'],
        ], $user);
        $this->applyPending($user);

        $this->assertSame($before, Product::count(), 'บาร์โค้ดชนของเดิมแล้วต้องไม่มีสินค้าค้างอยู่');
        $this->assertSame(1, ProductBarcode::where('barcode', '8850000000003')->count());
    }

    // ---------- 5 ----------

    public function test_a_file_with_any_error_writes_nothing_at_all(): void
    {
        $this->fixtures();
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['GOOD-1', 'สินค้าที่ถูกต้อง', '101', 'KG', '50', '30', '1', '1', '', ''],
            ['BAD-1', 'หมวดไม่มีจริง', '999', 'KG', '50', '30', '1', '1', '', ''],
        ], $user);
        $response = $this->applyPending($user);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, Product::count(), 'แถวที่ถูกต้องก็ต้องไม่ถูกเขียน ถ้าไฟล์ยังมีแถวผิด');
    }

    // ---------- 6 ----------

    public function test_new_employees_take_the_next_pop_number(): void
    {
        $this->fixtures();
        Employee::create(['employee_code' => 'POP007', 'full_name' => 'พนักงานเดิม', 'status' => 'Active']);
        $user = $this->manager();

        $this->upload('employees', [
            ['source_employee_code', 'full_name', 'nickname', 'phone', 'branch_code', 'department', 'position', 'status'],
            ['HR-1', 'พนักงานใหม่หนึ่ง', 'เอ', '0800000001', 'B001', 'ขาย', 'พนักงานขาย', 'Active'],
            ['HR-2', 'พนักงานใหม่สอง', 'บี', '0800000002', 'B001', 'ขาย', 'พนักงานขาย', 'Active'],
        ], $user);
        $this->applyPending($user);

        $this->assertSame('POP008', Employee::where('source_section', 'excel:HR-1')->value('employee_code'));
        $this->assertSame('POP009', Employee::where('source_section', 'excel:HR-2')->value('employee_code'));
    }

    // ---------- 7 ----------

    public function test_an_employee_already_imported_is_skipped(): void
    {
        $this->fixtures();
        Employee::create([
            'employee_code' => 'POP001', 'full_name' => 'ชื่อเดิมห้ามเปลี่ยน',
            'status' => 'Active', 'source_section' => 'excel:HR-1',
        ]);
        $user = $this->manager();

        $this->upload('employees', [
            ['source_employee_code', 'full_name', 'nickname', 'phone', 'branch_code', 'department', 'position', 'status'],
            ['HR-1', 'ชื่อใหม่ที่ไม่ควรถูกเขียนทับ', '', '', 'B001', '', '', 'Active'],
        ], $user);
        $this->applyPending($user);

        // migration มีพนักงานตั้งต้นอยู่แล้ว จึงนับเฉพาะตัวที่มาจากไฟล์นี้
        $this->assertSame(1, Employee::where('source_section', 'excel:HR-1')->count());
        $this->assertSame('ชื่อเดิมห้ามเปลี่ยน', Employee::where('source_section', 'excel:HR-1')->value('full_name'));
    }

    // ---------- 8 ----------

    public function test_a_preview_token_cannot_be_spent_twice(): void
    {
        $user = $this->manager();
        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['301', 'หมวดใหม่', ''],
        ], $user);
        $pending = session('master_data_setup_preview');

        $this->applyPending($user);
        $second = $this->actingAs($user)->post(route('master-data-setup.apply', 'categories'), ['token' => $pending['token']]);

        $second->assertStatus(422);
        $this->assertSame(1, ProductCategory::where('code', '301')->count(), 'ยิงซ้ำต้องไม่เพิ่มซ้ำ');
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $user = $this->manager();
        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['302', 'หมวดใหม่', ''],
        ], $user);

        $this->actingAs($user)
            ->post(route('master-data-setup.apply', 'categories'), ['token' => 'ปลอม-ไม่ใช่-token'])
            ->assertStatus(422);

        $this->assertSame(0, ProductCategory::where('code', '302')->count());
    }

    // ---------- 9 ----------

    public function test_someone_without_the_permission_cannot_reach_the_page(): void
    {
        $outsider = User::factory()->create(['username' => 'outsider', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'NO_SETUP', 'name' => 'no setup']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'reports.view'], ['name' => 'ดูรายงาน'])->id);
        $outsider->roles()->attach($role->id);

        $this->actingAs($outsider->fresh())->get(route('master-data-setup.index'))->assertForbidden();
    }

    // ---------- ที่แก้จากการรีวิว ----------

    public function test_two_products_cannot_end_up_sharing_a_legacy_code(): void
    {
        $this->fixtures();
        $unit = ProductUnit::where('code', 'KG')->sole();
        $category = ProductCategory::where('code', '101')->value('id');
        Product::create([
            'sku_code' => '101001', 'legacy_sku' => 'OLD-1', 'name_th' => 'สินค้าเดิม',
            'product_category_id' => $category, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 5, 'is_vat' => true, 'is_active' => true,
        ]);

        // โค้ดนำเข้าใช้ legacy_sku เป็นคีย์ตัดสิน ฐานข้อมูลจึงต้องบังคับว่าไม่ซ้ำด้วย
        // ไม่งั้นการนำเข้าสองครั้งที่วิ่งพร้อมกันจะสร้างซ้ำได้ทั้งคู่
        $this->expectException(\Illuminate\Database\QueryException::class);
        Product::create([
            'sku_code' => '101002', 'legacy_sku' => 'OLD-1', 'name_th' => 'สินค้าซ้ำ',
            'product_category_id' => $category, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 5, 'is_vat' => true, 'is_active' => true,
        ]);
    }

    public function test_a_legacy_code_that_looks_like_a_generated_sku_is_still_imported(): void
    {
        $this->fixtures();
        $unit = ProductUnit::where('code', 'KG')->sole();
        Product::create([
            'sku_code' => '101001', 'legacy_sku' => 'SOMETHING-ELSE', 'name_th' => 'สินค้าที่ถือรหัส 101001',
            'product_category_id' => ProductCategory::where('code', '101')->value('id'),
            'base_unit_id' => $unit->id, 'default_price' => 10, 'average_cost' => 5,
            'is_vat' => true, 'is_active' => true,
        ]);
        $user = $this->manager();

        // รหัสเดิมของไฟล์บังเอิญตรงกับ SKU ที่ระบบรันไว้ให้สินค้าคนละตัว
        // สองอย่างนี้เป็นคนละ namespace จะเอามาเทียบกันแล้วข้ามไม่ได้
        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['101001', 'สินค้าใหม่ที่รหัสเดิมบังเอิญซ้ำกับ SKU', '101', 'KG', '50', '30', '1', '1', '', ''],
        ], $user);
        $this->applyPending($user);

        $imported = Product::where('legacy_sku', '101001')->first();
        $this->assertNotNull($imported, 'สินค้าใหม่ต้องถูกนำเข้า ไม่ใช่ถูกข้ามเพราะรหัสไปตรงกับ SKU ของตัวอื่น');
        $this->assertSame('101002', $imported->sku_code);
    }

    public function test_a_stale_preview_is_refused_rather_than_applied_to_a_changed_database(): void
    {
        $user = $this->manager();
        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['501', 'หมวดใหม่', ''],
        ], $user);
        $pending = session('master_data_setup_preview');

        // ทำให้ผลตรวจเก่ากว่าอายุที่ยอมรับ
        $path = "master-data-setup/{$pending['token']}.json";
        $saved = json_decode(\Illuminate\Support\Facades\Storage::get($path), true);
        $saved['created_at'] = now()->subHours(3)->toIso8601String();
        \Illuminate\Support\Facades\Storage::put($path, json_encode($saved, JSON_UNESCAPED_UNICODE));

        $this->applyPending($user)->assertSessionHasErrors('file');

        $this->assertSame(0, ProductCategory::where('code', '501')->count(),
            'ผลตรวจที่เก่าแล้วคือภาพของฐานข้อมูลที่ไม่มีอยู่จริงแล้ว');
    }

    public function test_an_abandoned_preview_file_is_swept_away(): void
    {
        $user = $this->manager();
        \Illuminate\Support\Facades\Storage::put('master-data-setup/old-token.json', '{"type":"employees"}');
        touch(\Illuminate\Support\Facades\Storage::path('master-data-setup/old-token.json'), now()->subDay()->getTimestamp());

        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['502', 'หมวดใหม่', ''],
        ], $user);

        // ไฟล์ผลตรวจมีชื่อและเบอร์โทรพนักงานอยู่ข้างใน ไม่ควรค้างไว้ข้ามวัน
        $this->assertFalse(\Illuminate\Support\Facades\Storage::exists('master-data-setup/old-token.json'));
    }

    public function test_the_rows_that_failed_are_the_ones_shown_back(): void
    {
        $this->fixtures();
        $rows = [['category_code', 'category_name_th', 'category_name_en']];
        for ($index = 1; $index <= 14; $index++) {
            $rows[] = ['6'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'หมวดที่ถูกต้อง '.$index, ''];
        }
        $rows[] = ['', 'แถวที่ผิดอยู่ท้ายไฟล์', ''];

        $this->upload('categories', $rows, $this->manager());
        $pending = session('master_data_setup_preview');

        // เดิมหยิบ 12 แถวแรก แถวผิดที่อยู่ท้ายไฟล์จะไม่ถูกแสดงเลย
        // ผู้ใช้เห็นตัวเลข error แต่ไม่รู้ว่าต้องไปแก้บรรทัดไหน
        $this->assertSame(1, $pending['error']);
        $shown = collect($pending['examples'])->pluck('action');
        $this->assertSame('error', $shown->first(), 'แถวที่ผิดต้องขึ้นก่อน');
    }

    // ---------- CSV ----------

    public function test_a_template_downloads_with_a_bom_so_excel_reads_thai(): void
    {
        $response = $this->actingAs($this->manager())->get(route('master-data-setup.template', 'products'));

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $this->assertStringContainsString('legacy_sku', $response->streamedContent());
    }

    public function test_a_comma_inside_a_thai_name_survives_the_round_trip(): void
    {
        $this->fixtures();
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['OLD-9', 'หมูสามชั้น, สไลซ์บาง', '101', 'KG', '189', '120', '1', '1', '', ''],
        ], $user);
        $this->applyPending($user);

        $this->assertSame('หมูสามชั้น, สไลซ์บาง', Product::where('legacy_sku', 'OLD-9')->value('name_th'));
    }

    public function test_blank_rows_from_excel_are_ignored_not_counted_as_errors(): void
    {
        $this->fixtures();
        $user = $this->manager();

        $this->upload('categories', [
            ['category_code', 'category_name_th', 'category_name_en'],
            ['401', 'หมวดหนึ่ง', ''],
            ['', '', ''],
            ['402', 'หมวดสอง', ''],
        ], $user);
        $this->applyPending($user);

        $this->assertSame(2, ProductCategory::whereIn('code', ['401', '402'])->count());
    }

    public function test_a_category_that_cannot_run_a_sku_is_reported_before_anything_is_written(): void
    {
        $this->fixtures();
        ProductCategory::create(['code' => 'CC', 'name_th' => 'หมวดยกเลิก']);
        $user = $this->manager();

        $this->upload('products', [
            ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            ['OLD-CC', 'สินค้าในหมวดยกเลิก', 'CC', 'KG', '10', '5', '1', '1', '', ''],
        ], $user);
        $this->applyPending($user);

        // หมวด CC เก็บประวัติอย่างเดียว รัน SKU ไม่ได้ — ต้องไม่มีสินค้าค้าง
        $this->assertSame(0, Product::count());
    }
}
