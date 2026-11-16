<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * เอกสารสาย B18 ที่คีย์ใน BPlus แล้วนำเข้ามาดูใน ERP:
 * ตรวจไฟล์ก่อน → บันทึก → นำเข้าซ้ำแล้วอัปเดตใบเดิม ไม่สร้างซ้ำ
 * ยอดขายรายวันไม่รวมใบยกเลิก และไม่สร้างเอกสาร/สต๊อก/บัญชีของ ERP
 */
class BplusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_then_import_shows_route_documents_and_daily_sales(): void
    {
        Storage::fake('local');
        $this->signIn();

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($this->export())]);
        $preview->assertOk()->assertSee('B18')->assertSee('ยืนยันบันทึก');
        $this->assertSame(0, DB::table('bplus_sync_documents')->count(), 'ตรวจไฟล์ต้องยังไม่บันทึก');

        $token = $preview->viewData('token');
        $this->post(route('bplus.sync.import.store'), ['token' => $token])
            ->assertRedirect(route('bplus.sync.index'));

        $this->assertSame(5, DB::table('bplus_sync_documents')->count());
        $this->assertSame(0, DB::table('documents')->count(), 'ต้องไม่สร้างเอกสาร ERP');
        $this->assertSame(0, DB::table('stock_movements')->count(), 'ต้องไม่ตัดสต๊อก');

        $page = $this->get(route('bplus.sync.index', ['from' => '2026-11-15', 'to' => '2026-11-15']));
        $page->assertOk()->assertSee('B18-6911/00382')->assertSee('DS256911/06364')
            ->assertSeeInOrder(['ยอดขายรวม', '1,886.00', 'ยอดขายรายวัน']);
        $day = $page->viewData('daily')->first();
        $this->assertSame(1, (int) $day->bookings, 'ใบจองยกเลิกไม่นับ');
        $this->assertSame('1886.00', number_format((float) $day->booking_total, 2, '.', ''));
        $this->assertSame(2, (int) $day->sales);
        $this->assertSame('1886.00', number_format((float) $day->sales_total, 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $day->vat, 2, '.', ''));
        $this->assertSame('1886.00', number_format((float) $day->transfer, 2, '.', ''));

        $sale = DB::table('bplus_sync_documents')->where('ref', 'DS256911/06364')->first();
        $this->get(route('bplus.sync.show', $sale->id))->assertOk()
            ->assertSee('B18-6911/00382')->assertSee('RR26911/000800')->assertSee('ถุง*10');
    }

    public function test_reimport_updates_edited_documents_without_duplicates(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->importFile($this->export());

        $edited = $this->export();
        $edited['documents'][0]['total_amount'] = 2000;
        $edited['documents'][0]['bplus_updated_at'] = '2026-11-16 09:00:00';
        $edited['documents'][0]['lines'] = [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'qty' => 1, 'unit_price' => 2000, 'amount' => 2000]];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($edited)]);
        $this->assertSame(1, $preview->viewData('summary')['booking']['updated']);
        $this->assertSame(0, $preview->viewData('summary')['credit_sale']['new']);
        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')]);

        $this->assertSame(5, DB::table('bplus_sync_documents')->count());
        $booking = DB::table('bplus_sync_documents')->where('ref', 'B18-6911/00382')->first();
        $this->assertSame('2000.00', number_format((float) $booking->total_amount, 2, '.', ''));
        $this->assertSame(1, DB::table('bplus_sync_lines')->where('bplus_sync_document_id', $booking->id)->count());
    }

    public function test_document_list_shows_page_numbers(): void
    {
        Storage::fake('local');
        $this->signIn();
        $export = ['route' => 'B18', 'documents' => []];
        for ($i = 1; $i <= 120; $i++) {
            $export['documents'][] = ['bplus_key' => $i, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => sprintf('B18-6911/%05d', $i),
                'doc_date' => '2026-11-15', 'total_amount' => 10];
        }
        $this->importFile($export);

        $this->get(route('bplus.sync.index', ['from' => '2026-11-15', 'to' => '2026-11-15']))
            ->assertOk()->assertSee('page=3', false)->assertSee('class="page-link"', false);
    }

    public function test_rejects_a_file_that_is_not_a_bplus_export(): void
    {
        Storage::fake('local');
        $this->signIn();

        $this->from(route('bplus.sync.index'))
            ->post(route('bplus.sync.import.preview'), ['file' => $this->upload(['hello' => 'world'])])
            ->assertRedirect(route('bplus.sync.index'))->assertSessionHasErrors('file');
    }

    public function test_viewing_needs_reports_and_importing_needs_finance(): void
    {
        $this->assertSame('reports.view', RoutePermissions::resolve('bplus.sync.index'));
        $this->assertSame('reports.view', RoutePermissions::resolve('bplus.sync.show'));
        $this->assertSame('finance.manage', RoutePermissions::resolve('bplus.sync.import.preview'));
        $this->assertSame('finance.manage', RoutePermissions::resolve('bplus.sync.import.store'));
    }

    private function importFile(array $export): void
    {
        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')]);
    }

    private function upload(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('b18.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** ข้อมูลจริงจาก BPlus 15 พ.ย. 2569 (ชื่อลูกค้าเปลี่ยนเป็นตัวอย่าง) */
    private function export(): array
    {
        return ['route' => 'B18', 'documents' => [
            ['bplus_key' => 2713489, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00382', 'doc_date' => '2026-11-15',
                'is_cancelled' => false, 'customer_code' => '37120-0005', 'customer_name' => 'ร้านตัวอย่าง', 'created_by' => 'BANKKY',
                'total_amount' => 1886.00, 'bplus_updated_at' => '2026-11-15 10:00:00',
                'lines' => [
                    ['seq' => 1, 'sku_code' => '104032', 'barcode' => '0000001040319', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660],
                    ['seq' => 2, 'sku_code' => '501035', 'barcode' => '0000005010356', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 226, 'amount' => 226],
                ]],
            ['bplus_key' => 2713494, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00383', 'doc_date' => '2026-11-15',
                'is_cancelled' => true, 'total_amount' => 500, 'lines' => []],
            ['bplus_key' => 2714599, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256911/06364', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'vatable_amount' => 322.43, 'exempt_amount' => 1492.00,
                'vat_amount' => 22.57, 'total_amount' => 1837.00, 'due_date' => '2026-12-15',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660, 'has_vat' => false]]],
            ['bplus_key' => 2714605, 'kind' => 'credit_sale', 'book_code' => 'DSN', 'ref' => 'DSN256911/4861', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'exempt_amount' => 49, 'total_amount' => 49, 'due_date' => '2026-12-15'],
            ['bplus_key' => 2715000, 'kind' => 'receipt', 'book_code' => 'RR2', 'ref' => 'RR26911/000800', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1886, 'transfer_amount' => 1886,
                'allocations' => [
                    ['sale_ref' => 'DS256911/06364', 'paid_amount' => 1837, 'transfer_amount' => 1837],
                    ['sale_ref' => 'DSN256911/4861', 'paid_amount' => 49, 'transfer_amount' => 49],
                ]],
        ]];
    }

    private function signIn(): void
    {
        $user = User::factory()->create(['username' => 'bplus_sync']);
        $role = Role::create(['code' => 'BPLUS_SYNC', 'name' => 'BPlus sync']);
        $role->permissions()->sync(collect(['finance.manage', 'reports.view'])
            ->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
        $user->roles()->attach($role->id);

        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
