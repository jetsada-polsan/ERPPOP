# สรุปผลงานที่ 10: เอกสารพิมพ์ — แก้หัวสีฟ้าบังตัวหนังสือ + ห้ามพิมพ์เอกสารที่ยกเลิกแล้ว

**Branch:** `antigravity/erp-print`  
**สถานะ:** เสร็จสมบูรณ์ (Completed)  
**ผลทดสอบ PHPUnit:** ผ่านครบทั้งหมด 876 เทสต์ (876 passed, 7,790 assertions, 0 failures, 0 errors)

---

## 1. การสำรวจและวิเคราะห์สาเหตุ (Root Cause Analysis)

### ปัญหาที่ 1: แถบสีฟ้าบังตัวหนังสือที่หัวเอกสาร
จากการตรวจสอบโค้ดใน `resources/views/documents/partials/print-theme.blade.php` และ `resources/views/layouts/print.blade.php`:
1. **สาเหตุหลัก:** ใน `print-theme.blade.php` มีการประกาศ pseudo-element สามเหลี่ยมมุมขวาบน:
   ```css
   .sheet::before, .page::before, .print-sheet::before {
       position: absolute; top: 0; right: 0;
       border-top: 28mm solid var(--doc-blue);
       border-left: 28mm solid transparent;
       content: ""; ...
   }
   .sheet::after, .page::after, .print-sheet::after {
       position: absolute; top: 3mm; right: 4mm;
       content: "1"; color: #fff; ...
   }
   ```
   สามเหลี่ยมสีฟ้านี้มีขนาดกว้างและสูงถึง **28 มิลลิเมตร (ประมาณ 106 พิกเซล)** ทับลงบนส่วนขวาบนของเอกสารโดยตรง ซึ่งเป็นตำแหน่งของ `.doc-title`, `.doc-meta`, ชื่อเอกสาร, เลขที่เอกสาร และวันที่ ทำให้ตัวหนังสือหัวเอกสารบางส่วนถูกแถบสีฟ้าบัง ทั้งบนหน้าจอและในกระดาษพิมพ์จริง
2. **การชดเชยที่บิดเบือน:** มีการใส่ `padding-right: 36px` ไว้ใน `.doc-title` เพื่อหลบสามเหลี่ยมสีฟ้า ทำให้ข้อความหัวเอกสารบีบตัวและเยื้องไม่ตรงแนว
3. **การพิมพ์เปลืองหมึก:** หัวตารางเดิมและแถบตกแต่งมีพื้นหลังสีฟ้าทึบ (`var(--doc-blue)`) ทำให้สิ้นเปลืองหมึกพิมพ์โดยไม่จำเป็น

---

## 2. รายละเอียดการแก้ไข (Implementation Details)

### ขั้นที่ 2: แก้ไขหัวเอกสารและการแสดงผล
- **ตัดองค์ประกอบตกแต่งที่บังเนื้อหา:**
  - กำหนดให้ `.sheet::before`, `.sheet::after`, `.page::before`, `.page::after`, `.print-sheet::before`, `.print-sheet::after` และคลาส `.ribbon` ทั้งหมดเป็น `display: none !important; content: none !important; border: none !important;` ทั้งบนจอและกระดาษพิมพ์
  - นำ `padding-right: 36px` ออกจาก `.doc-title` เพื่อให้หัวเอกสารฝั่งขวาชิดขอบและจัดวางได้สวยงาม
  - ยืนยันแถบเครื่องมือบนจอ (`.toolbar`, `.no-print`) ไม่ลอยทับหัวกระดาษ และซ่อนอย่างเด็ดขาด (`display: none !important; height: 0 !important;`) ใน `@media print`
  - **คง `@page size` เดิมทุกจุดอย่างเคร่งครัด** ไม่มีการปรับขนาดหน้าของเอกสารใดๆ

### ขั้นที่ 3: ระบบล็อกการพิมพ์เอกสารที่ยกเลิกแล้ว (Voided Document Print Guard)
- **สร้าง Helper สำหรับป้องกันการพิมพ์:** `app/Support/VoidedDocumentPrint.php`
  - ตรวจสอบสถานะการยกเลิกจาก `$document->status === 'cancelled'` หรือ `'void'`, หรือจากเอกสารที่เกี่ยวข้อง (เช่น `paymentDocument->status`, `saleBooking->status`)
  - หากเอกสารถูกยกเลิก จะส่งคืน HTTP 410 (Gone) พร้อมเรนเดอร์วิว `resources/views/documents/voided-print.blade.php`
