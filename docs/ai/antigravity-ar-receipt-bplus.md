# รายงานสรุปงานที่ 28: หน้าออกใบเสร็จรับเงินแบบ BPlus (AR Receipt BPlus Single-Page Form - เฟส 1)

**Branch:** `antigravity/ar-receipt-bplus`  
**Base:** `origin/main` (commit `dfd9986`)  
**สถานะ:** ผ่านการทดสอบทั้งหมด (PHPUnit 953 เทสต์: ผ่าน 951, ข้าม 2, ไม่สมบูรณ์ 6, ล้มเหลว 0)

---

## 1. ที่มาและวัตถุประสงค์ (Scope: เฟส 1)

ตามที่เจ้าของระบบใช้งานโปรแกรม BPlus อยู่ และต้องการให้ออกใบเสร็จรับเงินจบในหน้าเดียว:
- **เลือกลูกหนี้/ลูกค้า** → แสดงรายการบิลค้างชำระทันทีโดยไม่ต้องรีโหลดหน้าเว็บ
- **ติ๊กเลือกบิล** พร้อมระบุยอดที่จะตัดหนี้ (ตัดเต็มจำนวน หรือตัดบางส่วนได้)
- **รับชำระได้หลายวิธีพร้อมกันในใบเดียว** (เงินสด + โอนเงินผ่านธนาคาร + เช็คหลายฉบับ)
- **แถบสรุปคำนวณสดด้านขวา (Sticky)**: ตรวจสอบความถูกต้องของยอดเงินแบบ Real-time ผลต่างต้องเท่ากับ 0.00 บาท ถึงจะบันทึกได้
- **ระบุผู้รับเงิน / พนักงานเก็บเงิน (`salesman_id`)**: บันทึกลงในเอกสารใบเสร็จ แสดงในหน้าพิมพ์และตารางรายการ
- **ขอบเขตเฟส 1**: ไม่ทำภาษีหัก ณ ที่จ่าย (WHT), ไม่ทำส่วนลดเงินสดจ่ายชำระเร็ว, ไม่แยกเล่มเลขที่เอกสาร (ใช้เลขที่ชุดเดียวต่อสาขาตามระบบเดิม)
- **คงความเข้ากันได้ย้อนหลัง 100%**: หากไม่ส่งรายการแบบใหม่ (`payments[]`) ผู้เรียกเดิมทั้งหมด (เช่น หน้าใบขาย `sales/show`, โมดูลกองรถ `fleet`, API, และเทสต์เดิม) ยังคงทำงานได้เหมือนเดิมทุกประการโดยไม่ต้องแก้ไขโค้ดใดๆ

---

## 2. การทำงานหน้าจอ BPlus (UI & UX)

