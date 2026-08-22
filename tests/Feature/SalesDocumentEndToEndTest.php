<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\CreditSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * เกณฑ์ความสำเร็จตาม CLAUDE_LEGACY_REBUILD_BRIEF.md:
 * "เอกสารหนึ่งใบไหลจากต้นทางถึง stock, revenue, debtor/cash, cost, GL และรายงาน
 *  ได้หนึ่งครั้งอย่างถูกต้อง"
 *
 * เทสต์ชุดนี้ไล่ทั้งเส้นจริงตั้งแต่ซื้อของเข้ามาจนถึงยอดในรายงาน ไม่ใช่ทดสอบทีละชิ้น
 * เพราะข้อผิดพลาดที่แพงที่สุดของ ERP อยู่ที่รอยต่อระหว่างโมดูล ไม่ใช่ในโมดูล
 */
class SalesDocumentEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_back_office_cash_sale_reaches_stock_cost_gl_and_the_report(): void
    {
        [$branch, $location, $product] = $this->stockedBranch('E2E1', qty: 10, unitCost: 60);

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]],
        ]);

        // 1. สต๊อกลดจริง
        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(8.0, (float) $balance->on_hand_qty);

        // 2. ต้นทุนถูกบันทึกลงบรรทัดเอกสาร ไม่ใช่คำนวณทีหลัง
        $line = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.document_id', $document->id)->sole();
        $this->assertSame(60.0, (float) $line->unit_cost);
        $this->assertSame(120.0, (float) $line->cost_amount);

        // 3. GL ลงครบและดุล
        $this->assertGlBalanced($document);
        $this->assertGlHas($document, ChartOfAccount::ROLE_CASH, debit: 200.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_SALES_REVENUE, credit: 200.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_COGS, debit: 120.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_INVENTORY, credit: 120.0);

        // 4. ปรากฏใน sales_postings ครั้งเดียว
        $postings = DB::table('sales_postings')->where('document_id', $document->id)->get();
        $this->assertCount(1, $postings);
        $this->assertSame('CASH_SALE', $postings[0]->channel);
        $this->assertSame(200.0, (float) $postings[0]->net_sales);
        $this->assertSame(120.0, (float) $postings[0]->cogs_amount);
        $this->assertSame(80.0, (float) $postings[0]->gross_profit);
    }

    public function test_a_booking_converted_to_a_credit_sale_opens_a_receivable_and_posts_to_ar(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('E2E2', qty: 10, unitCost: 60, withCustomer: true);

        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);

        // ใบจองต้องจองของ ไม่ตัดของ
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(10.0, (float) $balance->on_hand_qty, 'ใบจองต้องไม่ตัดสต๊อก');
        $this->assertSame(3.0, (float) $balance->reserved_qty, 'ใบจองต้องจองสต๊อก');
        $this->assertSame(0, DB::table('sales_postings')->where('document_id', $bookingDocument->id)->count(),
            'ใบจองต้องไม่ถูกนับเป็นยอดขาย');

        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $saleDocument = app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        // ยืนยันแล้วจึงตัดของ
        $balance = $balance->fresh();
        $this->assertSame(7.0, (float) $balance->on_hand_qty);
        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
        $this->assertSame($saleDocument->id, (int) $booking->fresh()->confirmed_document_id);

        // เปิดลูกหนี้พร้อมวันครบกำหนดชำระ
        $openItem = CustomerOpenItem::where('document_id', $saleDocument->id)->sole();
        $this->assertSame(300.0, (float) $openItem->balance_amount);
        $this->assertNotNull($openItem->due_date, 'ลูกหนี้ต้องมีวันครบกำหนดชำระ');

        $this->assertGlBalanced($saleDocument);
        $this->assertGlHas($saleDocument, ChartOfAccount::ROLE_AR, debit: 300.0);

        $postings = DB::table('sales_postings')->where('document_id', $saleDocument->id)->get();
        $this->assertCount(1, $postings);
        $this->assertSame('CREDIT_SALE', $postings[0]->channel);
    }

    public function test_every_posted_document_keeps_the_ledger_balanced(): void
    {
        [$branch, , $product] = $this->stockedBranch('E2E3', qty: 20, unitCost: 60);

        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);
        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 250]],
        ]);

        $unbalanced = DB::table('gl_journals')
            ->selectRaw('document_id, sum(debit) as total_debit, sum(credit) as total_credit')
            ->groupBy('document_id')
            ->havingRaw('round(sum(debit), 2) <> round(sum(credit), 2)')
            ->get();

        $this->assertCount(0, $unbalanced, 'มีเอกสารที่ debit ไม่เท่า credit: '.$unbalanced->pluck('document_id')->implode(', '));
        $this->assertGreaterThan(0, GlJournal::count(), 'ต้องมีรายการ GL เกิดขึ้นจริง ไม่ใช่ผ่านเพราะไม่มีข้อมูล');
    }

    private function assertGlBalanced(Document $document): void
    {
        $lines = GlJournal::where('document_id', $document->id)->get();
        $this->assertNotEmpty($lines, "เอกสาร {$document->doc_number} ไม่ได้ลง GL เลย");
        $this->assertSame(
            round((float) $lines->sum('debit'), 2),
            round((float) $lines->sum('credit'), 2),
            "GL ของ {$document->doc_number} ไม่ดุล",
        );
    }

    private function assertGlHas(Document $document, string $role, float $debit = 0, float $credit = 0): void
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');
        $this->assertNotNull($accountId, "ยังไม่ได้ผูกบัญชีสำหรับ role {$role}");
        $line = GlJournal::where('document_id', $document->id)->where('account_id', $accountId)->get();
        $this->assertNotEmpty($line, "ไม่พบรายการ GL ของ role {$role} ในเอกสาร {$document->doc_number}");
        $this->assertSame($debit, round((float) $line->sum('debit'), 2), "ยอด debit ของ {$role} ไม่ตรง");
        $this->assertSame($credit, round((float) $line->sum('credit'), 2), "ยอด credit ของ {$role} ไม่ตรง");
    }

    /** สาขาที่มีของในคลังจริงโดยซื้อเข้ามา ไม่ใช่ยัดตัวเลขลงตาราง */
    private function stockedBranch(string $suffix, float $qty, float $unitCost, bool $withCustomer = false): array
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'BOOKING'], ['name_th' => 'ใบจอง', 'stock_effect' => 'none']);

        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$suffix, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP'.$suffix, 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);

        app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'prices_include_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $unitCost]],
        ]);

        $customer = $withCustomer
            ? Customer::create(['code' => 'CUS'.$suffix, 'name_th' => 'ลูกค้าทดสอบ', 'branch_id' => $branch->id, 'is_active' => true])
            : null;

        return [$branch->fresh(), $location, $product->fresh(), $customer];
    }
}
