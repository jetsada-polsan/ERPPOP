# 01 — Current System Audit (Evidence-Based)

> ตรวจจากโค้ดจริงในโปรเจกต์ `jeterp` ณ 2026-08-31 (routes, controllers, services, models, migrations, POS Python, SQLite schema) ไม่ใช่การเดาจากชื่อไฟล์ ทุกข้อมีหลักฐานอ้างอิง file:line

## สรุปภาพรวม 1 ย่อหน้า

ระบบปัจจุบันเป็น **Laravel 13 monolith แบบ server-rendered (Blade + Alpine.js)** ไม่ใช่ Vue 3 SPA อย่างที่คาด — Vue 3 มีอยู่จริงแต่จำกัดอยู่ 3 เกาะเล็กๆ (POS-web compat page, App Launcher, พื้นที่ทดลอง "greenfield") ส่วนแกนบัญชี/สต๊อกกลับ **แข็งแรงเกินคาด**: มี FIFO/FEFO lot ledger จริง, central `GlPostingService` ที่กันเอกสารไม่มี GL ไม่ได้, document-numbering service ที่แก้ race condition จริงมาแล้ว (มีเคสทดสอบ concurrency 84% fail ก่อนแก้), และ `erp:health`/`erp:readiness` ที่ enforce invariant สำคัญอัตโนมัติ จุดที่อ่อนสุดคือ **stock reservation ไม่มีผลจริง** (จองแล้วยังโดนคนอื่นตัดสต๊อกทับได้), **infra แบบ async ยังไม่มีเลย** (ไม่มี Queue/Redis/Reverb ใช้งานจริง ทุกอย่าง synchronous), และ **POS hardware integration ยังเป็น mock ทั้งหมด** (เครื่องพิมพ์/ลิ้นชักเงิน/จอลูกค้า)

---

## A. System Setup (Auth / RBAC / Branch / Warehouse)

### ALREADY EXISTS
- Session-guard auth มาตรฐาน (`config/auth.php:19,42-43,66`) + `ErpAuthorize` middleware บังคับทุก web route (`app/Http/Middleware/ErpAuthorize.php:26-58`): เช็คบัญชีถูกปิดใช้งาน, บังคับเปลี่ยนรหัสผ่าน, resolve สิทธิ์ที่ต้องมีจาก `RoutePermissions::resolve()` แล้ว `abort(403)`
- RBAC: `roles` ↔ `permissions` (many-to-many) ↔ `users`; ใหม่กว่านั้นมี **สิทธิ์ระดับสาขา** ผ่าน `user_branch_roles` พร้อม `effective_from/to` (`User::canAccessBranch()`, `app/Models/User.php:79-107`)
- Branch → Warehouse → WarehouseLocation เป็นลำดับชั้นจริง 3 ระดับ, `stock_balances` ผูกกับ `(product_id, warehouse_location_id)` คู่ unique (`database/migrations/2024_01_01_000018...php:12-18`)
- Audit log ครอบคลุมการเปลี่ยน role ของ user, reset PIN, align สาขา POS (`UserController.php:378-386`)

### PARTIAL
- ไม่มี CRUD UI สำหรับ Role/Permission เอง — สร้าง/แก้ได้ผ่าน migration เท่านั้น (ยืนยันว่าไม่มี `RoleController`/`PermissionController` เลย) → การเปลี่ยนนิยามสิทธิ์ไม่ถูก audit โดยแอป (เป็นการ deploy โค้ด)
- MFA มีแต่ optional ต่อ user ไม่ได้ผูกกับ role ว่าใครต้องบังคับใช้

### MISSING
- **ไม่มี Company/Tenant เหนือ Branch เลย** — grep ทั้ง `app/Models`/`database/migrations` หา `Company`/`Tenant`/`company_id` ไม่เจอ ข้อมูลบริษัทเก็บเป็น key-value ใน `AppSetting` — ระบบเป็น **single-tenant จริงในระดับโค้ด**

