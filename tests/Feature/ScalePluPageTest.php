<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\ScalePluPage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScalePluPageTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'PLU', 'name' => 'PLU']);
        $permission = Permission::firstOrCreate(['code' => 'masterdata.manage'], ['name' => 'ข้อมูลหลัก']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    private function product(string $sku, bool $active = true, float $price = 100): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'KG-PLU'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);

        return Product::create(['sku_code' => $sku, 'name_th' => 'สินค้าชั่ง '.$sku, 'base_unit_id' => $unit->id, 'default_price' => $price, 'is_active' => $active]);
    }

    private function barcode(Product $p, string $plu): ProductBarcode
    {
        return ProductBarcode::create(['product_id' => $p->id, 'barcode' => $plu, 'barcode_type' => 'SCALE_PLU', 'unit_id' => $p->base_unit_id, 'unit_factor' => 1, 'is_active' => true]);
    }

    public function test_every_problem_and_clean_row_are_classified_per_barcode(): void
    {
        $bad = $this->product('801021', false, 1);
        $this->barcode($bad, '801021');
        $this->barcode($bad, '801081');
        $good = $this->product('100001');
        $this->barcode($good, '801003');
        $mismatch = $this->product('100002', true, 89);
        $this->barcode($mismatch, '801004');
        $this->product('100003');
        DB::table('scale_plu_settings')->insert([
            ['barcode' => '801003', 'scale_price' => 100], ['barcode' => '801004', 'scale_price' => 100],
        ]);
        $rows = app(ScalePluPage::class)->rows();
        $this->assertCount(5, $rows);
        $this->assertEqualsCanonicalizing(['sku_plu', 'inactive', 'multiple', 'unpriced', 'unchecked'], $rows->firstWhere('plu', '801021')['issues']);
        $this->assertSame([], $rows->firstWhere('plu', '801003')['issues']);
        $this->assertFalse($rows->firstWhere('plu', '801003')['has_problem']);
        $this->assertContains('mismatch', $rows->firstWhere('plu', '801004')['issues']);
        $missing = $rows->firstWhere('plu', null);
        $this->assertSame(['missing'], $missing['issues']);
        $this->assertFalse($missing['has_problem']);
        $this->actingAs($this->manager())->get('/scale-prices')->assertOk()->assertSee('801081')->assertSee('ปิดใช้งาน');
    }

    public function test_duplicate_detection_survives_legacy_database_without_unique_constraint(): void
    {
        // Production may contain legacy duplicates; do not weaken the application schema.
        Schema::table('product_barcodes', fn (Blueprint $t) => $t->dropUnique(['barcode']));
        $this->barcode($this->product('100001'), '801003');
        $this->barcode($this->product('100002'), '801003');
        $rows = app(ScalePluPage::class)->rows();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertContains('duplicate', $row['issues']);
        }
    }

    public function test_move_changes_only_existing_child_and_records_before_after_audit(): void
    {
        $old = $this->product('801003');
        $target = $this->product('100001');
        $barcode = $this->barcode($old, '801003');
        $other = $this->barcode($old, '801004');
        $user = $this->manager();
        $this->actingAs($user)->post('/scale-prices/move', ['barcode_id' => $barcode->id, 'from_product_id' => $old->id, 'product_id' => $target->id, 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('product_barcodes', ['id' => $barcode->id, 'product_id' => $target->id, 'barcode' => '801003', 'barcode_type' => 'SCALE_PLU']);
        $this->assertSame($old->id, $other->fresh()->product_id);
        $this->assertSame('801003', $old->fresh()->sku_code);
        $this->assertSame(2, ProductBarcode::count());
        $audit = AuditLog::where('action', 'scale_plu_moved')->sole();
        $this->assertSame($old->id, $audit->old_values['product_id']);
        $this->assertSame($target->id, $audit->new_values['product_id']);
        $this->assertSame($user->id, $audit->user_id);
    }

    public function test_move_rejects_inactive_plu_sku_unconfirmed_and_stale_requests(): void
    {
        $old = $this->product('100001');
        $barcode = $this->barcode($old, '801003');
        $this->actingAs($this->manager());
        foreach ([$this->product('100002', false), $this->product('801004')] as $target) {
            $this->post('/scale-prices/move', ['barcode_id' => $barcode->id, 'from_product_id' => $old->id, 'product_id' => $target->id, 'confirmed' => 1])->assertSessionHasErrors('product_id');
        }
        $target = $this->product('100003');
        $this->post('/scale-prices/move', ['barcode_id' => $barcode->id, 'from_product_id' => $old->id, 'product_id' => $target->id])->assertSessionHasErrors('confirmed');
        $this->post('/scale-prices/move', ['barcode_id' => $barcode->id, 'from_product_id' => $target->id, 'product_id' => $target->id, 'confirmed' => 1])->assertSessionHasErrors('barcode_id');
        $this->assertSame($old->id, $barcode->fresh()->product_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_every_route_requires_masterdata_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true, 'must_change_password' => false]));
        foreach (['/scale-prices', '/scale-prices/export'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/scale-prices', '/scale-prices/move', '/scale-prices/import/preview', '/scale-prices/import/save', '/scale-prices/test-label'] as $url) {
            $this->post($url)->assertForbidden();
        }
    }

    public function test_prices_and_csv_preview_save_are_persistent_and_mismatch_is_detected(): void
    {
        $p = $this->product('100001');
        $this->barcode($p, '801003');
        $table = PriceTable::create(['code' => 'PLU', 'name' => 'หลัก', 'is_default' => true, 'is_active' => true]);
        $this->actingAs($this->manager())->post('/scale-prices', ['prices' => [$p->id => 89], 'scale_prices' => ['801003' => 100]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_prices', ['product_id' => $p->id, 'price_table_id' => $table->id, 'price' => 89]);
        $this->assertContains('mismatch', app(ScalePluPage::class)->rows()->sole()['issues']);
        $this->post('/scale-prices/import/preview', ['csv' => "PLU,ราคา/กก.\n801003,89.00"])->assertSessionHasNoErrors()->assertSessionHas('scale_import_preview');
        $this->assertDatabaseHas('scale_plu_settings', ['barcode' => '801003', 'scale_price' => 100]);
        $this->get('/scale-prices')->assertOk()->assertSee('ราคาใหม่/กก.');
        $this->post('/scale-prices/import/save', ['confirmed' => 1])->assertSessionHasNoErrors()->assertSessionMissing('scale_import_preview');
        $this->assertSame([], app(ScalePluPage::class)->rows()->sole()['issues']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'scale_price_saved']);
        $this->post('/scale-prices/import/preview', ['file' => UploadedFile::fake()->createWithContent('prices.csv', "\xEF\xBB\xBFPLU,ราคา/กก.\n801003,90.00")])->assertSessionHasNoErrors();
        $this->post('/scale-prices/import/save', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('scale_plu_settings', ['barcode' => '801003', 'scale_price' => 90]);
        $this->get('/scale-prices/export')->assertOk()->assertSee('ราคาไม่ตรง')->assertSee('801003');
    }

    public function test_invalid_csv_is_atomic_and_cannot_be_saved_without_preview(): void
    {
        $this->barcode($this->product('100001'), '801003');
        $this->actingAs($this->manager());
        $this->post('/scale-prices/import/save', ['confirmed' => 1])->assertSessionHasErrors('csv');
        foreach (["801003,100\n999999,10", "801003,100\n801003,200", '801003,-1', '801003,12.345', '801003,=1+1', ''] as $csv) {
            $this->post('/scale-prices/import/preview', ['csv' => $csv])->assertSessionHasErrors('csv');
        }
        $this->assertDatabaseCount('scale_plu_settings', 0);
    }

    public function test_real_labels_use_pos_catalog_and_prices_and_invalid_digit_is_rejected(): void
    {
        $branch = Branch::create(['code' => 'PLU', 'name_th' => 'ทดสอบ', 'is_active' => true]);
        $this->barcode($this->product('100001', true, 100), '801003');
        $this->barcode($this->product('100002', true, 89), '801004');
        $this->actingAs($this->manager());
        foreach ([['8010030059406', '801003', 59.4, 100], ['8010040132007', '801004', 132.0, 89]] as [$label,$plu,$total,$price]) {
            $this->post('/scale-prices/test-label', ['branch_id' => $branch->id, 'label' => $label])->assertSessionHasNoErrors();
            $result = session('scale_label_result');
            $this->assertSame($plu, $result['plu']);
            $this->assertEquals($total, $result['price']);
            $this->assertEqualsWithDelta($total / $price, $result['qty'], 0.00000001);
            $this->assertNull($result['error']);
        }
        $this->post('/scale-prices/test-label', ['branch_id' => $branch->id, 'label' => '8010030059407'])->assertSessionHas('scale_label_result.error');
        $this->assertDatabaseCount('pos_receipts', 0);
    }
}
