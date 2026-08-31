# 02 — PEAK Account Benchmark

> อ้างอิง: [peakaccount.com/peak-manual](https://www.peakaccount.com/peak-manual), [sales-document](https://www.peakaccount.com/peak-manual/sales-document), [create-invoice-tax-invoice](https://www.peakaccount.com/peak-manual/sales-document/invoice/create-invoice-tax-invoice), [purchase-document](https://www.peakaccount.com/peak-manual/purchase-document), [setup-guide](https://www.peakaccount.com/peak-manual/setup-guide), [user-role-permissions](https://www.peakaccount.com/peak-manual/setup-guide/set-user-permissions/user-role-permissions), [withholding-tax-permanent-payment](https://www.peakaccount.com/peak-manual/purchase-document/record-expenses-wht/withholding-tax-permanent-payment)
>
> **เป้าหมายของเอกสารนี้ไม่ใช่ให้ Clone PEAK** — PEAK ออกแบบมาสำหรับ SME ที่เน้นงานบัญชี/เอกสารขาย-ซื้อเป็นหลัก ไม่มี POS offline, ไม่มี multi-warehouse/lot/FEFO แบบธุรกิจค้าส่งอาหารแช่แข็งต้องใช้ สิ่งที่ดึงมาคือ**แนวคิดการแยกเอกสารตามผลกระทบทางบัญชี** และ**การตั้งค่าระบบแบบ SME ที่ทำได้เองไม่ต้องพึ่งโปรแกรมเมอร์**

## 1. System Setup — 7 หมวดของ PEAK

| หมวด | เนื้อหา | เทียบกับ jeterp |
|---|---|---|
| ตั้งค่าองค์กร | ข้อมูลบริษัท, โลโก้/ตราประทับ, จัดการหลายกิจการในบัญชีเดียว | jeterp: `AppSetting` key-value เดียว ไม่รองรับหลายกิจการ (ดู 01-current-system-audit §A) |
| แพ็กเกจ/การชำระเงิน | ไม่เกี่ยวกับ ERP ภายใน (SaaS billing ของ PEAK เอง) | ไม่เกี่ยวข้อง |
| ข้อมูลส่วนตัวผู้ใช้ | ยืนยันอีเมล, ลายเซ็นดิจิทัล, PIN, 2FA, ThaID | jeterp มี MFA แบบ optional, มี PIN (POS) แต่ไม่มีลายเซ็นดิจิทัล/ThaID |
| **สิทธิ์ผู้ใช้งาน** | Role-based, 8 role สำเร็จรูป + สร้างเองได้ (ดูหัวข้อ 2) | jeterp มี role+permission+branch-role แล้ว แต่ไม่มี role สำเร็จรูปให้เลือกและไม่มี UI จัดการ role/permission |
| **ตั้งค่าเอกสาร** | เลขที่เอกสาร, เงื่อนไขเครดิต, รูปแบบใบกำกับภาษี, ลายเซ็น/ตราประทับอัตโนมัติในเอกสาร, ล็อกรหัสผ่านไฟล์ PDF | jeterp มี document numbering ที่แข็งแรงกว่า (race-condition-safe) แต่ไม่มี UI ตั้งค่าระดับนี้ (ต้องแก้โค้ด/migration) |
| **นโยบายบัญชี** | ล็อกข้อมูลตามงวด, กันสร้างข้อมูลซ้ำ, **นโยบาย stock แบบ periodic**, ลดหนี้/เพิ่มหนี้แบบไม่อ้างอิงเอกสารต้นทาง, ค่าเริ่มต้นราคารวม/แยก VAT | jeterp มี `AccountingPeriodGuard` (ล็อกงวดจริงระดับ Model Observer — **แข็งแรงกว่า PEAK ที่เป็นแค่ setting**) แต่ไม่มี perpetual-vs-periodic toggle (jeterp เป็น perpetual/real-time ledger อยู่แล้วซึ่งดีกว่าสำหรับธุรกิจที่มี POS) |
| **ยอดยกมา (Opening Balance)** | นำเข้ารายชื่อ contact, สินค้า/สต๊อกเริ่มต้น, ยอด AR/AP ยกมา, ยอดสินทรัพย์, ปรับ trial balance ด้วย journal — มี AI ช่วยตั้งค่า | jeterp: มี `OpeningBalanceService`, `OpeningBalanceRun` (พบใน audit §การอ้างอิง) แต่ยังไม่ตรวจสอบลึกในรอบนี้ — ควรตรวจเทียบ checklist นี้ในรอบถัดไป |

## 2. โมเดลสิทธิ์ผู้ใช้ของ PEAK

PEAK ใช้ **Role-Based Access Control พร้อม 8 role สำเร็จรูป**: System Administrator, ผู้จัดการบัญชี (full ยกเว้นตั้งค่าระบบ), นักบัญชี (full ยกเว้น Payroll/Board), นักบัญชี+เงินเดือน, ผู้จัดการเอกสาร (รายรับ-รายจ่ายทั้งหมด), ผู้ร่างเอกสาร (draft รออนุมัติ), ผู้จัดการเอกสารรายรับอย่างเดียว, ผู้จัดการใบเสนอราคาอย่างเดียว — granularity อยู่ที่ระดับ "โมดูล + การกระทำ (ดู/สร้าง/แก้/อนุมัติ/บันทึกรับเงิน)" **ไม่มี per-branch ในตัว role สำเร็จรูป** (PEAK ไม่ได้เน้น multi-branch เท่า jeterp)

**สิ่งที่ควรนำมาใช้**: ชุด role สำเร็จรูปที่ตั้งชื่อตามหน้าที่จริง (ไม่ใช่ตั้งชื่อสิทธิ์ทีละอัน) ช่วยให้ผู้ดูแลระบบที่ไม่ใช่โปรแกรมเมอร์ตั้งค่าได้เอง — jeterp ควรมี role template ชุดเริ่มต้นแบบนี้ (เช่น "แคชเชียร์สาขา", "หัวหน้าบัญชี", "ผู้จัดการสาขา") แทนที่จะให้แอดมินประกอบ permission ทีละตัวเอง

**สิ่งที่ไม่ต้องตามคือ**: PEAK ไม่มี branch-scoped role และไม่มี "draft ผู้ร่างเอกสาร" แยกจาก approval workflow แบบ jeterp ที่มี `pending_approval` status จริงในหลายโมดูล (stock adjustment, credit note) ระบบ jeterp ที่มี approval gate ต่อ **เอกสาร** (ไม่ใช่แค่ต่อ role) ละเอียดกว่าที่ PEAK ต้องการสำหรับ SME ทั่วไป

## 3. Sales / Account Receivable Flow

จากคู่มือ (sales-document + create-invoice-tax-invoice): ลำดับเอกสารคือ **ใบเสนอราคา → ใบรับมัดจำ → ใบแจ้งหนี้/ใบกำกับภาษี → ใบเสร็จรับเงิน → ใบวางบิล / ใบลดหนี้ / ใบเพิ่มหนี้** — จุดสำคัญที่ยืนยันจากคู่มือ: **การสร้างใบแจ้งหนี้ไม่บังคับต้องมีใบเสนอราคาก่อน** (สร้างอิสระได้) และระบบ "บันทึกบัญชีอัตโนมัติเมื่ออนุมัติเอกสาร" (แสดงผ่านฟีเจอร์ "booking records")

| เอกสาร PEAK | จองสินค้า | ตัด Stock | เกิด AR | เกิดรายได้ | เกิด VAT | รับเงิน | Post บัญชี |
|---|---|---|---|---|---|---|---|
| ใบเสนอราคา | ไม่ | ไม่ | ไม่ | ไม่ | ไม่ | ไม่ | ไม่ |
| ใบรับมัดจำ | ไม่ | ไม่ | ไม่ | ไม่ (เป็น liability มัดจำ) | ไม่เสมอไป | ใช่ (บางส่วน) | ใช่ (Dr เงินสด / Cr เงินมัดจำรับ) |
| ใบแจ้งหนี้/ใบกำกับภาษี | ไม่ระบุชัด (คู่มือไม่กล่าวถึงผลต่อ stock) | ไม่ระบุ | **ใช่** | **ใช่** | **ใช่** | ไม่ | ใช่ (อัตโนมัติเมื่ออนุมัติ) |
| ใบเสร็จรับเงิน | - | - | ลด AR | - | - | **ใช่** | ใช่ |
| ใบวางบิล | - | - | ไม่สร้างใหม่ (รวมยอดใบแจ้งหนี้หลายใบ) | - | - | ไม่ | ไม่ (เป็นเอกสารสรุปยอด) |
| ใบลดหนี้/ใบเพิ่มหนี้ | - | - | ปรับ AR | ปรับรายได้ | ปรับ VAT | - | ใช่ |

**ข้อสังเกตสำคัญ**: คู่มือ PEAK **ไม่ผูกใบแจ้งหนี้เข้ากับผลกระทบสต๊อกเลย** เพราะ PEAK ไม่ใช่ระบบสต๊อกจริงจัง (accounting-first ไม่ใช่ inventory-first) — **นี่คือจุดที่ jeterp ต้องออกแบบดีกว่า PEAK เสมอ**: ทุกเอกสารขายของ jeterp ต้องประกาศชัดเจนว่ากระทบสต๊อกหรือไม่ (ผ่าน `document_types.affects_stock` ที่มีอยู่แล้ว) ซึ่ง jeterp ทำได้ดีกว่าโดยธรรมชาติของธุรกิจอยู่แล้ว

**สิ่งที่ jeterp ควรรับมาพิจารณา**: concept "ใบวางบิล (Billing Note)" ที่รวมหลายใบแจ้งหนี้เป็นชุดเดียวสำหรับยื่นเก็บเงินลูกค้าองค์กร — jeterp มี `billing_notes` table อยู่แล้ว (พบใน routes/migrations ระหว่าง audit) ควรตรวจสอบว่า flow ตรงกับแนวคิดนี้หรือยัง

## 4. Purchase / Account Payable Flow

จาก purchase-document + withholding-tax-permanent-payment:

| ขั้นตอน PEAK | ผลต่อ Stock | สร้าง AP | บันทึกต้นทุน | จ่ายเงิน | หัก ณ ที่จ่าย |
|---|---|---|---|---|---|
| ใบสั่งซื้อ | ไม่ | ไม่ | ไม่ | - | ไม่ |
| ใบรับสินค้า/บริการ | **ใช่** | ไม่บังคับ (แล้วแต่ตั้งค่า) | ไม่ | - | ไม่ |
| บันทึกซื้อ/ค่าใช้จ่าย | ไม่ | **ใช่** | **ใช่ (ตอนรับของ)** | - | ไม่ |
| จ่ายชำระเงิน | ไม่ | ลดยอด | - | **ใช่** | **ใช่ (ตอนจ่ายเงิน ไม่ใช่ตอนบันทึกซื้อ)** |
| ใบลดหนี้/เพิ่มหนี้ (ฝั่งซื้อ) | ปรับ | ปรับ AP | ปรับ | - | - |

**กลไกหัก ณ ที่จ่ายที่ PEAK ทำได้ดีและ jeterp ยังไม่มี**: PEAK รองรับ 3 รูปแบบการคำนวณ WHT ต่อรายการจ่าย — (1) หักปกติ (ตัวอย่าง: ค่าบริการ 10,000 หัก 3% = 300, ผู้รับได้ 9,700), (2) "ออกใบเดียว" (gross-up ให้ยอดจ่ายจริงลงตัว), (3) **"ออกแทนให้ถาวร"** (gross-up ให้ผู้รับได้เงินเต็ม 10,000 บาทจริง โดยระบบคำนวณฐานภาษีย้อนกลับให้เอง เช่น ฐาน 10,309.28 หัก 309.28 ผู้รับได้ 10,000 พอดี) — ระบบออกใบหัก ณ ที่จ่ายอัตโนมัติพร้อมเลขที่/วันที่ที่จำเป็นสำหรับยื่น ภ.ง.ด.

**นำมาใช้กับ jeterp**: นี่ตรงกับช่องว่างที่ 01-current-system-audit ระบุไว้พอดี (§C, §H) — jeterp ควรมี (ก) ตารางอัตราภาษีหัก ณ ที่จ่ายตามหมวดค่าใช้จ่าย/ประเภทซัพพลายเออร์ (ข) ฟิลด์เลขที่ใบหัก ณ ที่จ่าย (ค) รองรับโหมดคำนวณ "หักปกติ" และ "ออกแทนให้" อย่างน้อย 2 แบบ ในผลลัพธ์ `SupplierPaymentService`

**สิ่งที่ jeterp เหนือกว่าอยู่แล้ว**: PEAK ไม่มี lot/FIFO/FEFO ในขา purchase-receiving เลย (ไม่ใช่ระบบสต๊อกจริง) ส่วน jeterp มี FIFO lot ledger จริงตอนรับของแล้ว (`FifoStockService::receive()`) — ไม่ต้องเรียนรู้อะไรจาก PEAK ในจุดนี้

## 5. Accounting Policy ที่น่าสนใจจาก PEAK

จากหน้า setup-guide หมวดนโยบายบัญชี มีตัวเลือกที่ jeterp ควรพิจารณาเพิ่ม (ไม่ใช่ต้องมีทันที):
- **"กันสร้างข้อมูลซ้ำ"** (duplicate data prevention) — ระดับ policy ไม่ใช่แค่ unique constraint ในฐานข้อมูล เช่น เตือนก่อนสร้างเอกสารซ้ำเลขที่อ้างอิงเดิม/ลูกค้าเดิมในวันเดียวกัน
- **ลดหนี้/เพิ่มหนี้แบบไม่อ้างอิงเอกสารต้นทาง** — PEAK อนุญาตให้ออกใบลดหนี้แบบ standalone ได้ (ไม่ผูกกับใบแจ้งหนี้เดิม) สำหรับกรณีปรับยอดทั่วไป — jeterp's `CreditDebitNoteService` ควรตรวจว่ารองรับกรณีนี้หรือบังคับอ้างอิงเอกสารต้นทางเสมอ (ถ้าบังคับเสมอคือดีกว่าในแง่ traceability แต่ syntax ต้องยืดหยุ่นพอสำหรับ edge case จริง)
- **ค่าเริ่มต้นราคารวม/แยก VAT ระดับนโยบายบริษัท** — jeterp มี `prices_include_vat` ต่อเอกสารอยู่แล้ว (ดีกว่า point-in-time toggle ของ PEAK)

## สรุปสิ่งที่นำมาใช้ vs ไม่นำมาใช้

**นำมาใช้ (concept)**:
1. Role template สำเร็จรูปตามหน้าที่งาน (ไม่ใช่ประกอบ permission ทีละตัว)
2. WHT gross-up modes (หักปกติ / ออกแทนให้) + เลขที่ใบหัก ณ ที่จ่าย
3. Opening-balance checklist แบบมีขั้นตอนชัดเจน (contact → product/stock → AR/AP → asset → journal adjustment)
4. แนวคิด "ใบวางบิล" รวมหลายใบแจ้งหนี้ (jeterp มีตารางอยู่แล้ว ต้องตรวจ flow)

**ไม่นำมาใช้**:
1. Accounting-first ที่ไม่ผูกกับสต๊อกจริงจัง — jeterp ต้องเป็น inventory-first เสมอ (ธุรกิจนี้เป็นค้าส่ง/ค้าปลีกสินค้าจริง)
2. Periodic inventory policy — jeterp ควรคง perpetual/real-time ledger (ดีกว่าสำหรับ POS ที่ต้องรู้สต๊อกตลอดเวลา)
3. Single-company/tenant model ของ PEAK ไม่เกี่ยวกับ multi-branch ที่ jeterp ต้องมี

**jeterp ต้องเหนือกว่า PEAK เสมอ**: POS offline-first, multi-warehouse/location, lot/FEFO, real concurrency-safe document numbering, per-branch RBAC — ทั้งหมดนี้ PEAK ไม่มีและไม่จำเป็นต้องมีสำหรับกลุ่มเป้าหมาย SME ทั่วไปของเขา
