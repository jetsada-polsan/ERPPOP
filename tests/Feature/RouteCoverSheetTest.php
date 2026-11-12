<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCoverSheetTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(?string $permission = 'sales.manage', ?int $branchId = null): User
    {
        $user = User::factory()->create([
            'branch_id' => $branchId,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        if ($permission) {
            $role = Role::create(['code' => 'ROLE_'.uniqid(), 'name' => 'Role']);
            $perm = Permission::firstOrCreate(['code' => $permission], ['name' => $permission]);
            $role->permissions()->attach($perm->id);
            $user->roles()->attach($role->id);
        }

        return $user->fresh();
    }

    private function createBooking(
        Branch $branch,
        SalesArea $area,
        Customer $customer,
        string $docNo,
        float $totalAmount,
        string $date = '2026-11-11',
        string $status = SaleBooking::STATUS_PENDING,
        string $docStatus = 'active'
    ): SaleBooking {
        $docType = DocumentType::firstOrCreate(['code' => 'BOOKING'], ['name_th' => 'ใบจองสินค้า']);
        $doc = Document::create([
            'document_type_id' => $docType->id,
            'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
            'customer_id' => $customer->id,
            'doc_number' => $docNo,
            'doc_date' => $date,
            'status' => $docStatus,
            'total_items' => 1,
            'total_amount' => $totalAmount,
        ]);

        return SaleBooking::create([
            'document_id' => $doc->id,
            'sales_area_id' => $area->id,
            'status' => $status,
            'fulfillment_type' => SaleBooking::FULFILLMENT_DELIVERY,
        ]);
    }

    /** 1. ใบจองที่แสดงตรงกับหน้าใบรวมจองชุดเดียวกัน */
    public function test_cover_sheet_displays_same_bookings_as_summary_page(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า กาญจนา', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area, $customer, 'BK-202611-0001', 4487.55, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-202611-0002', 1182.54, '2026-11-11');
        // ใบจองคนละวัน
        $this->createBooking($branch, $area, $customer, 'BK-OTHER-DAY', 999.00, '2026-11-12');

        $user = $this->userWithPermission('sales.manage');

        // หน้าใบรวมจอง
        $summaryResp = $this->actingAs($user)->get(route('bookings.summary', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
        ]));
        $summaryResp->assertOk();
        $summaryBookings = $summaryResp->viewData('bills')->pluck('document.doc_number')->all();

        // หน้าใบปะหน้า
        $coverResp = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
        ]));
        $coverResp->assertOk();
        $coverBookings = $coverResp->viewData('routeSheets')->first()->bookings->pluck('document.doc_number')->all();

        $this->assertSame(['BK-202611-0001', 'BK-202611-0002'], $summaryBookings);
        $this->assertSame($summaryBookings, $coverBookings, 'ใบจองที่แสดงในหน้าใบปะหน้าต้องตรงกับหน้าใบรวมจองทุกประการ');
    }

    /** 2. ไม่รวมใบยกเลิก */
    public function test_cancelled_or_voided_bookings_are_excluded(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า กาญจนา', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area, $customer, 'BK-VALID-01', 1500.00, '2026-11-11', SaleBooking::STATUS_PENDING);
        $this->createBooking($branch, $area, $customer, 'BK-CANCELLED-02', 2000.00, '2026-11-11', SaleBooking::STATUS_CANCELLED);
        $this->createBooking($branch, $area, $customer, 'BK-DOC-VOIDED-03', 3000.00, '2026-11-11', SaleBooking::STATUS_PENDING, 'cancelled');

        $user = $this->userWithPermission('sales.manage');

        $response = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
        ]));

        $response->assertOk();
        $response->assertSee('BK-VALID-01');
        $response->assertDontSee('BK-CANCELLED-02');
        $response->assertDontSee('BK-DOC-VOIDED-03');
    }

    /** 3. ผลรวมถูกถึงสตางค์ (ใช้ยอดอย่าง 4,487.55 + 1,182.54 = 5,670.09) */
    public function test_total_amount_is_accurate_down_to_satang(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า กาญจนา', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area, $customer, 'BK-001', 4487.55, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-002', 1182.54, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        $response = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
        ]));

        $response->assertOk();
        $response->assertSee('4,487.55');
        $response->assertSee('1,182.54');
        $response->assertSee('5,670.09');
    }

    /** 4. กรองสาย/ใบเดียวได้ */
    public function test_filter_by_sales_area_and_specific_booking(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area1 = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $area2 = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า ทั่วไป', 'branch_id' => $branch->id]);

        $b1 = $this->createBooking($branch, $area1, $customer, 'BK-AREA1-01', 1000.00, '2026-11-11');
        $b2 = $this->createBooking($branch, $area2, $customer, 'BK-AREA2-01', 2000.00, '2026-11-11');
        $b3 = $this->createBooking($branch, $area2, $customer, 'BK-AREA2-02', 3000.00, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        // กรองเฉพาะสาย 02
        $respArea = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area2->id,
        ]));
        $respArea->assertOk();
        $respArea->assertSee('BK-AREA2-01');
        $respArea->assertSee('BK-AREA2-02');
        $respArea->assertDontSee('BK-AREA1-01');

        // กรองเฉพาะใบเดียว
        $respSingle = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area2->id,
            'booking_id' => $b2->id,
        ]));
        $respSingle->assertOk();
        $singleBookings = $respSingle->viewData('routeSheets')->first()->bookings->pluck('document.doc_number')->all();
        $this->assertSame(['BK-AREA2-01'], $singleBookings, 'ต้องแสดงเฉพาะใบจองที่เลือก');
    }

    /** 5. "ทุกสาย" ได้ครบทุกสาย */
    public function test_all_routes_option_includes_all_sales_areas(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area1 = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $area2 = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า ทั่วไป', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area1, $customer, 'BK-ROUTE-01', 1500.00, '2026-11-11');
        $this->createBooking($branch, $area2, $customer, 'BK-ROUTE-02', 2500.00, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        $response = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => 'all',
        ]));

        $response->assertOk();
        $response->assertSee('BK-ROUTE-01');
        $response->assertSee('BK-ROUTE-02');
        $response->assertSee('สุพรรณบุรี');
        $response->assertSee('กาญจนบุรี');

        $routeSheets = $response->viewData('routeSheets');
        $this->assertGreaterThanOrEqual(2, $routeSheets->count(), 'ต้องแยกเป็นชุดเอกสารตามแต่ละสาย');
    }

    /** 6. CSV มี BOM และผลรวม */
    public function test_csv_export_has_utf8_bom_correct_headers_and_summary_row(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'บริษัท ทดสอบ จำกัด', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area, $customer, 'BK-EXP-01', 4487.55, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-EXP-02', 1182.54, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        $response = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
            'export' => 'csv',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        // ตรวจสอบ UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV ต้องขึ้นต้นด้วย UTF-8 BOM เพื่อให้ Excel เปิดภาษาไทยได้');
        // ตรวจสอบหัวตาราง
        $this->assertStringContainsString('ลำดับ,ชื่อลูกค้า,เลขที่บิล,จำนวนเงิน', $content);
        // ตรวจสอบข้อมูล
        $this->assertStringContainsString('บริษัท ทดสอบ จำกัด', $content);
        $this->assertStringContainsString('BK-EXP-01', $content);
        $this->assertStringContainsString('4,487.55', $content);
        $this->assertStringContainsString('1,182.54', $content);
        // ตรวจสอบแถวสรุปผลรวม
        $this->assertStringContainsString('รวม', $content);
        $this->assertStringContainsString('5,670.09', $content);
    }

    /** 7. ผู้ใช้สาขาอื่นไม่เห็นใบจองของสาขาที่ไม่มีสิทธิ์ */
    public function test_other_branch_user_cannot_see_bookings_of_inaccessible_branch(): void
    {
        $branchA = Branch::create(['code' => 'BR-A', 'name_th' => 'สาขา A', 'is_active' => true]);
        $branchB = Branch::create(['code' => 'BR-B', 'name_th' => 'สาขา B', 'is_active' => true]);

        $areaA = SalesArea::create(['code' => 'A1', 'name' => 'สาย A', 'branch_id' => $branchA->id]);
        $areaB = SalesArea::create(['code' => 'B1', 'name' => 'สาย B', 'branch_id' => $branchB->id]);

        $custA = Customer::create(['code' => 'CA', 'name_th' => 'ลูกค้า A', 'branch_id' => $branchA->id]);
        $custB = Customer::create(['code' => 'CB', 'name_th' => 'ลูกค้า B', 'branch_id' => $branchB->id]);

        $this->createBooking($branchA, $areaA, $custA, 'BK-BRANCH-A', 1000.00, '2026-11-11');
        $this->createBooking($branchB, $areaB, $custB, 'BK-BRANCH-B', 2000.00, '2026-11-11');

        // ผู้ใช้ประจำสาขา A
        $userA = $this->userWithPermission('sales.manage', $branchA->id);

        // เข้าหน้ารวม จะต้องเห็นเฉพาะของสาขา A ไม่เห็นของสาขา B
        $respA = $this->actingAs($userA)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => 'all',
        ]));
        $respA->assertOk();
        $respA->assertSee('BK-BRANCH-A');
        $respA->assertDontSee('BK-BRANCH-B');

        // พยายามระบุ branch_id เป็นสาขา B จะต้องถูกปฏิเสธ 403 Forbidden
        $this->actingAs($userA)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'branch_id' => $branchB->id,
        ]))->assertForbidden();
    }

    /** 8. ใช้สิทธิ์เดิมของหน้าใบรวมจอง (ห้ามสร้างสิทธิ์ใหม่) */
    public function test_cover_sheet_uses_existing_sales_manage_permission(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);

        // ผู้ใช้ที่ไม่มีสิทธิ์ sales.manage
        $userNoPerm = $this->userWithPermission(null);
        $this->actingAs($userNoPerm)
            ->get(route('bookings.cover-sheet', ['date' => '2026-11-11']))
            ->assertForbidden();

        // ผู้ใช้ที่มีสิทธิ์ sales.manage
        $userWithSales = $this->userWithPermission('sales.manage');
        $this->actingAs($userWithSales)
            ->get(route('bookings.cover-sheet', ['date' => '2026-11-11']))
            ->assertOk();
    }

    /** 9. จัดกลุ่มตามสายขายที่คีย์ในใบจอง (sale_bookings.sales_area_id) เท่านั้น ห้ามจัดกลุ่มตามชื่อพนักงานขาย */
    public function test_bookings_grouped_by_sales_area_not_by_salesman_even_if_same_salesman(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area1 = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $area2 = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer1 = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า สุพรรณ', 'branch_id' => $branch->id]);
        $customer2 = Customer::create(['code' => 'C02', 'name_th' => 'ลูกค้า กาญจน์', 'branch_id' => $branch->id]);

        $sameSalesman = \App\Models\Salesman::create(['code' => 'SM01', 'name' => 'นายสมคิด ขายดี']);

        // ใบจอง 2 ใบ ใช้พนักงานขายคนเดียวกัน แต่คนละสาย
        $b1 = $this->createBooking($branch, $area1, $customer1, 'BK-SAME-SM-01', 1200.00, '2026-11-11');
        $b1->update(['salesman_id' => $sameSalesman->id]);

        $b2 = $this->createBooking($branch, $area2, $customer2, 'BK-SAME-SM-02', 3400.00, '2026-11-11');
        $b2->update(['salesman_id' => $sameSalesman->id]);

        $user = $this->userWithPermission('sales.manage');

        $response = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => 'all',
        ]));

        $response->assertOk();
        $routeSheets = $response->viewData('routeSheets');

        // ต้องแยกเป็น 2 ชุดเอกสารตามสาย ไม่ยุบรวมกันตามพนักงานขาย
        $this->assertCount(2, $routeSheets);

        $sheet1 = $routeSheets->firstWhere('area.id', $area1->id);
        $this->assertNotNull($sheet1);
        $this->assertSame(['BK-SAME-SM-01'], $sheet1->bookings->pluck('document.doc_number')->all());
        $this->assertSame('ลูกค้า สุพรรณ', $sheet1->bookings->first()->document->customer->name_th);

        $sheet2 = $routeSheets->firstWhere('area.id', $area2->id);
        $this->assertNotNull($sheet2);
        $this->assertSame(['BK-SAME-SM-02'], $sheet2->bookings->pluck('document.doc_number')->all());
        $this->assertSame('ลูกค้า กาญจน์', $sheet2->bookings->first()->document->customer->name_th);
    }

    /** 10. ปุ่มใบปะหน้าจากหน้าใบรวมจองเมื่อไม่เลือกสาย (ทุกสาย) ต้องส่ง sales_area_id=all และหน้าใบปะหน้าแสดงทุกสาย */
    public function test_summary_page_cover_sheet_button_passes_sales_area_all_when_unfiltered(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area1 = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $area2 = SalesArea::create(['code' => '02', 'name' => 'กาญจนบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า กาญจนา', 'branch_id' => $branch->id]);

        $this->createBooking($branch, $area1, $customer, 'BK-ALL-ROUTE-01', 1000.00, '2026-11-11');
        $this->createBooking($branch, $area2, $customer, 'BK-ALL-ROUTE-02', 2000.00, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        // เข้าหน้าใบรวมจองโดยไม่ระบุ sales_area_id (คือ ทุกสาย)
        $summaryResp = $this->actingAs($user)->get(route('bookings.summary', [
            'date' => '2026-11-11',
        ]));
        $summaryResp->assertOk();

        // ตรวจสอบว่าปุ่ม ใบปะหน้า (ขนส่ง) ส่ง sales_area_id=all
        $summaryResp->assertSee('sales_area_id=all');

        // เมื่อเรียกตามลิงก์ที่ส่ง sales_area_id=all
        $coverResp = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => 'all',
        ]));
        $coverResp->assertOk();
        $this->assertTrue($coverResp->viewData('isAllRoutes'));

        // ตรวจสอบว่ามีข้อมูลครบทั้งสองสาย ไม่หายเงียบๆ
        $routeSheets = $coverResp->viewData('routeSheets');
        $this->assertCount(2, $routeSheets);
    }

    /** 11. เอกสารใบจองที่ถูกยกเลิก (cancelled/voided) ต้องไม่โผล่ทั้งใบรวมจอง ใบปะหน้า และพิมพ์ใบส่งของรวม */
    public function test_cancelled_documents_excluded_from_summary_cover_sheet_and_batch_delivery_notes(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า ทั่วไป', 'branch_id' => $branch->id]);

        // ใบจองปกติ
        $this->createBooking($branch, $area, $customer, 'BK-ACTIVE-01', 1500.00, '2026-11-11', SaleBooking::STATUS_PENDING, 'active');

        // ใบจองที่เอกสารถูกยกเลิก (document.status = cancelled)
        $this->createBooking($branch, $area, $customer, 'BK-CANCELLED-DOC', 2500.00, '2026-11-11', SaleBooking::STATUS_PENDING, 'cancelled');

        // ใบจองที่เอกสารถูก voided
        $this->createBooking($branch, $area, $customer, 'BK-VOIDED-DOC', 3500.00, '2026-11-11', SaleBooking::STATUS_PENDING, 'voided');

        $user = $this->userWithPermission('sales.manage');

        // 1. ตรวจหน้าใบรวมจอง
        $summaryResp = $this->actingAs($user)->get(route('bookings.summary', ['date' => '2026-11-11']));
        $summaryResp->assertOk();
        $summaryResp->assertSee('BK-ACTIVE-01');
        $summaryResp->assertDontSee('BK-CANCELLED-DOC');
        $summaryResp->assertDontSee('BK-VOIDED-DOC');

        // 2. ตรวจหน้าใบปะหน้า
        $coverResp = $this->actingAs($user)->get(route('bookings.cover-sheet', ['date' => '2026-11-11', 'sales_area_id' => 'all']));
        $coverResp->assertOk();
        $coverResp->assertSee('BK-ACTIVE-01');
        $coverResp->assertDontSee('BK-CANCELLED-DOC');
        $coverResp->assertDontSee('BK-VOIDED-DOC');

        // 3. ตรวจพิมพ์ใบส่งของรวม (DeliveryNoteController::batch type=booking)
        $batchResp = $this->actingAs($user)->get(route('documents.delivery-notes.batch', [
            'date' => '2026-11-11',
            'type' => 'booking',
        ]));
        $batchResp->assertOk();
        $batchResp->assertSee('BK-ACTIVE-01');
        $batchResp->assertDontSee('BK-CANCELLED-DOC');
        $batchResp->assertDontSee('BK-VOIDED-DOC');
    }

    /** 12. ยอดรวมเป็นสตางค์จำนวนเต็ม ไม่ใช้ float ป้องกันทศนิยมเพี้ยน */
    public function test_cover_sheet_exact_satang_math_with_precision_prone_floats(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาใหญ่', 'is_active' => true]);
        $area = SalesArea::create(['code' => '01', 'name' => 'สุพรรณบุรี', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'C01', 'name_th' => 'ลูกค้า พิเศษ', 'branch_id' => $branch->id]);

        // ยอดที่มีโอกาสทำให้ float เพี้ยน: 0.10 + 0.20 + 4,487.55 + 1,182.54 = 5,670.39
        $this->createBooking($branch, $area, $customer, 'BK-FLOAT-01', 0.10, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-FLOAT-02', 0.20, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-FLOAT-03', 4487.55, '2026-11-11');
        $this->createBooking($branch, $area, $customer, 'BK-FLOAT-04', 1182.54, '2026-11-11');

        $user = $this->userWithPermission('sales.manage');

        // 1. ตรวจหน้าเว็บ
        $resp = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
        ]));
        $resp->assertOk();
        $sheet = $resp->viewData('routeSheets')->first();
        $this->assertSame(5670.39, $sheet->totalAmount);
        $resp->assertSee('5,670.39');

        // 2. ตรวจส่งออก CSV
        $csvResp = $this->actingAs($user)->get(route('bookings.cover-sheet', [
            'date' => '2026-11-11',
            'sales_area_id' => $area->id,
            'export' => 'csv',
        ]));
        $csvResp->assertOk();
        $csvContent = $csvResp->streamedContent();
        $this->assertStringContainsString('5,670.39', $csvContent);
    }
}
