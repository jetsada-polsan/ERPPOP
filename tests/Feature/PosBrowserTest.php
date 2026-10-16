<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Web POS สาธารณะที่ pos.popstarcenter.com เลิกใช้แล้ว (ซ้ำกับ /pos และ POS Python)
 * โดเมนนี้ต้องพาไปหน้าดาวน์โหลด POS ไม่ใช่หน้าขาย
 */
class PosBrowserTest extends TestCase
{
    public function test_pos_subdomain_sends_cashiers_to_the_pos_download(): void
    {
        $this->get('http://pos.popstarcenter.com/')
            ->assertRedirect(route('python-pos.download'));
    }

    public function test_main_domain_still_shows_the_portal(): void
    {
        $this->get('/')->assertOk()->assertViewIs('portal');
    }
}
