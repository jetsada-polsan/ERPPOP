<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->string('shift_no', 40)->unique();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 18, 4)->default(0);
            $table->decimal('cash_sales', 18, 4)->default(0);
            $table->decimal('transfer_sales', 18, 4)->default(0);
            $table->decimal('card_sales', 18, 4)->default(0);
            $table->decimal('cheque_sales', 18, 4)->default(0);
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('cash_difference', 18, 4)->nullable();
            $table->integer('receipt_count')->default(0);
            $table->string('status', 20)->default('open');
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->foreignId('pos_shift_id')->nullable()->after('pos_terminal_id')->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('cashier_salesman_id')->nullable()->after('cashier_id')->constrained('salesmen')->nullOnDelete();
            $table->index('pos_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cashier_salesman_id');
            $table->dropConstrainedForeignId('pos_shift_id');
        });

        Schema::dropIfExists('pos_shifts');
    }
};
