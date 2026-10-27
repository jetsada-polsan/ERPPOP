<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ใบจ่ายชำระเจ้าหนี้ต้องจำได้ว่าเงินแต่ละก้อนไปตัดใบซื้อค้างจ่ายใบไหน เท่าไร
// (เหมือนฝั่งลูกหนี้ที่ใช้ customer_open_item_id) ไม่อย่างนั้นยกเลิกใบจ่ายแล้วคืนยอด
// กลับเข้าใบเดิมไม่ได้ และรายงานก็ตอบไม่ได้ว่าใบซื้อใบนี้จ่ายด้วยใบจ่ายเลขที่อะไร
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('supplier_open_item_id')->nullable()->after('customer_open_item_id')
                ->constrained('supplier_open_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_open_item_id');
        });
    }
};
