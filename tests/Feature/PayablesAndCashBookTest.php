<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBook;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\SupplierOpenItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\CashBookPostingService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierOpenItemService;
use App\Services\Sales\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ยอดเจ้าหนี้รายใบ (ฐานของ AP aging) และสมุดเงินสดที่เดินรายการอัตโนมัติ
 */
class PayablesAndCashBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_credit_purchase_opens_a_payable_that_aging_can_read(): void
    {
        [$branch, $supplier, $product] = $this->masters();

        $document = app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'payment_terms' => 'เครดิต 30 วัน',
            'due_date' => '2026-09-30',
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 100]],
        ]);

        $item = SupplierOpenItem::where('supplier_id', $supplier->id)->sole();
        $this->assertSame($document->doc_number, $item->document_no);
        $this->assertSame($document->id, $item->source_document_id);
        $this->assertSame('open', $item->status);
        $this->assertSame('2026-09-30', $item->due_date->toDateString());
        $this->assertSame('เครดิต 30 วัน', $item->payment_terms);
        $this->assertSame((float) $document->total_amount, (float) $item->balance_amount);
    }

    public function test_a_cash_purchase_opens_no_payable(): void
    {
        [$branch, $supplier, $product] = $this->masters();

        app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => false,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);

        $this->assertSame(0, SupplierOpenItem::count());
    }

    public function test_a_payment_clears_the_oldest_payable_first(): void
    {
        [$branch, $supplier] = $this->masters();
        $service = app(SupplierOpenItemService::class);

        foreach ([['PV-OLD', '2026-07-01', 300.0], ['PV-NEW', '2026-08-01', 500.0]] as [$number, $date, $amount]) {
            $service->openFromPurchase($this->purchaseDocument($branch, $supplier, $number, $date, $amount), $amount, null, $date);
        }

        $applied = $service->applyPayment($supplier->id, 400.0);

        $this->assertSame(400.0, $applied);
        $old = SupplierOpenItem::where('document_no', 'PV-OLD')->sole();
        $new = SupplierOpenItem::where('document_no', 'PV-NEW')->sole();
        $this->assertSame('cleared', $old->status);
        $this->assertSame(0.0, (float) $old->balance_amount);
        $this->assertNotNull($old->cleared_at);
        // เหลือ 100 จาก 400 ไปตัดใบใหม่ต่อ
        $this->assertSame('partial', $new->status);
        $this->assertSame(400.0, (float) $new->balance_amount);
    }

    public function test_paying_more_than_the_outstanding_only_applies_what_is_owed(): void
    {
        [$branch, $supplier] = $this->masters();
        $service = app(SupplierOpenItemService::class);
        $service->openFromPurchase($this->purchaseDocument($branch, $supplier, 'PV-1', '2026-08-01', 200.0), 200.0);

        $this->assertSame(200.0, $service->applyPayment($supplier->id, 350.0));
    }

    public function test_closing_a_shift_posts_cash_once_however_many_times_it_runs(): void
    {
        [$branch] = $this->masters();
        $shift = $this->shift($branch);
        $service = app(CashBookPostingService::class);

        $service->postShiftClose($shift, 1200.0, -50.0);
        $service->postShiftClose($shift, 1200.0, -50.0);

        $rows = CashBook::orderBy('id')->get();
        $this->assertCount(2, $rows, 'ควรได้สองบรรทัด: เงินสดจากการขาย และผลต่างเงินสด — ไม่ใช่สี่');
        $this->assertSame(1200.0, (float) $rows[0]->cash_in);
        $this->assertSame(50.0, (float) $rows[1]->cash_out);
        // ยอดยกมาเดินต่อกัน 1200 - 50
        $this->assertSame(1150.0, (float) $rows[1]->running_balance);
        $this->assertSame($shift->id, $rows[0]->pos_shift_id);
    }

    public function test_a_pos_sale_does_not_hit_the_cash_book_because_the_shift_close_does(): void
    {
        [$branch, , $product] = $this->masters();

        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
            'allow_negative_stock' => true,
            'source' => 'pos',
        ]);

        $this->assertSame(0, CashBook::where('source_type', CashBookPostingService::SOURCE_CASH_SALE)->count());
    }

    public function test_a_back_office_cash_sale_hits_the_cash_book(): void
    {
        [$branch, , $product] = $this->masters();

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
            'allow_negative_stock' => true,
        ]);

        $entry = CashBook::where('source_type', CashBookPostingService::SOURCE_CASH_SALE)->sole();
        $this->assertSame($document->id, (int) $entry->source_id);
        $this->assertSame((float) $document->total_amount, (float) $entry->cash_in);
    }

    public function test_an_entry_with_both_sides_or_neither_is_refused(): void
    {
        [$branch] = $this->masters();
        $service = app(CashBookPostingService::class);

        foreach ([['cash_in' => 100, 'cash_out' => 50], ['cash_in' => 0, 'cash_out' => 0]] as $index => $amounts) {
            try {
                $service->post($amounts + [
                    'branch_id' => $branch->id,
                    'entry_date' => '2026-08-23',
                    'description' => 'ทดสอบ',
                    'source_type' => CashBookPostingService::SOURCE_ADJUSTMENT,
                    'source_key' => 'adjustment:test-'.$index,
                ]);
                $this->fail('ควรถูกปฏิเสธ');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, CashBook::count());
    }

    private function purchaseDocument(Branch $branch, Supplier $supplier, string $number, string $date, float $amount): Document
    {
        return Document::create([
            'document_type_id' => DocumentType::where('code', 'PURCHASE')->value('id'),
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'doc_number' => $number,
            'doc_date' => $date,
            'status' => 'active',
            'total_items' => 1,
            'total_amount' => $amount,
        ]);
    }

    private function shift(Branch $branch): PosShift
    {
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS-CB', 'name' => 'POS ทดสอบ']);

        return PosShift::create([
            'branch_id' => $branch->id,
            'pos_terminal_id' => $terminal->id,
            'shift_no' => 'SHIFT-CB-01',
            'opened_at' => '2026-08-23 08:00:00',
            'closed_at' => '2026-08-23 20:00:00',
            'opening_cash' => 1000,
            'expected_cash' => 2200,
            'status' => 'closed',
        ]);
    }

    private function masters(): array
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        $branch = Branch::create(['code' => 'CB', 'name_th' => 'สาขาทดสอบสมุดเงินสด', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-CB', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-CB', 'name_th' => 'ผู้จำหน่ายทดสอบ', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA-CB', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'CB-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        return [$branch->fresh(), $supplier, $product];
    }
}
