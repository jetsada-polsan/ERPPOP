<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ชนิดเอกสารฝาก/ถอนเงินสดกับธนาคาร
 *
 * เป็นแหล่งสุดท้ายของสมุดเงินสดที่ยังขาด (แหล่งอื่นต่อไว้แล้วใน dc2a853)
 * ถ้าไม่มี เงินสดที่เอาไปฝากธนาคารจะหายไปจากสมุดเงินสดโดยไม่มีร่องรอย
 * และยอดเงินสดคงเหลือจะสูงกว่าของจริงตลอดไป
 */
return new class extends Migration
{
    private const TYPES = [
        'CASH_DEPOSIT' => 'ใบฝากเงินสดเข้าธนาคาร',
        'CASH_WITHDRAWAL' => 'ใบถอนเงินสดจากธนาคาร',
    ];

    public function up(): void
    {
        foreach (self::TYPES as $code => $name) {
            if (! DB::table('document_types')->where('code', $code)->exists()) {
                DB::table('document_types')->insert([
                    'code' => $code,
                    'name_th' => $name,
                ]);
            }
        }
    }

    public function down(): void
    {
        // ทิ้งเฉพาะชนิดที่ยังไม่มีเอกสารใช้งานจริง ไม่งั้นจะลบประวัติทางบัญชีไปด้วย
        $ids = DB::table('document_types')->whereIn('code', array_keys(self::TYPES))->pluck('id');
        foreach ($ids as $id) {
            if (! DB::table('documents')->where('document_type_id', $id)->exists()) {
                DB::table('document_types')->where('id', $id)->delete();
            }
        }
    }
};
