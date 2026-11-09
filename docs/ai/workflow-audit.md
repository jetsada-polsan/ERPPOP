# Workflow Audit — MPM / POPSTAR ERP — 9 พฤศจิกายน 2026

## หน้าแรก — สรุปผู้บริหาร

ตรวจ source บน `claude/workflow-improvement`, base `1653b49` และ migration ใน repo เท่านั้น ไม่เชื่อม production หรือ MSSQL ไม่ deploy ไม่ commit/push ตามคำสั่งเจ้าของ การ pull ล้มเหลวเพราะเขียน Git metadata/FETCH_HEAD ไม่ได้ จึงยังรับรองว่าเทียบ remote ล่าสุดไม่ได้ เอกสารรายงานเดิม `docs/ai/reports-inventory.md` เป็นข้อมูลประกอบ ไม่ถูกเขียนทับ

| เรื่อง | ผลตรวจและข้อเสนอ |
|---|---|
| ความพร้อม | มี ERP transaction services, document books, FIFO, stock ledger, GL, POS PIN/device, PR/PO และจัดส่งอยู่แล้ว ควรต่อของเดิม ไม่สร้าง ERP หรือฐานธุรกรรมคู่ขนาน |
| ปัญหาเร่งด่วน | modal ใบจอง/ใบขอซื้อเดิมปิดด้วยคลิกนอกและ Escape โดยไม่เตือน; Enter เสี่ยง submit ระหว่างคีย์; party search ไม่มี cancellation; popup ปิดกะต้องจัดพื้นที่เลื่อนและปุ่มยืนยัน |
| ความขัดแย้งธุรกิจ | ใบจองกัน `reserved_qty` จริง (`app/Services/Sales/BookingService.php:63`) และ Confirm แปลงก่อนส่งครบได้ (`app/Services/Sales/CreditSaleService.php:67`) ต่างจากความจำกลางบางส่วน ต้องยืนยันกติกาก่อน Phase 2 |
| ความเสี่ยงเงิน/ภาษี | ปัจจุบัน DSN ตั้ง no_vat ระดับเล่ม ไม่ใช่การแยกรายการตาม Tax Code; รับชำระยังต้องตรวจ lock/idempotency/หลักฐาน; ห้ามแก้ยอดหรือย้ายภาษีให้เท่ากันโดยการลบรายการ |
| งานรอบนี้ | ลงมือเฉพาะ Phase 1: รักษาฟอร์ม/ร่างในแท็บ, กัน submit ไม่ตั้งใจ, search race, polling lifecycle, popup เว็บ/Vue; ไม่แก้ Python POS และไม่เปลี่ยนวิธี posting |
| แผนถัดไป | P0 กติกา/สิทธิ์/จำนวนเงิน → P1 ขาย-จัดส่ง-จัดซื้อ-คลัง-รับชำระ → P2 ใบเสร็จ/รายงาน/ประสิทธิภาพ โดยผ่าน acceptance gate ราย phase |
| ข้อจำกัด | source audit ไม่ยืนยันข้อมูลจริง, PostgreSQL locks, อุปกรณ์ Windows/เครื่องชั่ง หรือความถูกต้องทางภาษี; ผลทดสอบที่รันจริงและข้อจำกัด browser อยู่ `docs/ai/task.md` |

## 1. System Audit

### โครงสร้างและขอบเขตที่ตรวจ

- Laravel `^13.8`, PHP `^8.3` จาก `composer.json:9`; routes web เป็น Blade/Alpine ไม่ใช่ SPA ทั้งระบบ (`resources/views/layout.blade.php:2004`, `resources/views/documents/partials/entry-assets.blade.php:141`); Vue/Tauri เป็น POS เก่า `apps/pos-desktop/package.json:6` ส่วน POS หลัก Python แยก branch ตาม brief ไม่แก้ในรอบนี้
- web auth/บัญชี disabled/บังคับเปลี่ยนรหัส/route permission อยู่ `app/Http/Middleware/ErpAuthorize.php:29`; scope รายงานอิง User/branch roles (`app/Models/User.php:47`) จึงต้องตรวจทั้ง route และ query ไม่พอแค่ซ่อนเมนู
- POS API ใช้ middleware `pos.device` (`routes/api.php:16`); ตัวตนแคชเชียร์บังคับจาก session/PIN (`app/Http/Controllers/PosController.php:181`) ไม่เอา user token มาแทนคนขาย
- เอกสารกลางผ่าน `documents` และ `document_books`; FIFO/stock movement/GL/AR/AP มี services เดิม การเปลี่ยนสถานะต้องอาศัย transaction เดิม ไม่เขียน ledger จากหน้า UI

