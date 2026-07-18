<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: new: ETL key-mapping & reconciliation
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_database', 100);
            $table->string('legacy_table', 100);
            $table->string('legacy_key', 100);
            $table->string('new_table', 100);
            $table->unsignedBigInteger('new_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['legacy_database', 'legacy_table', 'legacy_key', 'new_table'], 'legacy_mappings_unique');
            $table->index(['new_table', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_mappings');
    }
};
