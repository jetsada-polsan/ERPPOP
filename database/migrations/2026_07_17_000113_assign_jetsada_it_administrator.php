<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $hoId = DB::table('branches')->where('code', 'HO')->value('id');
        $itUnitId = DB::table('organizational_units')->where('code', 'IT')->value('id');
        $itManagerPositionId = DB::table('organization_positions')->where('code', 'IT-MGR')->value('id');

        DB::table('employees')->updateOrInsert(['employee_code'=>'EMP0107'], [
            'full_name'=>'Jetsada Polsan','nickname'=>'Jet','branch_id'=>$hoId,'branch_text'=>'สำนักงานใหญ่',
            'department'=>'ระบบ','position'=>'ผู้ดูแลระบบ (IT)','status'=>'Active',
            'source_section'=>'เพิ่มจากข้อมูลผู้ใช้งานจริง','created_at'=>$now,'updated_at'=>$now,
        ]);
        $employeeId = DB::table('employees')->where('employee_code', 'EMP0107')->value('id');

        if ($employeeId && $itUnitId) {
            DB::table('employee_org_assignments')->where('employee_id', $employeeId)->delete();
            DB::table('employee_org_assignments')->insert([
                'employee_id'=>$employeeId,'organizational_unit_id'=>$itUnitId,
                'position_title'=>'ผู้ดูแลระบบ (IT)','is_primary'=>true,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            DB::table('organization_positions')->updateOrInsert(['code'=>'IT-ADMIN'], [
                'organizational_unit_id'=>$itUnitId,'title'=>'ผู้ดูแลระบบ (IT)',
                'holder_employee_id'=>$employeeId,'reports_to_position_id'=>$itManagerPositionId,
                'sort_order'=>20,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
            ]);
            DB::table('organization_positions')->where('code', 'IT-LEAD')->update(['sort_order'=>30,'updated_at'=>$now]);
        }

        $adminId = DB::table('users')->where('username', 'admin')->value('id');
        $itRoleId = DB::table('roles')->where('code', 'IT_MGR')->value('id');
        DB::table('roles')->where('code', 'IT_MGR')->update(['name'=>'ผู้ดูแลระบบ (IT)','updated_at'=>$now]);
        if ($adminId) {
            DB::table('users')->where('id', $adminId)->update(['name'=>'Jetsada Polsan','position'=>'ผู้ดูแลระบบ (IT)','updated_at'=>$now]);
            if ($itRoleId) {
                DB::table('role_user')->where('user_id', $adminId)->delete();
                DB::table('role_user')->insert(['user_id'=>$adminId,'role_id'=>$itRoleId]);
            }
        }
    }

    public function down(): void {}
};
