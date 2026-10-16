<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บิลที่ ERP ปฏิเสธจาก POS desktop (Python/SQLite) ก็ต้องมาให้ผู้จัดการเห็นที่หน้าเดียวกับ Web POS
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_offline_sales', function (Blueprint $table): void {
            $table->string('source', 20)->default('web');
            $table->foreignId('pos_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_offline_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_device_id');
            $table->dropColumn('source');
        });
    }
};
