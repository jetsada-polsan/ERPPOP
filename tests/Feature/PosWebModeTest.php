<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWebModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // ทดสอบ logic ไม่ใช่ asset pipeline (pos-shared.css ไม่อยู่ใน manifest ของ env ทดสอบ)
    }

    private function cashier(): User
    {
        $user = User::factory()->create(['username' => 'pos-web-mode', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'POS_WEB', 'name' => 'POS Web']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'pos.use'])->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_web_pos_keeps_selling_by_default(): void
    {
        // flag ไม่ตั้ง = ขายได้ตามเดิม — cutover ต้องไม่เกิดเองโดยไม่สั่ง
        $this->actingAs($this->cashier())->get('/pos')
            ->assertOk()
            ->assertViewIs('pos.index');
    }

    public function test_a_pos_seller_can_open_the_pos_page_without_a_separate_view_permission(): void
    {
        $user = User::factory()->create([
            'username' => 'pos-sell-only',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'POS_SELL_ONLY', 'name' => 'POS Seller Only']);
        $role->permissions()->attach(
            Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS'])->id
        );
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/pos')
            ->assertOk()
            ->assertViewIs('pos.index');
    }

    public function test_redirect_flag_replaces_selling_with_a_status_page(): void
    {
        AppSetting::set('pos_web_mode', 'redirect');
        $this->actingAs($this->cashier())->get('/pos')
            ->assertOk()
            ->assertViewIs('pos.retired')
            ->assertSee('ย้ายไปแอปเดสก์ท็อป')
            ->assertSee(route('python-pos.download'));
    }

    public function test_published_pos_layout_can_be_previewed_without_writing_a_sale(): void
    {
        AppSetting::set('pos_layout_published', json_encode([
            'schema' => 'popcentral-pos-layout',
            'version' => 7,
            'canvas' => ['columns' => 12, 'rows' => 8],
            'components' => [
                ['id' => 'search', 'type' => 'search', 'x' => 1, 'y' => 1, 'w' => 7, 'h' => 1],
                ['id' => 'cart', 'type' => 'cart', 'x' => 8, 'y' => 1, 'w' => 5, 'h' => 5],
            ],
        ], JSON_UNESCAPED_UNICODE));
        AppSetting::set('pos_layout_version', '7');

        $this->actingAs($this->cashier())->get('/pos/preview')
            ->assertOk()
            ->assertViewIs('pos.preview')
            ->assertSee('ตัวอย่างจาก Build รุ่น 7')
            ->assertSee('Preview mode')
            ->assertSee('บิลปัจจุบัน');

        $this->assertDatabaseCount('pos_receipts', 0);
    }

    public function test_the_command_toggles_the_flag_both_ways(): void
    {
        $this->artisan('pos:web-mode redirect')->assertSuccessful();
        $this->assertSame('redirect', AppSetting::get('pos_web_mode'));
        $this->artisan('pos:web-mode sell')->assertSuccessful();
        $this->assertSame('sell', AppSetting::get('pos_web_mode'));
        $this->artisan('pos:web-mode nonsense')->assertFailed();
    }
}
