# รายงานการทำงานที่ 6: ล้างของระบบเดิม (BPlus) + คู่มือและรวมเมนูใหม่ + ปรับโลโก้และ JET ERP

**ผู้จัดทำ**: Antigravity  
**วันที่**: 9 พฤศจิกายน 2026  
**กิ่ง Git (Branch)**: `antigravity/erp-legacy-cleanup` (สร้างจาก `origin/main` commit `e953c33`)

---

## 1. สรุปภาพรวมการดำเนินงาน

ตามคำสั่งของเจ้าของระบบ:
1. **"อันไหนเกี่ยวกับระบบเดิมเอาออกเลย เราเอาแค่ logic มา"**: สำรวจหน้าจอ `bplus.*` ทั้งหมดในระบบ แยกกลุ่มหน้าที่ไม่มีใครใช้และเป็นเพียงสมุดจดบันทึกเก่าออกจากระบบ (ลบ route, view, controller method, เมนู และสิทธิ์) ส่วนฟังก์ชันที่มี logic ที่ระบบปัจจุบันและ POS ใช้งานจริง (QR รับเงิน, จอแสดงราคา, อัตราภาษี VAT, ปรับปรุงสมุดเงินสด, อนุมัติเอกสาร, แผนเติมเต็มจัดซื้อ) **เก็บ logic และ route ไว้ครบถ้วน** พร้อมทำความสะอาดข้อความบนหน้าจอไม่ให้มีคำว่า "BPlus"
2. **คู่มือและหน้ารวมเมนูการทำงานใหม่**:
   - หน้า `/features` (รวมเมนูการทำงาน): เขียนใหม่ทั้งหมด จัดหมวดหมู่ตามกระบวนการทำงานจริงของร้าน 7 หมวด พร้อมสรุปขั้นตอนการทำงาน (Workflow) 3–6 ข้อ บทบาท/สิทธิ์ผู้ใช้งาน และลิงก์เปิดหน้าจอทำงานจริง ตัดรหัสเอกสาร BPlus (PS1–PS5) และคำว่า "อิงลำดับงาน BPlus" ออกทั้งหมด
   - หน้า `core-modules.index` (คู่มือการใช้งาน): ปรับข้อความและหัวข้อจาก "BplusBack Blueprint" / "ตามแบบ BPlus" สู่ "พิมพ์เขียวสถาปัตยกรรมระบบ JET ERP" และเปลี่ยนป้ายใน Dropdown ผู้ใช้เป็น "คู่มือการใช้งาน"
3. **ปรับขนาดโลโก้และจัดกึ่งกลาง JET ERP**:
   - ช่องใส่โลโก้ `.odn-brand-slot` ปรับขนาดความกว้างให้ตรงกับแถบเมนูด้านซ้ายพอดี (`calc(216px * var(--menu-scale, 1))`)
   - โลโก้ `.odn-logo-box img` ใช้ `object-fit: contain; height: 38px !important; width: auto !important; max-width: 150px !important; max-height: 40px !important;` อยู่กึ่งกลางแนวตั้งพอดี ไม่ถูกบีบอัด ไม่ล็อก 48px และไม่ถูกตัดขอบ
   - ข้อความ "JET ERP" ฝั่งขวาบนของแถบ ใช้ `display: inline-flex; align-items: center; justify-content: center; height: 52px; line-height: 52px;` อยู่กึ่งกลางแนวตั้งของแถบหลักอย่างสมบูรณ์แบบทั้งจอ 1366px และ 1024px

---

## 2. ตารางสำรวจหน้า BPlus ทั้งหมด: แยกกลุ่ม ลบ vs เก็บ+ย้าย

