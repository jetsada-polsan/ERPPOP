<?php

use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BillingNoteController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BplusOperationController;
use App\Http\Controllers\CashSaleController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\CreditDebitNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\DiscountCardController;
use App\Http\Controllers\DocumentBookController;
use App\Http\Controllers\DocumentBrowserController;
use App\Http\Controllers\EcommerceChannelController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FinancialStatementController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\FlashSaleController;
use App\Http\Controllers\GlJournalController;
use App\Http\Controllers\LegacyReportController;
use App\Http\Controllers\LineIntegrationController;
use App\Http\Controllers\ManagementControlController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberPointController;
use App\Http\Controllers\MonthlyAccountingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrganizationalUnitController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosImportController;
use App\Http\Controllers\PosReleaseController;
use App\Http\Controllers\PriceTableController;
use App\Http\Controllers\PriceTagController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductUnitController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\QtyPromotionController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\ScalePriceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockIssueController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockTransformController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TaxComplianceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseLocationController;
use App\Http\Controllers\WarehouseMobileController;
use Illuminate\Support\Facades\Route;

// Auth: ทุก route ถูกคุมโดย ErpAuthorize middleware (ดู bootstrap/app.php +
// App\Support\RoutePermissions สำหรับ mapping เมนู -> สิทธิ์)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::get('/mfa/challenge', [AuthController::class, 'showMfaChallenge'])->name('mfa.challenge');
Route::post('/mfa/challenge', [AuthController::class, 'verifyMfaChallenge'])->name('mfa.verify');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/download/pos', function () {
    $manifestPath = storage_path('app/pos-releases/latest.json');
    $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $releaseUrl = data_get($manifest, 'platforms.windows-x86_64.url');
    if ($releaseUrl) {
        return redirect()->away($releaseUrl);
    }

    $installer = public_path('downloads/POPSTAR-POS-Setup.exe');
    abort_unless(is_file($installer), 404);

    return response()->download($installer, 'POPSTAR-POS-Setup.exe', [
        'Content-Type' => 'application/vnd.microsoft.portable-executable',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->name('pos.download');
Route::get('/download/pos/latest.json', [PosReleaseController::class, 'latest'])->name('pos.release.latest');
Route::get('/download/pos/releases/{filename}', [PosReleaseController::class, 'download'])->name('pos.release.download');
Route::get('/password/change', [AuthController::class, 'showChangePassword'])->name('password.change');
Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('password.update');
Route::get('/security/mfa', [AuthController::class, 'showMfaSetup'])->name('mfa.setup');
Route::post('/security/mfa', [AuthController::class, 'enableMfa'])->name('mfa.enable');
Route::delete('/security/mfa', [AuthController::class, 'disableMfa'])->name('mfa.disable');

Route::get('/', fn () => redirect()->route('dashboard'));
Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::prefix('organizational-units')->name('organizational-units.')->group(function () {
    Route::get('/', [OrganizationalUnitController::class, 'index'])->name('index');
    Route::post('/', [OrganizationalUnitController::class, 'store'])->name('store');
    Route::put('/{organizationalUnit}', [OrganizationalUnitController::class, 'update'])->name('update');
    Route::delete('/{organizationalUnit}', [OrganizationalUnitController::class, 'destroy'])->name('destroy');
    Route::post('/assignments', [OrganizationalUnitController::class, 'assign'])->name('assign');
    Route::delete('/assignments/{assignment}', [OrganizationalUnitController::class, 'removeAssignment'])->name('assignments.destroy');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::prefix('management-controls')->name('management-controls.')->group(function () {
    Route::get('/', [ManagementControlController::class, 'index'])->name('index');
    Route::post('/cost-centers', [ManagementControlController::class, 'storeCostCenter'])->name('cost-centers.store');
    Route::post('/budgets', [ManagementControlController::class, 'storeBudget'])->name('budgets.store');
    Route::post('/purchase-plans/generate', [ManagementControlController::class, 'generatePurchasePlan'])->name('purchase-plans.generate');
    Route::post('/attendance', [ManagementControlController::class, 'storeAttendance'])->name('attendance.store');
    Route::post('/payroll/generate', [ManagementControlController::class, 'generatePayroll'])->name('payroll.generate');
    Route::post('/ecommerce/orders', [ManagementControlController::class, 'importEcommerceOrder'])->name('ecommerce.orders.store');
});

// กระดิ่งแจ้งเตือน header: รายการตามภาระหน้าที่ของผู้ใช้ (ทุกคนที่ล็อกอินเรียกได้
// ตัวกรองสิทธิ์อยู่ใน NotificationService)
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

// POS: full-screen selling interface, separate layout from ERP backend.
Route::prefix('pos')->name('pos.')->group(function () {
    Route::get('/', [PosController::class, 'index'])->name('index');
    Route::get('/products', [PosController::class, 'products'])->name('products');
    Route::get('/members', [PosController::class, 'members'])->name('members');
    Route::get('/promotions', [PosController::class, 'promotions'])->name('promotions');
    Route::get('/shift', [PosController::class, 'activeShift'])->name('shift.active');
    Route::post('/shift/open', [PosController::class, 'openShift'])->name('shift.open');
    Route::post('/shift/close', [PosController::class, 'closeShift'])->name('shift.close');
    Route::post('/receipts/{receipt}/void', [PosController::class, 'voidReceipt'])->name('receipts.void');
    Route::get('/checkout', fn () => redirect()->route('pos.index'))->name('checkout.redirect');
    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
});
// คลังมือถือ: หน้าเดียวสำหรับมือถือ/PDA — รับเข้า (ใบซื้อ/รับตาม PO) + เช็คสต๊อก
Route::prefix('wh')->name('wh.')->group(function () {
    Route::get('/', [WarehouseMobileController::class, 'index'])->name('index');
    Route::get('/lookup', [WarehouseMobileController::class, 'lookup'])->name('lookup');
    Route::get('/products/{product}', [WarehouseMobileController::class, 'productDetail'])->name('products.detail');
    Route::get('/stock', [WarehouseMobileController::class, 'stock'])->name('stock');
    Route::post('/receive', [WarehouseMobileController::class, 'receiveStore'])->name('receive');
    Route::get('/purchase-orders', [WarehouseMobileController::class, 'purchaseOrders'])->name('purchase-orders');
    Route::get('/purchase-orders/{purchaseOrder}', [WarehouseMobileController::class, 'purchaseOrderDetail'])->name('purchase-orders.detail');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [WarehouseMobileController::class, 'purchaseOrderReceive'])->name('purchase-orders.receive');
});

Route::get('/features', [FeatureController::class, 'index'])->name('features.index');
Route::get('/core-modules', [ManualController::class, 'index'])->name('core-modules.index');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/legacy-reports', [LegacyReportController::class, 'index'])->name('legacy-reports.index');

// Typeahead search (Alpine.js pickers on the booking/sale/purchase forms).
Route::get('/search/customers', [SearchController::class, 'customers'])->name('search.customers');
Route::get('/search/products', [SearchController::class, 'products'])->name('search.products');
Route::get('/search/suppliers', [SearchController::class, 'suppliers'])->name('search.suppliers');
Route::get('/search/salesmen', [SearchController::class, 'salesmen'])->name('search.salesmen');
Route::get('/search/branches', [SearchController::class, 'branches'])->name('search.branches');
Route::get('/search/stock-balance', [SearchController::class, 'stockBalance'])->name('search.stock-balance');

// Booking (ใบจอง): reserves stock for a customer. "แปลงเป็นใบขายเชื่อ" converts a
// pending booking into a real credit sale (see SaleController for the result).
Route::prefix('bookings')->name('bookings.')->group(function () {
    Route::get('/', [BookingController::class, 'index'])->name('index');
    Route::get('/create', [BookingController::class, 'create'])->name('create');
    Route::post('/', [BookingController::class, 'store'])->name('store');
    Route::get('/legacy/{diKey}', [BookingController::class, 'legacyShow'])->name('legacy-show');
    Route::get('/{booking}', [BookingController::class, 'show'])->name('show');
    Route::post('/{booking}/convert', [BookingController::class, 'convert'])->name('convert');
});

// Cash sale (ใบขายสด): direct stock-out without booking or AR.
Route::prefix('cash-sales')->name('cash-sales.')->group(function () {
    Route::get('/', [CashSaleController::class, 'index'])->name('index');
    Route::post('/', [CashSaleController::class, 'store'])->name('store');
    Route::get('/{cashSale}', [CashSaleController::class, 'show'])->name('show');
});

// Sale return (ใบรับคืนสินค้า): reverses a sale, restores stock, optionally reduces AR.
Route::prefix('sale-returns')->name('sale-returns.')->group(function () {
    Route::get('/', [SaleReturnController::class, 'index'])->name('index');
    Route::post('/', [SaleReturnController::class, 'store'])->name('store');
    Route::get('/{saleReturn}', [SaleReturnController::class, 'show'])->name('show');
});

// Payment receipt / voucher show page.
Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
Route::get('/payments/{payment}/print', [PaymentController::class, 'print'])->name('payments.print');

// ใบเสนอราคา (Quotation): เสนอราคาก่อนขาย พิมพ์ได้ แปลงเป็นใบจองต่อได้
Route::prefix('quotations')->name('quotations.')->group(function () {
    Route::get('/', [QuotationController::class, 'index'])->name('index');
    Route::post('/', [QuotationController::class, 'store'])->name('store');
    Route::get('/{quotation}', [QuotationController::class, 'show'])->name('show');
    Route::post('/{quotation}/status', [QuotationController::class, 'updateStatus'])->name('status');
    Route::post('/{quotation}/convert', [QuotationController::class, 'convert'])->name('convert');
});

// ใบลดหนี้ / ใบเพิ่มหนี้ (Credit/Debit Note): ปรับยอดหนี้ลูกค้าแบบการเงินล้วน
Route::prefix('credit-debit-notes')->name('credit-debit-notes.')->group(function () {
    Route::get('/', [CreditDebitNoteController::class, 'index'])->name('index');
    Route::post('/', [CreditDebitNoteController::class, 'store'])->name('store');
    Route::get('/customers/{customer}/open-items', [CreditDebitNoteController::class, 'openItems'])->name('open-items');
    Route::post('/{note}/approve', [CreditDebitNoteController::class, 'approve'])->name('approve');
    Route::post('/{note}/reject', [CreditDebitNoteController::class, 'reject'])->name('reject');
    Route::get('/{note}/print', [CreditDebitNoteController::class, 'print'])->name('print');
});

// ใบวางบิล (Billing Note): รวบใบขายเชื่อค้างชำระเป็นใบวางบิลรอบเก็บเงิน
Route::prefix('billing-notes')->name('billing-notes.')->group(function () {
    Route::get('/', [BillingNoteController::class, 'index'])->name('index');
    Route::post('/', [BillingNoteController::class, 'store'])->name('store');
    Route::get('/customers/{customer}/open-items', [BillingNoteController::class, 'openItems'])->name('open-items');
    Route::get('/{billingNote}', [BillingNoteController::class, 'show'])->name('show');
    Route::post('/{billingNote}/status', [BillingNoteController::class, 'updateStatus'])->name('status');
});

// ทะเบียนเช็ครับ-จ่าย (FN): สร้างอัตโนมัติจากการรับ/จ่ายชำระด้วยเช็ค
// สถานะ: ในมือ -> นำฝาก -> ผ่าน | คืน (รับ) / ออกเช็ค -> ตัดบัญชี (จ่าย)
Route::prefix('cheques')->name('cheques.')->group(function () {
    Route::get('/', [ChequeController::class, 'index'])->name('index');
    Route::post('/', [ChequeController::class, 'store'])->name('store');
    Route::post('/{cheque}/deposit', [ChequeController::class, 'deposit'])->name('deposit');
    Route::post('/{cheque}/clear', [ChequeController::class, 'clear'])->name('clear');
    Route::post('/{cheque}/bounce', [ChequeController::class, 'bounce'])->name('bounce');
    Route::post('/{cheque}/cancel', [ChequeController::class, 'cancel'])->name('cancel');
});

// Credit sale (ใบขายเชื่อ): read-only view of a posted sale document (cuts stock,
// opens an AR entry) - created only via BookingController::convert for now.
Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
Route::get('/sales/{sale}/print', [SaleController::class, 'print'])->name('sales.print');

// Purchase (ใบซื้อ): receives stock from a supplier. Credit purchases also open
// an AP debt (supplier_ledger). The "stock IN" counterpart to bookings/sales.
// ใบขอซื้อ/ใบสั่งซื้อ (AP ต้นน้ำ): ขอซื้อ -> อนุมัติ -> สั่งซื้อ -> รับของ (สร้างใบซื้อจริง)
Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
    Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
    Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('show');
    Route::get('/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->name('print');
    Route::post('/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('approve');
    Route::post('/{purchaseOrder}/order', [PurchaseOrderController::class, 'order'])->name('order');
    Route::post('/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('receive');
    Route::post('/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
});

Route::prefix('purchases')->name('purchases.')->group(function () {
    Route::get('/', [PurchaseController::class, 'index'])->name('index');
    Route::get('/create', [PurchaseController::class, 'create'])->name('create');
    Route::post('/', [PurchaseController::class, 'store'])->name('store');
    Route::get('/{purchase}', [PurchaseController::class, 'show'])->name('show');
});

// Product master data (สินค้า/หน่วยนับ): the read-only ETL'd catalog is now editable
// here too - new products created in-app live alongside the BPlus-imported ones.
Route::prefix('products')->name('products.')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::post('/', [ProductController::class, 'store'])->name('store');
    Route::get('/{product}', [ProductController::class, 'show'])->name('show');
    Route::put('/{product}', [ProductController::class, 'update'])->name('update');
    Route::get('/{product}/lots/{stockLot}/trace', [ProductController::class, 'lotTrace'])->name('lots.trace');
    Route::put('/{product}/lots/{stockLot}/quality', [ProductController::class, 'updateLotQuality'])->name('lots.quality');
    Route::post('/{product}/lots/{stockLot}/quality-checks', [ProductController::class, 'storeLotQualityCheck'])->name('lots.quality-checks.store');
    Route::post('/{product}/lots/{stockLot}/recalls', [ProductController::class, 'openRecall'])->name('lots.recalls.store');
    Route::put('/recall-contacts/{recallContact}', [ProductController::class, 'updateRecallContact'])->name('recall-contacts.update');
    Route::post('/{product}/barcodes', [ProductController::class, 'addBarcode'])->name('barcodes.store');
    Route::put('/{product}/barcodes/{productBarcode}', [ProductController::class, 'updateBarcode'])->name('barcodes.update');
    Route::post('/{product}/prices', [ProductController::class, 'upsertPrice'])->name('prices.upsert');
    Route::post('/{product}/suppliers', [ProductController::class, 'upsertSupplier'])->name('suppliers.upsert');
    Route::delete('/{product}/suppliers/{productSupplier}', [ProductController::class, 'removeSupplier'])->name('suppliers.destroy');
});

// Stock transfer (โอนย้ายสต็อก) and stock count adjustment (ตรวจนับสต็อก) - both
// touch stock_balances directly without going through a customer/supplier.
Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
    Route::get('/', [StockTransferController::class, 'index'])->name('index');
    // ขอโอน (พนักงานสาขา, stock.request) - ต้องมาก่อน /{stockTransfer}
    Route::get('/request', [StockTransferController::class, 'requestForm'])->name('request');
    Route::post('/request', [StockTransferController::class, 'requestStore'])->name('request.store');
    Route::post('/', [StockTransferController::class, 'store'])->name('store');
    Route::post('/{stockTransfer}/approve', [StockTransferController::class, 'approve'])->name('approve');
    Route::post('/{stockTransfer}/reject', [StockTransferController::class, 'reject'])->name('reject');
    Route::get('/{stockTransfer}', [StockTransferController::class, 'show'])->name('show');
});

Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
    Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
    Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
    Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('show');
    Route::post('/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('approve');
    Route::post('/{stockAdjustment}/reject', [StockAdjustmentController::class, 'reject'])->name('reject');
});

