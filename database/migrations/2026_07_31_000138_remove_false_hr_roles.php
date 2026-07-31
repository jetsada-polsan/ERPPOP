<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR was used as the historical fallback when employee accounts had no
 * department mapping. It must not represent delivery, maintenance, or owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $reportPermissionId = DB::table('permissions')->where('code', 'reports.view')->value('id');

        $roles = [
            'EXECUTIVE' => ['ผู้บริหาร / กรรมการ', [$reportPermissionId]],
            'DELIVERY' => ['พนักงานจัดส่ง', []],
        ];

        $roleIds = [];
        foreach ($roles as $code => [$name, $permissionIds]) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            foreach (array_filter($permissionIds) as $permissionId) {
                if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            $roleIds[$code] = $roleId;
        }

        $hrId = DB::table('roles')->where('code', 'HR')->value('id');
        foreach (DB::table('users')->select('id', 'position')->cursor() as $user) {
            $position = (string) $user->position;
            $roleCode = match (true) {
                str_contains($position, 'กรรมการ') || str_contains($position, 'ผู้บริหาร') => 'EXECUTIVE',
                str_contains($position, 'จัดส่ง') => 'DELIVERY',
                default => null,
            };

            if ($roleCode && ! DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => $roleIds[$roleCode]])->exists()) {
                DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $roleIds[$roleCode]]);
            }

            if ($roleCode && $hrId) {
                DB::table('role_user')->where(['user_id' => $user->id, 'role_id' => $hrId])->delete();
            }
        }

        // No active account is currently an HR employee. Do not leave HR access as a fallback.
        if ($hrId) {
            DB::table('role_user')->where('role_id', $hrId)->delete();
        }
    }

    public function down(): void
    {
        // Role assignments are business access decisions and are not auto-reverted.
    }
};
