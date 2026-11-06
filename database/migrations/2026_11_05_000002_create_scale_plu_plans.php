<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scale_plu_plan_guard', function (Blueprint $table) {
            $table->id();
        });
        DB::table('scale_plu_plan_guard')->insert(['id' => 1]);
        Schema::create('scale_plu_plans', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('preview');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('rows');
            $table->string('fingerprint', 64);
            $table->json('changes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scale_plu_plans');
        Schema::dropIfExists('scale_plu_plan_guard');
    }
};
