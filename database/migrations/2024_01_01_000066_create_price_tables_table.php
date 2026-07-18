<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Price tables (ตารางราคา) mirror BPlus's price-table system: each named table
// holds a set of product prices per unit. Branches are assigned a default table
// so different branches can sell at different prices (วาริน ≠ ตลาด ≠ สมาชิก).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('price_table_id')->constrained('price_tables')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->decimal('price', 18, 4)->default(0);
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->decimal('min_qty', 18, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->unique(['product_id', 'price_table_id', 'unit_id']);
        });

        // Assign each branch a default price table (nullable = use global default)
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('price_table_id')->nullable()->after('default_warehouse_location_id')
                ->constrained('price_tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn ($t) => $t->dropConstrainedForeignId('price_table_id'));
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_tables');
    }
};
