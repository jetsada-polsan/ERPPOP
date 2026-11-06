# Codex: P0 ความถูกต้องของเงิน/สต๊อก/บัญชี (จากผลวิเคราะห์ docs/ai/bplus-erp-analysis.md บน branch claude/bplus-erp-analysis)

Branch `claude/p0-money-integrity` (จาก origin/main) commit ทีละข้อ push ได้ **ห้าม deploy ห้ามแตะ production**
migration ต้องรันได้ทั้ง SQLite และ PostgreSQL และ **ห้ามแก้ข้อมูลเก่าใน migration** (ถ้าต้องซ่อมข้อมูลเก่า ให้ทำ artisan command แบบ dry-run เป็นค่าเริ่ม `--apply` ถึงแก้ เหมือน `erp:pos-receipt-vat-backfill`)
**เรื่องเงินห้ามพลาดแม้สตางค์เดียว** ใช้ DecimalMath ทุกจุด ทุกข้อต้องมีเทสต์ + ถอดตัวแก้แล้วเทสต์ต้องแดง
`php -d memory_limit=2G vendor/bin/phpunit` ต้องผ่านทั้งหมด
ระบบใช้ขายจริงอยู่ (POS ทั้ง Vue 1.8.1 และ Python) ห้ามเปลี่ยน API contract ที่ POS ใช้ ถ้าจำเป็นต้องรองรับ client เก่า

## 1. โอนสินค้าระหว่างสาขา: สถานะระหว่างทาง + กันอนุมัติซ้ำ
`app/Services/Inventory/StockTransferService.php:47` approve() ตัดต้นทางและเพิ่มปลายทางทันที ไม่ lock เอกสาร
- lock เอกสาร (lockForUpdate) + ตรวจสถานะ pending ภายใน transaction; อนุมัติซ้ำ/พร้อมกัน = ปฏิเสธ ไม่ขยับ stock ซ้ำ
- เพิ่มสถานะ "ระหว่างทาง": อนุมัติ = ตัดต้นทางเข้า location/สถานะ in-transit (ไม่พร้อมขายที่ใด) ; ปลายทาง "ตรวจรับ" (`StockTransferReceiptService`) = ย้ายจาก in-transit เข้าคลังปลายทาง รองรับรับไม่ครบ/เสียหาย (ส่วนต่างเป็นเอกสารปรับ/ความเสียหายที่มีหลักฐาน ไม่หายเงียบ)
- ต้นทุน FIFO/lot ต้องตามของไปไม่เปลี่ยน; GL ถ้ามีบัญชีสินค้าระหว่างทางให้ใช้ ถ้าไม่มีอธิบายใน task.md
- เอกสารโอนที่อนุมัติไปแล้วก่อนแก้ (ขยับ stock ไปแล้ว) ต้องไม่ถูกขยับซ้ำ
- หน้าจอ: ใบโอนแสดงสถานะ "ระหว่างทาง" และปุ่มตรวจรับที่ปลายทาง

## 2. GL: ห้ามลบรายการเดิมเพื่อลงใหม่
`app/Services/Accounting/GlPostingService.php:230` `GlJournal::where('document_id')->delete()` ก่อนสร้างใหม่
- เปลี่ยนเป็น append-only: เอกสารที่ลง GL แล้ว ถ้าต้องลงใหม่ ให้ลงรายการกลับ (reversal) ของชุดเดิมแล้วลงชุดใหม่ เก็บประวัติครบ; กันลงซ้ำด้วย idempotency (เช่น posting batch/version) ไม่ใช่ delete
- ไล่ทุกที่ที่เรียก postDocument/ลบ GlJournal ให้ครบ; ยกเลิกเอกสาร = reversal ไม่ใช่ลบ
- รายงาน TB/GL/งบ ยอดต้องเท่าเดิม (reversal หักล้างกัน) — เทสต์ยืนยัน
- ดูให้แน่ว่า period lock (AccountingPeriodGuard) ทำงานกับ reversal ถูก (งวดปิดแล้ว reversal ลงวันปัจจุบัน/งวดเปิด ตามนโยบายที่มี)