| โมดูล | สิ่งที่มีจริง / หลักฐาน | ความไม่ครบที่ต้องตรวจต่อ |
|---|---|---|
| Sales / Reservation | create กันสต๊อกและรวมสินค้าซ้ำก่อนตรวจ availability `app/Services/Sales/BookingService.php:63`; conversion ล็อก booking `app/Services/Sales/CreditSaleService.php:52`; edit มี guard ก่อนโหลด `app/Http/Controllers/BookingController.php:208` | split tax หลายเอกสาร/Revision หลัง Confirm ยังไม่เป็น flow เดียวตามประชุม |
| Purchasing | PR/PO ใช้หน้าเดียว สร้าง requested + items ใน transaction `app/Http/Controllers/PurchaseOrderController.php:68`; รับบางส่วน lock PO `app/Services/Purchasing/PurchaseOrderReceivingService.php:23` | ไม่ควรสร้างตาราง PR ซ้ำ; หลักฐานรับตรง/ผู้ตรวจรับและ approval matrix ต้องกำหนด |
| Inventory / Warehouse | stock balances unique สินค้า+ตำแหน่ง `database/migrations/2024_01_01_000018_create_stock_balances_table.php:19`; FIFO และ in-transit receipt `app/Services/Inventory/StockTransferReceiptService.php:98` | partial/damage ต้องทดสอบ PostgreSQL concurrent; UI การนับ/หน่วยยังไม่ครบโจทย์ Excel |
| Production | แปรรูป raw→outputs และ batch จริง `app/Services/Inventory/StockTransformService.php:39`; มี production orders/receipts แยก | ต้องเทียบ WIP, เบิกจริง/ได้จริงคนละวัน, ค่าจ้างภายนอกและผลต่างต้นทุนกับบัญชี ไม่ถือว่า batch transform เท่ากับครบทุก production flow |
| Delivery | โหลดจริง lock รายการ `app/Http/Controllers/FleetController.php:85`; state transitions `:143`; บันทึก delivery มี audit `app/Services/Sales/BookingDeliveryService.php:63` | route/run/stop และใบปะหน้ารวมลูกค้า/เตือนพิมพ์ซ้ำยังต้องออกแบบ; บอร์ดยังมีสถานะ paid ผสมกับส่ง |
| Accounting | GL fingerprint/idempotent + reversal `app/Services/Accounting/GlPostingService.php:234`; payment allocation `app/Services/Sales/CustomerPaymentService.php:35`; review ใบจอง `app/Http/Controllers/BookingController.php:392` | รับชำระอ่าน open items ก่อน transaction (`CustomerPaymentService.php:42`, `:60`) เสี่ยง concurrent; ต้องพิสูจน์ก่อนแก้ Phase 6 |
| POS | close ตรวจ branch/cashier/terminal, transaction+locks+DecimalMath `app/Http/Controllers/PosController.php:776`; preview ไม่ปิดกะ `:827` | web/Vue popup ปรับในรอบนี้; Python และพิมพ์เครื่องจริงยังไม่ตรวจ |
| Reports | ทะเบียน/dispatch/gaps และ permission matrix อยู่ `docs/ai/reports-inventory.md:1` | ใช้ sales_postings กันซ้ำ; สิทธิ์ต้นทุน/กำไรและ historical tax เป็น gate ไม่ใช่งาน UI อย่างเดียว |
| Settings | permission/branch scope `app/Models/User.php:47`, books no_vat `database/migrations/2026_11_01_000001_add_no_vat_to_document_books.php:13` | แยก payment term จาก tax; สิทธิ์ IT ต้องตกลงก่อนเปลี่ยน bypass |

### Transaction, FK, ledger และ status

stock item อ้างเอกสารและสินค้า (`database/migrations/2024_01_01_000027_create_stock_document_items_table.php:12`), stock movement เป็นหลักฐานเคลื่อนไหว (`database/migrations/2024_01_01_000028_create_stock_movements_table.php:12`); ห้ามอ้างยอดเอกสารเพียงอย่างเดียวว่าตัดสต๊อกแล้ว GL มี component batches จึงต้องอ้าง reversal linkage ไม่ลบ journal ตอนแก้ (`app/Services/Accounting/GlPostingService.php:249`) Generic document lifecycle draft/rejected→pending_approval→approve ไม่ได้แทนทุก state machine (`app/Services/Documents/DocumentLifecycleService.php:12`) เช่น booking pending/converted, PO requested, shift open/closed และ delivery status ต้องแยกตรวจ

