<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the payroll and budget modules with an approve/pay workflow:
 * a run/budget is created as draft (HR/ACC), edited (WHT etc.), then locked by
 * a separate approver (GM). payroll runs also get a paid step. Permissions are
 * distinct from the *.manage ones so the maker/checker split can be enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('approved_at');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
        });

        foreach ([
            'payroll.approve' => ['อนุมัติและจ่ายเงินเดือน', ['GM']],
            'budget.approve' => ['อนุมัติงบประมาณ', ['GM']],
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
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
        $ids = DB::table('permissions')->whereIn('code', ['payroll.approve', 'budget.approve'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
