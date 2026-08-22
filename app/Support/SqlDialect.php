<?php

namespace App\Support;

/**
 * ชิ้นส่วน SQL ที่ไวยากรณ์ต่างกันระหว่าง PostgreSQL (production) กับ SQLite (ชุดเทสต์)
 *
 * รับชื่อ driver เข้ามาเป็นพารามิเตอร์ เพื่อให้ทดสอบทั้งสองสายได้จริงโดยไม่ต้องมี
 * ฐาน PostgreSQL ตอนรันเทสต์ — สายที่ production ใช้จึงถูกตรึงด้วยเทสต์ไปด้วย
 *
 * ทั้งสองสายต้องให้ความหมายเดียวกันเสมอ (กติกาเดียวกับ view `sales_postings`)
 */
class SqlDialect
{
    /** วันที่ปัจจุบันลบจำนวนวัน ใช้เทียบกับคอลัมน์ชนิดวันที่ */
    public static function dateMinusDays(string $driver, int $days): string
    {
        if ($driver === 'pgsql') {
            return $days === 0 ? 'current_date' : "current_date - interval '{$days} days'";
        }

        return $days === 0 ? "date('now')" : "date('now', '-{$days} day')";
    }

    /**
     * ตัดเวลาให้เหลือระดับชั่วโมงแล้วคืนเป็นข้อความ 'YYYY-MM-DD HH:00'
     *
     * ใช้ค่านี้ทั้ง select, group by และ order by ได้ เพราะรูปแบบเป็นเลขศูนย์นำหน้า
     * เรียงตามตัวอักษรจึงได้ลำดับเดียวกับเรียงตามเวลา
     */
    public static function truncateToHour(string $driver, string $column): string
    {
        return $driver === 'pgsql'
            ? "to_char(date_trunc('hour', {$column}), 'YYYY-MM-DD HH24:00')"
            : "strftime('%Y-%m-%d %H:00', {$column})";
    }
}
