<?php

namespace Tests\Feature;

use App\Support\ErpMenu;
use Tests\TestCase;

/**
 * ไอคอนเมนูต้องมีอยู่จริงในชุด Bootstrap Icons ที่ติดตั้งไว้
 *
 * ชื่อไอคอนที่พิมพ์ผิดไม่ทำให้หน้าพัง มันแค่แสดงเป็นช่องว่าง ๆ ซึ่งไม่มี
 * ใครสังเกตจนกว่าจะมีคนไปเจอเอง — bi-calendar2-lock หายไปแบบนี้อยู่นาน
 */
class ErpMenuIconTest extends TestCase
{
    /** @return array<int, string> ชื่อไอคอนทั้งหมดที่ชุดที่ติดตั้งมีจริง */
    private function availableIcons(): array
    {
        $css = file_get_contents(public_path('vendor/bootstrap-icons/bootstrap-icons.min.css'));
        preg_match_all('/\.bi-([a-z0-9-]+)::before/', (string) $css, $matches);

        return array_unique($matches[1]);
    }

    public function test_every_menu_icon_exists_in_the_installed_icon_set(): void
    {
        $available = $this->availableIcons();
        $this->assertNotEmpty($available, 'อ่านชุดไอคอนไม่ได้');

        $missing = [];
        foreach (ErpMenu::all() as $section) {
            foreach ($section['items'] as $item) {
                $name = str_replace('bi-', '', $item['icon']);
                if (! in_array($name, $available, true)) {
                    $missing[] = "{$section['label']} / {$item['label']} → {$item['icon']}";
                }
            }
        }

        $this->assertSame([], $missing, "ไอคอนเหล่านี้ไม่มีอยู่จริง:\n".implode("\n", $missing));
    }

    public function test_every_section_icon_exists(): void
    {
        $available = $this->availableIcons();

        foreach (ErpMenu::SECTION_ICONS as $label => $icon) {
            $this->assertContains(
                str_replace('bi-', '', $icon),
                $available,
                "ไอคอนหมวด {$label} ({$icon}) ไม่มีอยู่จริง"
            );
        }
    }
}
