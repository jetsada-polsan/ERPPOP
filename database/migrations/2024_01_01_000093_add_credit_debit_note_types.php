<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ใบลดหนี้ (Credit Note) / ใบเพิ่มหนี้ (Debit Note): ปรับยอดหนี้ลูกค้าแบบการเงิน
// ล้วน ไม่กระทบสต๊อก (ต่างจากใบรับคืนสินค้า SALE_RETURN ที่คืนสต๊อกจริง)
// - ใบลดหนี้: ลดราคาย้อนหลัง / ชดเชยของเสีย / ส่วนลดพิเศษ -> ลดยอดค้าง
// - ใบเพิ่มหนี้: เรียกเก็บค่าปรับ / ค่าขนส่งเพิ่ม -> เพิ่มยอดหนี้
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['code' => 'CREDIT_NOTE', 'name_th' => 'ใบลดหนี้'],
            ['code' => 'DEBIT_NOTE', 'name_th' => 'ใบเพิ่มหนี้'],
        ] as $type) {
            if (! DB::table('document_types')->where('code', $type['code'])->exists()) {
                DB::table('document_types')->insert($type);
            }
        }
    }

    public function down(): void
    {
        DB::table('document_types')->whereIn('code', ['CREDIT_NOTE', 'DEBIT_NOTE'])->delete();
    }
};
