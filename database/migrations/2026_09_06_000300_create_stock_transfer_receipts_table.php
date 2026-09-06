<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ตรวจรับสินค้าโอนย้ายด้วยการสแกนบาร์โค้ดที่ปลายทาง (แยกจาก stock_counts ซึ่งเป็น
// การนับสต๊อกทั้งคลัง) - ปัจจุบัน StockTransferService::approve() ตัด/เพิ่มสต๊อกทันที
// ตามตัวเลขในเอกสาร โดยไม่มีขั้นตอนยืนยันว่าของจริงที่มาถึงตรงตามนั้นหรือไม่ (หยิบผิด/
// ของแตกระหว่างขนส่ง) ตารางนี้เก็บผลสแกนตรวจนับที่ปลายทางไว้เป็นชั้นหลักฐาน/ควบคุม
// เพิ่มเติมเท่านั้น - "ไม่" แก้ไข stock_balances เอง ถ้าพบส่วนต่างจริงพนักงานต้องไป
// สร้าง "ใบปรับสต๊อก" (stock-adjustments) แยกต่างหากตามขั้นตอนปกติ (มีคนอนุมัติอีกที)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->string('status', 20)->default('checking'); // checking|completed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_receipt_id')->constrained('stock_transfer_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('expected_qty', 20, 8)->default(0);
            $table->decimal('scanned_qty', 20, 8)->nullable();
            $table->string('note', 300)->nullable();
            $table->unique(['stock_transfer_receipt_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_items');
        Schema::dropIfExists('stock_transfer_receipts');
    }
};