// Stock issues (งานคลังสินค้าตามคู่มือ BPlus บทที่ 5): ใบเบิก (DR ตัดสต๊อก),
// ใบคืนสินค้าจากการเบิก (IR รับกลับ), ใบตัดสินค้าชำรุด (DD ตัดของเสีย).
Route::prefix('stock-issues')->name('stock-issues.')->group(function () {
    Route::get('/', [StockIssueController::class, 'index'])->name('index');
    Route::post('/', [StockIssueController::class, 'store'])->name('store');
    Route::get('/{stockIssue}', [StockIssueController::class, 'show'])->name('show');
    Route::post('/{stockIssue}/approve', [StockIssueController::class, 'approve'])->name('approve');
    Route::post('/{stockIssue}/reject', [StockIssueController::class, 'reject'])->name('reject');
});

// Stock transforms (ใบแปรรูปสินค้า DT): consume raw materials, receive outputs
// with % cost allocation.
Route::prefix('stock-transforms')->name('stock-transforms.')->group(function () {
    Route::get('/', [StockTransformController::class, 'index'])->name('index');
    Route::post('/', [StockTransformController::class, 'store'])->name('store');
    Route::post('/{stockTransform}/packages', [StockTransformController::class, 'addPackages'])->name('packages.store');
    Route::get('/{stockTransform}/labels', [StockTransformController::class, 'labels'])->name('labels');
    Route::get('/{stockTransform}', [StockTransformController::class, 'show'])->name('show');
});

