<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BillingNote;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FlashSale;
use App\Models\OcrDocument;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\PriceTable;
use App\Models\PriceTagTemplate;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\ScalePluPlan;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockIssueService;
use App\Services\Inventory\StockTransferService;
use App\Services\Inventory\StockTransformService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\CreditDebitNoteService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * งานที่ 11 รอบที่ 1: ตรวจหน้าที่ต้องเปิดจากเอกสาร (show/edit/print)
 * เปิดทุก route GET ที่มีพารามิเตอร์ ด้วยผู้ใช้ 3 บทบาท (GM, SALES, WAREHOUSE)
 * ไม่มีหน้าไหนได้ 5xx — คนไม่มีสิทธิ์ต้องได้ 403
 */
class EveryRecordPageOpensTest extends TestCase
{
    use RefreshDatabase;

    /** หน้าที่ตั้งใจเป็น API/ดาวน์โหลด/ไฟล์ binary ไม่ใช่หน้า Blade ปกติ */
    private const SKIP_ROUTE_NAMES = [
        'api.pos.members.show',
        'api.ocr.documents.show',
        'api.ocr.documents.file',
        'pos.release.download',
        'master-data-setup.template',
        'ocr.documents.file',
        'stock-counts.export',
        'monthly-accounting.exports.download',
        'tax-compliance.filings.download',
        'scale-prices.plans.export',
        'storage.local',
    ];

