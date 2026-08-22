<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ยอดเจ้าหนี้ค้างชำระรายใบ — คู่ขนานกับ customer_open_items ของฝั่งลูกหนี้
 *
 * AP aging ต้องคำนวณจากตารางนี้ ไม่ใช่จาก supplier_ledger เพราะ ledger เป็นสมุดเดินบัญชี
 * (entry_type/amount/balance_after) ซึ่งบอกไม่ได้ว่าเงินก้อนไหนค้างจากใบไหนและครบกำหนดเมื่อไร
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_open_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('document_no', 40);
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->decimal('original_amount', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('balance_amount', 18, 4)->default(0);
            $table->string('status', 20)->default('open');      // open | partial | cleared | cancelled
            $table->string('payment_terms', 60)->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'document_no']);
            $table->index(['status', 'due_date']);
            $table->index('source_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_open_items');
    }
};
