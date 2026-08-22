<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * บัญชีผลต่างสต๊อก — ปลายทางของการปรับปรุงและตัดชำรุดที่ยังไม่เคยลง GL
 *
 * ก่อนหน้านี้การปรับสต๊อก ตรวจนับ และตัดชำรุด เปลี่ยนมูลค่าสินค้าคงเหลือจริง
 * แต่ไม่แตะ GL เลย มูลค่าสินค้าคงเหลือในบัญชีจึงเพี้ยนทันทีที่มีการปรับปรุง
 * และปิดงวดกระทบยอดไม่ได้
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('chart_of_accounts')->where('default_role', 'inventory_adjustment')->exists()) {
            return;
        }

        $existing = DB::table('chart_of_accounts')->where('code', '5030')->first();
        if ($existing) {
            DB::table('chart_of_accounts')->where('id', $existing->id)->update(['default_role' => 'inventory_adjustment']);

            return;
        }

        DB::table('chart_of_accounts')->insert([
            'code' => '5030',
            'name_th' => 'ผลต่างจากการปรับปรุงสินค้าคงเหลือ',
            'name_en' => 'Inventory adjustment variance',
            'account_type' => DB::table('chart_of_accounts')->where('default_role', 'cogs')->value('account_type') ?? 'expense',
            'default_role' => 'inventory_adjustment',
        ]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('default_role', 'inventory_adjustment')->delete();
    }
};
