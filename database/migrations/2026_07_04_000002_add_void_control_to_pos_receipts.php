<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_receipts', 'document_id')) {
                $table->foreignId('document_id')->nullable()->after('pos_shift_id')
                    ->constrained('documents')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_receipts', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('pos_receipts', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->after('voided_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_receipts', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('voided_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_receipts', 'voided_by')) {
                $table->dropConstrainedForeignId('voided_by');
            }
            if (Schema::hasColumn('pos_receipts', 'document_id')) {
                $table->dropConstrainedForeignId('document_id');
            }
            foreach (['voided_at', 'void_reason'] as $column) {
                if (Schema::hasColumn('pos_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
