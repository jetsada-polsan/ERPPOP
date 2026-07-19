<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// จัดโครงสาขาให้ตรงกับที่ใช้จริง (แทน BPlus): rename สาขาเดิม (คง id เดิม FK ไม่พัง) +
// สร้างสาขาใหม่ เจริญศรี-1/-2 (ใช้คลังเจริญศรีร่วมกัน), สุรินทร์, อำนาจเจริญ +
// ปิดห้วยวังนอง (is_active=false เก็บบิลเก่า 3,302 ใบไว้ ไม่ hard-delete ประวัติการเงิน)
return new class extends Migration
{
    public function up(): void
    {
        $defaultPriceTable = DB::table('price_tables')->where('is_default', true)->value('id');
        $now = now();

        $byCode = fn ($code) => DB::table('branches')->where('code', $code)->first();

        // --- rename + recode สาขาเดิม (สลับ code ทีละขั้นกัน unique ชน) ---
        // สนง.ใหญ่ 0001 -> HO (คลังกลาง เก็บสต๊อก 137k) ปลด code 0001
        if ($ho = $byCode('0001')) {
            DB::table('branches')->where('id', $ho->id)->update(['code' => 'HO', 'name_th' => 'สำนักงานใหญ่ (คลังกลาง)']);
        }
        // ห้วยวังนอง 0003 -> ปิด + ปลด code 0003
        if ($hwn = $byCode('0003')) {
            DB::table('branches')->where('id', $hwn->id)->update(['code' => 'X-HWN', 'name_th' => 'สาขาห้วยวังนอง (ปิดใช้งาน)', 'is_active' => false]);
        }
        // ดอนกลาง (เดิม 0005 ตลาดดอนกลาง) -> 0001
        if ($don = $byCode('0005')) {
            DB::table('branches')->where('id', $don->id)->update(['code' => '0001', 'name_th' => 'สาขาดอนกลาง']);
        }
        // ปลาดุก (เดิม 0004 บ้านปลาดุก) -> 0003
        if ($pla = $byCode('0004')) {
            DB::table('branches')->where('id', $pla->id)->update(['code' => '0003', 'name_th' => 'สาขาปลาดุก']);
        }
        // วาริน (เดิม 0002 หน้าร้าน) -> คง 0002 เปลี่ยนชื่อ
        if ($war = $byCode('0002')) {
            DB::table('branches')->where('id', $war->id)->update(['name_th' => 'สาขาวาริน']);
        }

        // --- สร้างสาขาใหม่ ---
        $shopWarehouse = DB::table('warehouses')->where('code', 'SHOP')->value('id')
            ?? DB::table('warehouses')->value('id');
        if (! $shopWarehouse) {
            $shopWarehouse = DB::table('warehouses')->insertGetId([
                'branch_id' => null,
                'code' => 'SHOP',
                'name' => 'คลังหน้าร้าน',
            ]);
        }

        $location = function (string $name, string $code) use ($shopWarehouse): int {
            return DB::table('warehouse_locations')->where('name', $name)->value('id')
                ?? DB::table('warehouse_locations')->insertGetId([
                    'warehouse_id' => $shopWarehouse,
                    'code' => $code,
                    'name' => $name,
                ]);
        };

        $charoensiLoc = $location('ตลาดเจริญศรี', 'CHAROENSI');
        $surinLoc = $location('สุรินทร์', 'SURIN');
        $amnatLoc = $location('อำนาจเจริญ', 'AMNAT');

        $newBranches = [
            ['0004', 'สาขาเจริญศรี-1', $charoensiLoc],
            ['0005', 'สาขาเจริญศรี-2', $charoensiLoc], // ใช้คลังเจริญศรีร่วมกับ -1
            ['0006', 'สาขาสุรินทร์', $surinLoc],
            ['0007', 'สาขาอำนาจเจริญ', $amnatLoc],
        ];

        foreach ($newBranches as [$code, $name, $locId]) {
            if (DB::table('branches')->where('code', $code)->exists()) {
                continue;
            }
            $branchId = DB::table('branches')->insertGetId([
                'code' => $code, 'name_th' => $name, 'is_active' => true,
                'default_warehouse_location_id' => $locId, 'price_table_id' => $defaultPriceTable,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            // เครื่อง POS 1 เครื่องต่อสาขาใหม่
            DB::table('pos_terminals')->insert([
                'branch_id' => $branchId, 'code' => 'POS-'.$code, 'name' => 'POS '.$name,
            ]);
        }
    }

    public function down(): void
    {
        // ไม่ย้อนอัตโนมัติ (เป็นการจัดโครงข้อมูลจริง) - ปรับผ่านหน้าจัดการสาขาถ้าต้องการ
    }
};
