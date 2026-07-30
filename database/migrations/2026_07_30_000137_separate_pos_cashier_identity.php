<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index('user_id', 'salesmen_user_id_idx');
        });

        DB::table('users')->whereNotNull('salesman_id')->get(['id', 'salesman_id'])->each(function ($user): void {
            DB::table('salesmen')->where('id', $user->salesman_id)->whereNull('user_id')->update(['user_id' => $user->id]);
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->foreignId('cashier_user_id')->nullable()->after('cashier_id')->constrained('users')->nullOnDelete();
            $table->index(['cashier_user_id', 'status'], 'pos_shifts_cashier_user_status_idx');
        });
        Schema::table('pos_held_bills', function (Blueprint $table) {
            $table->foreignId('cashier_user_id')->nullable()->after('cashier_id')->constrained('users')->nullOnDelete();
            $table->index('cashier_user_id', 'pos_held_bills_cashier_user_idx');
        });
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->foreignId('active_cashier_user_id')->nullable()->after('active_cashier_id')->constrained('users')->nullOnDelete();
            $table->index('active_cashier_user_id', 'pos_devices_cashier_user_idx');
        });

        $this->backfillCashierUsers('pos_shifts', 'cashier_id', 'cashier_user_id');
        $this->backfillCashierUsers('pos_held_bills', 'cashier_id', 'cashier_user_id');
        $this->backfillCashierUsers('pos_devices', 'active_cashier_id', 'active_cashier_user_id');
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropIndex('pos_devices_cashier_user_idx');
            $table->dropConstrainedForeignId('active_cashier_user_id');
        });
        Schema::table('pos_held_bills', function (Blueprint $table) {
            $table->dropIndex('pos_held_bills_cashier_user_idx');
            $table->dropConstrainedForeignId('cashier_user_id');
        });
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropIndex('pos_shifts_cashier_user_status_idx');
            $table->dropConstrainedForeignId('cashier_user_id');
        });
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropIndex('salesmen_user_id_idx');
            $table->dropConstrainedForeignId('user_id');
        });
    }

    private function backfillCashierUsers(string $table, string $legacyColumn, string $userColumn): void
    {
        DB::table($table)->whereNull($userColumn)->whereNotNull($legacyColumn)
            ->get(['id', $legacyColumn])->each(function ($row) use ($table, $legacyColumn, $userColumn): void {
                $userId = DB::table('salesmen')->where('id', $row->{$legacyColumn})->value('user_id');
                if ($userId) {
                    DB::table($table)->where('id', $row->id)->update([$userColumn => $userId]);
                }
            });
    }
};
