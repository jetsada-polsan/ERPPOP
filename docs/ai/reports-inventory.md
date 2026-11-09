# สำรวจรายงาน ERP — Phase 1

## สรุปผู้บริหาร (หน้าแรก)

| ประเด็น | ข้อสรุป / การตัดสินใจที่เสนอ |
|---|---|
| ขอบเขต | อ่าน source ใน branch `claude/reports-inventory`, HEAD `1653b49` วันที่ 9 พ.ย. 2026; เขียนเอกสารนี้ไฟล์เดียว ไม่แก้โค้ด ไม่ migration ไม่แตะ production ไม่ deploy ไม่ commit |
| ความครบ | จัด inventory ทะเบียนจาก migration และ dispatch จริง แยกจากหน้าปฏิบัติงาน/dashboard; มี gap เป้าหมาย ขาย 20 คลัง 25 จัดซื้อ 16 บัญชี/การเงิน 20 ขนส่ง 14 POS 14 รวม 109 รายการ และ Executive |
| ความเสี่ยงสูง | สิทธิ์กำไร/ต้นทุนเปิดกว้าง, AR Aging/หน้าบัญชีบางทางขาด branch scope, สูตรขายต่างหน้าครอบคลุมช่องทางไม่เท่ากัน, ภาษีย้อนหลังอิงอัตราปัจจุบัน (หลักฐานใน §3) |
| สิ่งที่ใช้ต่อได้ | ledger `sales_postings`, หน้าสต๊อกมี SQL pagination และหน่วยผสม, งบอ่าน GL จริง; ยังต้องกระทบยอด ไม่ถือว่าทดสอบ production ผ่าน |
| ลำดับพัฒนา | ปิดช่องสิทธิ์ → ยอดขายรายวัน → สต๊อก/stock card → ใบจองเทียบขาย → AR → รับชำระ → จัดส่ง → ขยายรายงาน/งบ/Executive |
| เงื่อนไขอนุมัติ Phase 2 | ใช้ 10 acceptance gates ใน §6, เจ้าของยืนยันนิยาม A/B และสิทธิ์ IT ใหม่; เอกสารนี้ยังไม่ใช่คำสั่ง deploy |
| ข้อจำกัด | `git pull origin main` ล้มเหลวด้วย `FETCH_HEAD: Operation not permitted` เพราะ Git metadata นอก writable workspace; ไม่เปลี่ยน branch ไม่แตะงานเดิม |

**วิธีอ่าน:** “มีแล้ว” หมายถึงพบเส้นทางและสูตรใน source ไม่ใช่รับรองยอดจริง; “มีแต่ต้องแก้” รวมการขาด filter/สิทธิ์/สูตร/UX ที่โจทย์ต้องการ; “ยังไม่มี” หมายถึงไม่พบรายงานเฉพาะในขอบเขต routes/controller ที่ตรวจ แม้อาจมีเอกสารต้นทางอยู่แล้ว ข้อเสนอทุกข้อเป็นงานอนาคต

ข้อมูล “production มี 88 แถว”, ล้างธุรกรรม 6 พ.ย. และไฟล์นอก git ราว 783 ไฟล์ เป็นบริบทจาก brief `docs/ai/codex-reports-phase1.md:8` และ `docs/ai/codex-reports-phase1.md:14`; ไม่ได้เชื่อม production เพื่อยืนยันจำนวน/ข้อมูล และไม่ใช้ไฟล์นอก repo สรุปผล

## 1. Report Inventory จาก source จริง

### 1.1 ทะเบียนและตัวคำนวณ

ทุกแถวด้านล่างใช้ GET `/reports?category=<ส่วนก่อนจุด>&report=<ส่วนหลังจุด>` route `reports.index` ที่ `routes/web.php:253` เข้า `app/Http/Controllers/ReportController.php:22`; คอลัมน์ dispatch อ้างบรรทัดจริงใน controller ส่วนทะเบียนอ้าง migration จริง ไม่ตีความว่าทุก definition เปิดในฐานปัจจุบัน

`ReportDefinition::scopeRunnable` กรอง enabled + status available (`app/Models/ReportDefinition.php:34`), controller อ่านสด (`app/Http/Controllers/ReportController.php:226`); migration policy ปิด P1/P2 (`database/migrations/2026_08_23_000147_apply_report_ownership_and_access_policy.php:35`) และเปิด P0 เพิ่มภายหลัง (`database/migrations/2026_08_23_000153_mark_p0_reports_available.php:1`) จึงห้ามใช้จำนวน migration แทนจำนวนหน้าที่เปิดจริง

สถานะ “ต้องปรับ” คือมี implementation แต่ยังไม่ผ่านมาตรฐาน phase นี้; “ซ้ำซ้อน” คือ alias/รายงานเดิมควรรวมเมนูแต่เก็บ URL รองรับ; “ข้อมูลผิด” ใช้เมื่อเห็นสูตรที่ก่อผลผิดได้จาก source (ดู §3)

