<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'pos.discount.override' => 'อนุมัติส่วนลดพิเศษ POS',
            'pos.sell_below_cost' => 'อนุมัติขายต่ำกว่าทุน POS',
            'purchasing.approve' => 'อนุมัติใบขอซื้อ',
        ];
        foreach ($permissions as $code => $name) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('code', 'name'));
            $roleIds = DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['pos.discount.override', 'pos.sell_below_cost', 'purchasing.approve'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
