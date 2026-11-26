งาน: ปิดช่องโหว่และทำให้ POPSTAR ERP โหลดเร็วขึ้น (branch `antigravity/system-hardening`)

repo: `https://github.com/jetsada-polsan/ERPPOP` (Laravel 13; production ใช้ PostgreSQL + PHP 8.3; เทสต์ใช้ SQLite in-memory)
ผลตรวจทั้งระบบของ Claude อยู่ใน `docs/ai/claude-review.md` บน branch `origin/claude/system-review-2026-11-26` ซึ่งเป็น branch เดียวกับไฟล์นี้

## ก่อนเริ่ม
1. ถ้าเครื่องนี้ยังไม่มี repo ให้ `git clone https://github.com/jetsada-polsan/ERPPOP.git` แล้วเข้าไปในโฟลเดอร์ ถ้ามีอยู่แล้ว `git status` ต้องสะอาด
2. เครื่องต้องมี:
   - PHP 8.3 ขึ้นไป พร้อม `pdo_sqlite`, `mbstring`, `intl` และ `openssl`
   - Composer และ Node 20 ขึ้นไป
   - สิทธิ์ push ไปยัง GitHub repo นี้

   ถ้าขาดอย่างใดอย่างหนึ่งให้หยุดแล้วบอกเจ้าของ
3. `git fetch origin` แล้วตรวจว่า PR #147 merge เข้า main แล้ว:
   `git show origin/main:.github/workflows/production-check.yml | grep -c "Trust the pinned server host key"` ต้องได้ 1 ขึ้นไป
   ถ้าได้ 0 ให้หยุดแล้วบอกเจ้าของให้ merge PR #146 และ #147 ก่อน เพราะ PR #147 แก้ `docs/OPERATIONS.md` และ `docs/ai/task.md` เหมือนงานนี้
4. `git checkout -b antigravity/system-hardening origin/main` แล้วตามด้วย `git merge --no-edit origin/claude/system-review-2026-11-26`
5. อ่าน `AGENTS.md`, `docs/ai/WORKFLOW.md`, `docs/ai/PROJECT_MEMORY.md`, หัว `docs/ai/task.md` และ `docs/ai/claude-review.md`
6. `composer install`
7. รัน `php artisan test` ก่อนแก้และจดผลไว้ ถ้าเป็น Windows จะแดง 6 ตัว (ดูงานที่ 1) ถ้าเป็น macOS หรือ Linux ควรเขียวทั้งหมด

## ห้ามทำ
- ห้าม deploy ห้ามกดหรือรัน workflow บน GitHub และห้ามแตะ server หรือ `.env` ของ production
- ห้าม push `main` ให้ push เฉพาะ branch นี้
- ห้ามแตะ `.github/workflows/` ยกเว้นบรรทัดเดียวในงานที่ 4
- ห้ามแตะ `apps/pos-*` และ `resources/views/pos/*` (เจ้าของเลิกทำ POS เองแล้ว)
- ห้ามแตะโฟลเดอร์หรือ repo อื่น เช่น `popstar` ซึ่งเป็นคนละโปรเจกต์
- ห้ามตั้ง พิมพ์ หรือ commit รหัสผ่าน PIN token หรือ secret ใดๆ
- ห้ามทำให้เทสต์ผ่านด้วยการลดสิ่งที่เทสต์ตรวจ
- migration (ถ้ามี) ต้องรันได้ทั้ง SQLite และ PostgreSQL
- ติดเรื่องที่ต้องให้เจ้าของตัดสินใจ ให้หยุดถาม อย่าเดา

