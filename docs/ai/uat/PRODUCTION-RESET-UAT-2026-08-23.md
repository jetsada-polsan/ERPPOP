# บันทึกการล้างข้อมูลและ UAT บน production — 2026-08-23

## 1. Deploy

| | |
|---|---|
| SHA ที่ deploy | `fdaf607` (บันทึกไว้ที่ `storage/app/DEPLOYED_SHA` บน host) |
| วิธี deploy | rsync (host ไม่มี git repo) ยืนยันด้วย md5 ของไฟล์คำสั่งตรงกับเครื่องพัฒนา |
| commit ในรอบนี้ | `b9cbf37` ถอด CASCADE + เทสต์ · `2e03b69` GRANT หลัง restore · `5864fc1` เปิดทาง UAT บน production ที่ล้างแล้ว · `fdaf607` แก้ modulo by zero |
| ชุดทดสอบ | 215 passed / 2,277 assertions / 6 incomplete |

## 2. Maintenance mode

เปิด 18:11:18 — หน้าเว็บตอบ 503 ระหว่างสำรองและล้างข้อมูล ปิด 18:24:02 (`/` 302, `/login` 200)

คงเปิดไว้ตลอดช่วง UAT ด้วย ไม่ได้ปิดทันทีหลังล้าง เพื่อกันผู้ใช้จริงบันทึกรายการแทรกระหว่างยิงโหลด ซึ่งเป็นเหตุผลเดียวกับที่เปิดตั้งแต่แรก

## 3. Backup

| | |
|---|---|
| ไฟล์ | `erp-db-20260823-181119.sql.gz` (28 MB) |
| SHA-256 | `3c0d57ab19d3a19ef1941b0efd6221724b2eef3ea5adf45dc285053ea8ae07ae` |
| ตรวจสอบ | `sha256sum -c` → OK |
| ที่เก็บ | `/var/www/jeterp/storage/app/backups/` บน host |

**ไฟล์นี้เป็นทางเดียวที่จะกู้ใบขายและยอด POS เดิมกลับมาได้**

### Restore drill (ก่อนล้างจริง)

กู้ `erp-db-20260823-180436.sql.gz` เข้าฐาน `jeterp_restore_test` — 150 ตารางเท่ากัน ทุกจำนวนแถวตรง
(documents 21 · pos_receipts 16,557 · gl_journals 59 · products 5,332 · customers 7,422 · users 114 · imported_receipts 25,223 · stock_balances 2,521 · report_definitions 87)

**เจอปัญหาจริงจาก drill:** `erp:backup` ดัมป์ด้วย `--no-owner --no-privileges` ฐานที่กู้มาจึงตกเป็นของผู้ใช้ที่รัน restore แอปเปิดไม่ได้ ฟ้อง `permission denied for table failed_jobs` ทั้งที่ข้อมูลครบ แก้แล้วใน `2e03b69` — `erp:restore-drill` คืนสิทธิ์ให้ app user อัตโนมัติ รวม default privileges

## 4. Reset

เริ่ม 18:12:26 จบ 18:12:28 (2 วินาที) — **ลบ 270,616 แถว จาก 64 ตาราง · stock_balances ตั้งเป็นศูนย์ 2,521 แถว**

| กลุ่ม | แถว |
|---|---|
| staging นำเข้าเก่า | 167,547 (`imported_receipt_items` 95,588 · `imported_payments` 37,412 · `imported_receipts` 25,223 · `import_errors` 9,204 · `import_batches` 120) |
| POS | 102,832 (`pos_receipt_items` 60,823 · `pos_payments` 25,447 · `pos_receipts` 16,557 · `pos_shifts` 5) |
| เอกสาร/บัญชี/สต๊อก | 237 (`documents` 21 · `stock_documents` 21 · `stock_document_items` 61 · `gl_journals` 59 · `stock_movements` 61 · `stock_lots` 2 · `document_sequences` 7 · `quotations` 1 · `quotation_items` 3 · `tax_filing_runs` 1) |

คงไว้ครบ: products 5,332 · customers 7,422 · suppliers 355 · users 114 · roles 14 · permissions 32 · chart_of_accounts 190 · report_definitions 87 · app_settings 13 · audit_logs 56 · branches 8 · pos_devices 6 · employees 107 · salesmen 45

## 5. ตรวจหลังล้าง

`erp:health` ผ่านทุกข้อ รวม "ขาย-GL" ที่เคยไม่ผ่าน (เอกสาร 5 ใบไม่มี GL รวม 2,383 บาท ถูกล้างไปพร้อมกัน)

`stock_balances` แถวที่ไม่เป็นศูนย์ = **0** (จาก 2,521 แถวที่ยังอยู่ครบ)

## 6. UAT

### concurrency 30 users

