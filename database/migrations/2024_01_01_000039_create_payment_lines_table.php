<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: TRANPAYD
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')->constrained('payment_documents')->cascadeOnDelete();
            $table->integer('seq');
            $table->string('method', 20);
            $table->decimal('amount', 18, 4);
            $table->string('card_no', 30)->nullable();
            $table->string('cheque_no', 30)->nullable();
            $table->date('cheque_due_date')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_lines');
    }
};
