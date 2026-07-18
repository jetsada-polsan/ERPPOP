<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: GOODSMASTER
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('barcode', 50)->unique();
            $table->foreignId('unit_id')->constrained('product_units')->restrictOnDelete();
            $table->decimal('unit_factor', 18, 4)->default(1);
            $table->decimal('price', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
