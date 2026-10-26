<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * /stock-transfers และ /stock-issues เคยกดบันทึกแล้วเงียบ: ไม่สร้างเอกสารและไม่มีข้อความ.
 *
 * สาเหตุที่ reproduce ได้คือ HTML5 required บน <select> ทำให้เบราว์เซอร์บล็อก
 * submit ตั้งแต่ก่อนเกิด submit event — handler ของหน้าไม่ทำงาน ไม่มี popup ใด ๆ.
 * ส่วนฝั่ง server ทำงานอยู่แล้วแต่ข้อความสต๊อกไม่พอเป็นข้อความกลาง ๆ ที่เอาไปใช้ต่อไม่ได้.
 */
class StockDocumentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM_ENTRY_FORMS = [
        '/purchase-orders',
        '/stock-transfers',
        '/stock-transfers/request',
        '/stock-issues',
    ];

    public function test_item_entry_forms_validate_themselves_instead_of_letting_the_browser_block_silently(): void
    {
        // /stock-transfers/request จะ render ฟอร์มก็ต่อเมื่อ user มีสาขาประจำ
        [$branch] = $this->masters();
        $this->signIn($branch);

        foreach (self::ITEM_ENTRY_FORMS as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString('novalidate', $html, "[{$uri}] ยังปล่อยให้ browser บล็อก submit เอง");
            $this->assertStringContainsString('erpStopSubmit(event, problems)', $html, "[{$uri}] ไม่ได้แจ้ง validation เอง");
        }
    }

    public function test_layout_always_defines_the_validation_helper_even_without_sweetalert(): void
    {
        $this->signIn();

        $html = $this->get('/stock-transfers')->assertOk()->getContent();

        $this->assertStringContainsString('window.erpStopSubmit = function', $html);
        // ต้องมี fallback เป็น alert() เผื่อ SweetAlert โหลดไม่สำเร็จ จะได้ไม่เงียบอีก
        $this->assertStringContainsString("window.alert('ยังบันทึกไม่ได้", $html);
    }

    public function test_transfer_with_insufficient_stock_names_the_product_and_the_shortfall(): void
    {
        [$branch, $from, $to, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $from->id,
            'on_hand_qty' => 3, 'reserved_qty' => 0,
        ]);
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'ใบโอนย้ายสต็อก']);
        $this->signIn();

        $response = $this->post('/stock-transfers', [
            'branch_id' => $branch->id,
            'from_warehouse_location_id' => $from->id,
            'to_warehouse_location_id' => $to->id,
            'items' => [['product_id' => $product->id, 'qty' => 10]],
        ]);

        $response->assertRedirect();
        $error = (string) session('error');

        // ต้องบอกให้ครบว่าสินค้าตัวไหน ต้องการเท่าไร มีเท่าไร ขาดเท่าไร
        $this->assertStringContainsString('SKU-SHORT', $error);
        $this->assertStringContainsString('สินค้าสต๊อกน้อย', $error);
        $this->assertStringContainsString('ต้องการ 10', $error);
        $this->assertStringContainsString('มีอยู่ 3', $error);
        $this->assertStringContainsString('ขาดอีก 7', $error);

        $this->assertDatabaseCount('stock_documents', 0);
    }

    public function test_shortage_message_lists_every_short_product_not_just_the_first(): void
    {
        [$branch, $from, $to, $product] = $this->masters();
        $second = Product::create([
            'sku_code' => 'SKU-SHORT2', 'name_th' => 'สินค้าขาดตัวที่สอง',
            'base_unit_id' => $product->base_unit_id, 'average_cost' => 5,
            'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        foreach ([[$product, 3], [$second, 1]] as [$p, $qty]) {
            StockBalance::create([
                'product_id' => $p->id, 'warehouse_location_id' => $from->id,
                'on_hand_qty' => $qty, 'reserved_qty' => 0,
            ]);
        }
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'ใบโอนย้ายสต็อก']);
        $this->signIn();

        $this->post('/stock-transfers', [
            'branch_id' => $branch->id,
            'from_warehouse_location_id' => $from->id,
            'to_warehouse_location_id' => $to->id,
            'items' => [
                ['product_id' => $product->id, 'qty' => 10],
                ['product_id' => $second->id, 'qty' => 4],
            ],
        ]);

        $error = (string) session('error');
        $this->assertStringContainsString('SKU-SHORT ', $error);
        $this->assertStringContainsString('SKU-SHORT2', $error);
        $this->assertStringContainsString('ขาดอีก 3', $error);
    }

    public function test_fifo_issue_shortage_also_names_the_product(): void
    {
        [, $from, , $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $from->id,
            'on_hand_qty' => 2, 'reserved_qty' => 0,
        ]);

        try {
            app(FifoStockService::class)->issue($product->id, $from->id, 5, null);
            $this->fail('ควรโยน RuntimeException เมื่อสต๊อกไม่พอ');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SKU-SHORT', $exception->getMessage());
            $this->assertStringContainsString('ขาดอีก 3', $exception->getMessage());
        }
    }

    private function signIn(?Branch $branch = null): void
    {
        $this->actingAs(User::factory()->create([
            'username' => 'feedback_tester',
            'branch_id' => $branch?->id,
        ]));
        $this->withoutMiddleware(ErpAuthorize::class);
    }

    /** @return array{0: Branch, 1: WarehouseLocation, 2: WarehouseLocation, 3: Product} */
    private function masters(): array
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH', 'name' => 'คลัง']);
        $from = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $to = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'SHOP', 'name' => 'หน้าร้าน']);
        $branch->update(['default_warehouse_location_id' => $from->id]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU-SHORT', 'name_th' => 'สินค้าสต๊อกน้อย', 'base_unit_id' => $unit->id,
            'average_cost' => 10, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);

        return [$branch->fresh(), $from, $to, $product];
    }
}
