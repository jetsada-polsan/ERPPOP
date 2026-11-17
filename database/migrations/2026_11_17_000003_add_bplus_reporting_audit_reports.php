<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REPORTS = [
        ['sales.bplus_daily_sales_summary', 'sales', 'ขาย', 'BPlus สรุปขายเชื่อรายวัน', 'sales.manage', 93],
        ['payment.bplus_receipt_allocations', 'payment', 'รับชำระ / การเงิน', 'BPlus ใบเสร็จตัดหนี้ตามเอกสาร', 'finance.manage', 655],
        ['ar.ar_statement', 'ar', 'ลูกหนี้', 'Statement ลูกหนี้รายตัว', 'finance.manage', 395],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::REPORTS as [$code, $category, $categoryTitle, $name, $permission, $sortOrder]) {
            DB::table('report_definitions')->updateOrInsert(
                ['code' => $code],
                [
                    'category' => $category,
                    'category_title' => $categoryTitle,
                    'name' => $name,
                    'view_permission' => $permission,
                    'sort_order' => $sortOrder,
                    'status' => 'available',
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('report_definitions')->whereIn('code', array_column(self::REPORTS, 0))->delete();
    }
};