| Route Name | URL | Controller & Action | สถานะ | เมนู / ตำแหน่ง | เหตุผลและหลักฐานการตรวจสอบ (grep) |
|---|---|---|:---:|---|---|
| `bplus.pos-preparation` | `GET /bplus/pos-preparation`<br>`POST /bplus/pos-preparation` | `BplusOperationController@posPreparation`<br>`BplusOperationController@storePosPreparation` | **ลบออก** | เมนู 'ส่งข้อมูลไป POS' (ลบออกจาก ErpMenu) | **เป็นแค่สมุดจดของระบบเดิม**: ฟอร์มมีเพียงการบันทึกแถวจำลองลงตาราง `pos_preparations` ไม่มีการส่งข้อมูลจริงไปยัง POS เครื่อง POS ซิงก์จริงผ่าน REST API `/api/pos/v1/sync/*` จึงไม่มีผู้ใช้งานจริง |
| `bplus.pos-workbench` | `GET /bplus/pos-workbench` | `BplusOperationController@posWorkbench` | **ลบออก** | เมนู 'เครื่องมือ POS' (ลบออกจาก ErpMenu) | **เป็น workbench ชั่วคราวเดิม**: งานดูบิลขายมีใน 'ศูนย์ควบคุม POS' (`pos.control`) การผูกเครื่อง/Token มีใน 'จัดการเครื่อง POS' (`pos-admin.index`) และ Settings แท็บ `pos-download` ครบถ้วนแล้ว |
| `bplus.purchase-planning` | `GET /bplus/purchase-planning`<br>`POST /bplus/purchase-planning`<br>`POST /bplus/purchase-planning/generate-requisitions` | `BplusOperationController@purchasePlanning`<br>`BplusOperationController@storePurchasePlan`<br>`BplusOperationController@generatePurchaseRequisitions` | **เก็บไว้** | เมนู 'สินค้า/คลัง' → 'เติมเต็ม / แผนจัดซื้อ' | **มี Logic ธุรกิจจริง**: มีอัลกอริทึมคำนวณ Min/Max, Safety Stock, ยอดขายย้อนหลัง, จุดสั่งซื้อ และสร้างใบขอซื้อ (PR) อัตโนมัติ เทสต์ `ReplenishmentFlowTest` ใช้งานจริง |
| `bplus.approvals` | `GET /bplus/approvals`<br>`POST /bplus/approvals` | `BplusOperationController@approvals`<br>`BplusOperationController@storeApproval` | **เก็บไว้** | เมนู 'การเงิน/บัญชี' → 'อนุมัติเอกสาร' | **มี Logic ธุรกิจจริง**: จัดการคำขออนุมัติวงเงินเครดิตขาย เครดิตซื้อ และรายการเงิน |
| `bplus.finance` | `GET /bplus/finance`<br>`POST /bplus/cash-books` | `BplusOperationController@finance`<br>`BplusOperationController@storeCashBook` | **เก็บไว้** | เมนู 'การเงิน/บัญชี' → 'เงินสด / ภาษี' | **มี Logic ธุรกิจจริง**: ปรับปรุงสมุดเงินสดพร้อมระบบ Maker-Checker และกระทบยอดบัญชีธนาคาร |
| `bplus.tax` | `GET /bplus/tax`<br>`POST /bplus/vat-rates`<br>`PUT /bplus/vat-rates/{vatRate}` | `BplusOperationController@tax`<br>`BplusOperationController@storeVatRate`<br>`BplusOperationController@closeVatRate` | **เก็บไว้** | เข้าถึงจากหน้า 'เงินสด / ภาษี' | **มี Logic ธุรกิจจริง**: จัดการอัตราภาษีมูลค่าเพิ่ม (VAT) สรุปยอดภาษีขายจาก POS เทสต์ `BasicMasterTest` เรียกใช้งานจริง |
| `bplus.qr-payments` | `GET /bplus/qr-payments`<br>`POST /bplus/qr-payments`<br>`PUT /bplus/qr-payments/{qrPaymentConfig}`<br>`DELETE /bplus/qr-payments/{qrPaymentConfig}` | `BplusOperationController@qrPayments`<br>`BplusOperationController@storeQrPayment`<br>`BplusOperationController@updateQrPayment`<br>`BplusOperationController@destroyQrPayment` | **เก็บไว้** | เมนู 'POS / หน้าร้าน' → 'QR รับเงิน / จอแสดงราคา' และหน้า Settings | **มี Logic ธุรกิจจริง**: ตั้งค่า PromptPay ID สำหรับสร้าง Dynamic QR หน้าร้าน POS และ EDC ต่อสาขา |
| `bplus.show-price` | `GET /bplus/show-price`<br>`POST /bplus/show-price` | `BplusOperationController@showPrice`<br>`BplusOperationController@storeShowPrice` | **เก็บไว้** | เข้าถึงจากหน้า 'QR รับเงิน / จอแสดงราคา' | **มี Logic ธุรกิจจริง**: ตั้งค่าอุปกรณ์จอแสดงราคาสำหรับลูกค้าและเครื่องสแกนเช็กราคาหน้าร้าน |

---

## 3. รายละเอียดการล้างข้อความ BPlus ทั่วทั้งระบบ

