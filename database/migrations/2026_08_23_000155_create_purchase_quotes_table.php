<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใบสอบราคา — เทียบราคาจากหลายผู้ขายก่อนตัดสินใจสั่งซื้อ
 *
 * ของเดิมมีใบขอซื้อ (purchase_orders สถานะ requested -> approved -> ordered) และดึงราคา
 * ตามข้อตกลงของผู้ขายรายเดียวได้ (supplierPrices) แต่ไม่มีที่เก็บว่า "เทียบกับใครบ้าง"
 * เมื่อสั่งซื้อไปแล้วจึงพิสูจน์ไม่ได้ว่าเลือกเจ้านี้เพราะถูกที่สุดหรือเพราะอะไร
 *
 * ตารางนี้เก็บใบเสนอราคาที่ได้รับจากแต่ละเจ้า และทำเครื่องหมายว่าเลือกใบไหน
 * พร้อมเหตุผล — เลือกเจ้าที่แพงกว่าได้ถ้ามีเหตุผล แต่ต้องบันทึกไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->date('valid_until')->nullable();
            $table->string('reference', 100)->nullable();      // เลขที่ใบเสนอราคาของผู้ขาย
            $table->text('note')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->string('selection_reason', 255)->nullable();
            $table->foreignId('quoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
            // ผู้ขายรายหนึ่งเสนอราคาต่อใบขอซื้อได้ใบเดียว แก้ไขได้แต่ไม่ซ้ำ
            $table->unique(['purchase_order_id', 'supplier_id']);
        });

        Schema::table('purchase_quotes', function (Blueprint $table) {
            $table->index(['purchase_order_id', 'is_selected'], 'purchase_quotes_selected_idx');
        });

        Schema::create('purchase_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_quote_id')->constrained('purchase_quotes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('note', 255)->nullable();
            $table->unique(['purchase_quote_id', 'purchase_order_item_id'], 'purchase_quote_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quote_items');
        Schema::dropIfExists('purchase_quotes');
    }
};
