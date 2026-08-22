<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * รายงานที่เปิดใช้งานอยู่ทุกตัวต้องรันได้โดยไม่ระเบิด
 *
 * ก่อนหน้านี้รายงาน 48 ตัวไม่มีเทสต์แม้แต่ตัวเดียว ทั้งที่เป็น query เขียนมือยาว ๆ
 * ที่อ้างชื่อตารางและคอลัมน์ตรง ๆ — พิมพ์ผิดที่ไหนก็รู้ตอนผู้ใช้กดเท่านั้น
 *
 * เทสต์นี้ไม่ได้ตรวจว่ายอด "ถูก" (นั่นคืองานของ UAT เทียบยอดจริง)
 * แต่ตรวจว่า query รันผ่านบนฐานเปล่าและบนฐานที่มีข้อมูลขั้นต่ำ
 */
class ReportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_enabled_report_runs_without_error(): void
    {
        $user = $this->omniscientUser();
        $failures = [];

        foreach (ReportDefinition::runnable()->orderBy('code')->get() as $definition) {
            $url = sprintf(
                '/reports?category=%s&report=%s&from=2026-01-01&to=2026-12-31&branch_id=all',
                $definition->category,
                $definition->reportKey(),
            );

            try {
                $response = $this->actingAs($user)->get($url);
                if ($response->status() !== 200) {
                    $failures[] = $definition->code.' -> HTTP '.$response->status();
                    continue;
                }
                // ยืนยันว่าได้รายงานที่ขอจริง ไม่ใช่ถูกเด้งไปตัวแรกของหมวดเงียบ ๆ
                if ($response->viewData('selectedReport') !== $definition->reportKey()) {
                    $failures[] = $definition->code.' -> เด้งไปเป็น '.$response->viewData('selectedReport');
                }
            } catch (\Throwable $e) {
                $failures[] = $definition->code.' -> '.get_class($e).': '.$e->getMessage();
            }
        }

        $this->assertSame([], $failures, "รายงานที่รันไม่ผ่าน:\n".implode("\n", $failures));
    }

    private function omniscientUser(): User
    {
        $user = User::factory()->create([
            'username' => 'report_smoke',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'REPORT_SMOKE', 'name' => 'Report smoke']);
        foreach ([
            'reports.view', 'reports.export', 'reports.all_branches',
            'sales.manage', 'finance.manage', 'stock.manage', 'purchasing.manage',
            'settings.manage', 'users.manage',
        ] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
