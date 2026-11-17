# รายงานงานซิงค์ใบลดหนี้ B18

Branch: `codex/b18-credit-notes` แตกจาก `codex/b18-report-audit`

## ไฟล์ที่แก้

- `scripts/bplus-route-export.py` — export ใบลดหนี้ IS/IS-N จากเอกสารที่บรรทัดอ้างใบขายเชื่อของสาย และส่งยอด/จำนวนเป็นบวก
- `app/Services/Bplus/BplusSyncImporter.php` และ `app/Http/Controllers/BplusSyncController.php` — รับ kind `credit_note`, บังคับ `sale_ref`, เก็บเลขอ้างอิง และแสดงใบลดหนี้ในหน้าเอกสารซิงค์
- `app/Services/Bplus/BplusDocumentMaterializer.php` — สร้าง `CREDIT_NOTE` เชื่อมกับใบขายเชื่อเดิม, ลดลูกหนี้แบบซิงค์ซ้ำได้, ลง GL กลับด้านตาม role ของใบขาย และกลับ GL/ลูกหนี้เมื่อยกเลิก โดยไม่สร้าง stock document หรือ stock movement
- `database/migrations/2026_11_17_000004_add_bplus_credit_note_support.php` — เพิ่ม `sale_ref` และเล่มใบลดหนี้ IS/IS-N โดย IS-N ระบุว่าไม่มี VAT
- `app/Http/Controllers/ReportController.php` — หักใบลดหนี้ในสรุปขายตามลูกค้า, รายงานขาย-รับคืนตามเอกสาร, สรุปขายเชื่อรายวัน, ภาษีขาย และ Statement ลูกหนี้
- `app/Http/Controllers/CreditDebitNoteController.php` — หน้าพิมพ์ใช้ฐานภาษี/VAT ที่ซิงค์มา และแสดง VAT 0% สำหรับ IS-N
- `app/Http/Controllers/DocumentBrowserController.php` — เปิดใบลดหนี้จากเอกสารย้อนหลัง
- `resources/views/bplus-sync/show.blade.php` — แสดงเลขใบขายเชื่อที่ใบลดหนี้อ้างถึง
- `tests/Feature/BplusDocumentsTest.php` — ครอบคลุม IS/IS-N, ลูกหนี้, GL, ยกเลิก, ซิงค์ซ้ำ, รายงาน, หน้าดูเอกสาร และสัญญา exporter แบบ offline

## คอลัมน์ BPlus และสมมติฐานที่ต้องยืนยัน

