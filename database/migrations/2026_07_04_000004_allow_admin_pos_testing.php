<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('code', ['IT_MGR', 'GM'])
            ->pluck('id');

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['pos.use', 'pos.sell', 'pos.void'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('code', ['IT_MGR'])
            ->pluck('id');

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['pos.use', 'pos.sell', 'pos.void'])
            ->pluck('id');

        if ($roleIds->isNotEmpty() && $permissionIds->isNotEmpty()) {
            DB::table('permission_role')
                ->whereIn('role_id', $roleIds)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }
};
