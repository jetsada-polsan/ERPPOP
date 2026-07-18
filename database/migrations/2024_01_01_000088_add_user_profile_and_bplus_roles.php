<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// User management ตามแบบ BPlus: เพิ่มข้อมูลพนักงานบน users, seed บทบาท 12 แบบ
// ตามต้นไม้สิทธิ์ของระบบเดิม (ผู้จัดการทั่วไป...ฝ่ายขาย) พร้อมสิทธิ์รายโมดูล
// และ document type ใบรับสินค้าจากการผลิต (IP).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('position', 100)->nullable()->after('phone');
        });

        $permissions = [
            'pos.use' => 'ใช้งาน POS ขายหน้าร้าน',
            'sales.manage' => 'งานขาย / ใบจอง / รับคืน',
            'stock.manage' => 'คลังสินค้า / เบิก / ตรวจนับ',
            'purchasing.manage' => 'จัดซื้อ / แผนจัดซื้อ',
            'finance.manage' => 'การเงิน / บัญชี / รับชำระ',
            'masterdata.manage' => 'ข้อมูลตั้งต้น / ราคา / โปรโมชั่น',
            'reports.view' => 'ดูรายงาน',
            'users.manage' => 'จัดการผู้ใช้และสิทธิ์',
            'settings.manage' => 'ตั้งค่าระบบ',
        ];
        foreach ($permissions as $code => $name) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['code' => $code, 'name' => $name]);
            }
        }

        $roles = [
            'GM' => ['ผู้จัดการทั่วไป', array_keys($permissions)],
            'BRANCH_MGR' => ['ผู้จัดการสาขา', ['pos.use', 'sales.manage', 'stock.manage', 'purchasing.manage', 'reports.view']],
            'ACC_MGR' => ['ผู้จัดการฝ่ายบัญชี', ['finance.manage', 'reports.view', 'masterdata.manage']],
            'ACC' => ['พนักงานบัญชี', ['finance.manage', 'reports.view']],
            'IT_MGR' => ['ผู้จัดการฝ่ายคอมพิวเตอร์', ['users.manage', 'settings.manage', 'masterdata.manage', 'reports.view']],
            'HR_MGR' => ['ผู้จัดการฝ่ายบุคคล', ['users.manage', 'reports.view']],
            'HR' => ['พนักงานฝ่ายบุคคล', ['reports.view']],
            'CASHIER' => ['Cashier', ['pos.use']],
            'WAREHOUSE' => ['คลังสินค้า', ['stock.manage', 'reports.view']],
            'PURCHASING' => ['ฝ่ายจัดซื้อ', ['purchasing.manage', 'masterdata.manage', 'reports.view']],
            'MARKETING' => ['การตลาด', ['masterdata.manage', 'reports.view']],
            'SALES' => ['ฝ่ายขาย', ['pos.use', 'sales.manage', 'reports.view']],
        ];
        foreach ($roles as $code => [$name, $permCodes]) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($permCodes as $permCode) {
                $permId = DB::table('permissions')->where('code', $permCode)->value('id');
                if ($permId && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permId])->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
                }
            }
        }

        if (! DB::table('document_types')->where('code', 'PRODUCTION_RECEIPT')->exists()) {
            DB::table('document_types')->insert(['code' => 'PRODUCTION_RECEIPT', 'name_th' => 'ใบรับสินค้าจากการผลิต']);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'position']);
        });
        DB::table('document_types')->where('code', 'PRODUCTION_RECEIPT')->delete();
    }
};
