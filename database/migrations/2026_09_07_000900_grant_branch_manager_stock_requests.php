<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ผู้จัดการสาขาต้องขอโอนและตรวจรับสินค้าได้ตาม flow งานสาขา
// แต่ยังไม่มี pos.sell เพื่อไม่ให้เปิดกะหรือคิดเงินแทนแคชเชียร์
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'BRANCH_MGR')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'stock.request')->value('id');

        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', 'BRANCH_MGR')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'stock.request')->value('id');

        if ($roleId && $permissionId) {
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }
    }
};
