<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ขยาย audit_logs.action จาก 20 เป็น 60 ตัวอักษร
 *
 * ชื่อกิจกรรมที่ยาวเกิน 20 ถูกปฏิเสธบน PostgreSQL (SQLSTATE 22001) แต่ SQLite
 * ไม่บังคับความยาวเลย ชุดเทสต์จึงผ่านมาตลอดทั้งที่ของจริงเขียนไม่ลง
 *
 * ที่ยาวเกินอยู่ตอนนี้:
 *   - `auto_archive_inactive_customer` (30) — มีมาก่อนแล้วใน ArchiveInactiveCustomers
 *     แปลว่าการเก็บประวัติของคำสั่งนั้นเขียนไม่ลงมาตลอดโดยไม่มีใครรู้
 *   - `booking_delivery_delivered` / `booking_delivery_cancelled` (26) — เพิ่งเพิ่ม
 *
 * เลือกขยายคอลัมน์แทนการย่อชื่อ เพราะ audit log ต้องอ่านแล้วเข้าใจว่าเกิดอะไรขึ้น
 * การบีบความหมายออกจากชื่อเพื่อให้พอดีคอลัมน์เป็นการแก้ผิดจุด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 60)->change();
        });
    }

    public function down(): void
    {
        // ย่อกลับไม่ได้ถ้ามีค่าที่ยาวกว่า 20 อยู่แล้ว — ตัดทิ้งเงียบ ๆ แย่กว่าปล่อยไว้กว้าง
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 60)->change();
        });
    }
};
