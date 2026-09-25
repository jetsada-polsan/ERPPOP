<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_api_rejects_requests_without_member_session(): void
    {
        $this->getJson('/api/member/me')->assertUnauthorized();
    }

    public function test_member_api_uses_the_member_from_the_server_session(): void
    {
        $member = Member::create([
            'member_code' => 'M-PORTAL-1', 'name' => 'สมาชิกทดสอบ',
            'phone' => '0812345678', 'phone_normalized' => '0812345678',
            'points' => 2450, 'is_active' => true,
        ]);

        $this->withSession(['member_portal_member_id' => $member->id])
            ->getJson('/api/member/me')
            ->assertOk()
            ->assertJsonPath('member.member_code', 'M-PORTAL-1')
            ->assertJsonPath('member.points', 2450);
    }

    public function test_liff_auth_cannot_use_a_fake_browser_member_id(): void
    {
        $this->postJson('/api/member/auth/liff', ['id_token' => 'not-a-token'])
            ->assertStatus(503);
        $this->assertFalse(session()->has('member_portal_member_id'));
    }
}
