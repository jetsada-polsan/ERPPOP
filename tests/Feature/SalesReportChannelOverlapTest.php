<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ทุกบิล POS สร้างเอกสารขายสดผูกไว้เสมอ (PosController::recordPosReceipt รับ documentId
 * แบบ non-null) รายงานที่รวมสองช่องทางเองจึงต้องกันไม่ให้บิลเดียวถูกนับสองรอบ
 * แบบเดียวกับ view `sales_postings`
 */
class SalesReportChannelOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pos_sale_linked_to_its_cash_sale_document_is_counted_once(): void
    {
        [$user, $product] = $this->posSaleWorth(100.0);

        $response = $this->actingAs($user)->get('/reports?category=sales&report=sales_by_category&from=2026-08-01&to=2026-08-31&branch_id=all');
        $response->assertOk();

        $rows = collect($response->viewData('result')['rows']);
        $total = $rows->sum(fn ($row) => (float) $row->amount);

        // ยอดจริงคือ 100 บาทหนึ่งบิล ไม่ใช่ 200 จากการนับซ้ำฝั่ง POS และฝั่งเอกสาร
        $this->assertSame(100.0, round($total, 2), 'ยอดขายตามหมวดสินค้านับบิล POS ซ้ำกับเอกสารขายสดที่ผูกกัน');
        $this->assertSame(1, (int) $rows->sum('bill_count'));
    }

    public function test_the_report_total_matches_the_sales_postings_ledger(): void
    {
        [$user] = $this->posSaleWorth(100.0);

        $ledgerTotal = (float) DB::table('sales_postings')->sum('net_sales');

        $rows = collect($this->actingAs($user)
            ->get('/reports?category=sales&report=sales_by_category&from=2026-08-01&to=2026-08-31&branch_id=all')
            ->viewData('result')['rows']);

        $this->assertSame($ledgerTotal, round($rows->sum(fn ($row) => (float) $row->amount), 2));
    }

    public function test_a_voided_pos_bill_is_not_reported_as_a_sale(): void
    {
        [$user] = $this->posSaleWorth(100.0);

        // ยกเลิกบิลแบบเดียวกับ PosController::voidReceipt — บิลเป็น 'void', เอกสารเป็น 'cancelled'
        PosReceipt::query()->update(['status' => 'void']);
        Document::query()->update(['status' => 'cancelled']);

        $rows = collect($this->actingAs($user)
            ->get('/reports?category=sales&report=sales_by_category&from=2026-08-01&to=2026-08-31&branch_id=all')
            ->viewData('result')['rows']);

        $this->assertSame(0.0, round($rows->sum(fn ($row) => (float) $row->amount), 2));
        $this->assertSame(0.0, (float) DB::table('sales_postings')->sum('net_sales'));
    }

    public function test_a_receipt_imported_from_the_old_system_is_not_reported_as_a_sale(): void
    {
        [$user] = $this->posSaleWorth(100.0);

        // บิลนำเข้าเก่าไม่มีกะและไม่มีเอกสารผูก — บน production มีแบบนี้ 16,537 ใบ
        PosReceipt::query()->update(['is_legacy_import' => true, 'document_id' => null, 'pos_shift_id' => null]);
        Document::query()->update(['status' => 'cancelled']);

        $rows = collect($this->actingAs($user)
            ->get('/reports?category=sales&report=sales_by_category&from=2026-08-01&to=2026-08-31&branch_id=all')
            ->viewData('result')['rows']);

        $this->assertSame(0.0, round($rows->sum(fn ($row) => (float) $row->amount), 2));
        $this->assertSame(0.0, (float) DB::table('sales_postings')->sum('net_sales'),
            'บิลนำเข้าเก่าต้องไม่โผล่ใน sales_postings');
        // แต่ข้อมูลต้องยังอยู่ ไม่ได้ถูกลบ
        $this->assertSame(1, PosReceipt::count());
    }

    /** สร้างบิล POS หนึ่งใบพร้อมเอกสารขายสดที่ผูกกัน เหมือน checkout ของจริง */
    private function posSaleWorth(float $amount): array
    {
        $branch = Branch::create(['code' => 'OVL', 'name_th' => 'สาขาทดสอบซ้ำ', 'is_active' => true]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS-OVL', 'name' => 'POS ทดสอบ']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-OVL', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'L1', 'name' => 'ชั้น 1']);
        $unit = ProductUnit::create(['code' => 'PCS-OVL', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $category = ProductCategory::create(['code' => 'CAT-OVL', 'name_th' => 'หมวดทดสอบ']);
        $product = Product::create([
            'sku_code' => 'SKU-OVL', 'name_th' => 'สินค้าทดสอบ',
            'product_category_id' => $category->id, 'base_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $cashType = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด']);
        $document = Document::create([
            'document_type_id' => $cashType->id, 'branch_id' => $branch->id,
            'doc_number' => 'CS-OVL-0001', 'doc_date' => '2026-08-10', 'status' => 'active',
            'total_items' => 1, 'total_amount' => $amount,
        ]);
        $stockDocumentId = DB::table('stock_documents')->insertGetId([
            'document_id' => $document->id, 'total_qty' => 1, 'total_items' => 1, 'created_at' => now(),
        ]);
        DB::table('stock_document_items')->insert([
            'stock_document_id' => $stockDocumentId, 'seq' => 1, 'product_id' => $product->id,
            'warehouse_location_id' => $location->id, 'qty' => 1, 'unit_id' => $unit->id, 'unit_price' => $amount,
        ]);

        $receipt = PosReceipt::create([
            'pos_terminal_id' => $terminal->id, 'document_id' => $document->id,
            'receipt_no' => 'POS-OVL-0001', 'receipt_date' => '2026-08-10 10:00:00',
            'gross_sales' => $amount, 'net_sales' => $amount, 'status' => 'completed',
        ]);
        DB::table('pos_receipt_items')->insert([
            'pos_receipt_id' => $receipt->id, 'seq' => 1, 'product_id' => $product->id,
            'qty' => 1, 'unit_price' => $amount, 'net_amount' => $amount,
        ]);

        $user = User::factory()->create(['username' => 'report-overlap', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'REPORT_OVL', 'name' => 'Report Viewer']);
        // หมวด 'sales' ต้องใช้ sales.manage ไม่ใช่ reports.view (ดู canSeeReport)
        // และผู้ใช้ที่ไม่ผูกสาขาต้องมี reports.all_branches ไม่งั้นถูกล็อกจนไม่เห็นข้อมูลใด ๆ
        foreach (['reports.view', 'sales.manage', 'reports.all_branches'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return [$user, $product];
    }
}