| ไฟล์ | ข้อความเดิม | ข้อความใหม่ |
|---|---|---|
| `resources/views/features/index.blade.php` | `เมนู ERP แบบใช้งานง่าย อิงลำดับงานจาก BPlus` | `คู่มือและแผนผังขั้นตอนการทำงาน ERP ตามระบบปัจจุบัน` |
| `resources/views/features/index.blade.php` | `รหัสเอกสาร BPlus ในหมวดนี้` (PS1-PS5 ฯลฯ) | นำบล็อกเอกสารเก่าออก แทนที่ด้วยขั้นตอนการทำงานจริง (Workflow Steps 1–6) และเมนูที่เกี่ยวข้อง |
| `resources/views/features/index.blade.php` | `ลำดับงานอิง BPlus` | `ขั้นตอนการทำงานตามระบบปัจจุบัน` |
| `resources/views/features/index.blade.php` | `MPM Food Group Flow · อิงลำดับงาน BPlus` | `JET ERP · แผนผังและขั้นตอนการทำงานตามระบบปัจจุบัน` |
| `resources/views/core-modules/index.blade.php` | `BPLUS Rebuild Blueprint: กติกาหลักของระบบใหม่` | `พิมพ์เขียวสถาปัตยกรรมระบบ JET ERP: กติกาหลัก` |
| `resources/views/core-modules/index.blade.php` | `ลำดับสร้างระบบตาม BplusBack Blueprint` | `ลำดับพัฒนาและควบคุมระบบ JET ERP` |
| `resources/views/core-modules/index.blade.php` | `BplusBack → PopStar 4M: แผนผังความสามารถครบวงจร` | `แผนผังขั้นตอนการทำงาน ERP ครบวงจร` |
| `resources/views/core-modules/index.blade.php` | `หลักการของ PopStar: ใช้แนวคิดจาก BplusBack...` | `หลักการทำงานของ JET ERP: เอกสารทุกใบมีสถานะ ผู้รับผิดชอบ ผลกระทบ และลิงก์ต้นทาง/ปลายทางชัดเจน ยืนยันแล้วแก้ตรงๆ ไม่ได้ ต้องยกเลิกหรือกลับรายการ พร้อมบันทึกประวัติการแก้ไข (Audit log) เสมอ` |
| `resources/views/chart-of-accounts/index.blade.php` | `รองรับไฟล์ Excel จาก BPlus: รหัสบัญชี, ชื่อบัญชีไทย...` | `รองรับไฟล์ Excel: รหัสบัญชี, ชื่อบัญชีไทย...` |
| `resources/views/document-books/index.blade.php` | `...แต่ละเล่มมีเลขรันของตัวเอง (เช่น DS / DSN เหมือน BPlus)` | `...แต่ละเล่มมีเลขรันของตัวเอง (เช่น DS / DSN แยกตามสาขาหรือประเภทงาน)` |
| `resources/views/users/index.blade.php` | `จัดการผู้ใช้ บทบาท และสิทธิ์การใช้งานตามแบบฉบับ BPlus` | `จัดการผู้ใช้ บทบาท และสิทธิ์การใช้งาน` |
| `resources/views/users/index.blade.php` | `บทบาทและสิทธิ์ (ตามแบบ BPlus)` | `บทบาทและสิทธิ์การใช้งาน` |
| `resources/views/products/show.blade.php` | `Legacy / BPlus: เก็บตามจริง ไม่ตรวจรูปแบบ` | `Legacy: เก็บตามจริง ไม่ตรวจรูปแบบ` |
| `resources/views/documents/browser.blade.php` | `{{-- ต้นไม้ ประเภท -> ปี -> เดือน แบบ BPlus --}}` | `{{-- แผนผังต้นไม้ ประเภท -> ปี -> เดือน --}}` |
| `app/Support/BarcodePolicy.php` | `'Legacy / BPlus'` | `'Legacy (เก็บตามจริง)'` / `'Legacy'` |
| `resources/views/layout.blade.php` | `คู่มือ 4M` (ในเมนู Dropdown บัญชีผู้ใช้) | `คู่มือการใช้งาน` |

---

## 4. โครงสร้างคู่มือและหน้ารวมเมนูใหม่ (`/features`)

