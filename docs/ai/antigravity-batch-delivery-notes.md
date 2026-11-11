# รายงานการพัฒนา: พิมพ์ใบส่งของหลายใบในครั้งเดียว (Batch Delivery Notes Printing)

> **ภารกิจ:** งานที่ 27 สำหรับ Antigravity  
> **Branch:** `antigravity/batch-delivery-notes` (สร้างจาก `origin/main`)  
> **ขอบเขต:** งานหน้าจอและการพิมพ์ ไม่แตะยอดเงิน สต็อก บัญชี ภาษี หรือสิทธิ์เดิม

---

## 1. ปัญหาเดิมและวัตถุประสงค์

ในกระบวนการจัดส่งสินค้าช่วงเช้าของแต่ละวัน พนักงานจัดของต้องพิมพ์ใบส่งของชั่วคราวหรือใบส่งของทีละใบผ่านปุ่มในหน้ารายละเอียดเอกสาร (`documents.delivery-note`) ซึ่งหากมีเอกสาร 30–50 ใบ จะต้องเปิดทีละแท็บและสั่งพิมพ์ทีละใบ ทำให้เสียเวลามาก

งานนี้จึงพัฒนาระบบ **พิมพ์ใบส่งของรวมในครั้งเดียว (Batch Delivery Notes)** เพื่อให้:
1. เลือกติ๊กหลายใบจากหน้ารายการใบจอง (`bookings/index`) หรือหน้ารายการใบขายสด (`cash-sales/index`) แล้วกดพิมพ์รวมได้ทันที
2. กดพิมพ์ใบส่งของทั้งหมดตามตัวกรองวันที่/สาขา/สายขายจากหน้ารวมจอง (`bookings/summary`) ได้ในคลิกเดียว
3. จัดเรียงเอกสารตามเส้นทางส่งของ: **สายการขาย → ชื่อลูกค้า → เลขที่เอกสาร**
4. โหลดข้อมูลแบบ **Eager Loading 100%** ไม่มี N+1 query เปิดได้รวดเร็ว (< 3 วินาที)
5. ใบส่งของเดี่ยวเดิมยังคงพิมพ์ได้ถูกต้องตามฟอร์แมตกระดาษ A5 แนวนอนเหมือนเดิม 100%

---

## 2. สิ่งที่ได้รับการปรับปรุงและพัฒนา

### 2.1 แยก Partial ใบส่งของ (`resources/views/partials/delivery-note-page.blade.php`)
- สกัดโครงสร้าง `<div class="page delivery-note-page">...</div>` ออกมาเป็น Blade Partial รับตัวแปร:
  - `$document` (Model Document พร้อม relations)
  - `$title` (หัวเอกสาร: 'ใบส่งของชั่วคราว' หรือ 'ใบส่งของ / ใบกำกับสินค้า')
  - `$typeCode` (รหัสประเภทเอกสาร: BOOKING, CASH_SALE, CREDIT_SALE)
  - `$paymentLabel` (ข้อความการชำระเงิน: 'รอแปลงเป็นใบขาย', 'เงินสด', 'เงินเชื่อ')
  - `$copyLabel` (ข้อความระบุฉบับ: 'สำหรับพนักงาน (นำกลับ)', 'สำหรับลูกค้า' หรือ `null`)
- ให้ `resources/views/delivery-note.blade.php` เดิมเรียกใช้ Partial นี้ โดยผลลัพธ์ HTML และสไตล์ A5 แนวนอนยังคงเดิม 100% (ผ่านการทดสอบ `PrintablePageSizeTest` ครบทุกข้อ)

### 2.2 เส้นทางและ Controller สำหรับพิมพ์รวม (`DeliveryNoteController::batch`)
- **Route:** `GET /documents/delivery-notes/batch` (ชื่อ `documents.delivery-notes.batch`)
  - ประกาศไว้ก่อน `/documents/{document}/delivery-note` ใน `routes/web.php`
  - ใช้สิทธิ์ `sales.manage` โดยอัตโนมัติตามกฎของ `RoutePermissions` (`'documents.' => 'sales.manage'`) โดยไม่มีการสร้างสิทธิ์ใหม่
