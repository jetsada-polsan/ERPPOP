# รายงานการพัฒนางานที่ 28 (ฉบับสมบูรณ์): ระบบใบเสร็จรับเงินลูกหนี้เอกสารเดียว (BPlus Single Receipt Integration)

> **Branch**: `antigravity/ar-receipt` (แตกจาก `origin/main`)  
> **สถานะ**: พัฒนาเสร็จสมบูรณ์, ผ่าน Golden Test ครบ 14 เคส (73 assertions), ผ่านชุดทดสอบทั้งระบบ 957 เคส, ผ่านการทดสอบ Mutation Testing ครบทั้ง 2 ด้าน  
> **รายงานโดย**: Antigravity Pair Programming Agent  
> **วันที่บันทึก**: 11 พฤศจิกายน 2569  

---

## 1. บทสรุปการตัดสินใจและการออกแบบระบบ (Owner Decision & Architecture)

ตามคำสั่งงานฉบับปรับปรุงของเจ้าของระบบ:
1. **เอกสารเดียว เลขที่ชุดเดียว ไม่แยกเล่ม**: ยกเลิกแนวคิดการแยกเล่ม RR1–RR12 ของ BPlus เดิม โดยใช้เลขที่เอกสารชุดเดียวตามระบบเดิมของ ERP (`numbers->next('RECEIPT', branchId)`) เพื่อป้องกันความสับสนในการคุมสต็อกกระดาษและเลขที่เอกสารทางภาษี
2. **รับชำระได้หลายวิธีพร้อมกันในใบเดียว**: ในใบเสร็จรับเงิน 1 ใบ สามารถตัดชำระหลายบิล และรับเงินผสมกันได้ทั้ง **เงินสด**, **โอนเงินผ่านธนาคาร** (ระบุบัญชีธนาคาร + เลขที่อ้างอิงสลิป + วันเวลาโอน), และ **เช็ครับ** (ระบุธนาคาร + เลขที่เช็ค + วันที่หน้าเช็ค)
3. **รองรับภาษีหัก ณ ที่จ่าย (Withholding Tax - ฝั่งลูกหนี้) ตามหลักสากล**:
   - ลูกหนี้หักภาษีจากเรา ถือเป็น **สินทรัพย์หมุนเวียน (ภาษีเงินได้ถูกหัก ณ ที่จ่ายรอนำไปเครดิตภาษีสิ้นปี)** ไม่ใช่เจ้าหนี้
   - คำนวณฐานภาษีอัตโนมัติจาก **มูลค่าก่อน VAT** ตามสัดส่วนของยอดตัดหนี้
   - ผู้ใช้สามารถแก้ไขจำนวนเงินภาษีหัก ณ ที่จ่ายได้เองตามหนังสือรับรองจริงที่ลูกค้าออกให้ พร้อมบันทึกเลขที่และวันที่หนังสือรับรอง หงด.
4. **รองรับส่วนลดเงินสด (Cash Discount / ส่วนลดจ่ายชำระเร็ว)**:
   - บันทึกส่วนลดเงินสดแยกตามแถวบิลเพื่อตัดลดยอดหนี้คงค้างเต็มจำนวน
   - ไม่ปรับปรุงภาษีขายของบิลเดิมตามหลักเกณฑ์ภาษีมูลค่าเพิ่ม
5. **รายงานสรุปรับเงินรายวัน (Daily AR Summary Report)**:
   - ออกแบบหน้าจอใหม่ `/ar-receipts/daily-summary` เพื่อทดแทนระบบเล่มแยกของ BPlus
   - สรุปยอดตามมิติ **ผู้รับเงิน (Salesman) × วิธีชำระ (Cash/Transfer/Cheque)** พร้อมส่งออก CSV
6. **การยกเลิกใบเสร็จ (Void Integrity)**:
   - ย้อนคืนยอดหนี้ค้างชำระ (`customer_open_items`) ครบถ้วนทุกบาททุกสตางค์ รวมถึงส่วนลดและหัก ณ ที่จ่าย
   - กลับรายการสมุดรายวันทั่วไป (GL Journal) ทุกแถว และยกเลิกเช็ครับในทะเบียนเช็ค

---

## 2. การเรียกใช้โค้ดบัญชีเดิมซ้ำ (Reused Accounting & Posting Helpers)

