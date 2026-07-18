<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// new: pos_import - raw uploaded files, always retained
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('raw_path', 500);
            $table->timestamp('uploaded_at')->useCurrent();
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_files');
    }
};
