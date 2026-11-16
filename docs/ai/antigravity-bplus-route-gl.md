# รายงานสรุปงานที่ 47: ลงบัญชี (GL) ให้เอกสาร B18 ที่มาจาก BPlus

**วันที่:** 16 พฤศจิกายน 2026  
**ผู้จัดทำ:** Antigravity  
**Git Branch:** `antigravity/bplus-route-gl` (สร้างจาก `origin/antigravity/bplus-route-master`, PR #107)

---

## 1. ภาพรวมการดำเนินงาน

งานนี้ได้เชื่อมต่อการลงบัญชีแยกประเภททั่วไป (GL) อัตโนมัติสำหรับเอกสารสาย B18 ที่นำเข้าจาก BPlus:
1. **`BplusDocumentMaterializer`**:
   - บันทึก GL ผ่าน `GlPostingService::postResolved()` (append-only, component `main`) สำหรับเอกสารขายเชื่อ (DS/DSN) และใบเสร็จรับเงิน (RR)
   - **ไม่เรียก `postCreditSale()`** เพื่อไม่ให้แตะ `Document` booted guard และไม่ลงต้นทุนขาย (COGS) เนื่องจากเอกสาร BPlus ไม่ตัดสต๊อก
   - ตรวจสอบยอดเดบิตและเครดิตให้สมดุลตรงตามทศนิยม 2 ตำแหน่งทุกใบด้วย `DecimalMath`
   - หากผังบัญชียังไม่ได้ผูก role ที่ต้องใช้ (เช่น `ar`, `sales_revenue`, `vat_output`, `cash`, `bank`, `rounding`) ระบบจะไม่ throw จนทำให้การนำเข้าทั้งไฟล์ล้มเหลว แต่จะข้ามการลง GL ของใบนั้นและบันทึกข้อความปัญหาใน `bplus_sync_documents.problem` ว่า `"ยังไม่ผูกบัญชี [role]"`
   - รองรับการซิงค์ซ้ำ:
     - หากยอดและบัญชีเท่าเดิม: fingerprint ตรงกัน ไม่มีการบันทึกซ้ำ
     - หากยอดหรือบัญชีเปลี่ยน: กลับรายการเดิม (`REPOST REVERSAL:`) แล้วลงรายการใหม่ตามยอดปัจจุบัน
     - หากเอกสารถูกยกเลิกใน BPlus: กลับรายการเดิม (`VOID REVERSAL:`) ทำให้ยอดสุทธิของทุกบัญชีเป็น 0.00 โดยไม่แตะตาราง `documents` (ไม่ละเมิด guard)
2. **คำสั่งใหม่ `php artisan bplus:repost-gl {route=B18} {--dry-run}`**:
   - ใช้สำหรับให้สำนักงานบัญชีสั่งลงบัญชีใหม่ทั้งสายเมื่อมีการเปลี่ยนเลขบัญชีของแต่ละ role
   - ตัวเลือก `--dry-run` คำนวณยอดและแสดงสรุปเดบิต/เครดิตต่อบัญชีโดยไม่บันทึกจริงลงฐานข้อมูล
3. **การตรวจสอบ ขาย-GL (`ErpHealth` และ `ErpReadiness`)**:
   - นำเงื่อนไขยกเว้น `source_system = 'bplus'` ออกจากการตรวจสอบ เพื่อให้เอกสารขายจาก BPlus ถูกตรวจสอบการลง GL เช่นเดียวกับเอกสารขายปกติของ ERP

---

## 2. ตารางตัวอย่าง Dr/Cr

### 2.1 ตัวอย่างใบขายเชื่อ (DS) มี VAT 1 ใบ: `DS256911/06364`
- ยอดรวมทั้งบิล (`total_amount`): 1,837.00 บาท
- ยอดภาษีขาย (`vat_amount`): 22.57 บาท
- ยอดก่อนภาษี (`subtotal_amount`): 1,814.43 บาท

| ลำดับ | รหัสบัญชี | ชื่อบัญชี (ตาม Role) | เดบิต (บาท) | เครดิต (บาท) | หมายเหตุ |
|:---:|:---:|:---|:---:|:---:|:---|
| 1 | 1030 | ลูกหนี้การค้า (`ar`) | 1,837.00 | 0.00 | ยอดรวมทั้งบิล |
| 2 | 4010 | รายได้จากการขาย (`sales_revenue`) | 0.00 | 1,814.43 | ยอดก่อน VAT |
| 3 | 2150 | ภาษีขาย (`vat_output`) | 0.00 | 22.57 | ยอด VAT จาก BPlus |
| **รวม** | | | **1,837.00** | **1,837.00** | **สมดุลตรงตามสตางค์** |

*(หมายเหตุ: กรณีใบขาย DSN ที่ไม่มี VAT ยอด 49.00 บาท จะลง Dr 1030 = 49.00 / Cr 4010 = 49.00 โดยไม่มีรายการภาษีขาย)*

---

### 2.2 ตัวอย่างใบเสร็จรับเงิน (RR) 1 ใบ: `RR26911/000800`
- ยอดตัดหนี้รวม (`total_amount`): 1,000.00 บาท
- ชำระด้วยเงินสด (`cash_amount`): 500.00 บาท
- ชำระด้วยเงินโอน (`transfer_amount`): 499.63 บาท
- เศษสตางค์ขาด (`short_amount`): 0.37 บาท

| ลำดับ | รหัสบัญชี | ชื่อบัญชี (ตาม Role) | เดบิต (บาท) | เครดิต (บาท) | หมายเหตุ |
|:---:|:---:|:---|:---:|:---:|:---|
| 1 | 1010 | เงินสด (`cash`) | 500.00 | 0.00 | รับเป็นเงินสด |
| 2 | 1020 | เงินฝากธนาคาร (`bank`) | 499.63 | 0.00 | รับเงินโอนเข้าบัญชีธนาคาร |
| 3 | 5020 | ค่าใช้จ่ายเศษสตางค์ขาด (`expense`/`rounding_diff`) | 0.37 | 0.00 | ยอดขาดจากการปัดเศษ |
| 4 | 1030 | ลูกหนี้การค้า (`ar`) | 0.00 | 1,000.00 | ตัดหนี้ลูกหนี้ตามยอดบิล |
| **รวม** | | | **1,000.00** | **1,000.00** | **สมดุลตรงตามสตางค์** |

---

## 3. ผลการรัน `--dry-run` จากข้อมูลทดสอบ

คำสั่ง:
```bash
php artisan bplus:repost-gl B18 --dry-run
```

ผลลัพธ์ที่ได้:
```text
สายขาย: B18 [DRY-RUN]
+--------+----------------+------------+-------------+
| รหัสบัญชี | ชื่อบัญชี          | เดบิต (บาท) | เครดิต (บาท) |
+--------+----------------+------------+-------------+
| 1020   | เงินฝากธนาคาร   | 1,049.00   | 0.00        |
| 1030   | ลูกหนี้การค้า      | 1,886.00   | 1,049.00    |
| 2150   | ภาษีขาย         | 0.00       | 22.57       |
| 4010   | รายได้จากการขาย | 0.00       | 1,863.43    |
+--------+----------------+------------+-------------+
| รวม    |                | 2,935.00   | 2,935.00    |
+--------+----------------+------------+-------------+
จำนวนเอกสาร: 3 ใบ
โหมด DRY-RUN: ไม่ได้บันทึกการเปลี่ยนแปลงลงสมุดรายวัน
```

---

## 4. ผลการถอดโค้ดแล้วเทสต์แดง (Red Test Verification)

เพื่อพิสูจน์ว่าชุดทดสอบสามารถจับข้อผิดพลาด (bug) ได้จริงตามข้อ 5 ได้ทดลองถอดบรรทัด Cr `vat_output` ใน `BplusDocumentMaterializer.php` ออกชั่วคราว:

```php
        $resolved = [
            [$arAccount->id, $total, 0],
            [$revAccount->id, 0, $subtotal],
        ];
        // if (DecimalMath::compare($vat, 0) > 0 && $vatAccount) {
        //     $resolved[] = [$vatAccount->id, 0, $vat];
        // }
```

ผลการรันชุดทดสอบกลายเป็น **สีแดง (Failed 5 tests)** ทันที:

```text
FAILED  Tests\Feature\BplusDocumentsTest > route documents become erp bookings sales and receipts without stock or gl
ใบขายจาก BPlus ไม่ทำให้ตรวจ GL ไม่ผ่าน
Failed asserting that:
| ขาย-GL | ไม่ผ่าน | เอกสารขาย 1 ใบไม่มีรายการ GL รวม 1,837.00 บาท |
does not contain "ไม่มีรายการ GL".

FAILED  Tests\Feature\BplusDocumentsTest > missing product co8110 is created and shown in document lines
Failed asserting that 'GL debit/credit ไม่สมดุล' is null.

FAILED  Tests\Feature\BplusDocumentsTest > ds credit sale with vat posts balanced gl by roles
Failed asserting that actual size 0 matches expected size 3.

FAILED  Tests\Feature\BplusDocumentsTest > resync with changed amount reverses old and posts new gl
Failed asserting that 0 is identical to 3.

FAILED  Tests\Feature\BplusDocumentsTest > erp health and readiness pass sales gl check when all documents posted
Output does not contain "เอกสารขายลง GL ครบ".

Tests:    5 failed, 17 passed (144 assertions)
```

หลังคืนโค้ดกลับสภาพสมบูรณ์ ชุดทดสอบทั้งหมด 22 เทสต์ใน `BplusDocumentsTest` และทั้งโครงการ 1,149 เทสต์ผ่านทั้งหมด (Green 100%)

---

## 5. สรุปไฟล์ที่แก้ไข/สร้างใหม่

1. `app/Services/Bplus/BplusDocumentMaterializer.php`:
   - ฉีด `GlPostingService`
   - เพิ่ม `materializeCreditSaleGl()` และ `materializeReceiptGl()`
   - เพิ่ม `repostGl()` และ `roundingAccount()`
2. `app/Console/Commands/BplusRepostGl.php`:
   - คำสั่ง Artisan `bplus:repost-gl {route=B18} {--dry-run}`
3. `app/Console/Commands/ErpHealth.php`:
   - นำเงื่อนไขข้อยกเว้น `source_system = 'bplus'` ออกจากการตรวจ ขาย-GL
4. `app/Console/Commands/ErpReadiness.php`:
   - นำเงื่อนไขข้อยกเว้น `source_system = 'bplus'` ออกจากการตรวจ ขาย-GL
5. `tests/Feature/BplusDocumentsTest.php`:
   - ปรับ assertion ใน `test_route_documents_become_erp_bookings_sales_and_receipts_without_stock_or_gl` ให้ตรวจว่ามี GL
   - เพิ่มเทสต์ครอบคลุม 10 กรณีใหม่สำหรับการลงบัญชี GL, การกลับรายการ, การซิงค์ซ้ำ, การ unmap role, คำสั่ง repost-gl, dry-run และ erp:health / erp:readiness
6. `docs/ai/antigravity-bplus-route-gl.md`:
   - รายงานสรุปผลภาษาไทย