- **การรับข้อมูล:**
  - รองรับ `ids[]` (Primary Key ID หรือ Doc Number) จากการติ๊กเลือกในหน้ารายการ
  - หรือรับตัวกรองจากหน้ารวมจอง: `date`, `branch_id`, `sales_area_id`, `type=booking|sale`
- **การจำกัดสิทธิ์ระดับสาขา (Branch Isolation):**
  - ตรวจสอบสิทธิ์สาขาของผู้ใช้ (`allowedBranchIds` จาก `user_branch_roles` หรือ `branch_id`)
  - ผู้ใช้สาขาอื่นจะไม่เห็นเอกสารของสาขาที่ไม่มีสิทธิ์
  - หากระบุ `branch_id` ที่ตนเองไม่มีสิทธิ์ ระบบจะปฏิเสธด้วย HTTP 403 Forbidden
- **การจำกัดจำนวนไม่เกิน 150 ใบ:**
  - ตรวจสอบจำนวนก่อนโหลดข้อมูล หากเกิน 150 ใบ จะแสดงหน้าแจ้งเตือนพร้อม HTTP 422 Unprocessable Entity แนะนำให้ผู้ใช้แบ่งตัวกรองใหม่
- **การข้ามเอกสารที่ยกเลิกหรือไม่มีสินค้า:**
  - เอกสารที่ยกเลิก (`VoidedDocumentPrint::isVoided`) หรือไม่มีสินค้า (`!$doc->stockDocument || $doc->stockDocument->items->isEmpty()`) จะถูกแยกเข้าสู่รายการ `$skipped`
  - แสดงแถบเตือนสีเหลืองด้านบนหน้าพิมพ์: `"ข้าม N ใบ: เลขที่..."` โดยไม่ทำให้การพิมพ์ทั้งชุดล้มเหลว
- **จำนวนฉบับต่อใบ:**
  - ใบจอง (`BOOKING`): พิมพ์ 2 ฉบับ (สำหรับพนักงานนำกลับ และสำหรับลูกค้า)
  - ใบขาย (`CASH_SALE` / `CREDIT_SALE`): พิมพ์ 1 ฉบับ
- **แถบหัวกระดาษแบบ Dark Bar (`no-print`):**
  - แสดงสรุป: จำนวนใบ, จำนวนหน้ารวมทั้งหมด (A5), ปุ่ม `[🖨️ พิมพ์ทั้งหมด (A5)]` และปุ่มย้อนกลับ

### 2.3 การปรับแต่ง UI หน้าจอ
1. **หน้ารายการใบจอง (`resources/views/bookings/index.blade.php`):**
   - เพิ่มคอลัมน์ Checkbox ต่อแถว
   - เพิ่ม Master Checkbox หัวตาราง "เลือกทั้งหมดในหน้านี้"
   - เพิ่มปุ่ม "พิมพ์ใบส่งของที่เลือก (N)" ที่จะ Disable เมื่อยังไม่เลือก และแสดงจำนวนที่เลือก
   - เมื่อกดจะเปิดหน้าพิมพ์รวมในแท็บใหม่ (`target="_blank"`)
2. **หน้ารวมจอง (`resources/views/bookings/summary.blade.php`):**
   - เพิ่มปุ่ม `"พิมพ์ใบส่งของทั้งหมดตามตัวกรองนี้"` ในแถบเครื่องมือ สอดคล้องกับวันที่ สาขา และสายการขายที่กำลังดูอยู่
3. **หน้ารายการใบขายสด (`resources/views/cash-sales/index.blade.php`):**
   - เพิ่ม Checkbox ต่อแถว + Checkbox เลือกทั้งหมด + ปุ่ม "พิมพ์ใบส่งของที่เลือก (N)" สำหรับใบขายสดเช่นเดียวกัน
   - *หมายเหตุ:* หน้ารายการ `sales.index` ไม่มีในระบบ (มีเฉพาะ `cash-sales.index` และ `sales.show`) จึงทำที่หน้ารายการใบขายสดที่มีอยู่

