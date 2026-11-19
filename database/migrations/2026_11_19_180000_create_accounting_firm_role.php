<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('roles')->where('code', 'ACCOUNTING_FIRM')->value('id')
            ?? DB::table('roles')->insertGetId(['code' => 'ACCOUNTING_FIRM', 'name' => 'สำนักงานบัญชี (ดูอย่างเดียว)', 'created_at' => now(), 'updated_at' => now()]);
        $permissions = DB::table('permissions')->whereIn('code', ['reports.view', 'reports.export'])->pluck('id');
        DB::table('permission_role')->where('role_id', $id)->delete();
        foreach ($permissions as $permission) {
            DB::table('permission_role')->insert(['role_id' => $id, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('code', 'ACCOUNTING_FIRM')->delete();
    }
};
