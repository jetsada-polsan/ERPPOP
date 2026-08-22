# Legacy Flow ↔ Table Mapping — POPSTAR 2021

วันที่: 2026-08-23 · ผู้ทำ: Claude
**สถานะ: หลักฐานชั้นแรก — มาจาก SQL ของรายงานเดิม ยังไม่ได้ยืนยันกับฐานข้อมูลจริง**

## ที่มาของข้อมูลในเอกสารนี้

ยังแนบฐาน `BPLUSERP_POPSTAR_2021` ไม่ได้ (เหตุผลใน `legacy-popstar-2021-schema-inventory.md`)
เอกสารนี้จึงสร้างจากหลักฐานที่มีอยู่แล้วในมือ: **SQL ของรายงานเดิม 1,502 ตัว**
ในตาราง `REPORTFILE` ที่เจ้าของส่งมาก่อนหน้า (`docs/ai/legacy/reportfile-index.csv`)

วิธีทำ: จับคู่ชื่อรายงานกับ flow ธุรกิจ แล้วดึงชื่อตารางจาก `FROM` / `JOIN` ใน SQL ของรายงานนั้น
ตัวเลขในวงเล็บ = จำนวนรายงานที่อ้างถึงตารางนั้น

**ข้อจำกัดที่ต้องรู้**: 777 จาก 1,502 รายงานเก็บตรรกะไว้ในไฟล์ `.rpt` (Crystal Reports)
ซึ่งไม่ได้อยู่ใน dump ตารางที่รายงานกลุ่มนั้นใช้จึงยังมองไม่เห็น — ตัวเลขด้านล่างเป็น
**ขั้นต่ำ** ไม่ใช่รายการครบ

---

## Flow ↔ ตาราง (จากหลักฐานจริง)

### 1. ใบจอง — 31 รายงาน (มี SQL 10)

`DOCINFO`(10) · `TRANSTKH`(3) · `AROE`(3) · `PRJTAB`(3) · `ARCAT`(3) · `ARFILE`(3)
· `DEPTTAB`(3) · `BRANCH`(3) · `DOCTYPE`(3) · `ARCONDITION`(2) · `SHIPBY`(2) · `GOODSMASTER`(2)

รายงานที่ POPSTAR ทำเองเพิ่ม (`POPSTAR2022`, `POPSTAR2022-1`, `POPDEV2022`) อ่าน `DOCINFO` ล้วน
แปลว่าใบจองไม่ได้มีตารางของตัวเอง แต่เป็นเอกสารชนิดหนึ่งใน `DOCINFO` แยกด้วย `DOCTYPE`

> **จุดที่ต้องยืนยันกับฐานจริง**: `SHIPBY` และ `ARCONDITION` น่าจะเป็นที่เก็บ
> "วิธีส่ง" และ "เงื่อนไข/กำหนด" ของใบจอง — ถ้าใช่ จะเป็นต้นแบบของ `delivery_due_at`
> ที่เพิ่งเพิ่มเข้า ERP ใหม่ใน `ce81e2b`

### 2. ใบขายสด / ขายเชื่อ — 37 รายงาน (มี SQL 16)

`DOCINFO`(13) · `SLDETAIL`(2) · `TRANSTKD`(2) · `TRANSTKH`(2) · `PRMTPLAN`(2) · `SHIPBY`(2)
· `ARFILE`(2) · `ARDETAIL`(2) · `VATTABLE`(2) · `PRJTAB`(2) · `DEPTTAB`(2) · `BRANCH`(2)

โครงชัดเจน: `DOCINFO` = หัวเอกสาร, `SLDETAIL` = บรรทัดขาย,
`TRANSTKH`/`TRANSTKD` = หัว/บรรทัดการเคลื่อนไหวสต๊อก, `VATTABLE` = ภาษี

### 3. ส่งของ — 25 รายงาน (มี SQL 13)

`DOCINFO`(11) · `BANKACCOUNT`(3) · `SHIPBY`(2) · `TRANSTKH`(2) · `WAREHOUSE`(1) · `WARELOCATION`(1)

**ไม่พบตารางใบส่งของแยกต่างหาก** — สอดคล้องกับที่ ERP ใหม่ยังไม่มีเอกสารส่งของเช่นกัน
ต้องยืนยันกับฐานจริงว่าการส่งของถูกเก็บเป็น `DOCTYPE` หนึ่งใน `DOCINFO` หรือไม่

### 4. รับคืนสินค้า / ลดหนี้ — 77 รายงาน (มี SQL 54)

`DOCINFO`(47) · `TRANSTKH`(9) · `BANKACCOUNT`(9) · `ARFILE`(7) · `SKUMASTER`(7) · `TRANSTKD`(7)
· `SALESMAN`(6) · `ARDETAIL`(6) · `SHIPBY`(6) · `DOCTYPE`(7) · `PRJTAB`(7) · `DEPTTAB`(7)

