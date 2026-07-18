<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: TRANPAYA
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')->constrained('payment_documents')->cascadeOnDelete();
            $table->foreignId('customer_open_item_id')->nullable()->constrained('customer_open_items')->nullOnDelete();
            $table->decimal('allocated_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('wht_amount', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
