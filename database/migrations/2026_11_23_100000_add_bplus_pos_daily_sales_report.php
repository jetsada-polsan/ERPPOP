<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_definitions')->updateOrInsert(['code' => 'sales.bplus_pos_daily_sales'], [
            'category' => 'sales', 'category_title' => 'ขาย', 'name' => 'BPlus ภาษีขาย POS รายวัน',
            'view_permission' => 'sales.manage', 'sort_order' => 94, 'status' => 'available', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('report_definitions')->where('code', 'sales.bplus_pos_daily_sales')->delete();
    }
};