| ชื่อ / code (ใช้ประกอบ URL ตามข้างต้น) | แผนก | controller dispatch | สถานะ | หลักฐานทะเบียน |
|---|---|---|---|---|
| ใบจองคงค้างและสถานะส่งสินค้า — `booking.outstanding` | ใบจอง | `app/Http/Controllers/ReportController.php:336` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:123` |
| ใบจองครบกำหนด/เกินกำหนดส่ง — `booking.due` | ใบจอง | `app/Http/Controllers/ReportController.php:337` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:124` |
| ใบจองตามสาขา/สายขาย/พนักงานขาย — `booking.by_branch_seller` | ใบจอง | `app/Http/Controllers/ReportController.php:339` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:125` |
| รายละเอียดยอดเจ้าหนี้คงค้าง — `ap.outstanding_detail` | เจ้าหนี้ | `app/Http/Controllers/ReportController.php:348` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:126` |
| อายุหนี้เจ้าหนี้ (AP aging) — `ap.aging` | เจ้าหนี้ | `app/Http/Controllers/ReportController.php:359` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:127` |
| สมุดเงินสดรายวัน (ยกมา/รับ/จ่าย/คงเหลือ) — `cash.daily_cash_book` | เงินสด/ธนาคาร | `app/Http/Controllers/ReportController.php:369` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:128` |
| สรุปยอดธนาคารตามบัญชี — `cash.bank_summary` | เงินสด/ธนาคาร | `app/Http/Controllers/ReportController.php:379` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:129` |
| รายการกระทบยอด statement — `cash.bank_reconciliation` | เงินสด/ธนาคาร | `app/Http/Controllers/ReportController.php:389` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:130` |
| ยอดขายรายวัน แยก POS/ขายสดหลังบ้าน/ขายเชื่อ — `sales.daily_by_channel` | ขาย | `app/Http/Controllers/ReportController.php:327` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:131` |
| การรับชำระและยอดเงินรอพิสูจน์ — `payment.received_and_unidentified` | รับชำระ / การเงิน | `app/Http/Controllers/ReportController.php:400` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:132` |
| ยอดขายรายวัน — `sales.daily_sales` | ขาย | `app/Http/Controllers/ReportController.php:407` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:161` |
| ยอดขายตามสาขา — `sales.sales_by_branch` | ขาย | `app/Http/Controllers/ReportController.php:414` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:162` |
| ยอดขายตามพนักงาน/แคชเชียร์ — `sales.sales_by_staff` | ขาย | `app/Http/Controllers/ReportController.php:420` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:163` |
| ยอดขายตามหมวดสินค้า — `sales.sales_by_category` | ขาย | `app/Http/Controllers/ReportController.php:427` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:164` |
| ยอดขายตามคนขาย — `sales.sales_by_seller` | ขาย | `app/Http/Controllers/ReportController.php:435` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:165` |
| ยอดขายตามหมวดสินค้า / คนขาย — `sales.sales_by_category_seller` | ขาย | `app/Http/Controllers/ReportController.php:443` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:166` |
| สินค้าขายดี — `sales.top_products` | ขาย | `app/Http/Controllers/ReportController.php:452` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:167` |
| สินค้าขายตามสาขา — `sales.products_by_branch` | ขาย | `app/Http/Controllers/ReportController.php:459` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:168` |
| ใบขายเชื่อ — `sales.credit_sales` | ขาย | `app/Http/Controllers/ReportController.php:476` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:169` |
| ใบจองค้างแปลงขาย — `sales.pending_bookings` | ขาย | `app/Http/Controllers/ReportController.php:484` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:170` |
| ยอดขายตามใบจอง — `sales.sales_by_booking` | ขาย | `app/Http/Controllers/ReportController.php:492` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:171` |
| ใบขาย-รับคืน ตามเอกสาร — `sales.sales_returns_by_document` | ขาย | `app/Http/Controllers/ReportController.php:502` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:172` |
| รายงานรับจ่าย-รับคืนสินค้า ตามเอกสาร — `sales.bplus_sales_return_by_document` | ขาย | `app/Http/Controllers/ReportController.php:511` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:173` |
| สรุปขาย-รับคืน ตามสินค้า — `sales.sale_return_by_product` | ขาย | `app/Http/Controllers/ReportController.php:520` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:174` |
| รายงานสรุปการขาย-รับคืนตามสินค้า — `sales.bplus_sale_return_by_product` | ขาย | `app/Http/Controllers/ReportController.php:530` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:175` |
| รายงานสรุปยอดขายตามลูกค้า — `sales.sales_summary_by_customer` | ขาย | `app/Http/Controllers/ReportController.php:540` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:176` |
| รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้ — `sales.sales_summary_12m_customer` | ขาย | `app/Http/Controllers/ReportController.php:549` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:177` |
| รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้-สินค้า — `sales.sales_summary_12m_customer_product` | ขาย | `app/Http/Controllers/ReportController.php:556` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:178` |
| รายงานสรุปยอดขาย 12 เดือน ตามหมวดสินค้า — `sales.sales_summary_12m_category` | ขาย | `app/Http/Controllers/ReportController.php:565` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:179` |
| รายงานสรุปยอดขาย 12 เดือน ตามพนักงานขาย-สินค้า — `sales.sales_summary_12m_salesman_product` | ขาย | `app/Http/Controllers/ReportController.php:572` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:180` |
| กำไรขั้นต้นเบื้องต้น — `management.gross_margin` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:467` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:181` |
| สินค้าขายต่ำกว่าทุน / ขาดทุน — `management.loss_sales` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:581` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:182` |
| รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน — `management.loss_sales_6m` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:592` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:183` |
| รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามประเภทสินค้า — `management.loss_sales_6m_by_type` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:602` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:184` |
| รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามยี่ห้อสินค้า — `management.loss_sales_6m_by_brand` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:603` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:185` |
| รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามหมวดสินค้า — `management.loss_sales_6m_by_category` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:604` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:186` |
| รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามผู้จำหน่ายหลัก — `management.loss_sales_6m_by_supplier` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:605` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:187` |
| รายงานราคาขายต่ำกว่าทุนตามตารางราคา — `management.loss_price_table` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:606` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:188` |
| รายงานสรุปเอกสารขายที่ขาดทุน — `management.loss_sales_documents_summary` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:614` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:189` |
| รายงานรายละเอียดเอกสารขายที่ขาดทุน — `management.loss_sales_documents_detail` | ผู้บริหาร | `app/Http/Controllers/ReportController.php:621` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:190` |
| สรุปยอดลูกหนี้ — `ar.ar_summary` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:632` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:191` |
| รายงานสรุปยอดลูกหนี้ — `ar.ar_summary_bplus` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:640` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:192` |
| อายุหนี้ AR Aging — `ar.ar_aging` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:648` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:193` |
| ลูกหนี้เกินกำหนด — `ar.overdue_customers` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:653` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:194` |
| ลูกหนี้คงค้าง — `ar.open_items` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:661` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:195` |
| รายงานรายละเอียดยอดลูกหนี้ แบบย่อ — `ar.ar_detail_short` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:669` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:196` |
| รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด — `ar.ar_detail_full` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:676` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:197` |
| รายงานรายละเอียดลูกหนี้เกินกำหนดชำระ — `ar.ar_overdue_detail` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:687` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:198` |
| รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต — `ar.ar_over_credit_limit` | ลูกหนี้ | `app/Http/Controllers/ReportController.php:696` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:199` |
| สินค้าคงเหลือ — `inventory.stock_balance` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:703` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:200` |
| สต็อกตามสาขา — `inventory.stock_by_branch` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:705` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:201` |
| สต็อกต่ำ / ติดลบ — `inventory.stock_alerts` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:713` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:202` |
| Lot ใกล้หมดอายุ / หมดอายุ — `inventory.expiring_stock` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:721` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:203` |
| เคลื่อนไหวสินค้า — `inventory.stock_movements` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:734` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:204` |
| สรุปเอกสาร — `documents.documents_summary` | เอกสาร | `app/Http/Controllers/ReportController.php:758` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:205` |
| รายการเอกสารทั้งหมด — `documents.document_list` | เอกสาร | `app/Http/Controllers/ReportController.php:765` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:206` |
| รายการสินค้าในเอกสาร — `documents.document_items` | เอกสาร | `app/Http/Controllers/ReportController.php:776` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:207` |
| ใบจอง — `documents.booking_documents` | เอกสาร | `app/Http/Controllers/ReportController.php:787` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:208` |
| ใบขายสด — `documents.cash_sale_documents` | เอกสาร | `app/Http/Controllers/ReportController.php:788` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:209` |
| ใบขายเชื่อ — `documents.credit_sale_documents` | เอกสาร | `app/Http/Controllers/ReportController.php:789` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:210` |
| ใบรับคืนสินค้า — `documents.sale_return_documents` | เอกสาร | `app/Http/Controllers/ReportController.php:790` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:211` |
| ใบเสร็จรับเงิน — `documents.receipt_documents` | เอกสาร | `app/Http/Controllers/ReportController.php:791` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:212` |
| ใบเสร็จ POS — `pos.pos_receipts` | POS | `app/Http/Controllers/ReportController.php:793` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:213` |
| ยอดขายตามเครื่อง POS — `pos.pos_by_terminal` | POS | `app/Http/Controllers/ReportController.php:802` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:214` |
| รับชำระตามช่องทาง — `pos.pos_payments` | POS | `app/Http/Controllers/ReportController.php:808` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:215` |
| ยอดขายรายชั่วโมง — `pos.pos_hourly` | POS | `app/Http/Controllers/ReportController.php:814` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:216` |
| ภาษี / ส่วนลด POS — `pos.pos_tax_discount` | POS | `app/Http/Controllers/ReportController.php:820` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:217` |
| เอกสารซื้อสินค้า — `purchasing.purchase_documents` | ซื้อสินค้า | `app/Http/Controllers/ReportController.php:829` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:218` |
| ยอดซื้อตามผู้ขาย — `purchasing.purchase_by_supplier` | ซื้อสินค้า | `app/Http/Controllers/ReportController.php:838` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:219` |
| รับสินค้าเข้าตามสินค้า — `purchasing.purchase_items` | ซื้อสินค้า | `app/Http/Controllers/ReportController.php:845` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:220` |
| เอกสารโอนสินค้า — `transfer.stock_transfers` | โอนสินค้า | `app/Http/Controllers/ReportController.php:852` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:221` |
| รายการสินค้าโอน — `transfer.transfer_items` | โอนสินค้า | `app/Http/Controllers/ReportController.php:861` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:222` |
| ยอดโอนตามคลังต้นทาง/ปลายทาง — `transfer.transfer_by_location` | โอนสินค้า | `app/Http/Controllers/ReportController.php:870` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:223` |
| เอกสารรับชำระ — `payment.payment_documents` | รับชำระ / การเงิน | `app/Http/Controllers/ReportController.php:877` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:224` |
| ตัดหนี้ / จัดสรรยอด — `payment.payment_allocations` | รับชำระ / การเงิน | `app/Http/Controllers/ReportController.php:885` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:225` |
| GL Journal — `payment.gl_journals` | รับชำระ / การเงิน | `app/Http/Controllers/ReportController.php:893` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:226` |
| รายงานภาษีขาย — `tax.vat_sales` | ภาษี (ภพ.30) | `app/Http/Controllers/ReportController.php:306` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:227` |
| รายงานภาษีซื้อ — `tax.vat_purchase` | ภาษี (ภพ.30) | `app/Http/Controllers/ReportController.php:316` | ข้อมูลผิด | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:228` |
| ประวัติลบบิล / ยกเลิกบิลย้อนหลัง — `audit.void_bill_history` | ตรวจสอบระบบ | `app/Http/Controllers/ReportController.php:901` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:229` |
| ตรวจสอบเอกสารที่ถูกยกเลิก — `audit.deleted_bill_audit` | ตรวจสอบระบบ | `app/Http/Controllers/ReportController.php:911` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:230` |
| งานค้างต้องตาม — `audit.pending_work` | ตรวจสอบระบบ | `app/Http/Controllers/ReportController.php:921` | ต้องปรับ | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:231` |
| ตัวอย่างเดิม: ยอดขายรายวัน POS (p_reportZ) — `custom.legacy_daily_pos` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:260` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:232` |
| ตัวอย่างเดิม: สรุป POS + หลังบ้าน (p_daily_sales_summary) — `custom.legacy_daily_summary` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:267` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:233` |
| ตัวอย่างเดิม: ยอดขายตามพนักงาน (p_sale-BI6) — `custom.legacy_salesman` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:274` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:234` |
| ตัวอย่างเดิม: วิเคราะห์กำไรตามสินค้า (p_sales_profit) — `custom.legacy_sales_profit` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:281` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:235` |
| ตัวอย่างเดิม: เติมเต็ม/แผนสต๊อก (p_planstock) — `custom.legacy_reorder` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:290` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:236` |
| ตัวอย่างเดิม: ขาย-รับคืนตามเอกสาร — `custom.legacy_sales_return` | รายงานต้นแบบ PopStar เดิม | `app/Http/Controllers/ReportController.php:298` | ซ้ำซ้อน | `database/migrations/2026_08_22_000143_create_report_definitions_table.php:237` |
| Stock Card รายละเอียด — `inventory.stock_card_detail` | สินค้าและสต็อก | `app/Http/Controllers/ReportController.php:742` | ต้องปรับ | `database/migrations/2026_11_02_000001_add_stock_card_detail_report.php:11` |

### 1.2 Dashboard และหน้ารายงานนอกทะเบียน

| ชื่อ / route | Controller:บรรทัด | แผนก | สถานะ / หลักฐาน |
|---|---|---|---|
| Dashboard `/dashboard` | `app/Http/Controllers/DashboardController.php:13` | รวม/ตามสิทธิ์ | ต้องปรับ; กรองสาขาตาม reports permission ที่ :20, ใช้ ledger :42; ไม่ใช่ controller แยกตาม role แต่ใช้ชุดข้อมูลร่วมและสิทธิ์ |
| Executive `/executive`, `/executive/data` | `app/Http/Controllers/ExecutiveDashboardController.php:24`, `:33` | ผู้บริหาร | ข้อมูลสิทธิ์ต้องแก้; JSON มี cost/profit :84, scope :71; routes `routes/web.php:163` |
| Management `/management-controls` (กำไรสาขา/งบ/เวลา/เงินเดือน/จัดซื้อ/e-commerce/monitor) | `app/Http/Controllers/ManagementControlController.php:14` | ผู้บริหาร/HR/จัดซื้อ | ต้องปรับ; กำไรทุกสาขา :24, แต่กลุ่มอื่นตรวจสิทธิ์ :17; route `routes/web.php:165` |
| TB/P&L/BS `/financial-statements?sheet=…` | `app/Http/Controllers/FinancialStatementController.php:24` | บัญชี | ต้องปรับ scope/กระทบยอด; GL :36, TB :70, P&L :81, BS :97; route `routes/web.php:489` |
| บัญชีรายเดือน `/monthly-accounting` + export/download | `app/Http/Controllers/MonthlyAccountingController.php:27`, `:258`, `:275` | บัญชี | ต้องแก้ scope; route `routes/web.php:580`; ค่าใช้จ่าย paginate 20, statement limit 100 :34 |
| Bplus POS preparation / workbench | `app/Http/Controllers/BplusOperationController.php:34`, `:60` | POS | ต้องปรับ; jobs paginate 30 :37; workbench scope :68; routes `routes/web.php:792` |
| Bplus purchase planning | `app/Http/Controllers/BplusOperationController.php:106` | จัดซื้อ | ต้องปรับ; ใช้ replenishment :121; route `routes/web.php:795` |
| Bplus finance / tax / approvals | `app/Http/Controllers/BplusOperationController.php:211`, `:262`, `:186` | บัญชี | ซ้ำซ้อนบางงานกับ monthly; cashbook eager load paginate :214; routes `routes/web.php:798` |
| Bplus show-price / QR config | `app/Http/Controllers/BplusOperationController.php:348`, `:300` | master/POS | หน้าปฏิบัติงาน ไม่ใช่รายงานยอด; routes `routes/web.php:805`, `routes/web.php:809` |
| POS control / Z report | `app/Http/Controllers/PosController.php:1017`, `:995` | POS | ต้องปรับ; มีปิดกะ float/deposit :819; route กลุ่ม POS `routes/web.php:202` |
| Offline sale review | `app/Http/Controllers/PosOfflineSaleController.php:55` | POS/ตรวจสอบ | ข้อมูลสิทธิ์ต้องแก้; paginate 50 แต่ไม่ scope branch; route `routes/web.php:222` |
| Fleet board / driver / load sheet | `app/Http/Controllers/FleetController.php:25`, `:32`, `:39` | ขนส่ง | ต้องปรับเป็นรายงานค้นย้อนหลัง; routes `routes/web.php:136` |
| Fleet cost report `/fleet/report` | `app/Http/Controllers/FleetController.php:230` | ขนส่ง | ต้องแก้ scope; eager-load trips/repairs ทั้งช่วงและ sum ใน PHP :235; route `routes/web.php:135` |
| Scale prices / export | `app/Http/Controllers/ScalePriceController.php:20`, `:182` | master/คลัง | ต้องปรับเฉพาะการเชื่อมรายงาน ไม่รวมยอดธุรกรรม; route `routes/web.php:673` |
| Report governance `/settings/reports` | `app/Http/Controllers/ReportGovernanceController.php:1` | IT/เจ้าของ | ทะเบียนเปิดปิด ไม่ใช่รายงานตัวเลข; route `routes/web.php:757` |

ขอบเขตนี้รวมหน้าปฏิบัติงานที่ brief ระบุเพื่อไม่หลงนับเป็นรายงานใหม่; หน้า CRUD/พิมพ์เอกสารรายใบอื่นไม่ถือเป็นรายงาน aggregate

### 1.3 Export, pagination และประสิทธิภาพ

| เรื่อง | สิ่งที่พบจริง | ผลต่อแผน |
|---|---|---|
| Excel/CSV | `app/Http/Controllers/ReportController.php:70` เรียกสูตรเดิมแต่ cap 50,000; XLSX จริง :119, CSV :135; CSV เตือนท้ายไฟล์ :155 แต่ XLSX writer call :122 ไม่แนบคำเตือนเดียวกัน | ต้องมี manifest จำนวนแถว/filters/เวลา/ยอดรวมและแจ้ง truncation ทั้งสองชนิด |
| พิมพ์/PDF | `resources/views/reports/index.blade.php:917` เปิด print=1 แล้ว browser print :923; controller :48 พิมพ์หน้าปัจจุบัน | มี browser Save as PDF; ไม่พบ server PDF export สำหรับรายงานกลางนี้ ห้ามเรียกว่า PDF engine พร้อมใช้งาน |
| Pagination | stock balance มี SQL paginate จริง `app/Services/Reports/StockBalanceReport.php:51`; ตัวอื่นเช่น daily channel `app/Http/Controllers/ReportController.php:952` ใช้ limit; tableResult :1171 total=rows count | ยังไม่ใช่ pagination ครบทุกตัว; หน้า 2 ของรายงาน limit-only ไม่ได้ offset; KPI ห้าม sum จาก page |
| Search | stock UI เปิด async เฉพาะ stock_balance `resources/views/reports/index.blade.php:926`; date/branch/q/per_page มาจาก controller :60 | ต้องยกเป็น pattern ทุก report; filter ปัจจุบันไม่ครบโจทย์ |
| N+1 | management map สาขาแล้ว query sales/COGS/expense ต่อสาขา `app/Http/Controllers/ManagementControlController.php:24`; stock formatter query หน่วยเป็น batch `app/Services/Reports/StockQuantityFormatter.php:20` | management เปลี่ยน grouped aggregate; หน่วยคง batch เดิม |
| Query หนัก | stock cross join สินค้า×location `app/Services/Reports/StockBalanceReport.php:15`; stock card correlated prior balance ต่อ movement `app/Http/Controllers/ReportController.php:2359`; VAT get ทั้งช่วง :1259 | ต้อง EXPLAIN PostgreSQL จริงและวัดจำนวน query/หน่วยความจำ; พิจารณา window sum + opening balance, indexes ตามแผนจริง ไม่เดาว่าช้า production |
| UX table | มีตัวเลขชิดขวาและ number_format `resources/views/reports/partials/result-table.blade.php:23`; sort-mark :8 เป็นสัญลักษณ์ ไม่มี handler ใน partial | ต้องเพิ่ม sort/filter/visibility ที่ server ตรวจ whitelist; ไม่ถือว่าลูกศรคือ sort ใช้งานแล้ว |

## 2. Gap Analysis ครบเป้าหมายรายแผนก

โจทย์ระบุจำนวนแต่ไม่ได้แจกแจงชื่อครบ 109 จึงเสนอชื่อรายการสำหรับอนุมัติด้านล่าง ไม่อ้างว่าเป็นรายชื่อเดิมที่เจ้าของยืนยันแล้ว แหล่งข้อมูลในแถวที่ “ยังไม่มี” เป็น candidate จาก implementation ที่อ้าง ต้องตรวจ schema/ความครบก่อนลงมือ

รหัสสถานะ: **แก้** = มีแต่ต้องแก้, **มี** = มีแล้วใน source สำหรับขอบเขตแคบที่ระบุ, **ใหม่** = ยังไม่มีรายงานเฉพาะ แหล่งอ้างอิงใช้ dictionary ต่อไปนี้เพื่อให้ทุกแถวตามย้อนกลับได้:

- **S**: `app/Http/Controllers/ReportController.php:1359` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **SL**: `app/Http/Controllers/ReportController.php:936` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **ST**: `app/Http/Controllers/ReportController.php:1393` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **SP**: `app/Http/Controllers/ReportController.php:1430` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **SC**: `app/Http/Controllers/ReportController.php:1470` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **SB**: `app/Http/Controllers/ReportController.php:1702` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **SR**: `app/Http/Controllers/ReportController.php:1729` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **GP**: `app/Http/Controllers/ReportController.php:1605` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **LS**: `app/Http/Controllers/ReportController.php:2052` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **I**: `app/Services/Reports/StockBalanceReport.php:10` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **U**: `app/Services/Reports/StockQuantityFormatter.php:14` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **M**: `app/Http/Controllers/ReportController.php:2343` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **IA**: `app/Http/Controllers/ReportController.php:2259` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **EX**: `app/Http/Controllers/ReportController.php:2280` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **TR**: `app/Http/Controllers/ReportController.php:2618` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **AR**: `app/Http/Controllers/ReportController.php:2144` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **AA**: `app/Http/Controllers/ReportController.php:2098` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **AP**: `app/Http/Controllers/ReportController.php:1040` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PA**: `app/Http/Controllers/ReportController.php:2709` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PD**: `app/Http/Controllers/ReportController.php:2691` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **BK**: `app/Http/Controllers/ReportController.php:1118` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **CB**: `app/Http/Controllers/ReportController.php:1081` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **VT**: `app/Http/Controllers/ReportController.php:1236` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **VP**: `app/Http/Controllers/ReportController.php:1301` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PU**: `app/Http/Controllers/ReportController.php:2559` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PS**: `app/Http/Controllers/ReportController.php:2578` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PI**: `app/Http/Controllers/ReportController.php:2597` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PO**: `app/Http/Controllers/BplusOperationController.php:106` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **FL**: `app/Http/Controllers/FleetController.php:25` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **FC**: `app/Http/Controllers/FleetController.php:230` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PC**: `app/Http/Controllers/PosController.php:1017` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PZ**: `app/Http/Controllers/PosController.php:995` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **OF**: `app/Http/Controllers/PosOfflineSaleController.php:55` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **PP**: `app/Http/Controllers/ReportController.php:2507` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **GL**: `app/Http/Controllers/FinancialStatementController.php:24` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

- **MC**: `app/Http/Controllers/ManagementControlController.php:14` — อ่านฟังก์ชันนี้เป็นฐานข้อมูล/สูตร ไม่ใช่รับรองความถูกต้อง

### ขาย — 20

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | รายวันแยกช่องทาง | แก้ | SL | ledger ต้องหักคืนและแยก VAT/ยอดรวม |
| 2 | ตามสาขา | แก้ | S | ของเดิม POS-only ต้องใช้ ledger |
| 3 | ตามแคชเชียร์ | แก้ | ST | users กับ salesmen คนละตัวตน |
| 4 | ตามพนักงานขาย | แก้ | ST | ตัด POS-linked documents ซ้ำ |
| 5 | ตามหมวดสินค้า | แก้ | SC | master หมวดปัจจุบันทำอดีตเปลี่ยน |
| 6 | หมวด×พนักงาน | แก้ | SC | หน่วยขายต้อง normalize |
| 7 | สินค้าขายดี | แก้ | SP | เพิ่มหลังบ้านและหักคืน |
| 8 | สินค้า×สาขา | แก้ | SP | จำนวนฐาน ไม่รวมจำนวนต่างหน่วย |
| 9 | ขายเชื่อ | แก้ | AR | เอกสาร active กับ AR ไม่ใช่ยอดเดียวกัน |
| 10 | ใบจองรอขาย | แก้ | SB | ไม่นับจองเป็นรายได้ |
| 11 | ใบจองเทียบใบขาย | แก้ | SB | แยกยอดจอง/ขาย A/B ไม่ coalesce เป็นยอดเดียว |
| 12 | ขายรับคืนรายเอกสาร | แก้ | SR | active/เครื่องหมายและ note ให้ครบ |
| 13 | คืนรายสินค้า | แก้ | SR | ยอดคืนจากเอกสารอนุมัติ |
| 14 | ตามลูกค้า | แก้ | SR | ลูกค้าเดิมต้องมี snapshot |
| 15 | 12 เดือนลูกค้า | แก้ | SR | ช่วง 12 เดือน/SQLite-PostgreSQL |
| 16 | 12 เดือนลูกค้า×สินค้า | แก้ | SR | หักคืน/หน่วยฐาน |
| 17 | 12 เดือนหมวด | แก้ | SC | snapshot หมวด |
| 18 | 12 เดือนพนักงาน×สินค้า | แก้ | ST | snapshot ผู้ขาย |
| 19 | ขาย A/B และ VAT/non-VAT | ใหม่ | SB | ต้องยืนยัน no_vat และ book ไม่เดาจาก prefix |
| 20 | ส่วนลด/โปรโมชั่นรวมทุกช่องทาง | ใหม่ | SL | ส่วนลดบรรทัดกับท้ายบิลห้ามนับซ้ำ |

### คลัง — 25

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | คงเหลือหน่วยหลัก+ย่อย | แก้ | I/U | มีแล้วแต่เลือก factor ใหญ่สุดไม่ใช่หน่วยหลักที่เจ้าของกำหนด |
| 2 | stock card drill-down | แก้ | M | ต้องส่ง product/location IDs และคง filter |
| 3 | คงเหลือตามสาขา | แก้ | I | คลังร่วมต้องไม่คูณยอด |
| 4 | คงเหลือตามคลัง/ตำแหน่ง | แก้ | I | เพิ่ม filter เฉพาะ location |
| 5 | ต่ำกว่าจุดสั่งซื้อ | แก้ | IA | reserved กับ available แยก |
| 6 | ติดลบ | แก้ | IA | ต้องเห็นค่าจริงไม่ clamp ศูนย์ |
| 7 | ใกล้หมดอายุ | แก้ | EX | ใช้ lot เหลือจริง |
| 8 | หมดอายุ | แก้ | EX | แยกขายได้/กักกัน |
| 9 | เคลื่อนไหวรายวัน | แก้ | M | ครบทุก movement type |
| 10 | โอนรายเอกสาร | แก้ | TR | อนุมัติไม่เท่ากับตรวจรับ |
| 11 | โอนรายสินค้า | แก้ | TR | อย่าคูณจากหลาย lots |
| 12 | โอนต้นทางปลายทาง | แก้ | TR | รวมบริษัทต้อง net zero |
| 13 | ระหว่างทาง | ใหม่ | TR | แยก lot transit จาก available |
| 14 | ตรวจรับขาดเสียหาย | ใหม่ | TR | ค้างอนุมัติยังคงมูลค่า |
| 15 | จอง/พร้อมขาย | ใหม่ | I | on_hand-reserved และ restricted |
| 16 | lot traceability | ใหม่ | M | source_lot family |
| 17 | มูลค่าต้นทุน FIFO | ใหม่ | M | ราคาขาย stock_value ไม่ใช่ต้นทุน |
| 18 | ยอดยกมา ณ วัน | ใหม่ | M | ห้ามใช้ balance ปัจจุบัน |
| 19 | กระทบยอด ledger/balance | ใหม่ | M/I | opening+movement=closing |
| 20 | ผลตรวจนับ | ใหม่ | M | นับไม่ใช่อนุมัติปรับ |
| 21 | ปรับเพิ่มลด | ใหม่ | M | status approved เท่านั้น |
| 22 | ชำรุด/สูญหาย | ใหม่ | M | pending ยังไม่ตัด |
| 23 | แปรรูปเข้าออก | ใหม่ | M | หน่วยและต้นทุนทั้งสองด้าน |
| 24 | ไม่เคลื่อนไหว | ใหม่ | M | สินค้าไม่มี movement ต้องไม่หาย |
| 25 | conversion ไม่ครบ/ผิดปกติ | ใหม่ | U | factor ต่อสินค้าและหน่วยต้องผ่าน validation |

### จัดซื้อ — 16

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | รายวันเอกสารซื้อ | แก้ | PU | active ไม่รวมร่าง/ยกเลิก |
| 2 | ตาม supplier | แก้ | PS | ไม่รวมคืนเป็นยอดบวก |
| 3 | รับเข้ารายสินค้า | แก้ | PI | จำนวนฐานและยอดไม่คูณ lot |
| 4 | ขอซื้อค้างอนุมัติ | ใหม่ | PO | คำแนะนำไม่ใช่ PO จริง |
| 5 | PO ค้างรับ | ใหม่ | PO | ต้องมี linkage PO→receipt พิสูจน์ก่อน |
| 6 | PO เทียบรับจริง | ใหม่ | PI/PO | ห้ามจับคู่ด้วยชื่อสินค้าอย่างเดียว |
| 7 | ส่งช้าตาม supplier | ใหม่ | PO | ต้องมีวันที่นัด/วันที่รับจริง |
| 8 | ราคาซื้อย้อนหลัง | ใหม่ | PI | ใช้ราคาเอกสาร ไม่ใช่ master ล่าสุด |
| 9 | เปรียบเทียบราคาผู้ขาย | ใหม่ | PI/PS | หน่วย/ภาษีให้เท่ากัน |
| 10 | ซื้อคืน/ลดหนี้ผู้ขาย | ใหม่ | VP | note ไม่ใช่ stock movement ซ้ำ |
| 11 | ปรับต้นทุนซื้อ | ใหม่ | M | valuation correction ไม่เปลี่ยน AP |
| 12 | ซื้อ VAT/non-VAT | ใหม่ | VP | claim VAT กับ VAT amount แยก |
| 13 | ซื้อเงินสด/เครดิต | ใหม่ | PU/AP | จ่ายหนี้ไม่ใช่ซื้อเพิ่ม |
| 14 | ภาระ PO ยังไม่รับ | ใหม่ | PO | ไม่ตั้ง AP ก่อนเอกสารจริง |
| 15 | แผนเติมสินค้า | แก้ | PO | suggestion ไม่ใช่ยอดสั่งซื้อ |
| 16 | ซื้อเทียบ AP/GL | ใหม่ | PU/AP/GL | ต้อง reconcile notes/payment/valuation ต่างกัน |

### บัญชี/การเงิน — 20 (งบ 3 ชุดเพิ่มเติม)

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | ลูกหนี้สรุป | แก้ | AR | branch ของเอกสารไม่ใช่ customer master |
| 2 | ลูกหนี้รายใบ | แก้ | AR | original-paid-notes=balance |
| 3 | AR aging | แก้ | AA | ไม่มี branch filter และใช้วันนี้ |
| 4 | เกินกำหนด | แก้ | AR | as-of ต้องย้อน payment ได้ |
| 5 | เกินวงเงิน | แก้ | AR | วงเงินปัจจุบันไม่แทนประวัติ |
| 6 | รับชำระรายวัน | แก้ | PD | active/reversed และเวลาเงินเข้า |
| 7 | จัดสรรตัดหนี้ | แก้ | PA | หนึ่ง payment หลาย allocation ไม่คูณเงิน |
| 8 | เงินรอพิสูจน์ | แก้ | BK | statement matched ไม่สร้างรายได้ใหม่ |
| 9 | เจ้าหนี้รายใบ | แก้ | AP | supplier notes และคืนจ่าย |
| 10 | AP aging | แก้ | AP | as-of ไม่ใช้ balance วันนี้ |
| 11 | จ่ายเจ้าหนี้ | ใหม่ | AP | ใช้ payment ฝั่ง supplier จริง |
| 12 | สมุดเงินสด | แก้ | CB | opening ก่อน from และ scope |
| 13 | ธนาคารตามบัญชี | แก้ | BK | บัญชีส่วนกลางต้องมี policy |
| 14 | กระทบ statement | แก้ | BK | source_type+source_id กันซ้ำ |
| 15 | ภาษีขาย | แก้ | VT | snapshot VAT และ notes |
| 16 | ภาษีซื้อ | แก้ | VP | active/claim VAT/expense/notes |
| 17 | GL journal | แก้ | GL | append reversal ห้ามนับ gross เป็น net |
| 18 | ค่าใช้จ่ายสาขา | แก้ | MC | status และสาขา |
| 19 | หัก ณ ที่จ่าย | ใหม่ | VP | อ้างรายการ withholding จริง ไม่เดาจากยอดซื้อ |
| 20 | ปิดเดือน/รายการไม่กระทบยอด | แก้ | BK | ไฟล์ download ต้องตรวจ branch อีกครั้ง |

### ขนส่ง — 14

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | บอร์ดสถานะจัดส่ง | แก้ | FL | งานปฏิบัติงานต้องเพิ่มประวัติ/filters |
| 2 | ครบกำหนด/เกินกำหนด | แก้ | SB | วันนัดไม่ใช่ doc_date |
| 3 | ใบขึ้นของ | แก้ | FL | จำนวนต้องตรงใบจองก่อนโหลด |
| 4 | ส่งครบ/บางส่วน | แก้ | FL | อย่านับสถานะเป็นจำนวนส่งจริง |
| 5 | ตามสายส่ง | ใหม่ | FL | แยก sales area กับ route ที่ยืนยันแล้ว |
| 6 | ตามคนขับ | แก้ | FL | หน้า driver ยังไม่ใช่ summary ย้อนหลัง |
| 7 | ตามรถ | ใหม่ | FC | trip ไม่จำเป็นเท่ากับ booking |
| 8 | เวลาขึ้นรถถึงส่งครบ | ใหม่ | FL | ใช้ loaded_at/delivered_at |
| 9 | ส่งไม่สำเร็จ/ยกเลิก | ใหม่ | FL | ต้องมีเหตุผลจริงไม่เดา |
| 10 | ส่งเทียบออกใบขาย A/B | ใหม่ | SB | confirmed_document_id ไม่ join เลขคล้ายกัน |
| 11 | เก็บเงินปลายทาง | ใหม่ | FL/PD | รับเงินและ allocation แยก grain |
| 12 | ระยะทาง/เชื้อเพลิง | มี | FC | มีค่า aggregate ตาม trip ในช่วงที่เลือก |
| 13 | ซ่อมบำรุง/ต้นทุนรถ | มี | FC | มี sum repairs ไม่ใช่ต้นทุนต่อเที่ยว |
| 14 | ประสิทธิภาพตรงเวลา/ต้นทุนต่อเที่ยว | ใหม่ | FC/FL | ต้องยืนยัน linkage และ denominator ก่อน |

### POS — 14

| # | รายงานเป้าหมาย | สถานะ | แหล่งข้อมูล (dictionary) | gap / ความเสี่ยงยอดผิด |
|---|---|---|---|---|
| 1 | ใบเสร็จ | แก้ | PC | completed/void แยก |
| 2 | ตามเครื่อง | แก้ | PC | branch จาก terminal/shift ต้องคงประวัติ |
| 3 | ตามช่องทางชำระ | แก้ | PP | mixed payment เป็นหลายแถวไม่หลายบิล |
| 4 | รายชั่วโมง | แก้ | PC | เวลาขายจริงของ offline |
| 5 | ภาษี/ส่วนลด | แก้ | PP | snapshot และบิล void |
| 6 | กะเปิดปิด/Z | แก้ | PZ | cashier_id ใน shift คือ salesmen |
| 7 | เงินทอนยกมา/คงไว้ | แก้ | PZ | null รุ่นเก่าไม่แปลว่า 0 |
| 8 | ยอดนำฝาก | แก้ | PZ | counted_cash-float_kept |
| 9 | เงินเกินขาด | แก้ | PC | opening variance ต้องแยก |
| 10 | เงินเข้าออกกะ | ใหม่ | PC | cash movement ไม่ใช่ sales |
| 11 | โอนยังไม่พิสูจน์ | แก้ | PC | statement match ไม่ลง receipt ใหม่ |
| 12 | บิลยกเลิก/ผู้อนุมัติ | แก้ | PC | void กลับยอด/stock/GL ครั้งเดียว |
| 13 | offline needs_review | แก้ | OF | ยังไม่ถือเป็นรายได้ ERP |
| 14 | sync health/คิวค้างรายเครื่อง | ใหม่ | OF | ERP ไม่เห็น SQLite queue ที่ยังไม่ส่ง ต้องมี telemetry ก่อน |

### Executive และงบที่เพิ่มจาก 109 รายการ

| เป้าหมาย | สถานะ/แหล่ง | เงื่อนไข |
|---|---|---|
| Executive ยอดขาย/เทียบเมื่อวาน/แนวโน้ม/สาขา/ช่องทาง/สินค้า/แจ้งเตือน | มีแต่ต้องแก้ `app/Http/Controllers/ExecutiveDashboardController.php:39` | คืน cost/profit เฉพาะสิทธิ์แยก ไม่ส่ง field ลับใน JSON; KPI กับ detail ต้องใช้ grain เดียวกัน |
| TB | มีแต่ต้องแก้ GL dictionary | ปัจจุบันแสดง movement Dr/Cr ช่วงวันที่ ไม่ใช่ TB ครบ opening+movement+closing; เทียบยอด GL และยอดยกมาจริง |
| P&L | มีแต่ต้องแก้ `app/Http/Controllers/FinancialStatementController.php:81` | ใช้ revenue/expense จริงใน GL เท่านั้น; ไม่ประมาณจาก sales-average_cost; เปิดใช้เป็นทางการเมื่อ mapping/รายการครบ |
| BS | มีแต่ต้องแก้ `app/Http/Controllers/FinancialStatementController.php:97` | สะสมถึง to + retained profit; ตรวจ opening/equity และ balanced tolerance :124; สมดุลไม่ยืนยันว่ารายการครบ |

**ตัวอย่างสมมติทั้งหมด ไม่ใช่ข้อมูล production:** POS 1,070 ที่มี CASH_SALE ผูกอยู่ ถ้ารวมสองตารางจะเป็น 2,140; ขาย 1,070 คืน 107 สุทธิ 963 (VAT ต้องใช้ snapshot ของแต่ละใบ); บิล 1,000 จ่ายสด 400 โอน 600 คือ 1 บิล/1,000 ไม่ใช่ 2 บิล/2,000; AR 1,000 จ่าย 300 ลดหนี้ 100 เหลือ 600; stock 100 ฐาน โอน 20 ในบริษัท คงรวม 100 แต่ขายได้ปลายทางเพิ่มหลังรับจริง; 1 ถุง=25 กก. และเหลือ 305 กก. แสดง **12 ถุง + 5 กก.**; factor ต้องเป็นของสินค้านั้น ไม่ใส่ 25 ตายตัว

## 3. บั๊กสูตร/สิทธิ์ที่พบจากการอ่าน source

เป็น static findings ยังไม่ได้ทดสอบ exploit หรือเทียบฐานจริง; severity อิงผลที่เกิดได้จากเงื่อนไขในโค้ด

| ระดับ | จุด/หลักฐานไฟล์:บรรทัด | อาการและเงื่อนไข | แนวแก้/ทดสอบที่เสนอ |
|---|---|---|---|
| สูง | `app/Http/Controllers/ReportController.php:2098` | arAging ไม่รับ filters ไม่มี branch condition; ผู้มีสิทธิ์รายงาน AR สาขาเดียวได้ยอดรวมทุกสาขา | shared scope ผ่านทุก query + test สองสาขาและ forged branch |
| สูง | `app/Http/Controllers/ReportController.php:327` (daily_by_channel), `:194` (admin bypass); `app/Support/RoutePermissions.php:109` (executive mapping อยู่ในไฟล์นี้) | รายวันส่ง cogs/profit แม้ permission sales.manage; executive payload :84 ให้ต้นทุน/กำไรแก่ reports.view | ใช้สิทธิ์กำไรเฉพาะและตัด field ใน server serializer/export ไม่ใช่ CSS ซ่อน |
| สูง | `app/Models/User.php:241` | users.manage+settings.manage global bypass ทุกสิทธิ์ ทำให้ IT เห็นการเงินโดยอัตโนมัติ ขัด brief ใหม่ แม้เป็นนโยบายเก่าโดยตั้งใจ | เจ้าของยืนยัน policy ใหม่แล้วแยก admin configuration จาก financial data; ไม่เปลี่ยนสิทธิ์ใน phase นี้ |
| สูง | `app/Http/Controllers/MonthlyAccountingController.php:30`, `:258`, `:275`; `app/Http/Controllers/FinancialStatementController.php:36`; `app/Http/Controllers/BplusOperationController.php:211`; `app/Http/Controllers/FleetController.php:235`; `app/Http/Controllers/PosOfflineSaleController.php:55` | หลายหน้ารับ branch ตรง/ไม่ scope และ downloadRun ตรวจเพียงไฟล์มีอยู่; route middleware ตรวจ permission ไม่ตรวจ row (`app/Http/Middleware/ErpAuthorize.php:53`) | ตรวจ allowed branches ที่ index/detail/export/download ทั้งหมด; แยก bank account ส่วนกลาง; test IDOR export run |
| สูง | `app/Http/Controllers/ReportController.php:1393` (salesByStaff บริเวณนี้), `:1374` (salesByBranch บริเวณนี้) | salesByBranch อ่าน POS อย่างเดียวและไม่กรอง completed; salesByStaff รวม receipt กับ CASH/CREDIT documents โดยไม่ตัด POS-linked และ coalesce ชื่อ user ก่อน salesman | ยึด ledger สำหรับรวมยอด; ระบุ cashier_salesman_id ตัวคน; test draft/void/POS linked |
| สูง | `app/Http/Controllers/ReportController.php:1221`, `:1237`, `:1308` | VAT เอกสารใช้ current rate และ total_amount; ซื้อไม่ตรวจ active/claim_input_vat; ขายไม่รวม CREDIT_NOTE/DEBIT_NOTE; POS vat=0 ถูก fallback คิดภาษี | ใช้ document/item snapshot + policy A/B/no_vat และ TaxComplianceService เป็นจุดเทียบ ไม่อนุมานจาก total อย่างเดียว |
| สูง | `app/Http/Controllers/ReportController.php:2052`, `:1605`; `app/Http/Controllers/ManagementControlController.php:24` | lossSalesBase และ grossMargin fallback ใช้ p.average_cost ปัจจุบัน ทำกำไรย้อนหลังเปลี่ยนเมื่อ master เปลี่ยน; loss ใช้ qty×unit_price ต่างหน่วยได้; management net profit ใช้ยอดรวมเอกสารรวมภาษีเทียบ COGS/expense | ใช้ posted cost/VAT/qty snapshots; legacy ที่ขาดแสดง “ไม่ทราบ” พร้อม coverage ห้ามสร้างต้นทุนสมมติ |
| กลาง | `app/Http/Controllers/ReportController.php:1171`, `:952`; `resources/views/reports/index.blade.php:345` | total ของ limit-only คือจำนวนแถวที่ดึง ไม่ใช่ผลทั้งหมด; KPI บางหน้า sum page; export ดึงมากกว่าหน้าจอ | aggregate query แยก page และใช้ filter contract เดียว |
| กลาง | `app/Services/Reports/StockQuantityFormatter.php:20`, `:30`; `app/Models/ProductUnit.php:37` | หน่วยผสมมีแล้วแต่ใช้ barcode unit_factor ใหญ่สุด ใช้ float ไม่อิง qty_per_base_unit โดยตรง; packSize มี parsing ชื่อหน่วย | ยืนยัน conversion ต่อสินค้า/หน่วยและ preferred display; DecimalMath และ test เศษ/ลบ/หลาย barcode/ชื่อผิด |
| กลาง | `app/Http/Controllers/ReportController.php:1702` | booking report coalesce ยอดขายกับยอดจองเป็นคอลัมน์เดียว ไม่มีแยก A/B/ภาษี; จึงใช้เป็นยอดรับรู้รายได้ไม่ได้ | แยก booking amount, converted sale amount, variance และสถานะ |
| กลาง | `app/Services/Reports/StockBalanceReport.php:15`; `app/Http/Controllers/ReportController.php:2359`; `app/Http/Controllers/ManagementControlController.php:24` | cross join / correlated balance / query ต่อ branch เสี่ยงเมื่อข้อมูลโต | query plan และ benchmark dataset ก่อนเลือก index/window; ไม่ยืนยัน latency จาก source |
| ต่ำ | `resources/views/reports/partials/result-table.blade.php:8`, `:23` | มีลูกศรแต่ partial ไม่มี sort action; type number ปัดจำนวนเต็ม ขณะที่ quantity รองรับ 8 ตำแหน่ง | whitelist sort/quantity column metadata; เพิ่มทดสอบ fractional stock/sales |


### 3.1 ข้อค้นพบสำคัญเพิ่มเติมจาก SQL ของ ledger และ flow ต้นทาง

- **สูง — ledger ยังไม่ใช่ยอดขายสุทธิหลังคืน:** `database/migrations/2026_07_31_000141_create_sales_postings_view.php:40` สร้าง **VIEW ไม่ใช่ตาราง posting ถาวร**; ฝั่ง POS เฉพาะ completed :68 และฝั่งเอกสารจำกัด CASH_SALE/CREDIT_SALE :93 ไม่รวม SALE_RETURN/CREDIT_NOTE/DEBIT_NOTE ดังนั้น daily/Executive ที่ sum view ตรง ๆ ยังไม่หักคืน แม้กัน POS กับ CASH_SALE ซ้ำได้ ตัวอย่างสมมติขาย 1,070 คืน 107 หน้านี้ยังอาจเป็น 1,070 แทน 963 ต้องออกแบบ signed sales/adjustment dataset โดยไม่เอา booking มาบวก และเพิ่ม return/notes tests
- **สูง — คำว่า gross_profit ยังรวม VAT ในฐานขาย:** view คำนวณ `r.net_sales - costs.cogs_amount` และ `d.total_amount - costs.cogs_amount` (`database/migrations/2026_07_31_000141_create_sales_postings_view.php:63`, `database/migrations/2026_07_31_000141_create_sales_postings_view.php:88`) ทั้งที่มี vat_amount แยก จึงต้องยืนยันความหมาย net_sales และใช้รายได้ก่อน VAT ก่อนเรียกกำไร; POS legacy document_id NULL มีทุน/กำไร NULL :62 อย่า coalesce ให้ดูเป็นกำไรศูนย์โดยไม่แสดง coverage
- **สูง — รายงาน GL กลางขาด branch scope เช่นกัน:** `app/Http/Controllers/ReportController.php:2728` เริ่ม glJournals และกรองวัน/ค้นหา แต่ไม่มี applyBranch ต่างจากรายงาน payment; ต้องทดสอบทั้ง `/reports?category=payment&report=gl_journals` และ `/financial-statements`
- **สูง — alias ต้นแบบเปิดกำไรด้วย reports.view:** `database/migrations/2026_08_22_000143_create_report_definitions_table.php:235` ให้ `custom.legacy_sales_profit` เป็น reports.view และ dispatch ไป grossMargin (`app/Http/Controllers/ReportController.php:281`); แม้ปิดเมนูตาม enabled ในฐานต้องปิดช่องเปิดกลับพร้อมกำหนดสิทธิ์ที่ถูกต้อง
- **Flow ใบจอง/จัดส่ง:** `app/Services/Sales/BookingDeliveryService.php:48` บันทึกส่งครบได้เมื่อ job delivered, loaded_at/delivered_at และ load sheet ครบ; audit :63 และ due query :82 เป็นข้อมูลจริงสำหรับรายงานเวลา/ค้างส่ง ส่วน `app/Services/Sales/CreditSaleService.php:55` ล็อก pending, ใช้ delivered_at หรือวันนี้ :66, สร้าง AR :138 และผูก confirmed_document_id :153 ต้องรักษา grain booking→sale ไม่คูณตาม items; service นี้ไม่ได้ตั้ง VAT snapshot ใน Document::create :74 แม้มี document_book_id จึงต้องตรวจผล no_vat จาก book และการคำนวณภาษีปลายทางก่อนสรุป A/B
- **Async เดิมมีของใช้ต่อได้:** `public/report-search.js:20` ใช้ AbortController/fetch/revision และรักษา focus, :54 debounce 500ms; ข้อเสนอคือขยาย pattern นี้ ไม่เขียนใหม่โดยมองข้ามพฤติกรรมเดิม

## 4. แบบ UI มาตรฐาน (ข้อเสนอ ไม่ใช่สิ่งที่แก้แล้ว)

ฐานเดิม: layout ใช้ `--erp-bg`, `--erp-primary`, `--erp-surface`, `--erp-text`, `--erp-muted`, `--erp-border` ใน `resources/views/layout.blade.php:59` และ :87; reports มี rail/sidebar/header ที่ `resources/views/reports/index.blade.php:192`, :202, :240 ใช้โครงนี้ต่อ ไม่สร้าง theme ขนาน

| ส่วน | มาตรฐานเสนอ |
|---|---|
| Layout | sidebar หมวดตามสิทธิ์ 240px, header ชื่อ/คำอธิบาย/อัปเดตเมื่อ/ขอบเขตข้อมูล, filter bar, KPI, chart, table, totals+pagination; tablet ย่อ sidebar เป็น drawer และ filter เป็นแผงเปิดปิด |
| Tokens | ใช้สี `--erp-*` เดิม; เพิ่มเฉพาะ report spacing 4/8/12/16/24/32px, radius 8px, control สูง 40px, row สูงอย่างน้อย 44px, text 14px, KPI 24px; ตัวเลข tabular-nums และ right aligned |
| Filter | from/to และ presets วันนี้/เมื่อวาน/7วัน/เดือนนี้/เดือนก่อน; สาขา/คลัง/พนักงาน/ลูกค้า/supplier/สินค้า/หมวด/สายส่ง/สถานะ/ประเภทภาษี เฉพาะที่ใช้กับรายงาน; server validate IDs/range และสิทธิ์ ไม่เชื่อ client |
| State | skeleton เฉพาะผลระหว่างโหลด; empty แยก “ไม่มีข้อมูล” กับ “ไม่มีสาขาที่ได้รับสิทธิ์”; error พร้อม retry และคง filter; ไม่แสดง 0 แทน query ล้มเหลว |
| Table | sticky header, comma, เงิน 2/จำนวนถึง 8 ตำแหน่งพร้อมหน่วย, sort whitelist + stable ID tie-breaker, per-column filter, column visibility, abnormal highlight พร้อมข้อความ, expand row detail; SQL pagination 25/50/100 |
| Navigation | query string เป็น canonical filter; เก็บ preference columns/filters ต่อ user+report version; back/drill-down มี return URL/filter ที่ validate แล้ว; reset page เมื่อ filter เปลี่ยน |
| Async | fetch/AbortController กันผลเก่าทับผลใหม่, debounce ค้น 500ms ตาม pattern เดิม, update URL และ fragment ไม่ refresh ทั้งหน้า, keyboard/focus/aria-live |
| Export | Excel/CSV/print/PDF ใช้ authorized query+sort+filter เดียวกับหน้า; แยก “หน้านี้” กับ “ทั้งหมดที่กรอง”; KPI ทั้งผล = export totals ทั้งผล; แสดง scope, เวลา, จำนวนแถว, rounding และ cap; PDF server เป็นงานใหม่หากต้องการไฟล์มาตรฐาน |
| Chart | daily trend / aging / delivery status มีตารางทดแทน; ไม่ส่ง series ต้นทุนให้ผู้ไม่มีสิทธิ์แม้ซ่อนกราฟ |

Reusable components (ชื่อ/paths เสนอ ยังไม่ได้สร้าง):

| Component | หน้าที่/contract |
|---|---|
| ReportPage | header/sidebar, schema ของ columns/filters, summary/page แยกกัน, states และ scope banner |
| FilterBar | typed filters + presets + reset + query string, lookup ตาม allowed branch |
| KpiCard | ค่า aggregate ทั้งผล หน่วย/ช่วงเวลา/coverage ไม่ sum หน้า |
| ReportTable | rows, columns, pagination, sort/filter events, sticky/visibility/row detail |
| ExportButtons | หน้า/ทั้งหมด, capability จาก server, cap warning และ metadata |
| DrillDownLink | primary IDs + filter context + safe return URL; server authorize ปลายทางซ้ำ |
| StockQtyCell | raw base qty, base unit, preferred pack unit/factor, decimal display; ขาด mapping แสดงฐานพร้อมเตือน ไม่เดา |

### Wireframe ข้อความ 6 รายงานแรก

```text
1 ยอดขายรายวัน
[ขาย > รายวัน] [updated/scope]                 [Excel CSV พิมพ์]
[วัน/presets][สาขา][ช่องทาง][พนักงาน][tax type]
[KPI ขายก่อน VAT][VAT][คืน][สุทธิ][บิล] [กำไรเฉพาะสิทธิ์]
[เส้นขายสุทธิรายวัน]
วันที่ | ช่องทาง | บิล | ก่อน VAT | VAT | คืน | สุทธิ → รายใบ
[ยอดรวมทั้งผล] [25 ต่อหน้า] [ก่อนหน้า 1 2 ถัดไป]

