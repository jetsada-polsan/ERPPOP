<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_documents', 'rounding_amount')) {
            Schema::table('payment_documents', function (Blueprint $table) {
                $table->decimal('rounding_amount', 8, 2)->default(0)->after('wht_cert_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_documents', 'rounding_amount')) {
            Schema::table('payment_documents', function (Blueprint $table) {
                $table->dropColumn('rounding_amount');
            });
        }
    }
};
