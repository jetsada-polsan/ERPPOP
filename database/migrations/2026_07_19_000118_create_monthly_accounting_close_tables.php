<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->updateOrInsert(['code' => 'EXPENSE'], [
            'name_th' => 'ใบค่าใช้จ่าย', 'name_en' => 'Expense Voucher',
            'affects_stock' => false, 'affects_ar' => false, 'affects_ap' => false, 'is_active' => true,
        ]);

        DB::table('chart_of_accounts')->updateOrInsert(['code' => '1020'], [
            'name_th' => 'เงินฝากธนาคาร', 'name_en' => 'Bank Deposits', 'account_type' => 'asset', 'default_role' => 'bank',
        ]);
        DB::table('chart_of_accounts')->updateOrInsert(['code' => '2160'], [
            'name_th' => 'ภาษีหัก ณ ที่จ่ายค้างจ่าย', 'name_en' => 'Withholding Tax Payable', 'account_type' => 'liability', 'default_role' => 'wht_payable',
        ]);

        Schema::create('branch_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('expense_date');
            $table->string('supplier_name', 200);
            $table->string('supplier_tax_id', 20)->nullable();
            $table->string('tax_branch', 20)->nullable();
            $table->string('tax_invoice_no', 80)->nullable();
            $table->date('tax_invoice_date')->nullable();
            $table->text('description');
            $table->decimal('base_amount', 18, 4);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('withholding_rate', 8, 4)->default(0);
            $table->decimal('withholding_amount', 18, 4)->default(0);
            $table->string('withholding_form', 10)->nullable();
            $table->decimal('total_amount', 18, 4);
            $table->string('payment_method', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('payment_reference', 100)->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'expense_date']);
            $table->index(['withholding_form', 'expense_date']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->unique()->constrained('bank_statements')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('match_type', 30)->default('other');
            $table->string('reference', 120)->nullable();
            $table->decimal('expected_amount', 18, 4);
            $table->decimal('difference_amount', 18, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('slip_path', 500)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'checked_at']);
        });

        Schema::create('accounting_export_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->jsonb('summary')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at');
            $table->index(['period', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_runs');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('branch_expenses');
    }
};