### 5. เจ้าหนี้ — 52 รายงาน (มี SQL 25)

`DOCINFO`(13) · **`APFILE`(12)** · `DEPTTAB`(7) · `APADDRESS`(6) · `ADDRBOOK`(6)
· `ACCOUNTCHART`(5) · `APCAT`(4) · `TRANPAYD`(3) · `TRANPAYH`(3) · `BANKACCOUNT`(3) · `CHEQUEBOOK`(3)

`APFILE` คือแฟ้มเจ้าหนี้ · `TRANPAYH`/`TRANPAYD` คือหัว/บรรทัดการจ่ายชำระ
**ตรงกับที่ ERP ใหม่เพิ่งสร้าง `supplier_open_items` ใน `2af30a4`**

### 6. รับชำระ / ลูกหนี้ — 105 รายงาน (มี SQL 45)

`DOCINFO`(40) · `ARFILE`(14) · `DOCTYPE`(14) · `ARDETAIL`(12) · `TRANPAYH`(11)
· `PAYMENTTYPE`(8) · `TRANPAYD`(8) · `BANKACCOUNT`(7) · `BANKFILE`(7) · `SLDETAIL`(5) · `SALESMAN`(5)

หมวดที่มีรายงานมากที่สุด — สอดคล้องกับที่ ERP ใหม่มีรายงานลูกหนี้ครบ 9 ตัวแล้ว

### 7. สมุดเงินสด — 29 รายงาน (มี SQL 18)

`DOCINFO`(12) · `BANKACCOUNT`(12) · **`CASHBOOK`(3)** · `BANKSTATEMENT`(3) · `TRANPAYH`(3)
· `BRANCH`(3) · **`CASHACCOUNT`(2)** · `TRANPAYD`(1)

มี `CASHBOOK` และ `CASHACCOUNT` แยกกัน — น่าจะเป็น "รายการ" กับ "บัญชีเงินสด"
ERP ใหม่มี `cash_books` อย่างเดียว ยังไม่มีมิติ "บัญชีเงินสด" หลายบัญชีต่อสาขา

### 8. ธนาคาร / เช็ค — 77 รายงาน (มี SQL 55)

**`CHEQUEBOOK`(27)** · `DOCINFO`(16) · `CHEQUEIN`(6) · `BANKSTATEMENT`(3) · `TRANPAYH`(3)
· `BANKACCOUNT`(2) · `ARPAYMENT`(1) · `PAYMENTTYPE`(1) · `BANKFILE`(1)

`CHEQUEBOOK` (เช็คจ่าย) แยกจาก `CHEQUEIN` (เช็ครับ) ชัดเจน
ERP ใหม่มี `cheques` ตารางเดียวใช้ `direction` แยก — mapping ได้แต่ต้องตรวจว่ากติกาสถานะตรงกัน

### 9. สินค้า / สต๊อก — 133 รายงาน (มี SQL 23)

`DOCINFO`(17) · **`SKUMASTER`(14)** · `UOFQTY`(11) · `WAREHOUSE`(11) · `WARELOCATION`(11)
· `BRAND`(11) · `ICDEPT`(11) · **`SKUMOVE`(11)** · `ICCAT`(11) · `DOCTYPE`(10) · `ARPRICETAB`(2)

`SKUMASTER` = แฟ้มสินค้า, `SKUMOVE` = การเคลื่อนไหว, `UOFQTY` = หน่วยนับ,
`ICCAT`/`ICDEPT`/`BRAND` = หมวด/แผนก/ยี่ห้อ — โครงเดียวกับ ERP ใหม่ทุกตัว

### 10. ต้นทุน / กำไร — 51 รายงาน (มี SQL 25)

**`SKUMOVE`(15)** · `DOCINFO`(6) · `TRANSTKD`(2) · `TRANSTKH`(1) · `ICCOMMIT`(1)

ต้นทุนคำนวณจาก `SKUMOVE` เป็นหลัก — ต้องดูให้ออกว่าใช้ average หรือ FIFO
เพราะ ERP ใหม่รองรับทั้งสองแบบ ถ้าเลือกผิดยอดกำไรจะไม่ตรงตอน UAT

### 11. ราคา / โปรโมชัน — 111 รายงาน (มี SQL 73)

**`ARCAMPAIGN`(30)** · `DOCINFO`(18) · `SKUMASTER`(11) · `ARPRICETAB`(8) · `PRICECHANGE`(6)
· `ARCBUY`(3) · `ARPLU`(3) · `UOFQTY`(3) · `PRICETAG`(2) · `APPRICETAB`(2)

