# ชุดส่งสำนักงานบัญชีและการเปิด/ปิดเมนู

Branch `codex/accounting-package-menus` สร้างจาก `origin/main` ที่ `81bda0d` สำหรับให้ Claude ตรวจ

## วิธีใช้

- จาก **รายงาน → ชุดส่งสำนักงานบัญชี** เลือกเดือนและสายขาย ค่าเริ่มต้นคือเดือนก่อนและ B18 เมื่อมีสิทธิ์ดูสาขาของสายนั้น
- ตัวอย่างเดือนธันวาคม 2569 ได้ไฟล์ `สนง-บัญชี-B18-2026-12.zip` มีสรุปข้อความ และ Excel เจ็ดไฟล์ตามลำดับ ภาษีขาย ขายเชื่อ ใบลดหนี้/รับคืน ใบรับเงินและการตัดหนี้ ลูกหนี้สิ้นเดือน สรุปขายรายวัน และ GL
- ใช้ตัวเขียน XLSX และการกรองข้อมูลอ่อนไหวชุดเดียวกับรายงานเดิม ดาวน์โหลดทั้งเดือน รวมเอกสารเกินหน้าจอ และตรวจสิทธิ์ทั้งดูและส่งออกที่ URL
- ใบรับเงินแสดงบิลที่ตัด จำนวนเงินตัด ส่วนลด ภาษีหัก ณ ที่จ่าย และวิธีรับเงิน สรุปรับชำระนับเงินที่รับจริง ส่วนสรุป GL นับเอกสารจากรายการที่รายงาน GL คืนมา
- อายุหนี้สิ้นเดือนคำนวณต่อใบจากยอดลูกหนี้ BPlus รวมยอดยกมา หักใบลดหนี้และยอดจัดสรรจากใบรับเงินที่มีวันที่ถึงสิ้นเดือน จากนั้นจัดช่วงอายุตามวันครบกำหนด ณ สิ้นเดือน สะท้อนหลักฐานที่ยังมีใน ERP หากต้นทางแก้ไขหรือลบประวัติการจัดสรรไปแล้ว การส่งออกซ้ำอาจให้ผลต่างจากไฟล์ที่เคยส่ง
- ใน **ตั้งค่า → เปิด/ปิดเมนู** เปิดหรือปิดทีละหมวดหรือรายการแล้วกดบันทึก ค่าเริ่มต้นเปิดทั้งหมด ปุ่มเปิดทั้งหมดย้อนคืนได้ทันที รายการที่จำเป็นต่อการเข้าระบบแสดงเหตุผลและล็อกสวิตช์
- การเปิด URL ของเมนูปิดใช้สิทธิ์เดิมร่วมด้วย ผู้ใช้ทั่วไปได้ 404 ผู้ดูแลที่เปิดหน้าได้จะเห็นคำเตือน รายการบนแถบเมนู ช่องค้นหาและหน้ารวมแอปมาจาก ErpMenu ตาราง API และงานตามเวลาใช้เส้นทางเดิม

## เจ้าของสร้างบัญชีสำนักงานบัญชีเอง

1. หลังนำรุ่นนี้ไปใช้และรัน migration ตามขั้นตอนของโครงการ เข้า **ตั้งค่า → ผู้ใช้และสิทธิ์ → เพิ่มผู้ใช้**
2. กรอกชื่อเข้าระบบ ชื่อผู้ใช้ และรหัสผ่านในฟอร์ม เลือกสาขาที่ถือเอกสารสาย B18 การเลือกสายขายเริ่มต้นในประวัติผู้ใช้อย่างเดียวไม่ได้ให้สิทธิ์สาขา
3. เลือกเฉพาะบทบาท **สำนักงานบัญชี (ดูอย่างเดียว)** แล้วบันทึก บทบาทได้รับ `reports.view` และ `reports.export` ดูรายงานและเอกสารสายขายจาก BPlus ได้ หน้าสร้างเอกสารเดิมใช้สิทธิ์จัดการจึงไม่ได้เพิ่มสิทธิ์เหล่านั้น
4. เจ้าของสามารถทวนสิทธิ์โดยเปิดหน้าชุดส่งสำนักงานบัญชี เลือก B18 และตรวจว่าเข้าเมนูตั้งค่าไม่ได้ Migration เพิ่มเฉพาะบทบาทและความสัมพันธ์กับสิทธิ์

## การทดสอบ

