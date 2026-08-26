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

    public function test_redirect_flag_replaces_selling_with_a_status_page(): void
    {
        AppSetting::set('pos_web_mode', 'redirect');
        $this->actingAs($this->cashier())->get('/pos')
            ->assertOk()
            ->assertViewIs('pos.retired')
            ->assertSee('ย้ายไปแอปเดสก์ท็อป')
            ->assertSee(route('python-pos.download'));
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
