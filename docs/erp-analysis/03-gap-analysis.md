# 03 — Gap Analysis: PEAK Account (แนวคิด) vs jeterp (ปัจจุบัน)

> เอกสารนี้เปรียบเทียบ "แนวคิด" ของ PEAK Account (อ้างอิงจาก `02-peak-benchmark.md`) กับสถานะจริงของ jeterp (อ้างอิงจาก `01-current-system-audit.md`) เพื่อหาช่องว่าง (gap) ที่ต้องปิดก่อนที่ระบบจะเป็น ERP ที่สมบูรณ์
>
> **หลักการสำคัญ**: PEAK เป็น SME Accounting SaaS ที่เน้นความง่ายสำหรับธุรกิจขนาดเล็ก-กลาง ไม่ใช่ระบบที่ต้องเหนือกว่า jeterp ทุกด้าน — จุดที่ jeterp ต้อง "หนักกว่า PEAK" อยู่แล้วและต้องรักษาไว้คือ: **POS, Offline-first, Warehouse/Lot/FEFO, Multi-Branch, Barcode Scale (ชั่งน้ำหนัก), ธุรกิจขายส่ง (Wholesale), สินค้าแช่แข็ง (Frozen Food), และ Internal Operation** จุดเหล่านี้ PEAK แทบไม่รองรับเลยหรือรองรับแบบผิวเผิน จึงไม่ใช่ gap ของเรา แต่เป็นจุดแข็งที่ต้องคงไว้และพัฒนาต่อ

Priority: **P0** = ต้องแก้ก่อนขึ้น Production จริงจัง (risk สูง/ข้อมูลผิด/เงินหาย) · **P1** = Core ERP ที่ควรมีในเฟสถัดไป · **P2** = สำคัญแต่ยังไม่เร่งด่วน · **P3** = อนาคต/nice-to-have

---

## A. System Setup / RBAC

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Company/Tenant | มี concept "กิจการ" แยกจาก user, 1 user เข้าได้หลายกิจการ | ไม่มีระดับ Company/Tenant เลย —ระบบเป็น single-tenant โดยสมบูรณ์ (ยืนยันจาก schema: ไม่มีตาราง `companies`/`tenants`, ทุกตารางอิงตรงกับ branch) | MISSING (by design) | Multi-company/tenant switching | **ไม่แนะนำให้ทำตอนนี้** — jeterp ถูกออกแบบมาเป็น single-tenant สำหรับกิจการเดียวที่มีหลายสาขา ถ้าต้องรองรับหลายกิจการในอนาคตค่อยออกแบบ tenant layer แยกเป็นโปรเจกต์ใหม่ ไม่ใช่ retrofit | P3 |
| Role/Permission | 8 role สำเร็จรูป (Owner/Accountant/Sales/Purchase/Stock/Approver/Viewer/Cashier) ผูกกับสิทธิ์ระดับเมนู | มี `roles`↔`permissions`↔`users` แบบ custom + ใหม่กว่าคือ `user_branch_roles` ที่ scope ตามสาขา (ยืดหยุ่นกว่า PEAK เพราะ PEAK ไม่มี branch-scoped role) | PARTIAL/PROBLEM | `RoutePermissions` เป็น static string-map และมีความเสี่ยง "fail-open by omission" (route ที่ไม่ได้ map ไว้ = เข้าได้โดยไม่เช็คสิทธิ์); superadmin bypass แบบ implicit ทุกจุด | เพิ่ม test/command ที่ scan ทุก route ใน `routes/web.php`+`routes/api.php` แล้วเช็คว่า map ไว้ใน `RoutePermissions` ครบทุกเส้น (default-deny แทน default-allow) | P0 |
| User Onboarding/Invite | ระบบเชิญผู้ใช้ผ่านอีเมล | สร้าง user โดย admin โดยตรง ไม่มี email-invite flow | MISSING | Self-service invite/accept flow | Nice-to-have เท่านั้น ธุรกิจขนาดนี้ admin สร้าง user เองได้ ไม่ใช่ priority | P3 |
| Numbering Setup (เลขที่เอกสาร) | ตั้ง prefix/running number ต่อประเภทเอกสารผ่าน UI setup | มี `document_sequences` + `DocumentNumberGenerator` ที่ concurrency-safe ด้วย `SELECT...FOR UPDATE` (แก้ปัญหาเดิมที่ COUNT(*)+1 fail 84% ภายใต้ concurrent load — มี comment บันทึกไว้ในโค้ดจริง) | ALREADY EXISTS (ดีกว่า PEAK ในแง่ concurrency safety) | UI ตั้งค่า prefix ต่อ branch/ปีงบ อาจยังไม่ครบทุก entity | เพิ่มหน้า setting ให้ config ได้ครบทุกประเภทเอกสาร แต่ไม่ต้องแตะ engine ที่ทำงานถูกต้องอยู่แล้ว | P2 |

