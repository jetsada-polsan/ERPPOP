<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('report_definitions')->updateOrInsert(
            ['code' => 'sales.bplus_credit_sales_by_document'],
            [
                'category' => 'sales',
                'category_title' => 'ขาย',
                'name' => 'BPlus ใบขายเชื่อแยกตามเอกสาร',
                'view_permission' => 'sales.manage',
                'sort_order' => 91,
                'status' => 'available',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('report_definitions')->updateOrInsert(
            ['code' => 'sales.bplus_credit_sales_by_product'],
            [
                'category' => 'sales',
                'category_title' => 'ขาย',
                'name' => 'BPlus ใบขายเชื่อแยกตามสินค้า',
                'view_permission' => 'sales.manage',
                'sort_order' => 92,
                'status' => 'available',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('report_definitions')->whereIn('code', [
            'sales.bplus_credit_sales_by_document',
            'sales.bplus_credit_sales_by_product',
        ])->delete();
    }
};
