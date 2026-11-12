<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->decimal('unit_qty', 18, 4)->nullable()->after('unit_id');
            $table->decimal('unit_factor', 20, 8)->nullable()->after('unit_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->dropColumn(['unit_qty', 'unit_factor']);
        });
    }
};
