<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ผูก user กับ salesman (รหัสพนักงานขาย/แคชเชียร์) เพื่อบังคับ POS ให้ขายในชื่อ
// ตัวเองเท่านั้น + ผูกสาขา (users.branch_id มีอยู่แล้ว) เพื่อล็อกสาขาที่ขายได้
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('salesman_id')->nullable()->after('branch_id')
                ->constrained('salesmen')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('salesman_id'));
    }
};
