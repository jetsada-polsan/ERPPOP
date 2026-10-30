<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'pos.use' => 'ใช้งาน POS ขายหน้าร้าน',
            'pos.sell' => 'ขายหน้าร้าน POS (เปิดกะ/คิดเงิน)',
            'pos.void' => 'ยกเลิก / void บิล POS',
            'pos.cash.manage' => 'อนุมัติเงินเข้าออกลิ้นชัก POS',
        ];

        $permissionIds = [];
        foreach ($permissions as $code => $name) {
            $permissionIds[] = DB::table('permissions')->where('code', $code)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('code', 'name'));
        }

        $itRoleId = DB::table('roles')->where('code', 'IT_MGR')->value('id');
        if (! $itRoleId) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert([
                'role_id' => $itRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve role permissions during rollback; access may also be managed manually.
    }
};
