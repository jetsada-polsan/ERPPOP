งานรอบ 2: แก้ PR #148 ให้พร้อม merge แล้วลด query ที่ทุกหน้าต้องจ่าย

repo: `https://github.com/jetsada-polsan/ERPPOP`
Claude ตรวจ PR #148 แล้ว ผลอยู่ใน `docs/ai/claude-review.md` บน branch `origin/claude/system-review-2026-11-26`
กติกาเดิมจาก `docs/ai/ANTIGRAVITY_SYSTEM_HARDENING_PROMPT.md` ยังใช้ทั้งหมด

## ห้ามทำ (เหมือนรอบแรก)
- ห้าม deploy ห้ามกดหรือรัน workflow บน GitHub และห้ามแตะ server หรือ `.env` ของ production
- ห้าม push `main`
- ห้ามแตะ `.github/workflows/`, `apps/pos-*` และ `resources/views/pos/*`
- ห้ามตั้ง พิมพ์ หรือ commit รหัสผ่าน PIN token หรือ secret
- ห้ามทำให้เทสต์ผ่านด้วยการลดสิ่งที่เทสต์ตรวจ
- migration (ถ้ามี) ต้องรันได้ทั้ง SQLite และ PostgreSQL
- ติดเรื่องที่ต้องให้เจ้าของตัดสินใจ ให้หยุดถาม อย่าเดา

## ก่อนเริ่ม
1. `git fetch origin` แล้ว `git checkout antigravity/system-hardening` และ `git pull`
2. `git merge --no-edit origin/claude/system-review-2026-11-26` เพื่อดึงผลตรวจรอบนี้และไฟล์พร็อมป์นี้เข้ามา
3. อ่าน `docs/ai/claude-review.md`
4. `composer install` แล้วรัน `php artisan test` จดผลไว้

## ส่วน A: แก้ PR #148 (ทำบน branch `antigravity/system-hardening` เดิม)

### A1. เอา Guzzle กลับมาเป็น 7.x (commit แยก)
`composer update` พา `guzzlehttp/guzzle` จาก 7.13.1 ไป 8.6.0 ซึ่งข้าม major version ทั้งที่พร็อมป์รอบแรกห้ามไว้
ตัวพ่วงก็ข้าม major ไปด้วย: `guzzlehttp/psr7` 2→3, `guzzlehttp/promises` 2→3 และ `guzzlehttp/uri-template` 1→2
Guzzle 7.17.0 ปิดช่องโหว่ทั้ง 6 รายการแล้ว (ช่วงที่โดนคือ `<7.15.2`) ระบบใช้ Guzzle ผ่าน `Http::` ในการยิง LINE (`AlertDispatchService`, `LineWebhookController`, `MemberPortalController`) เทสต์ใช้ `Http::fake()` จึงไม่ได้ทดสอบการยิงจริง

ขั้นตอน:
1. เพิ่ม `"guzzlehttp/guzzle": "^7.17"` ใน `require` ของ `composer.json`
2. `composer update guzzlehttp/guzzle guzzlehttp/psr7 guzzlehttp/promises guzzlehttp/uri-template --with-dependencies`
3. ต้องได้ guzzle 7.17.x, psr7 2.x, promises 2.x และ uri-template 1.x
4. `composer audit --locked` ต้องเป็น 0 และ `php artisan test` ต้องเขียวทั้งหมด

แพ็กเกจที่ข้าม major ตัวอื่น ได้แก่ `brick/math` 0.18→1.0 และ `hamcrest/hamcrest-php` 2→3 (ใช้เฉพาะตอน dev) คงไว้ได้ เพราะโค้ดเราไม่ได้เรียกตรง และ Laravel รองรับอยู่แล้ว ให้ยืนยันด้วย `grep -rn "Brick\\\\Math" app` ว่าไม่มีโค้ดเรียก แล้วเขียนเหตุผลไว้ในรายงาน

### A2. ให้ security headers ติดทุก response (commit แยก)
`SecurityHeaders` ถูกต่อท้ายกลุ่ม `web` หลัง `ErpAuthorize` จึงทำงานเฉพาะเมื่อ request ผ่านทุก middleware ก่อนหน้า
Claude ทดสอบแล้วว่า response เหล่านี้ไม่มี `X-Content-Type-Options` และ `X-Frame-Options`:
- `GET /dashboard` ตอนยังไม่ login (302 ไปหน้า login)
- URL ที่ไม่มีอยู่ (404)
- `/up`
- หน้า 403 ที่ `ErpAuthorize` abort

ขั้นตอน:
- ย้ายไปเป็น global middleware ใน `bootstrap/app.php` ด้วย `$middleware->append(SecurityHeaders::class)` และเอาออกจากกลุ่ม `web`
- พฤติกรรมเดิมทั้งหมดคงไว้ (ไม่เขียนทับ header ที่มีแล้ว และ HSTS เฉพาะ https)
- เพิ่มเทสต์ใน `tests/Feature/SecurityHeadersAndAssetVersioningTest.php` ให้ครบทั้ง 302 guest redirect, 404, `/up`, 403 (user ที่ไม่มีสิทธิ์เปิดเมนู) และ response JSON ของ `api/*` อย่างน้อยหนึ่ง route
- mutation check: ย้ายกลับไปไว้ท้ายกลุ่ม `web` ชั่วคราว เทสต์ใหม่ต้องแดง แล้วคืนกลับ

