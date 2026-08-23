<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ช่องว่างเชิงโครงสร้างที่ยังปิดไม่ได้ — เขียนเป็นเทสต์เพื่อให้เป็นรายการตรวจที่มีชีวิต
 *
 * แต่ละเทสต์ยืนยัน "ข้อเท็จจริงว่าช่องว่างนั้นยังอยู่" แล้วจึง markTestIncomplete
 * พร้อมบอกว่าต้องมีอะไรถึงจะปิดได้
 *
 * **เมื่อใครปิดช่องว่างข้อไหนได้ เทสต์ข้อนั้นจะ "แดง" ทันที** เพราะข้อเท็จจริงเปลี่ยนไป
 * นั่นคือสัญญาณให้มาเขียนเทสต์จริงแทนที่หมายเหตุนี้ ไม่ใช่บั๊ก
 *
 * ที่มา: การตรวจความพร้อม ERP 2026-08-23 (ข้อ 1-6 และ 8)
 */
class ErpStructuralGapsTest extends TestCase
{
    use RefreshDatabase;

    /** ช่องว่างข้อ 1 — เอกสารขายไม่มีวงจรสถานะ */
    public function test_gap_sales_documents_have_no_draft_review_approve_lifecycle(): void
    {
        [$branch, $product] = $this->stockedBranch('GAP1');

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);

        // สร้างมาเป็น active ทันที ข้ามร่าง/รอตรวจ/อนุมัติทั้งหมด
        $this->assertSame('active', $document->status);
        $this->assertFalse(Schema::hasColumn('documents', 'approved_by'));
        $this->assertFalse(Schema::hasColumn('documents', 'submitted_at'));

