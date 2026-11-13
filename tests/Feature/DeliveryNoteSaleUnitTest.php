<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ฝ่ายขาย 13 พ.ย.: "บิลส่งของมันดูยากไหม" — ให้เหมือนใบส่งของ BPlus:
 * หน่วยนับ = หน่วยที่ขาย (ถุง*10) ราคา = ต่อหน่วยนั้น จำนวน = ตัวเลขล้วน ยอดรวมเท่าเดิมถึงสตางค์
 */
class DeliveryNoteSaleUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_shows_sale_unit_price_and_plain_quantity(): void
    {
        $kg = new ProductUnit(['name' => 'กิโลกรัม', 'code' => 'KG', 'qty_per_base_unit' => 1]);
        $kg->id = 1;
        $bag = new ProductUnit(['name' => 'ถุง', 'code' => 'BAG', 'qty_per_base_unit' => 10]);
        $bag->id = 2;
        $product = new Product(['sku_code' => '104013', 'name_th' => 'ข้อยาว ก้าวหน้า (ถุง10kg.)', 'base_unit_id' => 1]);
        $product->setRelation('baseUnit', $kg);
        $barcode = new ProductBarcode(['barcode' => '0000001040135', 'unit_id' => 2, 'unit_factor' => 10, 'is_active' => true]);
        $barcode->setRelation('unit', $bag);
        $product->setRelation('barcodes', collect([$barcode]));

        $item = new StockDocumentItem(['qty' => 10, 'unit_id' => 2, 'unit_qty' => 1, 'unit_factor' => 10, 'unit_price' => 29]);
        $item->setRelation('product', $product);
        $item->setRelation('unit', $bag);
        $stock = new StockDocument;
        $stock->setRelation('items', collect([$item]));

        $document = new Document(['doc_number' => 'DSNB001-TEST', 'doc_date' => Carbon::parse('2026-11-13'), 'total_amount' => 290]);
        $document->setRelation('branch', new Branch(['name_th' => 'สาขาอำนาจเจริญ']));
        $customer = new Customer(['code' => '252', 'name_th' => 'คุณเจี๊ยบ']);
        $customer->setRelation('addresses', collect());
        $customer->setRelation('contacts', collect());
        $document->setRelation('customer', $customer);
        $document->setRelation('salesman', null);
        $document->setRelation('stockDocument', $stock);

        $html = view('partials.delivery-note-page', [
            'document' => $document, 'title' => 'ใบส่งของชั่วคราว', 'copyLabel' => '', 'logo' => null, 'paymentLabel' => 'เงินเชื่อ',
        ])->render();
        $row = preg_replace('/\s+/', ' ', strip_tags(substr($html, strpos($html, '0000001040135') - 40, 900)));

        $this->assertStringContainsString('ถุง*10', $row);
        $this->assertMatchesRegularExpression('/ถุง\*10 290\.00 1 290\.00/', $row, 'ราคาต่อถุง 290 จำนวน 1 รวม 290');
        $this->assertStringNotContainsString('(10 กิโลกรัม)', $row);
    }
}
