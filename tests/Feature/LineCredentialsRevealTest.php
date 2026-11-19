<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LineCredentialsRevealTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-access-token-AAAAAAAAAAAAAAAAAAAAAAAAAAAA-ends-W9xZ';

    private const SECRET = 'test-channel-secret-0123456789abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'services.line.channel_id' => '2001234567',
            'services.line.channel_secret' => self::SECRET,
            'services.line.channel_access_token' => self::TOKEN,
            'services.line.liff_id' => '2001234567-AbCdEfGh',
            'services.line.login_channel_id' => '',
        ]);
    }

    private function user(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false, 'password' => Hash::make('correct-horse-9')]);
        $role = Role::create(['code' => 'ROLE_'.strtoupper(uniqid()), 'name' => 'ทดสอบ']);
        $role->permissions()->sync(collect($permissions)->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id));
        $user->roles()->sync([$role->id]);

        return $user->fresh();
    }

    public function test_page_shows_ids_but_masks_secret_and_token(): void
    {
        $html = $this->actingAs($this->user(['settings.manage', 'users.manage']))
            ->get(route('line-integrations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ค่า LINE OA ที่ระบบใช้อยู่', $html);
        $this->assertStringContainsString('2001234567-AbCdEfGh', $html);   // LIFF ID ไม่ใช่ความลับ แสดงเลย
        $this->assertStringContainsString('W9xZ', $html);                  // 4 ตัวท้ายของ token พอให้รู้ว่าตัวไหน
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringContainsString('ยังไม่ตั้งค่า', $html);
        $this->assertStringContainsString('แสดงค่าเต็ม', $html);
    }

    public function test_admin_sees_full_values_only_after_reentering_password_and_it_is_audited(): void
    {
        $admin = $this->user(['settings.manage', 'users.manage']);

        $this->actingAs($admin)->post(route('line-integrations.credentials.reveal'), ['password' => 'wrong'])
            ->assertRedirect(route('line-integrations.index'))->assertSessionHasErrors('password');
        $this->assertSame(1, AuditLog::where('action', 'line_credentials_reveal_failed')->count());

        $response = $this->actingAs($admin)->post(route('line-integrations.credentials.reveal'), ['password' => 'correct-horse-9']);
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = $response->getContent();
        $this->assertStringContainsString(self::TOKEN, $html);
        $this->assertStringContainsString(self::SECRET, $html);
        $this->assertStringContainsString('กำลังแสดงค่าเต็ม', $html);

        $log = AuditLog::where('action', 'line_credentials_reveal')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($log->new_values));
        $this->assertNull(session('line_credentials'));

        // รีโหลดหน้าแล้วกลับมาปิดบัง
        $this->assertStringNotContainsString(self::TOKEN, $this->actingAs($admin)->get(route('line-integrations.index'))->getContent());
    }

    public function test_settings_operator_without_user_admin_cannot_reveal(): void
    {
        $operator = $this->user(['settings.manage']);

        $html = $this->actingAs($operator)->get(route('line-integrations.index'))->assertOk()->getContent();
        $this->assertStringContainsString('ดูค่าเต็มได้เฉพาะผู้ดูแลระบบ', $html);
        $this->actingAs($operator)->post(route('line-integrations.credentials.reveal'), ['password' => 'correct-horse-9'])->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'like', 'line_credentials_reveal%')->count());
    }
}
