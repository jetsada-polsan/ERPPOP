<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Member;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberListLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_list_precedes_the_collapsed_add_form(): void
    {
        Member::create(['member_code' => 'MEM-1', 'name' => 'สมาชิกหนึ่ง', 'is_active' => true]);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('members.index'))->assertOk()->assertSeeInOrder(['รายการสมาชิก', 'สมาชิกหนึ่ง', 'เพิ่มสมาชิก'])
            ->assertSee('<details><summary', false);
    }

}
