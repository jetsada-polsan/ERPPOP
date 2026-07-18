<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ใบวางบิล (Billing Note): รวบใบขายเชื่อที่ค้างชำระของลูกค้าเป็น 1 ใบสำหรับรอบ
// เก็บเงิน ไม่กระทบยอดหนี้/สต๊อก (เป็นแค่เอกสารสรุปยอดวางบิล) การรับชำระจริง
// ยังทำผ่านหน้ารับชำระเดิมที่ตัด customer_open_items
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_notes', function (Blueprint $table) {
            $table->id();
            $table->string('doc_number', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('doc_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('open'); // open|collected|cancelled
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        Schema::create('billing_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_note_id')->constrained('billing_notes')->cascadeOnDelete();
            $table->foreignId('customer_open_item_id')->constrained('customer_open_items')->cascadeOnDelete();
            $table->decimal('balance_at_billing', 18, 2); // ยอดค้าง ณ วันวางบิล (snapshot)
            $table->unique(['billing_note_id', 'customer_open_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_note_items');
        Schema::dropIfExists('billing_notes');
    }
};
