<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Task11UiFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user);

        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);

        $this->branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาหลัก', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH01', 'name' => 'คลังสินค้า']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC01', 'name' => 'ห้องเย็น']);
        $this->branch->update(['default_warehouse_location_id' => $location->id]);

        $this->supplier = Supplier::create(['code' => 'SUP01', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'KG', 'name' => 'กก.', 'qty_per_base_unit' => 1]);
        $this->product = Product::create([
            'sku_code' => 'PORK-01', 'name_th' => 'เนื้อหมู', 'base_unit_id' => $unit->id,
            'average_cost' => 100, 'is_vat' => false, 'is_active' => true,
        ]);
    }

    /**
     * ทดสอบหน้าสร้างใบปรับต้นทุนสินค้า (/purchases/{purchase}/cost-adjustments/create)
     * ต้องเปิดได้ HTTP 200 โดยไม่ติด Blade syntax error (@php($item = ...; $lot = ...))
     */
    public function test_purchase_cost_adjustments_create_page_opens_without_syntax_error(): void
    {
        $purchase = app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'is_credit' => false,
            'items' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 100],
            ],
        ]);

        $response = $this->get(route('purchases.cost-adjustments.create', $purchase));
        $response->assertOk();
        $response->assertSee('ปรับต้นทุนซื้อย้อนหลัง');
        $response->assertSee('PORK-01');
    }

    /**
     * ทดสอบหน้าพิมพ์ป้ายเครื่องชั่ง (scale-prices.plan-labels)
     * ต้อง render ได้อย่างปลอดภัย ไม่เกิด Undefined array key "sku"
     * รองรับทั้งคีย์ 'sku_code' และ 'sku'
     */
    public function test_scale_plan_labels_page_renders_safely(): void
    {
        $labelsWithSkuCode = [
            [
                'name' => 'เนื้อหมูบด',
                'sku_code' => 'PORK-MINCED',
                'plu' => '101',
                'price' => 120.00,
                'svg' => '<svg>barcode-1</svg>',
            ],
        ];

        $htmlWithSkuCode = view('scale-prices.plan-labels', ['labels' => $labelsWithSkuCode])->render();
        $this->assertStringContainsString('PORK-MINCED', $htmlWithSkuCode);
        $this->assertStringContainsString('PLU 101', $htmlWithSkuCode);

        $labelsWithSku = [
            [
                'name' => 'เนื้อหมูสันนอก',
                'sku' => 'PORK-LOIN',
                'plu' => '102',
                'price' => 150.00,
                'svg' => '<svg>barcode-2</svg>',
            ],
        ];

        $htmlWithSku = view('scale-prices.plan-labels', ['labels' => $labelsWithSku])->render();
        $this->assertStringContainsString('PORK-LOIN', $htmlWithSku);
        $this->assertStringContainsString('PLU 102', $htmlWithSku);
    }

    /**
     * ทดสอบการใช้งาน ilike บน SQLite Grammar ให้แปลงเป็น like โดยไม่เกิด QueryException
     */
    public function test_search_query_with_ilike_works_on_sqlite(): void
    {
        Document::create([
            'branch_id' => $this->branch->id,
            'document_type_id' => DocumentType::where('code', 'PURCHASE')->value('id'),
            'doc_number' => 'PO-TEST-1234',
            'doc_date' => now(),
            'created_by' => $this->user->id,
        ]);

        $results = Document::where('doc_number', 'ilike', '%test%')->get();
        $this->assertCount(1, $results);
        $this->assertSame('PO-TEST-1234', $results->first()->doc_number);
    }
}
