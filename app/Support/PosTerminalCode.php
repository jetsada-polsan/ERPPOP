<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\PosDevice;
use RuntimeException;

/**
 * จ่ายรหัสเครื่อง POS ตัวถัดไปของสาขา
 *
 * รหัสมีรูปแบบ POS-<รหัสสาขา>-<ลำดับในสาขา> เช่น POS-B001-01
 * สาขาหนึ่งมีได้หลายเครื่อง ลำดับจึงนับแยกรายสาขา
 *
 * ให้ระบบจ่ายเลขเอง ไม่ให้คนพิมพ์ เพราะการพิมพ์เองคือต้นเหตุที่เครื่องหลายตัว
 * ถูกตั้งรหัสจากเลขคลังแล้วผูกกับสาขาที่มีเลขเดียวกันแต่คนละที่ กว่าจะรู้ตัว
 * ยอดขายก็ลงผิดสาขาไปหลายเดือนแล้ว
 *
 * เลขที่เคยใช้แล้วจะไม่ถูกหยิบกลับมาใช้ซ้ำ ต่อให้เครื่องนั้นถูกยกเลิกไปแล้ว
 * เพราะบิลเก่ายังอ้างรหัสนั้นอยู่
 */
class PosTerminalCode
{
    public static function next(Branch $branch): string
    {
        $prefix = 'POS-'.$branch->code.'-';

        $highest = 0;
        foreach (PosDevice::withoutGlobalScopes()->where('terminal_code', 'like', $prefix.'%')->pluck('terminal_code') as $code) {
            $suffix = substr((string) $code, strlen($prefix));
            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        $next = $highest + 1;
        if ($next > 99) {
            throw new RuntimeException("สาขา {$branch->code} มีเครื่องครบ 99 เครื่องแล้ว ต้องขยายรูปแบบรหัสก่อน");
        }

        return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
}
