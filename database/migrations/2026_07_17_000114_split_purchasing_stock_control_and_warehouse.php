<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $hoId = DB::table('branches')->where('code', 'HO')->value('id');
        $gmUnitId = DB::table('organizational_units')->where('code', 'GM-OFFICE')->value('id');
        $gmPositionId = DB::table('organization_positions')->where('code', 'GM')->value('id');
        $pondId = DB::table('employees')->where('employee_code', 'EMP0020')->value('id');
        $juId = DB::table('employees')->where('employee_code', 'EMP0021')->value('id');
        $bankId = DB::table('employees')->where('employee_code', 'EMP0058')->value('id');
        $warehouseId = DB::table('organizational_units')->where('code', 'WH')->value('id');

        DB::table('organizational_units')->updateOrInsert(['code'=>'PUR-STOCK'], [
            'name'=>'จัดซื้อและควบคุมสต็อก','unit_type'=>'department','parent_id'=>$gmUnitId,
            'branch_id'=>$hoId,'manager_employee_id'=>$pondId,
            'description'=>'จัดซื้อ ประสานรับสินค้า ตรวจเอกสาร และควบคุมยอดสต็อก',
            'sort_order'=>25,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $unitId = DB::table('organizational_units')->where('code', 'PUR-STOCK')->value('id');

        $people = [
            [$pondId, 'จัดซื้อและควบคุมสต็อก', 'ผู้จัดการฝ่ายจัดซื้อและควบคุมสต็อก'],
            [$juId, 'จัดซื้อและควบคุมสต็อก', 'เจ้าหน้าที่จัดซื้อและควบคุมสต็อก'],
        ];
        foreach ($people as [$employeeId, $department, $position]) {
            if (! $employeeId) continue;
            DB::table('employees')->where('id', $employeeId)->update(['department'=>$department,'position'=>$position,'branch_id'=>$hoId,'branch_text'=>'สำนักงานใหญ่','updated_at'=>$now]);
            DB::table('employee_org_assignments')->where('employee_id', $employeeId)->delete();
            DB::table('employee_org_assignments')->insert(['employee_id'=>$employeeId,'organizational_unit_id'=>$unitId,'position_title'=>$position,'is_primary'=>true,'created_at'=>$now,'updated_at'=>$now]);
        }

        DB::table('organization_positions')->updateOrInsert(['code'=>'PUR-STOCK-MGR'], [
            'organizational_unit_id'=>$unitId,'title'=>'ผู้จัดการฝ่ายจัดซื้อและควบคุมสต็อก','holder_employee_id'=>$pondId,
            'reports_to_position_id'=>$gmPositionId,'sort_order'=>10,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $managerPositionId = DB::table('organization_positions')->where('code', 'PUR-STOCK-MGR')->value('id');
        DB::table('organization_positions')->updateOrInsert(['code'=>'PUR-STOCK-OFFICER'], [
            'organizational_unit_id'=>$unitId,'title'=>'เจ้าหน้าที่จัดซื้อและควบคุมสต็อก','holder_employee_id'=>$juId,
            'reports_to_position_id'=>$managerPositionId,'sort_order'=>20,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);

        if ($bankId && $warehouseId) {
            DB::table('employees')->where('id', $bankId)->update(['position'=>'ผู้จัดการคลังสินค้า','updated_at'=>$now]);
            DB::table('employee_org_assignments')->where(['employee_id'=>$bankId,'organizational_unit_id'=>$warehouseId])->update(['position_title'=>'ผู้จัดการคลังสินค้า','is_primary'=>true,'updated_at'=>$now]);
            DB::table('organizational_units')->where('id', $warehouseId)->update(['manager_employee_id'=>$bankId,'updated_at'=>$now]);
            DB::table('organization_positions')->where('code', 'WH-MGR')->update(['holder_employee_id'=>$bankId,'updated_at'=>$now]);
        }
    }

    public function down(): void {}
};
