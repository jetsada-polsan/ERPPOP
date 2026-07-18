<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// new: pos_import - staged PSP payment rows
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('receipt_id')->constrained('imported_receipts')->cascadeOnDelete();
            $table->string('payment_code', 20)->nullable();
            $table->string('payment_name', 50)->nullable();
            $table->decimal('amount', 18, 4);
            $table->decimal('change_amount', 18, 4)->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->index('receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_payments');
    }
};
