<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// new: pos_import - staged PSH header rows, before posting
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('pos_code', 20);
            $table->string('receipt_no', 30);
            $table->date('receipt_date');
            $table->time('receipt_time')->nullable();
            $table->string('cashier_code', 20)->nullable();
            $table->string('member_code', 30)->nullable();
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->integer('item_count')->default(0);
            $table->jsonb('raw_data')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('posted_pos_receipt_id')->nullable()->constrained('pos_receipts')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['pos_code', 'receipt_no', 'receipt_date']);
            $table->index('batch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_receipts');
    }
};
