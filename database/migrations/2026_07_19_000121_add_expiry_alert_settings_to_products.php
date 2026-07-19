<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('expiry_warning_days')->default(30)->after('tracks_expiry');
            $table->string('expiry_sale_policy', 20)->default('block')->after('expiry_warning_days');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->index(['expiry_date', 'remaining_qty'], 'stock_lots_expiry_alert_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('stock_lots_expiry_alert_idx');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['expiry_warning_days', 'expiry_sale_policy']);
        });
    }
};
