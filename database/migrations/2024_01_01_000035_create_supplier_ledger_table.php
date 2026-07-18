<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: APDETAIL
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('entry_type', 10);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->date('entry_date');
            $table->index(['supplier_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger');
    }
};