### PROBLEM
- `RoutePermissions::resolve()` คืน `null` เมื่อไม่เจอ route ในแมป และ `ErpAuthorize` เช็คแค่ `if ($permission && !hasPermission())` — **route ใหม่ที่ลืมเพิ่มลงแมปจะเปิดให้ user ที่ login แล้วทุกคนเข้าได้ทันทีโดยไม่มีการเช็คสิทธิ์ใดๆ** (fail-open by omission) เสี่ยงมากขึ้นเรื่อยๆ ตามที่ `routes/web.php` โตขึ้น (ตอนนี้ 693 บรรทัด)
- Superadmin bypass เป็น logic แฝง (`users.manage` + `settings.manage` พร้อมกัน = ผ่านเกือบทุกสิทธิ์) ไม่ใช่ flag ชัดเจน เสี่ยงให้สิทธิ์เกินโดยไม่ตั้งใจ

---

## B. Product / Unit / Barcode / Customer / Supplier

### ALREADY EXISTS
- Multi-barcode ต่อสินค้าจริง: `product_barcodes` แต่ละแถวมี `unit_id`, `unit_factor`, ราคาเฉพาะ, `is_active` — รองรับบาร์โค้ดแยกตามขนาดแพ็ก
- Scale-barcode (EAN-13 น้ำหนัก) รองรับจริงทั้งฝั่ง server (`scale_barcode_profiles`) และ POS client (`pos_python/barcode.py`)
- Product Category/Brand/Department เป็น lookup table แยกจริง

### PARTIAL
- **Unit conversion มีแค่ระดับบาร์โค้ด/แสดงผล ไม่มีในระดับเอกสาร** — `purchase_order_items` มีแค่ `qty`+`unit_price` **ไม่มีคอลัมน์ `unit_id` เลย** แปลว่าไม่มีการสั่งซื้อ "หน่วยลัง" แล้วแปลงเป็น "หน่วยฐาน" ที่บันทึกไว้ชัดเจนในระดับ PO line
- Customer มี credit-limit-approval workflow, sales-rep/area, CRM link, soft-delete; **Supplier ไม่มีสิ่งเหล่านี้เลย** (ไม่มี soft-delete, ไม่มี `SupplierContact`) — ไม่สมมาตรกันมาก

### MISSING
- ไม่มี per-customer price list (ราคาผูกกับ**สาขา**ผ่าน `Branch::price_table_id` เท่านั้น) — ธุรกิจขายส่งที่มักต่อรองราคารายลูกค้าถือเป็นช่องว่างจริง

---

## C. Purchase / Accounts Payable

### ALREADY EXISTS — ดีกว่าที่คาด
เอกสารเดียวไหลผ่านสถานะ `requested → approved → ordered → received/partially_received` (คอมเมนต์จริงใน `database/migrations/2024_01_01_000095...php:7-11`) ไม่ใช่หลายเอกสารแยกกัน:
- `PurchaseOrderReceivingService::receive()` — row-lock กัน double-receive, ตรวจ received ≤ outstanding, สร้าง `PurchaseOrderReceipt` เป็น audit trail ของการรับแต่ละรอบ
- `PurchaseService::create()` (transaction เดียว): สร้าง `Document` (ทำหน้าที่เป็น "ใบรับของ/invoice" ในตัว), คิดต้นทุนผ่าน `CostingService::purchaseUnitCost()` (รองรับ VAT รวม/แยก), **ตัดสต๊อกเข้าจริงผ่าน FIFO lot ledger** (`FifoStockService::receive()`), อัปเดต `average_cost`, เปิด AP ถ้าเป็นเครดิต (`SupplierLedger` + `SupplierOpenItem`), post GL เสมอ
- จ่ายเงินแยกเป็น `SupplierPaymentService::create()` — สร้างใบสำคัญจ่าย, ลงทะเบียน Cheque อัตโนมัติถ้าเลือกจ่ายเช็ค, apply กับ open item แบบ oldest-first, post GL