- **หน้าแจ้งเตือนภาษาไทย:** `resources/views/documents/voided-print.blade.php`
  - แสดงข้อความชัดเจน: *"เอกสาร {เลขที่} ถูกยกเลิกแล้ว พิมพ์ไม่ได้"*
  - พร้อมปุ่มย้อนกลับไปยังหน้ารายการเอกสาร
  - มีประกาศ `@page { size: A4 portrait; margin: 15mm; }` เพื่อให้สอดคล้องกับมาตรฐานการพิมพ์
- **ป้องกันที่ Controller ทุกจุดที่พิมพ์ได้ (ป้องกันการเปิด URL ตรง):**
  - `PaymentController::print` (`payments.print`)
  - `DeliveryNoteController::show` (`documents.delivery-note`)
  - `DeliveryNoteController::taxInvoice` (`documents.tax-invoice`)
  - `SaleController::print` (`sales.print`)
  - `CreditDebitNoteController::print` (`credit-debit-notes.print`)
  - ปรับ Return Type Hint เป็น `Response|View` เพื่อรองรับทั้ง Response 410 และ View ปกติ
- **ซ่อนปุ่มพิมพ์ในหน้าแสดงผล (Show Views):**
  - ซ่อนปุ่มพิมพ์เมื่อสถานะเป็น `cancelled` หรือ `void` ในหน้า:
    - `resources/views/sales/show.blade.php`
    - `resources/views/payments/show.blade.php`
    - `resources/views/cash-sales/show.blade.php`
    - `resources/views/sale-returns/show.blade.php`
    - `resources/views/bookings/show.blade.php`

### ขั้นที่ 4: ฟอร์มพิมพ์สวย ตัวหนังสือคมชัด ประหยัดหมึก
- **แบบอักษร:** นำเข้าฟอนต์ Google Fonts `IBM Plex Sans Thai` ใน `resources/views/layouts/print.blade.php` และกำหนด `font-family: 'IBM Plex Sans Thai', 'Sarabun', Tahoma, sans-serif;` ในทั้ง layout และ print-theme
- **ขนาดและความคมชัดของตัวอักษร:**
  - ตัวหนังสือเนื้อหาบนกระดาษ: สีดำ `#000` ขนาด $\ge 11\text{pt}$
  - หัวตาราง: ขนาด $10.5\text{pt}$ ตัวหนา ขอบตารางสีดำคมชัด $0.75\text{pt}$ ไม่ใช้พื้นหลังสีเข้มทึบ (ประหยัดหมึกพิมพ์)
  - ตัวเลขและจำนวนเงิน: ชิดขวา พร้อมใช้ `font-variant-numeric: tabular-nums` หลักเลขตรงกันในแนวตั้ง
  - ยอดรวม / ยอดสุทธิ: ขนาด $\ge 13.5\text{pt}$ ตัวหนา พร้อมเส้นขีดคู่ด้านล่าง
  - ชื่อเอกสาร (ใบเสร็จรับเงิน, ใบกำกับภาษี, ใบส่งของ ฯลฯ): ขนาด $18\text{pt}$ ตัวหนา เด่นชัด

---

## 3. ไฟล์ที่แก้ไขและสร้างใหม่

| หมวดหมู่ | รายการไฟล์ | การเปลี่ยนแปลง |
|---|---|---|
| **สร้างใหม่** | `app/Support/VoidedDocumentPrint.php` | คลาสตรวจสอบสถานะยกเลิกและปฏิเสธคำขอพิมพ์ด้วย HTTP 410 |
| **สร้างใหม่** | `resources/views/documents/voided-print.blade.php` | วิวแจ้งเตือนภาษาไทยเมื่อพยายามพิมพ์เอกสารที่ยกเลิกแล้ว |
| **สร้างใหม่** | `tests/Feature/VoidedDocumentPrintTest.php` | เทสต์ชุดใหม่ 4 เคส ครอบคลุมการล็อกพิมพ์เอกสารยกเลิก |
| **Controllers** | `app/Http/Controllers/PaymentController.php` | เพิ่มการตรวจ `VoidedDocumentPrint::deny()` ในเมธอด `print()` |
| **Controllers** | `app/Http/Controllers/DeliveryNoteController.php` | เพิ่มการตรวจ `VoidedDocumentPrint::deny()` ใน `show()` และ `taxInvoice()` |
| **Controllers** | `app/Http/Controllers/SaleController.php` | เพิ่มการตรวจ `VoidedDocumentPrint::deny()` ใน `print()` |
| **Controllers** | `app/Http/Controllers/CreditDebitNoteController.php` | เพิ่มการตรวจ `VoidedDocumentPrint::deny()` ใน `print()` |
| **Views - Print** | `resources/views/layouts/print.blade.php` | โหลด IBM Plex Sans Thai font, ปรับฟอนต์หลัก |
| **Views - Print** | `resources/views/documents/partials/print-theme.blade.php` | ลบสามเหลี่ยมสีฟ้า, ปรับสีดำ `#000`, ขนาดฟอนต์ $\ge 11\text{pt}$, tabular-nums |
| **Views - Show** | `resources/views/sales/show.blade.php` | ซ่อนปุ่มพิมพ์เมื่อสถานะเป็นยกเลิก |
| **Views - Show** | `resources/views/payments/show.blade.php` | ซ่อนปุ่มพิมพ์เมื่อสถานะเป็นยกเลิก |
| **Views - Show** | `resources/views/cash-sales/show.blade.php` | ซ่อนปุ่มพิมพ์เมื่อสถานะเป็นยกเลิก |
| **Views - Show** | `resources/views/sale-returns/show.blade.php` | ซ่อนปุ่มพิมพ์เมื่อสถานะเป็นยกเลิก |
| **Views - Show** | `resources/views/bookings/show.blade.php` | ซ่อนปุ่มพิมพ์เมื่อใบจองยกเลิก |

