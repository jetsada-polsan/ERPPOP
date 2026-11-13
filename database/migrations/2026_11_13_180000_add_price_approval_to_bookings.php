<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// เจ้าของ 13 พ.ย.: ราคาขายต่ำกว่าทุน "ให้บันทึกได้ แล้วเด้งไปให้ผู้จัดการอนุมัติ"
// ฝ่ายขายกดออกบิลได้ ระบบเก็บคำขอไว้ ผู้จัดการ (sales.price_below_cost) อนุมัติแล้วค่อยออกใบขายจริง
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->string('price_approval_status', 20)->nullable(); // pending | approved | rejected
            $table->text('price_approval_request')->nullable(); // JSON ของ bills ที่ฝ่ายขายกรอก
            $table->text('price_approval_note')->nullable();
            $table->foreignId('price_approval_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('price_approval_requested_at')->nullable();
            $table->foreignId('price_approval_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('price_approval_decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_approval_requested_by');
            $table->dropConstrainedForeignId('price_approval_decided_by');
            $table->dropColumn(['price_approval_status', 'price_approval_request', 'price_approval_note',
                'price_approval_requested_at', 'price_approval_decided_at']);
        });
    }
};