เพื่อรักษาเสถียรภาพและความสอดคล้องของระบบบัญชี ERP ไม่มีการเขียนตรรกะบัญชีขึ้นมาใหม่จากศูนย์ แต่ใช้และต่อยอด Helper เดิม ดังนี้:

| โมดูล / Helper เดิม | วัตถุประสงค์และการนำมาใช้งาน |
|---|---|
| `App\Services\Accounting\GlPostingService::postCustomerReceipt()` | นำมาวนลูปเรียกใช้บันทึก GL สำหรับแถวการรับชำระเงินแต่ละวิธี (Dr. เงินสด / Dr. เงินฝากธนาคาร และ Cr. ลูกหนี้การค้า) |
| `App\Services\Accounting\GlPostingService::postCustomerWhtDeduction()` | เมธอดใหม่บน `GlPostingService` ตามแพตเทิร์นเดิม: Dr. ภาษีเงินได้ถูกหัก ณ ที่จ่าย (`ROLE_WHT_RECEIVABLE`) และ Cr. ลูกหนี้การค้า (`ROLE_AR`) |
| `App\Services\Accounting\GlPostingService::postCustomerDiscount()` | เมธอดใหม่บน `GlPostingService` ตามแพตเทิร์นเดิม: Dr. รับคืนและส่วนลดจ่าย (`ROLE_SALES_RETURN`) และ Cr. ลูกหนี้การค้า (`ROLE_AR`) |
| `App\Services\Accounting\CashBookPostingService::postCustomerPayment()` | บันทึกรับชำระเงินสดเข้าสมุดเงินสดประจำสาขา/หน่วยรถโดยอัตโนมัติ |
| `App\Models\Cheque::create()` | บันทึกเช็ครับเข้าทะเบียนเช็คของบริษัท สถานะ `on_hand` เพื่อรอการนำฝากเข้าธนาคาร |
| `App\Services\Accounting\DocumentVoidService::voidReceipt()` | ใช้ตรรกะการยกเลิกเดิม โดยคืนยอดค้าง `balance_amount += allocated_amount` (ยอดตัดหนี้รวมลดและหักภาษี) และกลับรายการ GL ผ่าน `reversePaymentDocument()` |

---

## 3. ข้อมูลบัญชีภาษีหัก ณ ที่จ่าย (`ROLE_WHT_RECEIVABLE`)

เพื่อรองรับการเดบิตภาษีเงินได้ถูกหัก ณ ที่จ่าย ได้มีการกำหนดบทบาทบัญชีใหม่ในโมเดล `ChartOfAccount`:

- **บทบาทบัญชี (Role Constant)**: `ChartOfAccount::ROLE_WHT_RECEIVABLE = 'wht_receivable'`
- **ชื่อบทบาทภาษาไทย (ROLE_LABELS)**: `'ภาษีเงินได้ถูกหัก ณ ที่จ่าย (สินทรัพย์)'`
- **รหัสบัญชีที่ผูก**: `1160`
- **ชื่อบัญชีที่ผูก**: `ภาษีเงินได้ถูกหัก ณ ที่จ่าย` (หมวด 1 สินทรัพย์ / บัญชีย่อยของสินทรัพย์หมุนเวียนอื่น)
- **Migration**: `database/migrations/2026_11_11_120000_add_wht_receivable_and_cert_columns.php`
  - ตรวจสอบผังบัญชีก่อน หากมีบัญชีที่มีชื่อหรือรหัสทำนองนี้อยู่แล้ว จะผูก `default_role => 'wht_receivable'` ให้ทันทีโดยไม่สร้างซ้ำ
  - หากยังไม่มี จะสร้างบัญชีรหัส `1160` ขึ้นใหม่โดยอัตโนมัติ
  - เพิ่มคอลัมน์ `wht_cert_no` และ `wht_cert_date` ในตาราง `payment_documents`

> [!IMPORTANT]
> **หมายเหตุสำหรับเจ้าของระบบเพื่อยืนยันกับผู้ทำบัญชี / ผู้สอบบัญชี (CPA)**:  
> ขอให้ฝ่ายบัญชียืนยันรหัสบัญชี `1160` (ภาษีเงินได้ถูกหัก ณ ที่จ่าย) ว่าสอดคล้องกับผังบัญชีหลักที่บริษัทใช้งานอยู่ในปัจจุบันหรือไม่ หากบริษัทใช้รหัสอื่น (เช่น `1154` หรือ `1190`) สามารถแก้ไขการผูกบทบาทบัญชีได้ในหน้าตั้งค่าผังบัญชี