    private array $fixture = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
        $this->fixture = $this->createComprehensiveFixtures();
    }

    private function createComprehensiveFixtures(): array
    {
        // 1. Types
        foreach ([
            'PURCHASE' => 'in',
            'CASH_SALE' => 'out',
            'CREDIT_SALE' => 'out',
            'BOOKING' => 'none',
            'SALE_RETURN' => 'in',
            'RECEIPT' => 'none',
            'PAYMENT_VOUCHER' => 'none',
            'CREDIT_NOTE' => 'none',
            'DEBIT_NOTE' => 'none',
            'STOCK_TRANSFER' => 'none',
            'STOCK_ADJUSTMENT' => 'none',
            'STOCK_REQUISITION' => 'out',
            'STOCK_TRANSFORM' => 'none',
        ] as $code => $effect) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }

        // 2. Master Org & Catalog
        $branch = Branch::create(['code' => 'BQA', 'name_th' => 'สาขาทดสอบ QA', 'is_active' => true]);
        $destBranch = Branch::create(['code' => 'BQA2', 'name_th' => 'สาขาปลายทาง QA', 'is_active' => true]);

        $wh1 = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WQA1', 'name' => 'คลังหลัก QA']);
        $loc1 = WarehouseLocation::create(['warehouse_id' => $wh1->id, 'code' => 'LQA1', 'name' => 'ตำแหน่งหลัก QA']);
        $branch->update(['default_warehouse_location_id' => $loc1->id]);

        $wh2 = Warehouse::create(['branch_id' => $destBranch->id, 'code' => 'WQA2', 'name' => 'คลังสาขา 2']);
        $loc2 = WarehouseLocation::create(['warehouse_id' => $wh2->id, 'code' => 'LQA2', 'name' => 'ตำแหน่งสาขา 2']);
        $destBranch->update(['default_warehouse_location_id' => $loc2->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $rawUnit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $product = Product::create([
            'sku_code' => 'P-QA-01',
            'name_th' => 'หมูสามชั้นสไลซ์ QA',
            'base_unit_id' => $unit->id,
            'default_price' => 180.0,
            'average_cost' => 120.0,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        ProductBarcode::create(['product_id' => $product->id, 'unit_id' => $unit->id, 'barcode' => '885000100001', 'is_active' => true]);

        $rawProduct = Product::create([
            'sku_code' => 'P-QA-RAW',
            'name_th' => 'หมูก้อนวัตถุดิบ QA',
            'base_unit_id' => $rawUnit->id,
            'default_price' => 110.0,
            'average_cost' => 90.0,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $supplier = Supplier::create([
            'code' => 'SUP-QA-01',
            'name_th' => 'บริษัท ซัพพลายเออร์ QA จำกัด',
            'tax_id' => '010555000001',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'code' => 'CUS-QA-01',
            'name_th' => 'ร้านอาหารทดสอบ QA',
            'tax_id' => '010555000002',
            'branch_id' => $branch->id,
            'credit_limit' => 50000,
            'credit_term_days' => 30,
            'is_active' => true,
        ]);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'address_line' => '123 ถนนสุขุมวิท กทม.',
            'is_default' => true,
        ]);

        // 3. Transactions via Real Services
        // 3.1 Purchase (Stock In)
        $purchase = app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'prices_include_vat' => false,
            'items' => [
                ['product_id' => $product->id, 'qty' => 100, 'unit_price' => 120.0],
                ['product_id' => $rawProduct->id, 'qty' => 50, 'unit_price' => 90.0],
            ],
        ]);
        $stockLot = StockLot::where('product_id', $product->id)->first();

        // 3.2 Booking
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'delivery_date' => now()->addDays(2)->toDateString(),
            'delivery_due_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'fulfillment_type' => 'delivery',
            'notes' => 'ออเดอร์ QA ทดสอบระบบ',
            'items' => [
                ['product_id' => $product->id, 'qty' => 10, 'unit_price' => 180.0],
            ],
        ]);

        // 3.3 Cash Sale
        $cashSale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $loc1->id,
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'notes' => 'ขายสด QA',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 180.0],
            ],
        ]);

        // 3.4 Credit Sale (converted from booking)
        $bookingForCredit = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'delivery_date' => now()->addDays(2)->toDateString(),
            'notes' => 'ออเดอร์ QA สำหรับขายเชื่อ',
            'items' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit_price' => 180.0],
            ],
        ]);
        $creditSale = app(CreditSaleService::class)->convertBookingToCreditSale($bookingForCredit->saleBooking);

        // 3.5 Sale Return
        $saleReturn = app(SaleReturnService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_open_item_id' => $creditSale->openItem?->id,
            'remark' => 'ทดสอบรับคืน QA',
            'items' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 180.0],
            ],
        ]);

        // 3.6 Receipt
        $openItem = \App\Models\CustomerOpenItem::where('document_id', $creditSale->id)->first();
        $receipt = app(CustomerPaymentService::class)->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount' => 500.0,
            'allocations' => [
                ['customer_open_item_id' => $openItem->id, 'amount' => 500.0],
            ],
        ]);

        // 3.7 Supplier Payment Voucher
        $voucher = app(SupplierPaymentService::class)->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount' => 1000.0,
        ]);

        // 3.8 Credit Note
        $creditNote = app(CreditDebitNoteService::class)->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'open_item_id' => $openItem->id,
            'type' => 'credit',
            'amount' => 50.0,
            'reason' => 'ลดหนี้ทดสอบ QA',
        ]);

        // 3.9 Quotation
        $quotation = Quotation::create([
            'doc_number' => 'QT-QA-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name_th,
            'branch_id' => $branch->id,
            'doc_date' => now()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(),
            'total_amount' => 1800.0,
            'status' => 'draft',
            'note' => 'ใบเสนอราคา QA',
        ]);

        // 3.10 Billing Note
        $billingNote = BillingNote::create([
            'doc_number' => 'BN-QA-001',
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'doc_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 900.0,
            'status' => 'active',
            'note' => 'ใบวางบิล QA',
        ]);

        // 3.11 Purchase Order
        $purchaseOrder = PurchaseOrder::create([
            'doc_number' => 'PO-QA-001',
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'doc_date' => now()->toDateString(),
            'need_by_date' => now()->addDays(7)->toDateString(),
            'status' => 'approved',
            'total_amount' => 5000.0,
            'is_credit' => true,
            'note' => 'ใบสั่งซื้อ QA',
        ]);

        // 3.12 Stock Transfer
        $transfer = app(StockTransferService::class)->createRequest([
            'branch_id' => $branch->id,
            'from_warehouse_location_id' => $loc1->id,
            'to_warehouse_location_id' => $loc2->id,
            'remark' => 'โอนย้ายสินค้า QA',
            'items' => [['product_id' => $product->id, 'qty' => 5]],
        ]);
        app(StockTransferService::class)->approve($transfer);

        // 3.13 Stock Adjustment
        $adjustment = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $loc1->id,
            'remark' => 'ปรับปรุงสต๊อก QA',
            'items' => [['product_id' => $product->id, 'counted_qty' => 90]],
        ]);

        // 3.14 Stock Issue (Requisition)
        $issue = app(StockIssueService::class)->create([
            'type' => 'requisition',
            'branch_id' => $branch->id,
            'warehouse_location_id' => $loc1->id,
            'purpose' => 'kitchen',
            'remark' => 'เบิกใช้งาน QA',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ]);

        // 3.15 Stock Transform (Production)
        $recipe = ProductionRecipe::create([
            'code' => 'RCP-QA-01',
            'name' => 'สูตรแปรรูป QA',
            'output_qty' => 10,
            'finished_product_id' => $product->id,
            'is_active' => true,
        ]);
        ProductionRecipeItem::create([
            'production_recipe_id' => $recipe->id,
            'product_id' => $rawProduct->id,
            'qty' => 12,
        ]);

        $transform = app(StockTransformService::class)->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $loc1->id,
            'batch_mode' => true,
            'remark' => 'แปรรูป QA',
            'production_recipe_id' => $recipe->id,
            'input_weight_qty' => 12.0,
            'raw_items' => [['product_id' => $rawProduct->id, 'qty' => 12]],
            'output_items' => [['product_id' => $product->id, 'qty' => 10]],
        ]);

        // 3.16 Stock Count
        $count = StockCount::create([
            'doc_number' => 'SC-QA-001',
            'branch_id' => $branch->id,
            'warehouse_location_id' => $loc1->id,
            'count_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        // 3.17 Fixed Asset
        $fixedAsset = FixedAsset::create([
            'asset_code' => 'FA-QA-001',
            'name' => 'เครื่องสไลซ์เนื้อ QA',
            'category' => 'machinery',
            'branch_id' => $branch->id,
            'acquired_date' => now()->subMonths(6)->toDateString(),
            'cost' => 35000.0,
            'salvage_value' => 1000.0,
            'useful_life_months' => 60,
            'status' => 'active',
        ]);

        // 3.18 Pricing: PriceTable, FlashSale, PriceTagTemplate
        $priceTable = PriceTable::create([
            'code' => 'PT-QA-01',
            'name' => 'ตารางราคาค้าส่ง QA',
            'is_active' => true,
        ]);
        $flashSale = FlashSale::create([
            'code' => 'FS-QA-01',
            'name' => 'โปรโมชันพิเศษ QA',
            'branch_id' => $branch->id,
            'starts_date' => now()->toDateString(),
            'ends_date' => now()->addDays(7)->toDateString(),
            'is_active' => true,
        ]);
        $priceTag = PriceTagTemplate::create([
            'code' => 'TAG-QA-01',
            'name' => 'ป้ายราคาสินค้า 5x3 QA',
            'price_source' => 'default',
            'is_active' => true,
        ]);

        // 3.19 Management Controls: Budget, PayrollRun, PayrollItem
        $costCenterId = \Illuminate\Support\Facades\DB::table('cost_centers')->insertGetId([
            'code' => 'CC-QA',
            'name' => 'ฝ่ายขาย QA',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $budgetId = \Illuminate\Support\Facades\DB::table('budgets')->insertGetId([
            'budget_no' => 'BG-QA-001',
            'fiscal_year' => (int) now()->year,
            'cost_center_id' => $costCenterId,
            'status' => 'approved',
            'total_amount' => 100000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-QA-01',
            'full_name' => 'พนักงานทดสอบ QA',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $payrollRunId = \Illuminate\Support\Facades\DB::table('payroll_runs')->insertGetId([
            'period' => now()->format('Y-m'),
            'status' => 'draft',
            'gross_amount' => 25000.0,
            'deduction_amount' => 750.0,
            'net_amount' => 24250.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payrollItemId = \Illuminate\Support\Facades\DB::table('payroll_items')->insertGetId([
            'payroll_run_id' => $payrollRunId,
            'employee_id' => $employee->id,
            'base_salary' => 25000.0,
            'social_security' => 750.0,
            'net_amount' => 24250.0,
        ]);

        // 3.20 POS Shift & Scale Plan
        $terminal = PosTerminal::firstOrCreate(['code' => 'T-QA-01'], [
            'name' => 'จุดขาย QA 1',
            'branch_id' => $branch->id,
        ]);
        $posShift = PosShift::create([
            'branch_id' => $branch->id,
            'pos_terminal_id' => $terminal->id,
            'shift_no' => 'SH-QA-001',
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => 2000.0,
        ]);
        $scalePlanId = \Illuminate\Support\Facades\DB::table('scale_plu_plans')->insertGetId([
            'status' => 'applied',
            'rows' => json_encode([['plu' => '800001', 'sku' => 'P-QA-01', 'sku_code' => 'P-QA-01', 'name' => 'หมูสามชั้น', 'price' => 180.0]]),
            'changes' => json_encode([['sku' => 'P-QA-01', 'plu' => '800001']]),
            'fingerprint' => 'test-fingerprint',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3.21 OCR Document
        $ocrDoc = OcrDocument::create([
            'uuid' => 'qa-ocr-'.uniqid(),
            'document_type' => 'tax_invoice',
            'source_module' => 'purchasing',
            'original_file_path' => 'dummy/path.pdf',
            'original_file_name' => 'invoice.pdf',
            'file_mime_type' => 'application/pdf',
            'original_file_sha256' => hash('sha256', 'dummy'),
            'status' => 'pending',
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
        ]);

        return compact(
            'branch', 'destBranch', 'loc1', 'loc2', 'product', 'rawProduct', 'stockLot',
            'supplier', 'customer', 'purchase', 'booking', 'cashSale', 'creditSale',
            'saleReturn', 'receipt', 'voucher', 'creditNote', 'quotation', 'billingNote',
            'purchaseOrder', 'transfer', 'adjustment', 'issue', 'transform', 'count',
            'fixedAsset', 'priceTable', 'flashSale', 'priceTag', 'budgetId', 'employee',
            'payrollRunId', 'payrollItemId', 'posShift', 'scalePlanId', 'ocrDoc'
        );
    }

    /** สร้าง mapping ระหว่างชื่อ route กับ URL ที่ bind ค่าจริง */
    private function resolveParameterizedUrl(string $name): ?string
    {
        $f = $this->fixture;

        return match ($name) {
            'bookings.edit' => route('bookings.edit', $f['booking']),
            'bookings.show' => route('bookings.show', $f['booking']),
            'bookings.bill' => route('bookings.bill', $f['booking']),
            'fleet.load-sheet' => route('fleet.load-sheet', $f['booking']),
            'cash-sales.show' => route('cash-sales.show', $f['cashSale']),
            'sales.show' => route('sales.show', $f['creditSale']),
            'sales.print' => route('sales.print', $f['creditSale']),
            'sale-returns.show' => route('sale-returns.show', $f['saleReturn']),
            'payments.show' => route('payments.show', $f['receipt']),
            'payments.print' => route('payments.print', $f['receipt']),
            'quotations.show' => route('quotations.show', $f['quotation']),
            'credit-debit-notes.open-items' => route('credit-debit-notes.open-items', $f['customer']),
            'credit-debit-notes.print' => route('credit-debit-notes.print', $f['creditNote']),
            'billing-notes.open-items' => route('billing-notes.open-items', $f['customer']),
            'billing-notes.show' => route('billing-notes.show', $f['billingNote']),
            'purchase-orders.show' => route('purchase-orders.show', $f['purchaseOrder']),
            'purchase-orders.print' => route('purchase-orders.print', $f['purchaseOrder']),
            'purchase-orders.supplier-prices' => route('purchase-orders.supplier-prices', $f['purchaseOrder']),
            'purchases.show' => route('purchases.show', $f['purchase']),
            'purchases.cost-adjustments.create' => route('purchases.cost-adjustments.create', $f['purchase']),
            'products.show' => route('products.show', $f['product']),
            'products.lots.trace' => route('products.lots.trace', [$f['product'], $f['stockLot']]),
            'stock-transfers.receipt.create' => route('stock-transfers.receipt.create', $f['transfer']),
            'stock-transfers.show' => route('stock-transfers.show', $f['transfer']),
            'stock-adjustments.show' => route('stock-adjustments.show', $f['adjustment']),
            'stock-issues.show' => route('stock-issues.show', $f['issue']),
            'stock-transforms.labels' => route('stock-transforms.labels', $f['transform']),
            'stock-transforms.show' => route('stock-transforms.show', $f['transform']),
            'stock-counts.show' => route('stock-counts.show', $f['count']),
            'fixed-assets.show' => route('fixed-assets.show', $f['fixedAsset']),
            'customers.show' => route('customers.show', $f['customer']),
            'suppliers.show' => route('suppliers.show', $f['supplier']),
            'basic-master.index' => route('basic-master.index', 'product-categories'),
            'documents.delivery-note' => route('documents.delivery-note', $f['creditSale']),
            'documents.tax-invoice' => route('documents.tax-invoice', $f['creditSale']),
            'price-tables.show' => route('price-tables.show', $f['priceTable']),
            'price-tables.search-products' => route('price-tables.search-products', $f['priceTable']),
            'scale-prices.plans.labels' => route('scale-prices.plans.labels', $f['scalePlanId']),
            'flash-sales.show' => route('flash-sales.show', $f['flashSale']),
            'flash-sales.search-products' => route('flash-sales.search-products', $f['flashSale']),
            'price-tags.edit' => route('price-tags.edit', $f['priceTag']),
            'management-controls.payroll.show' => route('management-controls.payroll.show', $f['payrollRunId']),
            'management-controls.payroll.payslip' => route('management-controls.payroll.payslip', $f['payrollItemId']),
            'management-controls.budgets.show' => route('management-controls.budgets.show', $f['budgetId']),
            'pos.shift.z-report' => route('pos.shift.z-report', $f['posShift']),
            'wh.products.detail' => route('wh.products.detail', $f['product']),
            'wh.purchase-orders.detail' => route('wh.purchase-orders.detail', $f['purchaseOrder']),
            'ocr.documents.show' => route('ocr.documents.show', $f['ocrDoc']),
            default => null,
        };
    }

    public function test_every_record_page_opens_without_5xx_for_all_roles(): void
    {
        $gmRole = Role::where('code', 'GM')->firstOrFail();
        $salesRole = Role::where('code', 'SALES')->firstOrFail();
        $warehouseRole = Role::where('code', 'WAREHOUSE')->firstOrFail();

        $gmUser = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $gmUser->roles()->attach($gmRole);

        $salesUser = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $salesUser->roles()->attach($salesRole);

        $whUser = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $whUser->roles()->attach($warehouseRole);

        $rolesToTest = [
            'GM' => $gmUser,
            'SALES' => $salesUser,
            'WAREHOUSE' => $whUser,
        ];

        $testedRoutes = 0;
        $serverErrors = [];
        $forbiddenCounts = [
            'GM' => 0,
            'SALES' => 0,
            'WAREHOUSE' => 0,
        ];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! str_contains($route->uri(), '{')) {
                continue;
            }

            $name = $route->getName();
            if (! $name || in_array($name, self::SKIP_ROUTE_NAMES, true)) {
                continue;
            }

            $url = $this->resolveParameterizedUrl($name);
            if (! $url) {
                continue;
            }

            $testedRoutes++;

            foreach ($rolesToTest as $roleCode => $user) {
                $response = $this->actingAs($user)->get($url);
                $status = $response->status();

                if ($status === 403) {
                    $forbiddenCounts[$roleCode]++;
                }

                if ($status >= 500) {
                    $serverErrors[] = "Role {$roleCode} got HTTP {$status} on route [{$name}] URL: {$url}";
                }
            }
        }

        $this->assertGreaterThan(35, $testedRoutes, 'ควรมีหน้าที่ต้องตรวจมากกว่า 35 หน้า');
        $this->assertEmpty($serverErrors, "พบหน้าที่มี Server Error (HTTP 5xx):\n".implode("\n", $serverErrors));
        $this->assertGreaterThan(20, $forbiddenCounts['SALES'], 'ผู้ใช้ SALES ต้องได้ 403 ในหน้าที่ไม่มีสิทธิ์');
        $this->assertGreaterThan(20, $forbiddenCounts['WAREHOUSE'], 'ผู้ใช้ WAREHOUSE ต้องได้ 403 ในหน้าที่ไม่มีสิทธิ์');
    }
}
