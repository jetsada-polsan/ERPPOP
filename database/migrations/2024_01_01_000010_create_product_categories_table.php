<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: ICCAT
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_th', 150);
            $table->string('name_en', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
