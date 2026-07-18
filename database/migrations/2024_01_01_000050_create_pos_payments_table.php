<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSP (P202103..P202511)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_id')->constrained('pos_receipts')->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 18, 4);
            $table->decimal('cash_received', 18, 4)->nullable();
            $table->decimal('change_amount', 18, 4)->nullable();
            $table->string('card_no', 30)->nullable();
            $table->string('cheque_no', 30)->nullable();
            $table->index('pos_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
