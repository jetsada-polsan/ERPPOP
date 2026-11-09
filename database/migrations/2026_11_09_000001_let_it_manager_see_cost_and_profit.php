<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// เจ้าของสั่ง 9 พ.ย. 2026: IT เห็นต้นทุน/กำไรได้ (management.view ไม่ถูก bypass จึงต้องให้ role ถือจริง)
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'management.view')->value('id')
            ?: DB::table('permissions')->insertGetId(['code' => 'management.view', 'name' => 'ดูต้นทุน กำไร และการควบคุมผู้บริหาร']);

        foreach (DB::table('roles')->where('code', 'IT_MGR')->pluck('id') as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'management.view')->value('id');
        $roleIds = DB::table('roles')->where('code', 'IT_MGR')->pluck('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->whereIn('role_id', $roleIds)->delete();
    }
};