จัดแบ่งหมวดงาน 7 หมวดตามการปฏิบัติงานจริงของร้านค้า:
1. **งานขายและการส่งมอบ (Sales & Fulfillment)**:
   - ผู้รับผิดชอบ: พนักงานขาย / ผู้จัดการฝ่ายขาย (`sales.manage`)
   - ขั้นตอน 5 ข้อ: ออกใบเสนอราคา/ใบจอง → ตรวจสต๊อกคงเหลือพร้อมขายอัตโนมัติ → แปลงเป็นใบขาย (DS/DSN) → ส่งมอบสินค้าและพิมพ์ใบส่งของ → รับชำระเงินหรือวางบิล
   - เมนูที่เกี่ยวข้อง: ใบจองสินค้า / ขายเชื่อ (`bookings.index`), ใบเสนอราคา (`quotations.index`), ใบขายสด (`cash-sales.index`), ใบรับคืนสินค้า (`sale-returns.index`), ใบวางบิล (`billing-notes.index`), ใบเพิ่มหนี้/ลดหนี้ (`credit-debit-notes.index`)
2. **POS หน้าร้านและแคชเชียร์ (POS Retail & Cashier)**:
   - ผู้รับผิดชอบ: แคชเชียร์ / ผู้จัดการสาขา (`pos.sell`, `pos.use`, `reports.view`)
   - ขั้นตอน 6 ข้อ: เปิดกะขายระบุเงินทอน → สแกนสินค้าคำนวณโปรโมชั่นและแต้มสมาชิก → รับชำระเงินหลายช่องทาง → พิมพ์ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ → พักบิลและเรียกกลับมาขายต่อ → ปิดกะพิมพ์ Z-Report และสรุปเงินสด
   - เมนูที่เกี่ยวข้อง: หน้าขาย POS (`pos.index`), ศูนย์ควบคุม POS (`pos.control`), จัดการเครื่อง POS (`pos-admin.index`), QR รับเงิน / จอแสดงราคา (`bplus.qr-payments`)
3. **สินค้าและคลัง (Inventory & Warehousing)**:
   - ผู้รับผิดชอบ: พนักงานคลัง / เจ้าหน้าที่จัดซื้อ (`inventory.manage`, `purchasing.manage`)
   - ขั้นตอน 6 ข้อ: ตรวจสอบจุดสั่งซื้อ Min/Max → ออกใบสั่งซื้อ (PO) → ตรวจรับสินค้าเข้าคลังตัด Lot/วันหมดอายุ/ต้นทุนจริง → ยื่นคำขอโอนและสแกนตรวจรับปลายทาง → ตรวจนับสต๊อกจริง (Stock Count) → อนุมัติปรับยอดสต๊อกพร้อมเหตุผล
   - เมนูที่เกี่ยวข้อง: ทะเบียนสินค้า/บาร์โค้ด (`products.index`), ใบขอซื้อ/สั่งซื้อ (`purchase-orders.index`), รับสินค้าเข้าคลัง (`purchases.index`), โอนย้าย/ปรับยอด (`stock-transfers.index`), ขอโอนสินค้า (`stock-transfers.request`), แนะนำเติมสินค้าสาขา (`stock-transfers.replenishment.index`), ตรวจนับสินค้า (`stock-counts.index`), แผนเติมเต็มและจัดซื้อ (`bplus.purchase-planning`)
4. **การผลิตและแปรรูป (Production & Processing)**:
   - ผู้รับผิดชอบ: หัวหน้าฝ่ายผลิต / เจ้าหน้าที่แปรรูป (`inventory.manage`, `production.manage`)
   - ขั้นตอน 5 ข้อ: กำหนดสูตรผลิต BOM → เปิดใบสั่งผลิต → เบิกวัตถุดิบออกจากคลัง → รับผลผลิตเข้าคลังชั่งน้ำหนักและพิมพ์ป้ายบาร์โค้ด → วิเคราะห์ Yield และปิดงานสั่งผลิต
   - เมนูที่เกี่ยวข้อง: การผลิตและสูตร BOM (`production.index`), เบิก/คืน/แปรรูปสินค้า (`stock-issues.index`), รายงานประสิทธิภาพผลิต (`production.efficiency`)
