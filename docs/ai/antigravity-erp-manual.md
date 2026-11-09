# สรุปงาน Task 8: คู่มือ JET ERP แบบสอนทีละขั้น (`/core-modules`)

**สาขา (Branch):** `antigravity/erp-manual` (แตกจาก `origin/main` `8400cb7`)

---

## 1. โครงสร้างหน้าคู่มือใหม่ (`resources/views/core-modules/index.blade.php`)
- **ด้านบน**: ช่องค้นหากรองหัวข้อทันทีในหน้า (ไม่ต้องโหลดหน้าใหม่) + แท็บหมวดหมู่ 6 หมวด (`ขาย / เอกสาร`, `POS / หน้าร้าน`, `สินค้า / คลัง`, `การเงิน / บัญชี`, `รายงาน`, `ตั้งค่า`)
- **ด้านซ้าย**: รายการหัวข้อในหมวดที่เลือก (หรือรายการผลลัพธ์การค้นหาจากทุกหมวดที่ผู้ใช้มีสิทธิ์)
- **ด้านขวา**: เนื้อหาหัวข้อที่เลือก แสดงผู้รับผิดชอบ (`who`), ขั้นตอนการทำงานทีละขั้น (`steps` 1, 2, 3...), ข้อควรระวัง (`tips`), ปุ่ม **พิมพ์** (`window.print()` พร้อม `@media print` เฉพาะเนื้อหาหัวข้อที่เปิดอยู่) และปุ่ม **เปิดหน้านี้** ลิงก์ไปยัง `route(...)` จริงของหน้านั้น
- **การกรองตามสิทธิ์**: ใช้ `App\Support\RoutePermissions::resolve($route)` ร่วมกับ `$user->hasPermission(...)` แสดงเฉพาะหัวข้อที่ผู้ใช้คนนั้นมีสิทธิ์เข้าใช้งานจริง

---

## 2. รายการหัวข้อคู่มือทั้งหมด (23 หัวข้อ ใน `config/manual.php`)

### หมวดที่ 1: ขาย / เอกสาร (`sales`) — 8 หัวข้อ
1. **เปิดใบจองสินค้า** (`booking-create` → `bookings.index`)
2. **แก้ไข / ยกเลิกใบจอง** (`booking-edit-cancel` → `bookings.index`)
3. **แปลงใบจองเป็นใบขายเชื่อ** (`booking-convert` → `bookings.index`) — อธิบายการแยกบิลอัตโนมัติตาม VAT ของสินค้า: มี VAT ออกเล่ม **DS**, ไม่มี VAT ออกเล่ม **DSN** (ใบจองเดียวอาจได้ 2 บิล ใช้ใบส่งของใบเดียวกัน) และตัดสต็อกพร้อมตั้งลูกหนี้ทันที
4. **สต็อกไม่พอแต่ของมีจริง (ผู้จัดการอนุมัติ)** (`booking-stock-shortage` → `bookings.index`) — ผู้จัดการกดปุ่มอนุมัติใน ERP ก่อนแปลงบิล สต็อกติดลบชั่วคราว
5. **ย้อนการคอนเฟิร์มใบจอง** (`booking-revert-confirm` → `bookings.index`) — ผู้มีสิทธิ์ยกเลิกเอกสารระบุเหตุผลแล้วกด **ถอย Confirm** เพื่อยกเลิกบิล DS/DSN คืนสต็อก และจองสต็อกคืน
6. **ใบขายสด** (`cash-sales-guide` → `cash-sales.index`)
7. **ใบรับคืนสินค้า** (`sale-returns-guide` → `sale-returns.index`)
8. **ใบวางบิล** (`billing-notes-guide` → `billing-notes.index`)

### หมวดที่ 2: POS / หน้าร้าน (`pos`) — 3 หัวข้อ
9. **เปิดกะ / ขาย / ปิดกะ และพิมพ์ Z Report** (`pos-shift-sale-zreport` → `pos.control`)
10. **ดาวน์โหลดและอัปเดตโปรแกรม POS** (`pos-download-update` → `pos-admin.index`) — ลิงก์ดาวน์โหลด `https://erp.popstarcenter.com/download/python-pos`
11. **ยกเลิกบิล / คืนสินค้าที่ POS** (`pos-void-return-pin` → `pos.control`) — อธิบายขั้นตอนการใช้ PIN ผู้มีสิทธิ์โดยไม่ระบุรหัส PIN จริงหรือตัวอย่าง PIN ใดๆ