2 สต๊อกคงเหลือ
[คลัง > คงเหลือ] [ปัจจุบัน/as-of ที่รองรับ]     [ส่งออก]
[สาขา][คลัง/location][สินค้า/barcode][หมวด][ขายได้/ทั้งหมด]
[KPI SKU][ฐานคงเหลือแยกหน่วย][จอง][ติดลบ] (ไม่บวก kg กับชิ้น)
SKU | ชื่อ | location | 12 ถุง + 5 กก. | ฐาน 305 กก. | จอง | พร้อมขาย → stock card
[stock card: ยกมา/เข้า/ออก/คงเหลือ/lot/เอกสาร] [กลับคง filter]

3 ใบจองเทียบใบขาย
[ขาย > ใบจองเทียบขาย]                         [ส่งออก]
[วันจอง/วันขาย][สาขา][ลูกค้า][สายส่ง][สถานะ][A/B]
[KPI จอง][ขายแล้ว][ค้าง][ผลต่าง]
ใบจอง | นัดส่ง | สถานะส่ง | ใบขาย A/B | ยอดจอง | ยอดขายจริง | ผลต่าง → เอกสาร
(ไม่รวมยอดจองเข้ารายได้)

4 ลูกหนี้
[บัญชี > ลูกหนี้] [as-of/coverage]             [ส่งออก]
[ณ วันที่][สาขา][ลูกค้า][พนักงาน][เกินกำหนด]
[KPI ตั้งหนี้][รับชำระ][ลดหนี้][คงค้าง][เกินกำหนด]
[aging chart]
ลูกค้า | ใบขาย | ครบกำหนด | ตั้งหนี้ | จ่าย/ลดหนี้ | ค้าง → ledger รายใบ

