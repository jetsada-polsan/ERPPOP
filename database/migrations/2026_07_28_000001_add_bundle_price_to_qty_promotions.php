<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table): void {
            $table->decimal('bundle_price', 18, 4)->nullable()->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table): void {
            $table->dropColumn('bundle_price');
        });
    }
};