### หมวดที่ 3: สินค้า / คลัง (`inventory`) — 5 หัวข้อ
12. **เพิ่มสินค้า** (`product-create` → `products.index`)
13. **หน่วยนับและบาร์โค้ด** (`product-units-barcodes` → `product-units.index`)
14. **รับสินค้าเข้า** (`inventory-receive` → `purchases.index`)
15. **โอนย้ายสต็อกระหว่างคลัง/สาขา** (`inventory-transfer` → `stock-transfers.index`)
16. **ตรวจนับสต็อก** (`inventory-stock-count` → `stock-counts.index`) — อธิบายโหมดนับบางส่วนและโหมดนับเต็ม ซึ่งการตั้งยอดที่ไม่ได้นับเป็นศูนย์ต้องให้ผู้จัดการติ๊กยืนยันก่อนเสมอ

### หมวดที่ 4: การเงิน / บัญชี (`finance`) — 3 หัวข้อ
17. **รับชำระลูกหนี้** (`finance-ar-payment` → `customers.index`)
18. **ดูยอดค้างชำระและจ่ายชำระเจ้าหนี้** (`finance-outstanding-ap` → `ap-payments.index`)
19. **กระทบยอดธนาคารและปิดบัญชีรายเดือน** (`finance-monthly-closing` → `monthly-accounting.index`)

### หมวดที่ 5: รายงาน (`reports`) — 1 หัวข้อ
20. **เปิดรายงานขาย ใบจอง การส่งของ รับชำระ และลูกหนี้** (`reports-guide` → `reports.index`)

### หมวดที่ 6: ตั้งค่า (`settings`) — 3 หัวข้อ
21. **ค่าคงที่ระบบ (หมวด ยี่ห้อ หน่วย สาขา คลัง)** (`settings-constants` → `basic-master.index`) — อธิบายว่าการลบรายการที่มีประวัติใช้งานจะเปลี่ยนสถานะเป็นปิดใช้งานแทน และเปิดกลับได้จากปุ่มแก้ไข
22. **สมุดเอกสาร** (`settings-document-books` → `document-books.index`) — อธิบายว่าช่อง "ไม่มีภาษี" แก้ไขไม่ได้เมื่อเล่มเอกสารนั้นมีเอกสารออกไปแล้ว
23. **ผู้ใช้และสิทธิ์การใช้งาน** (`settings-users-roles` → `users.index`)

---

## 3. ภาพหน้าจอที่ความละเอียด 1366 (`docs/ai/erp-manual-shots/`)
- `01_sales_booking_convert_1366.png` — หมวดขาย (หัวข้อแปลงใบจองเป็นใบขายเชื่อ)
- `02_pos_section_1366.png` — หมวด POS / หน้าร้าน
- `03_search_stock_1366.png` — ช่องค้นหาที่พิมพ์คำว่า `"สต็อก"`

---

## 4. ผลการทดสอบ (PHPUnit)
- เทสต์ใหม่ `tests/Feature/ManualPageTest.php` ผ่านครบทั้ง 4 ข้อ:
  1. ผู้ใช้ที่มีสิทธิ์เปิด `/core-modules` ได้ 200 และเห็นหัวข้อ `"แปลงใบจองเป็นใบขายเชื่อ"`
  2. ทุก `route` ใน `config/manual.php` มีอยู่จริง (`Route::has`)
  3. ผู้ใช้ที่ไม่มีสิทธิ์ขาย ไม่เห็นหัวข้อในหมวดขาย
  4. หน้าไม่มีคำว่า `"BPlus"`, `"PS1"`, `"MAN · "` หรือ `"MONEY"`
- ชุดทดสอบทั้งระบบ (`php -d memory_limit=1G vendor/bin/phpunit`) ผ่านทั้งหมด **869 passed** (0 failures)