---

## B. Product / Unit / Barcode

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Multi-unit ต่อสินค้า | มีหน่วยนับหลายระดับต่อสินค้า (เช่น ลัง/แพ็ค/ชิ้น) พร้อมอัตราแปลง | มี multi-unit + multi-barcode ต่อสินค้าจริง และมี **scale-barcode (EAN-13 น้ำหนัก)** ซึ่ง PEAK ไม่มีเลย — จำเป็นสำหรับสินค้าชั่งน้ำหนัก/แช่แข็ง | ALREADY EXISTS (เหนือกว่า PEAK) | — | คงไว้ ไม่ต้องแก้ | — |
| หน่วยนับระดับเอกสาร (unit conversion ต่อบรรทัด) | ทุกเอกสาร (SO/PO/Invoice) เลือกหน่วยนับต่อบรรทัดได้ พร้อมแปลงราคา/ต้นทุนอัตโนมัติ | `sale_order_items`/`sale_items` มี unit conversion แต่ **`purchase_order_items` ไม่มี `unit_id`** เลย — PO บันทึกได้แค่หน่วยฐานเดียว | PROBLEM | unit_id + conversion บนฝั่ง PO/รับสินค้า | เพิ่ม `unit_id` + `unit_conversion_rate` ใน `purchase_order_items`/`purchase_receipt_items` ให้สมมาตรกับฝั่งขาย — เป็นช่องว่างจริงที่กระทบการสั่งซื้อสินค้าที่ผู้ขายส่งเป็นลัง | P1 |
| Barcode/SKU | 1 บาร์โค้ดต่อสินค้า (มาตรฐาน) | Multi-barcode ต่อสินค้า + scale-barcode profile ที่ config ได้ฝั่ง server | ALREADY EXISTS (เหนือกว่า PEAK) | — | คงไว้ | — |

---

## C. Customer / Supplier (Contact Master)

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| โครงสร้าง Contact | Customer/Supplier ใช้โครงสร้างข้อมูลเดียวกัน (unified contact) slot บทบาทได้ทั้งคู่ | Customer และ Supplier เป็นคนละตาราง คนละโครงสร้าง **ไม่สมมาตร**: Customer มี credit-approval workflow + CRM fields + soft-delete, Supplier ไม่มีทั้งหมดนี้ | PROBLEM | Supplier: credit term approval, soft-delete, CRM notes | **ไม่แนะนำ unify เป็นตารางเดียวตอนนี้** (ต้นทุนสูง ~24 FK column ใน ~20 migration ตามที่ตรวจพบจริง) — แนะนำแค่เติมฟีเจอร์ที่ supplier ขาด (soft-delete, credit-term field) แบบ additive โดยไม่รวมตาราง | P2 (เติมฟีเจอร์ที่ขาด) / P3 (unify เต็มรูป — เก็บไว้พิจารณาในเอกสาร 08) |
| Aging/Credit control | มี credit limit + aging report ทั้งลูกหนี้/เจ้าหนี้ | Customer มี credit-approval; Supplier ไม่มี credit control เลย; ยังไม่ยืนยันว่ามี AP aging report | PARTIAL | Supplier credit limit, AP aging | เพิ่ม credit limit field ที่ supplier + AP aging report (ใช้ pattern เดียวกับที่มีอยู่ฝั่ง AR) | P2 |

---

