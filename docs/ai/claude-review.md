# ผลตรวจ — 2026-11-26 (ตรวจทั้งระบบหลัง deploy #423)

## ช่วง commit ที่ตรวจ

ทั้ง repo ที่ `55cf0e14` (main หลัง merge #145 และ #144) และ production `https://erp.popstarcenter.com` ตรวจจากภายนอก (ไม่ได้ login)

## สรุป

ผ่าน — ระบบใช้งานได้ ไม่พบเรื่องที่ทำให้ข้อมูลเสียหรือเปิดช่องให้เข้าระบบโดยไม่ login
มีเรื่อง **ควรแก้** ด้านความปลอดภัย (library มีช่องโหว่ 37 รายการ, ไม่มี security headers, session cookie ไม่ Secure)
และด้านความเร็ว (CSS/JS ไม่ถูก gzip และไม่มี cache header)

## สิ่งที่รันจริง

- `php -d memory_limit=2G vendor/bin/phpunit` (Windows, PHP 8.5.12, SQLite): 1,183 tests, 10,642 assertions,
  **6 failures**, 7 skipped/incomplete, 243 วินาที. ทั้ง 6 ตัวเป็นปัญหาเฉพาะ Windows (ข้อ 5);
  บน Linux job `test` ของ Deploy ERP #423 ผ่าน
- `node --test tests/js/`: 5/5 ผ่าน
- `composer audit --locked`: 37 advisories ใน 9 แพ็กเกจ (ข้อ 1)
- `curl` production: header, gzip, cache ของหน้าและไฟล์ static, ไฟล์ที่ไม่ควรเปิดได้

## สิ่งที่พบ

### 1. library ที่ล็อกไว้มีช่องโหว่ 37 รายการ — ควรแก้ (ทำก่อนข้ออื่น)
- ไฟล์: `composer.lock` (แก้ล่าสุด `b80c5c31` 2026-07-18)
- อาการ:
  - `laravel/framework` v13.17.0: critical CVE-2026-114509 (TokenGuard authentication bypass) และอีก 3 รายการ
  - `guzzlehttp/guzzle` 7.13.1: high CVE-2026-69246 และ medium อีก 5
  - `league/commonmark` 2.8.2: high หลายรายการ (DoS, XSS)
  - `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/mailer` และ `symfony/mime` (v7.4.13–14) รวมกับ `league/flysystem` 3.35.1 อีก 15 รายการ
- เสนอ: เพิ่ม `config.platform.php = "8.3.0"` ใน `composer.json` (server เป็น 8.3 แต่เครื่อง dev เป็น 8.5)
  แล้วรัน `composer update --with-all-dependencies` โดยไม่ข้าม major version และรันเทสต์ทั้งชุด

### 2. ไม่มี security headers — ควรแก้
- อาการ: `/login` ไม่ส่ง `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` และ `Strict-Transport-Security`
  และ `Server: nginx/1.24.0 (Ubuntu)` บอกเวอร์ชัน
- เสนอ: เพิ่ม middleware ต่อท้ายกลุ่ม `web` (แบบเดียวกับ `ErpAuthorize` ใน `bootstrap/app.php`)
  - iframe ในระบบอยู่ในโดเมนเดียวกันทั้งหมด (products, OCR) จึงใช้ `SAMEORIGIN` ได้
  - ส่วน `server_tokens off` เป็นค่าใน nginx

### 3. session cookie ไม่มี Secure flag — ควรแก้ (เจ้าของแก้บน server)
- อาการ: `popstar-erp-session` และ `XSRF-TOKEN` ไม่มี `secure` เพราะ `config/session.php` ใช้ค่า default `SESSION_SECURE_COOKIE=false`
- เสนอ: ใน `.env` ของ production ตั้ง `SESSION_SECURE_COOKIE=true` แล้วรัน `php artisan config:cache`
  - เว็บ redirect http → https อยู่แล้ว จึงไม่กระทบการ login

### 4. CSS/JS ไม่ถูก gzip และไม่มี cache header — ควรแก้ (ความเร็ว)
- อาการ: หน้า HTML ถูก gzip แล้ว แต่ไฟล์ static ส่งแบบไม่บีบอัด และไม่มี `Cache-Control`/`Expires`
  - ขนาด: `adminlte.min.css` 340 KB, `bootstrap-icons.min.css` 87 KB, `sweetalert2.all.min.js` 79 KB, `css/erp-layout.css` 77 KB, `alpine.min.js` 46 KB (รวม ~630 KB ต่อการเข้าครั้งแรก)
  - เบราว์เซอร์ต้องถาม server ซ้ำทุกหน้า
- ต้นเหตุฝั่งโค้ด: ไฟล์ CSS/JS 22 จุดเรียกผ่าน `asset()` โดยไม่มี `?v=` จึงเปิด cache ยาวไม่ได้ (หลัง deploy ผู้ใช้จะได้ไฟล์เก่า)
  - `layout.blade.php` 6 จุด, `pos/index` 5, `wh/index` 2, `auth/mfa-challenge` 2 และที่อื่น
- เสนอ: ใส่ `?v=` ให้ครบก่อน แล้วเจ้าของเปิด `gzip_types` และ `expires` ใน nginx **หลัง** deploy โค้ดนั้น

### 5. เทสต์ 6 ตัวล้มบน Windows — ควรแก้
- ไฟล์:
  - `tests/Feature/ConfirmPopupConsistencyTest.php` (2 ตัว)
  - `tests/Feature/ErpStructuralGapsTest.php`
  - `tests/Feature/LegacyPosImportQuarantineTest.php`
  - `tests/Feature/PrintablePageSizeTest.php`
  - `tests/Feature/FinanceSecurityControlTest.php:160`
- อาการ: เทสต์เอา `resource_path('views/layout.blade.php')` (ใช้ `/`) ไปเทียบกับ path จาก filesystem ที่ใช้ `\`
  - รายการยกเว้นจึงไม่ทำงาน และไฟล์ที่ได้รับอนุญาตถูกนับว่าผิด
  - ตัวสุดท้ายใช้ `chmod(0000)` ซึ่ง Windows ไม่บล็อกการอ่าน
  - agent ที่รันบน Windows จะเห็นแดงแล้วอาจไปแก้โค้ดผิดจุด
- เสนอ: normalize path ทั้งสองฝั่งเป็น `/` ก่อนเทียบ และให้เทสต์ chmod ข้ามเฉพาะบน Windows

### 6. CI ทดสอบด้วย PHP 8.5.10 แต่ production เป็น 8.3 — ควรแก้
- ไฟล์: `.github/workflows/deploy.yml:24`
- อาการ: syntax ของ PHP 8.4+ จะผ่าน gate ของ deploy แต่ไปพังบน server
  - `postgres-uat.yml` ใช้ 8.3 อยู่แล้ว แต่ยังแดงจนกว่า PR #146 จะ merge
- เสนอ: เปลี่ยนเป็น `'8.3'`

### 7. backup อยู่บน server เครื่องเดียวกับฐานข้อมูล — ควรแก้ (เจ้าของ)
- อาการ: `erp:backup` เขียนลง `storage/app/backups` บน server เดียวกัน ถ้าเครื่องเสียจะเสียทั้งข้อมูลและ backup
  - `docs/ai/backup-dr-uat.md` บันทึกว่ารอบ Google Drive (`rclone crypt`) และการกู้ `jeterp_dr` ยังไม่ได้ทำ
- เสนอ: ทำตาม `docs/BACKUP-DR-RUNBOOK.md` ให้ครบ แล้วจึงติดตั้ง cron

### 8. workflow ที่แก้หรือล้างข้อมูล production กดได้คลิกเดียว — ข้อสังเกต
- ไฟล์: `erp-reset-transactions.yml` (ถ้าเลือก `apply` จะล้างรายการธุรกรรมทั้งหมดหลัง backup), `void-pos-test-sales.yml`, `uat-receive-all-products.yml`, `add-large-unit-stock.yml`
- อาการ: environment `production` ยังไม่ตั้ง required reviewer ใครก็ตามที่มีสิทธิ์ write กดได้ทันที
- เสนอ: เจ้าของตั้ง required reviewer ใน GitHub และพิจารณาลบ workflow สมัย UAT ที่ไม่ใช้แล้ว

### 9. งานค้าง — ข้อสังเกต
- PR #146 (ทำให้ `postgres-uat` เขียว) รอ merge
- branch `fix/pin-host-keys` (เลิกใช้ `ssh-keyscan` ใน workflow อีก 9 ไฟล์) ทำเสร็จแล้วแต่ยังไม่ได้ push
- opcache, `CACHE_STORE`/`SESSION_DRIVER` และ query ช้าบน PostgreSQL ยังไม่ได้ยืนยัน เพราะดูจากภายนอกไม่ได้

## ที่ตรวจแล้วไม่มีปัญหา

- ทุก route ฝั่ง web ผ่าน `ErpAuthorize`: guest ถูกส่งไปหน้า login และตรวจสิทธิ์ตามเมนู ส่วน public route มี 12 ชื่อตามที่ระบุไว้
- route ของ `api.php` แต่ละกลุ่มมี guard:
  - `pos.device`, `member.portal` และ `auth`
  - `throttle` ที่ `pair` และ `bplus/sync`
  - ตรวจ signature ที่ LINE webhook
- login จำกัด 5 ครั้งต่อนาที (`RateLimiter`) และมี MFA
- LINE webhook ตรวจ `x-line-signature` ด้วย `hash_equals`
- raw SQL ที่ประกอบจากตัวแปรใช้ค่าภายในทั้งหมด ไม่รับ input ผู้ใช้:
  - `ReportController::branchByLocationSub`
  - `REPLACE()` ใน `BplusDocumentMaterializer`
- `{!! !!}` 6 จุดส่ง SVG/HTML ที่ระบบสร้างเอง
- production:
  - หน้า 404 ไม่โชว์ debug
  - `.env` และ `.git/config` ได้ 403, `storage/logs/laravel.log` และ `composer.lock` ได้ 404
  - http redirect ไป https (301) และ HTML ถูก gzip
  - TTFB ของ `/login` ประมาณ 0.29 วินาที
- `composer.lock` ไม่มีแพ็กเกจที่ต้องใช้ PHP 8.4+ (`platform.php = ^8.3`)

## ยังไม่ได้ดู

- เวลาโหลดหน้าจริงหลัง login (Chrome ไม่ได้ login ไว้ และไม่กรอกรหัสผ่านแทน)
- `pg_stat_statements`, opcache และ log บน server (เครื่องนี้ไม่มี SSH key)
- logic บัญชี สต๊อก และราคาเชิงลึก (รอบนี้ไม่ได้ไล่)