## งานที่ 1: ทำให้เทสต์ผ่านบน Windows (commit แยก)
เทสต์ 6 ตัวแดงบน Windows แต่ผ่านบน Linux CI มีสองสาเหตุ:
- เอา `resource_path('views/...')` ซึ่งใช้ `/` ไปเทียบกับ path จาก filesystem ซึ่งใช้ `\` รายการยกเว้นจึงไม่ทำงาน
- ใช้ `chmod(0000)` แต่ Windows ไม่บล็อกการอ่าน

เทสต์ที่แดง:
- `tests/Feature/ConfirmPopupConsistencyTest.php` (2 ตัว)
- `tests/Feature/ErpStructuralGapsTest.php::test_gap_approval_requests_are_not_wired_into_any_business_flow`
- `tests/Feature/LegacyPosImportQuarantineTest.php`
- `tests/Feature/PrintablePageSizeTest.php`
- `tests/Feature/FinanceSecurityControlTest.php::test_operations_page_stays_available_when_a_backup_checksum_is_unreadable`

วิธีแก้:
- แปลง path ทั้งสองฝั่งให้ใช้ `/` ก่อนเทียบ (`str_replace('\\', '/', ...)`) ทั้งตอนเทียบและตอนแสดงชื่อไฟล์ เช่น path ที่ตัดเป็น `app/...`
- เทสต์ chmod ให้ `markTestSkipped` เฉพาะเมื่อ `PHP_OS_FAMILY === 'Windows'`

ถ้าเครื่องนี้ไม่ใช่ Windows ให้แก้แบบเดียวกัน แต่ระบุในรายงานว่ายังไม่ได้ยืนยันบน Windows

เสร็จเมื่อ:
- `php artisan test` เขียวทั้งหมด
- เทสต์ยังจับของผิดได้: ลองเพิ่ม `alert('x')` ลงใน view ที่ไม่ใช่ layout ชั่วคราว ต้องแดง แล้วลบออก

## งานที่ 2: อัปเดต library ที่มีช่องโหว่ (commit แยก)
`composer audit --locked` เจอ 37 advisory ใน 9 แพ็กเกจ และ `composer.lock` ไม่ได้อัปเดตตั้งแต่ ก.ค. 2026:
- `laravel/framework` v13.17.0: critical CVE-2026-114509 (TokenGuard authentication bypass) และอีก 3 รายการ
- `guzzlehttp/guzzle` 7.13.1: high CVE-2026-69246 และ medium อีก 5
- `league/commonmark` 2.8.2: high หลายรายการ
- `symfony/http-foundation`, `http-kernel`, `routing`, `mailer` และ `mime` (v7.4.13–14) รวมกับ `league/flysystem` 3.35.1

ขั้นตอน:
1. เพิ่ม `"platform": {"php": "8.3.0"}` ใต้ `config` ใน `composer.json` เพื่อกันไม่ให้ดึงแพ็กเกจที่ต้องใช้ PHP 8.4+ เพราะ server เป็น 8.3
2. รัน `composer update --with-all-dependencies` โดยไม่แก้ constraint ใน `composer.json` และไม่ข้าม major version
3. `composer audit --locked` ต้องเหลือ 0 ถ้ายังเหลือ ให้อธิบายทีละตัวในรายงานว่าทำไมแก้ไม่ได้
4. `php artisan test` ต้องเขียวทั้งหมด ถ้ามีตัวแดงหลังอัปเดต ให้แก้โค้ดให้เข้ากับเวอร์ชันใหม่ ห้ามถอยเวอร์ชันหนีเงียบๆ

## งานที่ 3: security headers และ cache ไฟล์ static (commit แยก)
ตรวจ production จากภายนอกแล้วพบว่าไม่มี `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` และ `Strict-Transport-Security`
ไฟล์ CSS/JS ประมาณ 630 KB ไม่ถูก gzip และไม่มี `Cache-Control` เบราว์เซอร์จึงต้องถาม server ซ้ำทุกหน้า

3.1 สร้าง `app/Http/Middleware/SecurityHeaders.php` แล้วต่อท้ายกลุ่ม `web` ใน `bootstrap/app.php` แบบเดียวกับ `ErpAuthorize` โดยใส่:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN` (iframe ในระบบอยู่ในโดเมนเดียวกันทั้งหมด)
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000` เฉพาะเมื่อ `$request->isSecure()` และห้ามใส่ `includeSubDomains`

ข้อห้ามของ middleware:
- ห้ามเขียนทับ header ที่ response ตั้งไว้เอง
- รอบนี้ห้ามใส่ `Content-Security-Policy` หรือ `Permissions-Policy`

เทสต์:
- `GET /login` ต้องมี header ครบ และไม่มี HSTS เมื่อเป็น http
- ลองถอด middleware ออกชั่วคราว เทสต์ต้องแดง

3.2 view ที่เรียกไฟล์ CSS/JS ใน `public/` ผ่าน `asset()` แต่ไม่มี `?v=` มี 22 จุด (นอก `resources/views/pos/` ได้แก่ `layout.blade.php` 6 จุด, `wh/index` 2, `auth/mfa-challenge` 2, `reports/index`, `purchase-orders/index`, `documents/partials/entry-assets` และที่อื่น) ให้ทำดังนี้:
- สร้าง helper ตัวเดียว เช่น `App\Support\AssetVersion::url('vendor/x.css')` ที่คืน `asset(path).'?v='.filemtime` และไม่ throw ถ้าไฟล์ไม่มี
- เปลี่ยนทุกจุดให้ใช้ helper นี้ รวมถึงจุดเดิมที่เขียน `filemtime(public_path(...))` ซ้ำๆ

เทสต์: render หน้า login และหน้าที่ login แล้วหนึ่งหน้า ทุก `<link rel="stylesheet">` และ `<script src>` ที่ชี้ไฟล์ในโดเมนตัวเองต้องมี `?v=`

3.3 เพิ่มหัวข้อ "nginx: gzip และ cache ไฟล์ static" ใน `docs/OPERATIONS.md` ให้เจ้าของคัดไปใส่ server เอง (คุณห้ามแก้ server) เนื้อหามีดังนี้:
- `gzip_types text/css application/javascript application/json image/svg+xml;`, `gzip_vary on;` และ `gzip_min_length 1024;`
- block `location ~* \.(css|js|woff2?|svg|png|jpg|jpeg|ico)$ { expires 30d; add_header Cache-Control "public"; try_files $uri =404; }`
- `server_tokens off;`
- คำสั่ง `nginx -t && systemctl reload nginx`
- คำเตือนตัวหนาว่าเปิด `expires 30d` ได้ **หลัง** deploy งานข้อ 3.2 แล้วเท่านั้น ไม่งั้นผู้ใช้จะค้างอยู่กับ CSS/JS เก่า

## งานที่ 4: ให้ CI ทดสอบด้วย PHP เวอร์ชันเดียวกับ production (commit แยก)
ใน `.github/workflows/deploy.yml` job `test` เปลี่ยน `php-version: '8.5.10'` เป็น `'8.3'` แก้แค่บรรทัดนี้บรรทัดเดียว
ถ้าในโค้ดมี syntax ที่ใช้ได้เฉพาะ PHP 8.4 ขึ้นไป ให้แก้ให้ใช้ได้กับ 8.3

## ส่งงาน
1. ทุกข้อต่อไปนี้ต้องผ่าน:
   - `php artisan test` เขียวทั้งหมด
   - `node --test tests/js/` ผ่าน
   - `composer audit --locked` เป็น 0 หรืออธิบายตัวที่เหลือ
   - `vendor/bin/pint --test` บนไฟล์ PHP ที่แก้
2. push branch `antigravity/system-hardening` (ห้าม push `main`) แล้วเปิด PR เข้า `main` ถ้าเปิด PR เองไม่ได้ให้บอกเจ้าของพร้อมลิงก์ compare
3. เขียนรายงาน `docs/ai/antigravity-system-hardening.md` ให้มี:
   - ระบบปฏิบัติการของเครื่องที่ใช้ทำงาน
   - จำนวน advisory ก่อน/หลัง
   - เวอร์ชันแพ็กเกจที่เปลี่ยน
   - header ที่ได้
   - รายการไฟล์ที่ใส่ `?v=`
   - ผลเทสต์จริง
4. เขียน handoff ไว้หัว `docs/ai/task.md` ตามรูปแบบใน `docs/ai/WORKFLOW.md`
5. ห้าม deploy เจ้าของจะสั่ง deploy เอง
