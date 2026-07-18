<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Qty promotions (แคมเปญซื้อจำนวนครบ): buy min_qty of a product and, per
// complete set, either get free_qty of free_product (ซื้อ 1 แถม 1 when both
// products are the same) or get a percent/baht discount off the set. The POS
// reads active campaigns and auto-adds gift lines / discounts in the cart.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qty_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('promo_type', 20)->default('free_item'); // free_item|discount
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('min_qty', 18, 4)->default(1);
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('free_qty', 18, 4)->nullable();
            $table->string('discount_type', 10)->nullable(); // percent|baht
            $table->decimal('discount_value', 18, 4)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('starts_date')->nullable();
            $table->date('ends_date')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qty_promotions');
    }
};
