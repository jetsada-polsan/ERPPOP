<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_id')->constrained('pos_receipts')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('return_no', 40);
            $table->timestamp('returned_at')->useCurrent();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_method', 20)->default('cash');
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->string('status', 20)->default('completed');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique('return_no');
            $table->index(['pos_receipt_id', 'status']);
            $table->index(['pos_shift_id', 'status']);
        });

        Schema::create('pos_receipt_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_receipt_return_id')->constrained('pos_receipt_returns')->cascadeOnDelete();
            $table->foreignId('pos_receipt_item_id')->nullable()->constrained('pos_receipt_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_return_items');
        Schema::dropIfExists('pos_receipt_returns');
    }
};
