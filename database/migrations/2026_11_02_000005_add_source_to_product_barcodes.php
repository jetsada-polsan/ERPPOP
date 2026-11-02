<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ที่มาของบาร์โค้ด: generated_from_sku / generated_sequence = ระบบสร้างให้
 * แถวเดิมจาก BPlus เป็น null ตามเดิม ไม่แก้ค่าใด ๆ
 * barcode เป็น string และ unique อยู่แล้วตั้งแต่ตอนสร้างตาราง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_barcodes', 'source')) {
            Schema::table('product_barcodes', fn (Blueprint $table) => $table->string('source', 30)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('product_barcodes', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
