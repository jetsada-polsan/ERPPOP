<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ใบรับคืนต้องอ้างใบขายต้นทาง (เว้นแต่ผู้มีสิทธิ์พิเศษ) และคืนได้ไม่เกิน ขาย - คืนไปแล้ว
 */
class SaleReturnSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_without_a_source_sale_is_refused(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $this->signIn();

        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 1, customer: $customer))
            ->assertRedirect();

        $this->assertStringContainsString('ต้องอ้างอิงใบขายต้นทาง', (string) session('error'));
        $this->assertSame(0, $this->returns()->count());
    }

    public function test_returning_against_a_cash_sale_links_it_and_takes_its_customer(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();

        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 2, source: $sale->doc_number))
            ->assertRedirect();

        $return = $this->returns()->sole();
        $this->assertSame($sale->id, $return->source_document_id);
        $this->assertSame($customer->id, $return->customer_id);
        // ใบขายสดไม่มีลูกหนี้ reference ต้องว่าง ไม่อย่างนั้น approve() จะหาลูกหนี้ไม่เจอ
        $this->assertNull($return->reference);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();

        $this->postJson(route('sale-returns.store'), $this->payload($branch, $product, 5, source: $sale->doc_number))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        // Even a low requested price cannot bypass the independent quantity limit.
        $payload = $this->payload($branch, $product, 5, source: $sale->doc_number);
        $payload['items'][0]['unit_price'] = 10;
        $this->post(route('sale-returns.store'), $payload)->assertRedirect();
        $this->assertStringContainsString('รับคืนเกินจำนวนที่ขาย', (string) session('error'));
        $this->assertSame(0, $this->returns()->count());
    }

    public function test_earlier_returns_including_pending_ones_reduce_what_can_be_returned(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();

        // คืนครั้งแรก 2 ชิ้น (ยังรออนุมัติ) -> เหลือคืนได้ 1
        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 2, source: $sale->doc_number))->assertRedirect();
        $this->postJson(route('sale-returns.store'), $this->payload($branch, $product, 2, source: $sale->doc_number))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(1, $this->returns()->count());

        // ใบที่ถูกปฏิเสธไม่นับเป็นคืนไปแล้ว
        $this->returns()->sole()->update(['status' => 'rejected']);
        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 3, source: $sale->doc_number))->assertRedirect();
        $this->assertSame(2, $this->returns()->count());
    }

    public function test_a_product_that_was_not_on_the_sale_cannot_be_returned(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $other = Product::create([
            'sku_code' => 'RS-OTHER', 'name_th' => 'สินค้าอื่น', 'base_unit_id' => $product->base_unit_id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'allow',
        ]);
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();

        $this->post(route('sale-returns.store'), $this->payload($branch, $other, 1, source: $sale->doc_number))->assertRedirect();

        $this->assertStringContainsString("RS-OTHER ไม่อยู่ในใบขาย {$sale->doc_number}", (string) session('error'));
    }

    public function test_customer_must_match_the_source_sale(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $stranger = Customer::create(['code' => 'C-STRANGER', 'name_th' => 'คนอื่น', 'branch_id' => $branch->id, 'is_active' => true]);
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();

        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 1, customer: $stranger, source: $sale->doc_number))
            ->assertRedirect();

        $this->assertStringContainsString('ลูกค้าไม่ตรงกับใบขาย', (string) session('error'));
    }

    public function test_a_credit_sale_source_still_points_approval_at_its_receivable(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]],
        ]);
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
        $this->signIn();

        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 1, source: $sale->doc_number))->assertRedirect();

        $return = $this->returns()->sole();
        $this->assertSame($sale->doc_number, $return->reference);
        $this->assertSame($sale->id, $return->source_document_id);
    }

    public function test_privileged_users_may_return_without_a_source_sale(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $this->signIn(['sales.return.unreferenced']);

        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 1, customer: $customer))->assertRedirect();

        $this->assertSame(1, $this->returns()->count());
        $this->assertNull($this->returns()->sole()->source_document_id);
    }

    public function test_source_lookup_returns_what_is_still_returnable(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 3, $customer);
        $this->signIn();
        $this->post(route('sale-returns.store'), $this->payload($branch, $product, 1, source: $sale->doc_number));

        $this->getJson(route('sale-returns.source', ['doc_number' => $sale->doc_number]))
            ->assertOk()
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('lines.0.sku_code', 'RS-1')
            ->assertJsonPath('lines.0.sold', 3)
            ->assertJsonPath('lines.0.returned', 1)
            ->assertJsonPath('lines.0.returnable', 2);

        $this->getJson(route('sale-returns.source', ['doc_number' => 'NOPE']))->assertNotFound();
    }

    public function test_the_return_form_asks_for_the_source_sale(): void
    {
        $this->masters();
        $this->signIn();

        $this->get(route('sale-returns.index'))->assertOk()
            ->assertSee('ใบขายต้นทาง (ขายสด / ขายเชื่อ)')
            ->assertSee('name="source_doc_number"', escape: false);
    }

    public function test_credit_notes_and_pending_returns_share_the_sale_value_limit(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 20]],
        ]);
        $this->signIn();
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_NOTE'], ['name_th' => 'ใบลดหนี้']);
        $note = Document::create([
            'document_type_id' => $type->id, 'branch_id' => $branch->id,
            'doc_number' => 'CN-LIMIT', 'doc_date' => now(), 'customer_id' => $customer->id,
            'reference' => $sale->doc_number, 'status' => 'active', 'total_amount' => '5.00',
        ]);
        $this->getJson(route('sale-returns.source', ['doc_number' => $sale->doc_number]))
            ->assertOk()->assertJsonPath('refundable_amount', '15.00000000')->assertJsonPath('lines.0.unit_price', 15);
        $payload = $this->payload($branch, $product, 1, source: $sale->doc_number);
        $payload['items'][0]['unit_price'] = '15.01';
        $this->postJson(route('sale-returns.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, $this->returns()->count());
        $payload['items'][0]['qty'] = '0.5';
        $payload['items'][0]['unit_price'] = '20';
        $this->postJson(route('sale-returns.store'), $payload)->assertRedirect(); // reserve 10
        $payload['items'][0]['unit_price'] = '10.01'; // 5.005 > remaining 5
        $this->postJson(route('sale-returns.store'), $payload)->assertUnprocessable();
        $this->assertSame(1, $this->returns()->count());
        $note->update(['status' => 'rejected']);
        $this->getJson(route('sale-returns.source', ['doc_number' => $sale->doc_number]))
            ->assertOk()->assertJsonPath('refundable_amount', '10.00000000');
    }

    public function test_pending_credit_reserves_value_and_cancelled_credits_release_it(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 1, $customer);
        $this->signIn();
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_NOTE'], ['name_th' => 'ใบลดหนี้']);
        $note = Document::create([
            'document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => 'CN-PENDING',
            'doc_date' => now(), 'customer_id' => $customer->id, 'source_document_id' => $sale->id,
            'status' => 'pending_approval', 'total_amount' => '100',
        ]);
        $this->getJson(route('sale-returns.source', ['doc_number' => $sale->doc_number]))
            ->assertOk()->assertJsonPath('refundable_amount', '0.00000000')->assertJsonPath('lines.0.unit_price', 0);
        $note->update(['status' => 'cancelled']);
        $this->getJson(route('sale-returns.source', ['doc_number' => $sale->doc_number]))
            ->assertOk()->assertJsonPath('refundable_amount', '100.00000000');
    }

    public function test_approval_rechecks_the_credit_budget_before_stock_or_accounting_changes(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $sale = $this->cashSale($branch, $product, 1, $customer);
        $this->signIn();
        $payload = $this->payload($branch, $product, 1, source: $sale->doc_number);
        $payload['items'][0]['unit_price'] = '75';
        $this->post(route('sale-returns.store'), $payload)->assertRedirect();
        $return = $this->returns()->sole();
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_NOTE'], ['name_th' => 'ใบลดหนี้']);
        Document::create(['document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => 'CN-LEGACY-OVERLAP',
            'doc_date' => now(), 'customer_id' => $customer->id, 'reference' => $sale->doc_number,
            'status' => 'active', 'total_amount' => '25.01']);
        $this->signIn(['finance.note.approve']);
        $beforeStock = StockBalance::pluck('on_hand_qty', 'id')->all();
        $beforeGl = GlJournal::count();
        try {
            app(SaleReturnService::class)->approve($return, auth()->id());
            $this->fail('Approval must recheck remaining sale value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertSame('pending_approval', $return->fresh()->status);
        $this->assertSame($beforeStock, StockBalance::pluck('on_hand_qty', 'id')->all());
        $this->assertSame($beforeGl, GlJournal::count());
    }

    private function payload(Branch $branch, Product $product, float $qty, ?Customer $customer = null, ?string $source = null): array
    {
        return array_filter([
            'branch_id' => $branch->id,
            'customer_id' => $customer?->id,
            'source_doc_number' => $source,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => 100, 'return_disposition' => 'available']],
        ], fn ($value) => $value !== null);
    }

    private function cashSale(Branch $branch, Product $product, float $qty, Customer $customer): Document
    {
        return app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => 100]],
        ]);
    }

    private function returns()
    {
        return Document::whereHas('documentType', fn ($q) => $q->where('code', 'SALE_RETURN'));
    }

    /** @return array{0: Branch, 1: Product, 2: Customer} */
    private function masters(): array
    {
        foreach ([['PURCHASE', 'in'], ['CASH_SALE', 'out'], ['CREDIT_SALE', 'out'], ['BOOKING', 'none'], ['SALE_RETURN', 'in']] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $creditSaleType = DocumentType::where('code', 'CREDIT_SALE')->sole();
        foreach ([['DS', false, true], ['DSN', true, false]] as [$code, $noVat, $default]) {
            DocumentBook::firstOrCreate(
                ['document_type_id' => $creditSaleType->id, 'code' => $code],
                ['name' => $code, 'prefix' => $code, 'no_vat' => $noVat, 'is_default' => $default, 'is_active' => true],
            );
        }
        $branch = Branch::create(['code' => 'RS', 'name_th' => 'สาขารับคืน', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-RS', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M-RS', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'RS-1', 'name_th' => 'สินค้ารับคืน', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP-RS', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 20, 'unit_price' => 50]],
        ]);
        $customer = Customer::create(['code' => 'C-RS', 'name_th' => 'ลูกค้ารับคืน', 'branch_id' => $branch->id, 'is_active' => true]);

        return [$branch->fresh(), $product, $customer];
    }

    /** @param  array<int, string>  $permissions */
    private function signIn(array $permissions = []): void
    {
        $user = User::factory()->create(['username' => 'return_clerk_'.uniqid()]);
        if ($permissions !== []) {
            $role = Role::create(['code' => 'RS_'.uniqid(), 'name' => 'Return clerk']);
            $role->permissions()->sync(collect($permissions)
                ->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
            $user->roles()->attach($role->id);
        }
        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