### A3. ส่งงานส่วน A
- อัปเดต `docs/ai/antigravity-system-hardening.md` ทั้งตารางแพ็กเกจและหัวข้อ header
- push branch เดิมเพื่ออัปเดต PR #148 แล้วบอกเจ้าของว่า **PR #148 พร้อม merge**

## ส่วน B: ลด query ที่ layout ยิงทุกหน้า (branch ใหม่ `antigravity/fewer-queries-per-page`)
แตก branch ใหม่ต่อจาก `antigravity/system-hardening` หลังทำส่วน A เสร็จ แล้วเปิด PR เข้า `main` โดยเขียนในคำอธิบายว่าต่อจาก #148 และให้ merge #148 ก่อน

Claude วัดด้วย cache แบบ database (ค่าเดียวกับ `.env.example` ที่ production น่าจะใช้) โดยให้ admin เปิดหน้าละ 1 ครั้งหลัง warm-up:

| หน้า | queries | ไม่ซ้ำ |
|---|---|---|
| `/dashboard` | 31 | 14 |
| `/bookings` | 29 | 13 |
| `/products` | 27 | 11 |
| `/reports` | 31 | 10 |
| `/settings` | 34 | 8 |
| `/customers` | 26 | 10 |

ตัวซ้ำหลักบน `/dashboard`:
- `select * from "cache" where "key" in (?)` **13 ครั้ง**: `AppSetting::get()` เรียก `Cache::rememberForever()` ใหม่ทุกครั้ง ทั้ง layout และ `ErpMenu::forUser()` เรียกหลายสิบครั้งต่อหน้า และแต่ละครั้งต้อง unserialize ค่า settings ทั้งก้อน
- `select exists(... roles ... permissions ...)` **5 ครั้ง**: `User::hasGlobalPermission()` ยิง query ทุกครั้ง ไม่มี memo (`hasPermission('system.admin')` เรียกมันสองครั้งต่อการเรียก)

### B1. memo ค่า settings ต่อ request (commit แยก)
- ใน `App\Models\AppSetting` ให้โหลด settings ทั้งก้อนครั้งเดียวต่อ request แล้วเก็บไว้ใน `request()->attributes` (แบบเดียวกับ `MenuVisibility::hidden()`)
- อย่าใช้ static property ล้วน เพราะจะค้างข้ามเทสต์
- `AppSetting::set()` ต้องล้างทั้ง cache และ memo
- ตรวจทุกจุดที่เขียนตาราง `app_settings` ตรงโดยไม่ผ่าน `set()` (เช่น `PurgeLegacyMasters` ที่ใช้ `DB::table('app_settings')->delete()`) ให้ล้าง cache และ memo ด้วย
- ห้ามเปลี่ยนค่าที่ `get()`, `company()` และ `logoUrl()` คืน

### B2. memo สิทธิ์ global ต่อ user instance (commit แยก)
- `User::hasGlobalPermission()` ให้โหลดรายการ code ของ role แบบ global ครั้งเดียวต่อ instance แบบเดียวกับ `permissionCodes()` ที่ใช้ `??=` อยู่แล้ว แล้วเช็กจาก array นั้น
- ผลต้องเหมือนเดิมทุกกรณี: role ปกติเท่านั้น ไม่รวม branch role (ดู `activeBranchRoles()`)
- ไล่ดูเทสต์ที่เปลี่ยน role ของ user กลางเทสต์แล้วใช้ instance เดิมต่อ ถ้ามี ให้เทสต์นั้นใช้ `$user->fresh()` แทนการถอด memo ออก

### B3. เทสต์กันถอยหลัง (commit แยก)
เพิ่ม `tests/Feature/LayoutQueryBudgetTest.php`:
- ใน `setUp` ตั้ง `config(['cache.default' => 'database'])` เพื่อให้นับ query ของ cache ได้ (phpunit ใช้ `array`)
- ให้ admin เปิด `/dashboard` และ `/settings` (warm-up 1 ครั้งก่อน) แล้วนับด้วย `DB::listen`:
  - query ที่อ่าน `cache` key `app_settings.all` ได้ไม่เกิน 1 ครั้งต่อ request
  - query `exists` ของ roles/permissions ได้ไม่เกิน 1 ครั้งต่อ request
  - จำนวน query รวมต้องไม่เกินค่าที่วัดได้หลังแก้ บวกเผื่อ 3
- mutation check: ถอด memo ใน B1 ออกชั่วคราว เทสต์ต้องแดง แล้วคืนกลับ

### B4. ส่งงานส่วน B
1. ทุกข้อต่อไปนี้ต้องผ่าน:
   - `php artisan test` เขียวทั้งหมด
   - `node --test tests/js/` ผ่าน
   - `composer audit --locked` เป็น 0
   - `vendor/bin/pint --test` บนไฟล์ PHP ที่แก้
2. รายงาน `docs/ai/antigravity-fewer-queries-per-page.md` มีตารางก่อน/หลังของทั้ง 6 หน้าข้างบน (วัดแบบ cache database เหมือนกัน)
3. เขียน handoff ไว้หัว `docs/ai/task.md` ตามรูปแบบใน `docs/ai/WORKFLOW.md`
4. push branch `antigravity/fewer-queries-per-page` แล้วเปิด PR เข้า `main`
5. ห้าม deploy เจ้าของจะสั่ง deploy เอง