หน้าจอสร้างใบเสร็จรับเงินถูกออกแบบใหม่ตามโครงสร้าง BPlus ในไฟล์ [`resources/views/ar-receipts/create.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/ar-receipts/create.blade.php) โดยใช้ Alpine.js ร่วมกับ Bootstrap 5 และ SweetAlert2:

### 2.1 ส่วนหัวเอกสาร (Header Bar)
- **เลือกลูกหนี้/ลูกค้า**: ดรอปดาวน์แสดงเฉพาะลูกหนี้ที่มียอดหนี้ค้างชำระจริง (`$customersWithDebt`) เมื่อผู้ใช้เปลี่ยนลูกค้า ระบบจะดึงรายการบิลค้างชำระผ่าน API JSON (`/ar-receipts/customers/{customer}/open-items`) มาแสดงผลทันทีโดยไม่ต้องโหลดหน้าเว็บใหม่
- **สาขา (`branch_id`)**: กำหนดสาขาที่ออกเอกสาร
- **วันที่ใบเสร็จ (`doc_date`)**: ค่าตั้งต้นคือวันที่ปัจจุบัน
- **ผู้รับเงิน / พนักงานเก็บเงิน (`salesman_id`)**: เลือกพนักงานขายหรือพนักงานเก็บเงินจากฐานข้อมูลพนักงานขายเดิม

### 2.2 ตารางรายการบิลค้างชำระ (Open Bills Table)
- แสดงคอลัมน์: กล่องติ๊กชำระ, เลขที่บิล, วันที่, ครบกำหนด, ยอดหนี้คงค้าง, และช่องกรอกยอดที่ชำระ
- เมื่อติ๊กเลือกบิล ระบบจะใส่ยอดหนี้คงค้างให้เต็มจำนวนโดยอัตโนมัติ และผู้ใช้สามารถแก้ไขยอดเป็นยอดชำระบางส่วน (Partial Payment) ได้
- มีปุ่มสวิตช์ **"แสดงเฉพาะที่เลือก"** ช่วยให้ตรวจสอบได้ง่ายเมื่อลูกค้ามีบิลค้างจำนวนมาก
- ปุ่ม **"ติ๊กทั้งหมด"** และ **"ล้าง"** อำนวยความสะดวกในการเลือกบิลอย่างรวดเร็ว
- มี Validation ฝั่ง Client ตรวจสอบไม่ให้กรอกยอดชำระเกินยอดหนี้คงค้างของบิลนั้นๆ

### 2.3 รายละเอียดการชำระเงินหลายวิธี (Payment Methods)
1. **เงินสด (Cash)**: กรอกจำนวนเงินสด พร้อมปุ่ม *"ใส่ยอดคงเหลือ"* เพื่อเติมยอดส่วนต่างที่เหลือเข้าเงินสดในคลิกเดียว
2. **ยอดโอนเงิน (Bank Transfer)**: กรอกจำนวนเงินโอน พร้อมปุ่ม *"ใส่ยอดคงเหลือ"* และเมื่อมียอดโอนจะเปิดช่องให้ระบุ:
   - บัญชีธนาคารปลายทาง (`transfer_bank_account_id` เลือกจากรายการบัญชีธนาคารของกิจการ)
   - เลขที่อ้างอิง / สลิปโอน (`transfer_ref`)
   - วันเวลาที่โอน (`transfer_at`)
3. **เช็ค (Cheque)**: รองรับการรับเช็คหลายใบในใบเดียว
   - ปุ่ม **"+ เพิ่มเช็ค"** เพื่อเพิ่มแถวใหม่ในตารางเช็ค
   - แต่ละแถวระบุ: ธนาคารบนเช็ค, เลขที่เช็ค, ลงวันที่เช็ค, จำนวนเงิน, และปุ่มลบแถว

### 2.4 แผงสรุปยอดเงินสดๆ และการตรวจสอบ (Sticky Summary Panel)
- แสดงแถบสรุปคงที่ทางขวา ประกอบด้วย:
  - จำนวนบิลที่เลือก
  - ยอดหนี้ที่เลือกชำระรวม (ยอดจัดสรร)
  - จำแนกยอดรับชำระ: เงินสด, เงินโอน, เช็ค
  - ยอดรับชำระรวม
- **กล่องแสดงสถานะความสมดุล**:
  - สีเขียว: ยอดเงินถูกต้องลงตัว (ส่วนต่าง 0.00 บาท)
  - สีแดงเตือน: ยอดรับชำระขาดหรือเกิน พร้อมระบุจำนวนเงินที่ต้องปรับ
- **ปุ่มบันทึก**: ปิดการทำงาน (Disabled) ทันทีหากยอดรับชำระไม่เท่ากับยอดหนี้ที่เลือก (ส่วนต่าง != 0.00) หรือยังไม่ได้ติ๊กเลือกบิล
- **การยืนยันก่อนบันทึก**: ใช้ **SweetAlert2** สรุปรายละเอียดการตัดหนี้และช่องทางการรับเงินให้ผู้ใช้ตรวจสอบก่อนกดยืนยัน (ไม่มีการใช้ `confirm()` หรือ `alert()` ของเบราว์เซอร์)

---

## 3. ตรรกะรับชำระหลายวิธี (Mixed Payments) ใน Service

แก้ไขปรับปรุง [`app/Services/Sales/CustomerPaymentService.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/app/Services/Sales/CustomerPaymentService.php) ให้รองรับการรับชำระหลายวิธี:

### 3.1 การส่งข้อมูลและการเข้ากันได้ย้อนหลัง (Backward Compatibility)
- **โหมดใหม่ (BPlus Mixed Payments)**: รับข้อมูลอาร์เรย์ `payments[]` โดยแต่ละรายการประกอบด้วย:
  `method` ('cash' | 'transfer' | 'cheque'), `amount`, `bank_account_id`, `transfer_ref`, `transfer_at`, `cheque_no`, `cheque_due_date`, `cheque_bank`
- **โหมดเดิม (Legacy Single Payment)**: หากไม่ส่ง `payments[]` ระบบจะแปลงพารามิเตอร์แบบเดิม (`method`, `bank_account_id`, `transfer_ref`, `cheque_*`) เข้าสู่โครงสร้าง `payments` 1 รายการโดยอัตโนมัติ ทำให้ผู้เรียกเดิมทั้งหมดทำงานได้ราบรื่น 100% โดยไม่ต้องเปลี่ยนแปลงโค้ด

