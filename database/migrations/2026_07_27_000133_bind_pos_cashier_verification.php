<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->foreignId('active_cashier_id')->nullable()->after('branch_id')
                ->constrained('salesmen')->nullOnDelete();
            $table->timestamp('cashier_verified_at')->nullable()->after('active_cashier_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_cashier_id');
            $table->dropColumn('cashier_verified_at');
        });
    }
};
