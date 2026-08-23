<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->assertReportsRun(ReportDefinition::runnable()->orderBy('code')->get());
    }

    /**
     * รายงานที่ยังปิดอยู่ ต้องรันได้ทันทีที่ถูกเปิด
     *
     * ผู้บริหารเปิด-ปิดรายงานได้เอง ตัวที่ปิดอยู่วันนี้อาจถูกเปิดพรุ่งนี้
     * ถ้าทดสอบเฉพาะตัวที่เปิดอยู่ วันที่เปิดคือวันที่ผู้ใช้เจอ error แทนเรา
     *
     * ต้องเปิดก่อนถึงจะเรียกได้ เพราะรายงานที่ปิดจะถูกเด้งไปตัวอื่นตามการออกแบบ
     */
    public function test_every_available_report_runs_once_enabled(): void
    {
        // รายงานหลายตัวเขียนด้วย SQL ของ PostgreSQL โดยตรง (date_trunc, to_char, cast ด้วย ::)
        // ซึ่ง SQLite ไม่รู้จัก คำถามว่า "เปิดแล้วใช้ได้ไหม" จึงตอบได้บนเครื่องจริงเท่านั้น
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('ตรวจได้เฉพาะบน PostgreSQL — ชุด postgres-uat บน CI รันข้อนี้ให้');
        }

        $disabled = ReportDefinition::where('status', 'available')->where('enabled', false)->orderBy('code')->get();
        ReportDefinition::whereIn('id', $disabled->pluck('id'))->update(['enabled' => true]);

        $this->assertReportsRun($disabled->fresh());
    }

    /** @param  \Illuminate\Support\Collection<int, ReportDefinition>  $definitions */
    private function assertReportsRun($definitions): void
    {
        $user = $this->omniscientUser();
        $failures = [];

        foreach ($definitions as $definition) {
            $url = sprintf(
                '/reports?category=%s&report=%s&from=2026-01-01&to=2026-12-31&branch_id=all',
                $definition->category,
                $definition->reportKey(),
            );

            try {
                // แยก savepoint ให้แต่ละรายงาน — บน PostgreSQL คำสั่งเดียวที่ผิดจะทำให้
                // ทั้ง transaction ถูก abort (25P02) รายงานที่เหลือจะพังตามกันหมด
                // แล้วเราจะเห็นแค่ตัวแรกที่พัง ไม่รู้ว่าจริง ๆ มีกี่ตัว
                DB::beginTransaction();
                $response = $this->actingAs($user)->get($url);
                if ($response->status() !== 200) {
                    // บอกสาเหตุไปเลย ไม่ใช่แค่ HTTP 500 ไม่งั้นต้องไปไล่เดาเองว่าพังเพราะอะไร
                    $failures[] = $definition->code.' -> HTTP '.$response->status().($response->exception
                        ? ' — '.$response->exception::class.': '.mb_substr($response->exception->getMessage(), 0, 200)
                        : '');
                    continue;
                }
                // ยืนยันว่าได้รายงานที่ขอจริง ไม่ใช่ถูกเด้งไปตัวแรกของหมวดเงียบ ๆ
                if ($response->viewData('selectedReport') !== $definition->reportKey()) {
                    $failures[] = $definition->code.' -> เด้งไปเป็น '.$response->viewData('selectedReport');
                }
            } catch (\Throwable $e) {
                $failures[] = $definition->code.' -> '.get_class($e).': '.$e->getMessage();
            } finally {
                DB::rollBack();
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
