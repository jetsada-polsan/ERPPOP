<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->unsignedInteger('max_sets_per_bill')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->dropColumn('max_sets_per_bill');
        });
    }
};