`pos_shifts.cashier_id→salesmen` (`database/migrations/2026_07_04_000001_create_pos_shifts_table.php:15`) แต่ `pos_receipts.cashier_id→users` (`database/migrations/2024_01_01_000048_create_pos_receipts_table.php:17`); ผู้ขายจริง receipt ใช้ cashier_salesman_id (`database/migrations/2026_07_04_000001_create_pos_shifts_table.php:38`) ห้าม join cashier_id ทั้งสองตารางเข้ากัน

## 2. Gap Analysis ต่อ Phase

คำว่า Existing หมายถึงพบ implementation ไม่ได้หมายถึงผ่าน UAT; Incomplete มีฐานแต่ไม่ครบ acceptance; Missing หมายถึงไม่พบ flow เฉพาะใน source ที่ตรวจ; Bug คือพฤติกรรม/ความขัดแย้งที่มีหลักฐาน ต้องพิสูจน์ด้วยเทสต์ก่อนเปลี่ยนกติกาธุรกิจ

| Phase | Existing | Incomplete / Missing | Bug / ผลรอบนี้ |
|---|---|---|---|
| 1.1 ฟอร์ม | entry modal ร่วม `resources/views/documents/partials/entry-modal.blade.php:15`, PR modal `resources/views/purchase-orders/index.blade.php:64` | เดิมไม่มี local draft/unsaved warning; เพิ่มใน `public/document-workflow.js:1` (แท็บเดียว 8 ชั่วโมง ไม่ใช่ server draft) | เดิม click outside/Escape ปิดทันที; ถอด outside, เตือนปิด/ออก, กัน Enter และ submit ซ้ำ; ไม่เพิ่ม auto-print |
| 1.2 refresh | Executive ใช้ refresh JSON `resources/views/executive/index.blade.php:244`; POS มี catalog/host timers `resources/views/pos/index.blade.php:4604` และ lifecycle `:3076` | ไม่พบ polling/reload loop ในหน้าใบจอง/PR ที่ตรวจ จึงไม่อ้างว่าแก้สาเหตุ browser โหลดไม่หยุดทั้งระบบ | Executive เดิมไม่มี cleanup/overlap guard; เพิ่มอย่างน้อย 30 วินาที, หยุด hidden, abort เมื่อ destroy; เว็บ POS เดิมมีทั้ง Alpine auto-init และ x-init เรียกซ้ำ ถอด x-init เพิ่ม init guard/cleanup timers+listeners และกัน ping ซ้อน; เครื่องจริงยังต้อง UAT |
| 1.3 search | server products รหัส/ชื่อ/barcode + limit20 `app/Http/Controllers/SearchController.php:83`; shared picker debounce/abort/keyboard `resources/views/partials/product-picker.blade.php:174`; document filters `app/Http/Controllers/PurchaseOrderController.php:28` | ลูกค้า keyboard เพิ่มใน shared form; ไม่เปลี่ยน list search เป็น endpoint ใหม่ซ้ำ; เว็บ POS กัน product query/branch/category เก่าทับผลใหม่ (`resources/views/pos/index.blade.php:3563`) | party requests เดิมสลับผลได้; เพิ่ม abort+sequence และล้าง id เมื่อแก้คำค้น; เปลี่ยน customer/supplier/branch เป็น LOWER LIKE ที่ SQLite/PostgreSQL รองรับ |
| 1.4 ปิดกะ | backend validation/ownership/transaction/preview อยู่แล้ว `app/Http/Controllers/PosController.php:776` | desktop popup นับธนบัตรยาว; ไม่มีหลักฐานขนาดจอเครื่องจริง | ปรับ web/Vue head/body/footer, จำกัด viewport/เลื่อนเนื้อหา; web เริ่มยอดนับว่างและไม่ส่งซ้ำระหว่าง processing; ไม่เปลี่ยน ledger |
| 2 ขาย | edit ก่อนเริ่มโหลด, convert lock และไม่แปลง booking เดิมซ้ำ `app/Services/Sales/CreditSaleService.php:53` | Missing split-by-tax preview/link หลายใบ, revision แบบครบ cycle | Confirm ก่อนส่งครบได้; no_vat ระดับเล่มไม่ตรง requirement tax รายสินค้า ต้องเจ้าของชี้ขาด |
| 3 ส่ง | load sheet และ board `app/Http/Controllers/FleetController.php:39` | Missing route/run/stop grouping, cover QR, print history ตามโจทย์ | delivery board มี paid (`:134`) ต้องแยกสถานะรับชำระ ไม่ทำรอบนี้ |
| 4 ซื้อ | PR/PO partial receive, direct PurchaseService, inclusive/exclusive/input VAT `app/Services/Purchasing/PurchaseService.php:55` | หลักฐาน direct receipt/approval และ UOM snapshot ต้องครบทุก writer | อย่าสร้าง PR/GR ซ้ำเพราะชื่อเมนูต่าง; ไม่แก้ migration เดิม |
| 5 คลัง | transfer transit, transform, issue, count CSV `app/Http/Controllers/StockCountController.php:129` | Missing original-file hash/idempotency/unit validation สำหรับ Excel import; external WIP ยังต้องตรวจ | count มี full_zero_missing (`app/Services/Inventory/StockCountService.php:96`) ซึ่งอาจปรับรายการไม่นับ ต้องยืนยัน/จำกัดก่อนเปิด flow ใหม่ |
| 6 บัญชี | review, open items, allocations `app/Http/Controllers/PaymentController.php:22` | Missing mandatory transfer slip และ durable retry key ใน flow รับชำระที่ตรวจ; ต้อง unify document return/review | read/check ก่อน transaction + float ใน CustomerPaymentService ต้อง concurrency/decimal regression ก่อนแก้ |
| 7 ใบเสร็จ | web/Vue receipt/Z และ print paths อยู่แล้ว | ยังไม่รับรองโลโก้ MPM ต้นฉบับ/สำเนาด้านล่าง/ซ่อนบัญชีทุก template/เครื่องจริง | Python ไม่แก้; งาน typography/printing แยก Phase 7 |
| 8 สิทธิ์/รายงาน | report registry/exports/branch scope | KPI/filter/drilldown enterprise ยังไม่ครบตาม inventory; role cost/profit policy รอเจ้าของ | IT bypass/รายงานบาง query ขาด scope ตาม `docs/ai/reports-inventory.md` ไม่ปรับสิทธิ์วงกว้างใน Phase 1 |
| 9 E2E | มี feature suites booking/delivery/purchase/inventory/POS | Missing UAT 20 scenarios เชื่อมทั้งสายงานบน PostgreSQL+เครื่องจริง | SQLite ผ่านไม่พิสูจน์ concurrent locks หรือความครบธุรกิจ |