### PARTIAL
- **ไม่มี Withholding Tax ในขาจ่ายซัพพลายเออร์เลย** — `SupplierPaymentService` ไม่มีฟิลด์ WHT ใดๆ (WHT มีแค่ฝั่ง `BranchExpense` ซึ่งเป็นค่าใช้จ่ายทั่วไปของสาขา ไม่ใช่ฝั่งจ่ายซัพพลายเออร์หลัก) — สำหรับธุรกิจไทยที่ต้องหัก ณ ที่จ่ายค่าบริการ/ขนส่งจากซัพพลายเออร์ ถือเป็นช่องว่างจริง
- ไม่มี Supplier Credit/Debit Note หรือ Purchase Return service เลย (มีแค่ฝั่งลูกค้า)
- ไม่มี Tax Invoice แยกจากเอกสารซื้อ (ใช้เอกสารเดียวกันพิมพ์ทุกฟอร์แมต)

### MISSING
- Purchase Request แบบวัตถุอนุมัติแยก (มีแค่สถานะ `requested` บนแถวเดียวกัน)

---

## D. Document Numbering / Sequence

### ALREADY EXISTS — แข็งแรง, ผ่านบทเรียนจริงมาแล้ว
`DocumentNumberGenerator.php` มีคอมเมนต์เล่าเหตุการณ์จริง: วิธีเดิม (`COUNT(*)+1`) ทดสอบ 10 process × 5 เอกสารพร้อมกันบน PostgreSQL → **สำเร็จ 8 ล้มเหลว 42 (84%)** แก้ด้วยตาราง `document_sequences` unique `(scope, period)` + `SELECT...FOR UPDATE` ในทรานแซกชัน + `insertOrIgnore` (หลีกเลี่ยง PostgreSQL SQLSTATE 25P02 ที่ทำให้ทั้งทรานแซกชันพังถ้าใช้ insert-then-catch) รูปแบบเลข `{prefix}{branch_code}{Ymd}{seq:03d}`

### PARTIAL
- ไม่มีแนวคิด fiscal year — period คือ "วัน" ไม่ใช่ "ปีบัญชี" รีเซ็ตรายวัน
- `%03d` ไม่ hard-cap ที่ 999 (ยอมให้เกินแต่ format เพี้ยน)
- Book-based numbering (`nextInBook()`) กับ type+branch numbering (`next()`) เป็นคนละ code path ต้อง caller เลือกเองให้ถูก ไม่ enforce เชิงโครงสร้าง

---

## E. Document Architecture

### ALREADY EXISTS
`documents` table มี `document_type_id, branch_id, doc_number(unique per branch), doc_date, customer_id, supplier_id, status(varchar20), total_amount, created_by, cancelled_at` และ `document_types` มี flag ประกาศพฤติกรรม (`affects_stock/affects_ar/affects_ap`) — ดีกว่า boolean-soup ล้วนๆ

### PROBLEM — status ไม่ใช่ state machine จริง
`status` เป็น varchar(20) เปล่าๆ ไม่มี CHECK/enum/ตัวควบคุมกลาง แต่ละโมดูลตั้งค่าคำที่ต่างกันเองบนคอลัมน์เดียวกัน: `'active'` (ขาย/โอนสต๊อก), `'pending_approval'`/`'rejected'` (ปรับสต๊อก/โอนสาขา), `'posted'`/`'pending_adjustment'` (นับสต๊อก — คนละความหมายกับสถานะขาย) ไม่มี `approval_status`, `posted_at`, `reference_document_id`, `uuid` เลยบนตาราง `documents` ฟังก์ชัน cancel ทั่วไปก็ไม่มี (มีแค่ 2 endpoint เฉพาะจุด: Cheque, PurchaseOrder)

พบไฟล์ขยะ `app/Http/Controllers/PosController.php.bak.20260712111108` ค้างอยู่ใน `app/` ด้วย

---

## F. Stock Ledger / Costing / Lot-FEFO / Reservation

