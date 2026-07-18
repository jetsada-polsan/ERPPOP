<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: PSL (L202103..L202511) -- heaviest table, ~12.9M rows; consider native partitioning by logged_at
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $table->string('station', 30)->nullable();
            $table->string('function_code', 30)->nullable();
            $table->text('log_data')->nullable();
            $table->timestamp('logged_at');
            $table->index(['pos_terminal_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_logs');
    }
};