---

## 4. ข้อสมมติทางบัญชีเรื่องภาษีมูลค่าเพิ่มสำหรับส่วนลดเงินสด (VAT on Cash Discount)

> [!NOTE]
> **ข้อสมมติทางบัญชี (Accounting Assumption)**:  
> ส่วนลดเงินสด (Cash Discount หรือส่วนลดจ่ายชำระเร็ว เช่น จ่ายภายใน 15 วันลด 2%) เป็น **ส่วนลดทางการเงิน (Financial Discount / Financing Cost)** ที่เกิดขึ้นภายหลังจากที่มีการส่งมอบสินค้าและออกใบกำกับภาษีไปแล้ว
> 
> ตาม**ประมวลรัษฎากร มาตรา 79(4)** และแนวปฏิบัติของกรมสรรพากร:
> - ส่วนลดที่ให้ภายหลังจากออกใบกำกับภาษี ไม่ถือเป็นส่วนลดการค้า จึง**ไม่สามารถนำมาลดยอดมูลค่าของฐานภาษีขายที่ได้ยื่นแบบ ภ.พ.30 ไปแล้วได้** เว้นแต่จะมีการออกใบลดหนี้ (Credit Note) ตามมาตรา 86/10
> - ในระบบใบเสร็จรับเงินนี้ ส่วนลดเงินสดจึงถูกบันทึกเป็นค่าใช้จ่าย/รายการปรับลดรายได้ในบัญชี **"รับคืนและส่วนลดจ่าย" (รหัส 4020, `ROLE_SALES_RETURN`) เต็มจำนวน โดยไม่มีการปรับปรุงหรือแตะต้องภาษีขายเดิม**
> 
> **สมการบัญชี (Accounting Entry per Receipt)**:
> ```text
> Dr. เงินสด (1111) / เงินฝากธนาคาร (1112)       [ตามจำนวนเงินที่รับจริง]
> Dr. ภาษีเงินได้ถูกหัก ณ ที่จ่าย (1160)             [ตามยอด WHT ที่ถูกหัก]
> Dr. รับคืนและส่วนลดจ่าย (4020)                   [ตามยอดส่วนลดเงินสด]
>     Cr. ลูกหนี้การค้า (1130)                                    [เท่ายอดตัดหนี้เต็มจำนวน]
> (เดบิตรวม = เครดิตรวม ลงตัวถึงสตางค์)
> ```

---

## 5. ผลการทดสอบ Mutation Testing (พิสูจน์ว่าเทสต์จับบั๊กได้จริง)

เพื่อให้มั่นใจว่าชุดทดสอบไม่ได้ผ่านเพียงแค่ฉากหน้า (False Positive) ได้มีการจงใจแก้โค้ดให้ผิด 2 จุด และรันชุดทดสอบเพื่อพิสูจน์:

### (ก) การทดสอบปลดตัวตรวจผลรวมเงินรับชำระ (Disable Payment Sum Validation)
- **จุดที่แก้**: ใน `CustomerPaymentService.php` ปิดเงื่อนไข `abs($totalPaid - $totalNet) > 0.009` ให้ปล่อยผ่านแม้เงินที่รับจริงจะไม่ตรงกับยอดสุทธิที่ต้องรับ
- **ผลการทดสอบ (RED Output)**:
  ```text
  $ php -d memory_limit=1G vendor/bin/phpunit --filter=test_mismatched_payment_sum_and_net_to_receive_is_rejected_and_writes_nothing
  F                                                                   1 / 1 (100%)

  There was 1 failure:

  1) Tests\Feature\ArReceiptMultiPaymentTest::test_mismatched_payment_sum_and_net_to_receive_is_rejected_and_writes_nothing
  Failed asserting that 4 is identical to 3.

  /Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/ArReceiptMultiPaymentTest.php:437

  FAILURES!
  Tests: 1, Assertions: 4, Failures: 1.
  ```
- **การคืนโค้ด (GREEN Output)**: นำโค้ดตรวจสอบกลับมา รันซ้ำผ่าน 100% (Passed: 1, Assertions: 4)

---

