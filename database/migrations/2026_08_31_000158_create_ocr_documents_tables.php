<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('document_type', 60);
            $table->string('source_module', 40)->default('purchase');
            $table->string('original_file_path', 500);
            $table->string('original_file_name', 255);
            $table->string('file_mime_type', 120);
            $table->string('original_file_sha256', 64)->nullable()->index();
            $table->string('status', 30)->default('uploaded');
            $table->string('ocr_engine', 80)->nullable();
            $table->longText('raw_text')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_tax_id', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->decimal('total_amount', 20, 8)->nullable();
            $table->decimal('vat_amount', 20, 8)->nullable();
            $table->decimal('net_amount', 20, 8)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'reference_no']);
        });

        Schema::create('ocr_extracted_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_document_id')->constrained('ocr_documents')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->text('raw_text')->nullable();
            $table->string('extracted_product_code', 100)->nullable();
            $table->string('extracted_barcode', 80)->nullable();
            $table->string('extracted_product_name', 250)->nullable();
            $table->decimal('extracted_qty', 20, 8)->nullable();
            $table->string('extracted_unit', 100)->nullable();
            $table->decimal('extracted_unit_price', 20, 8)->nullable();
            $table->decimal('extracted_discount', 20, 8)->nullable();
            $table->decimal('extracted_line_total', 20, 8)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->foreignId('matched_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('matched_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->string('match_status', 30)->default('unmatched');
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['ocr_document_id', 'line_no']);
            $table->index(['matched_product_id', 'match_status']);
        });

        Schema::create('ocr_match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_document_id')->constrained('ocr_documents')->cascadeOnDelete();
            $table->foreignId('ocr_extracted_line_id')->nullable()->constrained('ocr_extracted_lines')->cascadeOnDelete();
            $table->string('match_type', 30);
            $table->string('candidate_id', 80)->nullable();
            $table->string('candidate_name', 250)->nullable();
            $table->decimal('score', 5, 4)->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamps();
            $table->index(['ocr_document_id', 'match_type']);
        });

        Schema::create('ocr_review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_document_id')->constrained('ocr_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ocr_document_id', 'created_at']);
        });

        Schema::create('ocr_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_document_id')->constrained('ocr_documents')->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedInteger('page_no')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ocr_document_id', 'page_no']);
        });

        Schema::create('supplier_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('supplier_product_code', 100)->nullable();
            $table->string('supplier_product_name', 250)->nullable();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->decimal('conversion_rate', 20, 8)->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'supplier_product_code']);
            $table->index(['supplier_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_mappings');
        Schema::dropIfExists('ocr_attachments');
        Schema::dropIfExists('ocr_review_logs');
        Schema::dropIfExists('ocr_match_results');
        Schema::dropIfExists('ocr_extracted_lines');
        Schema::dropIfExists('ocr_documents');
    }
};
