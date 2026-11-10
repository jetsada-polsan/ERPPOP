<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            // เครื่อง POS รายงานตัวเองทุก ~5 นาที: ข้อมูลชุดไหนที่เครื่องใช้อยู่ บิลค้างส่งกี่ใบ เวอร์ชันโปรแกรม
            $table->timestamp('sync_reported_at')->nullable();
            $table->integer('pending_bills')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->text('applied_versions')->nullable();
            $table->timestamp('master_pulled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropColumn(['sync_reported_at', 'pending_bills', 'app_version', 'applied_versions', 'master_pulled_at']);
        });
    }
};
