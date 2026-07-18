<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSH (H202103..H202511, 57 monthly tables consolidated)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_terminal_id')->constrained('pos_terminals')->restrictOnDelete();
            $table->string('receipt_no', 30);
            $table->timestamp('receipt_date');
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('net_sales', 18, 4)->default(0);
            $table->string('status', 20)->default('completed');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['pos_terminal_id', 'receipt_no']);
            $table->index('receipt_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipts');
    }
};