## 3. Workflow แต่ละแผนก — ประชุมเทียบระบบจริง

1. **คลัง:** ประชุมต้องรับถุง/ลัง→ฐาน กก.→เบิกผลิต→รับผลผลิต→โอน/เสียหาย/นับ มี PurchaseService, StockTransformService และ receipt transit แล้ว หน่วยและต้นทุนต้อง snapshot ต่อบรรทัด ไม่ใช้ conversion master ย้อนคำนวณอดีต เพิ่มการเก็บไฟล์นับ/ตรวจหน่วย/ผู้อนุมัติบน StockCount เดิม ห้ามเปลี่ยนยอดไม่นับโดยเงียบ
2. **จัดซื้อ:** ต้อง PR→อนุมัติ→PO→รับ และรับตรงไม่มี PO หน้า `purchase-orders` ปัจจุบันใช้ requested/approve/receive บนโมเดลเดียว (`app/Http/Controllers/PurchaseOrderController.php:68`) ให้เพิ่มสถานะ/หลักฐานเฉพาะ gap ไม่ทำตาราง PR ใหม่เพียงเพราะชื่อเอกสารต่าง ภาษีซื้อมี flags ใน PurchaseService แต่ supplier default/ข้อยกเว้นต้องบัญชียืนยัน
3. **ฝ่ายขาย/Sales Admin:** คีย์ใบจอง→ขึ้นของ→แก้น้ำหนักจริงพร้อม before/after→ตรวจ→ส่ง→Confirm→เทียบยอด ของจริง booking กัน stock reservation, edit ถูกจำกัดก่อน loading และ conversion ตั้ง active sale/AR/FIFO (`CreditSaleService.php:75`) จึงต้องแยก edit request กับ posted correction ก่อนเพิ่ม revision ห้ามลบ source lines ตอน split tax
4. **จัดส่ง:** มี load quantities/state machine แล้ว (`FleetController.php:85`, `:149`), แต่ route/run/stop หลายลูกค้าและ cover sheet ต้องต่อยอด ขนส่งส่งเอกสารกลับเป็น event แยกจากเงินเข้า ห้ามกด delivered แล้วตั้ง paid โดยถือว่าได้เงิน
5. **บัญชีตรวจ:** วันถัดไปต้องเห็น before/after ราคา/น้ำหนัก/ลบรายการ/ค่าขนส่ง/ส่วนลด/คืน และยอดจองเทียบทุกใบขาย ปัจจุบัน review booking มี approved/rejected (`BookingController.php:392`) ยังไม่ใช่ revision ledger ครบทุกเอกสาร ต้องส่งคืนแก้แบบอ้างอิงโดยไม่ถอด posted history
6. **บัญชีรับชำระ:** เปิดเอกสารตามลำดับลูกค้า→พิสูจน์ cash/transfer+bank+slip→จัดสรรเต็ม/บางส่วน/หลายครั้ง→ค้าง/เกิน/ขาด ปัจจุบันมี allocations/AR/CashBook/GL (`CustomerPaymentService.php:60`) แต่ต้องเพิ่ม lock/retry-proof/หลักฐานและ audit ทุก transition ก่อนใช้ flow ตามประชุม
7. **POS:** offline queue→API retry→receipt/stock/GL→close preview→นับเงิน→confirm ระบบบังคับ PIN/device และตรวจ shift แล้ว ไม่สร้าง auth คู่ขนาน Vue เก็บ compatibility กับ ERP รุ่นเก่า Python ต้องพอร์ต popup/preview/Decimal/retry ใน branch ของตนหลังตกลง API

