<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ใบจองที่สต๊อกในระบบไม่พอแต่ของจริงมี: บันทึกได้ แต่ต้องให้ผู้จัดการยืนยันใน ERP ก่อนออกใบขาย
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->string('stock_shortage_status', 20)->nullable(); // pending | approved
            $table->text('stock_shortage_note')->nullable();
            $table->foreignId('stock_shortage_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stock_shortage_approved_at')->nullable();
        });

        $permissionId = DB::table('permissions')->where('code', 'sales.stock_shortage.approve')->value('id')
            ?: DB::table('permissions')->insertGetId(['code' => 'sales.stock_shortage.approve', 'name' => 'อนุมัติใบจองที่สต๊อกในระบบไม่พอ']);
        foreach (DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR', 'IT_MGR'])->pluck('id') as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'sales.stock_shortage.approve')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_shortage_approved_by');
            $table->dropColumn(['stock_shortage_status', 'stock_shortage_note', 'stock_shortage_approved_at']);
        });
    }
};
