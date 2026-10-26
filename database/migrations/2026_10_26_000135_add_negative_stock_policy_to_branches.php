<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// นโยบายสต๊อกติดลบรายสาขา: 'block' = ห้ามขาย/ตัดเกินยอดคงเหลือ, 'allow' = ยอมให้ติดลบ.
//
// ค่าเริ่มต้นเป็น 'allow' โดยตั้งใจ เพื่อให้การ deploy ไม่เปลี่ยนพฤติกรรมของสาขาที่
// ใช้งานอยู่ทันที (ถ้า default เป็น block สาขาที่สต๊อกติดลบอยู่แล้วจะขายไม่ได้ทันที
// ที่ migrate) ให้ผู้ดูแลเปิด block ทีละสาขาจากหน้า /settings/stock-policies เอง.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('negative_stock_policy', 20)->default('allow')->after('default_warehouse_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('negative_stock_policy');
        });
    }
};
