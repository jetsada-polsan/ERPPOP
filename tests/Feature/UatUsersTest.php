<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UatUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_users_with_approval_roles_then_disables_them(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'B001'], ['name_th' => 'อำนาจเจริญ', 'is_active' => true]);

        $this->artisan('erp:uat-users', ['--dry-run' => true])->assertSuccessful();
        $this->assertFalse(User::where('username', 'uat-staff')->exists());

        $this->artisan('erp:uat-users')->assertSuccessful();
        $mgr = User::where('username', 'uat-branch-mgr')->first();
        $this->assertSame($branch->id, $mgr->branch_id);
        $this->assertTrue($mgr->hasPermission('purchasing.approve'));
        $this->assertTrue(User::where('username', 'uat-acc-mgr')->first()->hasPermission('finance.note.approve'));
        $this->assertFalse(User::where('username', 'uat-staff')->first()->hasPermission('purchasing.approve'));

        $old = $mgr->password;
        $this->artisan('erp:uat-users', ['--remove' => true])->assertSuccessful();
        $mgr->refresh();
        $this->assertFalse($mgr->is_active);
        $this->assertNotSame($old, $mgr->password);
        $this->assertFalse(Hash::check('', $mgr->password));
    }
}
