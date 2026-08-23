<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosPayment;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * กระทบยอด statement ธนาคาร — เขียนก่อนเปิดรายงานธนาคารตามเงื่อนไขของเจ้าของ
 *
 * โค้ดส่วนนี้มีมาตั้งแต่ต้นแต่ไม่เคยมีเทสต์และไม่เคยถูกใช้จริง (0 แถวบน production)
 * สิ่งที่ต้องพิสูจน์ที่สุดคือ "เงินก้อนเดียวถูกใช้กระทบยอดสองบรรทัดไม่ได้"
 * เพราะถ้าพลาดตรงนี้ ยอดธนาคารจะดูตรงทั้งที่จริงไม่ตรง
 */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matching_amount_marks_the_statement_reconciled(): void
    {
        [$user, , $statement] = $this->statementWorth(2500.00);

        $this->actingAs($user)->post(route('monthly-accounting.statements.reconcile', $statement), [
            'match_type' => 'other',
            'expected_amount' => 2500.00,
            'reference' => 'INV-001',
        ])->assertRedirect();

        $reconciliation = BankReconciliation::sole();
        $this->assertSame('matched', $reconciliation->status);
        $this->assertSame(0.0, (float) $reconciliation->difference_amount);
        $this->assertTrue((bool) $statement->fresh()->reconciled);
    }

    public function test_a_difference_is_recorded_and_the_statement_stays_open(): void
    {
        [$user, , $statement] = $this->statementWorth(2500.00);

        $this->actingAs($user)->post(route('monthly-accounting.statements.reconcile', $statement), [
            'match_type' => 'other',
            'expected_amount' => 2300.00,
        ])->assertRedirect();

        $reconciliation = BankReconciliation::sole();
        $this->assertSame('mismatch', $reconciliation->status);
        $this->assertSame(200.0, (float) $reconciliation->difference_amount);
        $this->assertFalse((bool) $statement->fresh()->reconciled,
            'ยอดไม่ตรงต้องยังค้างไว้ ไม่ใช่ปิดทิ้ง');
    }

    public function test_auto_reconcile_matches_a_pos_transfer_of_the_same_day_and_amount(): void
    {
        [$user, $bank, $statement] = $this->statementWorth(1800.00);
        $this->posTransfer($bank, 1800.00, $statement->statement_date);

        $this->actingAs($user)->post(route('monthly-accounting.statements.auto-reconcile'), [
            'period' => $statement->statement_date->format('Y-m'),
        ])->assertRedirect();

        $reconciliation = BankReconciliation::sole();
        $this->assertSame('pos_transfer', $reconciliation->match_type);
        $this->assertSame('pos_payment', $reconciliation->source_type);
        $this->assertSame('matched', $reconciliation->status);
        $this->assertTrue((bool) $statement->fresh()->reconciled);
    }

    public function test_one_payment_cannot_reconcile_two_statement_lines(): void
    {
        [$user, $bank, $first] = $this->statementWorth(1800.00);
        // statement บรรทัดที่สอง ยอดเท่ากันวันเดียวกัน แต่มีเงินเข้าจริงก้อนเดียว
        $second = BankStatement::create([
            'bank_account_id' => $bank->id,
            'statement_date' => $first->statement_date,
            'description' => 'โอนเข้า (บรรทัดซ้ำ)',
            'amount' => 1800.00,
            'balance' => 0,
            'reconciled' => false,
        ]);
        $this->posTransfer($bank, 1800.00, $first->statement_date);

        $this->actingAs($user)->post(route('monthly-accounting.statements.auto-reconcile'), [
            'period' => $first->statement_date->format('Y-m'),
        ]);

        $this->assertSame(1, BankReconciliation::count(),
            'เงินเข้าก้อนเดียวต้องกระทบยอดได้บรรทัดเดียว');
        $reconciledCount = BankStatement::where('reconciled', true)->count();
        $this->assertSame(1, $reconciledCount);
        $this->assertSame(1, BankStatement::where('reconciled', false)->count());
        unset($second);
    }

    public function test_an_amount_with_no_matching_money_stays_unreconciled(): void
    {
        [$user, $bank, $statement] = $this->statementWorth(1800.00);
        $this->posTransfer($bank, 999.00, $statement->statement_date);   // ยอดไม่ตรง

        $this->actingAs($user)->post(route('monthly-accounting.statements.auto-reconcile'), [
            'period' => $statement->statement_date->format('Y-m'),
        ]);

        $this->assertSame(0, BankReconciliation::count());
        $this->assertFalse((bool) $statement->fresh()->reconciled);
    }

    public function test_a_cash_payment_is_never_matched_to_a_bank_statement(): void
    {
        [$user, $bank, $statement] = $this->statementWorth(1800.00);
        $this->posTransfer($bank, 1800.00, $statement->statement_date, method: 'cash');

        $this->actingAs($user)->post(route('monthly-accounting.statements.auto-reconcile'), [
            'period' => $statement->statement_date->format('Y-m'),
        ]);

        $this->assertSame(0, BankReconciliation::count(),
            'เงินสดไม่เคยผ่านธนาคาร จะเอามากระทบยอด statement ไม่ได้');
    }

    private function posTransfer(BankAccount $bank, float $amount, $date, string $method = 'transfer'): PosPayment
    {
        $terminal = PosTerminal::firstOrCreate(
            ['code' => 'BR-POS'],
            ['branch_id' => $bank->branch_id, 'name' => 'POS กระทบยอด'],
        );
        $receipt = PosReceipt::create([
            'pos_terminal_id' => $terminal->id,
            'receipt_no' => 'BR-'.uniqid(),
            'receipt_date' => $date->copy()->setTime(10, 0),
            'gross_sales' => $amount,
            'net_sales' => $amount,
            'status' => 'completed',
        ]);

        return PosPayment::create([
            'pos_receipt_id' => $receipt->id,
            'method' => $method,
            'amount' => $amount,
            'payment_reference' => 'QR-'.uniqid(),
        ]);
    }

    private function statementWorth(float $amount): array
    {
        $branch = Branch::create(['code' => 'BR', 'name_th' => 'สาขากระทบยอด', 'is_active' => true]);
        $bank = BankAccount::create([
            'branch_id' => $branch->id, 'bank_name' => 'ธนาคารทดสอบ',
            'account_no' => '999-9-99999', 'account_name' => 'บัญชีทดสอบ',
        ]);
        $statement = BankStatement::create([
            'bank_account_id' => $bank->id,
            'statement_date' => '2026-08-10',
            'description' => 'โอนเข้า',
            'amount' => $amount,
            'balance' => $amount,
            'reconciled' => false,
        ]);

        $user = User::factory()->create([
            'username' => 'recon_'.uniqid(), 'branch_id' => $branch->id,
            'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'RECON_'.strtoupper(uniqid()), 'name' => 'Reconciliation test']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'finance.manage'], ['name' => 'finance.manage'])->id);
        $user->roles()->attach($role->id);

        unset($amount);

        return [$user, $bank, $statement];
    }
}
