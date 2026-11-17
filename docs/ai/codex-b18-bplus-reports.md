# Handoff - 2026-11-17 — รายงานใบขายเชื่อ B18

## ขอบเขต
- Branch: `codex/b18-bplus-reports`, แตกจาก `origin/claude/b18-exporter-vat-login` (ฐาน `bdbc23c`).
- เพิ่มข้อมูลผู้ขายเฉพาะใบขายเชื่อจาก BPlus และรายงานสองแบบใน ERP ตาม `CODEX_TASK_B18_REPORTS.md`.
- ไม่เชื่อมต่อ/เขียนข้อมูล BPlus, ไม่แตะ production, ไม่ deploy, ไม่ merge และไม่ push `main`.

## ไฟล์ที่แก้
- `scripts/bplus-route-export.py` — ส่ง `salesman_code` จาก `SLDETAIL.SLD_SLMN` → `SALESMAN.SLMN_KEY`; ค่า 0 ส่งเป็น null.
- `database/migrations/2026_11_17_000001_add_salesman_code_to_bplus_sync_documents.php` — เพิ่มคอลัมน์ nullable ที่รองรับ SQLite/PostgreSQL.
- `app/Services/Bplus/BplusSyncImporter.php` — เก็บรหัสผู้ขายที่มีค่า; ช่องว่างเก็บเป็น null.
- `app/Services/Bplus/BplusDocumentMaterializer.php` — ใบขายเชื่อและลูกหนี้เปิดใช้พนักงานขายที่จับคู่จาก `salesmen.code`; ว่าง/ไม่พบเป็น null ไม่ใช้ค่าเริ่มต้นของสายขาย. ใบจองและใบเสร็จคง flow เดิม.
- `database/migrations/2026_11_17_000002_add_bplus_credit_sale_reports.php` — ลงทะเบียนรายงาน A/B ในหมวดขาย.
- `app/Http/Controllers/ReportController.php` — เพิ่ม query/filter วันที่ พนักงานขาย ลูกค้า และเล่ม DS/DSN; ข้ามเอกสารที่ยกเลิก. รายงาน A รวมยอดระดับเอกสาร; รายงาน B ใช้จำนวน/หน่วย/ราคาฐานจาก `stock_document_items`, ใช้ข้อมูล BPlus เฉพาะหน่วยและของแถม แล้วแสดงยอดย่อยตามสินค้าและยอดรวม.
- `resources/views/reports/index.blade.php` — เพิ่มตัวเลือกรายงานและตัวกรองเฉพาะรายงาน BPlus.
- `tests/Feature/BplusDocumentsTest.php` — เพิ่มกรณีจับคู่ผู้ขาย 09, ค่าว่าง/รหัสไม่พบ, กรองรายงาน, รวมสินค้า SKU เดียวกัน และไม่นับเอกสารยกเลิก; เติมรหัสผู้ขายใน fixture ให้ตรงกับรูปแบบ export ใหม่.

## ผลทดสอบ
- ชุด BPlus: `29 tests`, `245 assertions` ผ่าน.
- ทั้งชุด: `php artisan test` ผ่าน — `1,159 tests`, `1,157 passed`, `10,137 assertions`, `2 skipped`, `6 incomplete`, ไม่มี failure/error.
- การรันทดสอบส่ง log ไป stderr และย้าย Laravel storage/compiled views ไป `/private/tmp` เพราะ sandbox ไม่อนุญาตให้เขียน runtime files ใน repo.
- PHP lint ของไฟล์ PHP ที่แก้, Python AST parse ของ exporter และ `git diff --check` ผ่าน. Pint `--test` แจ้ง formatter fixers ใน ReportController และ BplusDocumentsTest; ไม่รัน formatter ทับทั้งไฟล์เพื่อเลี่ยง diff รูปแบบที่ไม่เกี่ยวข้อง.
- Migration ถูกทดสอบผ่าน SQLite ใน `RefreshDatabase`; ยังไม่ได้ execute migration บน PostgreSQL จริง.

## Mutation check
- ถอดเงื่อนไขกรอง `salesman_id` ออกจาก query รายงานชั่วคราว แล้วรัน `test_bplus_credit_sale_reports_filter_and_total_by_seller_customer_and_book`.
- เทสต์แดงด้วย assertion จริง: คาด 1 เอกสาร แต่ได้ 3 (`exit 1`). คืนตัวกรองเดิมแล้ว; ชุด BPlus และ full suite รอบสุดท้ายผ่าน.

## เทียบกับไฟล์ BPlus หลังดึงข้อมูลรอบใหม่
1. เจ้าของดึง/นำเข้า B18 รอบใหม่ผ่านขั้นตอนที่อนุมัติไว้ แล้วเปิดรายงาน **BPlus ใบขายเชื่อแยกตามเอกสาร** และ **BPlus ใบขายเชื่อแยกตามสินค้า**.
2. ตั้งวันที่ `16/11/2569` (`2026-11-16`), พนักงานขาย `09`, ลูกค้าทั้งหมด, เล่ม `DS และ DSN`, และสาขาของ B18 ให้ตรงกับไฟล์ `~/Desktop/b18.xls` และ `~/Desktop/18.xls` ที่ดึงรอบเดียวกัน.
3. ใน A เทียบเลขที่เอกสารรายใบกับ `b18.xls`, จากนั้นตรวจยอดรวมคาดหวัง: 47 เอกสาร, ก่อนภาษี `93,989.11`, VAT `698.69`, สุทธิ `94,687.80`. ตรวจตัวอย่าง `DS256911/06745` ว่า VAT `124.63`.
4. ใน B เทียบรหัสสินค้า/จำนวน/หน่วย/ของแถมและยอดเงินรายบรรทัดกับ `18.xls`; ยอดรวมคาดหวัง `94,687.80` และจำนวน `1,706.9`. รวมยอดต่อ SKU ก่อนเทียบกับ subtotal ต่อสินค้าในรายงาน.
5. เอกสารที่ไม่ระบุผู้ขาย `DSN256911/5079`, `/5135`, `/5174`, `/5326` (รวม `947.00`) ต้องไม่เข้าผลลัพธ์เมื่อกรองพนักงานขาย 09.

ค่าคาดหวังข้างต้นมาจาก brief และไฟล์ที่เจ้าของระบุ; ยังไม่ได้ดึงข้อมูลใหม่หรือทำ UAT เทียบกับ BPlus หลัง deploy. ชุดทดสอบนี้ใช้ fixture ใน SQLite เท่านั้น.

## ความเสี่ยง / งานถัดไป
- ต้องให้เจ้าของดึงข้อมูล BPlus รอบใหม่และเทียบรายงานกับไฟล์ `.xls` ก่อน UAT/merge. Runtime ของเครื่องนี้ยังไม่มี `xlrd` จึงไม่ได้อ่าน workbook ตัวอย่างรอบนี้.
- ให้ตรวจ PostgreSQL migration ในฐานทดสอบแยกและตรวจยอดจริงก่อน deploy ตาม `docs/OPERATIONS.md` เมื่อมีคำสั่งจากเจ้าของ.
- Deploy: ยังไม่ deploy.