## 4. Database Impact

**Phase 1 ไม่มี migration ไม่มี backfill ไม่มีเปลี่ยน FK หรือข้อมูลธุรกรรม** ร่างอยู่ sessionStorage แยก user/path/form action อายุ 8 ชั่วโมง ล้างหลัง server ยืนยันบันทึกสำเร็จของ action นั้น ไม่เก็บ CSRF/password/PIN/token ไม่ใช่หลักฐานบัญชีและไม่รับรองข้ามเครื่อง/ปิดแท็บ ต้องใช้สิทธิ์และ validation เดิมเมื่อกู้คืน

| Phase ถัดไป | ขยายของเดิมก่อน / proposed schema (ยังไม่สร้าง) | Constraints / migration gate |
|---|---|---|
| 2 | documents/source booking links, tax snapshot, revision header+line diff เชื่อม audit_logs | unique conversion key ต่อ source+tax group; total source=children; เก็บเลขเดิม/lines เดิม; posted แก้ด้วย reversal/adjustment |
| 3 | transport_jobs/loadItems เดิม + route run/stop membership + print events | branch/date/sequence, ห้ามใบจองอยู่สอง active runs, separate payment state |
| 4 | purchase_orders/items/receipts เดิม + UOM snapshot/evidence refs | decimal qty/factor/cost; factor>0; retain tax setting snapshot; ไม่เดาหน่วยเก่า |
| 5 | stock transfer receipts/counts/production เดิม + count import batches/file hash/evidence และ WIP references เฉพาะที่ขาด | unique import batch; stock_count_id+product; only counted lines; stage/review/approve; source lot linkage |
| 6 | payment_documents/lines/allocations เดิม + idempotency/evidence/review fields | lock open items ใน transaction; unique retry key; cumulative allocations ≤ available; decimal money; reversal references |
| 8 | report query/read model และ explicit grants ก่อนเพิ่ม persistent aggregates | หลีกเลี่ยง ledger ซ้ำ; branch scope ทุก query/export; audit export ตาม policy |

ทุก schema ใหม่ต้อง additive/nullable หรือ default ที่ปลอดภัย, SQLite+PostgreSQL up/down จริงบนฐานทดสอบ, FK/index plan, ไม่มีการแก้ข้อมูลธุรกิจเก่าใน migration; historical repair เป็นคำสั่ง preview/apply แยกที่เจ้าของอนุมัติพร้อมหลักฐาน ไม่ rewrite journal/เลขเอกสาร

## 5. API Impact

Phase 1 คง routes/payload/permissions เดิม (`routes/web.php:255`, `routes/api.php:16`); customer/supplier/branch search ใช้ LOWER LIKE, ผลลัพธ์ยัง limit20/schema เดิม ไม่เพิ่ม endpoint ร่างหรือส่งร่างเข้าฐาน Session flash `document_draft_saved` ใช้เฉพาะ cleanup UI หลังสำเร็จ ไม่คืน credentials และไม่เปลี่ยน API POS

ข้อเสนอต่อไป: conversion preview คืนกลุ่ม tax+lines+ยอดและ version เพื่อ confirm ใต้ lock; revision command รับเหตุผล+version+diff; delivery run membership รับ expected status; count import preview/hash→approve; payment command รับ evidence+retry key แล้วตัด allocations ใต้ lock ทุก command authorize branch/entity ฝั่ง server และ audit before/after งานเงินใช้ DecimalMath/decimal strings ไม่เชื่อยอดจาก client ต้องกำหนด OpenAPI/error contract หลังตอบ Open Questions ไม่สร้าง endpoint สมมติใน Phase 1

