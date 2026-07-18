<?php

namespace App\Support;

/**
 * แปลงจำนวนเงินเป็นตัวอักษรไทย เช่น 2990.50 -> "สองพันเก้าร้อยเก้าสิบบาทห้าสิบสตางค์"
 * ใช้พิมพ์ท้ายใบกำกับภาษี/ใบเสร็จตามธรรมเนียมเอกสารไทย
 */
class ThaiBaht
{
    private const DIGITS = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];

    private const UNITS = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    public static function text(float $amount): string
    {
        $amount = round($amount, 2);
        $baht = (int) floor($amount);
        $satang = (int) round(($amount - $baht) * 100);

        if ($baht === 0 && $satang === 0) {
            return 'ศูนย์บาทถ้วน';
        }

        $text = $baht > 0 ? self::readNumber($baht).'บาท' : '';
        $text .= $satang > 0 ? self::readNumber($satang).'สตางค์' : 'ถ้วน';

        return $text;
    }

    // อ่านจำนวนเต็ม แบ่งทีละ 6 หลัก (ล้าน)
    private static function readNumber(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        $millions = intdiv($number, 1000000);
        $rest = $number % 1000000;

        $text = '';
        if ($millions > 0) {
            $text .= self::readNumber($millions).'ล้าน';
        }

        $digits = str_split((string) $rest);
        $len = count($digits);
        foreach ($digits as $i => $d) {
            $d = (int) $d;
            $pos = $len - $i - 1; // ตำแหน่งหลัก (0 = หน่วย)
            if ($d === 0) {
                continue;
            }
            if ($pos === 0 && $d === 1 && $len > 1) {
                $text .= 'เอ็ด';
            } elseif ($pos === 1 && $d === 2) {
                $text .= 'ยี่สิบ';
            } elseif ($pos === 1 && $d === 1) {
                $text .= 'สิบ';
            } else {
                $text .= self::DIGITS[$d].self::UNITS[$pos];
            }
        }

        return $text;
    }
}
