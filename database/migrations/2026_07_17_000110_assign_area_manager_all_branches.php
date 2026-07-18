<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $unitId = DB::table('organizational_units')->where('code', 'SHOP-OPS')->value('id');
        $managerPositionId = DB::table('organization_positions')->where('code', 'OPS-MGR')->value('id');
        $daoId = DB::table('employees')->where('employee_code', 'EMP0089')->value('id');
        if (! $unitId || ! $daoId) return;

        DB::table('employees')->where('id', $daoId)->update([
            'department' => 'ปฏิบัติการหน้าร้าน',
            'position' => 'Area Manager (ทุกสาขา)',
            'updated_at' => now(),
        ]);
        DB::table('employee_org_assignments')->where('employee_id', $daoId)->delete();
        DB::table('employee_org_assignments')->insert([
            'employee_id'=>$daoId,'organizational_unit_id'=>$unitId,
            'position_title'=>'Area Manager (ทุกสาขา)','is_primary'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('organization_positions')->updateOrInsert(
            ['code'=>'OPS-AREA'],
            ['organizational_unit_id'=>$unitId,'title'=>'Area Manager (ทุกสาขา)',
             'holder_employee_id'=>$daoId,'reports_to_position_id'=>$managerPositionId,
             'sort_order'=>20,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]
        );
        DB::table('organization_positions')->where('code', 'OPS-LEAD')->update(['sort_order'=>30,'updated_at'=>now()]);
    }

    public function down(): void
    {
        DB::table('organization_positions')->where('code', 'OPS-AREA')->delete();
        DB::table('organization_positions')->where('code', 'OPS-LEAD')->update(['sort_order'=>20,'updated_at'=>now()]);
    }
};
