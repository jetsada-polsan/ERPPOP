<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('negative_stock_policy', 20)->default('allow')->after('tracks_expiry');
            $table->decimal('reorder_point', 18, 4)->nullable()->after('negative_stock_policy');
            $table->decimal('minimum_stock', 18, 4)->nullable()->after('reorder_point');
            $table->decimal('maximum_stock', 18, 4)->nullable()->after('minimum_stock');
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('supplier_sku', 80)->nullable();
            $table->decimal('last_purchase_price', 18, 4)->nullable();
            $table->decimal('minimum_order_qty', 18, 4)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'negative_stock_policy', 'reorder_point', 'minimum_stock', 'maximum_stock',
        ]));
    }
};
