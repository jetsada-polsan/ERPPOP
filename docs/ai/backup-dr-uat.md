# Backup / DR UAT

## 2026-10-16 — restore drill บน server production (Claude ผ่าน workflow `Production check`)
- Run: GitHub Actions 38865190805 และ 38865355895 (`scripts/ops/production-check.sh`)
- `php artisan erp:backup` ผ่าน: `erp-db-20261016-140859.sql.gz` ขนาด 14M พร้อม checksum
- `erp:restore-drill --execute` ด้วย app user ล้ม: `permission denied to create database`
  → กู้แทนด้วยผู้ใช้ `postgres` ลงฐานแยก `jeterp_restore_drill` (ตรวจ checksum ด้วย erp:restore-drill ก่อน) ผ่าน
- เทียบจำนวนแถว production / ฐานที่กู้: products 5332/5332, customers 7422/7422, pos_receipts 9/9,
  pos_receipt_items 38/38, documents 8/8, gl_journals 40/40, stock_balances 2545/2545, stock_movements 38/38 — ตรงทุกตาราง
- ลบฐาน drill ทิ้งหลังตรวจ (ไม่ทิ้งสำเนาข้อมูลไว้)
- ยังไม่ทำตาม runbook: รอบส่งขึ้น Google Drive (rclone crypt) และกู้บนเครื่อง DR ในเครื่อง (`jeterp_dr`)
  → ยังไม่ติดตั้ง cron ตาม `docs/OPERATIONS.md` จนกว่าสองข้อนี้ผ่าน
- ทางเลือกถาวร: `ALTER ROLE <app user> CREATEDB` หรือกำหนด `ERP_BACKUP_DB_USERNAME` ที่สร้างฐานได้ ให้ `erp:restore-drill --execute` รันได้เอง
