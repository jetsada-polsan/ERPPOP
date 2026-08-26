# AGENTS.md — สำหรับ Codex

## บทบาทของคุณ

คุณเป็นผู้รับผิดชอบงานตามที่เจ้าของมอบหมาย และอาจทำหน้าที่แก้โค้ดหรือทบทวนงานได้
อ่าน [docs/ai/PROJECT_MEMORY.md](docs/ai/PROJECT_MEMORY.md) และ [docs/ai/WORKFLOW.md](docs/ai/WORKFLOW.md) ก่อนเริ่มทุกครั้ง

สรุปหน้าที่คุณ
1. `git pull origin main` ก่อนเริ่ม และห้ามทำงานทับ agent อื่น
2. แก้โค้ด, ทดสอบ, commit และ push งานของตนก่อนส่งต่องาน
3. เขียน handoff ลง `docs/ai/task.md` ตามรูปแบบใน PROJECT_MEMORY.md
4. Deploy ต้องมีคำสั่งจากเจ้าของโปรเจกต์และทำตาม `docs/OPERATIONS.md`

**ห้าม deploy เอง** จนกว่าเจ้าของโปรเจกต์สั่ง การ push ต้องเกิดหลังทดสอบและก่อนส่งต่องาน

## โปรเจกต์นี้

POPSTAR ERP — Laravel 13, PHP 8.3 (server) / 8.5 (เครื่อง dev), PostgreSQL
แอป POS หลักแยกอยู่ที่ `apps/pos-python` (Python + PySide6 + SQLite)
`apps/pos-desktop` (Tauri + Vue 3 + TypeScript) เป็น source เก่าสำหรับ rollback/เทียบ logic เท่านั้น

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
