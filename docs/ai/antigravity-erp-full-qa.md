# รายงานผลการทดสอบระบบแบบเจาะลึก (Full QA Report) — JET ERP

**สาขา:** `antigravity/erp-full-qa`  
**สถานะ:** กำลังดำเนินการ (Round 1: เสร็จสิ้น, Round 2: กำลังดำเนินการ, Round 3: กำลังดำเนินการ)

---

## สรุปภาพรวมการตรวจ (Overview)
งานนี้เป็นการตรวจสอบระบบ JET ERP (Laravel 13 + Blade + Alpine บน PostgreSQL/SQLite) ทุกโมดูล ทุกฟีเจอร์ ทุกปุ่ม เพื่อให้มั่นใจว่า:
1. ไม่มีหน้าใดเกิดข้อผิดพลาด Server Error (HTTP 500)
2. ผู้ใช้แต่ละบทบาท (GM, SALES, WAREHOUSE) ได้รับสิทธิ์ตรงตามบทบาท (หน้าที่ไม่มีสิทธิ์ต้องตอบกลับด้วย HTTP 403 Forbidden ไม่ใช่ 500)
3. ตรวจสอบการทำงานของปุ่ม ลิงก์ ฟอร์ม ตัวกรอง และกรณีขอบเขต (Edge cases) ทั้งหมด
4. รวบรวมรายการฟีเจอร์ที่ยังขาด และรายการที่ต้องส่งต่อให้ Claude ปรับปรุงเชิงลึกด้านบัญชี/การเงิน/สต็อก

---

## รอบที่ 1: ตรวจหน้าที่ต้องเปิดจากเอกสาร (Show / Edit / Print) ด้วยเทสต์อัตโนมัติ

### 1.1 การสร้างเทสต์ `tests/Feature/EveryRecordPageOpensTest.php`
- มีการสร้างชุดข้อมูลจำลองเสมือนจริงผ่าน Service ของระบบจริง ได้แก่:
  - สาขา, คลัง, ตำแหน่งจัดเก็บ, หมวดสินค้า, แผนก, หน่วยนับ, สินค้า, บาร์โค้ด
  - ผู้จำหน่าย, ลูกค้า, ที่อยู่จัดส่ง
  - ใบซื้อสินค้า (Purchase) ผ่าน `PurchaseService` มีการรับเข้าคลังและสร้าง Lot
  - ใบจองสินค้า (Booking) ผ่าน `BookingService` แบบกำหนดวันส่งและเวลานัดหมาย
  - ใบขายสด (Cash Sale) ผ่าน `CashSaleService`
  - ใบขายเชื่อ (Credit Sale) ผ่าน `CreditSaleService::convertBookingToCreditSale`
  - ใบรับคืนสินค้า (Sale Return) ผ่าน `SaleReturnService` อ้างอิงยอดหนี้คงค้าง
  - ใบเสร็จรับเงิน (Receipt) ผ่าน `CustomerPaymentService` พร้อมการจัดสรรยอดชำระ
  - ใบสำคัญจ่าย (Supplier Voucher) ผ่าน `SupplierPaymentService`
  - ใบลดหนี้ (Credit Note) ผ่าน `CreditDebitNoteService`
  - ใบเสนอราคา (Quotation) และใบวางบิล (Billing Note)
  - ใบสั่งซื้อ (Purchase Order)
  - ใบโอนสินค้า (Stock Transfer) และการอนุมัติระหว่างสาขา
  - ใบปรับยอดสต๊อก (Stock Adjustment) และใบเบิกใช้ (Stock Issue)
  - ใบแปรรูปสินค้า (Stock Transform / Batch Production)
  - ใบนับสต๊อก (Stock Count)
  - ทะเบียนสินทรัพย์ถาวร (Fixed Asset)
  - การจัดการราคา: ตารางราคา (Price Table), Flash Sale, แม่แบบป้ายราคา (Price Tag Template)
  - การควบคุมฝ่ายบริหาร: ศูนย์ต้นทุน (Cost Center), งบประมาณ (Budget), รอบเงินเดือน (Payroll Run & Items)
  - กะการขายหน้าร้าน (POS Shift)
  - แผนบาร์โค้ดเครื่องชั่ง (Scale PLU Plan)
  - เอกสาร OCR ใบส่งของ/ใบกำกับภาษี (OCR Document)

### 1.2 ข้อผิดพลาด HTTP 500 ที่ตรวจพบและได้รับการแก้ไข

| หน้า / Route | URL ที่ทดสอบ | สาเหตุที่เกิด HTTP 500 | การแก้ไข |
|---|---|---|---|
| `purchases.cost-adjustments.create` | `/purchases/{purchase}/cost-adjustments` | ในไฟล์ `resources/views/purchases/cost-adjustments/create.blade.php` มีการเขียน `@php($item = $row['item']; $lot = $row['lot'])` ซึ่ง Blade แปลงเป็น `<?php ($a; $b); ?>` เป็นไวยากรณ์ผิดพลาดของ PHP ทำให้หน้าพังด้วย 500 | แก้ไขเป็น `@php $item = $row['item']; $lot = $row['lot']; @endphp` ถูกต้องตามมาตรฐาน Blade |
| `scale-prices.plans.labels` | `/scale-prices/plans/{plan}/labels` | ในไฟล์ `resources/views/scale-prices/plan-labels.blade.php` เข้าถึงคีย์ `$r['sku']` โดยตรง แต่บางกรณีแถวข้อมูลส่งมาเป็น `'sku_code'` หรือไม่มีคีย์ ทำให้เกิด `Undefined array key "sku"` (500 Error) | แก้ไขเป็น `{{ $r['sku'] ?? $r['sku_code'] ?? '' }}` รองรับทั้งสองรูปแบบอย่างปลอดภัย |

### 1.3 ผลการทดสอบสิทธิ์ 3 บทบาท (Role Authorization Check)
จากการทดสอบทุก Route แบบ Parameterized ทั้งหมด 48 Routes:
- **GM (ผู้จัดการทั่วไป):** เปิดได้ครบถ้วน ไม่มีหน้าใดเกิด HTTP 500 (0 errors, 0 server crashes)
- **SALES (พนักงานขาย):** ถูกปฏิเสธด้วย HTTP 403 Forbidden ในหน้าที่ไม่มีสิทธิ์จำนวน 33 หน้า และเปิดได้เฉพาะหน้าที่ได้รับอนุญาต (เช่น หน้ารายการขาย ใบจอง ใบส่งของ) โดยไม่มีหน้าใดเกิด HTTP 500
- **WAREHOUSE (พนักงานคลังสินค้า):** ถูกปฏิเสธด้วย HTTP 403 Forbidden ในหน้าที่ไม่มีสิทธิ์จำนวน 41 หน้า และเปิดได้เฉพาะหน้าที่เกี่ยวกับคลัง/สินค้า โดยไม่มีหน้าใดเกิด HTTP 500
- **ผลการรัน PHPUnit รวม:** ผ่านทั้งหมด 877 เทสต์ (875 passed, 2 skipped, 0 failures, 0 errors)
