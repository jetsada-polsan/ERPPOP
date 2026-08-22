# ERP Readiness Audit — POPSTAR ERP/POS

วันที่ตรวจ: 2026-08-22 · ผู้ตรวจ: Claude · ช่วง commit: `a1b7424` (+ แก้ระหว่างตรวจ `5107003`)

เอกสารนี้ตรวจจาก **โค้ดจริงและข้อมูล production จริง** ไม่ใช่จากรายการเมนู เกณฑ์ที่ใช้คือ
"นิยามความสำเร็จ" ใน `CLAUDE_LEGACY_REBUILD_BRIEF.md` — เอกสารหนึ่งใบต้องไหลจากต้นทางถึง
stock, revenue, debtor/cash, cost, GL และรายงานได้ครบหนึ่งรอบอย่างถูกต้อง

## สิ่งที่รันจริงในรอบนี้

| คำสั่ง | ผล |
|---|---|
| `php artisan test` | 137 passed / 1,898 assertions |
| `php artisan migrate:status` (production) | ครบถึง `2026_08_02_000142` ไม่มีค้าง |
| `curl http://27.254.143.219/api/pos/ping` | 401 JSON ถูกต้อง (ระบบตอบปกติ) |
| อ่านข้อมูล production ผ่าน `tinker --execute` (SELECT ล้วน) | ดูตารางสรุปด้านล่าง |

> MSSQL legacy `192.168.88.200` ไม่ได้ถูกแตะเลยในรอบนี้ แม้แต่ `SELECT`

## ภาพรวมจากข้อมูล production จริง (สำคัญที่สุด)

ตัวเลขนี้เปลี่ยนข้อสรุปของ audit ทั้งฉบับ — โมดูลส่วนใหญ่ "มีโค้ด" แต่ **ยังไม่เคยมีใครใช้จริงเลย**

| กลุ่ม | ตาราง | จำนวนแถวจริง |
|---|---|---|
| แฟ้มหลัก | products / barcodes / prices | 5,332 / 10,374 / 14,344 |
| แฟ้มหลัก | customers / suppliers / salesmen / employees | 7,422 / 355 / 45 / 107 |
| POS | pos_receipts / pos_receipt_items | 16,557 / 60,823 |
| ขายหลังบ้าน | documents (ทุกชนิดรวมกัน) | **21** (CASH_SALE 20, STOCK_ADJUSTMENT 1) |
| ซื้อ | purchase_orders / purchase_order_receipts | **0 / 0** |
| การเงิน | payment_documents / payment_allocations / customer_open_items | **0 / 0 / 0** |
| ธนาคาร | bank_accounts / bank_statements / bank_reconciliations | **0 / 0 / 0** |
| สต๊อก | stock_movements / stock_counts / stock_lots | 61 / **0** / 2 |
| บัญชี | gl_journals / chart_of_accounts / accounting_periods | 59 / 189 / 1 |
| อื่น | fixed_assets / production_orders / members / qty_promotions | **0 / 0 / 0 / 0** |

ระบบที่มีปริมาณงานจริงตอนนี้มีอยู่ระบบเดียวคือ POS และแม้แต่ POS ก็มีบิลที่มาจากการขายจริง
ผ่าน ERP ใหม่แค่ **20 ใบ** เท่านั้น (ดู P0-1)

---

## A. พร้อมใช้งานแล้ว

เกณฑ์: มี flow ครบ + มีหลักฐานในโค้ด + มี test ที่รันผ่านจริง

### A1. POS ขายหน้าร้าน (แข็งแรงที่สุดในระบบ)

- **Flow**: device token → ping → catalog sync → cashier PIN → เปิดกะ → ขาย → คิวออฟไลน์ → ปิดกะ
- **หลักฐาน**: `routes/api.php` (18 endpoint ใต้ `/api/pos`), `app/Http/Middleware/AuthenticatePosDevice.php`,
  `app/Http/Controllers/PosController.php`, `app/Http/Controllers/Api/PosApiController.php`,
  `apps/pos-desktop/src/lib/{api,db,sync,catalog,pricing}.ts`
