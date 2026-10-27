<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Middleware\ErpAuthorize;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\PosDevice;
use App\Models\QrPaymentConfig;
use App\Models\User;
use App\Services\Accounting\GlPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * บัญชีรับเงินรายสาขา: สาขาที่ตั้งบัญชีธนาคาร + บัญชี GL ไว้ เงินโอน/QR/เช็คลงบัญชีของสาขานั้น
 * สาขาที่ยังว่างใช้บัญชีกลางต่อได้ ไม่สะดุดการขาย
 */
class BranchBankAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_any_setup_bank_money_still_goes_to_the_default_bank_account(): void
    {
        $branch = $this->branch('B006');

        $this->assertSame(
            ChartOfAccount::where('default_role', ChartOfAccount::ROLE_BANK)->value('id'),
            app(GlPostingService::class)->bankAccountFor($branch->id)?->id,
        );
    }

    public function test_a_branch_with_its_own_bank_account_posts_to_that_gl_account(): void
    {
        $amnat = $this->branch('B006');
        $warin = $this->branch('B001');
        $amnatGl = $this->glAccount('1020-06', 'เงินฝาก - อำนาจเจริญ');
        BankAccount::create(['branch_id' => $amnat->id, 'bank_name' => 'กสิกรไทย', 'account_no' => '111-1-11111-1', 'chart_of_account_id' => $amnatGl->id]);

        $service = app(GlPostingService::class);
        $this->assertSame($amnatGl->id, $service->bankAccountFor($amnat->id)->id);
        // สาขาที่ยังว่างไม่ได้เงินเข้าบัญชีของสาขาอื่น
        $this->assertNotSame($amnatGl->id, $service->bankAccountFor($warin->id)->id);
    }

    public function test_branches_left_empty_fall_back_to_the_central_bank_account(): void
    {
        $amnat = $this->branch('B006');
        $warin = $this->branch('B001');
        $centralGl = $this->glAccount('1020-00', 'เงินฝาก - บัญชีกลาง');
        $amnatGl = $this->glAccount('1020-06', 'เงินฝาก - อำนาจเจริญ');
        BankAccount::create(['branch_id' => null, 'bank_name' => 'กรุงไทย', 'account_no' => '999-0-00000-0', 'chart_of_account_id' => $centralGl->id]);
        BankAccount::create(['branch_id' => $amnat->id, 'bank_name' => 'กสิกรไทย', 'account_no' => '111-1-11111-1', 'chart_of_account_id' => $amnatGl->id]);

        $service = app(GlPostingService::class);
        $this->assertSame($amnatGl->id, $service->bankAccountFor($amnat->id)->id);
        $this->assertSame($centralGl->id, $service->bankAccountFor($warin->id)->id);
    }

    public function test_a_bank_account_not_yet_linked_to_gl_is_ignored(): void
    {
        $amnat = $this->branch('B006');
        // กรอกเลขบัญชีแล้วแต่ยังไม่ผูก GL = ยังไม่พร้อม ต้องไม่ทำให้ลง GL ไม่ได้
        BankAccount::create(['branch_id' => $amnat->id, 'bank_name' => 'กสิกรไทย', 'account_no' => '111-1-11111-1']);

        $this->assertSame(
            ChartOfAccount::where('default_role', ChartOfAccount::ROLE_BANK)->value('id'),
            app(GlPostingService::class)->bankAccountFor($amnat->id)->id,
        );
    }

    public function test_the_till_gets_its_branch_qr_before_the_central_one(): void
    {
        $amnat = $this->branch('B006');
        $central = BankAccount::create(['branch_id' => null, 'bank_name' => 'กลาง', 'account_no' => '000']);
        $own = BankAccount::create(['branch_id' => $amnat->id, 'bank_name' => 'สาขา', 'account_no' => '006']);
        QrPaymentConfig::create(['code' => 'QR-CENTRAL', 'name' => 'QR กลาง', 'qr_type' => 'dynamic', 'bank_account_id' => $central->id, 'merchant_ref' => '0100000000000', 'is_active' => true]);
        QrPaymentConfig::create(['code' => 'QR-B006', 'name' => 'QR อำนาจเจริญ', 'qr_type' => 'dynamic', 'bank_account_id' => $own->id, 'merchant_ref' => '0100000000006', 'is_active' => true]);

        $this->assertSame('QR-B006', $this->pingQr($amnat));
        $this->assertSame('QR-CENTRAL', $this->pingQr($this->branch('B001')));
    }

    public function test_bank_page_lists_every_branch_with_empty_slots_waiting_for_an_account(): void
    {
        $amnat = $this->branch('B006');
        $this->branch('B001');
        $gl = $this->glAccount('1020-06', 'เงินฝาก - อำนาจเจริญ');
        BankAccount::create(['branch_id' => $amnat->id, 'bank_name' => 'กสิกรไทย', 'account_no' => '111-1-11111-1', 'chart_of_account_id' => $gl->id]);
        $this->actingAs(User::factory()->create(['username' => 'bank_admin']));
        $this->withoutMiddleware(ErpAuthorize::class);

        $this->get(route('bank-accounts.index'))->assertOk()
            ->assertSee('บัญชีรับเงินรายสาขา')
            ->assertSee('111-1-11111-1')
            ->assertSee('1020-06')
            ->assertSee('ยังว่าง — ใช้บัญชีกลาง');
    }

    public function test_saving_a_bank_account_links_it_to_a_gl_account(): void
    {
        $amnat = $this->branch('B006');
        $gl = $this->glAccount('1020-06', 'เงินฝาก - อำนาจเจริญ');
        $this->actingAs(User::factory()->create(['username' => 'bank_admin']));
        $this->withoutMiddleware(ErpAuthorize::class);

        $this->post(route('bank-accounts.store'), [
            'branch_id' => $amnat->id, 'bank_name' => 'กสิกรไทย', 'account_no' => '111-1-11111-1',
            'account_name' => 'บริษัท ป๊อบสตาร์', 'chart_of_account_id' => $gl->id,
        ])->assertRedirect();

        $this->assertSame($gl->id, BankAccount::where('account_no', '111-1-11111-1')->value('chart_of_account_id'));
    }

    private function pingQr(Branch $branch): ?string
    {
        $user = User::factory()->create(['username' => 'till_'.uniqid()]);
        $device = PosDevice::create([
            'name' => 'POS '.$branch->code, 'user_id' => $user->id, 'branch_id' => $branch->id,
            'terminal_code' => 'T-'.$branch->code.'-'.uniqid(), 'token_hash' => hash('sha256', uniqid()),
        ]);
        $request = Request::create('/api/pos/ping', 'GET');
        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => $user);

        return app(PosApiController::class)->ping($request)->getData(true)['qr_payment']['code'] ?? null;
    }

    private function branch(string $code): Branch
    {
        return Branch::firstOrCreate(['code' => $code.'-T'], ['name_th' => 'สาขา '.$code, 'is_active' => true]);
    }

    private function glAccount(string $code, string $name): ChartOfAccount
    {
        return ChartOfAccount::create(['code' => $code, 'name_th' => $name, 'account_type' => 'asset']);
    }
}
