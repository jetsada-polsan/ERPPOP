<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\{AppSetting, Product, ProductUnit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatLocalVisualFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_and_plu_pages_render_in_both_layouts(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs(User::factory()->create(['is_active' => true, 'must_change_password' => false]));
        $unit = ProductUnit::firstOrCreate(['code' => 'UAT-VIS'], ['name' => 'กก.']);
        Product::create(['sku_code' => '801123', 'name_th' => 'สินค้าชั่งชื่อยาวสำหรับตรวจการห่อข้อความและปัญหาที่ต้องอ่านได้ครบทุกบรรทัด',
            'base_unit_id' => $unit->id, 'default_price' => 99.50, 'is_active' => true]);
        foreach (['classic', 'odoo'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            foreach (['quotations', 'billing-notes', 'scale-prices'] as $page) {
                $response = $this->get(route($page.'.index'))->assertOk();
                $response->assertSee('erp-page-heading', false);
                if ($dir = getenv('ERP_UAT_SNAPSHOT_DIR')) {
                    file_put_contents($dir.'/'.$page.'-'.$layout.'.html', str_replace('http://localhost', 'http://127.0.0.1:8876', $response->getContent()));
                }
            }
        }
    }
}
