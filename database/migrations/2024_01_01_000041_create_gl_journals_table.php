<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: TRANPAYJ
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')->nullable()->constrained('payment_documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->text('remark')->nullable();
            $table->date('entry_date');
            $table->index(['account_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_journals');
    }
};
