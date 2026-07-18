<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy source: BANKSTATEMENT
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4);
            $table->decimal('balance', 18, 4)->nullable();
            $table->boolean('reconciled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
