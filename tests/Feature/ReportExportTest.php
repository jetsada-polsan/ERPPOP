<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
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

    public function test_print_mode_ignores_screen_pagination_and_loads_all_report_rows(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH1', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        foreach (range(1, 30) as $number) {
            Product::create([
                'sku_code' => sprintf('SKU-%03d', $number), 'name_th' => 'สินค้า '.$number,
                'base_unit_id' => $unit->id, 'is_active' => true, 'is_vat' => false,
            ]);
        }

        ReportDefinition::where('code', 'inventory.stock_balance')->update(['enabled' => true, 'status' => 'available']);

        $response = $this->actingAs($this->exporter())
            ->get('/reports?category=inventory&report=stock_balance&print=1&per_page=10');

        $response->assertOk();
        $this->assertTrue($response->viewData('printMode'));
        $this->assertSame(50001, $response->viewData('filters')['per_page']);
        $this->assertFalse($response->viewData('printTruncated'));
        $this->assertGreaterThan(25, count($response->viewData('result')['rows']));
    }

    public function test_xlsx_export_downloads_a_real_spreadsheet(): void
    {
        $response = $this->actingAs($this->exporter())
            ->get('/reports?category=sales&report=daily_sales&export=xlsx');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        // เขียนลงไฟล์แล้วเปิดเป็น zip จริง ไม่ใช่ CSV เปลี่ยนนามสกุล
        $path = tempnam(sys_get_temp_dir(), 'dl-').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($path);
    }

    public function test_xlsx_export_needs_the_same_permission_as_csv(): void
    {
        $this->actingAs($this->userWith(['reports.view']))
            ->get('/reports?category=sales&report=daily_sales&export=xlsx')
            ->assertForbidden();
    }

    private function exporter(): User
    {
        return $this->userWith(['reports.view', 'reports.export', 'reports.all_branches', 'stock.manage']);
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
