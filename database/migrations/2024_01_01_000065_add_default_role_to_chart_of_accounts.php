<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marks which chart_of_accounts row is the default posting target for a role
// (cash/ar/ap) so payment GL postings know which account to debit/credit
// without needing a full account-mapping settings screen. At most one account
// should hold a given role at a time (enforced in the controller, not the DB).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('default_role', 20)->nullable()->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('default_role');
        });
    }
};
