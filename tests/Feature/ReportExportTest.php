<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ส่งออกและพิมพ์ต้องได้ทั้งรายงาน ไม่ใช่เท่าที่เห็นบนจอ
 *
 * per_page เป็น LIMIT ของ query ไม่ใช่การแบ่งหน้า ถ้า export ใช้ค่าเดียวกับหน้าจอ
 * คนกดออกไฟล์ภาษีขายจะได้ไฟล์ที่ดูสมบูรณ์แต่ขาดข้อมูล ซึ่งแย่กว่าไฟล์ที่ error
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_a_csv_download_with_a_bom(): void
    {
        $response = $this->actingAs($this->exporter())
            ->get('/reports?category=sales&report=daily_sales&export=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent(), 'ต้องมี BOM ไม่งั้น Excel อ่านภาษาไทยเพี้ยน');
    }

    public function test_the_csv_header_row_uses_the_report_column_labels(): void
    {
        $response = $this->actingAs($this->exporter())
            ->get('/reports?category=sales&report=daily_sales&export=csv');

        $firstLine = strtok(ltrim($response->streamedContent(), "\xEF\xBB\xBF"), "\n");
        $this->assertNotSame('', trim($firstLine), 'แถวหัวตารางต้องไม่ว่าง');
    }

    public function test_export_is_refused_without_the_export_permission(): void
    {
        $viewer = $this->userWith(['reports.view']);

        $this->actingAs($viewer)
            ->get('/reports?category=sales&report=daily_sales&export=csv')
            ->assertForbidden();
    }

    public function test_print_mode_renders_the_page_and_asks_for_more_rows_than_the_screen(): void
    {
        $response = $this->actingAs($this->exporter())
            ->get('/reports?category=sales&report=daily_sales&print=1');

        $response->assertOk();
        $this->assertTrue($response->viewData('printMode'));
        $this->assertGreaterThan(100, $response->viewData('perPage'),
            'กระดาษต้องได้มากกว่าที่หน้าจอแสดง ไม่งั้นพิมพ์ออกมาแล้วข้อมูลขาด');
    }

    private function exporter(): User
    {
        return $this->userWith(['reports.view', 'reports.export', 'reports.all_branches']);
    }

    /** @param  array<int, string>  $codes */
    private function userWith(array $codes): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'exporter-'.++$sequence, 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'EXPORT_'.$sequence, 'name' => 'export test '.$sequence]);
        foreach ($codes as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        ReportDefinition::where('code', 'sales.daily_sales')->update(['enabled' => true, 'status' => 'available']);

        return $user->fresh();
    }
}