### ALREADY EXISTS — สถาปัตยกรรม 3 ชั้นจริง
`stock_movements` (immutable, insert-only) + `stock_lots` (per-lot remaining_qty/unit_cost/expiry) + `stock_balances` (cache on_hand_qty/reserved_qty) — ไม่ใช่คอลัมน์ `qty` เดียวที่แก้ทับ

**Costing คู่แบบตั้งใจ**: moving-average ลง `products.average_cost` (สำหรับดูวิเคราะห์) + **FIFO lot cost จริงสำหรับ COGS/GL** (`CostingService::unitCostFromAllocations()`) — คอมเมนต์ประกาศชัดว่าตั้งใจแยกสองค่านี้

**FEFO ใช้งานจริง ไม่ใช่แค่ป้าย**: `FifoStockService::issue()` เรียง `expiry_date ASC (nulls last), received_date, id` เมื่อ `product.tracks_expiry=true`, กันขายของหมดอายุถ้า `expiry_sale_policy='block'`, ใช้ `lockForUpdate()` กันสภาวะแข่งขัน — เป็น opt-in ต่อสินค้า ไม่ใช่ global

### PROBLEM — จองสต๊อกไม่มีผลจริง (สำคัญที่สุดในหมวดนี้)
`BookingService::create()` เพิ่ม `reserved_qty` โดย **ไม่เช็ค cap ใดๆ กับ on_hand_qty เลย** และจุดตัดสต๊อกจริงทุกจุด (`FifoStockService::issue()`) **อ่านแค่ `on_hand_qty` ไม่เคยอ่าน `reserved_qty`** ผลคือ: ลูกค้า A จองสินค้าตัวสุดท้าย → ขายหน้าร้าน/บิลอื่นตัดสต๊อกตัวเดียวกันได้ทันที (เพราะ on_hand ยังพอ) → ตอนแปลงใบจอง A เป็นบิลจริงจึงพบว่าสต๊อกหมดไปแล้ว `reserved_qty` เป็นแค่ตัวเลขในรายงาน ไม่ใช่ตัวกันชนจริง — **นี่คือ Available = On Hand - Reserved ที่โจทย์ขอ ยังไม่ได้ implement จริง แม้จะมีคอลัมน์ครบแล้ว**

### ALREADY EXISTS (adjustment/transfer/count)
Adjustment/Damage-write-off มี 2-phase (สร้าง→อนุมัติ, self-approve ถูกบล็อก), ตีมูลค่าจาก FIFO lot ที่ตัดจริง (ไม่ใช่ average cost), post GL เข้าบัญชี `5030 ผลต่างจากการปรับปรุงสินค้าคงเหลือ` เสมอ Stock Count = สร้าง adjustment อัตโนมัติจากส่วนต่าง (ใช้ pipeline เดียวกัน) Transfer พก lot provenance (cost/expiry/quality) ข้ามคลังถูกต้อง แต่**ไม่ post GL เลย** (โอนภายในบริษัทเดียวถือว่าโอเค แต่ถ้าจะทำ branch เป็น cost center แยกในอนาคตต้องเพิ่ม)

---

## G. Accounting Engine

### ALREADY EXISTS — hybrid ที่ดีกว่าที่กลัว แต่ไม่ใช่ posting-rule table เต็มรูป
ไม่มีตาราง `posting_rules` — โครงสร้าง Dr/Cr ต่อ transaction type ถูก hardcode เป็น PHP หนึ่งเมธอดต่อประเภท ใน `GlPostingService` (`postCashSale`, `postCreditSale`, `postPurchase`, `postSaleReturn`, `postCreditNote/DebitNote`, `postInventoryAdjustment`, `postCashTransfer`, `postExpense`, `postCustomerReceipt`, `postSupplierPayment`) **แต่การเลือกบัญชีจริงเป็น data-driven**: `chart_of_accounts.default_role` (เช่น `ROLE_CASH`, `ROLE_SALES_REVENUE`, `ROLE_VAT_OUTPUT`) resolve runtime ผ่าน `GlPostingService::role()` — เปลี่ยนบัญชีได้โดยไม่แก้โค้ด แต่เพิ่ม transaction type ใหม่ต้องแก้โค้ดเสมอ