5. **การเงินและบัญชี (Finance & Accounting)**:
   - ผู้รับผิดชอบ: ฝ่ายการเงิน / สมุห์บัญชี (`finance.manage`, `accounting.manage`)
   - ขั้นตอน 6 ข้อ: บันทึกรับ-จ่ายเงินสดและกระทบยอดบัญชีธนาคาร → ตรวจสอบหนี้ครบกำหนดและจ่ายเจ้าหนี้ → ติดตามลูกหนี้และรับชำระเงิน → ระบบลงบัญชีแยกประเภท (GL) อัตโนมัติ → จัดทำภาษีขาย/ภาษีซื้อ ภ.พ.30 และ E-Tax → ปิดงวดบัญชีรายเดือนและออกงบการเงิน
   - เมนูที่เกี่ยวข้อง: ผังบัญชีและสมุดรายวัน GL (`chart-of-accounts.index`), สมุดเงินสด/ธนาคาร (`bplus.finance`), ฝาก/ถอน/โอนเงินสด (`cash-transfers.index`), บัญชีธนาคาร (`bank-accounts.index`), ทะเบียนเช็ค (`cheques.index`), จ่ายชำระเจ้าหนี้ (`ap-payments.index`), ภาษีไทยและ E-Tax (`tax-compliance.index`), อัตราภาษี VAT (`bplus.tax`), ปิดบัญชีรายเดือน (`monthly-accounting.index`), งวดบัญชี/ปิดงวด (`accounting-periods.index`), ทะเบียนทรัพย์สิน (`fixed-assets.index`), ศูนย์อนุมัติเอกสาร (`bplus.approvals`), งบการเงิน (`financial-statements.index`)
6. **รายงานและการบริหาร (Reports & Executive BI)**:
   - ผู้รับผิดชอบ: ผู้บริหาร / ผู้จัดการ / ผู้ตรวจสอบ (`reports.view`, `management.view`)
   - ขั้นตอน 5 ข้อ: ตรวจสอบภาพรวมบน Dashboard → เรียกดูรายงานขายรายวัน/สาขา/พนักงาน → ตรวจสอบรายงานสินค้าคงเหลือและ Stock Card → วิเคราะห์รายงานลูกหนี้ค้างชำระ (AR Aging) → ส่งออกรายงาน Excel/CSV
   - เมนูที่เกี่ยวข้อง: ภาพรวมกิจการ (`dashboard`), ภาพรวมผู้บริหาร (`executive.index`), ศูนย์รวมรายงาน (`reports.index`)
7. **ตั้งค่าระบบและผู้ใช้งาน (System Settings & Administration)**:
   - ผู้รับผิดชอบ: ผู้ดูแลระบบ (`settings.manage`, `system.admin`)
   - ขั้นตอน 5 ข้อ: สร้างผู้ใช้และกำหนดสิทธิ์ตามบทบาท → กำหนดข้อมูลบริษัท สาขา คลังสินค้า → ตั้งค่าเล่มเอกสารและเลขรันอัตโนมัติ → กำหนดค่าคงที่ระบบ → เชื่อมต่อช่องทางขายออนไลน์
   - เมนูที่เกี่ยวข้อง: ตั้งค่าระบบทั่วไป (`settings.index`), จัดการผู้ใช้และสิทธิ์ (`users.index`), สมุดเอกสาร (`document-books.index`), ช่องทางขายออนไลน์ (`ecommerce-channels.index`)

---

## 5. การปรับโลโก้และข้อความ JET ERP

ไฟล์: `resources/views/layout.blade.php`
- `.odn-brand-slot`: กำหนดความกว้าง `calc(216px * var(--menu-scale, 1))` เท่ากับแถบเมนูด้านซ้ายพอดี ความสูงเต็มแถบ 52px
- `.odn-logo-box`: กำหนด `height: 38px; max-height: 40px; width: auto; display: inline-flex; align-items: center; justify-content: center;`
- `.odn-logo-box img`: กำหนด `object-fit: contain; height: 38px !important; width: auto !important; max-width: 150px !important; max-height: 40px !important;` แสดงผลพอดีช่อง ไม่ถูกบีบหรือยืด ไม่ถูกตัด และอยู่กึ่งกลางแนวตั้งเสมอ
- `.odn-sysname`: กำหนด `display: inline-flex; align-items: center; justify-content: center; height: 52px; line-height: 52px;` ทำให้ข้อความ "JET ERP" อยู่กึ่งกลางแนวตั้งพอดีกับแถบ Navigation Bar ด้านบน

---

## 6. ภาพถ่ายหน้าจอการทดสอบ (Screenshots)

ภาพถ่ายหน้าจอที่ความละเอียด 1366×768 และ 1024×768 บันทึกไว้ในไดเรกทอรี `docs/ai/erp-legacy-cleanup-shots/`:

1. **Header Bar & Logo Alignment (1366px & 1024px)**:
   - `docs/ai/erp-legacy-cleanup-shots/header_logo_1366.png` (MD5: `58d39936ce7e9696e1e834785e9472f6`, 138 KB)
   - `docs/ai/erp-legacy-cleanup-shots/header_logo_1024.png` (MD5: `317ba3d232677dca99da6ffdea830d71`, 121 KB)
   - *คำอธิบาย*: แสดงช่องใส่โลโก้ความกว้าง 216px พอดีแนวแถบเมนูด้านซ้าย โลโก้อยู่กึ่งกลางแนวตั้งไม่ถูกบีบ และข้อความ "JET ERP" ด้านขวาบนอยู่กึ่งกลางแนวตั้งพอดี
2. **หน้ารวมเมนูและขั้นตอนการทำงานใหม่ `/features` (1366px & 1024px)**:
   - `docs/ai/erp-legacy-cleanup-shots/features_page_1366.png` (MD5: `734aef6fdb77c2427a306170f71f4954`, 203 KB)
   - `docs/ai/erp-legacy-cleanup-shots/features_page_1024.png` (MD5: `d691f941e105c36d168a4d1ce5c9d412`, 133 KB)
   - *คำอธิบาย*: แสดง 7 หมวดงานจริง ขั้นตอนการทำงาน (Workflow) 1–6 ข้อ บทบาท/สิทธิ์ และเมนูการทำงานที่เกี่ยวข้อง โดยไม่มีศัพท์ BPlus
3. **หน้าคู่มือการใช้งาน `/core-modules` (1366px & 1024px)**:
   - `docs/ai/erp-legacy-cleanup-shots/manual_page_1366.png` (MD5: `988efe98e3a3e6f734a25fb204c6c07a`, 159 KB)
   - `docs/ai/erp-legacy-cleanup-shots/manual_page_1024.png` (MD5: `b7129a397aba118b456fc48347cc3a09`, 146 KB)
   - *คำอธิบาย*: แสดงคู่มือมาตรฐาน JET ERP ที่ทำความสะอาดรหัส BPlus/BplusBack เรียบร้อย

---

## 7. ผลการทดสอบอัตโนมัติ (Automated Tests)

### 7.1 เทสต์ใหม่: `tests/Feature/LegacyCleanupTest.php`
- `test_removed_legacy_bplus_routes_return_404`: ตรวจสอบว่า `/bplus/pos-preparation` และ `/bplus/pos-workbench` ได้ผลลัพธ์เป็น 404 Not Found ทั้งหมด
- `test_erp_menu_does_not_contain_removed_bplus_routes`: ตรวจสอบว่าเมนู `ErpMenu` ไม่มีรายการที่ถูกลบออก
- `test_features_page_loads_and_contains_no_bplus_jargon`: ตรวจสอบว่าหน้า `/features` เปิดได้ปกติ (200 OK) และไม่มีคำว่า "BPlus", "PS1", "PS2", "PS3", "PS4", "PS5", "ลำดับงานอิง" ปรากฏบนหน้าจอ
- `test_core_modules_manual_page_loads_and_contains_no_bplus_jargon`: ตรวจสอบว่าหน้าคู่มือเปิดได้ปกติ (200 OK) และไม่มีคำว่า "BPlus" หรือ "BplusBack" ปรากฏบนหน้าจอ
- `test_kept_bplus_logic_routes_still_accessible_and_working`: ตรวจสอบว่า route ของ logic ที่เก็บไว้ (`bplus.purchase-planning`, `bplus.approvals`, `bplus.finance`, `bplus.tax`, `bplus.qr-payments`, `bplus.show-price`) ยังคงเปิดใช้งานได้ปกติ (200 OK)
- **ผลลัพธ์**: ผ่าน 5/5 เทสต์ (22 assertions)

### 7.2 ผลการรันชุดทดสอบทั้งหมดของระบบ (Full Test Suite)
```bash
php -d memory_limit=1G vendor/bin/phpunit
```
```json
{"tool":"phpunit","result":"passed","tests":867,"passed":865,"assertions":7351,"duration_ms":29792,"skipped":2,"incomplete":6}
```
**ผลสรุป**: ผ่านทั้งหมด 865 เทสต์, ข้าม 2 เทสต์, ผิดพลาด 0 เทสต์ (0 failed, 0 errors, 7,351 assertions).
