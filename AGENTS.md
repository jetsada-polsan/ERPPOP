# AGENTS.md — สำหรับ Codex

## บทบาทของคุณ

คุณคือฝ่าย **แก้โค้ด** ในลูปนี้ Claude คือฝ่าย **ตรวจ**
อ่านกติกาเต็มที่ [docs/ai/WORKFLOW.md](docs/ai/WORKFLOW.md) ก่อนเริ่ม

สรุปหน้าที่คุณ
1. แก้โค้ดบน `main` แล้ว commit (ยังไม่ push)
2. เขียนสิ่งที่ต้องตรวจลง `docs/ai/task.md` ตามรูปแบบใน WORKFLOW.md
3. รอผลใน `docs/ai/claude-review.md` แล้วแก้ตามนั้น
4. ทดสอบรอบสุดท้าย แล้วบอกเจ้าของโปรเจกต์ว่าพร้อม push

**ห้าม push หรือ deploy เอง** จนกว่ารายงานตรวจจะสรุปว่าผ่าน และเจ้าของโปรเจกต์สั่ง

## โปรเจกต์นี้

POPSTAR ERP — Laravel 13, PHP 8.3 (server) / 8.5 (เครื่อง dev), PostgreSQL
แอป POS แยกอยู่ที่ `apps/pos-desktop` (Tauri + Vue 3 + TypeScript)

```bash
php artisan test        # เทสต์ใช้ SQLite in-memory
php artisan erp:health  # ตรวจสุขภาพระบบ
```

## ข้อควรระวังเฉพาะที่นี่

- **migration ต้องรันได้ทั้ง SQLite และ PostgreSQL** เทสต์ใช้ SQLite แต่ของจริงเป็น PostgreSQL
- **`cashier_id` คนละความหมายกันในสองตาราง** — `pos_shifts.cashier_id` ชี้ `salesmen` (คน)
  ส่วน `pos_receipts.cashier_id` ชี้ `users` (บัญชีที่ยิง API) ตัวที่บอกว่าใครขายคือ
  `pos_receipts.cashier_salesman_id` อย่า join สองตัวแรกเข้าหากัน
- **ตัวตนแคชเชียร์มาจาก PIN ไม่ใช่จาก user** ดู `PosController::enforcedCashierId()`
  เครื่อง POS หนึ่งเครื่องรองรับแคชเชียร์หลายคน และ user หนึ่งคนถือได้หลายเครื่อง
- **อย่า commit `.claude/` หรือ `.codex/`** เป็น worktree/state ของ agent ไม่ใช่โค้ดโปรเจกต์
- **อย่าตั้งรหัสผ่าน PIN หรือออก token ให้ใคร** เตรียมคำสั่งให้เจ้าของโปรเจกต์รันเอง
