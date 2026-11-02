<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_definitions')->updateOrInsert(
            ['code' => 'inventory.stock_card_detail'],
            [
                'category' => 'inventory',
                'category_title' => 'สินค้าและสต็อก',
                'name' => 'Stock Card รายละเอียด',
                'view_permission' => 'stock.manage',
                'sort_order' => 445,
                'status' => 'available',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('report_definitions')->where('code', 'inventory.stock_card_detail')->delete();
    }
};
