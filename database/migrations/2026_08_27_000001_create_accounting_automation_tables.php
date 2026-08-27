<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_accounting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('rule_type', 30);
            $table->string('name', 160);
            $table->string('party_name', 200)->nullable();
            $table->decimal('base_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->string('frequency', 20)->default('monthly');
            $table->date('next_run_date');
            $table->date('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'next_run_date']);
        });

        Schema::create('accounting_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('status', 30)->default('queued');
            $table->string('original_name', 255);
            $table->string('file_path', 500);
            $table->string('file_hash', 64);
            $table->decimal('suggested_amount', 18, 4)->nullable();
            $table->date('suggested_date')->nullable();
            $table->string('suggested_party', 200)->nullable();
            $table->json('extracted_json')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->unique('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_import_batches');
        Schema::dropIfExists('recurring_accounting_rules');
    }
};
