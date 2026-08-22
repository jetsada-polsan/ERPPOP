# POPSTAR ERP/POS - ความจำกลางของทีม AI

> อ่านไฟล์นี้ก่อนเริ่มงานทุกครั้ง ไม่ว่าจะเป็น Codex, Claude หรือ agent อื่น
> แก้ไขเมื่อมีการตัดสินใจเชิงระบบ, deploy, เปลี่ยน flow หรือพบความเสี่ยงใหม่

## ระบบและที่เก็บโค้ด

- GitHub: `jetsada-polsan/ERPPOP`
- Remote: `git@github.com:jetsada-polsan/ERPPOP.git`
- Branch หลัก: `main`
- ERP: Laravel 13, PHP 8.3 (production), PostgreSQL
- POS Desktop: Vue 3 + TypeScript + Tauri + SQLite
- Production: `27.254.143.219`, deploy path `/var/www/jeterp`

## หลักการทำงานร่วมกัน

1. ทำงานทีละคนต่อหนึ่งช่วงงานเสมอ
2. ก่อนเริ่ม: `git checkout main && git pull origin main && git status`
3. อ่าน `docs/ai/PROJECT_MEMORY.md`, `docs/ai/task.md` และ commit ล่าสุดก่อนแก้
4. แก้เฉพาะงานที่ได้รับมอบหมาย, ทดสอบตามขอบเขต, commit แบบเล็กและมีความหมาย
5. Push ก่อนส่งต่องานเสมอ แล้วบันทึก commit hash และผลทดสอบใน `docs/ai/task.md`
6. Agent คนถัดไปต้อง `git pull origin main` ก่อนทำงาน ห้ามแก้ไฟล์เดียวกันพร้อมกัน
7. Deploy production ต้องได้รับอนุญาตจากเจ้าของ และห้ามใช้ `rsync --delete`

## ข้อห้ามด้านข้อมูลและความปลอดภัย

- MSSQL legacy `192.168.88.200` ใช้เพื่ออ่านข้อมูลเท่านั้น: อนุญาตเฉพาะ `SELECT`.
- ห้าม `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, migration หรือเขียนข้อมูลใด ๆ ลง MSSQL legacy.
- ห้าม force push, `git reset --hard`, หรือ checkout ทับงานผู้อื่น.
- ห้ามส่ง password, private SSH key, Device Token หรือ PIN ผ่านแชต/commit.
- ห้าม commit `.claude/`, `.codex/`, ไฟล์ backup, `.env` หรือ credentials.

## Flow ธุรกิจที่ตกลงแล้ว

- ใบเสนอราคา: ไม่ตัด/ไม่จองสต๊อก
- ใบจอง: จองเพื่อติดตามการส่ง ไม่ตัดสต๊อกและไม่รับรู้รายได้
- ขายสดหลังบ้าน: ยืนยันเอกสารแล้วตัดสต๊อกและรับรู้รายได้
- ขายเชื่อ: ยืนยันเอกสารแล้วตัดสต๊อกและเปิดลูกหนี้
- POS: บิลเสร็จสมบูรณ์แล้วตัดสต๊อกและรับรู้รายได้
- การส่งของ: ติดตามการส่งของ ห้ามสร้างรายได้/ตัดสต๊อกซ้ำ
- คืน/ใบลดหนี้: ต้องอนุมัติก่อนย้อนรายได้และรับสต๊อกกลับ
- รายงานรวมต้องอ่าน `sales_postings` เพื่อไม่รวมยอด POS กับเอกสารหลังบ้านซ้ำ

รายละเอียด: `docs/pos-flow-redesign.md`

## POS Desktop: ข้อเท็จจริงปัจจุบัน

- SQLite local มี: `products`, `promotions`, `offline_cashiers`, `checkout_queue`, `pos_sale_history`, `app_state`.
- ข้อมูล POS เก็บที่ไดรฟ์ `D:` เมื่อมีไดรฟ์ พร้อม backup, restore และ integrity check.
- POS ต้องได้รับ Device Token ที่ผูกเครื่อง/สาขา จึงเรียก `/api/pos/ping` และซิงก์ได้.
- API POS ใช้ `Authorization: Bearer <Device Token>`; URL มาตรฐานคือ `http://27.254.143.219`.
- **production ไม่ได้เปิด HTTPS** (port 443 ปิดสนิท) อย่าแนะนำ `https://` ในคู่มือหรือหน้าตั้งค่า.
- แอป POS ถูกล็อกให้ต่อได้เฉพาะ `27.254.143.219` ผ่าน Tauri http scope
  (`src-tauri/capabilities/default.json`) และ CSP ใน `tauri.conf.json` — ถ้าย้าย host หรือ
  เปิดโดเมนใหม่ ต้องแก้สองไฟล์นั้นแล้ว build ใหม่ ไม่ใช่แค่พิมพ์ URL ใหม่ในหน้าตั้งค่า.
