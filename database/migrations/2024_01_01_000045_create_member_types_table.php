<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: MBTYPE
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->decimal('point_rate', 8, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_types');
    }
};
