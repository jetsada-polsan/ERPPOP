<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้ารวมโมดูล (App Launcher)
 *
 * สิ่งที่ต้องคุมคือ "เห็นเฉพาะของที่ตัวเองมีสิทธิ์" — การ์ดที่กดแล้วเจอ 403
 * แย่กว่าการไม่แสดงการ์ดนั้นเลย และเมนูต้องมาจากแหล่งเดียวกับ layout
 * ไม่ใช่รายการที่คัดลอกไปไว้อีกที่แล้วหลุดจากกันภายหลัง
 */
class AppLauncherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        /* เทสต์ไม่ควรผูกกับ asset ที่ build แล้ว — หน้าจริงต้องมี build เสมอ
           แต่ความถูกต้องของสิทธิ์และข้อมูลตรวจได้โดยไม่ต้องรัน vite */
        $this->withoutVite();
    }

    private function userWith(array $codes): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'launcher-'.++$sequence, 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'LAUNCH_'.$sequence, 'name' => 'launcher '.$sequence]);
        foreach ($codes as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_guests_cannot_open_the_launcher(): void
    {
        $this->get(route('apps.launcher'))->assertRedirect(route('login'));
    }

    public function test_it_renders_the_mount_point_with_section_data(): void
    {
        $this->actingAs($this->userWith([]))->get(route('apps.launcher'))
            ->assertOk()
            ->assertSee('id="erp-app-launcher"', false)
            ->assertSee('data-sections=', false);
    }

    /** ข้อกำหนด: "ซ่อนโมดูลตามสิทธิ์ผู้ใช้" */
    public function test_it_hides_modules_the_user_has_no_permission_for(): void
    {
        $this->actingAs($this->userWith([]))->get(route('apps.launcher'))
            ->assertOk()
            ->assertDontSee('ผังบัญชี / บันทึกบัญชี');

        $this->actingAs($this->userWith(['finance.manage']))->get(route('apps.launcher'))
            ->assertOk()
            ->assertSee('ผังบัญชี / บันทึกบัญชี');
    }

    /** launcher กับเมนูข้างต้องเดินจาก ErpMenu ตัวเดียวกัน */
    public function test_it_uses_the_same_menu_source_as_the_sidebar(): void
    {
        $user = $this->userWith(['finance.manage']);

        $expected = collect(ErpMenu::forUser($user))
            ->flatMap(fn (array $section) => array_column($section['items'], 'label'))
            ->sort()->values()->all();

        $sections = $this->actingAs($user)->get(route('apps.launcher'))->viewData('sections');
        $actual = collect($sections)
            ->flatMap(fn (array $section) => array_column($section['items'], 'label'))
            ->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertNotEmpty($actual);
    }

    /** ทุกการ์ดต้องมี URL ที่ใช้ได้จริง ไม่ใช่ # */
    public function test_every_card_points_at_a_real_route(): void
    {
        $sections = $this->actingAs($this->userWith(['finance.manage']))
            ->get(route('apps.launcher'))->viewData('sections');

        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $this->assertStringStartsWith('http', $item['url'], "การ์ด {$item['label']} ไม่มี URL");
            }
        }
    }

    /** ใช้ได้ทั้งสอง layout ตามค่า erp_layout */
    public function test_it_opens_in_both_layouts(): void
    {
        $user = $this->userWith([]);

        foreach (['classic', 'odoo'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            $this->actingAs($user)->get(route('apps.launcher'))->assertOk();
        }
    }
}
