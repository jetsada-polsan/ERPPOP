<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** เจ้าของ 13 พ.ย.: ล้างกะและยอดขาย POS ทั้งหมด "ทุกอย่าง ยกเว้นสต๊อก" */
class PosResetSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_removes_shifts_sales_and_their_accounting_but_keeps_stock(): void
    {
        ob_start();
        require base_path('tools/staging/seed_uat_data.php');
        ob_end_clean();
        $branchId = (int) DB::table('branches')->where('code', 'UAT')->value('id');
        $productId = (int) DB::table('products')->where('sku_code', 'UAT-SKU-001')->value('id');
        $price = (float) DB::table('products')->where('id', $productId)->value('default_price');
        $user = User::factory()->create(['branch_id' => $branchId, 'is_active' => true, 'must_change_password' => false]);
        $cashier = Salesman::create(['branch_id' => $branchId, 'code' => 'RST-C1', 'name' => 'แคชเชียร์', 'is_active' => true, 'user_id' => $user->id]);
        $terminal = PosTerminal::create(['branch_id' => $branchId, 'code' => 'POS-RST-01', 'name' => 'Reset']);
        $shift = PosShift::create(['branch_id' => $branchId, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id,
            'shift_no' => 'RST-1', 'opened_at' => now(), 'opening_cash' => 0, 'status' => 'open']);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user);
        foreach (range(1, 3) as $i) {
            $this->postJson(route('pos.checkout'), ['branch_id' => $branchId, 'shift_id' => $shift->id, 'cashier_id' => $cashier->id,
                'method' => 'cash', 'cash_received' => $price, 'change_amount' => 0,
                'items' => [['product_id' => $productId, 'qty' => 1, 'unit_price' => $price]]], ['X-Offline-Uuid' => (string) Str::uuid()])
                ->assertOk()->assertJsonPath('success', true);
        }
        $documentIds = DB::table('pos_receipts')->pluck('document_id');
        $this->assertGreaterThan(0, DB::table('gl_journals')->whereIn('document_id', $documentIds)->count());
        $movements = DB::table('stock_movements')->count();
        $onHand = (string) DB::table('stock_balances')->sum('on_hand_qty');
        $otherDocuments = DB::table('documents')->whereNotIn('id', $documentIds)->count();

        $this->artisan('pos:reset-sales')->assertSuccessful();
        $this->assertSame(3, DB::table('pos_receipts')->count(), 'ไม่ใส่ --apply ต้องไม่ลบ');

        $this->artisan('pos:reset-sales', ['--apply' => true, '--confirm-database' => 'wrong'])->assertFailed();
        $this->assertSame(3, DB::table('pos_receipts')->count());

        $backups = storage_path('app/backups');
        @mkdir($backups, 0777, true);
        $file = $backups.'/erp-db-test-reset.sql.gz';
        file_put_contents($file, 'x');
        file_put_contents($file.'.sha256', hash_file('sha256', $file).'  x');
        try {
            $this->artisan('pos:reset-sales', ['--apply' => true, '--confirm-database' => DB::connection()->getDatabaseName()])->assertSuccessful();
        } finally {
            @unlink($file);
            @unlink($file.'.sha256');
        }

        foreach (['pos_receipts', 'pos_receipt_items', 'pos_payments', 'pos_shifts'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('documents')->whereIn('id', $documentIds)->count());
        $this->assertSame(0, DB::table('gl_journals')->whereIn('document_id', $documentIds)->count());
        $this->assertSame($otherDocuments, DB::table('documents')->count(), 'เอกสารอื่น (รับเข้าสต๊อก ฯลฯ) ต้องอยู่ครบ');
        $this->assertSame($movements, DB::table('stock_movements')->count(), 'ประวัติสต๊อกต้องอยู่ครบ');
        $this->assertSame($onHand, (string) DB::table('stock_balances')->sum('on_hand_qty'), 'ยอดคงเหลือต้องเท่าเดิม');
    }
}
