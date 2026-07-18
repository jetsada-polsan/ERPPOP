<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ทะเบียนเช็ครับ-เช็คจ่าย (FN, BPlus manual ch.15):
// เช็ครับ: on_hand (ในมือ) -> deposited (นำฝาก) -> cleared (ผ่าน) | bounced (คืน/เด้ง)
// เช็คจ่าย: issued (ออกเช็ค) -> cleared (ตัดบัญชี) | cancelled
// สร้างอัตโนมัติเมื่อรับ/จ่ายชำระด้วยเช็ค หรือคีย์เพิ่มเองในทะเบียน
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3); // in|out
            $table->string('cheque_no', 40);
            $table->string('bank_name', 100)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('cheque_date'); // วันที่บนเช็ค / ครบกำหนดขึ้นเงิน
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('payment_document_id')->nullable()->constrained('payment_documents')->nullOnDelete();
            $table->string('status', 20)->default('on_hand');
            $table->date('deposited_at')->nullable();
            $table->date('cleared_at')->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->index(['direction', 'status']);
            $table->index('cheque_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
