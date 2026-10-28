<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ยกเลิกเอกสารหลังบ้าน (ขาย ขายสด รับคืน ซื้อ ใบเสร็จ) กลับสต๊อก/GL/ลูกหนี้-เจ้าหนี้ — สิทธิ์ระดับผู้จัดการ
return new class extends Migration
{
    private const CODE = 'documents.void';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id')
            ?: DB::table('permissions')->insertGetId(['code' => self::CODE, 'name' => 'ยกเลิกเอกสารพร้อมกลับรายการ']);

        foreach (DB::table('roles')->whereIn('code', ['GM', 'ACC_MGR', 'BRANCH_MGR', 'IT_MGR'])->pluck('id') as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', self::CODE)->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
