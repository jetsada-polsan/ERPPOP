<?php

namespace App\Support;

use App\Services\Inventory\ScaleBarcodeService;

/**
 * กฎของบาร์โค้ดแต่ละประเภท
 *
 * แยกประเภทเพราะเลข 13 หลักไม่ได้แปลว่าเป็น EAN-13 เสมอไป บริษัทตั้งรหัสภายใน
 * 13 หลักเองก็ได้ ถ้าเหมารวมเป็น EAN-13 หมดแล้วบังคับ check digit
 * ของเดิมที่ใช้งานอยู่จะสแกนไม่ผ่านทั้งที่ไม่มีอะไรเสีย
 */
class BarcodePolicy
{
    /** EAN-13 ตามมาตรฐาน GS1 — บังคับ check digit */
    public const EAN13_STANDARD = 'EAN13_STANDARD';

    /** รหัส 13 หลักที่บริษัทตั้งเอง ไม่ใช่ GS1 — check digit เป็นแค่คำเตือน */
    public const INTERNAL_13 = 'INTERNAL_13';

    /** ฉลากจากเครื่องชั่ง ราคา/น้ำหนักฝังในบาร์โค้ด */
    public const SCALE_WEIGHT = 'SCALE_WEIGHT';

    /** ของเก่าหรือรหัสที่กำหนดเอง ไม่บังคับรูปแบบ */
    public const CUSTOM = 'CUSTOM';

    public const ALL = [self::EAN13_STANDARD, self::INTERNAL_13, self::SCALE_WEIGHT, self::CUSTOM];

    public const LABELS = [
        self::EAN13_STANDARD => 'EAN-13 มาตรฐาน (GS1)',
        self::INTERNAL_13 => 'รหัส 13 หลักภายใน',
        self::SCALE_WEIGHT => 'บาร์โค้ดเครื่องชั่ง',
        self::CUSTOM => 'กำหนดเอง / ของเก่า',
    ];

    /**
     * ตรวจบาร์โค้ดตามประเภท
     *
     * แยก error กับ warning ชัดเจน: error คือบันทึกไม่ได้ warning คือบันทึกได้
     * แต่ต้องรู้ไว้ — ของเดิม 217 รายการที่ check digit ไม่ตรงต้องยังใช้งานได้
     *
     * @return array{ok:bool, errors:array<int,string>, warnings:array<int,string>}
     */
    public function check(string $type, string $barcode): array
    {
        $barcode = trim($barcode);
        $errors = [];
        $warnings = [];

        if ($barcode === '') {
            return ['ok' => false, 'errors' => ['ต้องระบุบาร์โค้ด'], 'warnings' => []];
        }
        if (! in_array($type, self::ALL, true)) {
            return ['ok' => false, 'errors' => ['ประเภทบาร์โค้ดไม่ถูกต้อง'], 'warnings' => []];
        }

        switch ($type) {
            case self::EAN13_STANDARD:
                if (strlen($barcode) !== 13 || ! ctype_digit($barcode)) {
                    $errors[] = 'EAN-13 ต้องเป็นตัวเลข 13 หลักพอดี';
                } elseif (! $this->isValidEan13($barcode)) {
                    $errors[] = 'check digit ไม่ถูกต้อง ควรเป็น '.$this->checkDigit(substr($barcode, 0, 12));
                }
                break;

            case self::INTERNAL_13:
                if (strlen($barcode) !== 13 || ! ctype_digit($barcode)) {
                    $errors[] = 'รหัสภายในต้องเป็นตัวเลข 13 หลักพอดี';
                } elseif (! $this->isValidEan13($barcode)) {
                    // ไม่ใช่ GS1 จึงไม่จำเป็นต้องตรงสูตร แต่เครื่องอ่านบางรุ่นจะไม่ยอม
                    $warnings[] = 'check digit ไม่ตรงสูตร EAN-13 (ใช้ได้ แต่เครื่องอ่านบางรุ่นอาจไม่รับ)';
                }
                break;

            case self::SCALE_WEIGHT:
                if (app(ScaleBarcodeService::class)->decode($barcode) === null) {
                    $errors[] = 'ไม่ตรงรูปแบบบาร์โค้ดเครื่องชั่งที่ตั้งไว้';
                }
                break;

            case self::CUSTOM:
                // ไม่บังคับรูปแบบโดยเจตนา ของเก่ามีได้หลายแบบ
                break;
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    public function isValidEan13(string $barcode): bool
    {
        return strlen($barcode) === 13
            && ctype_digit($barcode)
            && $this->checkDigit(substr($barcode, 0, 12)) === (int) $barcode[12];
    }

    /** คำนวณหลักตรวจสอบจากตัวเลข 12 หลักแรก */
    public function checkDigit(string $twelveDigits): int
    {
        if (strlen($twelveDigits) !== 12 || ! ctype_digit($twelveDigits)) {
            return -1;
        }

        $sum = 0;
        for ($position = 0; $position < 12; $position++) {
            $sum += ((int) $twelveDigits[$position]) * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
