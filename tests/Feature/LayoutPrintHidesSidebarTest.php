<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * กฎของจอ ".app-sidebar.odn-side{display:block!important}" หนักกว่ากฎซ่อนเมนูแบบสั้นในส่วนพิมพ์
 * ผลคือเมนูข้างโผล่บนกระดาษและเนื้อหาหน้าว่าง ต้องมีกฎซ่อนที่หนักกว่าอยู่ท้าย stylesheet เสมอ
 */
class LayoutPrintHidesSidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_rule_hiding_the_sidebar_outweighs_the_screen_rule_and_comes_last(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs(User::factory()->create());

        $html = $this->get('/bookings/summary')->assertOk()->getContent();

        $screenRule = strpos($html, 'html[data-layout="classic"] .app-sidebar.odn-side {');
        $this->assertNotFalse($screenRule, 'กฎของจอที่บังคับแสดงเมนูต้องยังอยู่ (ถ้าเปลี่ยนชื่อ ให้ปรับเทสต์นี้)');

        $printRule = strrpos($html, 'html[data-layout] body .app-sidebar.odn-side');
        $this->assertNotFalse($printRule, 'ต้องมีกฎซ่อนเมนูข้างตอนพิมพ์ที่ระบุ html[data-layout] body เพื่อให้หนักกว่ากฎของจอ');
        $this->assertGreaterThan($screenRule, $printRule, 'กฎซ่อนตอนพิมพ์ต้องอยู่หลังกฎของจอ');

        $before = substr($html, 0, $printRule);
        $this->assertGreaterThan(strrpos($before, '}'), strrpos($before, '@media print'), 'กฎซ่อนต้องอยู่ภายใน @media print');
        $this->assertStringContainsString('display:none !important', substr($html, $printRule, 420));
    }
}
