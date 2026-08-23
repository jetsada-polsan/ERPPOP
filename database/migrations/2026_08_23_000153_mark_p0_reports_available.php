<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * รายงาน P0 ทั้ง 10 ตัวมีหน้าจอจริงแล้ว — เปลี่ยนสถานะจาก planned เป็น available
 *
 * `available` แปลว่า "มีหน้าจอให้เปิดได้" ไม่ใช่ "เปิดใช้แล้ว" — ยังคง enabled = false
 * ตามกติกาที่เจ้าของตั้งไว้ว่าเปิดใช้จริงได้ต่อเมื่อ mapping และ UAT เทียบยอดผ่าน
 * ผู้บริหารเป็นคนกดเปิดเองจากทะเบียนรายงานเมื่อพร้อม
 */
return new class extends Migration
{
    private const CODES = [
        'sales.daily_by_channel',
        'booking.outstanding',
        'booking.due',
        'booking.by_branch_seller',
        'ap.outstanding_detail',
        'ap.aging',
        'cash.daily_cash_book',
        'cash.bank_summary',
        'cash.bank_reconciliation',
        'payment.received_and_unidentified',
    ];

    public function up(): void
    {
        DB::table('report_definitions')
            ->whereIn('code', self::CODES)
            ->where('status', 'planned')
            ->update(['status' => 'available', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('report_definitions')
            ->whereIn('code', self::CODES)
            ->update(['status' => 'planned', 'enabled' => false, 'updated_at' => now()]);
    }
};
