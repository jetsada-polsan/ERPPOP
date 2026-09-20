<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosBrowserTest extends TestCase
{
    public function test_pos_subdomain_serves_the_public_browser_pos_shell(): void
    {
        $response = $this->get('http://pos.popstarcenter.com/');

        $response->assertOk()
            ->assertViewIs('pos.browser')
            ->assertSee('PopCentral Web POS')
            ->assertSee('เลือกชื่อคนขาย')
            ->assertSee('เปิดกะ')
            ->assertSee('ปิดกะ')
            ->assertDontSee('เข้าสู่ระบบ ERP');
    }
}