// Stock counts (ใบตรวจนับสินค้า): per-branch counting sheet - snapshot system
// qty, key in / export-import counted qty via CSV, post diffs as an adjustment.
Route::prefix('stock-counts')->name('stock-counts.')->group(function () {
    Route::get('/', [StockCountController::class, 'index'])->name('index');
    Route::post('/', [StockCountController::class, 'store'])->name('store');
    Route::get('/{stockCount}', [StockCountController::class, 'show'])->name('show');
    Route::post('/{stockCount}/items', [StockCountController::class, 'saveItems'])->name('items.save');
    Route::post('/{stockCount}/submit', [StockCountController::class, 'submit'])->name('submit');
    Route::get('/{stockCount}/export', [StockCountController::class, 'export'])->name('export');
    Route::post('/{stockCount}/import', [StockCountController::class, 'import'])->name('import');
    Route::post('/{stockCount}/post', [StockCountController::class, 'post'])->name('post');
});

// Chart of accounts (ผังบัญชี): plain master data, not yet wired into any
// posting flow (gl_journals exists but nothing writes to it yet).
Route::prefix('chart-of-accounts')->name('chart-of-accounts.')->group(function () {
    Route::get('/', [ChartOfAccountController::class, 'index'])->name('index');
    Route::post('/', [ChartOfAccountController::class, 'store'])->name('store');
    Route::post('/import', [ChartOfAccountController::class, 'import'])->name('import');
    Route::put('/{chartOfAccount}', [ChartOfAccountController::class, 'update'])->name('update');
});

