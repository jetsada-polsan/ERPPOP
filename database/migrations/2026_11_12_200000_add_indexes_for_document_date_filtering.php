<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index('doc_date');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->index('doc_date');
        });

        Schema::table('billing_notes', function (Blueprint $table) {
            $table->index('doc_date');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('doc_date');
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('gl_journals', function (Blueprint $table) {
            $table->index('entry_date');
        });

        Schema::table('pos_offline_sales', function (Blueprint $table) {
            $table->index('sold_at');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->index('doc_date');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex(['doc_date']);
        });

        Schema::table('pos_offline_sales', function (Blueprint $table) {
            $table->dropIndex(['sold_at']);
        });

        Schema::table('gl_journals', function (Blueprint $table) {
            $table->dropIndex(['entry_date']);
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['doc_date']);
        });

        Schema::table('billing_notes', function (Blueprint $table) {
            $table->dropIndex(['doc_date']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['doc_date']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['doc_date']);
        });
    }
};