- หัวเอกสาร: `DOCINFO.DI_KEY`, `DI_REF`, `DI_DATE`, `DI_ACTIVE`, `DI_AMOUNT`, `DI_CRE_BY`, `DI_UPD_DATE`, `DI_CRE_DATE`; ประเภทเล่มจาก `DOCTYPE.DT_DOCCODE`
- หาใบขายเชื่อของสาย: `TRANSTKH.TRH_DI`, `TRANSTKD.TRD_TRH`, `TRD_REFER_DI`; เลขบิลต้นทางจาก `TRD_REFER_REF`
- รายการสินค้าใช้ `lines_of()` เดิม: `TRD_SEQ`, `TRD_SKU`, `TRD_GOODS`, `TRD_UTQNAME`, `TRD_UTQQTY`, `TRD_QTY`, `TRD_Q_FREE`, `TRD_K_U_PRC`, `TRD_DSC_KEYINV`, `TRD_B_AMT`, `TRD_VAT_TY`
- ลูกหนี้/ภาษีใช้ `ARDETAIL.ARD_DI`, `ARD_AR`, `ARD_B_SV`, `ARD_B_SNV`, `ARD_B_VAT`, `ARD_B_AMT`; ชื่อลูกค้าจาก `ARFILE.AR_KEY`, `AR_CODE`, `AR_NAME`; พนักงานขายจาก `SLDETAIL.SLD_DI`, `SLD_SLMN` และ `SALESMAN.SLMN_KEY`, `SLMN_CODE`
- **ยังไม่ได้ตรวจเครื่องหมายของ `DI_AMOUNT` กับ BPlus จริงในงานนี้** exporter ใช้ `abs()` และเมื่อรันจะแจ้งเลขเอกสารที่ต้นทางติดลบ หรือแจ้งว่าไม่พบยอดติดลบเพื่อให้เจ้าของยืนยัน ห้ามสรุปจาก fixture ว่าระบบจริงเก็บเครื่องหมายแบบใด
- คาดว่าแต่ละใบมีเลข `TRD_REFER_REF` ที่ไม่ซ้ำกันหนึ่งเลข; exporter จะข้ามและแจ้งเตือนหากไม่มีเลขหรืออ้างหลายบิล ต้องตรวจกรณีนี้จากข้อมูลจริง
- ยึดความหมาย `ARD_B_*` แบบเดียวกับใบขายเชื่อ และถือว่า IS มี VAT / IS-N ไม่มี VAT พร้อมส่งฐานและ VAT ตามค่าจาก BPlus เจ้าของควรเทียบกับตัวอย่างเอกสารจริงก่อนเปิด sync
- จำนวนจากการตรวจเดิมของเจ้าของคาดว่า IS 9 ใบ / 8,265.00 บาท และ IS-N 4 ใบ / 442.00 บาท รอบนี้ไม่ได้เชื่อม BPlus หรือรัน exporter จึงยังไม่ได้ยืนยันยอดและจำนวนหลัง export จริง

## ผลทดสอบ

- `php artisan test --no-coverage`: ผ่าน 1,168 tests, 1,166 passed, 10,398 assertions, 2 skipped, 6 incomplete; ไม่มี failure/error
- B18 credit-note cases: 4 tests ผ่าน ครอบคลุม IS, IS-N, การอ้างบิล, รายงานและสัญญา exporter
- PHP lint ทุกไฟล์ PHP ที่แก้, `python3 -m py_compile scripts/bplus-route-export.py` และ `git diff --check`: ผ่าน
- ตรวจ migration ผ่านชุดทดสอบ SQLite in-memory; ยังไม่ได้ execute migration บน PostgreSQL จริง

## Mutation checks

ถอด/ทำให้ logic เสียชั่วคราวแล้วเทสต์แดงจริง จากนั้นคืน source เดิมก่อน suite สุดท้าย:

- เอา kind/validation/dispatch/link ของใบลดหนี้ออก: preview หรือเทสต์การเชื่อมบิลล้มเหลว
- เอาการหักลูกหนี้ออก: `net_amount` กลับเป็น 1,837.00 แทน 1,730.00
- กลับด้าน GL ผิด: ไม่ได้ journal ครบ 3 รายการ; ปิด idempotency แล้ว journal เพิ่มจาก 3 เป็น 9; เอา reversal ตอนยกเลิกออกแล้วบัญชีไม่เป็นศูนย์
- เอาเครื่องหมายหักออกจากสรุปขาย/รายงานขายตามเอกสาร/BPlus รายวัน: ยอด 1,993.00 แทน 1,779.00
- เอาเครื่องหมายหัก VAT ออก: VAT สุทธิ 29.57 แทน 15.57; เอาเครื่องหมายใบลดหนี้ออกจาก Statement: คงเหลือ 1,144.00 แทน 930.00
- เอาอัตรา VAT 0% ของ IS-N ออก: เทสต์พบ `vatRate` 7% แทน 0%
- เอา route เอกสารย้อนหลังหรือเงื่อนไขอ้าง `ROUTE_SALES`/`abs(DI_AMOUNT)` ใน exporter ออก: เทสต์ route หรือ exporter contract แบบ offline แดง

ไม่มีการเชื่อม BPlus/Production, ไม่เขียน BPlus, ไม่ merge, ไม่ deploy และไม่ push `main`.
