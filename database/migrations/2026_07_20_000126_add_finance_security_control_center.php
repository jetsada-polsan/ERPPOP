<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedTinyInteger('match_confidence')->nullable();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('tax_filing_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('form_type', 20);
            $table->string('status', 20)->default('prepared');
            $table->decimal('taxable_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->unsignedInteger('document_count')->default(0);
            $table->string('file_name', 255);
            $table->string('file_hash', 64);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('submission_reference', 120)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['period', 'branch_id', 'form_type']);
        });

        Schema::create('etax_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->string('document_uuid', 36)->unique();
            $table->string('status', 20)->default('prepared');
            $table->string('payload_path', 255);
            $table->string('payload_hash', 64);
            $table->string('provider_reference', 120)->nullable();
            $table->text('provider_message')->nullable();
            $table->timestamp('prepared_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'prepared_at']);
        });

        Schema::create('operation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 40);
            $table->string('status', 20);
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['operation', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_runs');
        Schema::dropIfExists('etax_documents');
        Schema::dropIfExists('tax_filing_runs');

        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id', 'match_confidence']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled_at', 'password_changed_at']);
        });
    }
};
