<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_purchase_cost', 18, 4)->default(0)->after('average_cost');
            $table->timestamp('last_purchase_cost_at')->nullable()->after('last_purchase_cost');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 18, 4)->nullable()->after('total_amount');
            $table->decimal('vat_amount', 18, 4)->default(0)->after('subtotal_amount');
            $table->boolean('prices_include_vat')->default(true)->after('vat_amount');
            $table->boolean('claim_input_vat')->default(false)->after('prices_include_vat');
        });

        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 4)->nullable()->after('unit_price');
            $table->decimal('cost_amount', 18, 4)->nullable()->after('unit_cost');
            $table->decimal('vat_amount', 18, 4)->default(0)->after('cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_items', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'cost_amount', 'vat_amount']));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['subtotal_amount', 'vat_amount', 'prices_include_vat', 'claim_input_vat']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['last_purchase_cost', 'last_purchase_cost_at']));
    }
};