## D. Purchase / AP

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| PO Lifecycle | Quotation→PO→รับของ→ใบกำกับซื้อ→จ่ายเงิน (แยกเอกสารชัดเจนแต่ละ step) | Lifecycle เดียว `requested→approved→ordered→received` พร้อม FIFO receiving จริง (สร้าง `stock_lots` ตอนรับของ) | ALREADY EXISTS | ไม่มีเอกสารใบกำกับซื้อ (AP Invoice) แยกจาก PO — การรับของกับการตั้งหนี้อาจถูกผูกไว้ในจุดเดียวกัน | ตรวจสอบว่าการรับของสร้างรายการตั้งหนี้ (AP) แยกจาก PO หรือไม่ ถ้ายังไม่แยก ให้ออกแบบ AP Invoice เป็นเอกสารอิสระที่อ้างอิง PO/GRN (ดู 05-document-flow.md) | P1 |
| Withholding Tax (WHT) ฝั่งซื้อ | มี WHT rate table ต่อประเภทรายจ่าย + คำนวณ gross-up 2 โหมด (จ่ายสุทธิ/จ่ายรวม) + เลขที่หนังสือรับรองหัก ณ ที่จ่าย | **ไม่มี WHT บนฝั่งจ่ายเงินให้ supplier เลย** — ไม่มี rate table, ไม่มีฟิลด์เลขที่หนังสือรับรอง | MISSING | WHT rate table, gross-up calculation, certificate number field, WHT GL account role | เพิ่มตาราง `wht_rates` (ประเภทรายจ่าย↔อัตรา) + ฟิลด์ `wht_certificate_no` บนการจ่ายเงิน + posting rule ใหม่ผ่าน `GlPostingService` (ใช้ pattern `chart_of_accounts.default_role` เดิม เพิ่ม role `ROLE_WHT_PAYABLE`) | P0 (เป็นข้อกำหนดกฎหมายภาษี ธุรกิจที่มีรายจ่ายต้องหัก ณ ที่จ่ายจะผิดกฎหมายถ้าไม่มี) |
| Supplier Credit/Debit Note | มีใบลดหนี้/เพิ่มหนี้จากผู้ขาย (คืนสินค้า/ส่วนลดย้อนหลัง) | **ไม่มี** Supplier Credit/Debit Note เลย มีแต่ `CreditDebitNoteService` ฝั่งลูกค้า | MISSING | Supplier-side credit/debit note + stock-return-to-supplier flow | ออกแบบ `SupplierCreditNoteService` คู่กับที่มีอยู่ฝั่งลูกค้า ให้ pattern เดียวกัน (ผ่าน `pending_approval` เหมือน `CreditDebitNoteService` ที่มีอยู่แล้ว ไม่ใช่ post ทันทีแบบ `SaleReturnService`) | P1 |

---

## E. Document Numbering & Document Architecture

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| เลขที่เอกสาร (concurrency) | ไม่เปิดเผยรายละเอียด engine | `document_sequences` + row-level lock — **พิสูจน์แล้วว่าแก้ปัญหา race condition จริง** (มี comment ในโค้ดอ้างอิง incident เดิมที่ COUNT(*)+1 fail 84% ภายใต้โหลดพร้อมกัน) | ALREADY EXISTS (แข็งแรงกว่าที่คาดสำหรับ SME ระดับ PEAK) | — | คงไว้ ห้ามแก้กลไกนี้ | — |
| Document Status Model | มี state ชัดเจนต่อประเภทเอกสาร (ร่าง/รออนุมัติ/อนุมัติแล้ว/ยกเลิก) | `documents.status` เป็น **varchar ธรรมดาไม่มี constraint** และแต่ละ module ใช้คำศัพท์ status ไม่ตรงกัน (ไม่ใช่ state machine จริง) | PROBLEM | Unified state model, DB-level หรือ application-level constraint, transition validation | ออกแบบ state model กลาง (ดูรายละเอียดใน `07-accounting-architecture.md`) — ใช้ enum/lookup table + service-layer transition guard แทน string เปล่า โดย **ไม่ rename ค่าที่ใช้อยู่จริงในข้อมูลเดิม** เพิ่ม mapping layer แทน | P1 |
| เอกสารตกค้าง | — | พบไฟล์ `.bak` หลงเหลืออยู่ในโค้ด (ไม่ใช่ความเสี่ยงข้อมูล แต่เป็น code hygiene) | PROBLEM (minor) | — | ลบไฟล์ `.bak` ที่ไม่ได้ใช้งาน (เป็น cleanup เล็กน้อย ทำได้ทันทีโดยไม่กระทบระบบ) | P2 |

