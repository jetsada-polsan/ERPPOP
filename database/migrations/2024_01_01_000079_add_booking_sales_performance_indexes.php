<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->index(['status', 'document_id'], 'sale_bookings_status_document_idx');
            $table->index(['status', 'salesman_id'], 'sale_bookings_status_salesman_idx');
            $table->unique('confirmed_document_id', 'sale_bookings_confirmed_document_unique');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index(['branch_id', 'document_type_id', 'doc_date'], 'documents_branch_type_date_idx');
            $table->index(['reference'], 'documents_reference_idx');
        });

        Schema::table('stock_documents', function (Blueprint $table) {
            $table->index(['refer_reference'], 'stock_documents_refer_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropIndex('stock_documents_refer_reference_idx');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_reference_idx');
            $table->dropIndex('documents_branch_type_date_idx');
        });

        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropUnique('sale_bookings_confirmed_document_unique');
            $table->dropIndex('sale_bookings_status_salesman_idx');
            $table->dropIndex('sale_bookings_status_document_idx');
        });
    }
};
