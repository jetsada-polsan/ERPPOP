<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockIssueService;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * มูลค่าสินค้าคงเหลือในบัญชีต้องเดินตามของจริง
 *
 * ก่อนหน้านี้การปรับปรุง ตรวจนับ และตัดชำรุด เปลี่ยนสต๊อกจริงแต่ไม่แตะ GL เลย
 * พอทำ parallel run แล้วมีการปรับสต๊อกระหว่างเดือน มูลค่าสินค้าคงเหลือในบัญชี
 * จะเพี้ยนทันทีและปิดงวดกระทบยอดไม่ได้ ซึ่งเป็นทั้งหมดของการทำ parallel run
 */
class InventoryLedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shortfall_found_at_stock_count_reduces_inventory_in_the_ledger(): void
    {
        [$branch, $location, $product, $creator, $approver] = $this->stockedBranch('INV1', qty: 10, unitCost: 60);

        // นับได้ 7 จากที่ระบบบอก 10 -> ขาด 3 ชิ้น มูลค่า 180
        $document = $this->adjustTo($branch, $location, $product, $creator, countedQty: 7);
        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        $this->assertGlBalanced($document);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY, credit: 180.0);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY_ADJUSTMENT, debit: 180.0);
    }

    public function test_a_surplus_found_at_stock_count_increases_inventory_in_the_ledger(): void
    {
        [$branch, $location, $product, $creator, $approver] = $this->stockedBranch('INV2', qty: 10, unitCost: 60);

        // นับได้ 12 -> เกิน 2 ชิ้น ตีมูลค่าด้วยต้นทุนเฉลี่ยปัจจุบัน 60 = 120
        $document = $this->adjustTo($branch, $location, $product, $creator, countedQty: 12);
        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        $this->assertGlBalanced($document);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY, debit: 120.0);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY_ADJUSTMENT, credit: 120.0);
    }

    public function test_writing_off_damaged_goods_reaches_the_ledger(): void
    {
        [$branch, $location, $product, $creator, $approver] = $this->stockedBranch('INV3', qty: 10, unitCost: 60);

        $this->actingAs($creator);
        $document = app(StockIssueService::class)->create([
            'type' => 'damage',
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'purpose' => 'สินค้าเสียหายจากการขนส่ง',
            'items' => [['product_id' => $product->id, 'qty' => 4]],
        ]);

        $this->actingAs($approver);
        app(StockIssueService::class)->approveDamage($document);

        $this->assertGlBalanced($document);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY, credit: 240.0);
        $this->assertGlAmount($document, ChartOfAccount::ROLE_INVENTORY_ADJUSTMENT, debit: 240.0);
    }

    public function test_a_count_that_matches_the_system_creates_no_document_at_all(): void
    {
        [$branch, $location, $product, $creator] = $this->stockedBranch('INV4', qty: 10, unitCost: 60);

        // นับได้เท่าระบบ = ไม่มีอะไรต้องปรับ service ปฏิเสธตั้งแต่ต้น
        $this->expectException(RuntimeException::class);
        $this->adjustTo($branch, $location, $product, $creator, countedQty: 10);

        $this->assertSame(0, GlJournal::count());
    }

    private function adjustTo(Branch $branch, WarehouseLocation $location, Product $product, User $creator, float $countedQty): Document
    {
        $this->actingAs($creator);

        return app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'remark' => 'ตรวจนับประจำเดือน',
            'items' => [['product_id' => $product->id, 'counted_qty' => $countedQty]],
        ]);
    }

    private function assertGlBalanced(Document $document): void
    {
        $lines = GlJournal::where('document_id', $document->id)->get();
        $this->assertNotEmpty($lines, "เอกสาร {$document->doc_number} ไม่ได้ลง GL");
        $this->assertSame(
            round((float) $lines->sum('debit'), 2),
            round((float) $lines->sum('credit'), 2),
            "GL ของ {$document->doc_number} ไม่ดุล",
        );
    }

    private function assertGlAmount(Document $document, string $role, float $debit = 0, float $credit = 0): void
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');
        $this->assertNotNull($accountId, "ยังไม่ได้ผูกบัญชีสำหรับ role {$role}");
        $lines = GlJournal::where('document_id', $document->id)->where('account_id', $accountId)->get();
        $this->assertNotEmpty($lines, "ไม่พบรายการ GL ของ {$role}");
        $this->assertSame($debit, round((float) $lines->sum('debit'), 2), "debit ของ {$role} ไม่ตรง");
        $this->assertSame($credit, round((float) $lines->sum('credit'), 2), "credit ของ {$role} ไม่ตรง");
    }

    private function stockedBranch(string $suffix, float $qty, float $unitCost): array
    {
        foreach (['PURCHASE' => 'ใบซื้อ', 'STOCK_ADJUSTMENT' => 'ใบปรับปรุงสต็อก', 'STOCK_DAMAGE' => 'ใบตัดชำรุด'] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name]);
        }

        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$suffix, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        $supplier = Supplier::create(['code' => 'SUP'.$suffix, 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);

        $creator = User::factory()->create(['username' => 'inv_creator_'.strtolower($suffix), 'branch_id' => $branch->id]);
        $approver = User::factory()->create(['username' => 'inv_approver_'.strtolower($suffix), 'branch_id' => $branch->id]);

        $this->actingAs($creator);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $unitCost]],
        ]);

        return [$branch->fresh(), $location, $product->fresh(), $creator, $approver];
    }
}
