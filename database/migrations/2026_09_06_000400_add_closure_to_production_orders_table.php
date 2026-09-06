<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// เพิ่มคอลัมน์ปิดงานผลิตด้วยมือให้ production_orders - ปัจจุบัน status จะเป็น
// 'completed' อัตโนมัติเฉพาะตอน produced_qty >= planned_qty เท่านั้น
// (ProductionReceiptService::receive()) ถ้าของจริงได้น้อยกว่าแผน (ผลผลิตขาด/วัตถุดิบ
// หมด) ใบสั่งผลิตจะค้างสถานะ in_progress ตลอดไป ไม่มีทางปิดงานยอมรับยอดที่ได้จริงได้
// คอลัมน์นี้ใช้บันทึกว่าใครปิดงานเอง เมื่อไร และเหตุผลอะไร (บังคับกรอกเสมอเวลาปิดสั้น
// กว่าแผน) แยกจากการปิดงานอัตโนมัติที่ผลิตครบ (closed_at จะว่างในกรณีนั้น)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->text('close_note')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'close_note']);
        });
    }
};
