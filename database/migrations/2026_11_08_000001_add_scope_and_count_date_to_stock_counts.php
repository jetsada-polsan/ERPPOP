<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->date('count_date')->nullable();
            $table->string('product_scope', 20)->default('active');
        });

        DB::table('stock_counts')->whereNull('count_date')->update([
            'count_date' => DB::raw('CAST(created_at AS DATE)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropColumn(['count_date', 'product_scope']);
        });
    }
};
