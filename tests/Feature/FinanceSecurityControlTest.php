<?php

namespace Tests\Feature;

use App\Http\Controllers\MonthlyAccountingController;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\PosPayment;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountingCloseReadinessService;
use App\Services\Accounting\BranchExpenseService;
use App\Services\Accounting\TaxComplianceService;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceSecurityControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_accepts_current_standard_code_and_rejects_wrong_code(): void
    {
        $service = app(TotpService::class);
        $secret = 'JBSWY3DPEHPK3PXP';
        $timestamp = 1_784_505_600;

        $this->assertTrue($service->verify($secret, $this->totpCode($secret, $timestamp), $timestamp));
        $this->assertFalse($service->verify($secret, '000000', $timestamp));
    }

    public function test_mfa_secret_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create(['username' => 'mfa-user', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_enabled_at' => now()]);

        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->mfa_secret);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', DB::table('users')->where('id', $user->id)->value('mfa_secret'));
    }

    public function test_pre_close_check_blocks_unposted_accounting_document(): void
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $period = AccountingPeriod::create(['name' => 'กรกฎาคม 2569', 'branch_id' => $branch->id, 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'status' => 'open']);
        $typeId = DB::table('document_types')->insertGetId(['code' => 'CASH_SALE', 'name_th' => 'ขายสด', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false, 'is_active' => true]);
        DB::table('documents')->insert(['document_type_id' => $typeId, 'branch_id' => $branch->id, 'doc_number' => 'CS-MISSING-GL', 'doc_date' => '2026-07-10', 'status' => 'active', 'total_items' => 1, 'total_amount' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $check = collect(app(AccountingCloseReadinessService::class)->checks($period))->firstWhere('code', 'document_posting');
        $this->assertSame('block', $check['status']);
    }

    public function test_pnd53_working_paper_has_hash_and_separation_status(): void
    {
        Storage::fake('local');
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        app(BranchExpenseService::class)->create([
            'branch_id' => $branch->id,
            'expense_account_id' => ChartOfAccount::where('default_role', ChartOfAccount::ROLE_EXPENSE)->value('id'),
            'expense_date' => '2026-07-15', 'supplier_name' => 'บริษัท ทดสอบ จำกัด',
            'supplier_tax_id' => '0105555555555', 'tax_branch' => '00000', 'description' => 'ค่าบริการ',
            'base_amount' => 100, 'vat_amount' => 0, 'withholding_rate' => 3, 'withholding_amount' => 3,
            'withholding_form' => 'PND53', 'payment_method' => 'cash',
        ]);

        $run = app(TaxComplianceService::class)->prepareFiling('2026-07', $branch->id, 'PND53');
        $this->assertSame('prepared', $run->status);
        $this->assertSame(1, $run->document_count);
        $this->assertSame(64, strlen($run->file_hash));
        Storage::disk('local')->assertExists($run->file_name);
    }

    public function test_positive_statement_auto_matches_unused_pos_transfer_once(): void
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $bank = BankAccount::create(['branch_id' => $branch->id, 'bank_name' => 'TEST BANK', 'account_no' => '123']);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS01', 'name' => 'POS 1']);
        $receipt = PosReceipt::create(['pos_terminal_id' => $terminal->id, 'receipt_no' => 'R001', 'receipt_date' => '2026-07-15 10:00:00', 'net_sales' => 250, 'status' => 'completed']);
        $payment = PosPayment::create(['pos_receipt_id' => $receipt->id, 'method' => 'qr', 'amount' => 250]);
        $statement = BankStatement::create(['bank_account_id' => $bank->id, 'statement_date' => '2026-07-15', 'description' => 'QR R001', 'amount' => 250, 'reconciled' => false]);
        $user = User::factory()->create(['username' => 'finance-user']);
        $this->actingAs($user);
        $request = Request::create('/monthly-accounting/statements/auto-reconcile', 'POST', ['period' => '2026-07', 'branch_id' => $branch->id]);

        (new MonthlyAccountingController)->autoReconcile($request);

        $this->assertDatabaseHas('bank_reconciliations', ['bank_statement_id' => $statement->id, 'source_type' => 'pos_payment', 'source_id' => $payment->id, 'status' => 'matched']);
    }

    public function test_control_center_pages_render_for_authorized_user(): void
    {
        $user = User::factory()->create(['username' => 'control-user', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'CONTROL', 'name' => 'Control Center']);
        $permissions = collect(['finance.manage', 'settings.manage', 'reports.view'])->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code]));
        $role->permissions()->sync($permissions->pluck('id')->all());
        $user->roles()->attach($role->id);
        $this->actingAs($user);
        $this->get('/operations')->assertOk()->assertSee('ศูนย์ Backup และ Security');
        $this->get('/tax-compliance')->assertOk()->assertSee('ศูนย์ภาษีไทยและ E-Tax');
        $this->get('/accounting-periods')->assertOk()->assertSee('งวดบัญชีและการปิดงวด');
        $this->get('/core-modules')->assertOk()->assertSee('คู่มือควบคุมระบบ 1-5 แบบละเอียด');
        $this->get('/security/mfa')->assertOk()->assertSee('Setup key');
    }

    private function totpCode(string $secret, int $timestamp): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr(bindec($chunk));
            }
        }
        $counter = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4_294_967_296), $counter % 4_294_967_296), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
