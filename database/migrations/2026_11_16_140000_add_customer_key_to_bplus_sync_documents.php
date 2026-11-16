<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// รหัสลูกค้าใน ERP มาจาก AR_KEY ของ BPlus (ไม่ใช่ AR_CODE) เก็บไว้ใช้จับคู่ลูกค้า
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->string('customer_key', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->dropColumn('customer_key');
        });
    }
};
