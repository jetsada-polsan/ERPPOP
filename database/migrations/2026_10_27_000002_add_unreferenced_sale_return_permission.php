<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ใบรับคืนต้องอ้างใบขายต้นทางเสมอ ยกเว้นผู้ถือสิทธิ์นี้ (เช่น ของคืนจากระบบเก่าที่หาใบขายไม่ได้)
return new class extends Migration
{
    private const CODE = 'sales.return.unreferenced';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id')
            ?: DB::table('permissions')->insertGetId(['code' => self::CODE, 'name' => 'รับคืนสินค้าโดยไม่อ้างใบขายต้นทาง']);

        foreach (DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id') as $roleId) {
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
