<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_booking_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_booking_id')->constrained('sale_bookings')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('vat_group', 16);
            $table->unique(['sale_booking_id', 'document_id']);
            $table->index(['document_id', 'sale_booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_booking_documents');
    }
};
