<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_withholdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')->unique()->constrained('payment_documents')->restrictOnDelete();
            $table->string('certificate_no', 80)->unique();
            $table->date('paid_on');
            $table->string('form', 10);
            $table->string('income_type', 255);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('rate', 7, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->string('supplier_name', 250);
            $table->string('supplier_tax_id', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_withholdings');
    }
};