- **การ์ดที่ตรวจแล้วว่ามีจริง**: idempotency key กันบิลซ้ำ, `enforcedBranchId()`/`enforcedCashierId()`
  บังคับสาขาและคนขายฝั่ง server (client ส่งค่าอะไรมาก็ override), `PosPricingGuard` ตรวจราคาซ้ำฝั่ง server,
  `pos.sell` เป็น non-bypass permission (แม้ superadmin ก็ต้องถือจริง)
- **Test ที่รันผ่าน**: `PosCashierIdentityTest` (10), `PosPricingGuardTest` (8), `PosPinChangeTest` (8),
  `PosRetailControlTest` (6), `PosTransactionSafetyTest` (5), `PosDeviceConnectionTest` (5),
  `PosPaymentValidatorTest` (2), `PosReceiptTemplateTest` (3)

### A2. แฟ้มหลักสินค้าและราคา

- **หลักฐาน**: 5,332 สินค้า / 10,374 บาร์โค้ด / 14,344 ราคา อยู่บน production จริง
  พร้อม `ProductUnit`, `ProductCategory`, `ProductBrand`, `ProductDepartment`, `PriceTable`
- ตารางราคาแบบชั้นซ้อน (สาขา override → ตารางหลัก → default_price) ทำงานใน `PosController::products()`
- บาร์โค้ดชั่ง (scale PLU) ถอดน้ำหนัก/ราคาฝั่ง server ผ่าน `ScaleBarcodeService`

### A3. ต้นทุนและ stock ledger

- **หลักฐาน**: `CostingService`, `FifoStockService`, `InventoryCostCloseService`, `StockLot` (lot/expiry)
- **Test**: `InventoryCostFlowTest` (12), `InventoryApprovalAndCloseTest` (10), `ReplenishmentFlowTest` (2)
- นี่เป็นโมดูลที่ test แน่นเป็นอันดับสองรองจาก POS

### A4. สิทธิ์ / audit log / ปิดงวด

- RBAC มี non-bypass permission list สำหรับสิทธิ์ควบคุมภายใน (`User::NON_BYPASS_PERMISSIONS`)
- `AccountingPeriodGuard` + `AccountingPeriodLockTest` (4) ล็อกงวดได้จริง
- **Test**: `ManagementControlWorkflowTest` (12), `FinanceSecurityControlTest` (9), `UserManagementTest` (2)

### A5. GL posting ผูกกับ service จริง

- `GlPostingService` มี 10 entry point และถูกเรียกจาก `CashSaleService`, `CreditSaleService`,
  `PurchaseService`, `SaleReturnService`, `CreditDebitNoteService`, `CustomerPaymentService`,
  `SupplierPaymentService`, `BranchExpenseService`, `PosController`
- `gl_journals` มี `document_id` ผูกกลับเอกสารต้นทางได้ และ production Dr = Cr = 23,479.77 บาท (สมดุล)

---

## B. มีแล้วแต่ยังไม่พร้อมใช้งานจริง

### B1. ซื้อ / AP — มีโค้ด แต่ยังไม่เคยมีเอกสารสักใบ

`purchase_orders = 0`, `purchase_order_receipts = 0`, `payment_documents = 0` บน production
มี `PurchaseOrderPartialReceiptTest` แค่ **1 test** ทั้งโมดูล ยังไม่พอจะเรียกว่าผ่าน UAT
ตามเกณฑ์ 6 ข้อใน `bplusback-gap-analysis.md`

### B2. ขายหลังบ้าน — flow มี แต่ไม่มีสถานะเอกสาร

`CashSaleService`/`CreditSaleService` สร้างเอกสารด้วย `status = 'active'` ทันที ไม่มีขั้น
ร่าง → รอตรวจ → อนุมัติ → ยืนยัน ตามหลักการข้อ 1 ของ BplusBack และ `sales_postings`
ก็อ่านเฉพาะ `status = 'active'` เท่ากับว่าเอกสารมีผลทางบัญชีทันทีที่กดสร้าง

### B3. ส่งของ — เป็นแค่หน้าพิมพ์ ไม่ใช่เอกสารติดตาม