## 6. UI/UX Plan

ใช้ doc-entry modal/product picker/layout เดิม ไม่เปลี่ยน design system ฟอร์มใบจอง/PR รักษา state เดิมเมื่อเปิด picker/ค้นหา/ยกเลิก เตือนก่อนปิด/ออก เก็บร่างในแท็บแบบ best effort และถามก่อนกู้คืน Native validation ก่อน submit; validation/server failure ต้องไม่ทำเหมือนบันทึกสำเร็จ ไม่มี auto-print ระหว่างคีย์ ลูกค้าใช้ debounce+ยกเลิก request เก่า+keyboard เลือกผลโดย Tab/ลูกศร/Enter

Executive refresh เฉพาะ JSON ไม่ full reload, ไม่ request ซ้อน, เว้นอย่างน้อย 30 วินาที, hidden ไม่เริ่มใหม่, timeout 15 วินาทีและ abort เมื่อ Alpine destroy; เว็บ POS init ครั้งเดียว เก็บ/ล้าง clock/catalog/host timers กับ listeners และ abort background reads เมื่อ destroy; หน้าฟอร์มไม่มี polling ที่พบ ไม่เติม refresh ใหม่ Desktop/web close shift แยกหัว/เนื้อหาเลื่อน/ปุ่มท้าย sticky หรือ flex footer, ใช้ dvh และจำกัด width; ไม่ปิดจากคลิกนอกกะที่กำลังกรอก

Phase ต่อไปแสดง status ส่ง/รับเงินคนละ badge, preview tax split ก่อนยืนยัน, diff ก่อน/หลังใน revision, route cover/print history, count import error ทีละบรรทัด และ evidence receipt แยกจากเลือก method รายงานใช้ KPI/filter/sort/pagination/drill-down/export/preview/empty/loading ตาม inventory เดิม ไม่เปิดทุน/กำไรเพราะเป็น IT อัตโนมัติ

## 7. Implementation Plan P0 → P1 → P2

| ลำดับ / Phase | ชิ้นงานและ dependency | Gate ก่อนเฟสถัดไป |
|---|---|---|
| P0 / 1 (รอบนี้) | modal draft/guard, search race/portable query, Executive lifecycle, web+Vue popup; tests+mutation | ดูผลรันจริง task.md; browser/เครื่องจริงที่รันไม่ได้ยังเป็น release gate |
| P0 / 2 เตรียม | เจ้าของ+บัญชียืนยัน Confirm/tax/A-B; review ledger/permission concurrency ก่อนแก้ | ตัวอย่างเอกสารจริงที่ปกปิดข้อมูล + policy ลงนาม; no production writes |
| P1 / 2 | tax preview/convert หลายใบ/source links/revision โดย reuse services | ยอด children=source, ครบทุกรายการ, retry ไม่ตัดซ้ำ, posted immutable |
| P1 / 3 | delivery run/stop/cover/print events + separate payment state | loadครบก่อนออก; delivered ไม่เปลี่ยน AR; branch/driver scopes |
| P1 / 4 | direct receipt evidence/approval + unit snapshots | 5ลัง×10=50กก.; ขาย2.5เหลือ47.5; VAT/AP/GL reconcile |
| P1 / 5 | transfer partial/damage, requisition approval, WIP, count staged import | transitห้ามขาย; yield+loss=inputs; counted-only; retry unchanged |
| P1 / 6 | review queue/evidence/locked Decimal allocations/idempotency | simultaneous payments ไม่เกินหนี้; transferไม่มีslipไม่post; reversal reconcile |
| P2 / 7 | logo/typography/copy labels/privacy/print choice + Python branch | fixture image QA + เครื่องพิมพ์จริง; failure ไม่ทำบิล/กะหาย |
| P0 policy → P2 UI / 8 | server role/scope/cost policy ก่อน dashboard/report expansion | role×branch×UI/API/export/print matrix;ยอด report เท่าต้นทาง |
| ทุกลำดับ / 9 | local E2E แล้ว PostgreSQL staging และอุปกรณ์ UAT | 20 scenarios ด้านล่าง + mutation + concurrency; deploy ต้องคำสั่งใหม่ |

## 8. Test Scenarios + Acceptance Criteria

