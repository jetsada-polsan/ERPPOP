<?php

namespace App\Support;

final class DocumentStatus
{
    public const LABELS = [
        'active' => 'เสร็จสมบูรณ์', 'posted' => 'ลงบัญชีแล้ว', 'approved' => 'อนุมัติแล้ว',
        'completed' => 'เสร็จสมบูรณ์', 'pending_approval' => 'รออนุมัติ', 'pending' => 'รอดำเนินการ',
        'open' => 'เปิดอยู่', 'closed' => 'ปิดแล้ว', 'draft' => 'ฉบับร่าง',
        'rejected' => 'ไม่อนุมัติ', 'cancelled' => 'ยกเลิก', 'void' => 'ยกเลิก',
        'paid' => 'ชำระครบแล้ว', 'partial' => 'ชำระบางส่วน', 'overdue' => 'เกินกำหนด',
        'prepared' => 'จัดทำแล้ว', 'reviewed' => 'ตรวจแล้ว', 'submitted' => 'ยื่นแล้ว',
        'accepted' => 'ตอบรับแล้ว', 'sent' => 'ส่งแล้ว', 'uploaded' => 'อัปโหลดแล้ว',
        'needs_review' => 'รอตรวจสอบ', 'matched' => 'จับคู่แล้ว', 'processing' => 'กำลังประมวลผล',
        'approving' => 'กำลังอนุมัติ',
        'success' => 'สำเร็จ', 'failed' => 'ล้มเหลว', 'queued' => 'รอคิว', 'in_progress' => 'กำลังดำเนินการ',
        'running' => 'กำลังทำงาน', 'ready' => 'พร้อมใช้งาน', 'inactive' => 'ปิดใช้งาน',
        'online' => 'ออนไลน์', 'offline' => 'ออฟไลน์', 'expired' => 'หมดอายุ',
        'booked' => 'รอจัดของ', 'loaded' => 'ขึ้นรถแล้ว', 'in_transit' => 'กำลังส่ง',
        'delivered' => 'ส่งครบแล้ว', 'converted' => 'แปลงเอกสารแล้ว', 'resolved' => 'แก้ไขแล้ว',
        'scheduled' => 'กำหนดเวลาแล้ว', 'issued' => 'ออกเอกสารแล้ว', 'received' => 'รับแล้ว',
        'cleared' => 'ผ่านแล้ว', 'bounced' => 'เช็คคืน', 'pending_review' => 'รอตรวจสอบ',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status ?? ''] ?? ($status ? 'ไม่ทราบสถานะ' : '-');
    }

    public static function badge(?string $status): string
    {
        return match ($status) {
            'active', 'posted', 'approved', 'completed', 'paid' => 'text-bg-success',
            'pending_approval', 'pending', 'draft' => 'text-bg-warning',
            'rejected', 'failed' => 'text-bg-danger',
            'cancelled', 'void', 'closed' => 'text-bg-secondary',
            default => 'text-bg-info',
        };
    }
}
