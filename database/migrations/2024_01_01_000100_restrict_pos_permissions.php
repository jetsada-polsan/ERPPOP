<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// จำกัดสิทธิ์ POS: แยก "ขายหน้าร้าน" (pos.sell - เปิดกะ/คิดเงิน) ออกจาก "เห็นหน้า POS"
// (pos.use) - แคชเชียร์เท่านั้นที่ขายได้ ส่วน ผจก.สาขา + ผู้บริหาร (GM) ดูหน้า POS ได้
// อย่างเดียว role อื่น (เช่น SALES) ไม่เห็น POS เลย
return new class extends Migration
{
    public function up(): void
    {
        // เพิ่ม permission pos.sell (ถ้ายังไม่มี)
        $sellId = DB::table('permissions')->where('code', 'pos.sell')->value('id');
        if (! $sellId) {
            $sellId = DB::table('permissions')->insertGetId([
                'code' => 'pos.sell', 'name' => 'ขายหน้าร้าน POS (เปิดกะ/คิดเงิน)',
            ]);
        }

        $useId = DB::table('permissions')->where('code', 'pos.use')->value('id');
        $roleIds = DB::table('roles')->whereIn('code', ['CASHIER', 'BRANCH_MGR', 'GM', 'SALES'])
            ->pluck('id', 'code');

        // แคชเชียร์เท่านั้นที่ขายได้
        if (isset($roleIds['CASHIER'])) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $sellId, 'role_id' => $roleIds['CASHIER'],
            ]);
        }

        // pos.use (เห็นหน้า POS) เหลือเฉพาะ CASHIER / BRANCH_MGR / GM
        if ($useId) {
            $allowed = collect(['CASHIER', 'BRANCH_MGR', 'GM'])
                ->map(fn ($code) => $roleIds[$code] ?? null)->filter()->all();
            DB::table('permission_role')->where('permission_id', $useId)
                ->whereNotIn('role_id', $allowed)->delete();
        }
    }

    public function down(): void
    {
        $sellId = DB::table('permissions')->where('code', 'pos.sell')->value('id');
        if ($sellId) {
            DB::table('permission_role')->where('permission_id', $sellId)->delete();
            DB::table('permissions')->where('id', $sellId)->delete();
        }
    }
};
