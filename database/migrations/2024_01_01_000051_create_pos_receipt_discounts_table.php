<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSS (S202103..S202511)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_id')->constrained('pos_receipts')->cascadeOnDelete();
            $table->string('discount_type', 30);
            $table->decimal('amount', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_discounts');
    }
};
