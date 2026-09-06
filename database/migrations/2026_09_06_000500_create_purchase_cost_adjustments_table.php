<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ปรับต้นทุนซื้อย้อนหลัง (เช่น กรอกราคาผิดตอนรับของ/ใบแจ้งหนี้จริงมาทีหลังราคาต่างจากที่
// บันทึกไว้) ตารางนี้เป็น "หลักฐานการปรับ" เท่านั้น ไม่ใช่ตารางที่ธุรกิจอ่านค่าไปคำนวณต่อ -
// ค่าจริงที่มีผลคือ stock_lots.unit_cost (เฉพาะจำนวนคงเหลือ) และ products.average_cost
// ที่ปรับให้สอดคล้องกันในเซอร์วิส ดู PurchaseCostAdjustmentService สำหรับตรรกะ/ข้อจำกัด
// สำคัญ: ส่วนที่ตัดสต๊อกออกไปแล้ว (consumed_qty) "ไม่" ย้อนแก้ต้นทุนขายที่บันทึกไปแล้ว
// (ห้ามแก้ยอดขาย/COGS ย้อนหลังตามนโยบายของระบบ) เก็บไว้แค่มูลค่าผลต่างเพื่ออ้างอิงทางบัญชี
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_cost_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('old_unit_cost', 20, 8);
            $table->decimal('new_unit_cost', 20, 8);
            $table->decimal('remaining_qty_adjusted', 20, 8);
            $table->decimal('consumed_qty', 20, 8);
            $table->decimal('unconfirmed_variance_amount', 20, 8);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_cost_adjustments');
    }
};
