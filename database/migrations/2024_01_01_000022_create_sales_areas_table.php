<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: new: normalized out of legacy booking document-type codes (B14, B26, ...)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_areas');
    }
};