// General journal (สมุดรายวันทั่วไป): read-only view of gl_journals, auto-posted
// by GlPostingService whenever a customer/supplier payment is recorded.
Route::get('/gl-journals', [GlJournalController::class, 'index'])->name('gl-journals.index');

// งบการเงิน: งบทดลอง / กำไรขาดทุน / งบดุล จาก gl_journals
Route::get('/financial-statements', [FinancialStatementController::class, 'index'])->name('financial-statements.index');

// FA ทะเบียนทรัพย์สิน + ค่าเสื่อมราคา (เส้นตรงรายเดือน)
Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
    Route::get('/', [FixedAssetController::class, 'index'])->name('index');
    Route::post('/', [FixedAssetController::class, 'store'])->name('store');
    Route::post('/depreciate', [FixedAssetController::class, 'runDepreciation'])->name('depreciate');
    Route::get('/{fixedAsset}', [FixedAssetController::class, 'show'])->name('show');
    Route::post('/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->name('dispose');
});

Route::prefix('product-units')->name('product-units.')->group(function () {
    Route::get('/', [ProductUnitController::class, 'index'])->name('index');
    Route::post('/', [ProductUnitController::class, 'store'])->name('store');
    Route::put('/{productUnit}', [ProductUnitController::class, 'update'])->name('update');
});