### 3.2 ความปลอดภัยและการตรวจสอบความถูกต้อง (Validation & Safety)
- ตรวจสอบยอดรวม: คำนวณ `totalPaid` และ `totalAllocated` หากผลต่าง `abs($totalPaid - $totalAllocated) > 0.009` ระบบจะโยน `RuntimeException` และปฏิเสธการทำรายการทันที
- ตรวจสอบความเป็นเจ้าของบิล: บิลค้างชำระทั้งหมดต้องเป็นของลูกค้า (`customer_id`) รายที่ระบุเท่านั้น
- ตรวจสอบยอดเกิน: ยอดจัดสรรของแต่ละบิลต้องไม่เกินยอดคงค้าง (`balance`) ของบิลนั้น
- **Single Database Transaction**: ดำเนินการทั้งหมดภายใน `DB::transaction(...)` หากมีข้อผิดพลาดในขั้นตอนใด ข้อมูลทั้งหมดจะถูก Rollback ย้อนคืนเสมือนไม่มีอะไรเกิดขึ้น

---

## 4. การเรียกใช้ Helper บัญชีเดิม (Accounting Posting Helpers)

เพื่อความถูกต้อง ปลอดภัย และไม่สร้างตรรกะบัญชีซ้ำซ้อน ระบบได้นำ Helper เดิมที่มีอยู่แล้วมาใช้งานตามข้อกำหนด:

1. **`GlPostingService::postCustomerReceipt(PaymentDocument $paymentDoc, float $amount, string $entryDate, string $remark, string $method, ?int $bankAccountId)`**:
   - ระบบวนลูปตามรายการชำระเงินแต่ละแถว (`payments`)
   - แต่ละแถวจะบันทึกลงบัญชีแยกประเภททั่วไป:
     - **เดบิต (Dr)**: บัญชีเงินสด (`ROLE_CASH`) เมื่อวิธีชำระเป็น `'cash'` หรือ บัญชีเงินฝากธนาคาร (`ROLE_BANK`) เมื่อเป็น `'transfer'` หรือ `'cheque'`
     - **เครดิต (Cr)**: บัญชีลูกหนี้การค้า (`ROLE_AR`) ตามยอดเงินของแถวนั้นๆ
   - ผลรวมของเดบิตทุกแถวรับเงิน จะเท่ากับผลรวมของเครดิตลูกหนี้การค้าพอดีถึงระดับสตางค์ (เดบิตรวม = เครดิตรวม = ยอดตัดหนี้รวม)
2. **`CashBookPostingService::postCustomerPayment(Document $document, float $cashAmount)`**:
   - หากในใบเสร็จมียอดชำระด้วยเงินสด (`$totalCashAmount > 0`) ระบบจะเรียกใช้ Helper นี้เพียงครั้งเดียวเพื่อบันทึกยอดเงินสดรวมเข้าสู่สมุดเงินสด (Cash Book)
3. **`Cheque::create(...)`**:
   - หากมีรายการชำระด้วยเช็ค จะบันทึกข้อมูลเข้าสู่ตาราง `cheques` โดยตั้งค่าสถานะเริ่มต้นเป็น `status => 'on_hand'` (เช็คในมือ) และ `direction => 'in'` (เช็ครับ) ผูกกับเอกสารใบเสร็จรับเงินโดยตรง
4. **`PaymentLine`**:
   - บันทึกรายละเอียดการชำระเงินแต่ละวิธีลงในตาราง `payment_lines` โดยกำหนดลำดับ `seq` เริ่มต้นจาก 1 ถึง N เพื่อใช้เป็นหลักฐานและอ้างอิงในการตรวจสอบย้อนหลัง

---

## 5. ผลการทดสอบ (Automated Tests)

