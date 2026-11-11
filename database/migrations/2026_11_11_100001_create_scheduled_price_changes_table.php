<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('price_table_id')->nullable()->constrained('price_tables')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->decimal('new_price', 18, 4);
            $table->decimal('old_price', 18, 4)->nullable();
            $table->timestamp('effective_at');
            $table->string('status', 20)->default('pending'); // pending, applied, cancelled, failed
            $table->foreignId('pos_price_schedule_id')->nullable()->constrained('pos_price_schedules')->nullOnDelete();
            $table->string('batch_ref', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_at'], 'scheduled_price_status_effective');
            $table->index('batch_ref', 'scheduled_price_batch_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_price_changes');
    }
};
