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
            ->assertSee('โหลดทีละ 100 รายการ')
            ->assertSee('discountCardCode')
            ->assertSee('paymentQr')
            ->assertSee('topContext')
            ->assertSee('settingsShiftButton')
            ->assertSee('55fr')
            ->assertSee('45fr')
            ->assertSee('repeat(3, minmax(124px, 1fr))')
            ->assertSee('grid-auto-rows: minmax(124px, auto)')
            ->assertDontSee('id="sellerPanel"')
            ->assertSee('new URLSearchParams')
            ->assertDontSee('&all=1')
            ->assertDontSee('เข้าสู่ระบบ ERP');
    }
}