---

## 4. ผลการทดสอบ (Test Results)

1. **เทสต์ชุดใหม่ `VoidedDocumentPrintTest`:**
   - `test_voided_receipt_cannot_be_printed`: **ผ่าน** (คืนค่า HTTP 410 ไม่แสดงเนื้อหาใบเสร็จ)
   - `test_voided_credit_sale_cannot_print_tax_invoice_or_delivery_note`: **ผ่าน** (คืนค่า HTTP 410 ทั้ง 2 หน้า)
   - `test_cancelled_booking_cannot_print_delivery_note`: **ผ่าน** (คืนค่า HTTP 410)
   - `test_active_documents_can_be_printed_normally`: **ผ่าน** (คืนค่า HTTP 200 เรนเดอร์เอกสารปกติ)
2. **เทสต์เดิม `PrintablePageSizeTest`:**
   - **ผ่าน 100%** ไม่มีเอกสารใดเปลี่ยนขนาดหน้ากระดาษ `@page size`
3. **ผลทดสอบรวมทั้งหมด:**
   - รันคำสั่ง `php -d memory_limit=1G vendor/bin/phpunit`
   - ผลลัพธ์: **876 tests passed** (7,790 assertions, 2 skipped, 6 incomplete, 0 failures, 0 errors)

---

## 5. ภาพบันทึกหน้าจอก่อนและหลังการปรับปรุง

ไฟล์ภาพบันทึกหน้าจอจัดเก็บอยู่ที่ `docs/ai/erp-print-shots/`:
- **ภาพหน้าแจ้งเตือนการยกเลิก:**
  - `voided_print_notice.png` (HTTP 410 แจ้งเตือนภาษาไทย)
- **ภาพเปรียบเทียบก่อน-หลัง (บนหน้าจอ Screen และ Print Preview):**
  - ใบเสร็จรับเงิน (Receipt): `before_screen_01_receipt.png`, `after_screen_01_receipt.png`, `before_print_01_receipt.png`, `after_print_01_receipt.png`
  - ใบกำกับภาษี (Tax Invoice): `before_screen_02_tax_invoice.png`, `after_screen_02_tax_invoice.png`, `before_print_02_tax_invoice.png`, `after_print_02_tax_invoice.png`
  - ใบส่งของ (Delivery Note): `before_screen_03_delivery_note.png`, `after_screen_03_delivery_note.png`, `before_print_03_delivery_note.png`, `after_print_03_delivery_note.png`
  - ใบส่งของจากใบจอง (Delivery Note Booking): `before_screen_04_delivery_note_booking.png`, `after_screen_04_delivery_note_booking.png`, `before_print_04_delivery_note_booking.png`, `after_print_04_delivery_note_booking.png`
  - ใบขาย (Sale Print): `before_screen_05_sale_print.png`, `after_screen_05_sale_print.png`, `before_print_05_sale_print.png`, `after_print_05_sale_print.png`
  - ใบเสนอราคา (Quotation): `before_screen_06_quotation.png`, `after_screen_06_quotation.png`, `before_print_06_quotation.png`, `after_print_06_quotation.png`
  - ใบวางบิล (Billing Note): `before_screen_07_billing_note.png`, `after_screen_07_billing_note.png`, `before_print_07_billing_note.png`, `after_print_07_billing_note.png`
  - ใบรวมจอง (Bookings Summary): `before_screen_09_bookings_summary.png`, `after_screen_09_bookings_summary.png`, `before_print_09_bookings_summary.png`, `after_print_09_bookings_summary.png`