| # | สถานการณ์ | Acceptance / สิ่งที่ต้องตรวจ |
|---|---|---|
| 1 | ใบจอง/PR 50 รายการ เปิด-ปิด picker, Enter, Escape/outside, reload | ไม่ submit ระหว่างคีย์; parent ไม่ปิดเมื่อปิด picker; กู้ qty/price/party/header ครบ; server failure เก็บร่าง |
| 2 | เปลี่ยนจำนวน/น้ำหนักขึ้นรถ | before/after+ผู้แก้+เหตุผลครบ; ledger ไม่ตัดก่อนจุดที่ policy กำหนด |
| 3 | tax split VAT/Non-VAT | previewครบ; sourceไม่ลบ; childrenรวมเท่าต้นทาง; retryชุดเดียว |
| 4 | Confirm แก้เวลาในงวดเปิด/ปิด | วันจริงถูกต้อง; closed period ปฏิเสธ; auditครบ |
| 5 | Confirm ผิดฝั่ง/เล่ม | ไม่ย้าย VAT เงียบ; correction links/reversal ตรวจย้อนหลังได้ |
| 6 | revision posted/issued tax invoice | original immutable; stock/AR/GLกลับด้วยเอกสารอ้างอิง |
| 7 | delivery ตามสาย+หลายลูกค้า | branch/run/stopถูก; รวมสินค้าตามรายการจริง ไม่มีตกหล่น |
| 8 | ใบปะหน้า/พิมพ์ซ้ำ | โลโก้ต้นฉบับ/วันที่/สาย/ลำดับ/ลูกค้า/เอกสาร/QR; audit printและเตือนซ้ำ |
| 9 | รับไม่มี PO สด/เชื่อ incl/excl VAT | approval/evidenceครบ; lot/stock/AP/GLถูก; ไม่มี POปลอม |
| 10 | รับ10ลัง×10กก. ขาย2.5กก. | ฐาน100→97.5; cost per kgถูก; เปลี่ยนmasterไม่เปลี่ยนsnapshot |
| 11 | HQ→สาขารับบางส่วน/เสียหาย | transitแยก; receipt retryไม่เพิ่มซ้ำ; lossรออนุมัติ; total value reconcile |
| 12 | แปรรูป A→B ภายใน/ภายนอก | เบิก/รับ/WIP/ค่าจ้าง/yield/lossครบ; input cost=output+lossตามpolicy |
| 13 | ตัดชำรุด | ผู้ขออนุมัติตัวเองไม่ได้ตามpolicy; รูป/เหตุผล/น้ำหนักครบ; approveครั้งเดียว |
| 14 | export→นับ→import→review→post | unit/SKU errorsชัด; duplicate hashไม่postซ้ำ; ไม่นับไม่ปรับ; เก็บoriginal |
| 15 | รับเงินสดเต็ม/หลายใบ | received=allocated+unallocated; cashbook/GLครั้งเดียว; branchscope |
| 16 | รับโอนไม่มี/มีสลิป | ไม่มีหลักฐานไม่post; bank/evidence/reviewer/auditครบ |
| 17 | รับบางส่วน/พร้อมกัน/ซ้ำ | สองrequestไม่จ่ายเกินหนี้; retryได้ผลเดิม; outstandingตรงallocations |
| 18 | ใบเสร็จ/ไม่พิมพ์/พิมพ์ล้ม | MPM/สำเนาท้าย/ไม่เปิดบัญชีลูกค้า; ไม่ auto-printเมื่อปิด; บิลอยู่localก่อนพิมพ์ |
| 19 | ปิดกะ cash/transfer/mixed/short/over/API fail/retry | previewไม่เขียน;เงินนับไม่เติมexpected;footerถึงที่375×420และ1024×600; failureคงกะ/ยอด;closeซ้ำไม่postซ้ำ |
| 20 | Sales Admin/Manager/Accounting/Owner/IT | scopeทั้งUI/API/export/print;ไม่มีทุนกำไรเมื่อไม่ได้grant;ยอดreportเท่าledger |

เพิ่ม regression rapid customer query ตอบย้อนลำดับ/clear query/select ระหว่าง request, polling repeated start/destroy/hidden/in-flight, quota storage/expired draft/user isolation, invalid qty และ server validation roundtrip ชุดเดิม POS float/real checkout/transaction safety ใช้ยืนยัน backend ไม่เขียน testที่เพียงตรวจข้อความ CSSแล้วอ้างว่าbrowserผ่าน ต้องแยก DOM/feature/browser/เครื่องจริงและ PostgreSQL ผลรันจริงอยู่ handoff; ไม่มีการยิงธุรกรรมทดสอบ production

