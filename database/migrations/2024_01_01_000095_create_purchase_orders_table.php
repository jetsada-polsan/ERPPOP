<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AP ต้นน้ำ (ใบขอซื้อ -> อนุมัติ -> ใบสั่งซื้อ -> รับของ): เอกสารเดียวไหลผ่านสถานะ
// requested (ขอซื้อ) -> approved (อนุมัติ) -> ordered (สั่งผู้ขาย) -> received (รับของ)
// เมื่อรับของจะสร้างใบซื้อจริงผ่าน PurchaseService (ตัดสต๊อก+ตั้งหนี้+GL) แล้ว
// ผูก received_document_id กลับมา
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('doc_number', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->date('doc_date');
            $table->date('need_by_date')->nullable();
            $table->string('status', 20)->default('requested');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->boolean('is_credit')->default(true);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('received_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
