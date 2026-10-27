<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnassignedBranchSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_filter_finds_active_users_without_changing_their_branch(): void
    {
        $branch = Branch::create(['code' => 'SETUP', 'name_th' => 'สาขา']);
        $admin = User::factory()->create(['username' => 'setupadmin', 'branch_id' => $branch->id]);
        $missing = User::factory()->create(['username' => 'missingbranch', 'branch_id' => null, 'is_active' => true]);
        User::factory()->create(['username' => 'hasbranch', 'branch_id' => $branch->id]);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($admin)
            ->get(route('users.index', ['status' => 'unassigned']))->assertOk()
            ->assertViewHas('users', fn ($users) => $users->count() === 1 && $users->first()->id === $missing->id)
            ->assertViewHas('unassignedBranchCount', 1);
        $this->assertNull($missing->fresh()->branch_id);
    }
}