- `php artisan test` จบด้วย exit code 0 ตัวรันรายงาน 1,181 เทสต์, 1,179 passed, 10,584 assertions, 2 skipped และ 6 incomplete
- `php artisan test --filter=AccountingPackageMenusTest` ผ่าน 4 เทสต์ รวม 54 assertions

ใช้ SQLite in-memory เปิด ZIP จริง ตรวจ XML ใน Excel ทั้งเจ็ดไฟล์ มีใบลดหนี้ของสายที่เลือก 30 ใบ อีกสาย 1 ใบ และเอกสารเดือนถัดไป กรณีลูกหนี้ 100 บาท รับชำระในเดือน 30 และเดือนถัดไป 70 ได้ยอดสิ้นเดือน 70 บาท เทสต์บัญชีสำนักงานบัญชีใช้ตัวตนในหน่วยความจำและอ่านสิทธิ์จาก role ที่ migration สร้าง

| ถอดโค้ดชั่วคราว | ผลที่เทสต์ตรวจพบ |
|---|---|
| ถอดสิทธิ์ส่งออก | ผู้มีเพียงสิทธิ์ดูได้ HTTP 200 แทน 403 |
| ถอดตัวกรองสายขายของชุดรายงาน | ใบลดหนี้กลายเป็น 31 ใบแทน 30 |
| ข้าม middleware เปิด/ปิดเมนู | ผู้ใช้เปิด URL ของเมนูปิดได้แทนที่จะได้ 404 |
| ลงทะเบียน middleware เปิด/ปิดเมนูให้กลุ่ม API ด้วย | เทสต์พบ middleware ในเส้นทาง POST /api/bplus/sync ที่ต้องใช้เฉพาะการยืนยันตัวตนของ API |

คืนโค้ดทุกจุดแล้ว เจ้าของอนุญาตให้เพิ่ม ACCOUNTING_FIRM หนึ่งบรรทัดในรายการ role ที่ PermissionMatrixTest คาดไว้

## ไฟล์ที่แก้

- `app/Http/Controllers/AccountingPackageController.php`
- `app/Http/Controllers/MenuSettingsController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Middleware/CheckMenuVisibility.php`
- `app/Http/Middleware/ErpAuthorize.php`
- `app/Models/User.php`
- `app/Services/Reports/MonthEndReceivables.php`
- `app/Support/ErpMenu.php`
- `app/Support/MenuVisibility.php`
- `app/Support/RoutePermissions.php`
- `bootstrap/app.php`
- `database/migrations/2026_11_19_180000_create_accounting_firm_role.php`
- `docs/ai/codex-accounting-package-menus.md`
- `docs/ai/task.md`
- `resources/views/dashboard.blade.php`
- `resources/views/features/index.blade.php`
- `resources/views/layout.blade.php`
- `resources/views/pos/index.blade.php`
- `resources/views/pos/preview.blade.php`
- `resources/views/pos/retired.blade.php`
- `resources/views/pos/z-report.blade.php`
- `resources/views/reports/accounting-package.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/settings/index.blade.php`
- `resources/views/settings/menus.blade.php`
- `routes/web.php`
- `tests/Feature/AccountingPackageMenusTest.php`
- `tests/Feature/PermissionMatrixTest.php`

## รายการเมนูทั้งหมดจาก ErpMenu

### หน้าหลัก

- ภาพรวมกิจการ — `dashboard`
- รวมเมนูการทำงาน — `features.index`
- คู่มือ JET ERP — `core-modules.index`
- ศูนย์ควบคุมบริหาร — `management-controls.index`

### ขาย/เอกสาร

- เอกสารย้อนหลัง — `documents.browser`
- ใบเสนอราคา — `quotations.index`
- ใบจอง / ขายเชื่อ — `bookings.index`
- ใบรวมจอง — `bookings.summary`
- ใบปะหน้า (ขนส่ง) — `bookings.cover-sheet`
- เทียบใบจองกับใบขาย — `bookings.comparison`
- ใบขายสด — `cash-sales.index`
- ใบรับคืนสินค้า — `sale-returns.index`
- ใบวางบิล — `billing-notes.index`
- ใบเพิ่ม/ลดหนี้ — `credit-debit-notes.index`
- ลูกค้าสัมพันธ์ (CRM) — `crm.index`
- Pipeline งานขาย — `crm.pipeline`

### POS / หน้าร้าน

