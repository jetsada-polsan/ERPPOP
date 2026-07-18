<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSD (D202103..D202511)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_id')->constrained('pos_receipts')->cascadeOnDelete();
            $table->integer('seq');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->index('pos_receipt_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_items');
    }
};
