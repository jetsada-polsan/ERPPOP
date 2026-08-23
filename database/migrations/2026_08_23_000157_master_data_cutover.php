<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เปลี่ยนรหัสสาขาและรหัสสินค้าเป็นชุดใหม่ตอนเริ่มระบบ
 *
 * รหัสเดิมเก็บไว้เพื่อ mapping กับรายงานของระบบเก่าเท่านั้น ไม่ใช้อ้างอิงในระบบใหม่
 * บาร์โค้ดไม่ถูกแตะเลยแม้แต่แถวเดียว — ของที่พิมพ์ติดสินค้าไปแล้วเปลี่ยนตามไม่ได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('legacy_sku', 60)->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->index('legacy_sku');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('legacy_branch_code', 30)->nullable();
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->index('legacy_branch_code');
        });

        Schema::create('master_cutover_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);                 // branches | products
            $table->unsignedInteger('mapped_count')->default(0);
            $table->string('first_code', 40)->nullable();
            $table->string('last_code', 40)->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // ชุดเดียวกันทำได้ครั้งเดียว ห้ามรันซ้ำแล้วไล่เลขทับของเดิม
            $table->unique('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_cutover_runs');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('legacy_sku'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('legacy_branch_code'));
    }
};