`ARCAMPAIGN` เด่นมาก (30 รายงาน) = แคมเปญ/นาทีทอง · `ARPRICETAB` = ตารางราคาขาย
· `APPRICETAB` = ตารางราคาซื้อ · `PRICECHANGE` = ประวัติเปลี่ยนราคา · `PRICETAG` = ป้ายราคา

> **ช่องว่างที่เห็นชัด**: ERP ใหม่มี `price_changes` และ `pos_price_schedules` แล้ว
> แต่ยังไม่มีตารางราคา**ซื้อ** (`APPRICETAB`) ที่ผูกกับผู้ขาย — มีแค่ `supplier_price_schedules`

### 12. POS — 15 รายงาน (มี SQL 0)

**ไม่มี SQL ในทะเบียนเลยสักตัว** ทั้ง 15 รายงานเก็บตรรกะใน `.rpt`
ยังบอกไม่ได้ว่า POS เดิมเก็บข้อมูลที่ตารางไหน ต้องดูจากฐานจริงหรือขอไฟล์ `.rpt`

### 13. พนักงานขาย / สายขาย — 46 รายงาน (มี SQL 13)

`SALESMAN`(6) · `DOCTYPE`(6) · `MISCLOOKUP`(2) · `SLDETAIL`(2) · `ARDETAIL`(2) · `SKUMOVE`(2)

`DEPTTAB` และ `PRJTAB` โผล่แทบทุก flow — น่าจะเป็นมิติ "แผนก" และ "โครงการ"
ที่ใช้แบ่งยอดข้ามทุกโมดูล **ERP ใหม่ยังไม่มีมิติโครงการเลย**

---

## เทียบกับ PostgreSQL ERP ใหม่ (ระดับความหมายธุรกิจ)

| ความหมายทางธุรกิจ | BPlus (จากหลักฐาน) | ERP ใหม่ | สถานะ |
|---|---|---|---|
| หัวเอกสารทุกชนิด | `DOCINFO` + `DOCTYPE` | `documents` + `document_types` | ✅ ตรงกัน |
| บรรทัดขาย | `SLDETAIL` | `stock_document_items` | ⚠️ ใหม่รวมบรรทัดขายกับบรรทัดสต๊อกเป็นตารางเดียว |
| เคลื่อนไหวสต๊อก | `TRANSTKH` / `TRANSTKD` / `SKUMOVE` | `stock_documents` / `stock_movements` | ✅ ตรงกัน |
| แฟ้มสินค้า / หน่วย / หมวด | `SKUMASTER` `UOFQTY` `ICCAT` `ICDEPT` `BRAND` | `products` `product_units` `product_categories` `product_departments` `product_brands` | ✅ ตรงกัน |
| ตารางราคาขาย | `ARPRICETAB` | `price_tables` + `product_prices` | ✅ ตรงกัน |
| **ตารางราคาซื้อ** | `APPRICETAB` | `supplier_price_schedules` | ⚠️ ต้องตรวจว่าความหมายเดียวกันไหม |
| แคมเปญ / นาทีทอง | `ARCAMPAIGN` | `promotions` `qty_promotions` `flash_sales` | ⚠️ ใหม่แตกเป็น 3 ตาราง ต้อง map ให้ครบ |
| ป้ายราคา | `PRICETAG` | `price_tag_templates` | ✅ ตรงกัน |
| แฟ้มลูกหนี้ / เจ้าหนี้ | `ARFILE` / `APFILE` | `customers` / `suppliers` | ✅ ตรงกัน |
| รายละเอียดหนี้รายใบ | `ARDETAIL` / (ฝั่ง AP ยังไม่ยืนยัน) | `customer_open_items` / `supplier_open_items` | ✅ ใหม่เพิ่งเติมฝั่ง AP ใน `2af30a4` |
| การจ่าย/รับชำระ | `TRANPAYH` / `TRANPAYD` | `payment_documents` / `payment_allocations` | ✅ ตรงกัน |
| เช็ครับ / เช็คจ่าย | `CHEQUEIN` / `CHEQUEBOOK` | `cheques` (ใช้ `direction`) | ⚠️ ยุบสองตารางเป็นตารางเดียว ต้องตรวจสถานะเช็คให้ครบ |
| สมุดเงินสด | `CASHBOOK` + `CASHACCOUNT` | `cash_books` | ⚠️ **ใหม่ยังไม่มีมิติ "บัญชีเงินสด"** |
| ธนาคาร / statement | `BANKACCOUNT` `BANKFILE` `BANKSTATEMENT` | `bank_accounts` `bank_statements` | ✅ ตรงกัน |
| สาขา | `BRANCH` | `branches` | ✅ ตรงกัน |
| พนักงานขาย | `SALESMAN` | `salesmen` | ✅ ตรงกัน |
| **แผนก (มิติแบ่งยอด)** | `DEPTTAB` | — | ❌ **ไม่มีใน ERP ใหม่** |
| **โครงการ (มิติแบ่งยอด)** | `PRJTAB` `MKTPLAN` | — | ❌ **ไม่มีใน ERP ใหม่** |
| **วิธีส่ง / เงื่อนไขส่ง** | `SHIPBY` `ARCONDITION` | `sale_bookings.fulfillment_type` + `delivery_due_at` | ⚠️ เพิ่งเพิ่ม ต้องเทียบว่าครอบคลุมของเดิมไหม |
| สมาชิก / แต้ม | `MEMBER` `MBPOINT` `MBTYPE` | `members` `member_point_transactions` `member_types` | ✅ ตรงกัน |
| ผังบัญชี | `ACCOUNTCHART` | `chart_of_accounts` | ✅ ตรงกัน |

