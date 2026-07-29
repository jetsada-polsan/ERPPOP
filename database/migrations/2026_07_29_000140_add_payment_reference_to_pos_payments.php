<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->string('payment_reference', 80)->nullable()->after('method');
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropIndex(['payment_reference']);
            $table->dropColumn('payment_reference');
        });
    }
};
