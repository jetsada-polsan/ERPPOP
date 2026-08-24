<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UAT ของรายงาน P0 — พิสูจน์ "ตัวเลข" ไม่ใช่แค่ว่าเปิดหน้าได้
 *
 * ชุดเดิมตรวจแค่ว่ารายงานรันแล้วไม่ error ซึ่งรายงานที่คำนวณผิดก็ผ่านข้อนั้นได้
 * ชุดนี้ป้อนข้อมูลที่รู้คำตอบอยู่แล้วเข้าไป แล้วเทียบกับสิ่งที่รายงานตอบกลับมา
 *
 * ทั้งห้าตัวเป็นรายงานที่ผู้บริหารใช้ตัดสินใจเรื่องเงิน ตัวเลขผิดแล้วตัดสินใจผิด
 * โดยไม่มีอะไรบอก จึงต้องมีหลักฐานว่าถูกก่อนเปิดใช้
 */
class P0ReportFiguresTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivable_ageing_puts_each_invoice_in_the_right_bucket(): void
    {
        $customer = Customer::create(['code' => 'C-AGE', 'name_th' => 'ลูกค้าค้างชำระ', 'is_active' => true]);
        $branch = $this->branch();

        // วางให้ครบทุกถัง โดยเลือกวันที่ห่างจากขอบถังพอสมควร กันเรื่องเวลาคาบเกี่ยว
        $this->receivable($customer, $branch, 'INV-1', now()->addDays(10), 1000);    // ยังไม่ถึงกำหนด
        $this->receivable($customer, $branch, 'INV-2', now()->subDays(10), 200);     // 1-30
        $this->receivable($customer, $branch, 'INV-3', now()->subDays(45), 30);      // 31-60
        $this->receivable($customer, $branch, 'INV-4', now()->subDays(75), 4);       // 61-90
        $this->receivable($customer, $branch, 'INV-5', now()->subDays(200), 5000);   // เกิน 90

        $rows = $this->runReport('ar', 'ar_aging');
        $byBucket = collect($rows)->keyBy('bucket');

        $this->assertEqualsWithDelta(1000, (float) $byBucket['ยังไม่ถึงกำหนด']->amount, 0.01);
        $this->assertEqualsWithDelta(200, (float) $byBucket['1-30 วัน']->amount, 0.01);
        $this->assertEqualsWithDelta(30, (float) $byBucket['31-60 วัน']->amount, 0.01);
        $this->assertEqualsWithDelta(4, (float) $byBucket['61-90 วัน']->amount, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $byBucket['เกิน 90 วัน']->amount, 0.01);

        // ยอดรวมทุกถังต้องเท่ากับหนี้คงค้างทั้งหมด ไม่มีใบไหนตกหล่นระหว่างถัง
        $this->assertEqualsWithDelta(
            (float) DB::table('customer_open_items')->sum('balance_amount'),
            collect($rows)->sum(fn ($row) => (float) $row->amount),
            0.01,
            'ยอดรวมในรายงานต้องเท่ากับลูกหนี้คงค้างจริง',
        );
    }

    public function test_a_settled_invoice_drops_out_of_the_ageing(): void
    {
        $customer = Customer::create(['code' => 'C-PAID', 'name_th' => 'ลูกค้าจ่ายแล้ว', 'is_active' => true]);
        $branch = $this->branch();
        $this->receivable($customer, $branch, 'INV-OPEN', now()->subDays(10), 700);
        $this->receivable($customer, $branch, 'INV-DONE', now()->subDays(10), 900, 'paid');

        $total = collect($this->runReport('ar', 'ar_aging'))->sum(fn ($row) => (float) $row->amount);

        $this->assertEqualsWithDelta(700, $total, 0.01, 'ใบที่ปิดแล้วต้องไม่ถูกนับเป็นหนี้ค้าง');
    }

    public function test_payable_ageing_reports_each_supplier_separately(): void
    {
        $first = Supplier::create(['code' => 'S-1', 'name_th' => 'ผู้ขายหนึ่ง', 'is_active' => true]);
        $second = Supplier::create(['code' => 'S-2', 'name_th' => 'ผู้ขายสอง', 'is_active' => true]);
        $this->payable($first, 'PV-1', now()->subDays(100), 8000);
        $this->payable($first, 'PV-2', now()->addDays(5), 1000);
        $this->payable($second, 'PV-3', now()->subDays(40), 300);

        $rows = collect($this->runReport('ap', 'aging'))->keyBy('supplier_name');

        $this->assertEqualsWithDelta(9000, (float) $rows['ผู้ขายหนึ่ง']->total_amount, 0.01);
        $this->assertEqualsWithDelta(8000, (float) $rows['ผู้ขายหนึ่ง']->over_90, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows['ผู้ขายหนึ่ง']->current_amount, 0.01);
        $this->assertEqualsWithDelta(300, (float) $rows['ผู้ขายสอง']->days_31_60, 0.01);
    }

    public function test_the_cash_book_shows_every_movement_with_its_running_balance(): void
    {
        $branch = $this->branch();
        $this->cashEntry($branch, 'ยกมา', 5000, 0, 5000);
        $this->cashEntry($branch, 'ขายสด', 1200, 0, 6200);
        $this->cashEntry($branch, 'จ่ายค่าน้ำมัน', 0, 700, 5500);

        $rows = $this->runReport('cash', 'daily_cash_book');

        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(5500, (float) end($rows)->running_balance, 0.01);
        // ยอดคงเหลือสุดท้ายต้องเท่ากับรับหักจ่าย ไม่ใช่ตัวเลขที่เก็บไว้เฉย ๆ
        $this->assertEqualsWithDelta(
            collect($rows)->sum(fn ($row) => (float) $row->cash_in - (float) $row->cash_out),
            (float) end($rows)->running_balance,
            0.01,
        );
    }

    public function test_bank_lines_show_which_ones_are_still_unreconciled(): void
    {
        $branch = $this->branch();
        $accountId = DB::table('bank_accounts')->insertGetId([
            'branch_id' => $branch->id, 'bank_name' => 'ธนาคารทดสอบ',
            'account_no' => '123-4-56789-0', 'account_name' => 'บัญชีทดสอบ',
        ]);
        $matched = $this->bankLine($accountId, 'โอนเข้าจากลูกค้า', 2500);
        $this->bankLine($accountId, 'ยังไม่รู้ที่มา', 900);
        DB::table('bank_reconciliations')->insert([
            'bank_statement_id' => $matched, 'branch_id' => $branch->id,
            'expected_amount' => 2500, 'difference_amount' => 0,
            'status' => 'matched', 'reference' => 'RCP-1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect($this->runReport('cash', 'bank_reconciliation'));

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->where('status', 'matched')->count());
        $this->assertSame(1, $rows->where('status', 'ยังไม่กระทบยอด')->count(),
            'รายการที่ยังไม่กระทบยอดต้องโผล่ให้เห็น ไม่ใช่หายไปเพราะไม่มีคู่');
    }

    public function test_sales_by_channel_matches_the_sales_ledger(): void
    {
        $branch = $this->sellingBranch();
        $product = $this->sellableProduct($branch);

        app(\App\Services\Sales\CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product, 'qty' => 2, 'unit_price' => 150],
                ['product_id' => $product, 'qty' => 1, 'unit_price' => 200],
            ],
            'allow_negative_stock' => true,
        ]);

        $rows = collect($this->runReport('sales', 'daily_by_channel'));
        $reported = $rows->sum(fn ($row) => (float) $row->net_sales);

        // เทียบกับ sales_postings ซึ่งเป็นจุดที่การขายทุกช่องทางมารวมกัน
        // ถ้าสองตัวนี้ไม่ตรง แปลว่ารายงานนับซ้ำหรือนับตกไปหนึ่งช่องทาง
        $this->assertEqualsWithDelta(
            (float) DB::table('sales_postings')->sum('net_sales'),
            $reported,
            0.01,
            'ยอดในรายงานต้องเท่ากับยอดขายที่ลงบัญชีจริง',
        );
        $this->assertEqualsWithDelta(500, $reported, 0.01, '2×150 + 1×200 = 500');
        $this->assertSame(1, (int) $rows->sum(fn ($row) => (int) $row->bill_count), 'ขายใบเดียวต้องนับเป็นหนึ่งบิล');
    }

    private function sellingBranch(): Branch
    {
        $branch = Branch::firstOrCreate(['code' => 'SELL'], ['name_th' => 'สาขาขาย', 'is_active' => true]);
        $warehouse = \App\Models\Warehouse::firstOrCreate(['code' => 'WH-SELL'], ['name' => 'คลังขาย', 'branch_id' => $branch->id]);
        $location = \App\Models\WarehouseLocation::firstOrCreate(
            ['code' => 'SELL-MAIN'], ['warehouse_id' => $warehouse->id, 'name' => 'พื้นที่ขาย'],
        );
        $branch->update(['default_warehouse_location_id' => $location->id]);

        return $branch->fresh();
    }

    private function sellableProduct(Branch $branch): int
    {
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด']);
        $unit = \App\Models\ProductUnit::firstOrCreate(['code' => 'EA-RPT'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = \App\Models\Product::create([
            'sku_code' => 'RPT-1', 'name_th' => 'สินค้ารายงาน', 'base_unit_id' => $unit->id,
            'default_price' => 150, 'average_cost' => 90, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        DB::table('stock_balances')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $branch->default_warehouse_location_id,
            'on_hand_qty' => 100, 'reserved_qty' => 0,
        ]);
        DB::table('stock_lots')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $branch->default_warehouse_location_id,
            'lot_number' => 'RPT-LOT', 'received_date' => now()->toDateString(),
            'initial_qty' => 100, 'remaining_qty' => 100, 'unit_cost' => 90,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product->id;
    }

    /** @return array<int, object> */
    private function runReport(string $category, string $report): array
    {
        ReportDefinition::where('code', $category.'.'.$report)->update(['enabled' => true, 'status' => 'available']);

        $response = $this->actingAs($this->reportUser())->get(sprintf(
            '/reports?category=%s&report=%s&from=%s&to=%s&branch_id=all&per_page=100',
            $category, $report, now()->subYear()->toDateString(), now()->addYear()->toDateString(),
        ));
        $response->assertOk();
        $this->assertSame($report, $response->viewData('selectedReport'), 'ต้องได้รายงานที่ขอ ไม่ใช่ถูกเด้งไปตัวอื่น');

        return collect($response->viewData('result')['rows'])->all();
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(['code' => 'RPT'], ['name_th' => 'สาขารายงาน', 'is_active' => true]);
    }

    private function receivable(Customer $customer, Branch $branch, string $number, \DateTimeInterface $due, float $amount, string $status = 'open'): void
    {
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ใบขายเชื่อ']);
        $document = Document::create([
            'doc_number' => $number, 'document_type_id' => $type->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'doc_date' => now()->toDateString(), 'status' => 'active',
            'total_amount' => $amount, 'subtotal_amount' => $amount,
        ]);
        DB::table('customer_open_items')->insert([
            'customer_id' => $customer->id, 'document_id' => $document->id,
            'gross_amount' => $amount, 'net_amount' => $amount, 'paid_amount' => $status === 'paid' ? $amount : 0,
            'balance_amount' => $status === 'paid' ? 0 : $amount,
            'due_date' => $due->format('Y-m-d'), 'status' => $status, 'created_at' => now(),
        ]);
    }

    private function payable(Supplier $supplier, string $number, \DateTimeInterface $due, float $amount): void
    {
        DB::table('supplier_open_items')->insert([
            'supplier_id' => $supplier->id, 'document_no' => $number,
            'document_date' => now()->toDateString(), 'due_date' => $due->format('Y-m-d'),
            'original_amount' => $amount, 'paid_amount' => 0, 'balance_amount' => $amount,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashEntry(Branch $branch, string $description, float $in, float $out, float $balance): void
    {
        static $sequence = 0;
        DB::table('cash_books')->insert([
            'branch_id' => $branch->id, 'entry_date' => now()->toDateString(), 'description' => $description,
            'cash_in' => $in, 'cash_out' => $out, 'running_balance' => $balance,
            'source_type' => 'test', 'source_key' => 'test:'.++$sequence,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bankLine(int $accountId, string $description, float $amount): int
    {
        return DB::table('bank_statements')->insertGetId([
            'bank_account_id' => $accountId, 'statement_date' => now()->toDateString(),
            'description' => $description, 'amount' => $amount, 'balance' => $amount, 'reconciled' => false,
        ]);
    }

    private function reportUser(): User
    {
        static $user = null;
        if ($user) {
            return $user;
        }
        $user = User::factory()->create(['username' => 'p0_figures', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'P0_FIGURES', 'name' => 'P0 figures']);
        foreach (['reports.view', 'reports.export', 'reports.all_branches', 'finance.manage', 'sales.manage', 'stock.manage', 'purchasing.manage'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user = $user->fresh();
    }
}
