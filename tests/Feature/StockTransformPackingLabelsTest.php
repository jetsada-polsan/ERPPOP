<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\Code128;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransformPackingLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_permission_registered(): void
    {
        $this->assertSame('stock.manage', RoutePermissions::resolve('stock-transforms.packing-labels'));
    }

    public function test_code128_svg_generator(): void
    {
        $svg = Code128::svg('SKU-BEEF-001', 40);

        $this->assertNotEmpty($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringContainsString('SKU-BEEF-001', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('class="code128-barcode"', $svg);

        // Empty string returns empty
        $this->assertSame('', Code128::svg(''));
    }

    public function test_stock_transform_show_page_has_print_labels_button(): void
    {
        $this->withoutVite();
        [$branch, $product, $transformDoc] = $this->createTransformDocument();

        $this->signIn();

        $response = $this->get(route('stock-transforms.show', $transformDoc))->assertOk();
        $response->assertSee(route('stock-transforms.packing-labels', $transformDoc), false);
        $response->assertSee('พิมพ์ป้ายสินค้า (Packing Labels)');
    }

    public function test_packing_labels_declares_page_size_and_renders_output_details(): void
    {
        $this->withoutVite();
        [$branch, $product, $transformDoc] = $this->createTransformDocument();

        $this->signIn();

        $response = $this->get(route('stock-transforms.packing-labels', $transformDoc))->assertOk();

        // 1. Declares @page size
        $response->assertSee('@page', false);
        $response->assertSee('size: 80mm 50mm', false);

        // 2. Contains product name, SKU, real weight, lot number
        $response->assertSee($product->name_th);
        $response->assertSee($product->sku_code);
        $response->assertSee('5.250');
        $response->assertSee('กก.');
        $response->assertSee('LOT-OUTPUT-20261110');

        // 3. Contains Code 128 barcode SVG
        $response->assertSee('class="code128-barcode"', false);
        $response->assertSee($product->sku_code);
    }

    public function test_packing_labels_renders_one_label_per_output_product(): void
    {
        $this->withoutVite();
        [$branch, $product1, $transformDoc, $unit, $location] = $this->createTransformDocument();

        // Add second output item
        $product2 = Product::create([
            'sku_code' => 'SKU-FAT-02',
            'name_th' => 'มันหมูบดพิเศษ',
            'base_unit_id' => $unit->id,
            'default_price' => 80,
            'average_cost' => 45,
            'is_vat' => false,
            'is_active' => true,
        ]);

        StockDocumentItem::create([
            'stock_document_id' => $transformDoc->stockDocument->id,
            'warehouse_location_id' => $location->id,
            'seq' => 3,
            'product_id' => $product2->id,
            'qty' => 2.500,
            'unit_price' => 45.0,
            'lot_no' => 'LOT-FAT-02',
        ]);

        $this->signIn();

        $response = $this->get(route('stock-transforms.packing-labels', $transformDoc))->assertOk();

        // Both products are rendered
        $response->assertSee('SKU-OUTPUT-01');
        $response->assertSee('เนื้อหมูสันคอบด');
        $response->assertSee('5.250');

        $response->assertSee('SKU-FAT-02');
        $response->assertSee('มันหมูบดพิเศษ');
        $response->assertSee('2.500');

        // Both barcodes rendered
        $this->assertSame(2, substr_count($response->getContent(), 'class="code128-barcode"'));
    }

    private function createTransformDocument(): array
    {
        $branch = Branch::firstOrCreate(['code' => 'B01'], ['name_th' => 'สาขาหลัก', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH01'], ['branch_id' => $branch->id, 'name' => 'คลังหลัก', 'is_active' => true]);
        $location = WarehouseLocation::firstOrCreate(['code' => 'LOC01'], ['warehouse_id' => $warehouse->id, 'name' => 'จุดหลัก']);
        $unit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กก.', 'qty_per_base_unit' => 1]);

        $rawProduct = Product::create([
            'sku_code' => 'SKU-RAW-01',
            'name_th' => 'หมูก้อนใหญ่',
            'base_unit_id' => $unit->id,
            'default_price' => 150,
            'average_cost' => 100,
            'is_vat' => false,
            'is_active' => true,
        ]);

        $outputProduct = Product::create([
            'sku_code' => 'SKU-OUTPUT-01',
            'name_th' => 'เนื้อหมูสันคอบด',
            'base_unit_id' => $unit->id,
            'default_price' => 180,
            'average_cost' => 120,
            'is_vat' => false,
            'is_active' => true,
        ]);

        $docType = DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFORM'], ['name_th' => 'ใบแปรรูปสินค้า', 'stock_effect' => 'none']);

        $transformDoc = Document::create([
            'document_type_id' => $docType->id,
            'branch_id' => $branch->id,
            'doc_number' => 'DT20261110001',
            'doc_date' => now(),
            'status' => 'posted',
            'total_amount' => 500,
        ]);

        $stockDoc = StockDocument::create([
            'document_id' => $transformDoc->id,
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'status' => 'posted',
        ]);

        // Raw material item (negative qty)
        StockDocumentItem::create([
            'stock_document_id' => $stockDoc->id,
            'warehouse_location_id' => $location->id,
            'seq' => 1,
            'product_id' => $rawProduct->id,
            'qty' => -5.000,
            'unit_price' => 100.0,
        ]);

        // Output item (positive qty)
        StockDocumentItem::create([
            'stock_document_id' => $stockDoc->id,
            'warehouse_location_id' => $location->id,
            'seq' => 2,
            'product_id' => $outputProduct->id,
            'qty' => 5.250,
            'unit_price' => 120.0,
            'lot_no' => 'LOT-OUTPUT-20261110',
        ]);

        return [$branch, $outputProduct, $transformDoc, $unit, $location];
    }

    private function signIn(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
