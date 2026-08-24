<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * legacy_sku ต้องไม่ซ้ำ
 *
 * โค้ดนำเข้าใช้ค่านี้เป็นคีย์ตัดสินว่า "มีสินค้านี้แล้วหรือยัง" แต่ฐานข้อมูลไม่เคย
 * บังคับว่ามันไม่ซ้ำ การนำเข้าสองครั้งที่วิ่งพร้อมกันจึงตรวจว่าไม่มีทั้งคู่
 * แล้วสร้างซ้ำได้ทั้งคู่ กว่าจะรู้ก็ตอนกระทบยอดแล้วเจอสินค้าเดียวกันสองรหัส
 *
 * ใช้ unique เฉพาะแถวที่มีค่า เพราะสินค้าที่สร้างในระบบเองไม่มี legacy_sku
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('products')
            ->whereNotNull('legacy_sku')
            ->where('legacy_sku', '<>', '')
            ->selectRaw('legacy_sku')
            ->groupBy('legacy_sku')
            ->havingRaw('count(*) > 1')
            ->pluck('legacy_sku');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'มี legacy_sku ซ้ำอยู่ '.$duplicates->count().' ค่า: '.$duplicates->take(10)->implode(', ')
                .' — ต้องแก้ให้ไม่ซ้ำก่อน ระบบจะไม่เลือกให้เองว่าตัวไหนคือตัวจริง'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX products_legacy_sku_unique ON products (legacy_sku)
                WHERE legacy_sku IS NOT NULL AND legacy_sku <> ''");

            return;
        }

        DB::statement('CREATE UNIQUE INDEX products_legacy_sku_unique ON products (legacy_sku)
            WHERE legacy_sku IS NOT NULL AND legacy_sku <> \'\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_legacy_sku_unique');
    }
};
