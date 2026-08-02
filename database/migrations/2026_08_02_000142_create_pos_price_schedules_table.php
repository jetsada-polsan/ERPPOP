<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_price_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->decimal('price', 18, 4);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled, published, cancelled
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'branch_id', 'unit_id', 'status'], 'pos_price_schedule_lookup');
            $table->index(['status', 'effective_from'], 'pos_price_schedule_activation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_price_schedules');
    }
};
