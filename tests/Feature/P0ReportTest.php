<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * รายงาน P0 ทั้ง 10 ตัวต้องรันได้บนฐานเปล่าและบนฐานที่มีข้อมูล
 *
 * เทสต์นี้ไม่ได้ตรวจว่ายอดถูก — นั่นคืองานของ UAT เทียบยอดจริงบน staging
 * แต่ตรวจว่า query ไม่ระเบิด ซึ่งเป็นสิ่งที่รายงาน 48 ตัวเดิมไม่มีใครตรวจมาก่อน
 */
class P0ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_p0_report_runs(): void
    {
        $user = $this->reportUser();
        $failures = [];

        // เปิดชั่วคราวในฐานทดสอบเท่านั้น — ของจริงยังปิดอยู่จนกว่า UAT จะผ่าน
        // ถ้าไม่เปิด รายงานจะไม่อยู่ในเมนูแล้วถูกเด้งไปตัวแรกของหมวด เทสต์จะไม่ได้ตรวจอะไรเลย
        ReportDefinition::where('status', 'available')->update(['enabled' => true]);

        foreach (ReportDefinition::where('priority', 'P0')->where('status', 'available')->orderBy('code')->get() as $definition) {
            $url = sprintf('/reports?category=%s&report=%s&from=2026-01-01&to=2026-12-31&branch_id=all',
                $definition->category, $definition->reportKey());
            try {
                $response = $this->actingAs($user)->get($url);
                if ($response->status() !== 200) {
                    $failures[] = $definition->code.' -> HTTP '.$response->status();
                } elseif ($response->viewData('selectedReport') !== $definition->reportKey()) {
                    $failures[] = $definition->code.' -> เด้งไปเป็น '.$response->viewData('selectedReport');
                }
            } catch (\Throwable $e) {
                $failures[] = $definition->code.' -> '.get_class($e).': '.$e->getMessage();
            }
        }

        $this->assertSame([], $failures, "รายงาน P0 ที่รันไม่ผ่าน:\n".implode("\n", $failures));
    }

    public function test_the_ten_new_p0_reports_are_available_but_still_switched_off(): void
    {
        $codes = [
            'sales.daily_by_channel', 'booking.outstanding', 'booking.due', 'booking.by_branch_seller',
            'ap.outstanding_detail', 'ap.aging', 'cash.daily_cash_book', 'cash.bank_summary',
            'cash.bank_reconciliation', 'payment.received_and_unidentified',
        ];

        foreach ($codes as $code) {
            $definition = ReportDefinition::where('code', $code)->sole();
            $this->assertSame('available', $definition->status, "{$code} ควรมีหน้าจอแล้ว");
            $this->assertFalse($definition->enabled, "{$code} ต้องยังปิดอยู่จนกว่า UAT จะผ่าน");
        }
    }

    private function reportUser(): User
    {
        $user = User::factory()->create([
            'username' => 'p0_report', 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'P0_REPORT', 'name' => 'P0 report test']);
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
