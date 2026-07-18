<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// new: pos_import - staged PSD detail rows
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('receipt_id')->constrained('imported_receipts')->cascadeOnDelete();
            $table->integer('line_no');
            $table->string('product_code', 30)->nullable();
            $table->string('barcode', 50)->nullable();
            $table->string('sku_code', 30)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->jsonb('raw_data')->nullable();
            $table->string('mapping_status', 20)->default('pending');
            $table->index('receipt_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_receipt_items');
    }
};