// Customer master data (ลูกค้า): editable here; addresses/contacts feed the
// booking/sale forms, openItems shows their AR (customer_open_items) history.
Route::prefix('customers')->name('customers.')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
    Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
    Route::post('/{customer}/addresses', [CustomerController::class, 'addAddress'])->name('addresses.store');
    Route::post('/{customer}/contacts', [CustomerController::class, 'addContact'])->name('contacts.store');
    Route::post('/{customer}/payments', [PaymentController::class, 'storeCustomerPayment'])->name('payments.store');
    // เปลี่ยนวงเงินเครดิตต้องอนุมัติ (finance.credit.approve) - ผู้ขอกดเองไม่ได้
    Route::post('/{customer}/credit-limit/approve', [CustomerController::class, 'approveCreditLimit'])->name('credit-limit.approve');
    Route::post('/{customer}/credit-limit/reject', [CustomerController::class, 'rejectCreditLimit'])->name('credit-limit.reject');
});

// Supplier master data (ซัพพลายเออร์): editable here; ledgerEntries shows their
// AP (supplier_ledger) history, built up by the purchases module.
Route::prefix('suppliers')->name('suppliers.')->group(function () {
    Route::get('/', [SupplierController::class, 'index'])->name('index');
    Route::post('/', [SupplierController::class, 'store'])->name('store');
    Route::get('/{supplier}', [SupplierController::class, 'show'])->name('show');
    Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update');
    Route::post('/{supplier}/addresses', [SupplierController::class, 'addAddress'])->name('addresses.store');
    Route::post('/{supplier}/payments', [PaymentController::class, 'storeSupplierPayment'])->name('payments.store');
});

// Master data management: salesmen, warehouse locations, bank accounts
Route::prefix('salesmen')->name('salesmen.')->group(function () {
    Route::get('/', [SalesmanController::class, 'index'])->name('index');
    Route::post('/', [SalesmanController::class, 'store'])->name('store');
    Route::put('/{salesman}', [SalesmanController::class, 'update'])->name('update');
});

Route::prefix('warehouse-locations')->name('warehouse-locations.')->group(function () {
    Route::get('/', [WarehouseLocationController::class, 'index'])->name('index');
    Route::post('/', [WarehouseLocationController::class, 'store'])->name('store');
    Route::put('/{warehouseLocation}', [WarehouseLocationController::class, 'update'])->name('update');
});

Route::prefix('bank-accounts')->name('bank-accounts.')->group(function () {
    Route::get('/', [BankAccountController::class, 'index'])->name('index');
    Route::post('/', [BankAccountController::class, 'store'])->name('store');
    Route::put('/{bankAccount}', [BankAccountController::class, 'update'])->name('update');
});

