<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('vat_mode', 12);
            $table->boolean('prices_include_vat');
            $table->string('payment_terms', 60)->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('documents', fn (Blueprint $table) => $table->json('trade_tax')->nullable());
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('trade_tax'));
        Schema::dropIfExists('trade_agreements');
    }
};
