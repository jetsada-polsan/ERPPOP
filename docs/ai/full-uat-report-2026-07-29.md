# Full UAT ระบบ POPSTAR ERP / POS

วันที่ตรวจ: 29 กรกฎาคม 2569  
Commit ที่ตรวจ: `4f03b02`

## ผลรวม

ไม่มี automated test ล้มเหลวในรอบนี้

| ชุดตรวจ | ผล |
|---|---|
| Laravel Feature/Unit | ผ่าน 110 tests / 1,834 assertions |
| POS Desktop Vitest | ผ่าน 8 tests / 2 test files |
| POS Desktop typecheck | ผ่าน `vue-tsc --noEmit` |
| POS Desktop production build | ผ่าน 1,570 modules |
| Route integrity | ผ่าน 349 routes |
| Blade cache | ผ่าน |
| Production health | ผ่าน DB, migration, backup, storage, queue |
| Production HTTP | login 200, `/pos` 302, `/dashboard` 302 เมื่อยังไม่ login |

หมายเหตุ: 302 ของ `/pos` และ `/dashboard` คือผลที่ถูกต้องของการบังคับ login
ไม่ใช่ server error

## สถานะรายเมนู

| เมนู/โมดูล | สถานะ | หลักฐานที่ผ่าน | สิ่งที่ยังต้องทำ |
|---|---|---|---|
| ข้อมูลตั้งต้น / สินค้า / พนักงาน | ผ่าน automated | Employee, ModuleControl, RouteIntegrity | UAT ผู้ใช้จริงเรื่อง POP code, barcode, VAT และ Lot |
| POS หน้าร้าน | ผ่าน automated | pricing guard, cashier identity, payment, retail control, idempotency | ยิงขายจริงพร้อมเครื่องพิมพ์และลิ้นชัก |
| POS โปรโมชั่น | ผ่าน Desktop UAT | bundle, บาท, %, VAT, free item | ยืนยันกับข้อมูลสินค้าจริงบน Windows |
| POS ออฟไลน์ / Sync | ผ่าน logic UAT | pending/failed retry, stop on error, sync lock | ไฟดับและเปิดโปรแกรมใหม่บน Windows |
| จัดซื้อ / AP | ผ่าน automated | PO บางส่วนและ replenishment | เอกสารจริง PO-รับ-ใบกำกับ-จ่าย |
| รับสินค้า | ผ่าน automated บางส่วน | partial receipt และ cost flow | ทดสอบผู้ใช้จริงหลาย Lot/ส่วนลด/ค่าใช้จ่าย |
| คลังหลายสาขา | ผ่าน automated | approval/close, transfer/count workflows | รับโอนขาด/เสียหายและอุปกรณ์ scanner |
| ปรับคลัง / Stock adjustment | ผ่าน automated | maker-checker และ inventory approval | ทดสอบใบปรับจริงก่อน post |
| ต้นทุนสินค้า / COGS | ผ่าน automated | FIFO, average cost, bundle cost, period close | กระทบยอด Stock Valuation กับ GL จริง |
| ขายเชื่อ / AR | รอ UAT ผู้ใช้จริง | route และ accounting controls | quotation-booking-delivery-invoice-AR-payment-return |
| ผลิต / แปรรูป / จัดชุด | รอ UAT ผู้ใช้จริง | โมดูลและ route มีในระบบ | รับวัตถุดิบ, สร้าง batch, แบ่งถุง, label, ตัดต้นทุน |
| บัญชี / ปิดงวด | ผ่าน automated | accounting period, monthly accounting, finance security | กระทบยอดกับสำนักงานบัญชีจากข้อมูลจริง |
| ภาษี / E-Tax | ผ่านบางส่วน | VAT/GL และ accounting tests | export/import format และตรวจไฟล์กับสำนักงานบัญชี |
| Statement / สลิป / Reconcile | ผ่านบางส่วน | monthly accounting route/control | นำ Statement และสลิปจริงมาจับคู่ |
| เงินสด / กะ / Z Report | ผ่าน automated | retail control, cash movement, shift tests | นับเงินสดและพิมพ์ Z Report หน้างานจริง |
| Security / MFA / Audit | ผ่าน automated | MFA, PIN, role, session, maker-checker | เปิด MFA ให้ผู้ใช้สำคัญครบทุกบัญชี |
| Backup / Restore | health ผ่าน, UAT รอ | health check และ backup status ผ่าน | restore drill แยกฐานทดสอบและตรวจ checksum |
| Integration / Import / Sync | ผ่าน automated บางส่วน | sync branch/cashiers, route integrity | ทดสอบไฟล์ข้อมูลจริงและป้องกันข้อมูลซ้ำ |
| Hardware POS | รอทดสอบจริง | hardware profile ถูกส่งถึง Desktop | Windows printer, cash drawer, scanner, scale, display |

## ขั้นตอน UAT ที่รันและผ่าน

### 1. ซื้อและรับเข้า

