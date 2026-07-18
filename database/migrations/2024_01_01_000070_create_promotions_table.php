<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('promotion_type', 40)->default('discount');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->decimal('min_qty', 18, 4)->nullable();
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->decimal('discount_amount', 18, 4)->nullable();
            $table->decimal('discount_percent', 8, 4)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
