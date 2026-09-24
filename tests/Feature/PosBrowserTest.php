<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosBrowserTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('weightModal')
            ->assertSee('quantityModal')
            ->assertSee('กรอกน้ำหนัก')
            ->assertSee('กรอกจำนวน')
            ->assertSee('is_scale')
            ->assertSee('แก้น้ำหนัก')
            ->assertSee('scanProduct')
            ->assertSee('topContext')
            ->assertSee('settingsShiftButton')
            // Web POS เน้นขายสินค้า; ลูกค้า/สมาชิกเป็นงานเสริม ไม่แทรกในหน้าหลัก
            ->assertDontSee('สมาชิก')
            // สัดส่วนและจำนวนแถวต้องมาจากตัวแปร layout ไม่ใช่ค่าที่ฝังไว้ในกฎ CSS
            ->assertSee('--pos-product-fr: 55fr')
            ->assertSee('--pos-cart-fr: 45fr')
            ->assertSee('grid-template-columns: minmax(0, var(--pos-product-fr)) minmax(var(--pos-cart-min), var(--pos-cart-fr))')
            ->assertSee('repeat(var(--pos-product-rows), minmax(var(--pos-card-min-height), 1fr))')
            ->assertSee('grid-auto-rows: minmax(var(--pos-card-min-height), auto)')
            ->assertSee('grid-template-rows: repeat(var(--pos-product-rows), var(--pos-card-min-height))')
            ->assertSee('min-height: var(--pos-card-min-height)')
            ->assertDontSee('minmax(0, 55fr)')
            ->assertDontSee('repeat(3, minmax(124px, 1fr))')
            ->assertDontSee('id="sellerPanel"')
            ->assertSee('new URLSearchParams')
            ->assertDontSee('&all=1')
            ->assertDontSee('เข้าสู่ระบบ ERP');
    }

    public function test_the_published_layout_drives_the_css_the_browser_pos_renders(): void
    {
        AppSetting::set('pos_layout_published', json_encode([
            'schema' => 'popcentral-pos-layout',
            'version' => 4,
            'components' => [['id' => 'cart', 'type' => 'cart', 'x' => 8, 'y' => 1, 'w' => 5, 'h' => 5]],
            'runtime' => [
                'product_width' => 65, 'cart_width' => 35,
                'product_rows' => 5, 'product_columns' => 6,
                'density' => 'compact', 'button_size' => 'large',
                'show_branch' => true, 'show_terminal' => false,
                'show_seller' => true, 'show_shift' => false,
            ],
        ], JSON_UNESCAPED_UNICODE));
        AppSetting::set('pos_layout_version', '4');

        $response = $this->get('http://pos.popstarcenter.com/');

        $response->assertOk()
            ->assertSee('--pos-product-fr: 65fr')
            ->assertSee('--pos-cart-fr: 35fr')
            ->assertSee('--pos-product-rows: 5')
            ->assertSee('--pos-product-columns: 6')
            // compact + large มาจากตาราง metric ฝั่งเซิร์ฟเวอร์ ไม่ใช่ค่าที่หน้าเว็บเดาเอง
            ->assertSee('--pos-card-min-height: 104px')
            ->assertSee('--pos-button-min-height: 54px')
            // ชิปที่ปิดไว้ต้องถูกซ่อนตั้งแต่ HTML ก้อนแรก ไม่ใช่รอ JavaScript
            ->assertSee('id="deviceContext" class="context-item hidden"', false)
            ->assertSee('id="shiftSummary" class="context-item hidden"', false)
            ->assertSee('id="branchContext" class="context-item "', false);
    }

    public function test_the_browser_pos_still_renders_when_no_layout_was_ever_published(): void
    {
        $this->get('http://pos.popstarcenter.com/')
            ->assertOk()
            ->assertSee('--pos-product-fr: 55fr')
            ->assertSee('--pos-product-rows: 3');
    }
}