Route::prefix('accounting-periods')->name('accounting-periods.')->group(function () {
    Route::get('/', [AccountingPeriodController::class, 'index'])->name('index');
    Route::post('/', [AccountingPeriodController::class, 'store'])->name('store');
    Route::post('/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])->name('close');
    Route::post('/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])->name('reopen');
});

Route::prefix('monthly-accounting')->name('monthly-accounting.')->group(function () {
    Route::get('/', [MonthlyAccountingController::class, 'index'])->name('index');
    Route::post('/expenses', [MonthlyAccountingController::class, 'storeExpense'])->name('expenses.store');
    Route::post('/statements/import', [MonthlyAccountingController::class, 'importStatement'])->name('statements.import');
    Route::post('/statements/{bankStatement}/reconcile', [MonthlyAccountingController::class, 'reconcile'])->name('statements.reconcile');
    Route::post('/statements/auto-reconcile', [MonthlyAccountingController::class, 'autoReconcile'])->name('statements.auto-reconcile');
    Route::post('/export', [MonthlyAccountingController::class, 'export'])->name('export');
    Route::get('/exports/{run}', [MonthlyAccountingController::class, 'downloadRun'])->name('exports.download');
});

Route::prefix('tax-compliance')->name('tax-compliance.')->group(function () {
    Route::get('/', [TaxComplianceController::class, 'index'])->name('index');
    Route::post('/filings', [TaxComplianceController::class, 'prepare'])->name('filings.prepare');
    Route::post('/filings/{run}/review', [TaxComplianceController::class, 'review'])->name('filings.review');
    Route::post('/filings/{run}/submit', [TaxComplianceController::class, 'submit'])->name('filings.submit');
    Route::get('/filings/{run}/download', [TaxComplianceController::class, 'download'])->name('filings.download');
    Route::post('/etax', [TaxComplianceController::class, 'prepareEtax'])->name('etax.prepare');
    Route::post('/etax/{etaxDocument}/status', [TaxComplianceController::class, 'updateEtax'])->name('etax.status');
});

Route::prefix('operations')->name('operations.')->group(function () {
    Route::get('/', [OperationsController::class, 'index'])->name('index');
    Route::post('/backup', [OperationsController::class, 'backup'])->name('backup');
    Route::post('/restore-verify', [OperationsController::class, 'verifyRestore'])->name('restore-verify');
    Route::post('/users/{user}/mfa-reset', [OperationsController::class, 'resetMfa'])->name('mfa-reset');
});

Route::prefix('members')->name('members.')->group(function () {
    Route::get('/', [MemberController::class, 'index'])->name('index');
    Route::post('/', [MemberController::class, 'store'])->name('store');
    Route::put('/{member}', [MemberController::class, 'update'])->name('update');
});

Route::prefix('production')->name('production.')->group(function () {
    Route::get('/', [ProductionController::class, 'index'])->name('index');
    Route::post('/recipes', [ProductionController::class, 'storeRecipe'])->name('recipes.store');
    Route::put('/recipes/{recipe}', [ProductionController::class, 'updateRecipe'])->name('recipes.update');
    Route::post('/orders', [ProductionController::class, 'storeOrder'])->name('orders.store');
    Route::put('/orders/{order}', [ProductionController::class, 'updateOrder'])->name('orders.update');
    Route::post('/orders/{order}/receive', [ProductionController::class, 'receiveOrder'])->name('orders.receive');
});

// สมุดเอกสาร (Document Books): แยกเอกสารประเภทเดียวเป็นหลายเล่ม แต่ละเล่มเลขรันเอง
Route::prefix('document-books')->name('document-books.')->group(function () {
    Route::get('/', [DocumentBookController::class, 'index'])->name('index');
    Route::post('/', [DocumentBookController::class, 'store'])->name('store');
    Route::put('/{documentBook}', [DocumentBookController::class, 'update'])->name('update');
});

// ศูนย์เอกสารย้อนหลัง: ต้นไม้ ประเภท->ปี->เดือน + กรองรายวัน ทุกประเภทเอกสาร
Route::get('/documents', [DocumentBrowserController::class, 'index'])->name('documents.browser');
Route::get('/documents/legacy/{diKey}', [DocumentBrowserController::class, 'legacyShow'])->name('documents.legacy-show');

// ใบส่งของ/ใบส่งของชั่วคราว (A5): พิมพ์ได้จากใบจองและใบขายทุกชนิด
Route::get('/documents/{document}/delivery-note', [DeliveryNoteController::class, 'show'])->name('documents.delivery-note');
// ใบกำกับภาษีเต็มรูปแบบ A4 (ขายสด/ขายเชื่อ) ตามหลัก 9 จุดของสรรพากร
Route::get('/documents/{document}/tax-invoice', [DeliveryNoteController::class, 'taxInvoice'])->name('documents.tax-invoice');

// Users & roles (ผู้ใช้และสิทธิ์แบบ BPlus)
Route::prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::put('/{user}', [UserController::class, 'update'])->name('update');
});

