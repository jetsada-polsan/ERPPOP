<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รหัสเครื่อง POS ต้องไม่ซ้ำกัน
 *
 * รหัสนี้ใช้บอกว่าเครื่องอยู่สาขาไหน และใช้เป็นคำนำหน้า idempotency key ของบิล
 * ที่ขายตอนออฟไลน์ ปล่อยให้ซ้ำได้แปลว่าสองเครื่องอ้างตัวเป็นเครื่องเดียวกันได้
 * ซึ่งจะรู้ตัวก็ต่อเมื่อยอดขายสองสาขาปนกันแล้ว
 *
 * ใช้ unique เฉพาะแถวที่มีค่า เพราะเครื่องที่ยังไม่ได้ตั้งรหัสมี terminal_code เป็น null
 * ได้หลายเครื่อง
 */
return new class extends Migration
{
    public function up(): void
    {
        // ตั้ง index ทับของที่ซ้ำอยู่แล้วไม่ได้ ต้องบอกให้คนแก้ก่อน ไม่ใช่แก้ให้เงียบ ๆ
        $duplicates = DB::table('pos_devices')
            ->whereNotNull('terminal_code')
            ->selectRaw('terminal_code')
            ->groupBy('terminal_code')
            ->havingRaw('count(*) > 1')
            ->pluck('terminal_code');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'มีรหัสเครื่อง POS ซ้ำกันอยู่: '.$duplicates->implode(', ')
                .' — ต้องแก้ให้ไม่ซ้ำก่อนจึงจะตั้ง unique index ได้'
            );
        }

        Schema::table('pos_devices', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                return;   // ทำด้วย partial index ข้างล่างแทน
            }
            $table->unique('terminal_code');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pos_devices_terminal_code_unique
                ON pos_devices (terminal_code) WHERE terminal_code IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS pos_devices_terminal_code_unique');

            return;
        }

        Schema::table('pos_devices', fn (Blueprint $table) => $table->dropUnique(['terminal_code']));
    }
};