---

## ช่องว่างและลำดับความสำคัญ (เสนอ ยังไม่ได้แก้โค้ด)

### P0 — ต้องรู้ก่อนจึงจะ map เอกสารได้เลย

1. **`DOCTYPE` มีชนิดเอกสารอะไรบ้าง และแต่ละชนิดมีเอกสารกี่ใบต่อปี**
   ตัวนี้ตอบว่า flow ไหนบริษัทใช้จริง ทุกอย่างที่เหลือขึ้นกับข้อนี้
   (query อยู่ใน `03_flow_tables.sql` แล้ว)
2. **trigger ทั้งหมดในฐานเดิม** — BPlus ซ่อนกฎธุรกิจไว้ใน trigger ถ้าอ่านแต่ตารางจะเข้าใจ flow ผิด
   และจะ map การตัดสต๊อก/ลงบัญชีผิดตาม
3. **`SKUMOVE` ใช้วิธีคิดต้นทุนแบบไหน** (average หรือ FIFO) — ERP ใหม่รองรับทั้งสองแบบ
   ถ้าเลือกไม่ตรงของเดิม รายงานกำไรจะไม่มีทางตรงตอน UAT
   *(หมายเหตุ: ไฟล์ `สต็อกติดลบ.txt` ที่เจ้าของส่งมาก่อนหน้า ระบุ `SKUMASTER.SKU_COST_TY`
   เป็นตัวกำหนดวิธีคิดต้นทุนรายสินค้า — ต้องนับดูว่าสินค้าจริงตั้งค่าแบบไหนกันบ้าง)*

### P1 — มิติที่ ERP ใหม่ยังไม่มี ต้องตัดสินใจว่าจะเอาไหม

4. **`DEPTTAB` (แผนก)** และ **`PRJTAB` / `MKTPLAN` (โครงการ)** โผล่ในเกือบทุก flow ของรายงานเดิม
   แปลว่าบริษัทเคยแบ่งยอดตามสองมิตินี้จริง ERP ใหม่ไม่มีเลย
   ถ้ายังต้องใช้ ต้องเพิ่มเป็นมิติก่อนทำรายงาน ไม่ใช่มาเติมทีหลัง
5. **`CASHACCOUNT`** — บัญชีเงินสดหลายบัญชีต่อสาขา ERP ใหม่มีแค่สมุดเดียวต่อสาขา
6. **`APPRICETAB`** — ตารางราคาซื้อผูกผู้ขาย ต้องตรวจว่าต่างจาก `supplier_price_schedules` อย่างไร

### P2 — ตรวจความหมายให้ตรงก่อนใช้

7. `CHEQUEIN` vs `CHEQUEBOOK` ยุบเป็น `cheques` ตารางเดียว — ตรวจสถานะเช็คให้ครบวงจร
   (ออกเช็ค → ผ่าน → เด้ง → เปลี่ยนเช็ค)
8. `ARCAMPAIGN` หนึ่งตารางในของเดิม แตกเป็น 3 ตารางในของใหม่ — ต้อง map ให้ครบทุกแบบแคมเปญ
9. รายงาน POS 15 ตัวยังไม่รู้ว่าอ่านตารางไหน — ต้องขอไฟล์ `.rpt` หรือดูจากฐานจริง

---

## สิ่งที่ห้ามทำ (ย้ำจากข้อกำหนด)

- **ห้าม import ข้อมูล POS เก่าเข้าระบบใหม่** — และตอนนี้ยังมีของเก่าค้างใน production อยู่แล้ว
  16,537 บิล (ดู `docs/architecture/LEGACY_POS_IMPORT_QUARANTINE.md`)
- การเทียบทั้งหมดในเอกสารนี้อยู่ที่ระดับ **schema และความหมายทางธุรกิจ** เท่านั้น
  ไม่มีการย้ายข้อมูลใด ๆ