| โหมด | ผล |
|---|---|
| number (ขอเลขเอกสาร) | 150/150 สำเร็จ · 0 ผิดพลาด · 0 deadlock · **0 เลขซ้ำ** · 29.4 เอกสาร/วินาที · p95 2,080 ms |
| sale (ขายจริงทั้งวงจร) | 150/150 สำเร็จ · 0 ผิดพลาด · 0 deadlock · **0 เลขซ้ำ** · 11.9 เอกสาร/วินาที · p95 4,671 ms |

ทรัพยากร host: load average 0.98 (ปกติ 0.25) · RAM 608/1,967 MB

### uat:reconcile — ผ่าน 7/7

| เกณฑ์ | ยอดจริง |
|---|---|
| GL ดุลทุกเอกสาร | 0 เอกสารไม่ดุล |
| เอกสารขายลง GL ครบ | 0 ใบไม่มี GL |
| เลขที่เอกสารไม่ซ้ำ | 0 เลขซ้ำ |
| sales_postings ไม่นับซ้ำ | 0 เอกสารซ้ำ |
| ต้นทุนขาย GL = บรรทัด | 9,000.00 = 9,000.00 |
| sales_postings = รายได้ + VAT | 15,000.00 = 14,019.00 + 981.00 |
| สต๊อกที่ตัด = บรรทัดที่ขาย | 150 = 150 |

### รายงาน P0 — ผ่าน 10/10

`sales.daily_by_channel` · `booking.outstanding` · `booking.due` · `booking.by_branch_seller` · `ap.outstanding_detail` · `ap.aging` · `cash.daily_cash_book` · `cash.bank_summary` · `cash.bank_reconciliation` · `payment.received_and_unidentified`

ยอดในรายงานเทียบกับฐาน:

| | รายงาน | ฐานข้อมูล |
|---|---|---|
| ขายรายวันแยกช่องทาง | 150 บิล · 15,000.00 · ต้นทุน 9,000.00 · กำไรขั้นต้น 6,000.00 | ตรงทุกตัว |
| สมุดเงินสดรายวัน | 150 รายการ ยอดสะสมเดินถูกต้อง | ตรง |
| GL | debit 24,000.0000 | credit 24,000.0000 ดุล |

**ก่อนทดสอบต้องเปิดใช้รายงาน P0 ทั้ง 10 ตัวก่อน** — สถานะเดิมเป็น `available` แต่ `enabled = false` ทั้งหมด จึงไม่ขึ้นในเมนู ตอนนี้เปิดแล้ว (เหลือปิดอยู่ 29 ตัวจากชุด P1/P2)

## บั๊กที่เจอระหว่างทาง

1. **`TRUNCATE ... CASCADE`** — เจ้าของสั่งให้ถอด พอถอดแล้วด่านตรวจ FK ฟ้องว่า whitelist ตกไป 7 ตาราง (`ecommerce_orders` `ecommerce_order_items` `pos_coupons` `pos_receipt_return_items` `stock_lot_lineages` `recall_cases` `recall_contacts`) และมีชื่อผิด 1 ตัว (`e_tax_documents` ที่ไม่มีจริง ชื่อจริง `etax_documents`) — CASCADE จะลบทั้ง 8 ตัวเงียบ ๆ
2. **`uat:concurrency --mode=sale` พังทั้ง 150 ครั้ง** ด้วย `Modulo by zero` — หาสินค้าจาก `sku_code LIKE 'UAT-%'` ซึ่งมีแต่บน staging ข้อความ error ไม่บอกสาเหตุเลย แก้ให้ตรวจก่อน fork และบอกว่าหา prefix อะไรไม่เจอ
3. **restore แล้วแอปใช้ไม่ได้** — ดูข้อ 3

## ค้างไว้

- production ตอนนี้มีข้อมูล UAT ค้างอยู่: **150 ใบขาย · 6 สินค้า `UAT-P1..P6` · 1 ลูกค้า `UAT-CUS` · 1 หน่วยนับ `UAT-EA`** — ต้องล้างก่อนใช้งานจริง (`erp:reset-transactions` ล้างใบขายให้ แต่สินค้า/ลูกค้าเป็นแฟ้มหลัก ต้องลบแยก)
- `net_sales` ใน `sales_postings` รวม VAT อยู่แล้ว ทั้งที่ชื่อบอกว่า net — ชวนอ่านผิด แต่ตัวเลขถูกและ `uat:reconcile` จับความสัมพันธ์ที่ถูกไว้แล้ว
- ยังไม่ได้ทดสอบ: AP aging / bank reconciliation / booking ด้วยข้อมูลจริง (รายงานรันผ่านแต่ยังไม่มีรายการ จึงเป็นการพิสูจน์ว่าเปิดได้ ไม่ใช่พิสูจน์ยอด)
