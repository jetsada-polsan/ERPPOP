<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Discount cards (บัตรส่วนลด): physical/membership cards a cashier scans at
// checkout to apply a fixed or percent discount to the whole bill. Optionally
// tied to a member, bounded by a validity window, minimum bill amount, a cap
// on the discount for percent cards, and a total redemption limit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_cards', function (Blueprint $table) {
            $table->id();
            $table->string('card_code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('discount_type', 10)->default('percent'); // percent|amount
            $table->decimal('discount_value', 8, 4);
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->decimal('max_discount_amount', 18, 4)->nullable();
            $table->date('starts_date')->nullable();
            $table->date('ends_date')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_cards');
    }
};