## 3. บิล POS ลง GL ตามวิธีชำระจริง
`GlPostingService.php:299` ขายสดลง Dr เงินสด ทุกบิล ทั้งที่ POS แยก pos_payments (cash/transfer/credit_card/cheque/mixed)
- บิล POS: Dr ตามวิธีชำระ — เงินสด→บัญชีเงินสด (หรือเงินสดรอนำส่งตามที่ระบบใช้กับกะ), โอน/QR→ธนาคาร/บัญชีพักรอกระทบยอด, บัตร→ลูกหนี้บัตร/clearing, เช็ค→เช็ครับ/clearing; mixed แยกสองบรรทัดตามยอดจริง
- ใช้ ChartOfAccount roles ที่มีอยู่ ถ้าไม่มี role ที่จำเป็นให้เพิ่ม role (ไม่ hardcode เลขบัญชี) และอธิบายใน task.md ว่าต้องผูกบัญชีอะไร ถ้ายังไม่ผูก ต้องมีพฤติกรรมที่ปลอดภัยและชัดเจน (เช่นใช้ clearing รวม) ไม่ throw จนบิลขายไม่ได้
- ตรวจผลกับ CashBookPostingService (ปิดกะลงสมุดเงินสดเฉพาะเงินสด) และ bank reconciliation ของ pos_payments — ห้ามนับซ้ำ
- command ซ่อมบิลเก่า (dry-run/`--apply`) ที่ reclass ด้วยรายการกลับ+ลงใหม่ ไม่ลบ

## 4. ปรับต้นทุนซื้อย้อนหลังต้องลง GL
`app/Services/Inventory/PurchaseCostAdjustmentService.php` เปลี่ยน lot cost/average แต่ไม่ลง GL/AP
- ส่วนที่ของยังอยู่ในคลัง → Dr/Cr สินค้าคงเหลือ; ส่วนที่ขายไปแล้ว → Dr/Cr ต้นทุนขาย (หรือบัญชีผลต่างต้นทุน); อีกฝั่ง AP/บัญชีพักตามที่มาของการปรับ
- ยอดสต๊อกใน subledger กับ GL ต้องเท่ากันหลังปรับ — เทสต์ reconciliation

## 5. โปรสินค้าเดียวกันหลายบรรทัด
`app/Services/Sales/PosPricingGuard.php:~417` ใช้ firstWhere (บรรทัดแรก) แต่ Vue `apps/pos-desktop/src/lib/pricing.ts:~55` รวม qty
- เปลี่ยน ERP ให้ **รวมจำนวนทุกบรรทัดของสินค้าเดียวกัน** ก่อนคิดโปร (ตรงกับ Vue) — ลูกค้าได้สิทธิ์ตามจริง
- ราคาฐานต้องเป็นราคาตั้งของสินค้า (ไม่ใช่ราคาที่แคชเชียร์แก้) คงหลักเดิม
- ต้องไม่ทำให้บิลที่ POS เก่า (คิดแบบบรรทัดแรก) ถูกปฏิเสธตอนส่งซ้ำจากคิวออฟไลน์: ออกแบบ tolerance/flag ตามเวอร์ชัน client หรือยอมรับยอดที่ส่วนลดน้อยกว่าสิทธิ์ (ลูกค้าจ่ายมากกว่า ไม่ใช่ขาดทุน) — อธิบายเหตุผลใน task.md
- เทสต์: 2+2 ชิ้นกับโปรครบ 3 ได้ส่วนลด; ทุกโปร 8 แบบผ่านเทสต์เดิม
- จดใน task.md ว่า Python POS (`apps/pos-python/pos_python/promotions.py`, branch claude/pos-python-settings-popup) ต้องเปลี่ยนตาม — ห้ามแก้ apps/pos-python ใน branch นี้

## 6. จุดรับรู้ภาษี (ไม่แก้กฎ — ทำรายงานตรวจ)
`app/Services/Accounting/TaxComplianceService.php` VAT ใช้ doc_date, WHT ใช้ paid_on
- ห้ามเดากฎภาษี: เขียนสรุปใน task.md ว่าตอนนี้ระบบรับรู้ VAT ขาย/ซื้อ และ WHT ที่จุดไหน พร้อมไฟล์:บรรทัด และข้อที่ต้องให้ผู้สอบบัญชียืนยัน
- แก้เฉพาะบั๊กที่ชัดเจน (เช่น ไม่กรองเอกสารยกเลิก/ร่าง ออกจากรายงานภาษี) พร้อมเทสต์

## ส่งมอบ
ตาราง ข้อ → ไฟล์ → เทสต์ → ผลกระทบข้อมูลเก่า → คำสั่งซ่อม (ถ้ามี) ลง `docs/ai/task.md`
commit ทีละข้อ push (ถ้า commit/push ไม่ได้ให้เหลือไฟล์ไว้ใน workspace)
