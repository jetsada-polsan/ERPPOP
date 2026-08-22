<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ทะเบียนรายงานของ ERP ใหม่ — ย้ายรายการรายงานออกจาก array ที่ hardcode ไว้ใน
 * ReportController มาเป็นข้อมูล เพื่อให้ผู้บริหารเปิด/ปิดรายงานเองได้โดยไม่ต้องแก้โค้ด
 *
 * การ "ปิด" คือซ่อนจากเมนูเท่านั้น (`enabled = false`) definition และประวัติยังอยู่ครบ
 *
 * `status` แยกรายงานที่ยกมาจากของเดิม (`available` — ใช้ได้แต่ยังไม่เคยผ่าน UAT เทียบยอด)
 * ออกจากรายงานที่วางแผนไว้แต่ยังไม่มีหน้าจอ (`planned`) ตามกติกาใน
 * CLAUDE_LEGACY_REBUILD_BRIEF.md ที่ห้ามเปิดรายงาน P0 ก่อน mapping + UAT ผ่าน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('category', 40);
            $table->string('category_title', 100);
            $table->string('name', 200);
            $table->string('view_permission', 60)->default('reports.view');
            $table->string('owner_role', 100)->nullable();
            $table->string('frequency', 20)->default('unknown');   // daily | monthly | adhoc | unused | unknown
            $table->string('priority', 4)->nullable();             // P0 | P1 | P2
            $table->string('status', 20)->default('planned');      // planned | mapping | uat | available | retired
            $table->boolean('enabled')->default(false);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->string('legacy_code', 40)->nullable();
            $table->json('metadata')->nullable();                  // แหล่งข้อมูล สูตร filter timezone cutoff ความหมายของยอด
            $table->timestamps();
            $table->index(['category', 'sort_order']);
            $table->index('enabled');
        });

        $this->seedPermissions();
        $this->seedExistingReports();
        $this->seedPlannedReports();
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');

        $ids = DB::table('permissions')->whereIn('code', ['reports.export', 'reports.all_branches'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    /** สิทธิ์ใหม่: ดูรายงานอย่างเดียว / ดาวน์โหลดออกไป / เห็นข้ามสาขา เป็นคนละเรื่องกัน */
    private function seedPermissions(): void
    {
        $permissions = [
            'reports.export' => 'ส่งออกรายงาน (Excel/CSV/PDF)',
            'reports.all_branches' => 'ดูรายงานได้ทุกสาขา',
        ];
        foreach ($permissions as $code => $name) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['code' => $code, 'name' => $name]);
            }
        }

        // ให้ครบทุก role ที่ตั้งใจในรอบเดียว — ถ้าแบ่ง backfill ทีหลังจะเช็คไม่ออกว่าใครยังขาด
        $grants = [
            'GM' => ['reports.export', 'reports.all_branches'],
            'IT_MGR' => ['reports.export', 'reports.all_branches'],
            'ACC_MGR' => ['reports.export', 'reports.all_branches'],
            'ACC' => ['reports.export', 'reports.all_branches'],
            // ผู้จัดการสาขาส่งออกได้ แต่เห็นเฉพาะสาขาตัวเอง
            'BRANCH_MGR' => ['reports.export'],
            'PURCHASING' => ['reports.export'],
        ];
        foreach ($grants as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($codes as $code) {
                $permId = DB::table('permissions')->where('code', $code)->value('id');
                if ($permId && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permId])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
                }
            }
        }
    }

    /** รายงานที่มีหน้าจอใช้งานอยู่แล้ว — ยกมาทั้งชุดและเปิดไว้ เพื่อไม่ให้ของที่ใช้อยู่หาย */
    private function seedExistingReports(): void
    {
        $rows = $this->existingReports();
        $now = now();
        foreach ($rows as [$code, $category, $categoryTitle, $name, $permission, $order]) {
            DB::table('report_definitions')->insert([
                'code' => $code,
                'category' => $category,
                'category_title' => $categoryTitle,
                'name' => $name,
                'view_permission' => $permission,
                'frequency' => 'unknown',
                'status' => 'available',
                'enabled' => true,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * รายงานชุดแรกจาก brief ที่ยังไม่มีหน้าจอ — ลงทะเบียนไว้ให้เห็นว่ายังขาดอะไร
     * ปิดไว้ทั้งหมดจนกว่าจะมี mapping + UAT เทียบยอดจริงผ่าน
     */
    private function seedPlannedReports(): void
    {
        $planned = [
            ['booking.outstanding', 'booking', 'ใบจอง', 'ใบจองคงค้างและสถานะส่งสินค้า', 'sales.manage', 'ฝ่ายขาย/คลัง', 'daily', 'P0', 'POPSTAR2022'],
            ['booking.due', 'booking', 'ใบจอง', 'ใบจองครบกำหนด/เกินกำหนดส่ง', 'sales.manage', 'ฝ่ายขาย/ผู้จัดการ', 'daily', 'P0', 'POPSTAR2022-1'],
            ['booking.by_branch_seller', 'booking', 'ใบจอง', 'ใบจองตามสาขา/สายขาย/พนักงานขาย', 'sales.manage', 'ผู้จัดการขาย', 'daily', 'P0', 'POPSTAR2022-1'],
            ['ap.outstanding_detail', 'ap', 'เจ้าหนี้', 'รายละเอียดยอดเจ้าหนี้คงค้าง', 'finance.manage', 'บัญชี/จัดซื้อ', 'daily', 'P0', 'SQLAP569005'],
            ['ap.aging', 'ap', 'เจ้าหนี้', 'อายุหนี้เจ้าหนี้ (AP aging)', 'finance.manage', 'บัญชี/จัดซื้อ', 'daily', 'P0', 'SQLAP569005'],
            ['cash.daily_cash_book', 'cash', 'เงินสด/ธนาคาร', 'สมุดเงินสดรายวัน (ยกมา/รับ/จ่าย/คงเหลือ)', 'finance.manage', 'การเงิน', 'daily', 'P0', 'CAS0103002'],
            ['cash.bank_summary', 'cash', 'เงินสด/ธนาคาร', 'สรุปยอดธนาคารตามบัญชี', 'finance.manage', 'การเงิน/บัญชี', 'daily', 'P0', 'BK01030001'],
            ['cash.bank_reconciliation', 'cash', 'เงินสด/ธนาคาร', 'รายการกระทบยอด statement', 'finance.manage', 'การเงิน/บัญชี', 'daily', 'P0', null],
            ['sales.daily_by_channel', 'sales', 'ขาย', 'ยอดขายรายวัน แยก POS/ขายสดหลังบ้าน/ขายเชื่อ', 'sales.manage', 'ผู้จัดการ/การเงิน', 'daily', 'P0', null],
            ['payment.received_and_unidentified', 'payment', 'รับชำระ / การเงิน', 'การรับชำระและยอดเงินรอพิสูจน์', 'finance.manage', 'การเงิน', 'daily', 'P0', null],
        ];
        $now = now();
        $order = 0;
        foreach ($planned as [$code, $category, $categoryTitle, $name, $permission, $owner, $frequency, $priority, $legacy]) {
            $order += 10;
            DB::table('report_definitions')->insert([
                'code' => $code,
                'category' => $category,
                'category_title' => $categoryTitle,
                'name' => $name,
                'view_permission' => $permission,
                'owner_role' => $owner,
                'frequency' => $frequency,
                'priority' => $priority,
                'status' => 'planned',
                'enabled' => false,
                'sort_order' => $order,
                'legacy_code' => $legacy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** ยกมาจาก ReportController::catalog() ตอน migration นี้ถูกเขียน */
    private function existingReports(): array
    {
        return [
            ['sales.daily_sales', 'sales', 'ขาย', 'ยอดขายรายวัน', 'sales.manage', 10],
            ['sales.sales_by_branch', 'sales', 'ขาย', 'ยอดขายตามสาขา', 'sales.manage', 20],
            ['sales.sales_by_staff', 'sales', 'ขาย', 'ยอดขายตามพนักงาน/แคชเชียร์', 'sales.manage', 30],
            ['sales.sales_by_category', 'sales', 'ขาย', 'ยอดขายตามหมวดสินค้า', 'sales.manage', 40],
            ['sales.sales_by_seller', 'sales', 'ขาย', 'ยอดขายตามคนขาย', 'sales.manage', 50],
            ['sales.sales_by_category_seller', 'sales', 'ขาย', 'ยอดขายตามหมวดสินค้า / คนขาย', 'sales.manage', 60],
            ['sales.top_products', 'sales', 'ขาย', 'สินค้าขายดี', 'sales.manage', 70],
            ['sales.products_by_branch', 'sales', 'ขาย', 'สินค้าขายตามสาขา', 'sales.manage', 80],
            ['sales.credit_sales', 'sales', 'ขาย', 'ใบขายเชื่อ', 'sales.manage', 90],
            ['sales.pending_bookings', 'sales', 'ขาย', 'ใบจองค้างแปลงขาย', 'sales.manage', 100],
            ['sales.sales_by_booking', 'sales', 'ขาย', 'ยอดขายตามใบจอง', 'sales.manage', 110],
            ['sales.sales_returns_by_document', 'sales', 'ขาย', 'ใบขาย-รับคืน ตามเอกสาร', 'sales.manage', 120],
            ['sales.bplus_sales_return_by_document', 'sales', 'ขาย', 'รายงานรับจ่าย-รับคืนสินค้า ตามเอกสาร', 'sales.manage', 130],
            ['sales.sale_return_by_product', 'sales', 'ขาย', 'สรุปขาย-รับคืน ตามสินค้า', 'sales.manage', 140],
            ['sales.bplus_sale_return_by_product', 'sales', 'ขาย', 'รายงานสรุปการขาย-รับคืนตามสินค้า', 'sales.manage', 150],
            ['sales.sales_summary_by_customer', 'sales', 'ขาย', 'รายงานสรุปยอดขายตามลูกค้า', 'sales.manage', 160],
            ['sales.sales_summary_12m_customer', 'sales', 'ขาย', 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้', 'sales.manage', 170],
            ['sales.sales_summary_12m_customer_product', 'sales', 'ขาย', 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้-สินค้า', 'sales.manage', 180],
            ['sales.sales_summary_12m_category', 'sales', 'ขาย', 'รายงานสรุปยอดขาย 12 เดือน ตามหมวดสินค้า', 'sales.manage', 190],
            ['sales.sales_summary_12m_salesman_product', 'sales', 'ขาย', 'รายงานสรุปยอดขาย 12 เดือน ตามพนักงานขาย-สินค้า', 'sales.manage', 200],
            ['management.gross_margin', 'management', 'ผู้บริหาร', 'กำไรขั้นต้นเบื้องต้น', 'finance.manage', 210],
            ['management.loss_sales', 'management', 'ผู้บริหาร', 'สินค้าขายต่ำกว่าทุน / ขาดทุน', 'finance.manage', 220],
            ['management.loss_sales_6m', 'management', 'ผู้บริหาร', 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน', 'finance.manage', 230],
            ['management.loss_sales_6m_by_type', 'management', 'ผู้บริหาร', 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามประเภทสินค้า', 'finance.manage', 240],
            ['management.loss_sales_6m_by_brand', 'management', 'ผู้บริหาร', 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามยี่ห้อสินค้า', 'finance.manage', 250],
            ['management.loss_sales_6m_by_category', 'management', 'ผู้บริหาร', 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามหมวดสินค้า', 'finance.manage', 260],
            ['management.loss_sales_6m_by_supplier', 'management', 'ผู้บริหาร', 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามผู้จำหน่ายหลัก', 'finance.manage', 270],
            ['management.loss_price_table', 'management', 'ผู้บริหาร', 'รายงานราคาขายต่ำกว่าทุนตามตารางราคา', 'finance.manage', 280],
            ['management.loss_sales_documents_summary', 'management', 'ผู้บริหาร', 'รายงานสรุปเอกสารขายที่ขาดทุน', 'finance.manage', 290],
            ['management.loss_sales_documents_detail', 'management', 'ผู้บริหาร', 'รายงานรายละเอียดเอกสารขายที่ขาดทุน', 'finance.manage', 300],
            ['ar.ar_summary', 'ar', 'ลูกหนี้', 'สรุปยอดลูกหนี้', 'finance.manage', 310],
            ['ar.ar_summary_bplus', 'ar', 'ลูกหนี้', 'รายงานสรุปยอดลูกหนี้', 'finance.manage', 320],
            ['ar.ar_aging', 'ar', 'ลูกหนี้', 'อายุหนี้ AR Aging', 'finance.manage', 330],
            ['ar.overdue_customers', 'ar', 'ลูกหนี้', 'ลูกหนี้เกินกำหนด', 'finance.manage', 340],
            ['ar.open_items', 'ar', 'ลูกหนี้', 'ลูกหนี้คงค้าง', 'finance.manage', 350],
            ['ar.ar_detail_short', 'ar', 'ลูกหนี้', 'รายงานรายละเอียดยอดลูกหนี้ แบบย่อ', 'finance.manage', 360],
            ['ar.ar_detail_full', 'ar', 'ลูกหนี้', 'รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด', 'finance.manage', 370],
            ['ar.ar_overdue_detail', 'ar', 'ลูกหนี้', 'รายงานรายละเอียดลูกหนี้เกินกำหนดชำระ', 'finance.manage', 380],
            ['ar.ar_over_credit_limit', 'ar', 'ลูกหนี้', 'รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต', 'finance.manage', 390],
            ['inventory.stock_balance', 'inventory', 'สินค้าและสต็อก', 'สินค้าคงเหลือ', 'stock.manage', 400],
            ['inventory.stock_by_branch', 'inventory', 'สินค้าและสต็อก', 'สต็อกตามสาขา', 'stock.manage', 410],
            ['inventory.stock_alerts', 'inventory', 'สินค้าและสต็อก', 'สต็อกต่ำ / ติดลบ', 'stock.manage', 420],
            ['inventory.expiring_stock', 'inventory', 'สินค้าและสต็อก', 'Lot ใกล้หมดอายุ / หมดอายุ', 'stock.manage', 430],
            ['inventory.stock_movements', 'inventory', 'สินค้าและสต็อก', 'เคลื่อนไหวสินค้า', 'stock.manage', 440],
            ['documents.documents_summary', 'documents', 'เอกสาร', 'สรุปเอกสาร', 'sales.manage', 450],
            ['documents.document_list', 'documents', 'เอกสาร', 'รายการเอกสารทั้งหมด', 'sales.manage', 460],
            ['documents.document_items', 'documents', 'เอกสาร', 'รายการสินค้าในเอกสาร', 'sales.manage', 470],
            ['documents.booking_documents', 'documents', 'เอกสาร', 'ใบจอง', 'sales.manage', 480],
            ['documents.cash_sale_documents', 'documents', 'เอกสาร', 'ใบขายสด', 'sales.manage', 490],
            ['documents.credit_sale_documents', 'documents', 'เอกสาร', 'ใบขายเชื่อ', 'sales.manage', 500],
            ['documents.sale_return_documents', 'documents', 'เอกสาร', 'ใบรับคืนสินค้า', 'sales.manage', 510],
            ['documents.receipt_documents', 'documents', 'เอกสาร', 'ใบเสร็จรับเงิน', 'sales.manage', 520],
            ['pos.pos_receipts', 'pos', 'POS', 'ใบเสร็จ POS', 'sales.manage', 530],
            ['pos.pos_by_terminal', 'pos', 'POS', 'ยอดขายตามเครื่อง POS', 'sales.manage', 540],
            ['pos.pos_payments', 'pos', 'POS', 'รับชำระตามช่องทาง', 'sales.manage', 550],
            ['pos.pos_hourly', 'pos', 'POS', 'ยอดขายรายชั่วโมง', 'sales.manage', 560],
            ['pos.pos_tax_discount', 'pos', 'POS', 'ภาษี / ส่วนลด POS', 'sales.manage', 570],
            ['purchasing.purchase_documents', 'purchasing', 'ซื้อสินค้า', 'เอกสารซื้อสินค้า', 'purchasing.manage', 580],
            ['purchasing.purchase_by_supplier', 'purchasing', 'ซื้อสินค้า', 'ยอดซื้อตามผู้ขาย', 'purchasing.manage', 590],
            ['purchasing.purchase_items', 'purchasing', 'ซื้อสินค้า', 'รับสินค้าเข้าตามสินค้า', 'purchasing.manage', 600],
            ['transfer.stock_transfers', 'transfer', 'โอนสินค้า', 'เอกสารโอนสินค้า', 'stock.manage', 610],
            ['transfer.transfer_items', 'transfer', 'โอนสินค้า', 'รายการสินค้าโอน', 'stock.manage', 620],
            ['transfer.transfer_by_location', 'transfer', 'โอนสินค้า', 'ยอดโอนตามคลังต้นทาง/ปลายทาง', 'stock.manage', 630],
            ['payment.payment_documents', 'payment', 'รับชำระ / การเงิน', 'เอกสารรับชำระ', 'finance.manage', 640],
            ['payment.payment_allocations', 'payment', 'รับชำระ / การเงิน', 'ตัดหนี้ / จัดสรรยอด', 'finance.manage', 650],
            ['payment.gl_journals', 'payment', 'รับชำระ / การเงิน', 'GL Journal', 'finance.manage', 660],
            ['tax.vat_sales', 'tax', 'ภาษี (ภพ.30)', 'รายงานภาษีขาย', 'finance.manage', 670],
            ['tax.vat_purchase', 'tax', 'ภาษี (ภพ.30)', 'รายงานภาษีซื้อ', 'finance.manage', 680],
            ['audit.void_bill_history', 'audit', 'ตรวจสอบระบบ', 'ประวัติลบบิล / ยกเลิกบิลย้อนหลัง', 'settings.manage', 690],
            ['audit.deleted_bill_audit', 'audit', 'ตรวจสอบระบบ', 'ตรวจสอบเอกสารที่ถูกยกเลิก', 'settings.manage', 700],
            ['audit.pending_work', 'audit', 'ตรวจสอบระบบ', 'งานค้างต้องตาม', 'settings.manage', 710],
            ['custom.legacy_daily_pos', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: ยอดขายรายวัน POS (p_reportZ)', 'reports.view', 720],
            ['custom.legacy_daily_summary', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: สรุป POS + หลังบ้าน (p_daily_sales_summary)', 'reports.view', 730],
            ['custom.legacy_salesman', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: ยอดขายตามพนักงาน (p_sale-BI6)', 'reports.view', 740],
            ['custom.legacy_sales_profit', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: วิเคราะห์กำไรตามสินค้า (p_sales_profit)', 'reports.view', 750],
            ['custom.legacy_reorder', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: เติมเต็ม/แผนสต๊อก (p_planstock)', 'reports.view', 760],
            ['custom.legacy_sales_return', 'custom', 'รายงานต้นแบบ PopStar เดิม', 'ตัวอย่างเดิม: ขาย-รับคืนตามเอกสาร', 'reports.view', 770],
        ];
    }
};
