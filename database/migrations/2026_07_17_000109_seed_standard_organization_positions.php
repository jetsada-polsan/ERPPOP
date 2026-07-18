<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $gmPositionId = DB::table('organization_positions')->where('code', 'GM')->value('id');

        $structure = [
            'SHOP-OPS' => ['OPS-MGR', 'ผู้จัดการฝ่ายปฏิบัติการหน้าร้าน', 'OPS-LEAD', 'หัวหน้าฝ่ายปฏิบัติการหน้าร้าน'],
            'ACC' => ['ACC-MGR', 'ผู้จัดการฝ่ายบัญชี', 'ACC-LEAD', 'หัวหน้าฝ่ายบัญชี'],
            'WH' => ['WH-MGR', 'ผู้จัดการฝ่ายคลังสินค้า', 'WH-LEAD', 'หัวหน้าฝ่ายคลังสินค้า'],
            'DELIVERY' => ['DELIVERY-MGR', 'ผู้จัดการฝ่ายจัดส่ง', 'DELIVERY-LEAD', 'หัวหน้าฝ่ายจัดส่ง'],
            'SALES' => ['SALES-MGR', 'ผู้จัดการฝ่ายขาย', 'SALES-LEAD', 'หัวหน้าฝ่ายขาย'],
            'IT' => ['IT-MGR', 'ผู้จัดการฝ่ายระบบ', 'IT-LEAD', 'หัวหน้าฝ่ายระบบ'],
            'MAINT' => ['MAINT-MGR', 'ผู้จัดการฝ่ายซ่อมบำรุง', 'MAINT-LEAD', 'หัวหน้าฝ่ายซ่อมบำรุง'],
        ];

        foreach ($structure as $unitCode => [$managerCode, $managerTitle, $leadCode, $leadTitle]) {
            $unit = DB::table('organizational_units')->where('code', $unitCode)->first(['id', 'manager_employee_id']);
            if (! $unit) continue;

            DB::table('organization_positions')->updateOrInsert(
                ['code' => $managerCode],
                ['organizational_unit_id'=>$unit->id,'title'=>$managerTitle,'holder_employee_id'=>$unit->manager_employee_id,'reports_to_position_id'=>$gmPositionId,'sort_order'=>10,'is_active'=>true,'updated_at'=>$now,'created_at'=>$now]
            );
            $managerPositionId = DB::table('organization_positions')->where('code', $managerCode)->value('id');
            DB::table('organization_positions')->updateOrInsert(
                ['code' => $leadCode],
                ['organizational_unit_id'=>$unit->id,'title'=>$leadTitle,'holder_employee_id'=>null,'reports_to_position_id'=>$managerPositionId,'sort_order'=>20,'is_active'=>true,'updated_at'=>$now,'created_at'=>$now]
            );
        }
    }

    public function down(): void
    {
        DB::table('organization_positions')->whereIn('code', [
            'OPS-MGR','OPS-LEAD','ACC-MGR','ACC-LEAD','WH-MGR','WH-LEAD',
            'DELIVERY-MGR','DELIVERY-LEAD','IT-MGR','IT-LEAD','MAINT-MGR','MAINT-LEAD',
        ])->delete();
        DB::table('organization_positions')->where('code', 'SALES-MGR')->update(['reports_to_position_id'=>null]);
    }
};
