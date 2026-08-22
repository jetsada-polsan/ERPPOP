# แผน Deploy — งานค้างสะสมถึง 2026-08-23

> **ยังไม่ deploy** เอกสารนี้เตรียมไว้ให้เจ้าของตรวจก่อนอนุมัติ
> ขั้นตอนจริงอยู่ใน `docs/OPERATIONS.md` เอกสารนี้เพิ่มเฉพาะส่วนที่ต่างจากปกติ

## สิ่งที่จะขึ้น

| กลุ่ม | commit | ผลกับผู้ใช้ |
|---|---|---|
| POS desktop + ping สาขา | `2b1dfa9..645b916` | ต้อง build POS 1.5.0 ผ่าน GitHub Actions ด้วย ไม่งั้นสาขายังไม่ได้ของ |
| รายงานนับบิลซ้ำ | `5107003` | ยอดในรายงานตามหมวด/คนขายจะลดลง เพราะเลิกนับซ้ำ — **เป็นการแก้ให้ถูก ไม่ใช่ยอดหาย** |
| ทะเบียนรายงาน + สิทธิ์ | `711d133` | เมนูรายงานมาจากฐานข้อมูล, มีหน้า `ตั้งค่า → ทะเบียนรายงาน` |
| ใบจอง/เจ้าหนี้/สมุดเงินสด + นโยบายสิทธิ์ | รอบ 2026-08-23 | migration 4 ตัว ดูด้านล่าง |

## Migration ที่ต้องรัน (4 ตัว)

```
2026_08_22_000143_create_report_definitions_table
2026_08_23_000144_add_delivery_tracking_to_sale_bookings
2026_08_23_000145_create_supplier_open_items_table
2026_08_23_000146_rebuild_cash_books_for_auto_posting
2026_08_23_000147_apply_report_ownership_and_access_policy
```

ทดสอบแล้วทั้ง SQLite (ชุดเทสต์) และ PostgreSQL (ฐานทดสอบแยก `jeterp_migcheck` บน host
สร้าง → migrate → rollback 4 ขั้น → migrate ใหม่ → drop ทิ้ง; ฐาน `jeterp` ไม่ถูกแตะ)

### จุดที่ต้องระวังเป็นพิเศษ

`2026_08_23_000146_rebuild_cash_books_for_auto_posting` **สร้างตาราง `cash_books` ใหม่**
(เปลี่ยน debit/credit/balance เป็น cash_in/cash_out/running_balance + เพิ่มคอลัมน์ posting)
migration มีตัวกันไว้: ถ้า `cash_books` มีข้อมูลอยู่จะ **หยุดทันทีและไม่ทำอะไร**
ตอนตรวจ 2026-08-22 production มี 0 แถว — **ให้ตรวจซ้ำก่อน deploy**

```sql
SELECT count(*) FROM cash_books;
```

## แผน rollback

```bash
# ย้อนทั้งชุด (ทดสอบแล้วบน PostgreSQL)
php artisan migrate:rollback --step=5
```

| migration | ย้อนแล้วได้อะไรคืน | ข้อมูลที่หาย |
|---|---|---|
| `000147` นโยบายสิทธิ์ | คืน owner/frequency/priority เป็นค่าว่าง เปิดรายงานที่ status=available กลับทั้งหมด คืน all_branches ให้ ACC ถอนจาก EXECUTIVE | การเปิด/ปิดรายงานที่ผู้บริหารตั้งเองหลัง deploy |
| `000146` สมุดเงินสด | คืนโครงสร้างเดิม (debit/credit/balance) | **รายการในสมุดเงินสดทั้งหมด** — จึงมีตัวกันไม่ให้รันถ้ามีข้อมูล |
| `000145` เจ้าหนี้รายใบ | ทิ้งตาราง `supplier_open_items` | ยอดเจ้าหนี้รายใบที่เปิดไว้ (ledger ยังอยู่ครบ) |
| `000144` ใบจอง | ทิ้ง 4 คอลัมน์ส่งของ | กำหนดส่ง/สถานะส่งของที่กรอกไว้ |
| `000143` ทะเบียนรายงาน | ทิ้งตาราง + ถอนสิทธิ์ export/all_branches | การตั้งค่าเปิด/ปิดรายงาน |

**สรุป**: rollback ปลอดภัยถ้าทำทันทีหลัง deploy ยิ่งใช้งานไปนานยิ่งเสียข้อมูลที่เกิดใหม่
ถ้าเลย 1 วันไปแล้วให้ restore จาก backup แทนการ rollback

## ก่อน deploy

1. `php artisan erp:backup` บน production แล้วยืนยันว่าไฟล์ + SHA-256 ครบ
2. `SELECT count(*) FROM cash_books;` ต้องได้ 0
3. rsync `--dry-run --itemize-changes` ดูรายการก่อนเสมอ (ต้องไม่มี `.claude/`, `.env`, `storage/`)

## หลัง deploy

1. `php artisan migrate --force` แล้วดูว่าขึ้นครบ 5 ตัว
2. `php artisan erp:health`
3. เปิด `ตั้งค่า → ทะเบียนรายงาน` ดูว่านับได้ 87 รายการ เปิดอยู่ 48
4. เปิดหน้ารายงานด้วยบัญชีที่ไม่มี `reports.all_branches` แล้วยืนยันว่าเลือกสาขาอื่นไม่ได้

## เรื่องที่ต้องตัดสินใจก่อน deploy

**ผู้ใช้ที่ไม่มีสาขาจะเห็นรายงานเป็นค่าว่าง** — บน production มี active 5 คน

| role | มี all_branches หลัง migration | ผล |
|---|---|---|
| IT_MGR | ใช่ | ปกติ |
| MARKETING | ไม่ | **เห็นรายงานว่างเปล่า** ตามนโยบายข้อ 6 ที่สั่งไว้ |
| CASHIER, DELIVERY | ไม่ | ไม่มีสิทธิ์รายงานอยู่แล้ว ไม่กระทบ |

ถ้าต้องการให้ MARKETING ใช้งานต่อได้ ให้กำหนดสาขาให้บัญชีนั้นก่อน deploy