`DeliveryNoteController` มีแค่ `show()` และ `taxInvoice()` ผูกกับ route
`/documents/{document}/delivery-note` เท่านั้น ไม่มีตาราง ไม่มีสถานะ ไม่มีส่งบางส่วน
ตารางกติกาใน brief ระบุว่า "ส่งสินค้า = ติดตามการส่ง" ซึ่งยังทำไม่ได้

### B4. Approval — ไม่มีเครื่องมือกลาง

`ApprovalRequest` model ถูกใช้โดย `BplusOperationController` (หน้าเปิดดูข้อมูล legacy) เท่านั้น
ไม่มี flow ใหม่เรียกใช้เลย และ `approved_by` ปรากฏใน migration แค่ 4 ไฟล์
การอนุมัติจริงกระจายเป็น `approve()` รายบริการ (stock adjustment / transfer / damage) ไม่มี
approval matrix ตามวงเงิน/สาขา และไม่มีกฎห้ามผู้สร้างอนุมัติเอง

### B5. รายงาน — ครอบคลุมดี แต่ยังขาดของจำเป็น

มี 12 หมวด ~80 รายงานใน `ReportController::catalog()` ซึ่งถือว่ามากแล้ว แต่:

- **export เป็น CSV ฝั่ง browser อย่างเดียว** (`exportCsv()` ใน `resources/views/reports/index.blade.php`)
  ไม่มี Excel/PDF ฝั่ง server และไม่มี drill-down กลับไปเอกสารต้นทาง
- **ไม่มีหมวดเจ้าหนี้เลย** — ไม่มี AP aging, ไม่มียอดคงค้างผู้ขาย (มีแค่ "ยอดซื้อตามผู้ขาย")
- ไม่มีสมุดเงินสด/ธนาคาร, ไม่มีรายงานค่าใช้จ่ายหลายมิติ, ไม่มีรายงานมูลค่าสต๊อก
- **ไม่มี test สักตัวเดียวสำหรับรายงาน** ก่อนรอบนี้ — ซึ่งเป็นเหตุให้ P0-2 หลุดมาได้

### B6. POS desktop — ยังไม่มี gate อัตโนมัติ

`pnpm test` (vitest) ไม่ได้ถูกรันใน CI (`deploy.yml` รันแต่ `php artisan test`) และรันบน Mac
เครื่องนี้ไม่ได้ ฉะนั้นเทสต์ฝั่ง TypeScript ทั้งหมด **ไม่มีอะไรบังคับให้เขียว**

### B7. RouteIntegrityTest ถูกกันออกจาก CI

`deploy.yml:27` รัน `--filter='~^(?!.*RouteIntegrityTest).*~'` แปลว่า test ที่ตรวจว่า route
ทุกเส้นยังใช้ได้ **ไม่เคยรันตอน deploy** ซึ่งเป็นตัวที่ควรรันที่สุด

---

## C. ยังไม่มี / ต้องแก้ — จัดลำดับ P0/P1/P2

### P0-1 · ข้อมูล POS legacy ค้างอยู่ใน production ขัดนโยบายของโครงการเอง

**อาการ (ยืนยันจากข้อมูลจริง)**

| ตัวชี้วัด | ค่า |
|---|---|
| `pos_receipts` ทั้งหมด | 16,557 |
| มี `document_id` + `pos_shift_id` (ขายจริงผ่าน ERP ใหม่) | **20** |
| ไม่มีทั้งสองอย่าง (ของเก่านำเข้ามา) | **16,537** |
| เลขบิลของกลุ่มเก่า | `000101102901` … (คนละ format กับ `CS000720260706001` ของจริง) |
| ช่วงวันที่ | 2026-07-01 ถึง 2026-08-04 |
| ยอดใน `sales_postings` | **4,599,908.50 บาท** |
| ยอดใน `gl_journals` | **23,479.77 บาท** |
| แถวที่มีต้นทุน (cogs) | 20 จาก 16,557 |

**ทำไมถึงเป็น P0**

