<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * นโยบายรายงานที่เจ้าของโครงการตัดสินใจ 2026-08-23
 *
 * สิทธิ์:
 *  - เห็นข้ามสาขาให้เฉพาะคนที่ต้องตรวจทั้งบริษัทจริง (GM, IT_MGR, ACC_MGR, EXECUTIVE)
 *    พนักงานบัญชี (ACC) ส่งออกได้แต่ดูเฉพาะสาขาตัวเอง
 *  - CASHIER และ DELIVERY ไม่มีสิทธิ์รายงานเลย
 *  - MARKETING คงแค่ reports.view ไม่ให้ทั้งการเงินและข้ามสาขา จนกว่าจะกำหนดสาขาให้
 *
 * เจ้าของรายงานใช้ "ตำแหน่งงาน" ไม่ใช่ชื่อบุคคล ผู้บริหารแก้ได้เองจากทะเบียนรายงาน
 * รายงานที่จัดเป็น P1/P2 ปิดไว้ก่อนจนกว่าจะมี mapping และ UAT ผ่าน (เปิดกลับได้คลิกเดียว)
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->applyAccessPolicy();

        foreach ($this->reportPolicy() as [$codes, $owner, $frequency, $priority]) {
            DB::table('report_definitions')
                ->whereIn('code', $codes)
                ->update([
                    'owner_role' => $owner,
                    'frequency' => $frequency,
                    'priority' => $priority,
                    'updated_at' => now(),
                ]);
        }

        // P1/P2 ปิดไว้ก่อน — definition ยังอยู่ครบ ผู้บริหารเปิดกลับเองได้เมื่อ UAT ผ่าน
        DB::table('report_definitions')
            ->whereIn('priority', ['P1', 'P2'])
            ->update(['enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('report_definitions')
            ->whereNotNull('priority')
            ->update(['owner_role' => null, 'frequency' => 'unknown', 'priority' => null, 'updated_at' => now()]);

        DB::table('report_definitions')
            ->where('status', 'available')
            ->update(['enabled' => true, 'updated_at' => now()]);

        $this->grant('ACC', ['reports.all_branches']);
        $this->revoke('EXECUTIVE', ['reports.export', 'reports.all_branches']);
    }

    private function applyAccessPolicy(): void
    {
        // ผู้บริหารตรวจทั้งบริษัท: ให้ครบทั้งดู ส่งออก และข้ามสาขา
        $this->grant('EXECUTIVE', ['reports.view', 'reports.export', 'reports.all_branches']);

        // พนักงานบัญชีทำงานในสาขาตัวเอง ไม่ใช่ผู้ตรวจทั้งบริษัท
        $this->revoke('ACC', ['reports.all_branches']);

        // หน้าร้านและพนักงานส่งของไม่มีสิทธิ์รายงานและไม่เห็นข้ามสาขา
        foreach (['CASHIER', 'DELIVERY'] as $roleCode) {
            $this->revoke($roleCode, ['reports.view', 'reports.export', 'reports.all_branches']);
        }

        // การตลาดยังไม่ให้การเงินและข้ามสาขา จนกว่าจะกำหนดสาขาและรายงานที่จำเป็น
        $this->revoke('MARKETING', ['reports.export', 'reports.all_branches', 'finance.manage']);
    }

    private function grant(string $roleCode, array $permissionCodes): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        if (! $roleId) {
            return;
        }
        foreach ($permissionCodes as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if ($permissionId && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    private function revoke(string $roleCode, array $permissionCodes): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        if (! $roleId) {
            return;
        }
        $permissionIds = DB::table('permissions')->whereIn('code', $permissionCodes)->pluck('id');
        DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->delete();
    }

    /** @return array<int, array{0: array<int, string>, 1: string, 2: string, 3: string}> */
    private function reportPolicy(): array
    {
        return [
            // --- P0 ใช้ทุกวัน ---
            [['sales.daily_sales'], 'ผู้จัดการสาขา / การเงิน', 'daily', 'P0'],
            [['sales.sales_by_branch', 'sales.sales_by_staff', 'sales.sales_by_seller',
                'sales.sales_by_category', 'sales.sales_by_category_seller'], 'ผู้จัดการขาย', 'daily', 'P0'],
            [['sales.sales_returns_by_document', 'sales.sale_return_by_product',
                'sales.bplus_sales_return_by_document', 'sales.bplus_sale_return_by_product'], 'ฝ่ายขาย / บัญชี', 'daily', 'P0'],
            [['ar.ar_summary', 'ar.ar_summary_bplus', 'ar.ar_aging', 'ar.overdue_customers', 'ar.open_items',
                'ar.ar_detail_short', 'ar.ar_detail_full', 'ar.ar_overdue_detail', 'ar.ar_over_credit_limit'], 'บัญชี / ฝ่ายขาย', 'daily', 'P0'],
            [['inventory.stock_balance', 'inventory.stock_by_branch'], 'คลัง / ผู้จัดการสาขา', 'daily', 'P0'],
            [['inventory.stock_alerts', 'inventory.expiring_stock'], 'คลัง / จัดซื้อ', 'daily', 'P0'],
            [['sales.credit_sales', 'sales.pending_bookings', 'sales.sales_by_booking'], 'ฝ่ายขาย', 'daily', 'P0'],
            [['pos.pos_receipts', 'pos.pos_by_terminal', 'pos.pos_payments', 'pos.pos_hourly', 'pos.pos_tax_discount'], 'ผู้จัดการสาขา', 'daily', 'P0'],

            // --- P1 รายวัน/รายเดือนระดับผู้จัดการ ---
            [['inventory.stock_movements'], 'คลัง / บัญชี', 'daily', 'P1'],
            [['sales.top_products', 'sales.products_by_branch'], 'ผู้จัดการ / จัดซื้อ', 'weekly', 'P1'],
            [['management.gross_margin'], 'ผู้บริหาร / บัญชี', 'daily', 'P1'],
            [['management.loss_sales', 'management.loss_sales_6m', 'management.loss_sales_6m_by_type',
                'management.loss_sales_6m_by_brand', 'management.loss_sales_6m_by_category',
                'management.loss_sales_6m_by_supplier', 'management.loss_price_table',
                'management.loss_sales_documents_summary', 'management.loss_sales_documents_detail'], 'ผู้จัดการ / บัญชี', 'daily', 'P1'],
            [['purchasing.purchase_documents', 'purchasing.purchase_by_supplier', 'purchasing.purchase_items'], 'จัดซื้อ / บัญชี', 'daily', 'P1'],
            [['tax.vat_sales', 'tax.vat_purchase'], 'บัญชี', 'monthly', 'P1'],

            // --- P2 เฉพาะกิจ ---
            [['sales.sales_summary_by_customer', 'sales.sales_summary_12m_customer',
                'sales.sales_summary_12m_customer_product', 'sales.sales_summary_12m_category',
                'sales.sales_summary_12m_salesman_product'], 'ผู้จัดการขาย', 'adhoc', 'P2'],
            [['transfer.stock_transfers', 'transfer.transfer_items', 'transfer.transfer_by_location'], 'คลัง', 'adhoc', 'P2'],
            [['audit.void_bill_history', 'audit.deleted_bill_audit', 'audit.pending_work'], 'ผู้ดูแลระบบ', 'adhoc', 'P2'],
        ];
    }
};
