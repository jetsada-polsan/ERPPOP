<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ต้นทุนขายอัตโนมัติ (COGS): เก็บต้นทุนเฉลี่ยถ่วงน้ำหนัก (moving average) ต่อสินค้า
// อัปเดตทุกครั้งที่รับซื้อ ใช้คิดต้นทุนขายตอนขายเพื่อลง GL: Dr ต้นทุนขาย / Cr สินค้าคงเหลือ
// seed ค่าเริ่มต้นจาก cost_price ที่มีอยู่ในตารางราคา (ตัวที่ > 0)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('average_cost', 18, 4)->default(0)->after('default_price');
        });

        // seed จาก cost_price ตัวสูงสุดที่มากกว่า 0 ของแต่ละสินค้า
        $costs = DB::table('product_prices')
            ->where('cost_price', '>', 0)
            ->select('product_id', DB::raw('MAX(cost_price) as c'))
            ->groupBy('product_id')->get();

        foreach ($costs as $row) {
            DB::table('products')->where('id', $row->product_id)->update(['average_cost' => $row->c]);
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('average_cost'));
    }
};
