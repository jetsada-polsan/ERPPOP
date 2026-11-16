<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Bplus\BplusDocumentMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * เอกสารสาย B18 จาก BPlus กลายเป็นเอกสาร ERP จริง เห็นในเมนูและรายงานปกติ
 * โดยไม่ตัดสต๊อก ไม่จองสต๊อก ไม่ลง GL และแก้ใน ERP ไม่ได้
 */
class BplusDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['BOOKING' => 'ใบจอง', 'CREDIT_SALE' => 'ใบขายเชื่อ', 'RECEIPT' => 'ใบเสร็จรับเงิน'] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name]);
        }
        $this->branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $location->id]);

        $saleType = DocumentType::where('code', 'CREDIT_SALE')->value('id');
        $bookingType = DocumentType::where('code', 'BOOKING')->value('id');
        foreach ([[$saleType, 'DS', false], [$saleType, 'DSN', true], [$bookingType, 'B18', false]] as [$type, $code, $noVat]) {
            DB::table('document_books')->updateOrInsert(['document_type_id' => $type, 'code' => $code],
                ['name' => $code, 'prefix' => $code, 'is_active' => true, 'is_default' => false, 'no_vat' => $noVat]);
        }
        DB::table('salesmen')->updateOrInsert(['code' => '09'], ['name' => 'แบงค์', 'is_active' => true]);
        $salesmanId = DB::table('salesmen')->where('code', '09')->value('id');
        DB::table('sales_areas')->where('code', 'B18')->delete();
        DB::table('sales_areas')->insert(['code' => 'B18', 'name' => 'ม่วง-เลิง-อำนาจ-มุก', 'branch_id' => $this->branch->id,
            'default_salesman_id' => $salesmanId, 'is_active' => true]);

        Customer::create(['code' => '37120-0005', 'name_th' => 'ร้านตัวอย่าง', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $bag = ProductUnit::firstOrCreate(['code' => 'BAG'], ['name' => 'ถุง', 'qty_per_base_unit' => 1]);
        $bag10 = ProductUnit::firstOrCreate(['code' => 'BAG10'], ['name' => 'ถุง*10', 'qty_per_base_unit' => 10]);
        foreach (['104032', '501035'] as $sku) {
            $product = Product::create(['sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $bag->id, 'is_active' => true]);
            ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'B1-'.$sku, 'unit_id' => $bag->id, 'unit_factor' => 1, 'is_active' => true]);
            ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'B10-'.$sku, 'unit_id' => $bag10->id, 'unit_factor' => 10, 'is_active' => true]);
        }

        $user = User::factory()->create(['username' => 'bplus_docs']);
        $role = Role::create(['code' => 'BPLUS_DOCS', 'name' => 'BPlus docs']);
        $role->permissions()->sync(collect(['finance.manage', 'reports.view'])
            ->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
        $user->roles()->attach($role->id);
        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }

    public function test_route_documents_become_erp_bookings_sales_and_receipts_without_stock_or_gl(): void
    {
        $this->importFile($this->export());

        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $receipt = Document::where('doc_number', 'RR26911/000800')->firstOrFail();

        $this->assertSame('BOOKING', $booking->documentType->code);
        $this->assertSame('CREDIT_SALE', $ds->documentType->code);
        $this->assertSame('DS', $ds->documentBook->code);
        $this->assertSame('DSN', $dsn->documentBook->code);
        $this->assertSame($booking->id, $ds->source_document_id);
        $this->assertSame('22.57', number_format((float) $ds->vat_amount, 2, '.', ''));
        $this->assertSame('1814.43', number_format((float) $ds->subtotal_amount, 2, '.', ''));

        $sale = SaleBooking::where('document_id', $booking->id)->firstOrFail();
        $this->assertSame('converted_to_sale', $sale->status);
        $this->assertSame($ds->id, $sale->confirmed_document_id);
        $this->assertNull(Document::where('doc_number', 'B18-6911/00383')->first(), 'ใบจองที่ยังไม่รู้ลูกค้ายังไม่สร้าง');
        $this->assertStringContainsString('ยังไม่รู้ลูกค้า', (string) DB::table('bplus_sync_documents')->where('ref', 'B18-6911/00383')->value('problem'));

        // หน่วยใหญ่: 2 ถุง*10 = 20 ถุง ราคาต่อถุง 83 รวม 1,660
        $line = DB::table('stock_document_items as i')->join('stock_documents as s', 's.id', '=', 'i.stock_document_id')
            ->where('s.document_id', $ds->id)->first(['i.qty', 'i.unit_qty', 'i.unit_factor', 'i.unit_price']);
        $this->assertSame(['20', '2', '10', '83'], array_map(fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'),
            [$line->qty, $line->unit_qty, $line->unit_factor, $line->unit_price]));

        // ลูกหนี้: DS ชำระ 1,000 จาก 1,837 คงค้าง 837 / DSN ชำระครบ
        $this->assertSame(['partial', '837.00'], $this->openItem($ds));
        $this->assertSame(['paid', '0.00'], $this->openItem($dsn));
        $this->assertSame(1049.0, (float) DB::table('payment_lines as l')->join('payment_documents as p', 'p.id', '=', 'l.payment_document_id')
            ->where('p.document_id', $receipt->id)->where('l.method', 'transfer')->sum('l.amount'));

        $this->assertSame(0, DB::table('stock_movements')->count(), 'ต้องไม่ตัดสต๊อก');
        $this->assertSame(0, (int) DB::table('stock_balances')->sum('reserved_qty'), 'ต้องไม่จองสต๊อก');
        $this->assertSame(0, DB::table('gl_journals')->count(), 'ต้องไม่ลง GL');

        Artisan::call('erp:health');
        $this->assertStringNotContainsString('ไม่มีรายการ GL', Artisan::output(), 'ใบขายจาก BPlus ไม่ทำให้ตรวจ GL ไม่ผ่าน');
    }

    public function test_daily_sales_and_vat_reports_use_the_bplus_bill_amounts(): void
    {
        $this->importFile($this->export());

        $daily = DB::table('sales_postings')->where('sale_date', '2026-11-15')->get();
        $this->assertSame('1886.00', number_format((float) $daily->sum('net_sales'), 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $daily->sum('vat_amount'), 2, '.', ''));
    }

    public function test_documents_show_in_the_normal_erp_lists(): void
    {
        $this->importFile($this->export());
        $this->actingAs(User::where('username', 'bplus_docs')->first()->forceFill(['branch_id' => $this->branch->id]));

        $this->get(route('bookings.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('B18-6911/00382');
        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $sale = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('DS256911/06364')->assertSee('มาจาก BPlus');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('DS256911/06364')->assertSee('1,837.00');
        $this->get(route('ar-receipts.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('RR26911/000800')->assertSee('837.00');
    }

    public function test_resync_updates_in_place_and_cancels_follow_bplus(): void
    {
        $this->importFile($this->export());
        $before = DB::table('documents')->count();

        $edited = $this->export();
        $edited['documents'][2]['total_amount'] = 1900;
        $edited['documents'][2]['exempt_amount'] = 1555;
        $edited['documents'][3]['is_cancelled'] = true;
        $this->importFile($edited);

        $this->assertSame($before, DB::table('documents')->count(), 'ซิงค์ซ้ำต้องไม่สร้างเอกสารซ้ำ');
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame('1900.00', number_format((float) $ds->total_amount, 2, '.', ''));
        $this->assertSame(['partial', '900.00'], $this->openItem($ds));
        $this->assertSame('cancelled', Document::where('doc_number', 'DSN256911/4861')->value('status'));
    }

    public function test_bplus_documents_cannot_be_changed_in_the_erp(): void
    {
        $this->importFile($this->export());
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $ds->update(['status' => 'cancelled']);
    }

    public function test_preview_lists_customers_and_products_missing_from_the_erp(): void
    {
        $export = $this->export();
        $export['documents'][2]['customer_code'] = '99999-0001';
        $export['documents'][2]['lines'][] = ['seq' => 2, 'sku_code' => '999999', 'unit_name' => 'ถุง', 'qty' => 1, 'amount' => 0];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()->assertSee('99999-0001')->assertSee('999999')->assertSee('สำนักงานใหญ่');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')]);
        $this->assertNull(Document::where('doc_number', 'DS256911/06364')->first(), 'ไม่มีลูกค้าใน ERP ต้องไม่สร้างใบขาย');
        $this->assertStringContainsString('99999-0001', (string) DB::table('bplus_sync_documents')->where('ref', 'DS256911/06364')->value('problem'));
    }

    private function openItem(Document $document): array
    {
        $item = DB::table('customer_open_items')->where('document_id', $document->id)->first();

        return [$item->status, number_format((float) $item->balance_amount, 2, '.', '')];
    }

    private function importFile(array $export): void
    {
        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();
    }

    private function upload(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('b18.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function export(): array
    {
        return ['route' => 'B18', 'documents' => [
            ['bplus_key' => 2713489, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00382', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1886, 'created_by' => 'BANKKY',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660],
                    ['seq' => 2, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 226, 'amount' => 226]]],
            ['bplus_key' => 2713494, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00383', 'doc_date' => '2026-11-15',
                'is_cancelled' => true, 'total_amount' => 500],
            ['bplus_key' => 2714599, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256911/06364', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'vatable_amount' => 322.43, 'exempt_amount' => 1492,
                'vat_amount' => 22.57, 'total_amount' => 1837, 'due_date' => '2026-12-15',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660]]],
            ['bplus_key' => 2714605, 'kind' => 'credit_sale', 'book_code' => 'DSN', 'ref' => 'DSN256911/4861', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'exempt_amount' => 49, 'total_amount' => 49, 'due_date' => '2026-12-15',
                'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 49, 'amount' => 49]]],
            ['bplus_key' => 2715000, 'kind' => 'receipt', 'book_code' => 'RR2', 'ref' => 'RR26911/000800', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1049, 'transfer_amount' => 1049,
                'allocations' => [['sale_ref' => 'DS256911/06364', 'paid_amount' => 1000, 'transfer_amount' => 1000],
                    ['sale_ref' => 'DSN256911/4861', 'paid_amount' => 49, 'transfer_amount' => 49]]],
        ]];
    }
}
