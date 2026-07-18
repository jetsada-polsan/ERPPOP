<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * สิทธิ์ "ขอโอนสินค้า" (stock.request): พนักงานสาขา (แคชเชียร์/ฝ่ายขาย) สร้าง
 * ใบขอโอนแบบ pending ได้ แต่ไม่ตัดสต๊อก - คนถือ stock.manage เป็นผู้อนุมัติ=โอนจริง.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('code', 'stock.request')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'code' => 'stock.request',
                'name' => 'ขอโอนสินค้า',
            ]);
        }

        $roleIds = DB::table('roles')->whereIn('code', ['CASHIER', 'SALES'])->pluck('id');
        foreach ($roleIds as $roleId) {
            $exists = DB::table('permission_role')
                ->where('permission_id', $permId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('code', 'stock.request')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