5 รับชำระ
[การเงิน > รับชำระ]                          [ส่งออก]
[วันที่เงินเข้า][สาขา][ลูกค้า][วิธีชำระ][สถานะพิสูจน์]
[KPI รับจริง][ตัดหนี้][ยังไม่จัดสรร][รอพิสูจน์]
ใบรับ | ช่องทาง | รับจริง | จัดสรร | คงเหลือ | statement → allocation detail
(ยอดรับแสดงครั้งเดียวต่อ receipt/payment line)

6 จัดส่ง
[ขนส่ง > สรุปจัดส่ง]                         [ส่งออก]
[วันนัด/วันส่งจริง][สาขา][สายส่ง][รถ][คนขับ][สถานะ]
[KPI รอโหลด][กำลังส่ง][ส่งครบ][เกินนัด]
[สถานะรายวัน]
ใบจอง | นัด | รถ/คนขับ | ขึ้นรถ | ส่งจริง | สถานะ | ใบขาย A/B → load sheet/POD
[กลับบอร์ดโดยคง filters]
```

## 5. แผนพัฒนาแบ่งเฟส (รออนุมัติ)

ทุก phase คง URL/permission เดิมเท่าที่ไม่ขัด policy ใหม่; แยก query service ใหม่ behind feature flag, เทียบผลเก่า/ใหม่กับเอกสารต้นทางก่อนเปิด; ไม่เปลี่ยน transaction/posting จากงานรายงาน และไม่ backfill ต้นทุน/ภาษีด้วยค่าที่เดา

| เฟส/ผลส่งมอบ | ไฟล์ที่จะแตะ (ข้อเสนอ) | เทสต์เทียบเอกสารต้นทาง | ความเสี่ยง/กันของเดิมพัง |
|---|---|---|---|
| 0 สิทธิ์+filter contract | `app/Support/RoutePermissions.php`, `app/Models/User.php`, `app/Http/Controllers/ReportController.php`, Executive/Monthly/Financial/Fleet/Offline controllers; เพิ่ม `app/Services/Reports/ReportScope.php` | role matrix UI/JSON/CSV/XLSX/print/download, forged IDs, branch role หมดอายุ/ไม่มีสาขา; ขยาย `tests/Feature/ReportGovernanceTest.php` | เปลี่ยน IT policy กระทบงาน support; ยืนยันกับเจ้าของและทดสอบ existing permissions ทุกทาง |
| 1 รายวัน + UI shared | ReportController, `app/Services/Reports/DailySalesReport.php` ใหม่, `resources/views/reports/index.blade.php`, `resources/views/components/reports/*` ใหม่ | ขยาย `SalesReportChannelOverlapTest.php`, `P0ReportFiguresTest.php`, `ReportExportTest.php`; POS linked/credit/void/return/notes, filter/page/export ยอดเท่ากัน | ห้ามรวม POS กับ docs ซ้ำ; feature flag รายวันและเก็บ legacy URL |
| 2 คงเหลือ/stock card | `app/Services/Reports/StockBalanceReport.php`, `StockQuantityFormatter.php`, ReportController, StockQtyCell ใหม่ | ขยาย `StockBalanceReportTest.php`; factors ต่างกันต่อ SKU, 305/25, เศษ/ลบ/0/ไม่มี barcode, คลังร่วม, opening+movements=balance, transit; PostgreSQL plan test | ไม่แตะ stock writer; decimal และความหมายหน่วยยืนยันก่อนใช้; rollback UI ได้โดยไม่เปลี่ยนยอด |
| 3 ใบจองเทียบขาย A/B | ReportController + `BookingSalesReport.php` ใหม่ + report view; อ่าน CreditSaleService/BookingDeliveryService โดยไม่แก้ posting | ขยาย `BookingDeliveryAndCashTransferTest.php`, `BookingDeliveryDueTest.php` ด้วย report assertions; จอง pending/ส่งแล้ว/แปลง/void; A/B และ linked sale 1 ครั้ง | book.no_vat ต้องยืนยันว่าเทียบ A/B อย่างไร; ไม่สร้างยอดขายจากสถานะส่งเอง |
| 4 ลูกหนี้ | ReportController + `ReceivableReport.php` ใหม่, AR view/exports | original/payment allocation/credit note/reversal/as-of, customer ย้ายสาขา, aging bucket boundaries; เทียบ source documents และ open items | หากประวัติ allocation ไม่พอ ยืนยัน current-only และแสดง coverage แทนสร้าง as-of หลอก |
| 5 รับชำระ | ReportController + `PaymentReport.php` ใหม่, MonthlyAccountingController เฉพาะอ่าน/authorize download | ขยาย `MonthlyAccountingTest.php` และ `ReportExportTest.php`; mixed, partial, 1 payment หลาย allocations, unmatched/matched statement, reversal, cap | เปลี่ยน join แล้วเงินคูณ; แยก payment grain กับ allocation grain; ไม่สร้าง cashbook/GL ใหม่ |
| 6 จัดส่ง | `FleetController.php` เฉพาะ report query, `DeliveryReport.php` ใหม่, `resources/views/fleet/report.blade.php` และ reports view | load→transit→delivered, partial/cancelled/late, actual dates, booking→sale, collect payment ไม่เพิ่ม revenue | ไม่เปลี่ยน fleet mutation/state machine; คงบอร์ดปฏิบัติงานเดิม |
| 7 ขยาย inventory gap/จัดซื้อ/POS | report query services/views ตาม §2, Bplus read-only pages, report definition migration เฉพาะเฟสอนุมัติ | เทียบ purchase/PO/notes/receipt/lot; shift cash/float/deposit; offline retry/idempotency; pending ไม่นับ posted | mapping ที่ไม่ครบให้แสดง unavailable; ห้ามใช้ telemetry ที่ไม่มีจริง; migration ต้องผ่าน SQLite/PostgreSQL |
| 8 งบ/Executive/ประสิทธิภาพ | FinancialStatementController, ExecutiveDashboardController, ManagementControlController, report serializers/export services | GL opening+Dr-Cr=closing, reversals, COGS completeness, AR/AP/stock control accounts; executive KPI vs daily detail; query count/load benchmark | สมดุล Dr=Cr ไม่พิสูจน์ GL ครบ; เปิดงบทางการเมื่อ accounting sign-off และระบุ coverage |

## 6. ความถูกต้อง 10 ข้อและสิทธิ์ที่ต้องผ่าน

| Gate | เกณฑ์รับงาน / ตัวอย่าง fixture ที่ต้องมี | หลักฐานต้นทางที่ตรวจแล้ว |
|---|---|---|
| 1 ไม่ซ้ำ VAT A/B | จองไม่เป็นรายได้; 1 economic sale นับครั้งเดียว; แยก no_vat กับ vat snapshot | `app/Models/DocumentBook.php:10`, `app/Services/Sales/CreditSaleService.php:47`, `app/Http/Controllers/ReportController.php:938` |
| 2 หักรับคืน | posted returns/credit note กลับเครื่องหมาย; pending/cancelled ไม่หัก; คืนไม่เกินต้นทาง | `app/Http/Controllers/ReportController.php:1730` เป็นจุดเริ่มรายงานคืน; ต้องขยาย notes ให้ครบ |
| 3 สต๊อกไม่ซ้ำ | grain product+location+lot ตามรายงาน; barcode ใช้ exists; โอนภายในบริษัทไม่เพิ่มรวม | `app/Services/Reports/StockBalanceReport.php:32`, `app/Http/Controllers/ReportController.php:1183` |
| 4 รับชำระไม่ซ้ำ | 1 line เงินรับไม่คูณ allocations หรือ statement matches; matched ไม่สร้างเงินใหม่ | `app/Http/Controllers/ReportController.php:2709`, `app/Http/Controllers/MonthlyAccountingController.php:236` |
| 5 AR ตรงเอกสาร | original − payments − notes + reversals = balance ณวันเดียวกัน | `app/Http/Controllers/ReportController.php:2144`, `app/Services/Sales/CreditSaleService.php:138` |
| 6 สต๊อกตรง ledger | opening+signed movements=closing เทียบ stock_balances และ lot แยก transit | `app/Http/Controllers/ReportController.php:2343`, `app/Services/Reports/StockBalanceReport.php:22` |
| 7 หน่วยถูก | ต่อสินค้า 305 กก./25 =12 ถุง+5 กก.; แสดงฐานเสมอ; round เฉพาะ display | `app/Services/Reports/StockQuantityFormatter.php:20`, `app/Models/ProductUnit.php:37` |
| 8 สิทธิ์ถูก | UI/API/export/print/detail ทุกทางใช้ permission+scope เดียวกัน; ไม่มีต้นทุนใน response ของ Sales | `app/Http/Middleware/ErpAuthorize.php:53`, `app/Http/Controllers/ReportController.php:187`, `app/Models/User.php:47` |
| 9 อดีตไม่เปลี่ยนตาม master | เปลี่ยน cost/VAT/conversion/customer category แล้ว report historical ไม่เปลี่ยน; ข้อมูลขาดแสดง coverage | `app/Http/Controllers/ReportController.php:1221`, `:2072` เป็นจุดเสี่ยงปัจจุบัน |
| 10 export=หน้าจอ | filter/sort/scope/rounding ตรงกัน; ทั้งผลเทียบ KPI ทั้งผล; หน้านี้เทียบ page rows; cap ต้องแจ้ง | `app/Http/Controllers/ReportController.php:70`, `:119`, `:135` |

เส้นทางสั้นในตาราง gate หมายถึง `app/Http/Controllers/` สำหรับ controller และ `app/Services/Reports/` สำหรับ Stock* เพื่อไม่ให้สับสนไฟล์ชื่อเดียวกัน

| Role ตาม brief | UI ที่ควรเห็น | API/ไฟล์ export ที่ต้องบังคับ | สิ่งต่างจาก source ปัจจุบัน |
|---|---|---|---|
| Sales Admin | ขาย/จอง/ลูกค้าที่อนุญาต ไม่มีต้นทุนกำไร | omit fields/series/cost detail; export เป็น column schema ของ role | daily_by_channel มี cogs/profit อยู่แล้ว ต้องปิดที่ server |
| Warehouse | จำนวน/lot/stock card/จัดส่งตามสาขาที่มอบหมาย | ต้นทุน stock card ต้องแยกสิทธิ์ ไม่ให้ stock.manage หมายถึงเห็นทุนเสมอ | stock card มี unit_cost `app/Http/Controllers/ReportController.php:2378` |
| Accounting | AR/AP/รับจ่าย/ภาษี/GL ตาม assignment | scope ทั้ง index/detail/download; finance ไม่ให้ข้ามสาขาเอง | financial/monthly ยัง scope ไม่ครบ |
| Branch Manager | สาขาที่ได้รับมอบหมาย รวม KPI ที่อนุญาต | ปฏิเสธ branch/location อื่นแม้แก้ URL | User reportBranchIds/resolve มีฐานดี แต่บางหน้าข้าม helper |
| Executive/Owner | ทั้งบริษัทและกำไร เมื่อมีสิทธิ์จริง | explicit profit+all branches; audit exports | executive route ปัจจุบันใช้ reports.view กว้างเกิน |
| IT Admin | ตั้งค่า/health/audit เทคนิค | ไม่เห็นการเงินละเอียดอัตโนมัติ; ขอสิทธิ์เฉพาะงานตาม policy เจ้าของ | global superadmin bypass ใน User ต้องเปลี่ยนโดยอนุมัตินโยบายก่อน |

## 7. ตรวจเอกสารและข้อจำกัดการยืนยัน

- อ่าน PROJECT_MEMORY, WORKFLOW, task handoff, phase1 brief และไฟล์ source ที่อ้างจริง; ไม่ใช้ผลค้นหาเพียงชื่อไฟล์รับรองสูตร
- inventory ทะเบียนมาจาก migration ที่อ่านได้ + dispatch ของ controller; runtime enabled/role grants อาจต่างจาก source และยังไม่ได้อ่านฐานข้อมูลใด ๆ
- ยังไม่รัน PHP suite/health/SQL benchmark เพราะงานนี้เป็นเอกสารเท่านั้น ไม่รายงานว่า tests ผ่าน; รายการ tests ใน §5 เป็นสิ่งต้องทำใน phase 2
- ไม่รับรองความครบ GL, ความตรง production, latency, หรือความหมายภาษี A/B ตามกฎหมาย; ต้องตรวจเอกสารธุรกิจจริง/ให้บัญชียืนยันก่อนเลือกสูตร production
- ผลงานรอบนี้มีเฉพาะ `docs/ai/reports-inventory.md`; ไม่เขียน task.md ไม่ commit/push ตามคำสั่งล่าสุดของเจ้าของ
