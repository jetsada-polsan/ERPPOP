<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSC (C202103..C202511) -- 0 rows in source, kept for completeness
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_id')->constrained('pos_receipts')->cascadeOnDelete();
            $table->string('coupon_code', 50);
            $table->decimal('amount', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_coupons');
    }
};
