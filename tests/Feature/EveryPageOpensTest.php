<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * เจ้าของ: "ทุกหน้าเช็คอย่าให้มี 500"
 * เปิดทุกหน้า GET ที่ไม่ต้องใส่พารามิเตอร์ด้วยผู้ใช้ GM บนข้อมูลตั้งต้น ห้ามได้ 5xx
 */
class EveryPageOpensTest extends TestCase
{
    use RefreshDatabase;

    /** หน้าที่ตั้งใจไม่ใช่หน้าจอ (ดาวน์โหลด/ส่งออก/API/สตรีม) */
    private const SKIP_PATTERNS = ['download', 'export', 'api/', 'sanctum', 'up', 'storage', 'stream', 'logout', 'csv', 'xlsx', 'pdf', 'backup', 'heartbeat', 'sync', 'webhook', 'manifest', 'sw.js', 'oauth', 'callback'];

    public function test_every_parameterless_page_opens_without_a_server_error(): void
    {
        $this->seed();
        $this->withoutVite(); // เครื่องเทสต์ไม่มีไฟล์ build ของ Vite (production มี)
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));

        $failures = [];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue;
            }
            $uri = $route->uri();
            foreach (self::SKIP_PATTERNS as $skip) {
                if (str_contains($uri, $skip)) {
                    continue 2;
                }
            }
            $checked++;
            $status = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get('/'.ltrim($uri, '/'))->status();
            if ($status >= 500) {
                $failures[] = "{$status} /{$uri}";
            }
        }

        $this->assertGreaterThan(50, $checked, 'ควรตรวจหน้าได้จำนวนมาก');
        $this->assertSame([], $failures, "หน้าที่เปิดแล้วได้ 5xx:\n".implode("\n", $failures));
    }
}
