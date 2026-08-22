# CLAUDE.md — สำหรับ Claude

## บทบาทของคุณ

คุณเป็นผู้รับผิดชอบงานตามที่เจ้าของมอบหมาย และอาจทำหน้าที่แก้โค้ดหรือทบทวนงานได้
อ่าน [docs/ai/PROJECT_MEMORY.md](docs/ai/PROJECT_MEMORY.md) และ [docs/ai/WORKFLOW.md](docs/ai/WORKFLOW.md) ก่อนเริ่มทุกครั้ง

เริ่มรอบงานทุกครั้งด้วย
```bash
git checkout main
git pull origin main
cat docs/ai/PROJECT_MEMORY.md
cat docs/ai/task.md
```

เมื่อได้รับมอบหมายให้ตรวจ ให้เขียนผลลง `docs/ai/claude-review.md`.
เมื่อได้รับมอบหมายให้แก้ ให้แก้ ทดสอบ commit push แล้วอัปเดต `docs/ai/task.md`.

**ห้าม deploy** จนกว่าเจ้าของโปรเจกต์สั่ง และห้ามทำงานทับ Codex พร้อมกัน

## ตรวจยังไงให้ได้ผลจริง

- **รันจริงทุกครั้ง อย่าอ่านโค้ดแล้วเดา** `php artisan test` ใช้เวลาไม่ถึง 2 วินาที
- **เทสต์ที่ผ่านไม่ได้แปลว่าโค้ดถูก** ถ้าเจอเทสต์ใหม่ที่ควรจะจับ bug ได้
  ให้พิสูจน์โดยถอดโค้ดที่แก้ออกชั่วคราว ดูว่าเทสต์แดงจริงไหม แล้วค่อยคืนกลับ
  (เคยเจอจริง: เทสต์ผ่านเพราะไปวิ่งเส้นทางเว็บแทนเส้นทางเครื่อง POS)
- **`enforcedCashierId()` อ่าน `request()` ตัวกลาง** ไม่ใช่ `$request` ที่ส่งเข้า action
  เทสต์ต้อง `$this->app->instance('request', $request)` ไม่งั้นทดสอบผิดเส้นทาง
- **ก่อน deploy ทุกครั้ง** `php artisan erp:backup` แล้ว rsync `--dry-run --itemize-changes`
  ดูรายการก่อนเสมอ — dry-run เคยจับได้ว่ากำลังจะอัป worktree ของ agent ขึ้น production
- แอป POS: `cd apps/pos-desktop && ./node_modules/.bin/vue-tsc --noEmit`
  ใช้ node จาก `/Applications/Codex.app/Contents/Resources/cua_node/bin`
  `vite build` รันบนเครื่องนี้ไม่ได้ (native module ของ rollup คนละ Team ID)

## โปรเจกต์นี้

POPSTAR ERP — Laravel 13, PHP 8.3 (server) / 8.5 (เครื่อง dev), PostgreSQL
production: `/var/www/jeterp` บน `27.254.143.219` (ไม่มี `.git` — deploy ด้วย rsync)
ขั้นตอน deploy อยู่ใน `docs/OPERATIONS.md` และ `.github/workflows/deploy.yml`

## กับดักในโดเมนนี้

- **migration ต้องรันได้ทั้ง SQLite และ PostgreSQL** เทสต์ใช้ SQLite แต่ของจริงเป็น PostgreSQL
- **`cashier_id` คนละความหมายกันในสองตาราง** — `pos_shifts.cashier_id` ชี้ `salesmen` (คน)
  ส่วน `pos_receipts.cashier_id` ชี้ `users` ตัวที่บอกว่าใครขายคือ `cashier_salesman_id`
- **ตัวตนแคชเชียร์มาจาก PIN ที่ผูกไว้กับเครื่อง** (`pos_devices.active_cashier_id`)
  ไม่ใช่จาก `cashier_id` ที่ client ส่งมา ระวังโค้ดใหม่ที่เชื่อค่าจาก client ตรงๆ
- **อย่าตั้งรหัสผ่าน PIN หรือออก token ให้ใคร** เตรียมคำสั่งให้เจ้าของโปรเจกต์รันเอง
  ค่าพวกนี้ถ้าผ่านหน้าจอแชทถือว่ารั่วแล้ว
