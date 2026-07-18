<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_vat')->default(true)->after('average_cost');
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('lot_number', 80);
            $table->date('received_date');
            $table->date('expiry_date')->nullable();
            $table->decimal('initial_qty', 18, 4);
            $table->decimal('remaining_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();
            $table->index(['product_id', 'warehouse_location_id', 'received_date'], 'stock_lots_fifo_idx');
            $table->index(['lot_number', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_lot_id')->nullable()->after('document_id')->constrained('stock_lots')->nullOnDelete();
            $table->index(['stock_lot_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_lot_id');
        });
        Schema::dropIfExists('stock_lots');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_vat');
        });
    }
};
