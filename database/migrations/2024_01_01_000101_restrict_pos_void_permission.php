<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// สิทธิ์อนุมัติยกเลิกบิล POS (pos.void): เฉพาะ ผจก.สาขา + ผู้บริหาร (GM)
// แคชเชียร์ยกเลิกเองไม่ได้ (กันทุจริต - คนขายกับคนอนุมัติยกเลิกต้องแยกกัน)
// และ IT ไม่ควรมีสิทธิ์ธุรกรรมการเงิน
return new class extends Migration
{
    public function up(): void
    {
        $voidId = DB::table('permissions')->where('code', 'pos.void')->value('id');
        if (! $voidId) {
            return;
        }

        $allowed = DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id')->all();

        DB::table('permission_role')->where('permission_id', $voidId)
            ->whereNotIn('role_id', $allowed)->delete();

        foreach ($allowed as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $voidId, 'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        // ไม่คืนสิทธิ์ IT_MGR อัตโนมัติ - ปรับผ่านหน้าจัดการผู้ใช้ถ้าต้องการ
    }
};
