<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// โปรระดับบิล: ยอดซื้อครบ X บาท ลดบาท/% หรือแถมสินค้า ไม่ผูกกับสินค้าตัวใดตัวหนึ่ง
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('qty_promotions', 'min_amount')) {
                $table->decimal('min_amount', 18, 2)->nullable()->after('min_qty');
            }
            if (! Schema::hasColumn('qty_promotions', 'max_discount')) {
                $table->decimal('max_discount', 18, 2)->nullable()->after('discount_value');
            }
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->dropColumn(['min_amount', 'max_discount']);
        });
    }
};