### (ข) การทดสอบทำให้การยกเลิกใบเสร็จไม่คืนส่วนลด (Disable Restoring Discount on Void)
- **จุดที่แก้**: ใน `DocumentVoidService.php` แก้ไขบรรทัดคืนยอดค้างชำระ โดยหักส่วนลดเงินสดทิ้ง:
  `$item->balance_amount += ($alloc->allocated_amount - $alloc->discount_amount);` (แทนที่จะคืน `allocated_amount` เต็มจำนวน)
- **ผลการทดสอบ (RED Output)**:
  ```text
  $ php -d memory_limit=1G vendor/bin/phpunit --filter=test_void_receipt_restores_open_items_and_reverses_gl_journals
  F                                                                   1 / 1 (100%)

  There was 1 failure:

  1) Tests\Feature\ArReceiptMultiPaymentTest::test_void_receipt_restores_open_items_and_reverses_gl_journals
  Failed asserting that 2800.0 matches expected 3000.0.

  /Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/ArReceiptMultiPaymentTest.php:547

  FAILURES!
  Tests: 1, Assertions: 11, Failures: 1.
  ```
- **การคืนโค้ด (GREEN Output)**: นำโค้ดคืนยอดหนี้เดิมกลับมา รันซ้ำผ่าน 100% (Passed: 1, Assertions: 11)

---

## 6. สรุปผลการทดสอบอัตโนมัติ (Automated Test Suite)

### ชุดทดสอบเฉพาะฟังก์ชัน: `tests/Feature/ArReceiptMultiPaymentTest.php`
ครอบคลุม 14 กรณีทดสอบที่ระบุในข้อกำหนดอย่างสมบูรณ์:
1. `test_mixed_cash_and_transfer_posts_correct_gl_journals`: รับเงินสด + โอนเงิน ลงบัญชีเดบิตเท่ากับเครดิตถูกต้อง
2. `test_mixed_cash_transfer_and_cheque_creates_all_records`: รับเงินสด + โอนเงิน + เช็ครับ บันทึกครบทุกตารางรวมถึงทะเบียนเช็ค
3. `test_withholding_tax_with_vat_proportion_and_posts_correct_gl`: คำนวณหัก ณ ที่จ่าย 3% บนฐานก่อน VAT 7% ปัดเศษทศนิยม 2 ตำแหน่ง ลงบัญชี Dr. สินทรัพย์ภาษีหัก ณ ที่จ่าย ถูกต้อง
4. `test_cash_discount_posts_to_sales_return_gl`: ส่วนลดเงินสดลงบัญชี Dr. รับคืนและส่วนลดจ่าย (`ROLE_SALES_RETURN`) และ Cr. ลูกหนี้การค้า
5. `test_both_wht_and_discount_with_multiple_payment_methods`: รวมทั้งหัก ณ ที่จ่าย, ส่วนลดเงินสด และวิธีชำระหลายวิธีในใบเดียว ยอดเงินและ GL ดุลกันสมบูรณ์
6. `test_partial_payment_across_multiple_bills_closes_when_fully_paid`: ชำระหนี้บางส่วนหลายบิล และตัดบิลปิดสถานะเมื่อยอดคงค้างเป็น 0
7. `test_mismatched_payment_sum_and_net_to_receive_is_rejected_and_writes_nothing`: ยอดรับเงินไม่ตรงกับยอดสุทธิที่ต้องรับ ระบบปฏิเสธทั้ง Transaction ไม่มีการเขียนข้อมูลใดๆ
8. `test_deductions_exceeding_allocated_amount_is_rejected`: ผลรวมหัก ณ ที่จ่ายและส่วนลดเกินยอดตัดหนี้ ถูกปฏิเสธ
9. `test_foreign_customer_bill_is_rejected`: พยายามตัดบิลของลูกค้าคนอื่น ถูกปฏิเสธ
10. `test_missing_wht_receivable_account_is_rejected_with_clear_message`: กรณีไม่มีการตั้งค่าผังบัญชี `wht_receivable` ระบบปฏิเสธพร้อมแจ้งข้อความชัดเจน ไม่มีการเดาบัญชี
11. `test_void_receipt_restores_open_items_and_reverses_gl_journals`: การยกเลิกใบเสร็จคืนยอดหนี้คงค้างและกลับรายการบัญชีทุกบรรทัดกลับสู่สถานะเดิม 100%
12. `test_legacy_single_payment_without_payments_array_works_identically`: ฟังก์ชันรับชำระเงินเดิมผ่านหน้าจออื่นๆ ที่ไม่ส่งอาเรย์ payments ยังคงทำงานได้เหมือนเดิม 100%
13. `test_daily_summary_report_counts_correctly_and_excludes_voided`: รายงานสรุปรับเงินรายวันสรุปยอดแยกผู้รับเงิน × วิธีชำระถูกต้อง และไม่นำใบเสร็จที่ถูกยกเลิกมาคำนวณ
14. `test_collector_salesman_is_recorded_and_displayed`: ข้อมูลผู้รับเงิน (`salesman_id`) ถูกบันทึกและส่งต่อไปยังหน้าพิมพ์ใบเสร็จ