---

## F. Stock Ledger / Reservation / Costing

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Stock Ledger 3 ชั้น (movement/lot/balance) | ไม่มี concept นี้เลย (PEAK ไม่ใช่ WMS) | `stock_movements` (immutable) + `stock_lots` (remaining_qty/expiry/unit_cost) + `stock_balances` (cache) ผ่าน `FifoStockService` — **สถาปัตยกรรมนี้ดีกว่า PEAK มาก** เพราะ PEAK ไม่มี concept คลังสินค้าเชิงลึกเลย | ALREADY EXISTS (เหนือกว่า PEAK อย่างชัดเจน — ต้องคงไว้และพัฒนาต่อ ไม่ใช่ gap) | — | คงสถาปัตยกรรมนี้ไว้เป็นแกนหลัก | — |
| FEFO (First-Expired-First-Out) | ไม่มี | มี FEFO จริงแบบ opt-in ต่อสินค้า (`tracks_expiry`) — จำเป็นสำหรับสินค้าแช่แข็ง/อาหาร | ALREADY EXISTS (เหนือกว่า PEAK, critical สำหรับ frozen food) | — | คงไว้ | — |
| **Reservation Enforcement** | ไม่มี concept นี้ (PEAK ไม่ reserve stock) | มีฟิลด์ `reserved_qty` แต่ **`FifoStockService::issue()` ไม่เคยอ่านค่า `reserved_qty` เลยตอนตัดสต็อกจริง** และตอนจอง (booking) ก็ไม่มีการเช็ค cap ว่าจองเกิน available หรือไม่ — เท่ากับว่า reservation **ไม่มีผลบังคับใช้จริงทั้งระบบ** สต็อกถูกจองซ้ำได้ (double-claim) | **PROBLEM ระดับวิกฤต** | Available = OnHand − Reserved ต้องถูกบังคับใช้จริงตอน issue/checkout | แก้ `FifoStockService::issue()` ให้เช็ค available (on_hand − reserved) ก่อนตัดสต็อกเสมอ + ใส่ cap ตอน booking ไม่ให้จองเกิน available (รายละเอียดออกแบบใน `06-inventory-architecture.md`) | **P0** |
| Costing (Dual: moving-avg + FIFO) | ใช้วิธีเดียว (มักเป็น moving-average) แสดงในรายงาน | มี **dual costing โดยตั้งใจ**: `products.average_cost` (moving-weighted, informational) และ FIFO-lot cost จริงสำหรับ COGS/GL ผ่าน `CostingService` | ALREADY EXISTS (ออกแบบมาถูกต้องและครบกว่า PEAK) | — | คงไว้ ไม่ต้อง merge เป็นวิธีเดียว เพราะแต่ละวิธีมี use-case ต่างกัน (avg สำหรับดูภาพรวมเร็ว, FIFO สำหรับ GL ที่ถูกต้องตามบัญชี) | — |
| Stock Adjustment/Transfer/Count | มีปรับปรุงสต็อกอย่างง่าย ไม่มี approval 2 ชั้น | มี 2-phase approval + FIFO-lot-valued GL posting เข้าบัญชี `5030` จริง | ALREADY EXISTS (เหนือกว่า PEAK) | — | คงไว้ | — |

---