ถ้า mapping บัญชีไม่ครบ **ระบบปฏิเสธการบันทึกทันที** (throw RuntimeException) — กันเอกสารไม่มี GL คู่ได้จริง ไม่ใช่แค่ documentation Idempotent: `postDocument()` ลบ GL เดิมของ `document_id` ก่อนเขียนใหม่เสมอ — repost ซ้ำไม่ duplicate

Period-close บังคับจริงที่ระดับ **Model Observer** (`DocumentObserver`, `GlJournalObserver`, `StockMovementObserver` เรียก `AccountingPeriodGuard::assertOpen()`) ไม่ใช่แค่จุดเรียกบางจุด

Financial Statement (Trial Balance/P&L/Balance Sheet) คำนวณจริงจาก `gl_journals` ไม่ใช่ stub (`FinancialStatementController`)

### PROBLEM
- `gl_journals` เป็นตารางแบนตารางเดียว (ไม่มี journal header/batch, ไม่มีเลขที่ voucher, ไม่มี created_by/posted_by บนแถว) — ตรงข้ามกับ "Journal Entry → Journal Lines" สองชั้นตามสถาปัตยกรรมที่โจทย์ขอ
- Docblock ของ `GlPostingService` เขียนไว้ว่า "จงใจให้แคบ ลง journal แค่ payment" ซึ่ง**ไม่ตรงกับโค้ดจริง**ที่ post ครบทุกประเภทแล้ว — เอกสารกับโค้ดไม่ตรงกัน เสี่ยงให้คนต่อไปเข้าใจผิด
- **`SaleReturnService::create()` ขัดกับกติกาที่ทีมตกลงไว้เอง**: `docs/ai/PROJECT_MEMORY.md` บอกว่าคืน/ลดหนี้ต้องอนุมัติก่อนย้อนรายได้ แต่โค้ดจริงตั้ง `status='active'` และ post GL ทันทีไม่มี approval gate (ต่างจาก `CreditDebitNoteService` ที่ทำถูกตามกติกา คือมี `pending_approval` + `approveCredit()`) — เป็นความไม่สอดคล้องกันระหว่าง 2 เส้นทางคืนสินค้าที่ควรพฤติกรรมเดียวกัน

---

## H. Tax (VAT / Withholding)

### ALREADY EXISTS
- VAT input/output แยกบัญชีจริง (ไม่ใช่ column tax_amount แบน), มี `vat_rates` แบบ effective-dated
- **ภ.พ.30 ใช้งานได้จริง**: `TaxComplianceService::rows()` รวม output VAT จากขาย/คืน/ลดหนี้-เพิ่มหนี้ + input VAT จากซื้อ/ค่าใช้จ่าย ส่งออก CSV จริง
- โครง e-Tax invoice (UUID+SHA-256 payload) เตรียมพร้อมส่ง provider เซ็นต์ (ระบุชัดว่ายังไม่ได้ submit จริงไปกรมสรรพากร — ตรงไปตรงมา ไม่ over-claim)
- WHT บันทึกได้ที่ `branch_expenses` + โพสต์ GL เข้าบัญชีหนี้สิน WHT ได้จริง

### PARTIAL/PROBLEM
- **ไม่มีตารางอัตราภาษีหัก ณ ที่จ่าย** — พนักงานพิมพ์ % เองทุกครั้ง ไม่มี default ตามหมวดค่าใช้จ่าย (3% บริการ, 1% ขนส่ง ฯลฯ)
- ไม่มีฟิลด์เลขที่/วันที่ใบหัก ณ ที่จ่าย (`wht_cert_no`) เก็บเลย — จำเป็นสำหรับยื่น ภ.ง.ด.3/53 จริง
- `withholding_form` เป็น free-text ไม่ enum — พิมพ์ผิดได้โดยไม่มี validation

