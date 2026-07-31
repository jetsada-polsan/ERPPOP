<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('code', [
            'GM', 'BRANCH_MGR', 'ACC_MGR', 'ACC', 'IT_MGR', 'WAREHOUSE', 'PURCHASING', 'SALES', 'CASHIER', 'HR',
        ])->pluck('id', 'code');

        $hrId = $roleIds['HR'] ?? null;
        foreach (DB::table('users')->select('id', 'position')->orderBy('id')->get() as $user) {
            $position = (string) $user->position;
            $roles = match (true) {
                str_contains($position, 'ผู้ดูแลระบบ') || str_contains($position, '(IT)') => ['IT_MGR'],
                str_contains($position, 'ผู้จัดการทั่วไป') => ['GM', 'BRANCH_MGR'],
                str_contains($position, 'ผู้จัดการ-การเงิน') => ['ACC_MGR', 'ACC'],
                str_contains($position, 'บัญชี') => ['ACC'],
                str_contains($position, 'ผู้จัดการฝ่ายขาย') => ['BRANCH_MGR', 'SALES'],
                str_contains($position, 'แอดมินขาย') => ['SALES'],
                str_contains($position, 'จัดซื้อ') => ['PURCHASING'],
                str_contains($position, 'คลังสินค้า') || str_contains($position, 'พนักงานคลัง') || str_contains($position, 'คลัง') => ['WAREHOUSE'],
                str_contains($position, 'Area Manager') => ['BRANCH_MGR'],
                str_contains($position, 'แคชเชียร์') => ['CASHIER'],
                default => [],
            };

            foreach ($roles as $code) {
                $roleId = $roleIds[$code] ?? null;
                if ($roleId && ! DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => $roleId])->exists()) {
                    DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
                }
            }

            // HR was a temporary default on many imported accounts. Remove it only
            // when a job-specific role was assigned; all existing extra roles remain.
            if ($roles !== [] && $hrId) {
                DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => $hrId])->delete();
            }
        }
    }

    public function down(): void
    {
        // Historical role grants are intentionally retained for audit safety.
    }
};