## G. Accounting Engine / Tax

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Posting Rule | Engine ปิด ผู้ใช้ตั้งค่าผ่าน UI setup ไม่เห็น logic | `GlPostingService` เป็น hybrid: โครงสร้าง Dr/Cr **hardcode ต่อ method ต่อประเภทธุรกรรม** แต่เลือกบัญชีจริงแบบ data-driven ผ่าน `chart_of_accounts.default_role` (เช่น ROLE_CASH, ROLE_SALES_REVENUE, ROLE_VAT_OUTPUT) | PARTIAL (ดีกว่า PEAK ในแง่ configurability ของบัญชีปลายทาง แต่ยังไม่ใช่ posting-rule table เต็มรูป) | ตาราง posting-rule ที่ config ได้ทั้ง Dr/Cr โครงสร้างและเงื่อนไข ไม่ใช่แค่เลือกบัญชี | วิวัฒนาการจาก `default_role` เดิมไปสู่ posting-rule ที่ยืดหยุ่นกว่า (รายละเอียดใน `07-accounting-architecture.md`) — **ไม่ rewrite ทั้งหมด** เพราะของเดิมทำงานถูกต้องอยู่ | P1 |
| Journal Structure | มี journal header + line (batch concept) | `gl_journals` เป็น **ตารางเดียวแบบ flat** ไม่มี header/batch แยกจาก line | PROBLEM | Journal header (batch) table | เพิ่ม `gl_journal_batches` เป็น header อ้างอิงจาก `gl_journals` เดิม (additive, ไม่ breaking) เพื่อรองรับการดู/reverse journal เป็นชุด | P1 |
| Period Close | ปิดงวดบัญชีป้องกันแก้ไขย้อนหลัง | มี `AccountingPeriodGuard` บังคับผ่าน Eloquent Observer (`DocumentObserver`, `GlJournalObserver`, `StockMovementObserver`) จริง | ALREADY EXISTS | — | คงไว้ | — |
| Idempotent Posting | — | Post ซ้ำได้ปลอดภัยด้วย delete-then-repost pattern | ALREADY EXISTS | — | คงไว้ | — |
| VAT / PP30 | รองรับเต็มรูป | รองรับจริง (ภาษีขาย/ซื้อ, PP30) | ALREADY EXISTS | — | คงไว้ | — |
| WHT (หัก ณ ที่จ่าย) | Rate table + gross-up 2 โหมด + เลขที่หนังสือรับรอง | ไม่มี rate table, ไม่มีเลขที่หนังสือรับรอง (ดู D ด้านบนสำหรับฝั่งซื้อ — ฝั่งขาย/รับเงินก็ยังไม่ครบ) | MISSING | ดู D | ดู D | P0 |
| **Sale Return Approval Gap** | ใบลดหนี้ต้องผ่านอนุมัติก่อนมีผลทางบัญชี | `SaleReturnService::create()` **โพสต์กลับรายได้และรับคืนสต็อกทันทีด้วย status='active'** ขัดกับกฎที่ทีมเขียนไว้เองว่า "การคืนสินค้าต้องอนุมัติก่อน" — ในขณะที่ `CreditDebitNoteService` ทำถูกต้อง (ผ่าน `pending_approval`) | **PROBLEM ระดับวิกฤต — inconsistency ข้ามโมดูล** | Approval gate ก่อน post GL/stock ใน `SaleReturnService` | แก้ `SaleReturnService::create()` ให้สร้างด้วย `pending_approval` เหมือน `CreditDebitNoteService` แล้วโพสต์ GL/stock เฉพาะตอนอนุมัติแล้วเท่านั้น (เป็น bug fix ตรงไปตรงมา ใช้ pattern ที่มีอยู่แล้วในระบบ) | **P0** |

---

## H. Reporting

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Financial Statements | Trial Balance / P&L / Balance Sheet มาตรฐาน | `FinancialStatementController` คำนวณจาก GL จริง (Trial Balance/P&L/Balance Sheet) | ALREADY EXISTS | — | คงไว้ | — |
| Report Engine | รายงานสำเร็จรูปตายตัว | `ReportController` (2740 บรรทัด) แบบ data-driven ผ่าน `report_definitions` — ยืดหยุ่นกว่า PEAK ในแง่ extensibility | ALREADY EXISTS (เหนือกว่า PEAK) | — | คงไว้ | — |
| Health/Readiness Checks | ไม่มี concept นี้ (PEAK เป็น SaaS ปิด) | มี `erp:health`/`erp:readiness` command ตรวจ invariant จริง (เช่น stock balance ตรงกับ movement) | ALREADY EXISTS (เหนือกว่า PEAK, สำคัญมากสำหรับ self-hosted ERP) | — | คงไว้ และเพิ่ม invariant check สำหรับ reservation (หลังแก้ F ด้านบน) | P1 |

---

