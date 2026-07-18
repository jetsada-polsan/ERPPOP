<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// บังคับเปลี่ยนรหัสผ่านครั้งแรก: ใช้กับ user ที่ import จาก BPlus (รหัสเดิม
// 2-6 ตัวอักษรเก็บ plaintext ห้ามนำมาใช้) และเมื่อ admin ตั้งรหัสแทนผู้ใช้
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
