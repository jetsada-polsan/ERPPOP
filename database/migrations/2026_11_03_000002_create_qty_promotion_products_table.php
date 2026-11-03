<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// สินค้าในกลุ่มของโปรซื้อคละ (หยิบตัวไหนในกลุ่มก็นับรวมกัน)
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qty_promotion_products')) {
            return;
        }
        Schema::create('qty_promotion_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qty_promotion_id')->constrained('qty_promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['qty_promotion_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qty_promotion_products');
    }
};
