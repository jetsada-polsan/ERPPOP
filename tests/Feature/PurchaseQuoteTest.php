<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ใบสอบราคา — เทียบหลายผู้ขายก่อนสั่งซื้อ
 *
 * ใบขอซื้อมีอยู่แล้ว (purchase_orders สถานะ requested -> approved -> ordered)
 * ที่ขาดคือหลักฐานว่าเทียบกับใครบ้างและทำไมถึงเลือกเจ้านี้
 */
class PurchaseQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_quotes_totals_each_supplier(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ1');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);

        $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $service->record($order, $suppliers[1]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 85]]);

        $comparison = $service->comparison($order->fresh());
        $this->assertCount(2, $comparison['quotes']);
        // 10 ชิ้น: 850 กับ 900 ต่างกัน 50
        $this->assertSame(850.0, (float) $comparison['quotes'][0]->total_amount);
        $this->assertSame(50.0, $comparison['spread']);
        $this->assertSame($comparison['quotes'][0]->id, $comparison['cheapest_id']);
    }

    public function test_one_supplier_quotes_once_and_can_revise(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ2');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);

        $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 80]]);

        $this->assertSame(1, PurchaseQuote::where('purchase_order_id', $order->id)->count());
        $this->assertSame(800.0, (float) PurchaseQuote::sole()->total_amount, 'ราคาที่แก้ต้องทับของเดิม');
    }

    public function test_selecting_a_quote_puts_its_prices_on_the_order(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ3');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);
        $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $cheap = $service->record($order, $suppliers[1]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 85]]);

        $service->select($cheap);

        $this->assertTrue($cheap->fresh()->is_selected);
        $this->assertSame($suppliers[1]->id, (int) $order->fresh()->supplier_id);
        $this->assertSame(85.0, (float) $item->fresh()->unit_price);
        $this->assertSame(850.0, (float) $order->fresh()->total_amount);
    }

    public function test_choosing_a_dearer_supplier_requires_a_reason(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ4');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);
        $expensive = $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $service->record($order, $suppliers[1]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 85]]);

        try {
            $service->select($expensive);
            $this->fail('ควรถูกปฏิเสธเพราะไม่ได้ให้เหตุผล');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ต้องระบุเหตุผล', $exception->getMessage());
        }

        // ให้เหตุผลแล้วเลือกได้ — ถูกที่สุดไม่ได้แปลว่าดีที่สุดเสมอ
        $service->select($expensive, 'ส่งได้ใน 2 วัน อีกเจ้ารอ 3 สัปดาห์');
        $this->assertTrue($expensive->fresh()->is_selected);
        $this->assertSame('ส่งได้ใน 2 วัน อีกเจ้ารอ 3 สัปดาห์', $expensive->fresh()->selection_reason);
    }

    public function test_selecting_one_quote_unselects_the_others(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ5');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);
        $first = $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $second = $service->record($order, $suppliers[1]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 85]]);

        $service->select($second);
        $service->select($first, 'เปลี่ยนใจ ผู้ขายรายแรกรับประกันสินค้า');

        $this->assertTrue($first->fresh()->is_selected);
        $this->assertFalse($second->fresh()->is_selected, 'เลือกได้ทีละเจ้าเท่านั้น');
    }

    public function test_the_choice_is_written_to_the_audit_log_with_the_cheapest_for_comparison(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ6');
        $this->actingAs($user);
        $service = app(PurchaseQuoteService::class);
        $expensive = $service->record($order, $suppliers[0]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 90]]);
        $service->record($order, $suppliers[1]->id, [['purchase_order_item_id' => $item->id, 'unit_price' => 85]]);

        $service->select($expensive, 'ส่งเร็วกว่า');

        $audit = AuditLog::where('table_name', 'purchase_quotes')->sole();
        $this->assertSame('purchase_quote_selected', $audit->action);
        $this->assertSame('ส่งเร็วกว่า', $audit->new_values['reason']);
        // เก็บราคาต่ำสุดไว้ด้วย จะได้เห็นทีหลังว่ายอมจ่ายแพงกว่าเท่าไร
        $this->assertSame('850.0000', $audit->old_values['cheapest_total']);
    }

    public function test_quotes_cannot_be_added_after_the_order_is_placed(): void
    {
        [$order, $item, $suppliers, $user] = $this->approvedOrder('PQ7');
        $this->actingAs($user);
        $order->update(['status' => 'ordered']);

        $this->expectException(RuntimeException::class);
        app(PurchaseQuoteService::class)->record($order->fresh(), $suppliers[0]->id, [
            ['purchase_order_item_id' => $item->id, 'unit_price' => 80],
        ]);
    }

    private function approvedOrder(string $suffix): array
    {
        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'is_active' => true,
        ]);
        $suppliers = [
            Supplier::create(['code' => 'S1'.$suffix, 'name_th' => 'ผู้ขาย ก', 'is_active' => true]),
            Supplier::create(['code' => 'S2'.$suffix, 'name_th' => 'ผู้ขาย ข', 'is_active' => true]),
        ];
        $user = User::factory()->create(['username' => 'pq_'.strtolower($suffix), 'branch_id' => $branch->id]);

        $order = PurchaseOrder::create([
            'doc_number' => 'PR'.$suffix, 'branch_id' => $branch->id,
            'doc_date' => now()->toDateString(), 'status' => 'approved',
            'total_amount' => 0, 'requested_by' => $user->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'product_id' => $product->id,
            'qty' => 10, 'unit_price' => 0,
        ]);

        return [$order->fresh('items'), $item, $suppliers, $user];
    }
}
