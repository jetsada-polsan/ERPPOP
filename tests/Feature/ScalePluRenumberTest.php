<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\ScaleBarcodeService;
use App\Services\Inventory\ScalePluRenumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScalePluRenumberTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'REN', 'name' => 'PLU']);
        foreach (['masterdata.manage', 'pos.sell'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code]));
        }
        $u->roles()->attach($role);
        $this->actingAs($u->fresh());

        return $u;
    }

    private function product(string $sku, string $name = 'ข้าว ชั่ง กก', float $price = 89, bool $active = true): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'RENKG'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);

        return Product::create(['sku_code' => $sku, 'name_th' => $name, 'default_price' => $price, 'base_unit_id' => $unit->id, 'is_active' => $active]);
    }

    private function code(Product $p, string $code, bool $active = true): ProductBarcode
    {
        return ProductBarcode::create(['product_id' => $p->id, 'barcode' => $code, 'barcode_type' => 'SCALE_PLU', 'unit_id' => $p->base_unit_id, 'unit_factor' => 1, 'is_active' => $active]);
    }

    private function preview(array $selected): int
    {
        $response = $this->post('/scale-prices/plans', ['selected' => $selected])->assertSessionHasNoErrors();
        $id = DB::table('scale_plu_plans')->max('id');
        $response->assertRedirect(route('scale-prices.index', ['plan' => $id, 'section' => 'plan']));

        return $id;
    }

    private function apply(int $id, bool $warnings = true)
    {
        $p = app(ScalePluRenumber::class)->plan($id);

        return $this->post("/scale-prices/plans/$id/apply", ['confirmed' => 1, 'accept_warnings' => $warnings, 'revision' => hash('sha256', json_encode($p->rows))]);
    }

    public function test_candidates_include_sku_only_aliases_inactive_unpriced_and_normalized_duplicate_names(): void
    {
        $this->manager();
        $a = $this->product('A', '** ข้าว ชั่ง กก');
        $b = $this->product('B', 'ข้าว (ชั่งน้ำหนัก)', 1);
        $alias = $this->product('800123', 'ชื่อไม่มีคำชั่ง');
        $inactive = $this->product('D', 'ซั่งเก่า', 89, false);
        $barcodeOnly = $this->product('E', 'แป้ง');
        $this->code($barcodeOnly, '801234');
        $this->product('F', 'สินค้าปกติ');
        $rows = app(ScalePluRenumber::class)->candidates()->keyBy(fn ($r) => $r['product']->id);
        $this->assertCount(5, $rows);
        $this->assertContains('ชื่อซ้ำ — เลือกสินค้าเดียว', $rows[$a->id]['warnings']);
        $this->assertContains('ยังไม่ตั้งราคา', $rows[$b->id]['warnings']);
        $this->assertContains('ต้องผูกกับ SKU จริงก่อน', $rows[$alias->id]['warnings']);
        $this->assertFalse($rows[$inactive->id]['product']->is_active);
        $this->get('/scale-prices')->assertOk()->assertSee('ตรวจสินค้าชั่ง')->assertSee('บันทึกทั้งหมด');
    }

    public function test_preview_sorts_category_then_sku_and_changes_nothing_until_apply(): void
    {
        $this->manager();
        $a = $this->product('Z');
        $b = $this->product('B');
        $c = $this->product('A');
        $cat = ProductCategory::create(['code' => 'REN', 'name' => 'ทดสอบ', 'name_th' => 'ทดสอบ']);
        $a->update(['product_category_id' => $cat->id]);
        $id = $this->preview([$a->id => $a->id, $b->id => $b->id, $c->id => $c->id]);
        $rows = app(ScalePluRenumber::class)->plan($id)->rows;
        $this->assertSame(['A', 'B', 'Z'], array_column($rows, 'sku'));
        $this->assertSame(['801001', '801002', '801003'], array_column($rows, 'plu'));
        $this->assertDatabaseCount('product_barcodes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_preview_rejects_inactive_alias_targets_and_two_sources_for_one_sku(): void
    {
        $this->manager();
        $alias = $this->product('800123');
        $target = $this->product('T');
        $inactive = $this->product('I', active: false);
        foreach ([[$alias->id => $alias->id], [$inactive->id => $inactive->id], [$alias->id => $inactive->id], [$alias->id => $target->id, $target->id => $target->id], [$target->id => $alias->id]] as $selected) {
            $this->post('/scale-prices/plans', ['selected' => $selected])->assertSessionHasErrors('plan');
        }
        $this->assertDatabaseCount('scale_plu_plans', 0);
    }

    public function test_inline_duplicates_and_range_fail_and_reorder_skips_locked_numbers(): void
    {
        $this->manager();
        $a = $this->product('A');
        $b = $this->product('B');
        $id = $this->preview([$a->id => $a->id, $b->id => $b->id]);
        $this->post("/scale-prices/plans/$id/edit", ['numbers' => [$a->id => '801005', $b->id => '801005']])->assertSessionHasErrors('plan');
        $this->post("/scale-prices/plans/$id/edit", ['numbers' => [$a->id => '802000']])->assertSessionHasErrors();
        $this->post("/scale-prices/plans/$id/edit", ['numbers' => [$a->id => '801001', $b->id => '801001'], 'locked' => [$b->id => 1], 'reorder' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['801002', '801001'], array_column(app(ScalePluRenumber::class)->plan($id)->rows, 'plu'));
        // Persisted invalid plans are revalidated at apply, independent of edit validation.
        $rows = app(ScalePluRenumber::class)->plan($id)->rows;
        $rows[0]['plu'] = $rows[1]['plu'];
        DB::table('scale_plu_plans')->where('id', $id)->update(['rows' => json_encode($rows)]);
        $this->apply($id)->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('product_barcodes', 0);
    }

    public function test_apply_exports_valid_labels_pos_catalog_and_rollback_restore_barcode_states(): void
    {
        $user = $this->manager();
        $branch = Branch::create(['code' => 'REN', 'name_th' => 'ทดสอบ', 'is_active' => true]);
        $p = $this->product('REAL', 'ข้าวชั่ง', 89);
        $old = $this->code($p, '800999');
        $disabled = $this->code($p, '800998', false);
        $id = $this->preview([$p->id => $p->id]);
        $this->apply($id)->assertSessionHasNoErrors();
        $this->assertFalse($old->fresh()->is_active);
        $this->assertFalse($disabled->fresh()->is_active);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $p->id, 'barcode' => '801001', 'barcode_type' => 'SCALE_PLU', 'is_active' => true]);
        $this->assertDatabaseHas('scale_plu_settings', ['barcode' => '801001', 'scale_price' => 89]);
        $audit = AuditLog::where('action', 'scale_plu_plan_applied')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('800999', $audit->old_values[0]['barcode']);
        $csv = $this->get("/scale-prices/plans/$id/export")->assertOk()->getContent();
        $this->assertStringContainsString('801001,001,01001,ข้าวชั่ง,89.00,8', $csv);
        $barcode = app(ScaleBarcodeService::class)->fromTotalPrice('801001', 89);
        $this->get("/scale-prices/plans/$id/labels")->assertOk()->assertSee($barcode)->assertSee('<svg', false)->assertSee('1.000 กก.');
        $decoded = app(ScaleBarcodeService::class)->decode($barcode);
        $this->assertSame('POPSTAR-801', $decoded['profile']);
        $this->assertSame('801001', $decoded['plu']);
        $this->assertEquals(89, $decoded['price']);
        $this->assertEquals(1, $decoded['price'] / 89);
        $bad = substr($barcode, 0, -1).(((int) substr($barcode, -1) + 1) % 10);
        $this->assertNull(app(ScaleBarcodeService::class)->decode($bad));
        // Authenticate through the actual device API middleware; no production device/token involved.
        $device = PosDevice::create(['name' => 'fixture', 'user_id' => $user->id, 'branch_id' => $branch->id, 'token_hash' => hash('sha256', 'renumber-test-fixture')]);
        $this->withToken('renumber-test-fixture')->getJson('/api/pos/products?all=1')->assertOk()->assertJsonFragment(['scale_plu' => '801001']);
        $this->post("/scale-prices/plans/$id/rollback", ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($old->fresh()->is_active);
        $this->assertFalse($disabled->fresh()->is_active);
        $this->assertDatabaseHas('product_barcodes', ['barcode' => '801001', 'is_active' => false]);
        $this->assertDatabaseMissing('scale_plu_settings', ['barcode' => '801001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'scale_plu_plan_rolled_back']);
        $this->get("/scale-prices/plans/$id/export")->assertStatus(409);
        $this->post("/scale-prices/plans/$id/rollback", ['confirmed' => 1])->assertSessionHasErrors('plan');
    }

    public function test_alias_mapping_retires_placeholder_sku_and_rollback_reactivates_it(): void
    {
        $this->manager();
        $alias = $this->product('800111', price: 1);
        $target = $this->product('REAL', 'ข้าวจริง', 98);
        $old = $this->code($alias, '800111');
        $table = PriceTable::create(['code' => 'REN', 'name' => 'หลัก', 'is_default' => true, 'is_active' => true]);
        ProductPrice::create(['price_table_id' => $table->id, 'product_id' => $target->id, 'price' => 123, 'is_active' => true]);
        $id = $this->preview([$alias->id => $target->id]);
        $this->assertEquals(123, app(ScalePluRenumber::class)->plan($id)->rows[0]['price']);
        $this->apply($id, false)->assertSessionHasErrors('plan');
        $this->apply($id)->assertSessionHasNoErrors();
        $this->assertFalse($alias->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $target->id, 'barcode' => '801001', 'is_active' => true]);
        $this->post("/scale-prices/plans/$id/rollback", ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($alias->fresh()->is_active);
        $this->assertTrue($old->fresh()->is_active);
    }

    public function test_stale_catalog_stale_preview_confirmation_and_apply_replay_are_rejected(): void
    {
        $this->manager();
        $p = $this->product('P');
        $id = $this->preview([$p->id => $p->id]);
        $this->post("/scale-prices/plans/$id/apply", ['revision' => str_repeat('a', 64)])->assertSessionHasErrors('confirmed');
        $this->post("/scale-prices/plans/$id/apply", ['confirmed' => 1, 'revision' => str_repeat('a', 64)])->assertSessionHasErrors('plan');
        $p->update(['default_price' => 90]);
        $this->apply($id)->assertSessionHasErrors('plan');
        $id = $this->preview([$p->id => $p->id]);
        $this->apply($id)->assertSessionHasNoErrors();
        $this->apply($id)->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('product_barcodes', 1);
    }

    public function test_external_collision_is_atomic_and_rollback_never_overwrites_later_changes(): void
    {
        $this->manager();
        $p = $this->product('P');
        $other = $this->product('OTHER', 'ปกติ');
        $this->code($other, '801001');
        $old = $this->code($p, '800123');
        $id = $this->preview([$p->id => $p->id]);
        $this->apply($id)->assertSessionHasErrors('plan');
        $this->assertTrue($old->fresh()->is_active);
        $this->post("/scale-prices/plans/$id/edit", ['numbers' => [$p->id => '801002']])->assertSessionHasNoErrors();
        $this->apply($id)->assertSessionHasNoErrors();
        ProductBarcode::where('barcode', '801002')->update(['price' => 999]);
        $this->post("/scale-prices/plans/$id/rollback", ['confirmed' => 1])->assertSessionHasErrors('plan');
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_reused_numbers_warn_and_rollback_restores_original_owner_and_scale_setting(): void
    {
        $this->manager();
        $a = $this->product('A');
        $b = $this->product('B');
        $old = $this->code($b, '801001');
        DB::table('scale_plu_settings')->insert(['barcode' => '801001', 'scale_price' => 77]);
        $id = $this->preview([$a->id => $a->id, $b->id => $b->id]);
        $this->apply($id, false)->assertSessionHasErrors('plan');
        $this->apply($id)->assertSessionHasNoErrors();
        $this->assertSame($a->id, $old->fresh()->product_id);
        $this->post("/scale-prices/plans/$id/rollback", ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame($b->id, $old->fresh()->product_id);
        $this->assertTrue($old->fresh()->is_active);
        $this->assertDatabaseHas('scale_plu_settings', ['barcode' => '801001', 'scale_price' => 77]);
    }

    public function test_all_plan_routes_require_masterdata_permission(): void
    {
        $p = $this->product('P');
        $this->manager();
        $id = $this->preview([$p->id => $p->id]);
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actingAs($u);
        foreach (['/scale-prices/plans', "/scale-prices/plans/$id/edit", "/scale-prices/plans/$id/apply", "/scale-prices/plans/$id/rollback"] as $url) {
            $this->post($url)->assertForbidden();
        }
        foreach (["/scale-prices/plans/$id/export", "/scale-prices/plans/$id/labels"] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}
