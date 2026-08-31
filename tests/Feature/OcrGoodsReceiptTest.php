<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\OcrDocument;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrGoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_ocr_creates_a_reviewable_draft_and_only_posts_stock_after_approval(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ErpAuthorize::class);
        $user = User::factory()->create(['username' => 'ocr-uat']);
        DocumentType::create(['code' => 'PURCHASE', 'name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        $branch = Branch::create(['code' => 'OCR1', 'name_th' => 'สาขา OCR', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'OCR-WH', 'name' => 'คลัง OCR']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'OCR-SUP', 'name_th' => 'ซัพพลายเออร์ OCR', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'OCR-ITEM', 'name_th' => 'สินค้า OCR', 'base_unit_id' => $unit->id,
            'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '885000000001', 'unit_id' => $unit->id,
            'unit_factor' => 1, 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('supplier-invoice.txt', implode(PHP_EOL, [
            'เลขที่เอกสาร: INV-OCR-001',
            'วันที่: 31/08/2569',
            'ผู้ขาย: ซัพพลายเออร์ OCR',
            'ยอดรวม: 250',
            'สินค้า|รหัส|บาร์โค้ด|จำนวน|หน่วย|ราคา|ส่วนลด|รวม',
            'สินค้า OCR|OCR-ITEM|885000000001|5|ชิ้น|50|0|250',
        ]), 'text/plain');

        $this->actingAs($user)->post(route('ocr.documents.store'), [
            'document_type' => 'tax_invoice',
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'document_file' => $file,
        ])->assertRedirect();

        $document = OcrDocument::firstOrFail();
        $this->actingAs($user)->post(route('ocr.documents.process', $document))->assertRedirect();
        $document->refresh()->load('lines');
        $this->assertSame('matched', $document->status);
        $this->assertSame('INV-OCR-001', $document->reference_no);
        $this->assertSame('2026-08-31', $document->document_date->toDateString());
        $this->assertSame($product->id, $document->lines->sole()->matched_product_id);
        $this->actingAs($user)->get(route('ocr.documents.show', $document))
            ->assertOk()
            ->assertSee('ตรวจ OCR Draft')
            ->assertSee('ยังไม่ตัดสต็อก');
        $this->assertDatabaseCount('documents', 0);

        $this->actingAs($user)->post(route('ocr.documents.approve', $document))->assertRedirect();
        $this->assertSame('approved', $document->fresh()->status);
        $this->assertDatabaseCount('stock_balances', 0);

        $this->actingAs($user)->post(route('ocr.documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('posted', $document->fresh()->status);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'on_hand_qty' => 5,
        ]);
        $this->assertDatabaseHas('ocr_review_logs', ['ocr_document_id' => $document->id, 'action' => 'post']);
    }
}
