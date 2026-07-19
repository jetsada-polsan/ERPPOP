<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->foreignId('production_recipe_id')->nullable()->constrained('production_recipes')->nullOnDelete();
            $table->foreignId('output_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('input_weight_qty', 18, 4);
            $table->decimal('output_weight_qty', 18, 4);
            $table->decimal('loss_weight_qty', 18, 4)->default(0);
            $table->decimal('yield_percent', 9, 4)->default(0);
            $table->decimal('total_input_cost', 18, 4);
            $table->decimal('output_unit_cost', 18, 4);
            $table->decimal('selling_unit_price', 18, 4)->default(0);
            $table->decimal('net_selling_unit_price', 18, 4)->default(0);
            $table->decimal('estimated_profit_per_unit', 18, 4)->default(0);
            $table->decimal('estimated_margin_percent', 9, 4)->default(0);
            $table->string('scale_plu', 6)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_batch_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained('production_batches')->cascadeOnDelete();
            $table->integer('seq');
            $table->decimal('weight_qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('total_price', 18, 4);
            $table->string('barcode', 13);
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
            $table->unique(['production_batch_id', 'seq']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_packages');
        Schema::dropIfExists('production_batches');
    }
};
