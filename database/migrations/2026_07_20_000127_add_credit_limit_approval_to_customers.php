<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('pending_credit_limit', 18, 2)->nullable()->after('credit_limit');
            $table->foreignId('credit_limit_requested_by')->nullable()->after('pending_credit_limit')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('credit_limit_requested_at')->nullable()->after('credit_limit_requested_by');
        });

        foreach ([
            'finance.credit.approve' => ['อนุมัติเปลี่ยนวงเงินเครดิตลูกค้า', ['GM', 'ACC_MGR']],
        ] as $code => [$name, $roleCodes]) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('code', 'name'));
            foreach (DB::table('roles')->whereIn('code', $roleCodes)->pluck('id') as $roleId) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_limit_requested_by');
            $table->dropColumn(['pending_credit_limit', 'credit_limit_requested_at']);
        });

        $id = DB::table('permissions')->where('code', 'finance.credit.approve')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