### 5.1 เทสต์ใหม่ใน `tests/Feature/ArReceiptMixedPaymentTest.php`
สร้างชุดการทดสอบครอบคลุมเงื่อนไขการทำงาน 10 กรณี:
1. `test_mixed_cash_and_transfer_posts_correct_gl_journals`: ทดสอบรับเงินสด + โอนเงินในใบเดียว ตรวจสอบ GL Journal พบว่าลงบัญชี Dr เงินสด, Dr เงินฝากธนาคาร, และ Cr ลูกหนี้การค้า โดยเดบิตเท่ากับเครดิตถูกต้องสมบูรณ์
2. `test_mixed_cash_transfer_and_cheque_creates_all_records`: ทดสอบรับเงินสด + โอนเงิน + เช็ค ตรวจสอบพบ PaymentLine ครบ 3 รายการ, Cheque ถูกสร้างในสถานะ on_hand, และ GL Journal สมดุล
3. `test_mismatched_payment_sum_and_allocation_sum_is_rejected_and_writes_nothing`: ทดสอบกรณียอดรับชำระไม่เท่ายอดตัดหนี้ ระบบต้องปฏิเสธและไม่บันทึกข้อมูลใดๆ
4. `test_partial_payment_across_multiple_bills`: ทดสอบการชำระบางส่วนหลายบิลพร้อมกัน ยอดคงค้างของบิลถูกลดลงอย่างถูกต้อง
5. `test_legacy_single_payment_without_payments_array_works_identically`: ทดสอบความเข้ากันได้ย้อนหลังเมื่อไม่ส่ง `payments[]`
6. `test_foreign_customer_bill_is_rejected`: ทดสอบปฏิเสธการตัดบิลของลูกค้ารายอื่น
7. `test_overpayment_exceeding_open_item_balance_is_rejected`: ทดสอบปฏิเสธเมื่อยอดชำระเกินยอดหนี้คงค้าง
8. `test_bill_is_closed_when_fully_paid`: ทดสอบการปิดบิล (`status = 'closed'`) เมื่อชำระครบเต็มจำนวน
9. `test_collector_salesman_is_recorded_on_document`: ทดสอบการบันทึกผู้รับเงิน (`salesman_id`) ลงในเอกสาร
10. `test_controller_store_handles_mixed_payments_and_redirects_to_show`: ทดสอบการส่งคำขอผ่าน Controller และการ Redirect ไปยังหน้ารายละเอียดใบเสร็จ

ผลการทดสอบชุดใหม่: **ผ่าน 10 เทสต์ (62 assertions) ภายใน 1.33 วินาที**

### 5.2 ผลการทดสอบทั้งระบบ (PHPUnit Full Suite)
```
Tests: 953, Passed: 951, Skipped: 2, Incomplete: 6, Failures: 0, Errors: 0
Assertions: 8,279
Duration: 36 วินาที
Exit Code: 0
```
ไม่มีผลกระทบข้างเคียง (No Regression) ต่อโมดูลอื่นในระบบแต่อย่างใด

---

## 6. ผลการพิสูจน์ Mutation Testing

เพื่อพิสูจน์ว่าเทสต์ตรวจจับความผิดพลาดได้จริง (Test catches mismatch):

1. **สภาวะกลายพันธุ์ (Mutation - ปิดการตรวจยอดเงินไม่ตรงกันชั่วคราว):**
   ทำการ Comment ส่วนการตรวจสอบผลต่างใน `CustomerPaymentService.php`:
   ```php
   // if (abs($totalPaid - $totalAllocated) > 0.009) { ... }
   ```
   **ผลการรันเทสต์ (RED):**
   ```
   Command exited with code 1.
   {"tool":"phpunit","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":1236,"failed":1,
    "failures":[{"test":"Tests\\Feature\\ArReceiptMixedPaymentTest::test_mismatched_payment_sum_and_allocation_sum_is_rejected_and_writes_nothing",
    "line":249,"message":"Failed asserting that 4 is identical to 3."}]}
   ```
   เทสต์ตรวจจับได้ทันทีและล้มเหลวตามที่คาดหวัง เนื่องจากระบบไม่ได้ปฏิเสธการทำรายการ

2. **สภาวะปกติ (Restored - คืนค่าการตรวจสอบ):**
   ใส่โค้ดตรวจสอบกลับคืน
   **ผลการรันเทสต์ (GREEN):**
   ```
   Command exited with code 0.
   {"tool":"phpunit","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":1173}
   ```
   เทสต์ผ่านสมบูรณ์ 100%

---

## 7. ภาพหน้าจอ (Screenshots)

ภาพหน้าจอทั้งหมดถูกจัดเก็บไว้ใน [`docs/ai/ar-receipt-bplus-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/ar-receipt-bplus-shots/):

1. **หน้าจอสร้างใบเสร็จรับเงิน BPlus (เงินสด + โอนเงิน, ยอดสมดุล 0.00 บาท):**  
   ![01_ar_receipt_bplus_create.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/ar-receipt-bplus-shots/01_ar_receipt_bplus_create.png)

2. **หน้าจอสร้างใบเสร็จรับเงิน BPlus พร้อมระบุรายการเช็ค:**  
   ![02_ar_receipt_bplus_with_cheque.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/ar-receipt-bplus-shots/02_ar_receipt_bplus_with_cheque.png)

3. **กล่องยืนยัน SweetAlert2 ก่อนบันทึกข้อมูล:**  
   ![03_ar_receipt_bplus_confirm_modal.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/ar-receipt-bplus-shots/03_ar_receipt_bplus_confirm_modal.png)
