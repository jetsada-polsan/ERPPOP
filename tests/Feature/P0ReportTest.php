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

    /**
     * รายงานที่ตัวเลขยังไม่ถูกพิสูจน์ ต้องมาแบบปิดไว้
     *
     * "รันแล้วไม่ error" ไม่ใช่หลักฐานว่าตัวเลขถูก รายงานการเงินที่คำนวณผิด
     * จะดูปกติทุกอย่างจนกว่าจะมีคนเอาไปตัดสินใจแล้วผิด ตัวที่มีเทสต์เทียบยอด
     * อยู่ใน P0ReportFiguresTest แล้วเท่านั้นที่ควรเปิดได้
     */
    public function test_p0_reports_ship_switched_off_until_their_figures_are_proven(): void
    {
        $unproven = [
            'booking.outstanding', 'booking.due', 'booking.by_branch_seller',
            'ap.outstanding_detail', 'cash.bank_summary', 'payment.received_and_unidentified',
        ];

        foreach ($unproven as $code) {
            $definition = ReportDefinition::where('code', $code)->sole();
            $this->assertSame('available', $definition->status, "{$code} ควรมีหน้าจอแล้ว");
            $this->assertFalse($definition->enabled, "{$code} ยังไม่มีเทสต์เทียบยอด ต้องมาแบบปิดไว้");
        }
    }

    public function test_the_proven_p0_reports_each_have_a_figures_test(): void
    {
        $proven = [
            'sales.daily_by_channel' => 'sales_by_channel_matches_the_sales_ledger',
            'ar.ar_aging' => 'receivable_ageing_puts_each_invoice_in_the_right_bucket',
            'ap.aging' => 'payable_ageing_reports_each_supplier_separately',
            'cash.daily_cash_book' => 'the_cash_book_shows_every_movement_with_its_running_balance',
            'cash.bank_reconciliation' => 'bank_lines_show_which_ones_are_still_unreconciled',
        ];

        // ผูกชื่อเทสต์ไว้กับรหัสรายงาน ลบเทสต์ทิ้งแล้วข้อนี้จะพัง
        // ไม่ใช่ปล่อยให้รายงานเปิดค้างอยู่โดยไม่มีอะไรค้ำ
        $figures = (string) file_get_contents(base_path('tests/Feature/P0ReportFiguresTest.php'));
        foreach ($proven as $code => $testName) {
            $this->assertSame('available', ReportDefinition::where('code', $code)->sole()->status);
            $this->assertStringContainsString($testName, $figures, "{$code} ต้องมีเทสต์เทียบยอดชื่อ {$testName}");
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