- ศูนย์ควบคุม POS — `pos.control`
- จัดการเครื่อง POS — `pos-admin.index`
- QR รับเงิน / จอแสดงราคา — `bplus.qr-payments`

### สินค้า/คลัง

- รับสินค้าเข้าคลัง (ซื้อเข้า) — `purchases.index`
- ใบขอซื้อ / สั่งซื้อ (PO) — `purchase-orders.index`
- สแกนใบรับสินค้า OCR — `ocr.documents.index`
- โอนย้ายสินค้าข้ามสาขา — `stock-transfers.index`
- ขอโอนสินค้าข้ามสาขา — `stock-transfers.request`
- แนะนำเติมสต็อกสาขา — `stock-transfers.replenishment.index`
- เบิกใช้ / ตัดชำรุด / คืนเบิก — `stock-issues.index`
- แปรรูปสินค้า / สูตรผลิต — `production.index`
- รายงานผลผลิต & Yield — `production.efficiency`
- ตรวจนับสต๊อกสินค้า — `stock-counts.index`
- คลังมือถือ (สแกนบาร์โค้ด) — `wh.index`
- แผนจัดซื้อและเติมสต็อก — `bplus.purchase-planning`

### การเงิน/บัญชี

- บัญชีครบวงจร — `accounting-automation.index`
- ผังบัญชี / บันทึกบัญชี — `chart-of-accounts.index`
- งวดบัญชี / ปิดงวด — `accounting-periods.index`
- ปิดบัญชีรายเดือน — `monthly-accounting.index`
- ภาษีไทย / E-Tax — `tax-compliance.index`
- งบการเงิน — `financial-statements.index`
- ทะเบียนทรัพย์สิน — `fixed-assets.index`
- เงินสด / ภาษี — `bplus.finance`
- ใบเสร็จรับเงิน (รับชำระลูกหนี้) — `ar-receipts.index`
- จ่ายชำระเจ้าหนี้ — `ap-payments.index`
- บัญชีธนาคาร — `bank-accounts.index`
- ฝาก/ถอนเงินสด — `cash-transfers.index`
- ทะเบียนเช็ค — `cheques.index`
- เอกสารสายขาย (ระบบเดิม) — `bplus.sync.index`
- อนุมัติเอกสาร — `bplus.approvals`

### รายงาน

- ภาพรวมผู้บริหาร — `executive.index`
- ชุดส่งสำนักงานบัญชี — `accounting-package.index`
- ศูนย์รวมรายงาน — `reports.index`
- รายงานขาย — `reports.index?category=sales`
- รายงานซื้อ — `reports.index?category=purchasing`
- รายงานคลัง — `reports.index?category=inventory`
- รายงานบัญชี — `reports.index?category=payment`
- รายงานภาษี — `reports.index?category=tax`

### ตั้งค่า

- ค่าคงที่ระบบ — `basic-master.index`
- สินค้า / บริการ — `products.index`
- นัดเปลี่ยนราคา — `products.scheduled-prices.index`
- หน่วยนับสินค้า — `product-units.index`
- คลังสินค้า — `warehouse-locations.index`
- ลูกค้า (รวมลูกหนี้) — `customers.index`
- ผู้จำหน่าย / เจ้าหนี้ — `suppliers.index`
- สมาชิก — `members.index`
- พนักงาน — `employees.index`
- ตารางราคา — `price-tables.index`
- ราคาเครื่องชั่ง — `scale-prices.index`
- ศูนย์โปรโมชั่น — `promotion-center.index`
- ป้ายราคา — `price-tags.index`
- ยานพาหนะ / ขนส่ง — `fleet.index`
- ศูนย์ตั้งต้นระบบ — `master-data-setup.index`
- สมุดเอกสาร — `document-books.index`
- ตั้งค่า Workflow เอกสาร — `settings.workflows`
- ศูนย์ควบคุมโมดูล — `settings.module-controls`
- ผู้ใช้และสิทธิ์ — `users.index`
- ผังองค์กร — `organizational-units.index`
- LINE แจ้งเตือน — `line-integrations.index`
- E-Commerce — `ecommerce-channels.index`
- เปิด/ปิดเมนู — `settings.menus`
- สถานะระบบ — `system.status`
- ตั้งค่าระบบ — `settings.index`
- นโยบายขายติดลบ — `settings.stock-policies`
- Backup / Security — `operations.index`
- โครงสร้างฐานข้อมูล — `database-structure.index`
