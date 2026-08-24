<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าตัวอย่างการออกแบบระบบหลังบ้าน
 *
 * สิ่งที่ต้องคุมคือ "เปิดได้ทุกหน้า" และ "ไม่แตะข้อมูลจริง" — หน้าตาสวยหรือไม่
 * ตรวจด้วยเทสต์ไม่ได้ แต่การเผลอไปอ่านหรือเขียนฐานข้อมูลจริงตรวจได้
 */
class ErpMockupTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $codes = ['settings.manage']): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'mockup-'.++$sequence, 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'MOCKUP_'.$sequence, 'name' => 'mockup '.$sequence]);
        foreach ($codes as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_every_mockup_page_opens(): void
    {
        $viewer = $this->viewer();

        foreach ([
            'erp-mockup.launcher', 'erp-mockup.dashboard', 'erp-mockup.products',
            'erp-mockup.product-form', 'erp-mockup.pos-orders', 'erp-mockup.inventory',
            'erp-mockup.purchase',
        ] as $route) {
            $this->actingAs($viewer)->get(route($route))->assertOk();
        }
    }

    public function test_the_pages_never_touch_the_real_database(): void
    {
        $viewer = $this->viewer();
        $before = Product::count();

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            // ไม่นับ query ของระบบสิทธิ์และ session ที่เกิดจากการล็อกอิน
            if (! preg_match('/\b(users|roles|permissions|sessions|role_user|permission_role|migrations)\b/i', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($viewer)->get(route('erp-mockup.dashboard'))->assertOk();
        $this->actingAs($viewer)->get(route('erp-mockup.products'))->assertOk();

        $this->assertSame([], $queries, "หน้าตัวอย่างต้องไม่ query ตารางธุรกิจ:\n".implode("\n", $queries));
        $this->assertSame($before, Product::count());
    }

    public function test_the_mock_pages_do_not_reuse_the_live_layout(): void
    {
        $body = $this->actingAs($this->viewer())->get(route('erp-mockup.dashboard'))->getContent();

        // มี CSS ของตัวเอง จึงลองดีไซน์ได้โดยไม่กระทบหน้าจริงที่ใช้งานอยู่
        $this->assertStringContainsString('--pop-primary', $body);
        $this->assertStringContainsString('ข้อมูลทั้งหมดเป็นข้อมูลจำลอง', $body);
    }

    public function test_someone_without_settings_permission_is_kept_out(): void
    {
        $this->actingAs($this->viewer(['reports.view']))
            ->get(route('erp-mockup.dashboard'))
            ->assertForbidden();
    }

    public function test_the_status_badges_cover_every_sync_state(): void
    {
        $body = $this->actingAs($this->viewer())->get(route('erp-mockup.pos-orders'))->getContent();

        foreach (['ซิงค์แล้ว', 'รอซิงค์', 'ซิงค์ล้มเหลว', 'ยกเลิกแล้ว'] as $label) {
            $this->assertStringContainsString($label, $body, "ต้องมีสถานะ {$label} ให้เห็นตัวอย่างสี");
        }
    }

    public function test_the_purchase_board_shows_all_six_stages(): void
    {
        $body = $this->actingAs($this->viewer())->get(route('erp-mockup.purchase'))->getContent();

        foreach (['ใบขอซื้อ', 'ยืนยันสั่งซื้อ', 'รอตรวจรับ', 'รับบางส่วน', 'รับครบแล้ว', 'ยกเลิก'] as $stage) {
            $this->assertStringContainsString($stage, $body);
        }
    }
}
