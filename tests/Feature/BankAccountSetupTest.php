<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_account_setup_can_create_and_edit_the_company_account(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());
        $this->get(route('bank-accounts.index'))->assertOk()->assertSee('กด “เพิ่มบัญชี”');
        $this->post(route('bank-accounts.store'), ['bank_name' => 'ธนาคารทดสอบ', 'account_no' => 'TEST-ACCOUNT', 'account_name' => 'บริษัททดสอบ'])
            ->assertRedirect(route('bank-accounts.index'));
        $account = BankAccount::where('account_no', 'TEST-ACCOUNT')->sole();
        $this->put(route('bank-accounts.update', $account), ['bank_name' => 'ธนาคารแก้ไข', 'account_no' => 'TEST-ACCOUNT', 'account_name' => 'บริษัททดสอบ'])
            ->assertRedirect(route('bank-accounts.index'));
        $this->assertSame('ธนาคารแก้ไข', $account->fresh()->bank_name);
    }
}
