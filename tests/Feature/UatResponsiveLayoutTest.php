<?php

namespace Tests\Feature;

use Tests\TestCase;

class UatResponsiveLayoutTest extends TestCase
{
    public function test_shared_header_reserves_space_and_wraps_the_title_and_tools(): void
    {
        $layout = file_get_contents(resource_path('views/layout.blade.php'));
        $this->assertStringContainsString('navbar-nav align-items-center erp-page-heading', $layout);
        $this->assertStringContainsString('flex-wrap: wrap; gap: 8px 16px', $layout);
        $this->assertStringContainsString('overflow-wrap: anywhere; white-space: normal', $layout);
        $this->assertStringNotContainsString('nav-item d-none d-md-block ms-2', $layout);
    }

    public function test_plu_table_preserves_all_columns_as_labeled_cards_on_small_screens(): void
    {
        $view = file_get_contents(resource_path('views/scale-prices/index.blade.php'));
        $this->assertStringContainsString('scale-plu-table', $view);
        $this->assertStringContainsString('max-width: 1024px', $view);
        foreach (['PLU', 'SKU / ชื่อ / สถานะ', 'ราคา ERP/กก.', 'ราคาเครื่องชั่ง/กก.', 'ปัญหา / วิธีแก้', 'จัดการ'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $view);
        }
        $this->assertStringContainsString('min-width: 0 !important', $view);
    }
}