## I. POS / Offline / Hardware

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| POS ทั้งระบบ | **ไม่มี POS เลย** (PEAK เป็น back-office accounting) | Python POS client (PySide6+SQLite local) offline-first พร้อม sync ผ่าน `sync_outbox` + idempotent checkout (`Idempotency-Key` header + `pos_api_idempotency` table เทียบ request-hash) | ALREADY EXISTS (เหนือกว่า PEAK อย่างสิ้นเชิง — นี่คือจุดแข็งหลักของ jeterp ที่ PEAK ไม่แตะเลย) | — | คงไว้เป็นแกนหลัก ห้ามลดทอน | — |
| เลขที่ใบเสร็จ (offline) | — | Server-authoritative receipt numbering ไม่มี collision risk แม้ตอน sync จาก offline | ALREADY EXISTS | — | คงไว้ | — |
| Cashier/PIN Security | — | Device-bound cashier verification (`PosDevice::markCashierVerified`) — server ไม่เชื่อ cashier/branch id ที่ client ส่งมาโดยตรง | ALREADY EXISTS (ออกแบบปลอดภัยดี) | — | คงไว้ | — |
| Barcode ชั่งน้ำหนัก (Scale) | ไม่มี | รองรับ EAN-13 scale-barcode profile ที่ config ได้ฝั่ง server จริง | ALREADY EXISTS (critical สำหรับสินค้าชั่งน้ำหนัก/แช่แข็ง, PEAK ไม่มี) | — | คงไว้ | — |
| **Printer/Cash Drawer/Customer Display** | ไม่เกี่ยวข้อง (ไม่มี POS) | **เป็นแค่ stub ทั้งหมด** — มีแค่ mock printer เขียนไฟล์ `.txt` ไม่มีโค้ด ESC/POS, USB, หรือ serial จริงเลย | **MISSING (สำคัญมากสำหรับใช้งานจริงหน้าร้าน)** | ESC/POS driver, cash-drawer kick command, customer-display integration | implement ESC/POS printing จริง (ผ่าน python-escpos หรือเทียบเท่า) ก่อนเปิดหน้าร้านจริง — ปัจจุบันพิมพ์ใบเสร็จจริงไม่ได้เลย | **P0** (บล็อกการใช้งานจริงหน้าร้าน) |
| **Void Sale (UI)** | — | Backend `void`/`return` มี logic + test ครบถ้วนแล้ว แต่ **ปุ่ม "Void" ใน PySide6 UI จริงถูกผูกกับการลบบรรทัดในตะกร้า ไม่ใช่การยกเลิกรายการที่ขายสำเร็จแล้ว** | **PROBLEM — UI ผูกผิดจุด** | ปุ่ม void ที่เรียก backend void API จริง | แก้ event handler ของปุ่ม void ใน POS UI ให้เรียก API void ที่มีอยู่แล้ว (เป็น UI wiring bug ไม่ใช่งานออกแบบใหม่) | **P0** |
| `allow_negative_stock` | — | `PosApiController::checkout()` **บังคับ `allow_negative_stock=true` แบบไม่มีเงื่อนไข** ทุก checkout | PROBLEM | ทำให้ config-based แทน hardcode true | เปลี่ยนเป็นอ่านค่าจาก setting ต่อ branch/product แทนการ hardcode (เสี่ยงขายเกินสต็อกจริงโดยไม่ตั้งใจ) | P1 |

---

## J. Infrastructure (Queue / Redis / Realtime / Frontend)

