<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 40)->unique();
            $table->date('doc_date');
            $table->foreignId('production_recipe_id')->nullable()->constrained('production_recipes')->nullOnDelete();
            $table->foreignId('finished_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->decimal('planned_qty', 18, 4);
            $table->decimal('produced_qty', 18, 4)->default(0);
            $table->string('status', 20)->default('planned');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
