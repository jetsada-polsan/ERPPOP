<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\User;
use App\Support\PosLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POS Designer ต้องเป็นหน้าที่ "ตั้งค่าแล้วมีผลจริง" ไม่ใช่แค่หน้าพรีวิว
 * ทุกเทสต์ที่นี่จึงตามค่าเดินทางตั้งแต่ฟอร์ม -> AppSetting -> หน้าขาย/ API
 */
class PosLayoutDesignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_sees_the_runtime_controls_not_only_the_canvas(): void
    {
        $this->actingAs($this->admin())->get('/settings/pos-designer')
            ->assertOk()
            ->assertSee('POS Designer')
            ->assertSee('ค่าหน้าขายจริง (Runtime)')
            ->assertSee('runtime[product_width]', false)
            ->assertSee('runtime[cart_width]', false)
            ->assertSee('runtime[product_rows]', false)
            ->assertSee('runtime[product_columns]', false)
            ->assertSee('runtime[density]', false)
            ->assertSee('runtime[button_size]', false)
            ->assertSee('runtime[show_branch]', false)
            ->assertSee('runtime[show_terminal]', false)
            ->assertSee('runtime[show_seller]', false)
            ->assertSee('runtime[show_shift]', false)
            ->assertSee('คืนค่าเริ่มต้น');
    }

    public function test_a_cashier_cannot_open_or_save_the_designer(): void
    {
        $cashier = User::factory()->create([
            'username' => 'designer-cashier',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'POS_DESIGN_CASHIER', 'name' => 'Cashier']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'ใช้งาน POS'])->id);
        $cashier->roles()->attach($role->id);

        $this->actingAs($cashier)->get('/settings/pos-designer')->assertForbidden();
        $this->actingAs($cashier)->post('/settings/pos-designer', $this->payload())->assertForbidden();

        $this->assertNull(AppSetting::get('pos_layout_draft'));
    }

    public function test_saving_a_draft_keeps_the_published_layout_untouched(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', $this->payload(['product_width' => 60, 'cart_width' => 40, 'product_rows' => 4]))
            ->assertRedirect(route('settings.pos-designer'))
            ->assertSessionHas('success');

        $draft = json_decode((string) AppSetting::get('pos_layout_draft'), true);
        $this->assertSame(60, $draft['runtime']['product_width']);
        $this->assertSame(40, $draft['runtime']['cart_width']);
        $this->assertSame(4, $draft['runtime']['product_rows']);

        // ยังไม่ Publish = เครื่องสาขายังได้ค่าเดิม
        $this->assertNull(AppSetting::get('pos_layout_published'));
        $this->assertSame(0, PosLayout::publishedVersion());
    }

    public function test_publishing_bumps_the_layout_version_and_reaches_the_pos_api(): void
    {
        AppSetting::set('pos_layout_version', '6');

        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', $this->payload([
                'product_width' => 65,
                'cart_width' => 35,
                'product_columns' => 6,
                'density' => 'roomy',
                'button_size' => 'large',
                'show_shift' => '0',
            ], ['publish' => '1']))
            ->assertRedirect(route('settings.pos-designer'));

        $this->assertSame(7, PosLayout::publishedVersion());
        $this->assertNotNull(AppSetting::get('pos_layout_published_at'));

        $published = PosLayout::published();
        $this->assertSame(7, $published['version']);
        $this->assertSame(7, $published['layout_version']);
        $this->assertSame(65, $published['runtime']['product_width']);
        $this->assertSame(6, $published['runtime']['product_columns']);
        $this->assertSame('roomy', $published['runtime']['density']);
        $this->assertFalse($published['runtime']['show_shift']);

        // เครื่อง POS ต้องได้ทั้งค่า runtime และตัวแปร CSS ที่คำนวณแล้วจาก ping
        $response = $this->withToken($this->deviceToken())->getJson('/api/pos/ping');
        $response->assertOk()
            ->assertJsonPath('pos_layout.layout_version', 7)
            ->assertJsonPath('pos_layout.runtime.product_width', 65)
            ->assertJsonPath('pos_layout.runtime.button_size', 'large')
            ->assertJsonPath('pos_layout.css.--pos-product-fr', '65fr')
            ->assertJsonPath('pos_layout.css.--pos-button-min-height', '54px');

        // โครงเดิมที่ POS Python รุ่นที่ติดตั้งไปแล้วอ่านอยู่ต้องไม่หาย
        $response->assertJsonPath('pos_layout.schema', 'popcentral-pos-layout');
        $this->assertIsArray($response->json('pos_layout.components'));
    }

    public function test_out_of_range_values_are_rejected_with_a_message_instead_of_being_clamped(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', $this->payload(['product_rows' => 99, 'product_columns' => 1]))
            ->assertSessionHasErrors(['runtime.product_rows', 'runtime.product_columns']);

        $this->assertNull(AppSetting::get('pos_layout_draft'));
    }

    public function test_the_two_panes_must_fill_the_screen(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', $this->payload(['product_width' => 60, 'cart_width' => 60]))
            ->assertSessionHasErrors('runtime.cart_width');

        $this->assertNull(AppSetting::get('pos_layout_draft'));
    }

    public function test_a_layout_without_any_component_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', $this->payload([], ['layout' => json_encode(['version' => 1, 'components' => []])]))
            ->assertSessionHasErrors('layout');

        $this->assertNull(AppSetting::get('pos_layout_draft'));
    }

    public function test_reset_restores_the_default_draft_without_publishing(): void
    {
        AppSetting::set('pos_layout_draft', json_encode(['version' => 2, 'components' => [], 'runtime' => ['product_width' => 70, 'cart_width' => 30]]));

        $this->actingAs($this->admin())
            ->post('/settings/pos-designer', ['reset' => '1'])
            ->assertRedirect(route('settings.pos-designer'))
            ->assertSessionHas('success');

        $draft = json_decode((string) AppSetting::get('pos_layout_draft'), true);
        $this->assertSame(PosLayout::defaultRuntime(), $draft['runtime']);
        $this->assertNotEmpty($draft['components']);
        $this->assertNull(AppSetting::get('pos_layout_published'));
    }

    public function test_a_layout_stored_before_runtime_existed_still_opens_with_safe_defaults(): void
    {
        // แบบร่างรุ่นเก่าไม่มีคีย์ runtime เลย — หน้า Designer ต้องไม่ล้ม
        AppSetting::set('pos_layout_draft', json_encode([
            'schema' => 'popcentral-pos-layout',
            'version' => 3,
            'components' => [['id' => 'cart', 'type' => 'cart', 'x' => 8, 'y' => 1, 'w' => 9, 'h' => 5]],
        ]));

        $this->actingAs($this->admin())->get('/settings/pos-designer')->assertOk();

        $draft = PosLayout::draft();
        $this->assertSame(PosLayout::defaultRuntime(), $draft['runtime']);
        // w=9 ที่ x=8 จะล้นขอบ canvas ต้องถูกหดให้พอดี 12 ช่อง
        $this->assertSame(5, $draft['components'][0]['w']);
    }

    /** @return array<string, mixed> */
    private function payload(array $runtime = [], array $extra = []): array
    {
        return array_merge([
            'layout' => json_encode([
                'version' => 1,
                'components' => [
                    ['id' => 'products', 'type' => 'product_grid', 'x' => 1, 'y' => 3, 'w' => 7, 'h' => 5],
                    ['id' => 'cart', 'type' => 'cart', 'x' => 8, 'y' => 1, 'w' => 5, 'h' => 5],
                ],
            ]),
            'runtime' => array_merge([
                'product_width' => 55,
                'cart_width' => 45,
                'product_rows' => 3,
                'product_columns' => 4,
                'density' => 'comfortable',
                'button_size' => 'medium',
                'show_branch' => '1',
                'show_terminal' => '1',
                'show_seller' => '1',
                'show_shift' => '1',
            ], $runtime),
        ], $extra);
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'username' => 'pos-designer-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::firstOrCreate(['code' => 'POS_DESIGN_ADMIN'], ['name' => 'POS Design Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'จัดการตั้งค่า'])->id
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function deviceToken(): string
    {
        $branch = Branch::create(['code' => 'LAYOUT', 'name_th' => 'สาขาเทสต์ layout', 'is_active' => true]);
        $seller = User::factory()->create(['username' => 'layout-seller-'.Str::lower(Str::random(6)), 'branch_id' => $branch->id]);
        $role = Role::firstOrCreate(['code' => 'POS_SELLER'], ['name' => 'POS Seller']);
        $role->permissions()->syncWithoutDetaching(
            Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell'])->id
        );
        $seller->roles()->syncWithoutDetaching([$role->id]);

        [, $token] = PosDevice::issue(['name' => 'POS layout', 'user_id' => $seller->id, 'branch_id' => $branch->id]);

        return $token;
    }
}
