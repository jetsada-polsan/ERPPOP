<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// new: pos_import - every validation failure, kept for audit
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('receipt_no', 30)->nullable();
            $table->integer('line_no')->nullable();
            $table->string('error_type', 30);
            $table->text('error_message');
            $table->jsonb('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