1. ขัด `LEGACY_TABLE_SCOPE_POLICY.md` และ `PROJECT_MEMORY.md` ที่เขียนไว้ชัดว่า
   "ไม่ import ประวัติ POS legacy เข้าระบบใหม่ เพราะสาขา ชื่อ terminal และเลขเอกสารเดิมเปลี่ยนแล้ว"
   commit `d24c99d` ลบ *ท่อนำเข้า* ไปแล้ว แต่ *ข้อมูลที่นำเข้าไปก่อนหน้า* ยังอยู่
2. Dashboard และรายงานขายทุกตัวอ่าน `sales_postings` → โชว์ 4.6 ล้านบาทเสมือนเป็นยอดของ ERP ใหม่
   ขณะที่บัญชีมีแค่ 23,479 บาท **ต่างกัน ~4.58 ล้านบาท กระทบยอดไม่ได้เลย**
3. 16,537 แถวไม่มีต้นทุน → รายงานกำไรขั้นต้นทุกตัวไม่มีความหมาย
4. ถ้าปล่อยไว้จนถึง cut-over จริง จะแยกไม่ออกว่ายอดไหนของเก่ายอดไหนของใหม่

**เสนอ**: ตัดสินใจก่อนทำอย่างอื่น — จะ (ก) ลบทิ้งจาก production, (ข) ย้ายไปตาราง/schema
`legacy_*` แยกแบบอ่านอย่างเดียว, หรือ (ค) เติม flag `is_legacy` แล้วกันออกจาก `sales_postings`
ทั้งสามทางต้อง backup ก่อนและต้องมีรายงานกระทบยอดก่อน/หลัง **ผมยังไม่แตะข้อมูล production ใด ๆ
รอเจ้าของสั่ง**

### P0-2 · รายงานขายนับบิลซ้ำ — แก้แล้วในรอบนี้ (`5107003`)

`sales_by_category`, `sales_by_seller`, `sales_by_category_seller` รวมสองช่องทางเองแทนที่จะอ่าน
`sales_postings` และขาดกฎครบทั้งสามข้อที่ view ใช้ ทำให้บิล POS 100 บาทหนึ่งใบรายงานเป็น 200 บาท

- ฝั่งเอกสารไม่กันเอกสารขายสดที่มีบิล POS ผูกอยู่ออก (ทุกบิล POS สร้างเอกสารผูกเสมอ)
- ฝั่งเอกสารไม่กรอง `d.status` เลย → เอกสารที่ยกเลิกยังนับเป็นยอดขาย
- ฝั่ง POS กรอง `status != 'cancelled'` แต่บิลที่ยกเลิกเก็บเป็น `'void'` → บิลที่ยกเลิกยังนับเป็นยอดขาย

แก้แล้วพร้อม `SalesReportChannelOverlapTest` 3 เคสที่เทียบยอดรายงานกับ `sales_postings`

### P0-3 · ยังไม่มี UAT ที่พิสูจน์เอกสารไหลครบหนึ่งรอบ

นี่คือ "นิยามความสำเร็จ" ที่ brief เขียนไว้ตรง ๆ และยังไม่มีอะไรพิสูจน์

| โมดูล | จำนวน test |
|---|---|
| POS | 36 |
| สต๊อก/ต้นทุน | 24 |
| ควบคุม/สิทธิ์/การเงิน | 23 |
| **ขายหลังบ้าน** | **5** (`SalesPostingLedgerTest` 1, `BookingSalesAreaTest` 4) |
| **ซื้อ** | **1** |
| **รายงาน** | **3** (เพิ่มในรอบนี้ ก่อนหน้าเป็น 0) |

**ต้องมี**: test ที่เดินเส้นเดียวยาว ๆ ตั้งแต่ PO → รับสินค้า → ต้นทุน → ขายเชื่อ → ตัดสต๊อก →
เปิดลูกหนี้ → รับชำระ → GL → เห็นตัวเลขเดียวกันในรายงาน แล้วทำซ้ำสำหรับเคสยกเลิกและคืนสินค้า

### P1-1 · `gl_journals` ไม่มีเลขที่สมุดรายวัน / ไม่มีการบังคับ Dr=Cr ต่อรายการ