| Module | PEAK | Current ERP | Current Status | Missing | Recommendation | Priority |
|---|---|---|---|---|---|---|
| Queue/Jobs | ไม่เปิดเผย (เป็น SaaS ปิด) | **ไม่มี `ShouldQueue` job แม้แต่ตัวเดียวในระบบ** ทุกอย่างทำงาน synchronous | MISSING | Background jobs สำหรับงานหนัก (report generation, bulk sync, email) | เริ่มใช้ Laravel queue (`database` driver ที่มีอยู่แล้วรองรับอยู่แล้ว) สำหรับงานที่ควรทำ async เช่น sync ข้อมูล POS จำนวนมาก, generate รายงานหนัก — ทำแบบ additive ทีละจุดที่จำเป็นจริง ไม่ต้อง migrate ทุกอย่างพร้อมกัน | P2 |
| Redis | — | **ตั้งค่าไว้ใน env แต่ไม่ได้ใช้งานจริงเลย** ทุกจุดใช้ `database` driver (cache/session/queue) | MISSING (unused config) | ใช้ Redis จริงสำหรับ cache/session อย่างน้อย | ย้าย cache/session ไป Redis เมื่อ scale โตขึ้น (ไม่เร่งด่วนตอนนี้เพราะ database driver ยังรองรับโหลดปัจจุบันได้) | P2 |
| Realtime (WebSocket/Reverb) | — | **ไม่มีเลย** — ไม่มี package, ไม่มี config, ไม่มี channel | MISSING | Realtime stock/order update ระหว่างสาขา/POS | พิจารณา Laravel Reverb เมื่อมี requirement ชัดเจน (เช่น dashboard สต็อก real-time ข้ามสาขา) — ยังไม่ใช่ P0/P1 เพราะ POS sync แบบ polling/outbox ที่มีอยู่ทำงานได้จริงอยู่แล้ว | P3 |
| Frontend Architecture | — | **Blade + Alpine.js คือ frontend จริงของระบบ** (66 ไฟล์ใช้ `x-data`) Vue3 มีแค่ 3 component เกาะเล็ก ๆ (`PosCartPanel.vue`, `AppLauncher.vue`, `Starter.vue`) | ข้อเท็จจริง ไม่ใช่ gap | — | **ห้าม rewrite เป็น Vue3 SPA เต็มรูป** — Blade+Alpine ทำงานได้ดีอยู่แล้วและทีมคุ้นเคย การ rewrite จะเสี่ยงสูงและไม่คุ้มค่า (ดู 04-target-architecture.md) | — |
| `request()` Singleton Risk | — | `PosController::enforcedBranchId/enforcedCashierId` พึ่งพา `request()` global singleton ซึ่งเสี่ยงถ้ามี async/queue context ในอนาคต (ปัจจุบันยังไม่มีปัญหาเพราะไม่มี queue job) | PROBLEM (latent, ยังไม่ระเบิดตอนนี้) | Dependency injection แทน global `request()` | Refactor ให้รับ Request ผ่าน method parameter/constructor injection แทน — ทำก่อนที่จะเริ่มใช้ queue jobs (ข้อ J ด้านบน) เพื่อไม่ให้เกิด bug แอบแฝง | P1 |

---

## สรุปจำนวนช่องว่างตาม Priority

| Priority | จำนวนรายการ | ความหมาย |
|---|---|---|
| **P0** | 6 | Reservation enforcement ไม่ทำงานจริง, `SaleReturnService` ข้ามการอนุมัติ, ไม่มี WHT เลย, POS printer/cash-drawer เป็นแค่ stub, ปุ่ม Void ใน POS UI ผูกผิดจุด, `RoutePermissions` เสี่ยง fail-open |
| **P1** | 7 | PO ไม่มี unit conversion, ไม่มี Supplier Credit/Debit Note, Document status ไม่ใช่ state machine จริง, Journal ไม่มี header/batch, Posting rule ยัง hardcode โครงสร้าง, `allow_negative_stock` hardcode true, `request()` singleton risk |
| **P2** | 5 | Supplier credit control/soft-delete, AP aging report, Numbering setup UI, `.bak` cleanup, Queue/Redis adoption |
| **P3** | 3 | Multi-company/tenant, Self-service invite, Realtime/Reverb |

**ข้อสังเกตสำคัญ**: ช่องว่างที่ร้ายแรงที่สุด (P0 ทั้ง 6 ข้อ) **ไม่มีข้อไหนเกี่ยวกับสิ่งที่ PEAK ทำได้ดีกว่า** — ทั้งหมดเป็นบั๊ก/ช่องโหว่ในโค้ดที่มีอยู่แล้วของ jeterp เอง (reservation, approval gate, permission mapping, POS hardware, UI wiring) ซึ่งหมายความว่าสิ่งที่ต้องทำก่อนคือ **stabilize ของเดิมให้แข็งแรง** ไม่ใช่ไปเลียนแบบ feature ใหม่จาก PEAK ก่อน (รายละเอียด roadmap ใน `09-implementation-roadmap.md`)
