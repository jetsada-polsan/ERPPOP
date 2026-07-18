<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock transfer documents (STOCK_TRANSFER) move qty between two warehouse
// locations. stock_document_items.warehouse_location_id already holds the
// source location; this column holds the destination for the whole document.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignId('to_warehouse_location_id')->nullable()->after('document_id')
                ->constrained('warehouse_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_warehouse_location_id');
        });
    }
};
