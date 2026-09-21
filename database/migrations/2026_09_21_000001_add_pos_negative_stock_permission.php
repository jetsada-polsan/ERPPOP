<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately do not grant this to a role automatically.  It is a
        // separate exception permission and must be assigned by the owner to
        // the small group allowed to approve negative-stock sales.
        DB::table('permissions')->insertOrIgnore([
            'code' => 'pos.sell_negative_stock',
            'name' => 'อนุมัติขายติดสต๊อกลบ POS',
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('code', 'pos.sell_negative_stock')
            ->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
