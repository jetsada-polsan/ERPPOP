<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บิลที่ Web POS ขายตอนออฟไลน์ แล้วส่งขึ้นมาทีหลัง
 *
 * offline_uuid กันบิลซ้ำเมื่อ client ส่งซ้ำ; บิลที่ server รับไม่ได้ (ราคาไม่ตรง
 * กะปิดแล้ว สินค้าถูกปิด) ยังเก็บไว้ทั้งก้อนเป็น needs_review ให้ผู้จัดการตรวจ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offline_sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('offline_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->decimal('offline_total', 14, 2)->default(0);
            $table->string('status', 20)->default('processing');
            $table->foreignId('pos_receipt_id')->nullable()->constrained('pos_receipts')->nullOnDelete();
            $table->string('receipt_no', 60)->nullable();
            $table->text('review_reason')->nullable();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_offline_sales');
    }
};
