<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: POSCONFIG1..6
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
    }
};
