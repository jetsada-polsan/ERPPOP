<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใบจองต้องบอกได้ว่า "ต้องส่งเมื่อไร" และ "ส่งครบหรือยัง"
 *
 * เดิม sale_bookings มีแค่ status (pending/confirmed) จึงตอบไม่ได้ว่าใบไหนเกินกำหนดส่ง
 * ห้ามใช้ customer_open_items.due_date แทน เพราะนั่นคือกำหนด *ชำระเงิน* คนละเรื่องกับกำหนด *ส่งของ*
 *
 * ไม่ใช้ ->after() เพราะมีผลเฉพาะ MySQL — production เป็น PostgreSQL และเทสต์เป็น SQLite
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->string('fulfillment_type', 10)->default('pickup');   // pickup | delivery
            $table->timestamp('delivery_due_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status', 12)->default('pending');   // pending | partial | delivered | cancelled
            $table->index(['delivery_status', 'delivery_due_at'], 'sale_bookings_delivery_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropIndex('sale_bookings_delivery_due_idx');
            $table->dropColumn(['fulfillment_type', 'delivery_due_at', 'delivered_at', 'delivery_status']);
        });
    }
};
