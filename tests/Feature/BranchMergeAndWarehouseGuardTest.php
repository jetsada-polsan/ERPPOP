<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * สำนักงานใหญ่ถูกสร้างซ้ำสองใบ ("0001 - สำนักงานใหญ่" และ "HQ - สนญ.") ใบที่เรียง
 * มาก่อนไม่มีคลังเริ่มต้น ทำให้ POS default ไปสาขาที่ขายแล้วตัดสต๊อกไม่ได้ และหน้า
 * ซื้อเด้ง "ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น".
 */
class BranchMergeAndWarehouseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_dry_run_reports_the_move_without_touching_anything(): void
    {
        [$duplicate, $keeper] = $this->duplicateBranches();
        User::factory()->create(['username' => 'at_duplicate', 'branch_id' => $duplicate->id]);

        $this->artisan('erp:merge-branches', ['--from' => '0001', '--into' => 'HQ', '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame($duplicate->id, User::where('username', 'at_duplicate')->value('branch_id'));
        $this->assertTrue($duplicate->fresh()->is_active, 'dry-run ต้องไม่ปิดการใช้งานสาขา');
    }

    public function test_merge_moves_every_branch_reference_and_retires_the_duplicate(): void
    {
        [$duplicate, $keeper] = $this->duplicateBranches();
        User::factory()->create(['username' => 'at_duplicate', 'branch_id' => $duplicate->id]);
        $warehouse = Warehouse::create(['branch_id' => $duplicate->id, 'code' => 'WH-DUP', 'name' => 'คลังซ้ำ']);

        $this->artisan('erp:merge-branches', ['--from' => '0001', '--into' => 'HQ'])
            ->assertSuccessful();

        $this->assertSame($keeper->id, User::where('username', 'at_duplicate')->value('branch_id'));
        $this->assertSame($keeper->id, $warehouse->fresh()->branch_id);

        // ไม่ลบแถวทิ้ง แค่ปิดการใช้งาน เพื่อให้ตามรอยย้อนหลังได้
        $this->assertDatabaseHas('branches', ['id' => $duplicate->id, 'is_active' => false]);
    }

    public function test_merge_refuses_when_document_numbers_would_collide(): void
    {
        [$duplicate, $keeper] = $this->duplicateBranches();
        $type = DocumentType::create(['code' => 'STOCK_TRANSFER', 'name_th' => 'ใบโอนย้ายสต็อก']);
        foreach ([$duplicate, $keeper] as $branch) {
            DB::table('documents')->insert([
                'document_type_id' => $type->id, 'branch_id' => $branch->id,
                'doc_number' => 'DOC-DUP-001', 'doc_date' => now()->toDateString(),
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('erp:merge-branches', ['--from' => '0001', '--into' => 'HQ'])
            ->expectsOutputToContain('DOC-DUP-001')
            ->assertFailed();

        $this->assertTrue($duplicate->fresh()->is_active, 'เมื่อรวมไม่สำเร็จต้องไม่แตะสาขาเดิม');
    }

    public function test_merge_refuses_any_unique_collision_not_just_document_numbers(): void
    {
        [$duplicate, $keeper] = $this->duplicateBranches();
        $product = $this->product();
        // ตั้ง min/max ของสินค้าเดียวกันไว้ทั้งสองสาขา -> unique (branch_id, product_id) จะชน
        foreach ([$duplicate, $keeper] as $branch) {
            DB::table('branch_stock_policies')->insert([
                'branch_id' => $branch->id, 'product_id' => $product->id,
                'minimum_stock' => 5, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('erp:merge-branches', ['--from' => '0001', '--into' => 'HQ', '--dry-run' => true])
            ->expectsOutputToContain('branch_stock_policies')
            ->assertFailed();

        $this->assertSame(1, DB::table('branch_stock_policies')->where('branch_id', $duplicate->id)->count());
    }

    public function test_pos_does_not_default_to_a_branch_without_a_warehouse(): void
    {
        // ให้เหลือเฉพาะสองสาขาของเทส เพื่อไม่ให้สาขาที่ migration สร้างไว้มาบังผล
        Branch::query()->delete();
        [$duplicate, $keeper] = $this->duplicateBranches();
        $this->signIn();
        $this->withoutVite(); // หน้า POS ใช้ asset จาก Vite ซึ่งไม่ได้ build ในชุดทดสอบ

        $response = $this->get('/pos')->assertOk();
        $defaultBranchId = $response->viewData('defaultBranchId');

        // "0001" เรียงมาก่อน "HQ" แต่ไม่มีคลัง จึงต้องไม่ถูกเลือกเป็นค่าเริ่มต้น
        $this->assertNotSame($duplicate->id, $defaultBranchId);
        $this->assertSame($keeper->id, $defaultBranchId);
        $this->assertTrue(Branch::find($defaultBranchId)->hasDefaultWarehouse());
    }

    public function test_saving_against_a_branch_without_a_warehouse_explains_how_to_fix_it(): void
    {
        [$duplicate] = $this->duplicateBranches();
        $product = $this->product();
        DocumentType::firstOrCreate(['code' => 'STOCK_REQUISITION'], ['name_th' => 'ใบเบิกสินค้า']);
        $this->signIn();

        $response = $this->post('/stock-issues', [
            'type' => 'requisition',
            'branch_id' => $duplicate->id,
            'purpose' => 'office',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ]);

        $response->assertRedirect();
        $error = (string) session('error');
        $this->assertStringContainsString('0001', $error);
        $this->assertStringContainsString('ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น', $error);
        $this->assertStringContainsString('คลังสินค้า', $error);
    }

    public function test_branch_pickers_mark_branches_that_cannot_issue_documents(): void
    {
        $this->duplicateBranches();
        $this->signIn();

        foreach (['/stock-issues', '/purchase-orders', '/purchases'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString('(ยังไม่ได้ตั้งคลัง)', $html, "[{$uri}] ไม่ได้บอกว่าสาขาไหนใช้ไม่ได้");
            $this->assertMatchesRegularExpression(
                '/<option value="\d+" disabled>0001[^<]*\(ยังไม่ได้ตั้งคลัง\)/u',
                $html,
                "[{$uri}] ยังเลือกสาขาที่ไม่มีคลังได้",
            );
        }
    }

    /** @return array{0: Branch, 1: Branch} สาขาซ้ำ (ไม่มีคลัง) และสาขาที่เก็บไว้ (มีคลัง) */
    private function duplicateBranches(): array
    {
        $duplicate = Branch::create(['code' => '0001', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $keeper = Branch::create(['code' => 'HQ', 'name_th' => 'สนญ.', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $keeper->id, 'code' => 'WH-HQ', 'name' => 'คลังสำนักงานใหญ่']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $keeper->update(['default_warehouse_location_id' => $location->id]);

        return [$duplicate->fresh(), $keeper->fresh()];
    }

    private function product(): Product
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create([
            'sku_code' => 'SKU-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'average_cost' => 10, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['username' => 'branch_tester']));
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
