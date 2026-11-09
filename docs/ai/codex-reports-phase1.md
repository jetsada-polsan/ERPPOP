# Codex: รายงาน ERP ทุกแผนก — เฟส 1 สำรวจ + Gap Analysis + แบบ UI + แผนพัฒนา (ห้ามแก้โค้ด)

Branch `claude/reports-inventory` (จาก origin/main) ผลลัพธ์: `docs/ai/reports-inventory.md` (ภาษาไทย)
**รอบนี้เขียนเอกสารอย่างเดียว ห้ามแก้โค้ด ห้าม migration ห้ามแตะ production ห้าม deploy**
เจ้าของจะอ่านแล้วอนุมัติก่อนเริ่มเฟส 2 (ลงมือทำ)

## ของจริงที่ต้องตรวจ (อ้างไฟล์:บรรทัดทุกข้อ อย่าเดา)
- `routes/web.php` (reports, dashboard, pos.control, monthly-accounting, scale-prices ฯลฯ), `app/Http/Controllers/ReportController.php`, `ReportDefinition`/`report_definitions` (มี 88 แถวใน production), `ManagementControlController`, `FinancialStatementController`, `MonthlyAccountingController`, `BplusOperationController`, `PosController::control`, Dashboard ตาม role
- สิทธิ์: `app/Http/Middleware/ErpAuthorize.php`, permission codes (`reports.view`, `management.view` …), branch scoping
- Export Excel/PDF/พิมพ์ที่มีอยู่, pagination (server-side หรือไม่), N+1/ความเร็ว query
- ข้อมูลหน่วย: product units / conversion (`qty_per_base_unit`) สำหรับรายงานสต๊อก "12 ถุง + 5 กก."
- ใบจอง → ใบขาย A/B แยกประเภทภาษี (sale_bookings, BookingDeliveryService, CreditSaleService) — ยอดต้องไม่ซ้ำ
- POS: pos_receipts/payments/shifts (float_kept, deposit_amount), sync/offline sale review
- รู้ไว้: ล้างธุรกรรม production แล้ว 6 พ.ย. (ข้อมูลน้อย) และ production มีไฟล์แก้นอก git ค้าง ~783 ไฟล์ — ยึด source ใน repo เท่านั้น

## โจทย์ของเจ้าของ (ทำตามทุกหัวข้อ)
รายงานที่ต้องมี: ขาย 20 รายการ, คลัง 25 รายการ (รวม stock คงเหลือแบบหน่วยหลัก+หน่วยย่อย ด้วย conversion จริงของสินค้า + drill-down stock card), จัดซื้อ 16, บัญชี/การเงิน 20 (+TB/P&L/BS ถ้า GL สมบูรณ์ อ้างรายการบัญชีจริงเท่านั้น), ขนส่ง 14, POS 14, Executive dashboard (กำไร/ต้นทุนเฉพาะผู้มีสิทธิ์)
UI: sidebar หมวดรายงาน, header, filter bar (ช่วงวัน/วันนี้/เมื่อวาน/7 วัน/เดือนนี้/เดือนก่อน/สาขา/คลัง/พนักงาน/ลูกค้า/supplier/สินค้า/หมวด/สายส่ง/สถานะ/ประเภทภาษี), KPI cards, chart, data table (ตัวเลขชิดขวา, comma, หน่วย, sticky header, column visibility, sort, filter รายคอลัมน์, highlight ผิดปกติ, row detail), server-side pagination, skeleton/empty/error state, จำตัวกรอง, drill-down ไม่เสียตัวกรอง, export ยอดตรงหน้าจอ, desktop+tablet, ไม่ refresh ทั้งหน้า
ความถูกต้อง 10 ข้อ (ไม่ซ้ำ VAT A/B, หักรับคืน, สต๊อกไม่ซ้ำ, รับชำระไม่ซ้ำ, AR ตรงเอกสาร, สต๊อกตรง ledger, หน่วยถูก, สิทธิ์ถูก, ย้อนหลังไม่เปลี่ยนเพราะ master data, export = หน้าจอ)
สิทธิ์ตาม role: Sales Admin (ไม่เห็นต้นทุน/กำไร), Warehouse, Accounting, Branch Manager (สาขาตน), Executive/Owner (ทั้งหมด), IT Admin (ไม่เห็นการเงินละเอียดอัตโนมัติ) — ตรวจทั้ง UI, API และไฟล์ export

## ผลลัพธ์ใน docs/ai/reports-inventory.md
1. **Report Inventory**: ตารางทุกรายงาน/dashboard ที่มีจริง — ชื่อ, route, controller:บรรทัด, แผนก, สถานะ (ใช้ได้ / ข้อมูลผิด / ต้องปรับ / ซ้ำซ้อน), หลักฐาน
2. **Gap Analysis** เทียบรายการที่เจ้าของต้องการทุกแผนก: มีแล้ว / มีแต่ต้องแก้ / ยังไม่มี — พร้อมแหล่งข้อมูลที่ใช้คำนวณ และความเสี่ยงยอดผิด (ระบุตัวอย่างตัวเลขที่ระบุว่าเป็นตัวอย่าง)
3. **บั๊กสูตร/สิทธิ์ที่พบ** จัดลำดับ สูง/กลาง/ต่ำ
4. **แบบ UI มาตรฐาน**: โครง layout, design tokens (ใช้ของ layout เดิมเท่าที่ได้), รายการ reusable components (ReportPage, FilterBar, KpiCard, ReportTable, ExportButtons, DrillDownLink, StockQtyCell หน่วยหลัก+ย่อย) และ wireframe ข้อความของ 6 รายงานแรก
5. **แผนพัฒนาแบ่งเฟส** เริ่มจากที่ใช้ทุกวัน: ยอดขายรายวัน, สต๊อกคงเหลือ, ใบจองเทียบใบขาย, ลูกหนี้, รับชำระ, จัดส่ง — แต่ละเฟสระบุไฟล์ที่จะแตะ, เทสต์ที่ต้องเขียน (เทียบยอดกับเอกสารต้นทาง), ความเสี่ยงต่อระบบเดิม และวิธีไม่ให้ของเดิมพัง
หน้าแรกเป็นสรุปผู้บริหาร 1 หน้า ใช้ตารางให้กระชับ

commit + push บน branch ปัจจุบัน ถ้า commit/push ไม่ได้ให้เหลือไฟล์ไว้ใน workspace