// Price tables (ตารางราคา): named price lists assigned per branch so different
// branches can sell the same product at different prices.
Route::prefix('price-tables')->name('price-tables.')->group(function () {
    Route::get('/', [PriceTableController::class, 'index'])->name('index');
    Route::post('/', [PriceTableController::class, 'store'])->name('store');
    Route::get('/{priceTable}', [PriceTableController::class, 'show'])->name('show');
    Route::put('/{priceTable}', [PriceTableController::class, 'update'])->name('update');
    Route::post('/{priceTable}/prices', [PriceTableController::class, 'upsertPrice'])->name('prices.upsert');
    Route::post('/{priceTable}/assign-branch', [PriceTableController::class, 'assignBranch'])->name('assign-branch');
    Route::get('/{priceTable}/search-products', [PriceTableController::class, 'searchProducts'])->name('search-products');
});

// ราคาเครื่องชั่ง: หน้าเดียวจบสำหรับราคา/กก. ของสินค้าชั่ง (PLU 800xxx)
// ต้องตั้งให้ตรงกับเครื่องชั่งเสมอ — POS คำนวณน้ำหนักย้อนกลับจากราคานี้
Route::prefix('scale-prices')->name('scale-prices.')->group(function () {
    Route::get('/', [ScalePriceController::class, 'index'])->name('index');
    Route::post('/', [ScalePriceController::class, 'update'])->name('update');
    Route::post('/attach', [ScalePriceController::class, 'attachPlu'])->name('attach');
    Route::get('/export', [ScalePriceController::class, 'export'])->name('export');
});

Route::prefix('promotions')->name('promotions.')->group(function () {
    Route::get('/', [PromotionController::class, 'index'])->name('index');
    Route::post('/', [PromotionController::class, 'store'])->name('store');
    Route::put('/{promotion}', [PromotionController::class, 'update'])->name('update');
});

// Flash sale (ราคานาทีทอง): time-boxed special prices on specific products,
// picked up by the POS products endpoint while the campaign window is active.
Route::prefix('flash-sales')->name('flash-sales.')->group(function () {
    Route::get('/', [FlashSaleController::class, 'index'])->name('index');
    Route::post('/', [FlashSaleController::class, 'store'])->name('store');
    Route::get('/{flashSale}', [FlashSaleController::class, 'show'])->name('show');
    Route::put('/{flashSale}', [FlashSaleController::class, 'update'])->name('update');
    Route::post('/{flashSale}/items', [FlashSaleController::class, 'upsertItem'])->name('items.upsert');
    Route::delete('/{flashSale}/items/{item}', [FlashSaleController::class, 'destroyItem'])->name('items.destroy');
    Route::get('/{flashSale}/search-products', [FlashSaleController::class, 'searchProducts'])->name('search-products');
});

// Price tags (ป้ายราคา): named label styles (ราคาปกติ/ตารางราคา/นาทีทอง/ไม่มีราคา)
// used to print shelf labels for a chosen set of products.
Route::prefix('price-tags')->name('price-tags.')->group(function () {
    Route::get('/', [PriceTagController::class, 'index'])->name('index');
    Route::post('/', [PriceTagController::class, 'store'])->name('store');
    Route::put('/{priceTag}', [PriceTagController::class, 'update'])->name('update');
    Route::get('/print', [PriceTagController::class, 'print'])->name('print');
    Route::get('/search-products', [PriceTagController::class, 'searchProducts'])->name('search-products');
    Route::post('/preview', [PriceTagController::class, 'preview'])->name('preview');
});

// Discount cards (บัตรส่วนลด): cards a cashier scans at POS checkout to apply
// a fixed/percent discount to the whole bill.
Route::prefix('discount-cards')->name('discount-cards.')->group(function () {
    Route::get('/', [DiscountCardController::class, 'index'])->name('index');
    Route::post('/', [DiscountCardController::class, 'store'])->name('store');
    Route::put('/{discountCard}', [DiscountCardController::class, 'update'])->name('update');
    Route::post('/check', [DiscountCardController::class, 'check'])->name('check');
});

// Member points (แต้มทอง/แต้มทวีคูณ): earning rules + multiplier campaigns and
// the audit ledger. POS earns/redeems points at checkout via MemberPointService.
Route::prefix('member-points')->name('member-points.')->group(function () {
    Route::get('/', [MemberPointController::class, 'index'])->name('index');
    Route::post('/', [MemberPointController::class, 'store'])->name('store');
    Route::put('/{memberPointRule}', [MemberPointController::class, 'update'])->name('update');
});

// Qty promotions (แคมเปญซื้อจำนวนครบ): buy N get free item (ซื้อ 1 แถม 1) or
// buy N get percent/baht discount. POS auto-applies while the campaign runs.
Route::prefix('qty-promotions')->name('qty-promotions.')->group(function () {
    Route::get('/', [QtyPromotionController::class, 'index'])->name('index');
    Route::post('/', [QtyPromotionController::class, 'store'])->name('store');
    Route::put('/{qtyPromotion}', [QtyPromotionController::class, 'update'])->name('update');
});

