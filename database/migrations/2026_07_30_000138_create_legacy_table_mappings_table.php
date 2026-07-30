<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_table_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_database', 100);
            $table->string('legacy_schema', 100)->default('dbo');
            $table->string('legacy_table', 150);
            $table->string('target_table', 150)->nullable();
            $table->string('module', 50)->default('review');
            $table->string('mapping_type', 30)->default('unmapped');
            $table->string('status', 30)->default('needs_review');
            $table->unsignedInteger('legacy_column_count')->default(0);
            $table->unsignedInteger('target_column_count')->default(0);
            $table->unsignedInteger('shared_column_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['legacy_database', 'legacy_schema', 'legacy_table'], 'legacy_table_mappings_source_unique');
            $table->index(['module', 'status']);
            $table->index('target_table');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_table_mappings');
    }
};