1. สร้างสินค้าและผู้ขาย
2. สร้าง PO พร้อมจำนวนและราคาซื้อ
3. แยกผู้ขอซื้อกับผู้อนุมัติ แล้วทดสอบห้ามอนุมัติตนเอง
4. รับสินค้าเพียงบางส่วน
5. ตรวจสต็อกเพิ่มเฉพาะจำนวนที่รับ และ PO ยังมียอดค้าง
6. รับส่วนที่เหลือ แล้วตรวจว่า PO ปิดเมื่อครบ

ผล: **ผ่าน automated** จาก `PurchaseOrderPartialReceiptTest`,
`ReplenishmentFlowTest` และ `InventoryCostFlowTest`

### 2. ขาย POS และชำระเงิน

1. Login แคชเชียร์ด้วย PIN
2. เปิดกะและเงินทอนต้นกะ
3. ยิงสินค้า 2 รายการ
4. ทดสอบเงินสดไม่ครบ ต้องถูกปฏิเสธ
5. ทดสอบชำระสำเร็จและเงินทอน
6. ตรวจ idempotency key ไม่ให้บิลซ้ำ
7. ตรวจ Stock movement, receipt และ queue

ผล: **ผ่าน automated** จาก POS identity, payment, retail control และ transaction safety

### 3. โปรโมชั่นและกำไร

1. สินค้า 40 บาท จำนวน 3 ชิ้น
2. ตั้งโปร 3 ชิ้น 100 บาท
3. ตรวจยอดก่อนลด 120 บาท ส่วนลด 20 บาท ยอดสุทธิ 100 บาท
4. ทดสอบครบ 2 ลด 10 บาท และครบ 2 ลด 10 เปอร์เซ็นต์
5. ทดสอบซื้อ 2 แถม 1
6. ตรวจ VAT คำนวณจากยอดหลังโปรโมชั่น
7. ตรวจ payload ที่ส่ง ERP ใช้ราคาที่คำนวณแล้ว

ผล: **ผ่าน POS Desktop UAT 5 tests**

### 4. โอนและปรับคลัง

1. สร้างใบขอโอนจากคลังปลายทาง
2. ต้นทางอนุมัติและส่งออก
3. ตรวจยอดอยู่ระหว่างทาง
4. ปลายทางรับจริงน้อยกว่ายอดส่ง
5. สร้าง Stock count แบบ blind count
6. สร้างใบปรับและให้ผู้ตรวจคนละบัญชีอนุมัติ

ผล: **ผ่าน automated บางส่วน** จาก inventory approval/close และ route tests;
ผลต่างรับโอนและ scanner ยังต้องทำจริง

### 5. ต้นทุนและปิดงวด

1. รับสินค้าเดียวกันสองครั้งด้วยต้นทุนต่างกัน
2. ขายสินค้าออก
3. ตรวจ FIFO/FEFO และ COGS
4. ตรวจต้นทุนเฉลี่ยงวด
5. ปิดงวด
6. ลองแก้เอกสารย้อนหลัง

ผล: **ผ่าน automated** จาก `InventoryCostFlowTest` และ `AccountingPeriodLockTest`

### 6. บัญชี ภาษี และกระทบยอด

1. สร้างรายการซื้อ ขาย รับเงิน และจ่ายเงิน
2. ตรวจ debit/credit ใน GL
3. ตรวจ VAT และรายงานรายเดือน
4. นำเข้า Statement
5. จับคู่รายการกับสลิป/รายการโอน
6. ตรวจรายการ mismatch

ผล: **ผ่าน automated บางส่วน**; Statement/สลิปและ export ให้สำนักงานบัญชียังต้อง
ทดสอบด้วยไฟล์จริง

### 7. Backup และกู้คืน

1. สั่ง Backup
2. ตรวจไฟล์และ checksum
3. Restore ลงฐานทดสอบแยกจาก production
4. เปิดตรวจสินค้า บิล และยอด GL
5. จำลองไฟดับแล้วเปิด POS ใหม่

ผล: **Health ผ่าน แต่ full restore drill ยังรอ Windows/Tauri จริง**

## รายการที่ยังไม่ควรใช้คำว่า “ผ่านเต็มระบบ”

- ขายเชื่อ/AR แบบครบตั้งแต่ quotation ถึงรับชำระ
- ผลิต/แปรรูป/แบ่งถุงและพิมพ์ฉลากเครื่องชั่ง
- Statement และสลิปจริง
- Export ให้สำนักงานบัญชี/หน่วยงานรัฐ
- Restore SQLite และไฟดับบนเครื่อง Windows จริง
- อุปกรณ์ POS จริงทุกยี่ห้อ

## ข้อสรุป

สถานะปัจจุบันคือ **ระบบธุรกรรมหลักผ่าน automated UAT และ Desktop logic UAT**
แต่ยังเป็น **Conditional UAT Pass** จนกว่าจะมีหลักฐานจากการกดใช้งานจริง,
Windows native build, hardware และ restore drill ครบตามรายการด้านบน
