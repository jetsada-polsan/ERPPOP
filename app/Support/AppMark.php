<?php

namespace App\Support;

/**
 * ตราประจำโมดูล — รูปทรงนามธรรมสองสี แทนไอคอนเส้นบางแบบเดิม
 *
 * ไอคอน glyph สีเดียวทำให้เมนูทั้งแถวหน้าตาเหมือนกันหมด กวาดตาแล้วไม่มีอะไร
 * ให้จับ ชุดนี้ใช้ทรงตันทับซ้อนสองสีคนละวรรณะ (12 ทรง × 10 โทน
 * = 120 แบบ) เลือกทรงจากชื่อเมนูแบบคงที่ เมนูเดิมจึงได้ตราเดิมเสมอ
 *
 * ประกาศไว้ที่เดียวแล้วใช้ทั้งเมนูข้าง (Blade) และ App Launcher (Vue)
 * เพื่อไม่ให้สองที่หลุดจากกัน
 */
class AppMark
{
    /** เนื้อใน <svg viewBox="0 0 24 24"> ใช้ตัวแปร --m1/--m2 เป็นสี */
    public const SHAPES = [
        'orbit' => '<circle cx="9.2" cy="9.2" r="6.2" fill="var(--m2)"/><circle cx="15.6" cy="15.4" r="5.6" fill="var(--m1)"/>',
        'split' => '<path d="M4 7.5A3.5 3.5 0 017.5 4H20v12.5A3.5 3.5 0 0116.5 20H4z" fill="var(--m2)"/><path d="M20 4v12.5A3.5 3.5 0 0116.5 20H4z" fill="var(--m1)"/>',
        'petal' => '<path d="M12 3a9 9 0 019 9h-9z" fill="var(--m2)"/><path d="M12 12v9a9 9 0 01-9-9z" fill="var(--m1)"/><circle cx="12" cy="12" r="2.3" fill="#fff"/>',
        'tilt' => '<rect x="3.5" y="3.5" width="11.5" height="11.5" rx="3.4" fill="var(--m2)"/><rect x="9" y="9" width="11.5" height="11.5" rx="3.4" fill="var(--m1)"/>',
        'ring' => '<path d="M12 3a9 9 0 100 18 9 9 0 000-18zm0 4.6a4.4 4.4 0 110 8.8 4.4 4.4 0 010-8.8z" fill="var(--m1)"/><path d="M12 3a9 9 0 019 9h-4.6A4.4 4.4 0 0012 7.6z" fill="var(--m2)"/>',
        'bars' => '<rect x="3.6" y="12.4" width="4.8" height="7.6" rx="1.7" fill="var(--m2)"/><rect x="9.6" y="7.2" width="4.8" height="12.8" rx="1.7" fill="var(--m1)"/><rect x="15.6" y="3.4" width="4.8" height="16.6" rx="1.7" fill="var(--m2)"/>',
        'plus' => '<path d="M9.3 3.6h5.4v5.7h5.7v5.4h-5.7v5.7H9.3v-5.7H3.6V9.3h5.7z" fill="var(--m2)"/><path d="M14.7 9.3h5.7v5.4h-5.7z" fill="var(--m1)"/>',
        'leaf' => '<path d="M20.4 3.6c0 9.4-5.6 15-17 16.8 0-9.4 5.6-15 17-16.8z" fill="var(--m2)"/><path d="M20.4 3.6C13.4 8.3 8.1 13.6 3.4 20.4c11.4-1.8 17-7.4 17-16.8z" fill="var(--m1)"/>',
        'quad' => '<rect x="3.5" y="3.5" width="7.6" height="7.6" rx="2.4" fill="var(--m1)"/><rect x="12.9" y="3.5" width="7.6" height="7.6" rx="2.4" fill="var(--m2)"/><rect x="3.5" y="12.9" width="7.6" height="7.6" rx="2.4" fill="var(--m2)"/><rect x="12.9" y="12.9" width="7.6" height="7.6" rx="2.4" fill="var(--m1)"/>',
        'tag' => '<path d="M3.5 7.5A3.5 3.5 0 017 4h7.2l6.3 8-6.3 8H7a3.5 3.5 0 01-3.5-3.5z" fill="var(--m2)"/><path d="M14.2 4l6.3 8-6.3 8z" fill="var(--m1)"/>',
        'stack' => '<path d="M12 2.8l9 4.9-9 4.9-9-4.9z" fill="var(--m2)"/><path d="M21 12.2L12 17l-9-4.8 3.4-1.9L12 13.1l4.6-2.5z" fill="var(--m1)"/><path d="M21 16.6L12 21.4l-9-4.8 3.4-1.9L12 17.5l4.6-2.6z" fill="var(--m2)"/>',
        'burst' => '<circle cx="12" cy="12" r="8.8" fill="var(--m2)"/><path d="M12 3.2a8.8 8.8 0 018.8 8.8H12z" fill="var(--m1)"/><circle cx="12" cy="12" r="3.1" fill="#fff"/>',
    ];

    /** คู่สี: สีหลัก + สีรองคนละวรรณะ แบบเดียวกับไอคอนแอปสมัยใหม่ */
    public const TONES = [
        'blue' => ['#1274a8', '#f0b429'],
        'cyan' => ['#0e7490', '#e2725b'],
        'teal' => ['#0f766e', '#c86fa8'],
        'indigo' => ['#4054a8', '#4fc0a8'],
        'slate' => ['#52677d', '#8fbcd4'],
        'amber' => ['#9b6400', '#5f8fb0'],
        'orange' => ['#b0530a', '#3f8f86'],
        'red' => ['#c62828', '#3d8fb5'],
        'pink' => ['#a3376b', '#e0a94e'],
        'brown' => ['#7c5233', '#6aa39a'],
    ];

    /**
     * เลือกตราจากตำแหน่งของเมนู ไม่ใช่จากชื่อ
     *
     * ถ้าสุ่มจากชื่ออย่างเดียว ทรงเดียวกันจะไปกองอยู่ในหมวดเดียวกันได้ (เคยเจอ
     * ทรงใบไม้ซ้ำ 5 อันติดในหมวดขาย) การไล่ตามตำแหน่งทำให้ในหมวดหนึ่ง ๆ
     * ไม่มีทางซ้ำกันเลยจนกว่าจะเกิน 12 รายการ และยังคงที่เท่าเดิมทุกครั้ง
     *
     * @return array{svg: string, m1: string, m2: string}
     */
    public static function forItem(int $sectionIndex, int $itemIndex, string $tone = 'blue'): array
    {
        $shapes = array_values(self::SHAPES);
        $shape = $shapes[($sectionIndex * 5 + $itemIndex) % count($shapes)];
        [$m1, $m2] = self::TONES[$tone] ?? self::TONES['blue'];

        return ['svg' => $shape, 'm1' => $m1, 'm2' => $m2];
    }
}
