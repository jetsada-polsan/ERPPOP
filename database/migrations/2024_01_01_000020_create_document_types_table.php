<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: DOCTYPE (normalized: no salesperson/area/destination-branch codes)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_th', 150);
            $table->string('name_en', 150)->nullable();
            $table->boolean('affects_stock')->default(false);
            $table->boolean('affects_ar')->default(false);
            $table->boolean('affects_ap')->default(false);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