---

## 3. การทดสอบและการพิสูจน์ (Testing & Verification)

### 3.1 Feature Test: `tests/Feature/BatchDeliveryNotesTest.php`
เขียนเทสต์ครอบคลุมทุกเงื่อนไขตามข้อกำหนด 8 กรณีทดสอบ:
1. `test_batch_delivery_notes_renders_correct_pages_for_bookings_and_sales`: พิมพ์ใบจอง 2 ใบ (2 ฉบับ/ใบ) + ใบขาย 1 ใบ (1 ฉบับ/ใบ) ได้ 5 หน้า A5 ถูกต้อง
2. `test_batch_delivery_notes_skips_voided_and_empty_documents_with_summary`: ข้ามใบที่ยกเลิกและใบไม่มีสินค้า พร้อมขึ้นแถบสรุปเลขที่เอกสารที่ข้าม
3. `test_batch_delivery_notes_rejects_more_than_150_documents_with_message`: ส่งเกิน 150 ใบ ตอบกลับด้วย HTTP 422 พร้อมข้อความเตือนให้แบ่งตัวกรองใหม่
4. `test_batch_delivery_notes_enforces_branch_isolation`: ผู้ใช้สาขา ก ไม่เห็นเอกสารสาขา ข และหากระบุสาขา ข ตรงๆ จะได้รับ 403 Forbidden
5. `test_batch_delivery_notes_requires_sales_manage_permission`: ผู้ใช้ที่ไม่มีสิทธิ์ `sales.manage` (เช่น แคชเชียร์) เข้าใช้งานไม่ได้ (403 Forbidden)
6. `test_batch_delivery_notes_filter_by_date_and_type`: กรองตามวันที่ สาขา และประเภทเอกสารได้อย่างถูกต้อง
7. `test_query_count_does_not_grow_with_document_count`: จำนวน query ไม่โตตามจำนวนเอกสาร (ทดสอบเปรียบเทียบ 5 ใบ กับ 50 ใบ)
8. `test_single_delivery_note_remains_unchanged`: หน้าพิมพ์ใบเดี่ยวเดิม (`documents.delivery-note`) ยังคงทำงานได้ตามปกติและแสดงผลเหมือนเดิม

ผลการรัน:
```bash
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/BatchDeliveryNotesTest.php
# Result: OK (8 tests, 36 assertions)
```

### 3.2 การพิสูจน์ Mutation Testing (ถอด Eager Load ออกชั่วคราว)
ตามข้อกำหนดข้อ 29 ได้ทำการพิสูจน์ว่าเทสต์จับ N+1 Query ได้จริง:
- **เมื่อถอด Eager Load ออก (`$documents = $query->get();`):**
  - จำนวน query สำหรับ 5 ใบ: **68 queries**
  - จำนวน query สำหรับ 50 ใบ: **653 queries**
  - ส่วนต่าง query: **585 queries** (เกินเกณฑ์ที่กำหนดไว้ไม่เกิน 3)
  - ผลลัพธ์: **เทสต์ RED (FAILED) ทันที** ดังข้อความ:
    ```
    Query count difference between 5 docs (68 queries) and 50 docs (653 queries) must be <= 3, got 585
    Failed asserting that 585 is equal to 3 or is less than 3.
    ```
- **เมื่อใส่ Eager Load กลับคืน:**
  - ส่วนต่าง query ระหว่าง 5 ใบ และ 50 ใบ: **0 queries**
  - ผลลัพธ์: **เทสต์ GREEN (PASSED) ทันที**

### 3.3 ผลการรันชุดทดสอบทั้งระบบ
```bash
php -d memory_limit=1G vendor/bin/phpunit
# Result: 949 passed, 0 failed, 2 skipped, 6 incomplete (8,259 assertions)
```
ชุดทดสอบทั้งระบบ 949 เทสต์ผ่านทั้งหมด 100%