## 9. Open Questions — ห้ามเดา

| เรื่อง | หลักฐานที่ตรวจได้ | สิ่งที่ยังต้องให้เจ้าของ/บัญชียืนยัน |
|---|---|---|
| DS/DSN | migration books กำหนดทั้งคู่เป็น CREDIT_SALE (`database/migrations/2024_01_01_000099_create_document_books_table.php:14`, `:51`); migration no_vat ตั้ง DSN/CSN/IS-N (`database/migrations/2026_11_01_000001_add_no_vat_to_document_books.php:16`) | DSN ในการใช้งานจริงหมายถึงขายยกเว้น/ไม่ออกภาษี/สมุดอื่นอย่างไร? ขอเอกสารตัวอย่าง+tax code รายสินค้า ไม่สรุปจากตัว N ว่าทุกสินค้าไม่เสียภาษี |
| IB/IBN | source mapping BPlus มี `IB`, `IB-N` ไม่ใช่ `IBN` (`app/Support/LegacyDocumentModules.php:39`, `:98`); ERP PURCHASE default book เป็น PO (`database/migrations/2024_01_01_000099_create_document_books_table.php:17`) | ขอ DOCTYPE mapping/เอกสารซื้อจริง; IB/IB-N แยกเครดิตหรือVATหรืออย่างอื่น? ห้าม alias IBN→IB-N หรือแปลภาษีเอง ยังไม่ได้อ่าน DB จริง |
| หลัง Confirm | edit booking จำกัดก่อนโหลด (`BookingController.php:208`), convertสร้างactive sale (`CreditSaleService.php:75`), GL reversalมีแล้ว (`GlPostingService.php:234`) | Confirm หมายถึง approval/ออกใบขาย/ออกใบกำกับจุดไหน? กรณีใดถอนConfirmได้ ใครอนุมัติ และปิดงวด/ออกtaxแล้วใช้เอกสารใด? |
| ภาษี | product is_vat + book no_vat, purchases include/claim flags (`PurchaseService.php:55`), GL salesBreakdown (`GlPostingService.php:155`) | tax code/rate/effective date/exempt reasons, supplier defaults และ priority snapshot ที่บัญชีอนุมัติ; ไม่ผูกภาษีกับpayment termหรือลูกค้า |
| POS | device middleware+PIN enforced (`routes/api.php:16`, `PosController.php:181`); หลัก Python แยกbranch | รุ่นPythonที่ใช้งานจริงรองรับpreview/floatและqueueครบหรือยัง? printer/scale/สาขาเครื่องUATใด? APIcompatibilityก่อนพอร์ต ห้ามเขียนcredentialsให้ |
| ยอดใบจอง A/B | codeใช้ document books/no_vat ไม่พบ definition A/B ที่ยืนยันตรงประชุม; report inventory ตั้งคำถามไว้แล้ว | A/B คือกลุ่มภาษี/สมุด/ช่องทางหรือยอดก่อน-หลังConfirm? นับตามวันจอง/ส่ง/ขาย? กรณีคืน/ยกเลิก/แยกหลายใบเทียบอย่างไร? |
| สิทธิ์ IT | User permission/bypass และ system.admin (`app/Models/User.php:209`), report scope (`:47`) | ITดูทุนกำไรได้เฉพาะgrantใด? break-glassหมดอายุ/auditอย่างไร? ต้องยืนยันrole matrixก่อนเปลี่ยนทั่วระบบ |
| จุดกัน/ตัดสต๊อกและส่ง | BookingService กันreserved (`:63`); CreditSaleService ให้Confirmระหว่างส่ง (`:67`), ต่างจากความจำกลาง | ต้องรักษาการกันสต๊อกหรือยกเลิก? อนุญาตConfirmก่อนส่งครบหรือไม่? ตัดตามวันใดเมื่อส่งบางส่วน? เลือกpolicyเดียวแล้วเขียนtestsก่อนPhase2 |
| ตรวจนับรายการไม่นับ | StockCountService full_zero_missing (`app/Services/Inventory/StockCountService.php:96`) | ต้องปิดmodeนี้ในworkflowใหม่หรือให้explicit manager approvalแบบใด? กติกาโจทย์คือไม่นับห้ามปรับ |

ไม่เปิด/แก้ข้อมูล legacy เพื่อคลายคำถามในรอบนี้ หากต้องอ่านภายหลังต้องเป็น SELECT ที่ได้รับอนุญาตบนแหล่งที่กำหนดเท่านั้น และไม่แก้ historical migration ให้ย้อนหลังตามคำตอบใหม่
