<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_payments', 'transfer_account_last4')) {
            Schema::table('pos_payments', function (Blueprint $table) {
                $table->char('transfer_account_last4', 4)->nullable()->after('payment_reference');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_payments', 'transfer_account_last4')) {
            Schema::table('pos_payments', function (Blueprint $table) {
                $table->dropColumn('transfer_account_last4');
            });
        }
    }
};