---

## 4. ภาพหน้าจอ (Screenshots)

รูปภาพถูกจัดเก็บไว้ในโฟลเดอร์ [`docs/ai/batch-delivery-note-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/batch-delivery-note-shots/):

1. **หน้ารายการใบจองที่มีการติ๊กเลือกและปุ่มพิมพ์พร้อมใช้งาน:**  
   ![01_booking_index_selected.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/batch-delivery-note-shots/01_booking_index_selected.png)
   - แสดงช่องติ๊กด้านหน้าแถว, ไฮไลต์แถวที่เลือก, และปุ่ม `[🖨️ พิมพ์ใบส่งของที่เลือก (3)]`

2. **หน้ารวมจองพร้อมปุ่มพิมพ์ตามตัวกรอง:**  
   ![02_booking_summary_filter_print.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/batch-delivery-note-shots/02_booking_summary_filter_print.png)
   - แสดงปุ่มสีเขียว `[🚚 พิมพ์ใบส่งของทั้งหมดตามตัวกรองนี้]` เชื่อมต่อไปยังตัวกรองปัจจุบัน

3. **หน้าพิมพ์รวมตัวอย่าง 5 ใบพร้อมแถบสรุปหัวกระดาษ:**  
   ![03_batch_delivery_notes_5_docs.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/batch-delivery-note-shots/03_batch_delivery_notes_5_docs.png)
   - แสดงแถบ Dark Bar ด้านบน: "จำนวน 5 ใบ", "จำนวน 9 หน้า", ปุ่ม `[🖨️ พิมพ์ทั้งหมด (A5)]`
   - แสดงแถบเตือนสีเหลืองด้านบน: "ข้าม 1 ใบ: เลขที่ BK-202611-0004 (ยกเลิกแล้วหรือไม่มีรายการสินค้า)"
   - แสดงตัวอย่างการวางหน้า A5 แนวนอนพร้อมสำหรับสั่งพิมพ์ต่อเนื่อง

---

## 5. สรุปไฟล์ที่มีการเปลี่ยนแปลง

| ไฟล์ | ลักษณะการเปลี่ยนแปลง |
|---|---|
| `app/Http/Controllers/DeliveryNoteController.php` | เพิ่มเมธอด `batch()` สำหรับพิมพ์รวม พร้อม Eager load, Sort, Filter, Skip, Limit 150 |
| `resources/views/partials/delivery-note-page.blade.php` | สร้าง Partial เนื้อหาใบส่งของ A5 กลาง เพื่อใช้ร่วมกัน |
| `resources/views/delivery-note.blade.php` | ปรับให้เรียกใช้ `partials.delivery-note-page` โดยคงสไตล์และผลลัพธ์เดิม |
| `resources/views/documents/batch-delivery-notes.blade.php` | สร้าง View หน้าพิมพ์รวม แสดงหัว dark bar, แถบข้ามเอกสาร, วนลูปฉบับ |
| `resources/views/documents/batch-limit-exceeded.blade.php` | สร้าง View แจ้งเตือนเมื่อเอกสารเกิน 150 ใบ (HTTP 422) |
| `resources/views/bookings/index.blade.php` | เพิ่มช่องติ๊กเลือก, Master Checkbox, และปุ่มพิมพ์ใบส่งของที่เลือก |
| `resources/views/bookings/summary.blade.php` | เพิ่มปุ่ม "พิมพ์ใบส่งของทั้งหมดตามตัวกรองนี้" |
| `resources/views/cash-sales/index.blade.php` | เพิ่มช่องติ๊กเลือก, Master Checkbox, และปุ่มพิมพ์ใบส่งของที่เลือก |
| `routes/web.php` | เพิ่ม route `documents.delivery-notes.batch` |
| `tests/Feature/BatchDeliveryNotesTest.php` | สร้างชุดทดสอบ Feature Test 8 กรณีทดสอบ |
| `docs/ai/batch-delivery-note-shots/*` | บันทึกภาพหน้าจอผลลัพธ์การทำงาน |