// System settings (ตั้งค่าระบบ): logo + company info used across layouts and
// printed document headers.
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SystemSettingController::class, 'index'])->name('index');
    Route::post('/', [SystemSettingController::class, 'update'])->name('update');
    Route::post('/pos-token', [SystemSettingController::class, 'issuePosToken'])->name('pos-token.issue');
    Route::post('/pos-token/rotate', [SystemSettingController::class, 'rotatePosToken'])->name('pos-token.rotate');
    Route::post('/pos-release', [SystemSettingController::class, 'publishPosRelease'])->name('pos-release.publish');
});

Route::prefix('line-integrations')->name('line-integrations.')->group(function () {
    Route::get('/', [LineIntegrationController::class, 'index'])->name('index');
    Route::post('/', [LineIntegrationController::class, 'store'])->name('store');
    Route::put('/{lineIntegration}', [LineIntegrationController::class, 'update'])->name('update');
});

Route::prefix('ecommerce-channels')->name('ecommerce-channels.')->group(function () {
    Route::get('/', [EcommerceChannelController::class, 'index'])->name('index');
    Route::post('/', [EcommerceChannelController::class, 'store'])->name('store');
    Route::put('/{ecommerceChannel}', [EcommerceChannelController::class, 'update'])->name('update');
});

Route::prefix('bplus')->name('bplus.')->group(function () {
    Route::get('/pos-preparation', [BplusOperationController::class, 'posPreparation'])->name('pos-preparation');
    Route::post('/pos-preparation', [BplusOperationController::class, 'storePosPreparation'])->name('pos-preparation.store');
    Route::get('/pos-workbench', [BplusOperationController::class, 'posWorkbench'])->name('pos-workbench');
    Route::get('/purchase-planning', [BplusOperationController::class, 'purchasePlanning'])->name('purchase-planning');
    Route::post('/purchase-planning', [BplusOperationController::class, 'storePurchasePlan'])->name('purchase-planning.store');
    Route::get('/approvals', [BplusOperationController::class, 'approvals'])->name('approvals');
    Route::post('/approvals', [BplusOperationController::class, 'storeApproval'])->name('approvals.store');
    Route::get('/finance', [BplusOperationController::class, 'finance'])->name('finance');
    Route::post('/cash-books', [BplusOperationController::class, 'storeCashBook'])->name('cash-books.store');
    Route::get('/tax', [BplusOperationController::class, 'tax'])->name('tax');
    Route::post('/vat-rates', [BplusOperationController::class, 'storeVatRate'])->name('vat-rates.store');
    Route::get('/qr-payments', [BplusOperationController::class, 'qrPayments'])->name('qr-payments');
    Route::post('/qr-payments', [BplusOperationController::class, 'storeQrPayment'])->name('qr-payments.store');
    Route::put('/qr-payments/{qrPaymentConfig}', [BplusOperationController::class, 'updateQrPayment'])->name('qr-payments.update');
    Route::delete('/qr-payments/{qrPaymentConfig}', [BplusOperationController::class, 'destroyQrPayment'])->name('qr-payments.destroy');
    Route::get('/show-price', [BplusOperationController::class, 'showPrice'])->name('show-price');
    Route::post('/show-price', [BplusOperationController::class, 'storeShowPrice'])->name('show-price.store');
});

// POS Import module: review/confirm/post pipeline for batches synced from MSSQL
// via `php artisan pos-import:sync` (or the "sync now" form on the index page).
// Auth enforced globally by App\Http\Middleware\ErpAuthorize (see bootstrap/app.php):
// guests -> /login, permissions per module via App\Support\RoutePermissions.
Route::prefix('pos-import')->name('pos-import.')->group(function () {
    // JSON API (read-only) - kept for scripting/automation.
    Route::get('/batches', [PosImportController::class, 'index'])->name('batches.index');
    Route::get('/batches/{batch}', [PosImportController::class, 'show'])->name('batches.show');

    // Form actions (redirect back to the batch page with a flash message).
    Route::post('/upload', [PosImportController::class, 'upload'])->name('upload');
    Route::post('/sync', [PosImportController::class, 'sync'])->name('sync');
    Route::post('/batches/{batch}/revalidate', [PosImportController::class, 'revalidate'])->name('batches.revalidate');
    Route::post('/batches/{batch}/confirm', [PosImportController::class, 'confirm'])->name('batches.confirm');
    Route::post('/batches/{batch}/post', [PosImportController::class, 'post'])->name('batches.post');

    // Web pages - registered last so they don't shadow the /batches paths above.
    Route::get('/', [PosImportController::class, 'page'])->name('page');
    Route::get('/{batch}', [PosImportController::class, 'pageShow'])->name('batches.page-show');
});
