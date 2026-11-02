<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เพิ่มคอลัมน์อย่างเดียว ไม่แตะแถวเดิม: แถวจาก BPlus จะมีวันที่แก้ไขว่างจนกว่าจะมีคนแก้
 * การจัดประเภทข้อมูลเดิมทำผ่าน erp:classify-barcodes ซึ่งต้องสั่งเอง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            if (! Schema::hasColumn('product_barcodes', 'scale_barcode_profile_id')) {
                $table->foreignId('scale_barcode_profile_id')->nullable()->constrained('scale_barcode_profiles')->nullOnDelete();
            }
            if (! Schema::hasColumn('product_barcodes', 'created_at')) {
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scale_barcode_profile_id');
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};