---

## I. Reporting

### ALREADY EXISTS — เยอะและใช้งานจริง ไม่ใช่ stub
`ReportController` (2,740 บรรทัด) ขับเคลื่อนด้วยตาราง `report_definitions` (เปิด/ปิดรายงานได้โดยไม่ deploy) ครอบคลุมจริง: sales (daily/by branch/by staff/by category/top products/gross margin/loss-sales), VAT ซื้อ-ขาย, inventory (stock balance, stock alert, **expiring stock จริงจาก lot**, stock card, transfer), AR/AP aging เต็มรูป, POS analytics, audit (void/deleted bill) — Trial Balance/P&L/Balance Sheet อยู่แยกใน `FinancialStatementController` และคำนวณจริงจาก GL

### MISSING
- ไม่มีรายงาน slow-moving/dead-stock (มีแค่ low-stock กับ expiring)
- ไม่มี cash-flow statement

---

## J. POS (Python + PySide6) และ Offline Sync

### ALREADY EXISTS — offline-first ของจริง ออกแบบดีกว่าที่คาด
- เขียนลง SQLite ก่อนเสมอ (`PosService.checkout()` ไม่มี network call ในเส้นทางนี้เลย) แล้วค่อย sync ผ่าน `sync_outbox` โดย worker แยก (adaptive poll 5s/30s)
- **Idempotency ครบวงจรทั้งสองฝั่ง**: client ส่ง `sale_uuid` เป็น `Idempotency-Key`, server (`PosApiController::checkout()`) lock แถว `pos_api_idempotency`, ถ้า retry ด้วย key เดิมจะ **ตอบ response เดิมที่เคยสำเร็จซ้ำ** ไม่ประมวลผลซ้ำ — ตรงตามที่โจทย์ขอเป๊ะ
- เลขใบเสร็จ **server เป็นผู้ออกเสมอ** ภายใต้ row-lock ในทรานแซกชันเดียวกับ checkout เลขฝั่ง client เป็นแค่ id ท้องถิ่น ไม่ชนกันได้แม้หลายเครื่อง offline พร้อมกัน
- Cashier/PIN ผูกกับ**อุปกรณ์**ฝั่ง server จริง (`PosDevice::markCashierVerified`, `PosController::enforcedCashierId()`) — ส่ง `cashier_id` ปลอมมาทาง client ไม่ผ่าน, server จะ substitute เป็น null แล้ว fail แทน
- Scale-barcode (EAN-13 น้ำหนัก) decode จริง โปรไฟล์มาจาก server ไม่ hardcode

### PROBLEM
- **`PosApiController::checkout()` บังคับ `allow_negative_stock=true` ทุกครั้งโดยไม่มีทางปิด** — ทุกบิล POS ขายติดลบสต๊อกได้เสมอ ไม่มี toggle ระดับสาขา/สินค้า
- **ปุ่ม Void ในหน้าจอ PySide6 จริงไม่เชื่อมกับฟังก์ชัน void ที่มีอยู่** — backend/service/sync layer ทำ void ไว้ครบและมีเทสต์ แต่ `ui.py` ปุ่มที่ชื่อ voidBtn จริงๆ คือ "ลบรายการในตะกร้า" ไม่ใช่ยกเลิกบิลที่เสร็จแล้ว — **แคชเชียร์หน้างานไม่มีทาง void บิลจากจอจริงในตอนนี้**
- `enforcedBranchId()`/`enforcedCashierId()` อ่าน global `request()` แทนพารามิเตอร์ `$request` ที่ส่งเข้าเมธอด — ปัจจุบันไม่พังเพราะทางเดินการเรียกบังเอิญตรงกัน แต่เป็นกับดักถ้ามีการ refactor ภายหลัง (sub-request/queued job/artisan/test เรียกตรง)