        $this->markTestIncomplete(
            'เอกสารขายยังไม่มีวงจร ร่าง -> รอตรวจ -> อนุมัติ -> ยืนยัน -> ยกเลิก '.
            'ตามหลักข้อ 1 ของ BplusBack ต้องมี: คอลัมน์สถานะที่มากกว่า active/cancelled, '.
            'ผู้ส่งตรวจ/ผู้อนุมัติ/เวลา, กติกาว่าสถานะไหนตัดสต๊อกและลง GL ได้, '.
            'และห้ามแก้ข้อมูลสำคัญหลังยืนยัน'
        );
    }

    /** ช่องว่างข้อ 2 — แก้คำกล่าวอ้างเดิม: ใบขอซื้อและใบสอบราคามีแล้ว เหลือใบส่งของ */
    public function test_gap_only_the_delivery_note_document_is_still_missing(): void
    {
        // ใบขอซื้อไม่ได้หายไปไหน — เป็นสถานะหนึ่งของ purchase_orders ไม่ใช่ DocumentType แยก
        // การสรุปก่อนหน้าว่า "ไม่มีใบขอซื้อ" ผิด เพราะไปดูแต่ document_types
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'requested_by'));
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'approved_by'));

        // ใบสอบราคาเพิ่มแล้วในรอบนี้
        $this->assertTrue(Schema::hasTable('purchase_quotes'));
        $this->assertTrue(Schema::hasTable('purchase_quote_items'));

        // ที่ยังขาดจริงคือเอกสารติดตามการส่งของ
        $this->assertNotContains('DELIVERY_NOTE', DocumentType::pluck('code')->all());

        $this->markTestIncomplete(
            '"ใบส่งของ" ที่มีอยู่เป็นแค่หน้าพิมพ์ของเอกสารขาย (DeliveryNoteController::show) '.
            'ไม่ใช่เอกสารติดตามการส่ง จึงตอบไม่ได้ว่าส่งครบหรือยังและค้างส่งเท่าไรในระดับเอกสารขาย '.
            '(ใบจองตอบได้แล้วผ่าน sale_bookings.delivery_status แต่ใบขายตรงยังตอบไม่ได้)'
        );
    }

    /** ช่องว่างข้อ 3 — ไม่มี approval engine กลาง */
    public function test_gap_approval_requests_are_not_wired_into_any_business_flow(): void
    {
        $users = [];
        foreach ($this->applicationFiles() as $file) {
            if (str_contains(file_get_contents($file), 'ApprovalRequest')) {
                $users[] = str_replace(base_path().'/', '', $file);
            }
        }
        $users = array_values(array_filter($users, fn ($f) => ! str_contains($f, 'app/Models/ApprovalRequest.php')));

        // ตอนนี้มีที่เดียวคือหน้าเรียกดูของ BPlus ไม่ได้ผูกกับ flow ธุรกิจไหนเลย
        $this->assertSame(['app/Http/Controllers/BplusOperationController.php'], $users);

        $this->markTestIncomplete(
            'ApprovalRequest มีตารางและ model แต่ไม่มี flow ธุรกิจไหนใช้ '.
            'การอนุมัติกระจายทำเองรายโมดูล (StockAdjustmentService::approve, '.
            'StockTransferService::approve, StockIssueService::approveDamage) '.
            'ยังไม่มี approval matrix ตามสาขา/ประเภท/วงเงิน และไม่มีกติกาห้ามผู้สร้างอนุมัติเอง'
        );
    }

    /** ช่องว่างข้อ 4 — ปิดไปเกือบหมดแล้ว เหลือแปรรูป/ผลิต/ค่าเสื่อม */
    public function test_gap_only_some_inventory_movements_reach_the_ledger(): void
    {
        $posts = fn (string $file) => str_contains(file_get_contents(base_path($file)), 'GlPostingService');

        // ปิดแล้ว — เทสต์จริงอยู่ที่ InventoryLedgerPostingTest และ BookingDeliveryAndCashTransferTest
        $this->assertTrue($posts('app/Services/Inventory/StockAdjustmentService.php'));
        $this->assertTrue($posts('app/Services/Inventory/StockIssueService.php'));
        $this->assertTrue($posts('app/Services/Accounting/CashTransferService.php'));

        // ยังไม่ปิด
        $this->assertFalse($posts('app/Services/Inventory/StockTransformService.php'));
        $this->assertFalse($posts('app/Services/Inventory/ProductionReceiptService.php'));
        $this->assertFalse($posts('app/Services/Accounting/DepreciationService.php'));

        $this->markTestIncomplete(
            'ปรับสต๊อก ตรวจนับ ตัดชำรุด และฝาก/ถอนเงินสด ลง GL แล้ว '.
            'ที่ยังไม่ลง: แปรรูปสินค้า รับผลิต และค่าเสื่อมราคา '.
            'ค่าเสื่อมยังต้องมีบัญชีค่าเสื่อมสะสมก่อน ซึ่งผังบัญชียังไม่มี'
        );
    }

    /** ช่องว่างข้อ 5 — ไม่มีมิติแผนก/โครงการ ที่รายงานเดิมใช้แทบทุก flow */
    public function test_gap_no_department_or_project_dimension_on_documents(): void
    {
        $columns = Schema::getColumnListing('documents');

        foreach (['department_id', 'project_id', 'cost_center_id'] as $column) {
            $this->assertNotContains($column, $columns, "มี {$column} แล้ว - ให้เปลี่ยนเทสต์นี้เป็นเทสต์จริง");
        }
        $this->assertFalse(Schema::hasTable('departments'));
        $this->assertFalse(Schema::hasTable('projects'));

        $this->markTestIncomplete(
            'รายงานเดิมอ้าง DEPTTAB (แผนก) และ PRJTAB/MKTPLAN (โครงการ) แทบทุก flow '.
            'แปลว่าบริษัทเคยแบ่งยอดตามสองมิตินี้จริง แต่ ERP ใหม่ไม่มีเลย '.
            'ถ้าจะทำรายงานค่าใช้จ่ายหลายมิติหรือกำไรตามโครงการ ต้องเพิ่มมิติก่อน ไม่ใช่เติมทีหลัง '.
            'ดู docs/ai/legacy-popstar-2021-report-mapping.md'
        );
    }

    /** ช่องว่างข้อ 6 — ไม่มีมัดจำ ไม่มีหลายสกุลเงิน */
    public function test_gap_no_deposit_register_and_no_multi_currency(): void
    {
        foreach (['deposits', 'currencies', 'exchange_rates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "มีตาราง {$table} แล้ว - ให้เปลี่ยนเทสต์นี้เป็นเทสต์จริง");
        }
        $this->assertFalse(Schema::hasColumn('documents', 'currency_code'));

        $this->markTestIncomplete(
            'ยังไม่มีทะเบียนมัดจำ (รับ/จ่าย/คืน/ตัดกับใบขายหรือใบซื้อ) '.
            'และยังไม่มีหลายสกุลเงิน (currency master, อัตราแลกเปลี่ยนรายวัน, '.
            'กำไรขาดทุนอัตราแลกเปลี่ยนทั้ง realized และ unrealized)'
        );
    }

    /** ช่องว่างข้อ 8 — invariant ที่ข้อมูลจริงบน production ละเมิดอยู่ */
    public function test_sales_postings_revenue_reconciles_with_the_general_ledger(): void
    {
        [$branch, $product] = $this->stockedBranch('GAP8');

        foreach ([[2, 100], [1, 250]] as [$qty, $price]) {
            app(CashSaleService::class)->create([
                'branch_id' => $branch->id,
                'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $price]],
            ]);
        }

        $ledgerRevenue = (float) GlJournal::where(
            'account_id',
            ChartOfAccount::where('default_role', ChartOfAccount::ROLE_SALES_REVENUE)->value('id')
        )->sum('credit');

        $postedSales = (float) DB::table('sales_postings')->sum('net_sales');

        $this->assertSame(450.0, $postedSales);
        $this->assertSame(
            round($ledgerRevenue, 2),
            round($postedSales, 2),
            'รายได้ใน GL ต้องเท่ากับยอดขายใน sales_postings — บน production ต่างกันอยู่ราว 4.58 ล้าน '.
            'เพราะมีบิล POS นำเข้าเก่า 16,537 ใบที่ไม่เคยลง GL ปนอยู่ '.
            'ดู docs/architecture/LEGACY_POS_IMPORT_QUARANTINE.md'
        );
    }

    private function seedDocumentTypes(): void
    {
        foreach ([
            'PURCHASE' => 'ใบซื้อ', 'CASH_SALE' => 'ใบขายสด',
            'CREDIT_SALE' => 'ใบขายเชื่อ', 'BOOKING' => 'ใบจอง',
        ] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name]);
        }
    }

    private function stockedBranch(string $suffix): array
    {
        $this->seedDocumentTypes();
        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$suffix, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        $supplier = Supplier::create(['code' => 'SUP'.$suffix, 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);

        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => 20, 'unit_price' => 60]],
        ]);

        return [$branch->fresh(), $product->fresh()];
    }

    private function applicationFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
