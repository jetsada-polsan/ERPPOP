<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// pos.sell (เปิดกะ/คิดเงิน) ต้องเหลือ "แคชเชียร์เท่านั้น" - GM/ผู้บริหาร/IT ดูหน้า
// POS ได้ (pos.use) แต่ขายไม่ได้ ตามข้อกำหนดควบคุมภายใน (คนขาย=คนที่รับผิดชอบบิล)
return new class extends Migration
{
    public function up(): void
    {
        $sellId = DB::table('permissions')->where('code', 'pos.sell')->value('id');
        if (! $sellId) {
            return;
        }

        $cashierId = DB::table('roles')->where('code', 'CASHIER')->value('id');

        // ลบ pos.sell ออกจากทุก role ที่ไม่ใช่ CASHIER
        DB::table('permission_role')->where('permission_id', $sellId)
            ->where('role_id', '!=', $cashierId)->delete();
    }

    public function down(): void
    {
        // ไม่คืนอัตโนมัติ - ปรับผ่านหน้าจัดการสิทธิ์ถ้าต้องการ
    }
};
