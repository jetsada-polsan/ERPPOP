<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\GlJournal;
use App\Models\OpeningBalanceRun;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * ยอดยกมาตอนเริ่มใช้ระบบ — ของที่มีอยู่จริงต้องมีมูลค่าในงบดุลตรงกันเสมอ
 */
class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const AS_OF = '2026-09-01';

    public function test_opening_stock_creates_a_lot_a_balance_and_the_matching_inventory_entry(): void
    {
        [$branch, $location, $product] = $this->masters();

        $result = $this->service()->post('stock', $branch->id, self::AS_OF, [
            ['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 10, 'unit_cost' => 25],
        ]);

        $this->assertSame(250.0, $result['total']);

        $lot = DB::table('stock_lots')->where('product_id', $product->id)->sole();
        $this->assertEqualsWithDelta(10, (float) $lot->initial_qty, 0.0001, 'ต้องมี lot รองรับ ไม่งั้น FIFO ไม่มีต้นทุนให้ตัด');
        $this->assertEqualsWithDelta(10, (float) $lot->remaining_qty, 0.0001);
        $this->assertEqualsWithDelta(25, (float) $lot->unit_cost, 0.0001);

        $balance = StockBalance::where('product_id', $product->id)->sole();
        $this->assertEqualsWithDelta(10, (float) $balance->on_hand_qty, 0.0001);

        $this->assertSame(250.0, $this->accountBalance(ChartOfAccount::ROLE_INVENTORY, 'debit'));
        $this->assertSame(250.0, $this->accountBalance(ChartOfAccount::ROLE_OPENING_BALANCE, 'credit'));
        $this->assertSame(250.0, $this->service()->suspenseBalance());
    }

    public function test_opening_receivables_open_an_item_that_aging_can_read(): void
    {
        [$branch] = $this->masters();
        $customer = Customer::create(['code' => 'CUS-OPEN', 'name_th' => 'ลูกค้ายกมา', 'is_active' => true]);

        $this->service()->post('ar', $branch->id, self::AS_OF, [
            ['customer' => $customer->code, 'document_no' => 'INV-9001', 'document_date' => '2026-08-15', 'due_date' => '2026-09-14', 'amount' => 5000],
        ]);

        $item = DB::table('customer_open_items')->where('customer_id', $customer->id)->sole();
        $this->assertEqualsWithDelta(5000, (float) $item->balance_amount, 0.01);
        $this->assertStringStartsWith('2026-09-14', (string) $item->due_date);

        $document = Document::find($item->document_id);
        $this->assertNotNull($document, 'ลูกหนี้ยกมาต้องมีเอกสารอ้างอิง');
        $this->assertSame('INV-9001', $document->doc_number, 'ต้องเก็บเลขที่ใบเดิมไว้ ไม่ใช่ออกเลขใหม่');

        $this->assertSame(5000.0, $this->accountBalance(ChartOfAccount::ROLE_AR, 'debit'));
        $this->assertSame(5000.0, $this->accountBalance(ChartOfAccount::ROLE_OPENING_BALANCE, 'credit'));
    }

    public function test_opening_payables_credit_the_liability_not_debit_it(): void
    {
        [$branch] = $this->masters();
        $supplier = Supplier::create(['code' => 'SUP-OPEN', 'name_th' => 'ผู้ขายยกมา', 'is_active' => true]);

        $this->service()->post('ap', $branch->id, self::AS_OF, [
            ['supplier' => $supplier->code, 'document_no' => 'PV-7001', 'document_date' => '2026-08-20', 'due_date' => '2026-09-19', 'amount' => 3000],
        ]);

        $item = DB::table('supplier_open_items')->where('supplier_id', $supplier->id)->sole();
        $this->assertEqualsWithDelta(3000, (float) $item->balance_amount, 0.01);

        // หนี้สินยกมาต้องอยู่ฝั่ง credit ถ้ากลับข้างงบดุลจะเพี้ยนทั้งงบ
        $this->assertSame(3000.0, $this->accountBalance(ChartOfAccount::ROLE_AP, 'credit'));
        $this->assertSame(3000.0, $this->accountBalance(ChartOfAccount::ROLE_OPENING_BALANCE, 'debit'));
        $this->assertSame(-3000.0, $this->service()->suspenseBalance());
    }

    public function test_opening_cash_starts_the_cash_book_with_a_running_balance(): void
    {
        [$branch] = $this->masters();

        $this->service()->post('cash', $branch->id, self::AS_OF, [
            ['type' => 'cash', 'description' => 'เงินสดในลิ้นชัก', 'amount' => 2000],
            ['type' => 'cash', 'description' => 'เงินทอนสำรอง', 'amount' => 500],
        ]);

        $entries = DB::table('cash_books')->where('branch_id', $branch->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsWithDelta(2500, (float) $entries->last()->running_balance, 0.01, 'ยอดสะสมต้องเดินต่อกัน');
        $this->assertSame(2500.0, $this->accountBalance(ChartOfAccount::ROLE_CASH, 'debit'));
    }

    public function test_the_same_kind_cannot_be_carried_in_twice(): void
    {
        [$branch, $location, $product] = $this->masters();
        $rows = [['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 5, 'unit_cost' => 10]];

        $this->service()->post('stock', $branch->id, self::AS_OF, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ยกซ้ำไม่ได้');
        $this->service()->post('stock', $branch->id, self::AS_OF, $rows);
    }

    public function test_one_bad_line_stops_the_whole_batch(): void
    {
        [$branch, $location, $product] = $this->masters();

        try {
            $this->service()->post('stock', $branch->id, self::AS_OF, [
                ['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 5, 'unit_cost' => 10],
                ['sku' => 'ไม่มีสินค้านี้', 'location' => $location->code, 'qty' => 1, 'unit_cost' => 1],
            ]);
            $this->fail('ควรโยนข้อผิดพลาด');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ไม่พบสินค้า', $exception->getMessage());
        }

        // บรรทัดแรกถูกต้องแต่ต้องไม่ถูกเขียน ยกยอดครึ่ง ๆ อันตรายกว่าไม่ได้ยก
        $this->assertSame(0, DB::table('stock_lots')->count());
        $this->assertSame(0, GlJournal::count());
        $this->assertSame(0, OpeningBalanceRun::count());
    }

    public function test_validation_reports_every_bad_line_without_writing(): void
    {
        [$branch, $location, $product] = $this->masters();

        $checked = $this->service()->validate('stock', $branch->id, [
            ['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 5, 'unit_cost' => 10],
            ['sku' => 'ผิด', 'location' => $location->code, 'qty' => 1, 'unit_cost' => 1],
            ['sku' => $product->sku_code, 'location' => 'ผิด', 'qty' => 1, 'unit_cost' => 1],
            ['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 0, 'unit_cost' => 1],
        ]);

        $this->assertCount(3, $checked['errors']);
        $this->assertSame(1, $checked['lines']);
        $this->assertSame(0, DB::table('stock_lots')->count());
    }

    public function test_every_kind_together_leaves_the_suspense_account_equal_to_net_worth(): void
    {
        [$branch, $location, $product] = $this->masters();
        $customer = Customer::create(['code' => 'CUS-NET', 'name_th' => 'ลูกค้า', 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP-NET', 'name_th' => 'ผู้ขาย', 'is_active' => true]);

        $service = $this->service();
        $service->post('stock', $branch->id, self::AS_OF, [['sku' => $product->sku_code, 'location' => $location->code, 'qty' => 10, 'unit_cost' => 25]]);
        $service->post('ar', $branch->id, self::AS_OF, [['customer' => $customer->code, 'document_no' => 'INV-1', 'document_date' => '2026-08-01', 'amount' => 5000]]);
        $service->post('ap', $branch->id, self::AS_OF, [['supplier' => $supplier->code, 'document_no' => 'PV-1', 'document_date' => '2026-08-01', 'amount' => 3000]]);
        $service->post('cash', $branch->id, self::AS_OF, [['type' => 'cash', 'description' => 'เงินสด', 'amount' => 2000]]);

        // สินทรัพย์ 250 + 5,000 + 2,000 ลบหนี้สิน 3,000 = 4,250
        $this->assertSame(4250.0, $service->suspenseBalance());
        $this->assertSame(
            round((float) GlJournal::sum('debit'), 2),
            round((float) GlJournal::sum('credit'), 2),
            'ยกยอดทุกชุดแล้ว GL ต้องยังดุล',
        );
        $this->assertSame(4, OpeningBalanceRun::count());
    }

    private function service(): OpeningBalanceService
    {
        return app(OpeningBalanceService::class);
    }

    private function accountBalance(string $role, string $side): float
    {
        $account = ChartOfAccount::where('default_role', $role)->firstOrFail();

        return round((float) GlJournal::where('account_id', $account->id)->sum($side), 2);
    }

    /** @return array{0:Branch, 1:WarehouseLocation, 2:Product} */
    private function masters(): array
    {
        $branch = Branch::create(['code' => 'OB', 'name_th' => 'สาขายกยอด', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-OB', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN-OB', 'name' => 'พื้นที่หลัก']);
        $unit = ProductUnit::create(['code' => 'EA-OB', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'OB-1', 'name_th' => 'สินค้ายกมา', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ]);

        return [$branch, $location, $product];
    }
}