```text
$ php -d memory_limit=1G vendor/bin/phpunit tests/Feature/ArReceiptMultiPaymentTest.php
................                                                  14 / 14 (100%)

Time: 00:01.783, Memory: 42.00 MB
OK (14 tests, 73 assertions)
```

### การทดสอบทั้งระบบ ERP (Full Regression Test)
- รันชุดทดสอบทั้งหมด 957 เคส ผ่านทั้งหมด ไม่พบข้อผิดพลาดหรือผลกระทบข้างเคียง:
```text
OK (957 tests, 3354 assertions)
```

---

## 7. ภาพหน้าจอการทำงาน (Screenshots)

รูปภาพถูกบันทึกไว้ในไดเรกทอรี `docs/ai/ar-receipt-shots/`:

1. **หน้าสร้างใบเสร็จรับเงินแบบหน้าเดียว (BPlus Style)**  
   `docs/ai/ar-receipt-shots/01_ar_receipt_create_wht.png`  
   แสดงการเลือกลูกค้า, ตารางบิลค้างพร้อมช่องยอดตัดหนี้, ส่วนลดเงินสด, % และยอดภาษีหัก ณ ที่จ่าย, ช่องทางรับชำระผสมเงินสดและโอนเงิน, และแผงสรุปยอดคงเหลือสุทธิ

2. **ใบพิมพ์ใบเสร็จรับเงิน A4 ทางการ (Print Template)**  
   `docs/ai/ar-receipt-shots/02_ar_receipt_print.png`  
   แสดงข้อมูลผู้รับเงิน (Salesman), เลขที่หนังสือรับรอง หงด., ตารางแสดงช่องทางการรับชำระ, ตารางรายละเอียดการตัดบิลพร้อมยอดตัดหนี้ ส่วนลด และภาษีหัก ณ ที่จ่าย, ข้อความตัวอักษรบาทถ้วน และช่องลงนาม

3. **รายงานสรุปรับเงินรายวัน (Daily AR Summary Report)**  
   `docs/ai/ar-receipt-shots/03_ar_receipt_daily_summary.png`  
   แสดงตารางสรุปยอดแยกตามผู้รับเงิน × วิธีชำระเงิน (เงินสด/โอน/เช็ค), จำนวนใบ, ยอดรับจริง, ส่วนลด, หัก ณ ที่จ่าย, ยอดตัดหนี้รวม พร้อมปุ่มส่งออก CSV

---

## 8. รายการไฟล์ที่ถูกเพิ่มและแก้ไขใน Git Branch `antigravity/ar-receipt`

```text
modified:   app/Http/Controllers/ArReceiptController.php
modified:   app/Models/ChartOfAccount.php
modified:   app/Models/PaymentDocument.php
modified:   app/Services/Accounting/GlPostingService.php
modified:   app/Services/Sales/CustomerPaymentService.php
modified:   resources/views/ar-receipts/create.blade.php
modified:   resources/views/ar-receipts/index.blade.php
modified:   resources/views/payments/print.blade.php
modified:   resources/views/payments/show.blade.php
modified:   routes/web.php
untracked:  database/migrations/2026_11_11_120000_add_wht_receivable_and_cert_columns.php
untracked:  resources/views/ar-receipts/daily-summary.blade.php
untracked:  tests/Feature/ArReceiptMultiPaymentTest.php
untracked:  docs/ai/ar-receipt-shots/01_ar_receipt_create_wht.png
untracked:  docs/ai/ar-receipt-shots/02_ar_receipt_print.png
untracked:  docs/ai/ar-receipt-shots/03_ar_receipt_daily_summary.png
untracked:  docs/ai/antigravity-ar-receipt.md
```