ตารางเป็น flat line ไม่มี journal header ทำให้:
พิสูจน์ไม่ได้ว่าแต่ละรายการสมดุล, ไม่มีเลขอ้างอิงให้ตรวจ, และ `reverseDocument()` ใช้วิธี
`where('remark', 'like', 'VOID REVERSAL:%')` เป็นตัวกันกลับรายการซ้ำ ซึ่งพังทันทีถ้ามีคนแก้ข้อความ

### P1-2 · ไม่มีใบขอซื้อ (PR) และใบสอบราคา (RFQ)

ไม่มี model ไม่มี route — flow `ขอซื้อ → สอบราคา → PO` ที่ BplusBack ถือเป็นหลักยังขาดต้นทาง

### P1-3 · ไม่มี AP aging / ยอดคงค้างเจ้าหนี้

หมวดรายงานไม่มี `ap` เลย ขณะที่ฝั่งลูกหนี้มีครบ 9 รายงาน — ไม่สมดุลและปิดงบไม่ได้

### P1-4 · ไม่มีทะเบียนเงินมัดจำ

`deposit` พบแค่ในบริบทเช็ค (`ChequeController`) ไม่มีรับมัดจำ/จ่ายมัดจำ/ตัดมัดจำกับใบขาย-ซื้อ

### P1-5 · รายงานไม่มี drill-down และ export ที่ใช้งานจริงได้

CSV ฝั่ง browser ตัดที่จำนวนแถวที่แสดงบนหน้า (`per_page` สูงสุด 100) ผู้ใช้ที่ต้องการทั้งเดือน
จึงเอาไปกระทบยอดไม่ได้

### P1-6 · CI ยังไม่ครอบ POS desktop และกัน RouteIntegrityTest ออก

เสนอ: เพิ่ม job รัน `pnpm test` + `vue-tsc --noEmit` บน Linux runner และเอา
RouteIntegrityTest กลับเข้า CI (ถ้ามันแดง แปลว่ามีของพังจริงที่ต้องแก้ ไม่ใช่เหตุให้ปิด test)

### P2-1 · หลายสกุลเงิน

ไม่มี currency master / rate per date / FX revaluation เลย — ถ้า POPSTAR ยังซื้อขายบาทอย่างเดียว
ข้อนี้ปล่อยได้ แต่ต้องยืนยันกับเจ้าของก่อนตัดออกถาวร

### P2-2 · ข้อมูลแฟ้มหลักที่ต้องเก็บกวาด

- `warehouse_locations` id=14 ชื่อเป็น mojibake (`เธชเธดเธ...`) — migration `repair_mojibake_product_names`
  ซ่อมเฉพาะชื่อสินค้า ไม่ครอบคลุมคลัง
- มีสาขาสำนักงานใหญ่ซ้ำสองรหัส (`0001` และ `HO`) ชี้ `default_warehouse_location_id` ตัวเดียวกัน (id=1)
- `warehouses` ทั้ง 2 แถวมี `branch_id = NULL` (การแยกสาขาอาศัย `warehouse_locations` แทน ซึ่งตั้งค่าครบถูกต้องแล้ว
  แต่โครงสร้างนี้ทำให้ query ที่ไล่ผ่าน `warehouses.branch_id` ได้ผลว่าง — ตรวจก่อนเขียนรายงานใหม่)

---

## สรุปสำหรับเจ้าของโครงการ

ระบบใหม่ **ไม่ได้ขาดเมนู** — โครงสร้าง 113 model / 73 controller / 166 migration ครอบคลุมกว้างมาก
ของที่ขาดคือ **หลักฐานว่าใช้ได้จริง**: มีแค่ POS ที่มีปริมาณงานจริง โมดูลซื้อ-การเงิน-ธนาคาร-ทรัพย์สิน
ยังเป็นศูนย์ทั้งหมด และมีข้อมูลเก่าค้างอยู่ที่ทำให้ตัวเลขรายงานกับบัญชีต่างกัน 4.58 ล้านบาท

**ลำดับที่ควรทำ**: P0-1 (ตัดสินใจเรื่องข้อมูลเก่า) → P0-3 (UAT เส้นเดียวยาว) → P1-1 (journal header)
→ ที่เหลือ ทั้งหมดนี้ควรจบก่อนคิดเรื่อง cut-over ข้อมูลจริง