- `/api/pos/ping` คืน `branch_id` ของ **อุปกรณ์** ก่อนของ user เสมอ ให้ตรงกับ
  `PosController::enforcedBranchId()` ที่ `/products`, `/shift`, `/checkout` ใช้จริง.
- สถานะ "เชื่อม ERP ไม่ได้" บนหน้าจอ POS ตัดสินจากผล `ping` เท่านั้น ความล้มเหลวหลังจากนั้น
  (โหลดสินค้า/เขียน SQLite/ส่งคิว) ขึ้นเป็น "ซิงก์ข้อมูลไม่ครบ" อย่าเอาสองอย่างนี้มารวมกันอีก.
- ก่อนมี Token Windows อาจต่ออินเทอร์เน็ตได้ แต่ยังไม่ถือว่าเชื่อม ERP สำเร็จ.
- POS ต้องเขียนบิลลง SQLite ก่อนพิมพ์ แล้วค่อยส่ง `checkout_queue` เมื่อออนไลน์ โดยใช้ idempotency key.
- ข้อมูลสินค้าไม่ขึ้นบน POS หมายถึง catalog sync ล้มเหลว; ให้ดูข้อความ error และกดโหลดสินค้าใหม่.
- POS รองรับโหมดเลือกพนักงานตามอุปกรณ์ในช่วงทดสอบ แต่ก่อนเปิดใช้งานจริงต้องทบทวน PIN/สิทธิ์และการยืนยันตัวตน.

## ขอบเขต Legacy และการนำเข้าข้อมูล

- ไม่ import ประวัติ POS legacy เข้าระบบใหม่ เพราะสาขา, ชื่อ, terminal และเลขเอกสารเดิมเปลี่ยนแล้ว และเสี่ยงยอดซ้ำ.
- Legacy ใช้เป็นแหล่งอ้างอิง/กระทบยอดเท่านั้น.
- นำเข้าเฉพาะ master data ที่ mapping ผ่านแล้ว โดยไม่ทับ stock snapshot ของ ERP ใหม่.
- นโยบายเต็ม: `docs/architecture/LEGACY_TABLE_SCOPE_POLICY.md`

## สถานะ commit ล่าสุดที่ต้องรู้

- `5b577fc` Add shared AI project memory and handoff protocol
- `645b916` Stop blaming the network for POS sync failures
- `1f296c2` Write the POS catalog in one transaction
- `4f41b05` Clean up the ERP address entered on POS setup
- `2b1dfa9` Report the device branch from the POS ping
- `318b17b` Clarify POS ERP connection status

`2b1dfa9..645b916` ยัง **ไม่ได้ deploy** — ฝั่ง ERP ต้อง deploy `PosApiController` และฝั่ง POS
ต้อง build 1.5.0 ผ่าน GitHub Actions ถึงจะมีผลกับสาขา (รายละเอียดใน `docs/ai/task.md`)

## ตรวจและปล่อยงาน

- Laravel: `php artisan test`
- Health: `php artisan erp:health`
- POS ที่แก้ TypeScript: `cd apps/pos-desktop && ./node_modules/.bin/vue-tsc --noEmit`
- `pnpm test` (vitest) **รันบน Mac เครื่องนี้ไม่ได้** — rollup native module คนละ Team ID กับ node
  ที่ยืมมา ทดสอบ logic ล้วนๆ ชั่วคราวได้ด้วย node 24 ที่ strip type เอง (import ไฟล์ `.ts` ตรงๆ
  ได้ถ้าโมดูลนั้นไม่ import ของ Tauri) แต่ต้องหาเครื่องรัน vitest ยืนยันอีกรอบ
- เครื่อง Mac นี้อาจ build Tauri Windows ไม่ได้; ให้ GitHub Actions เป็นตัวตรวจ installer Windows.
- ก่อน deploy: backup, dry-run, ตรวจ migration สำหรับ PostgreSQL และเช็ก production health หลัง deploy.

## รูปแบบส่งมอบงาน

เพิ่มหรืออัปเดต `docs/ai/task.md` ทุกครั้ง:

```markdown
## Handoff - YYYY-MM-DD
- Commit: `<hash>`
- ทำอะไร: ...
- ทดสอบ: ...
- ยังไม่ทดสอบ/ความเสี่ยง: ...
- Deploy: ยังไม่ deploy / deploy แล้วพร้อมผลตรวจ
- งานถัดไป: ...
```
