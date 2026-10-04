<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ค่าเสื่อมราคาเริ่มลงบัญชีแล้ว ต้องรู้ว่าลงบัญชีไหน ผังบัญชีที่นำเข้ามามีบัญชีชื่อตรงตัวอยู่แล้ว
// (5340-00 ค่าเสื่อมราคา / 1420-00 ค่าเสื่อมราคาสะสม) ผูกให้เฉพาะเมื่อชื่อตรงตัวเพียงบัญชีเดียว
// และยังไม่มีบัญชีอื่นถือบทบาทนั้น — ไม่ตรงก็ปล่อยให้ผู้ดูแลเลือกเองในหน้าผังบัญชี
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'depreciation_expense' => ['ค่าเสื่อมราคา', 'expense'],
            'accumulated_depreciation' => ['ค่าเสื่อมราคาสะสม', 'asset'],
        ] as $role => [$name, $type]) {
            if (DB::table('chart_of_accounts')->where('default_role', $role)->exists()) {
                continue;
            }
            $matches = DB::table('chart_of_accounts')->where('name_th', $name)->where('account_type', $type)->pluck('id');
            if ($matches->count() === 1) {
                DB::table('chart_of_accounts')->where('id', $matches->first())->update(['default_role' => $role]);
            }
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereIn('default_role', ['depreciation_expense', 'accumulated_depreciation'])
            ->update(['default_role' => null]);
    }
};
