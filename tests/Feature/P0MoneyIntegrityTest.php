<?php

namespace Tests\Feature;

use App\Http\Controllers\FinancialStatementController;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\BranchExpenseService;
use App\Services\Accounting\GlPostingService;
use App\Services\Accounting\TaxComplianceService;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\PurchaseCostAdjustmentService;
use App\Services\Inventory\StockIssueService;
use App\Services\Inventory\StockTransferReceiptService;
use App\Services\Inventory\StockTransferService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\CashSaleService;
use App\Support\DecimalMath;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class P0MoneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function location(string $code): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => $code, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => $code, 'name' => $code]);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => $code, 'name' => $code]);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        return [$branch, $location];
    }

    private function purchase(int $qty = 10, int|string $cost = 10): array
    {
        [$branch, $location] = $this->location('P0HQ');
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'P0P', 'name_th' => 'P0', 'base_unit_id' => $unit->id,
            'default_price' => 20, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block']);
        $supplier = Supplier::create(['code' => 'P0SUP', 'name_th' => 'supplier', 'is_active' => true]);
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'purchase', 'stock_effect' => 'in']);
        $purchase = app(PurchaseService::class)->create(['branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'is_credit' => true, 'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $cost]]]);

        return [$purchase, $product, $branch, $location];
    }

    private function net(int $accountId): string
    {
        $rows = GlJournal::where('account_id', $accountId)->get();

        return DecimalMath::subtract(DecimalMath::sum($rows->pluck('debit')), DecimalMath::sum($rows->pluck('credit')), 2);
    }

    public function test_gl_reposting_preserves_rows_and_retries_and_void_have_correct_net_balances(): void
    {
        [$purchase] = $this->purchase();
        $gl = app(GlPostingService::class);
        $original = GlJournal::where('document_id', $purchase->id)->get()->keyBy('id')->toArray();
        $gl->postPurchase($purchase);
        $this->assertSame(count($original), GlJournal::where('document_id', $purchase->id)->count());
        $purchase->update(['total_amount' => '120.01', 'subtotal_amount' => '120.01', 'vat_amount' => 0]);
        $gl->postPurchase($purchase);
        $this->assertCount(count($original) * 3, GlJournal::where('document_id', $purchase->id)->get());
        $this->assertSame($original, GlJournal::whereIn('id', array_keys($original))->get()->keyBy('id')->toArray());
        $inventoryId = ChartOfAccount::where('default_role', 'inventory')->value('id');
        $this->assertSame('120.01', $this->net($inventoryId));
        $count = GlJournal::count();
        $gl->postPurchase($purchase);
        $this->assertSame($count, GlJournal::count());
        $gl->reverseDocument($purchase, 'cancel');
        $voidCount = GlJournal::count();
        $gl->reverseDocument($purchase, 'retry');
        $this->assertSame($voidCount, GlJournal::count());
        foreach (GlJournal::select('account_id')->distinct()->pluck('account_id') as $id) {
            $this->assertSame('0.00', $this->net($id));
        }
        foreach (['trial_balance', 'income_statement', 'balance_sheet'] as $sheet) {
            $view = app(FinancialStatementController::class)->index(
                Request::create('/financial-statements', 'GET', ['sheet' => $sheet]));
            foreach ($view->getData()['rows'] ?? $view->getData()['assets'] ?? [] as $row) {
                $this->assertEquals(0, $row->balance);
            }
        }
    }

    public function test_sales_repost_keeps_cogs_once_and_financial_statements_show_replacement_net(): void
    {
        [, $product, $branch] = $this->purchase();
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'sale', 'stock_effect' => 'out']);
        $sale = app(CashSaleService::class)->create(['branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 20]]]);
        $original = GlJournal::where('document_id', $sale->id)->get()->keyBy('id')->toArray();
        app(GlPostingService::class)->postCashSale($sale);
        $this->assertSame(count($original), GlJournal::where('document_id', $sale->id)->count());
        $sale->stockDocument->items()->update(['unit_price' => '21.01']);
        $sale->update(['total_amount' => '84.04']);
        app(GlPostingService::class)->postCashSale($sale);
        $this->assertSame($original, GlJournal::whereIn('id', array_keys($original))->get()->keyBy('id')->toArray());
        $this->assertSame('40.00', $this->net(ChartOfAccount::where('default_role', 'cogs')->value('id')));
        $view = app(FinancialStatementController::class)->index(
            Request::create('/financial-statements', 'GET', ['sheet' => 'income_statement']));
        $this->assertEquals(84.04, $view->getData()['totalRevenue']);
        $this->assertEquals(40, $view->getData()['totalExpense']);
        $this->assertEquals(44.04, $view->getData()['netProfit']);
    }

    public function test_closed_original_period_reposts_and_reverses_in_today_open_period(): void
    {
        [$purchase] = $this->purchase();
        $purchase->update(['doc_date' => now()->subMonth()->toDateString()]);
        $purchase->update(['total_amount' => '101.01', 'subtotal_amount' => '101.01']);
        AccountingPeriod::create(['name' => 'closed old', 'starts_on' => now()->subMonth()->startOfMonth(),
            'ends_on' => now()->subMonth()->endOfMonth(), 'status' => 'closed']);
        app(GlPostingService::class)->postPurchase($purchase);
        $this->assertSame(now()->toDateString(), DB::table('gl_posting_batches')->orderByDesc('id')->value('entry_date'));
        AccountingPeriod::create(['name' => 'closed now', 'starts_on' => now()->startOfMonth(), 'ends_on' => now()->endOfMonth(), 'status' => 'closed']);
        $count = GlJournal::count();
        try {
            app(GlPostingService::class)->reverseDocument($purchase, 'must reject');
            $this->fail('closed-period reversal accepted');
        } catch (ValidationException $e) {
            $this->assertSame($count, GlJournal::count());
        }
    }

    public function test_cost_adjustment_reconciles_remaining_stock_and_cogs_to_the_cent(): void
    {
        [$purchase, $product, $branch, $location] = $this->purchase();
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'sale', 'stock_effect' => 'out']);
        app(CashSaleService::class)->create(['branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 20]]]);
        $lot = StockLot::where('source_document_id', $purchase->id)->first();
        $apId = ChartOfAccount::where('default_role', 'ap')->value('id');
        $apBefore = $this->net($apId);
        $adjustments = app(PurchaseCostAdjustmentService::class)->adjust($purchase,
            [['stock_lot_id' => $lot->id, 'new_unit_cost' => '12.01']], 'invoice valuation correction', null);
        $this->assertCount(1, $adjustments);
        $this->assertSame('12.01000000', $lot->fresh()->unit_cost);
        $value = DecimalMath::sum(StockLot::all()->map(fn ($l) => DecimalMath::multiply($l->remaining_qty, $l->unit_cost)), 2);
        $this->assertSame('72.06', $value);
        $this->assertSame($value, $this->net(ChartOfAccount::where('default_role', 'inventory')->value('id')));
        $this->assertSame('48.04', $this->net(ChartOfAccount::where('default_role', 'cogs')->value('id')));
        $this->assertSame('-20.10', $this->net(ChartOfAccount::where('default_role', ChartOfAccount::ROLE_PURCHASE_COST_CLEARING)->value('id')));
        $this->assertSame($apBefore, $this->net($apId), 'valuation-only correction does not change supplier debt');
        app(PurchaseCostAdjustmentService::class)->adjust($purchase,
            [['stock_lot_id' => $lot->id, 'new_unit_cost' => '9.99']], 'decrease', null);
        $this->assertSame('59.94', $this->net(ChartOfAccount::where('default_role', 'inventory')->value('id')));
        $this->assertSame('39.96', $this->net(ChartOfAccount::where('default_role', 'cogs')->value('id')));
    }

    public function test_cost_adjustment_handles_crossing_a_half_cent_without_losing_a_cent(): void
    {
        [$purchase] = $this->purchase(1, '0.004');
        $lot = StockLot::where('source_document_id', $purchase->id)->first();
        $inventory = ChartOfAccount::where('default_role', 'inventory')->value('id');
        $this->assertSame('0.00', $this->net($inventory));
        app(PurchaseCostAdjustmentService::class)->adjust($purchase,
            [['stock_lot_id' => $lot->id, 'new_unit_cost' => '0.005']], 'half-cent boundary', null);
        $this->assertSame('0.01', $this->net($inventory));
        app(PurchaseCostAdjustmentService::class)->adjust($purchase,
            [['stock_lot_id' => $lot->id, 'new_unit_cost' => '0.004']], 'decrease across boundary', null);
        $this->assertSame('0.00', $this->net($inventory));
    }

    public function test_partial_transfer_retains_missing_lot_value_until_separate_damage_approval(): void
    {
        [$purchase, $product, $branch, $source] = $this->purchase();
        [$destinationBranch, $destination] = $this->location('P0SHOP');
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'transfer', 'stock_effect' => 'none']);
        DocumentType::firstOrCreate(['code' => 'STOCK_DAMAGE'], ['name_th' => 'damage', 'stock_effect' => 'out']);
        $transfer = app(StockTransferService::class)->create(['branch_id' => $branch->id,
            'from_warehouse_location_id' => $source->id, 'to_warehouse_location_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'qty' => 8]]]);
        $service = app(StockTransferReceiptService::class);
        $receipt = $service->openOrGet($transfer);
        $service->saveScans($receipt, [['id' => $receipt->items()->first()->id, 'scanned_qty' => 6]]);
        try {
            $service->complete($receipt);
            $this->fail('short receipt without evidence accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('checking', $receipt->fresh()->status);
        }
        $service->complete($receipt, '2 damaged; photo REF-001');
        $this->assertDatabaseHas('stock_balances', ['warehouse_location_id' => $destination->id, 'on_hand_qty' => 6]);
        $this->assertDatabaseHas('stock_balances', ['warehouse_location_id' => $transfer->stockDocument->transit_location_id, 'on_hand_qty' => 2]);
        $damage = Document::where('reference', $transfer->doc_number)->firstOrFail();
        $this->assertSame('pending_approval', $damage->status);
        $this->assertStringContainsString('REF-001', $damage->remark);
        $inventory = ChartOfAccount::where('default_role', 'inventory')->value('id');
        $this->assertSame('100.00', $this->net($inventory));
        app(StockIssueService::class)->approveDamage($damage);
        $this->assertSame('80.00', $this->net($inventory));
        $this->assertDatabaseHas('stock_balances', ['warehouse_location_id' => $transfer->stockDocument->transit_location_id, 'on_hand_qty' => 0]);
    }

    public function test_cost_adjustment_follows_transferred_lots_without_treating_transfers_as_sales(): void
    {
        [$purchase, $product, $branch, $source] = $this->purchase();
        [, $destination] = $this->location('P0SHOP');
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'transfer', 'stock_effect' => 'none']);
        app(StockTransferService::class)->create(['branch_id' => $branch->id,
            'from_warehouse_location_id' => $source->id, 'to_warehouse_location_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'qty' => 8]]]);
        $lot = StockLot::where('source_document_id', $purchase->id)->first();
        $adjustment = app(PurchaseCostAdjustmentService::class)->adjust($purchase,
            [['stock_lot_id' => $lot->id, 'new_unit_cost' => '12.01']], 'correct cost', null)->first();
        $this->assertSame('0.00000000', $adjustment->consumed_qty);
        $this->assertSame('120.10', $this->net(ChartOfAccount::where('default_role', 'inventory')->value('id')));
        foreach (StockLot::all() as $l) {
            $this->assertSame('12.01000000', $l->unit_cost);
        }
    }

    public function test_legacy_received_transfer_is_evidence_only_and_does_not_move_stock_again(): void
    {
        [, $product, $branch, $source] = $this->purchase();
        [, $destination] = $this->location('P0SHOP');
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'transfer', 'stock_effect' => 'none']);
        $transfer = app(StockTransferService::class)->createRequest(['branch_id' => $branch->id,
            'from_warehouse_location_id' => $source->id, 'to_warehouse_location_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'qty' => 8]]]);
        DB::transaction(function () use ($transfer, $product, $source, $destination) {
            app(FifoStockService::class)->issue($product->id, $source->id, 8, $transfer->id, 'transfer_out');
            app(FifoStockService::class)->receive($product->id, $destination->id, 8, $transfer->id, 'transfer_in', unitCost: 10);
            $transfer->update(['status' => 'active']);
        });
        $before = DB::table('stock_movements')->count();
        $service = app(StockTransferReceiptService::class);
        $receipt = $service->openOrGet($transfer);
        $service->saveScans($receipt, [['id' => $receipt->items()->first()->id, 'scanned_qty' => 8]]);
        $service->complete($receipt);
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('stock_balances', ['warehouse_location_id' => $destination->id, 'on_hand_qty' => 8]);
        $this->assertNull($transfer->stockDocument->transit_location_id);
    }

    public function test_new_migrations_compile_postgresql_ddl_without_connecting_or_updating_old_data(): void
    {
        $connection = new PostgresConnection(function () {
            throw new \RuntimeException('DDL verification must never connect');
        }, 'unused', '', ['version' => '16.0']);
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            foreach (['2026_11_06_100001_add_transfer_transit_location.php', '2026_11_06_100002_create_gl_posting_batches.php'] as $file) {
                $migration = require database_path('migrations/'.$file);
                $queries = $connection->pretend(fn () => $migration->up());
                $sql = strtolower(implode("\n", array_column($queries, 'query')));
                $this->assertNotEmpty($sql);
                foreach (['update ', 'delete ', 'insert '] as $mutation) {
                    $this->assertStringNotContainsString($mutation, $sql);
                }
                $down = $connection->pretend(fn () => $migration->down());
                $this->assertNotEmpty($down);
            }
        } finally {
            Schema::swap($original);
        }
    }

    public function test_tax_filing_excludes_cancelled_and_draft_expense_vat_and_withholding(): void
    {
        Storage::fake('local');
        [, , $branch] = $this->purchase();
        DocumentType::firstOrCreate(['code' => 'EXPENSE'], ['name_th' => 'expense', 'stock_effect' => 'none']);
        $account = ChartOfAccount::where('default_role', 'expense')->firstOrFail();
        foreach (['active', 'cancelled', 'draft'] as $status) {
            $expense = app(BranchExpenseService::class)->create([
                'branch_id' => $branch->id, 'expense_account_id' => $account->id,
                'expense_date' => now()->toDateString(), 'supplier_name' => 'P0 supplier',
                'description' => 'tax status test', 'base_amount' => 100, 'vat_amount' => 7,
                'withholding_rate' => 3, 'withholding_amount' => 3, 'withholding_form' => 'PND3', 'payment_method' => 'cash',
            ]);
            $expense->document->update(['status' => $status]);
        }
        $service = app(TaxComplianceService::class);
        $vat = $service->prepareFiling(now()->format('Y-m'), $branch->id, 'PP30');
        $wht = $service->prepareFiling(now()->format('Y-m'), $branch->id, 'PND3');
        $this->assertSame(1, $vat->document_count);
        $this->assertSame('-7.00', DecimalMath::round($vat->tax_amount, 2));
        $this->assertSame(1, $wht->document_count);
        $this->assertSame('3.00', DecimalMath::round($wht->tax_amount, 2));
    }

    public function test_tax_filing_and_etax_exclude_every_non_active_document(): void
    {
        Storage::fake('local');
        [$purchase, , $branch] = $this->purchase();
        $purchase->update(['claim_input_vat' => true, 'subtotal_amount' => '100.01', 'vat_amount' => '7.01']);
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'sale', 'stock_effect' => 'out']);
        $type = DocumentType::where('code', 'CASH_SALE')->value('id');
        foreach (['draft', 'pending', 'rejected', 'cancelled', 'void'] as $status) {
            $copy = $purchase->replicate();
            $copy->doc_number = 'P0P-'.$status;
            $copy->status = $status;
            $copy->save();
            Document::create(['document_type_id' => $type, 'branch_id' => $branch->id,
                'doc_number' => 'P0S-'.$status, 'doc_date' => now()->toDateString(), 'status' => $status,
                'subtotal_amount' => '100.01', 'vat_amount' => '7.01', 'total_amount' => '107.02']);
        }
        $service = app(TaxComplianceService::class);
        $run = $service->prepareFiling(now()->format('Y-m'), $branch->id, 'PP30');
        $this->assertSame(1, $run->document_count);
        $this->assertSame('-7.01', DecimalMath::round($run->tax_amount, 2));
        $this->assertSame(0, $service->prepareEtax(now()->format('Y-m'), $branch->id));
    }
}
