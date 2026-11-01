<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\GlPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class NoVatBookTest extends TestCase
{
    use RefreshDatabase;

    private function vatFor(bool $noVat, string $number): float
    {
        $type = DocumentType::firstOrCreate(['code' => DocumentType::CREDIT_SALE], ['name' => 'ขายเชื่อ', 'name_th' => 'ขายเชื่อ']);
        $book = DocumentBook::create(['code' => 'T'.$number, 'name' => 'x', 'document_type_id' => $type->id, 'prefix' => 'T', 'is_active' => true, 'no_vat' => $noVat]);
        $branch = Branch::firstOrCreate(['code' => 'VATB'], ['name_th' => 'x', 'is_active' => true]);
        $unit = ProductUnit::firstOrCreate(['code' => 'VU'], ['name' => 'u', 'qty_per_base_unit' => 1]);
        $product = Product::firstOrCreate(['sku_code' => '990001'], ['name_th' => 'x', 'base_unit_id' => $unit->id, 'is_active' => true, 'is_vat' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'VW'], ['name' => 'w']);
        $location = WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'M'], ['name' => 'm']);
        $document = Document::create(['document_type_id' => $type->id, 'document_book_id' => $book->id, 'branch_id' => $branch->id, 'doc_number' => $number, 'doc_date' => now(), 'status' => 'active', 'total_amount' => 107]);
        $stock = StockDocument::create(['document_id' => $document->id, 'total_qty' => 1, 'total_items' => 1]);
        StockDocumentItem::create(['stock_document_id' => $stock->id, 'seq' => 1, 'product_id' => $product->id, 'warehouse_location_id' => $location->id, 'qty' => 1, 'unit_price' => 107]);

        $method = new ReflectionMethod(GlPostingService::class, 'salesBreakdown');

        return $method->invoke(app(GlPostingService::class), $document->fresh())['vat'];
    }

    public function test_only_vat_books_charge_output_vat(): void
    {
        $this->assertSame(7.0, $this->vatFor(false, 'DS1'));
        $this->assertSame(0.0, $this->vatFor(true, 'DSN1'));
    }
}