### MISSING (ฮาร์ดแวร์)
- เครื่องพิมพ์ใบเสร็จจริง — ปัจจุบันมีแค่ `mock_printer.py` เขียนไฟล์ `.txt` ไม่มีโค้ด ESC/POS/USB/Serial ใดๆ ในโปรเจกต์เลย (ไม่มี dependency ด้วย)
- ลิ้นชักเก็บเงิน — มีแค่ dropdown settings ไม่มีคำสั่งเปิดจริง (ต้องพ่วงกับเครื่องพิมพ์ ESC/POS ที่ยังไม่มี)
- จอแสดงราคาลูกค้า (2nd screen) — ไม่มีเลยแม้แต่ schema

---

## K. Infra: Queue / Redis / Realtime / Frontend

### MISSING ทั้งหมด — ยืนยันแล้วไม่ใช่แค่ "ยังไม่ทดสอบ"
- **Queue**: ไม่มี `app/Jobs`, `app/Events`, `app/Listeners` เลย ไม่มีคลาสใดใช้ `ShouldQueue` — ทุกอย่าง (checkout, void, sync, report) รันซิงโครนัสในคำขอเดียวแม้ `QUEUE_CONNECTION=database` จะตั้งไว้
- **Redis**: ตั้งค่า env ครบ (host/port) แต่ cache/session/queue ทั้งหมดใช้ driver `database` จริง ไม่มีโค้ดที่เรียก `Redis::` เลยสักที่ — เป็น option ที่ไม่มีใครเปิดใช้
- **Realtime/Reverb**: ไม่มี `laravel/reverb`, ไม่มี `laravel-echo`, ไม่มี `config/broadcasting.php`/`routes/channels.php` เลย — ไม่มีฟีเจอร์เรียลไทม์ใดๆ แม้แต่โครง เป็นงานอนาคตล้วนๆ
- **Frontend**: ระบบ ERP หลักเป็น **Blade + Alpine.js** (66 ไฟล์ใช้ `x-data`) — Vue 3 มีจริงแต่จำกัดแค่ 3 เกาะเล็ก (POS-web เปรียบเทียบ, App Launcher, พื้นที่ทดลอง) การตั้งสมมติฐานว่า "Frontend คือ Vue 3" ไม่ตรงกับของจริง คอมเมนต์โค้ดหลายจุดยังอ้างอิง "POS desktop (Tauri)" ทั้งที่ของจริงตอนนี้คือ Python/PySide6 แล้ว

## L. API Surface

`routes/api.php` มีแค่ 32 บรรทัด ทั้งหมดเป็น `/api/pos/*` (ผ่าน `pos.device` middleware, Bearer token) บวก 1 endpoint `/api/legacy-backoffice/summary` (HMAC-signed, รับสรุปยอดจากระบบเก่า) **ไม่มี `/api/accounting`, `/api/reports`, `/api/customers`, `/api/sync` ทั่วไปเลย** — ERP ส่วนที่เหลือทั้งหมดเป็น web/session-based ล้วน ไม่มี REST API แยก ไม่มี versioning

---

## M. Health/Readiness Gates ที่มีอยู่แล้ว (ใช้เป็นฐานอ้างอิง invariant)

- `erp:health` — DB, migration ค้าง, backup อายุไม่เกิน (default 26 ชม.), **เอกสารขายยืนยันแล้วต้องมี GL เสมอ** (มีเคสจริงที่เคยพลาด 5 บิลก่อนมีเช็คนี้), storage เขียนได้, failed queue jobs
- `erp:readiness` — เหมือนข้างต้น + ตรวจ backup checksum จริง (`hash_equals` กับ `.sha256`), POS device ทุกตัวต้องผูก branch+user ครบ, ไม่มี idempotency ค้างสถานะ `processing` เกิน 10 นาที

ทั้งสองคำสั่งนี้คือ "invariant ที่ทีมถือว่า must-be-true" อยู่แล้ว ควรใช้เป็นฐานขยายต่อ ไม่ใช่สร้างระบบตรวจสุขภาพใหม่คู่ขนาน
