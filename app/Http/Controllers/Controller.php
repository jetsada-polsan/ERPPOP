<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /**
     * สาขาที่ยังไม่ได้ตั้งคลังเริ่มต้นออกเอกสารที่ต้องตัด/รับสต๊อกไม่ได้.
     *
     * เช็คตั้งแต่ชั้น controller เพื่อให้ผู้ใช้เห็นข้อความที่บอกวิธีแก้ แทนที่จะไป
     * เจอ RuntimeException ลึก ๆ ในชั้น service (หรือแย่กว่านั้น คือบันทึกครึ่ง ๆ).
     */
    protected function guardBranchWarehouse(int|string|null $branchId): ?RedirectResponse
    {
        $branch = $branchId ? Branch::find((int) $branchId) : null;

        if (! $branch || $branch->hasDefaultWarehouse()) {
            return null;
        }

        return back()->withInput()->with(
            'error',
            "สาขา [{$branch->code}] {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น ".
            'กรุณาตั้งค่าคลังเริ่มต้นของสาขาที่หน้า "คลังสินค้า" ก่อนออกเอกสาร',
        );
    }
}
