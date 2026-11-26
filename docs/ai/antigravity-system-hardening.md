# รายงานงานปิดช่องโหว่และเพิ่มความเร็ว POPSTAR ERP (`antigravity/system-hardening`)

วันที่: 2026-11-26
Branch: `antigravity/system-hardening` (แตกจาก `origin/main` ที่ `1e780b1f` หลัง merge PR #146 และ PR #147, และ merge `origin/claude/system-review-2026-11-26` ที่ `93532500`)

## สภาพแวดล้อมเครื่องที่ใช้ทำงาน

- **ระบบปฏิบัติการ**: Windows (`Microsoft Windows NT 10.0.26300.0`)
- **PHP**: `8.4.27` (`C:\php84\php.exe`, มี `pdo_sqlite`, `mbstring`, `intl`, `openssl` ครบ) — หมายเหตุ: `php` ตัวเริ่มต้นใน PATH เป็น herd-lite `8.5.12` ซึ่งไม่มี `intl` จึงใช้ `C:\php84\php.exe` ตลอดการทำงาน
- **Composer**: `2.8.12`
- **Node.js**: `v26.13.1`
- **ฐานข้อมูลเทสต์**: SQLite in-memory (ตาม `phpunit.xml`)

## สรุป Commit แยกตามงาน

| งาน | Commit | สรุป |
|---|---|---|
| งานที่ 1 | `8ab5f71a` | แก้ path separator และ `chmod(0000)` บน Windows ในเทสต์ 5 ไฟล์ |
| งานที่ 2 | `eb10592f` | เพิ่ม `config.platform.php = "8.3.0"` และอัปเดตแพ็กเกจทั้งหมดด้วย `composer update --with-all-dependencies` |
| งานที่ 3 | `225ee81e` | เพิ่ม `SecurityHeaders` middleware, `AssetVersion::url()`, ใส่ `?v=` ใน view นอก `pos/` และเพิ่มคู่มือ nginx ใน `docs/OPERATIONS.md` |
| งานที่ 4 | `b3aa4c4d` | แก้บรรทัดเดียวใน `.github/workflows/deploy.yml` (`php-version: '8.5.10'` → `'8.3'`) |

*(ไม่ได้แตะ `apps/pos-*`, `resources/views/pos/*`, `.github/workflows/*` อื่นๆ หรือเซิร์ฟเวอร์ production และยังไม่ได้ deploy)*

---

## งานที่ 2: ผล `composer audit --locked` ก่อน/หลัง และเวอร์ชันแพ็กเกจที่เปลี่ยน

### จำนวน Advisory ก่อน/หลัง

- **ก่อนอัปเดต**: **37 advisories** ใน **9 แพ็กเกจ** (`laravel/framework`, `guzzlehttp/guzzle`, `league/commonmark`, `league/flysystem`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/mailer`, `symfony/mime`)
- **หลังอัปเดต**: **0 advisories** (`No security vulnerability advisories found.`, exit code `0`)
- **หมายเหตุการ pin PHP platform**: เพิ่ม `"platform": { "php": "8.3.0" }` ใต้ `config` ใน `composer.json` โดยไม่แก้ constraint ของแพ็กเกจใดๆ ใน `require` / `require-dev`

### แพ็กเกจที่เคยมี Advisory (9 แพ็กเกจ)

| แพ็กเกจ | เวอร์ชันเดิม | เวอร์ชันใหม่ | หมายเหตุ |
|---|---|---|---|
| `laravel/framework` | `v13.17.0` | `v13.44.1` | ปิด critical CVE-2026-114509 และอีก 3 รายการ |
| `guzzlehttp/guzzle` | `7.13.1` | `8.6.0` | อัปเดตแบบ transitive ภายใต้ constraint ของ `laravel/framework` (ปิด high CVE-2026-69246 และ medium อีก 5) |
| `league/commonmark` | `2.8.2` | `2.10.9` | ปิด high DoS/XSS หลายรายการ |
| `league/flysystem` | `3.35.1` | `3.36.0` | ปิด advisory |
| `symfony/http-foundation` | `v7.4.14` | `v7.4.24` | ปิด advisory |
| `symfony/http-kernel` | `v7.4.14` | `v7.4.24` | ปิด advisory |
| `symfony/routing` | `v7.4.13` | `v7.4.24` | ปิด advisory |
| `symfony/mailer` | `v7.4.14` | `v7.4.24` | ปิด advisory |
| `symfony/mime` | `v7.4.13` | `v7.4.24` | ปิด advisory |

### รายการแพ็กเกจทั้งหมดที่เปลี่ยนใน `composer.lock` (อัปเกรด 75, เพิ่ม 2, ลบ 1)

| แพ็กเกจ | เดิม | ใหม่ | Scope |
|---|---|---|---|
| `brick/math` | `0.18.0` | `1.0.0` | prod |
| `carbonphp/carbon-doctrine-types` | `3.2.0` | `3.2.1` | prod |
| `doctrine/inflector` | `2.1.0` | `2.1.1` | prod |
| `doctrine/lexer` | `3.0.1` | `3.0.3` | prod |
| `dragonmantank/cron-expression` | `v3.6.0` | `v3.6.1` | prod |
| `egulias/email-validator` | `4.0.4` | `4.0.5` | prod |
| `filp/whoops` | `2.18.4` | `2.19.3` | dev |
| `graham-campbell/result-type` | `v1.1.4` | `v1.2.0` | prod |
| `guzzlehttp/guzzle` | `7.13.1` | `8.6.0` | prod |
| `guzzlehttp/promises` | `2.5.0` | `3.2.0` | prod |
| `guzzlehttp/psr7` | `2.12.3` | `3.6.0` | prod |
| `guzzlehttp/uri-template` | `v1.0.8` | `v2.0.1` | prod |
| `hamcrest/hamcrest-php` | `v2.1.1` | `v3.0.0` | dev |
| `laravel/agent-detector` | `v2.0.2` | `v2.0.5` | dev |
| `laravel/framework` | `v13.17.0` | `v13.44.1` | prod |
| `laravel/pail` | `v1.2.7` | `v1.3.0` | dev |
| `laravel/pao` | `v1.1.2` | `v1.1.6` | dev |
| `laravel/pint` | `v1.29.3` | `v1.32.3` | dev |
| `laravel/prompts` | `v0.3.21` | `v0.3.28` | prod |
| `laravel/serializable-closure` | `v2.0.13` | `v2.2.0` | prod |
| `laravel/tinker` | `v3.0.2` | `v3.2.0` | prod |
| `league/commonmark` | `2.8.2` | `2.10.9` | prod |
| `league/flysystem` | `3.35.1` | `3.36.0` | prod |
| `league/flysystem-local` | `3.31.0` | `3.35.3` | prod |
| `league/mime-type-detection` | `1.16.0` | `1.17.0` | prod |
| `league/uri` | `7.8.1` | `7.10.0` | prod |
| `league/uri-interfaces` | `7.8.1` | `7.10.0` | prod |
| `mockery/mockery` | `1.6.12` | `1.6.15` | dev |
| `monolog/monolog` | `3.10.0` | `3.13.0` | prod |
| `myclabs/deep-copy` | `1.13.4` | `1.14.0` | dev |
| `nesbot/carbon` | `3.13.0` | `3.15.1` | prod |
| `nette/schema` | `v1.3.5` | `v1.4.0` | prod |
| `nette/utils` | `v4.1.4` | `v4.1.6` | prod |
| `nikic/php-parser` | `v5.7.0` | `v5.9.0` | prod |
| `nunomaduro/collision` | `v8.9.4` | `v8.9.6` | dev |
| `nunomaduro/termwind` | `v2.4.0` | `v2.4.1` | prod |
| `phpoption/phpoption` | `1.9.5` | `1.10.0` | prod |
| `phpunit/php-code-coverage` | `12.5.7` | `12.5.8` | dev |
| `phpunit/php-file-iterator` | `6.0.1` | `6.0.2` | dev |
| `phpunit/php-text-template` | `5.0.0` | `5.0.1` | dev |
| `phpunit/phpunit` | `12.5.30` | `12.5.45` | dev |
| `ramsey/uuid` | `4.9.3` | `4.10.0` | prod |
| `sebastian/diff` | `7.0.0` | `7.0.1` | dev |
| `sebastian/version` | `6.0.0` | `6.0.2` | dev |
| `symfony/clock` | `v7.4.8` | `v7.4.21` | prod |
| `symfony/console` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/css-selector` | `v7.4.9` | `v7.4.22` | prod |
| `symfony/deprecation-contracts` | `v3.7.1` | `v3.8.1` | prod |
| `symfony/error-handler` | `v7.4.14` | `v7.4.22` | prod |
| `symfony/event-dispatcher` | `v7.4.14` | `v7.4.17` | prod |
| `symfony/event-dispatcher-contracts` | `v3.7.1` | `v3.8.1` | prod |
| `symfony/finder` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/http-foundation` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/http-kernel` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/mailer` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/mime` | `v7.4.13` | `v7.4.24` | prod |
| `symfony/polyfill-intl-grapheme` | `v1.38.1` | `v1.43.3` | prod |
| `symfony/polyfill-intl-idn` | `v1.38.1` | `v1.43.0` | prod |
| `symfony/polyfill-intl-normalizer` | `v1.38.0` | `v1.43.0` | prod |
| `symfony/polyfill-mbstring` | `v1.38.2` | `v1.43.3` | prod |
| `symfony/polyfill-php80` | `v1.37.0` | `v1.43.0` | prod |
| `symfony/polyfill-php82` | *(เพิ่มใหม่)* | `v1.43.0` | prod |
| `symfony/polyfill-php83` | `v1.38.2` | `v1.43.0` | prod |
| `symfony/polyfill-php84` | `v1.38.1` | `v1.43.0` | prod |
| `symfony/polyfill-php85` | `v1.38.1` | `v1.44.0` | prod |
| `symfony/polyfill-php86` | `v1.38.0` | `v1.43.0` | prod |
| `symfony/polyfill-time` | *(เพิ่มใหม่)* | `v1.42.0` | prod |
| `symfony/polyfill-uuid` | `v1.37.0` | `v1.43.0` | prod |
| `symfony/process` | `v7.4.13` | `v7.4.24` | prod |
| `symfony/routing` | `v7.4.13` | `v7.4.24` | prod |
| `symfony/service-contracts` | `v3.7.1` | `v3.8.1` | prod |
| `symfony/string` | `v7.4.13` | `v7.4.24` | prod |
| `symfony/translation` | `v7.4.14` | `v7.4.24` | prod |
| `symfony/translation-contracts` | `v3.7.1` | `v3.8.1` | prod |
| `symfony/uid` | `v7.4.9` | `v7.4.21` | prod |
| `symfony/var-dumper` | `v7.4.14` | `v7.4.24` | prod |
| `vlucas/phpdotenv` | `v5.6.3` | `v5.8.1` | prod |
| `ralouphie/getallheaders` | `3.0.3` | *(ลบออก)* | prod |

---

## งานที่ 3: Security Headers, Asset Versioning (`?v=`) และคู่มือ nginx

### 3.1 Security Headers ที่ได้ (`app/Http/Middleware/SecurityHeaders.php`)

ลงทะเบียนต่อท้ายกลุ่ม `web` ใน `bootstrap/app.php` ไม่เขียนทับ header ที่ response ตั้งไว้เอง และไม่เพิ่ม `Content-Security-Policy` หรือ `Permissions-Policy`:

| Header | `GET http://.../login` | `GET https://.../login` |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | `strict-origin-when-cross-origin` |
| `Strict-Transport-Security` | *(ไม่มี)* | `max-age=31536000` *(ไม่มี `includeSubDomains`)* |
| `Content-Security-Policy` | *(ไม่มี)* | *(ไม่มี)* |
| `Permissions-Policy` | *(ไม่มี)* | *(ไม่มี)* |

### 3.2 รายการไฟล์ที่เปลี่ยนมาใช้ `App\Support\AssetVersion::url()` (`?v=filemtime`)

Helper กลาง: `app/Support/AssetVersion.php` (`AssetVersion::url(string $path): string`) คืน `asset($normalized).'?v='.filemtime(...)` และคืน `asset($normalized)` โดยไม่ throw เมื่อไฟล์ไม่มี

ไฟล์ view นอก `resources/views/pos/` ที่อัปเดต (12 ไฟล์, รวม 31 จุด — แบ่งเป็น 16 จุด CSS/JS ที่เคยไม่มี `?v=`, 10 จุดที่เคยเขียน `filemtime(public_path(...))` ซ้ำ และ 5 จุดโลโก้/รูปที่ไม่มี `?v=`):

1. `resources/views/layout.blade.php`
   - `vendor/bootstrap-icons/bootstrap-icons.min.css` *(เพิ่ม `?v=`)*
   - `vendor/adminlte/css/adminlte.min.css` *(เพิ่ม `?v=`)*
   - `vendor/sweetalert2/sweetalert2.all.min.js` *(เพิ่ม `?v=`)*
   - `vendor/alpinejs/alpine.min.js` *(เพิ่ม `?v=`)*
   - `vendor/adminlte/js/adminlte.min.js` *(เพิ่ม `?v=`)*
   - `stock-preview.js` *(เพิ่ม `?v=`)*
   - `vendor/tailwindcss/tailwind.min.css` *(แทน `filemtime(public_path(...))` เดิม)*
   - `css/erp-layout.css` *(แทน `filemtime(public_path(...))` เดิม)*
   - `css/erp-ui-standard.css` *(แทน `filemtime(public_path(...))` เดิม)*
   - `images/logos/mpm-food-group.jpg` (fallback favicon และโลโก้มุมซ้ายบน) และ `favicon_path` *(แทน `filemtime(public_path(...))` เดิมและเพิ่ม `?v=`)*
2. `resources/views/partials/peak-style-ui.blade.php`
   - `css/peak-style-ui.css` *(แทน `filemtime(public_path(...))` เดิม)*
   - `js/peak-style-ui.js` *(แทน `filemtime(public_path(...))` เดิม)*
3. `resources/views/auth/login.blade.php`
   - `vendor/bootstrap-icons/bootstrap-icons.min.css` *(เพิ่ม `?v=`)*
   - `images/logos/mpm-food-group.jpg` (favicon แทน `filemtime(public_path(...))` เดิม + โลโก้เพิ่ม `?v=`)
4. `resources/views/auth/change-password.blade.php`
   - `vendor/bootstrap-icons/bootstrap-icons.min.css` *(เพิ่ม `?v=`)*
   - `images/logos/mpm-food-group.jpg` *(แทน `filemtime(public_path(...))` เดิม)*
5. `resources/views/auth/mfa-challenge.blade.php`
   - `vendor/adminlte/css/adminlte.min.css` *(เพิ่ม `?v=`)*
   - `vendor/bootstrap-icons/bootstrap-icons.min.css` *(เพิ่ม `?v=`)*
6. `resources/views/dashboard.blade.php`
   - `vendor/chartjs/chart.umd.js` *(เพิ่ม `?v=`)*
7. `resources/views/documents/partials/entry-assets.blade.php`
   - `document-workflow.js` *(เพิ่ม `?v=`)*
8. `resources/views/purchase-orders/index.blade.php`
   - `document-workflow.js` *(เพิ่ม `?v=`)*
9. `resources/views/reports/index.blade.php`
   - `report-search.js` *(เพิ่ม `?v=`)*
10. `resources/views/wh/index.blade.php`
    - `vendor/bootstrap-icons/bootstrap-icons.min.css` *(เพิ่ม `?v=`)*
    - `vendor/alpinejs/alpine.min.js` *(เพิ่ม `?v=`)*
    - `images/logo-jet-j-red.png` *(แทน `filemtime(public_path(...))` เดิม)*
    - `images/logos/mpm-food-group.jpg` *(เพิ่ม `?v=`)*
11. `resources/views/member-portal/index.blade.php`
    - `images/logos/mpm-food-group.jpg` *(เพิ่ม `?v=`)*
12. `resources/views/settings/index.blade.php`
    - `$preset` โลโก้ preset *(เพิ่ม `?v=`)*

*(ไม่ได้แตะ `resources/views/pos/*` ตามข้อกำหนด)*

### 3.3 คู่มือ nginx ใน `docs/OPERATIONS.md`

เพิ่มหัวข้อ `## nginx: gzip และ cache ไฟล์ static` พร้อม `gzip_types`, `gzip_vary on;`, `gzip_min_length 1024;`, `server_tokens off;`, `location` block `expires 30d`, คำสั่ง `nginx -t && systemctl reload nginx` และคำเตือนตัวหนาให้เปิด `expires 30d` หลัง deploy งานข้อ 3.2 แล้วเท่านั้น

---

## ผลเทสต์จริง

### 1. รอบ Baseline (ก่อนแก้งานข้อ 1)
- `php artisan test` (Windows, PHP 8.4.27, SQLite): **1,183 tests**, 1,175 passed, **6 failed** (ครบ 6 ตัวตามข้อ 1), 2 skipped, 5 incomplete, 10,642 assertions, 186.14 s
- `composer audit --locked`: **37 advisories ใน 9 แพ็กเกจ**
- `node --test tests/js/`: **5/5 passed** (182.8 ms)

### 2. รอบหลังงานที่ 1 (แก้เทสต์บน Windows)
- `php artisan test`: **1,183 tests**, **1,180 passed**, **0 failed**, 3 skipped *(รวม `FinanceSecurityControlTest::test_operations_page_stays_available_when_a_backup_checksum_is_unreadable` ที่ข้ามเฉพาะ Windows)*, 6 incomplete, 10,639 assertions, 145.28 s
- Mutation check: เพิ่ม `<script>alert('x');</script>` ใน `resources/views/auth/login.blade.php` ชั่วคราว → `ConfirmPopupConsistencyTest::test_no_browser_confirm_or_alert_in_views` **แดงจริง** (`resources/views/auth/login.blade.php contains native alert()`) แล้วลบออก

### 3. รอบหลังงานที่ 2 (อัปเดต Composer)
- `composer audit --locked`: **0 advisories** (exit code `0`)
- `php artisan test`: **1,183 tests**, **1,180 passed**, **0 failed**, 3 skipped, 6 incomplete, 10,639 assertions, 161.03 s

### 4. รอบหลังงานที่ 3 (SecurityHeaders + AssetVersion + nginx docs)
- `php artisan test tests/Feature/SecurityHeadersAndAssetVersioningTest.php`: **6/6 passed**, 49 assertions
- Mutation check: ถอด `SecurityHeaders` ออกจาก `bootstrap/app.php` ชั่วคราว → `SecurityHeadersAndAssetVersioningTest` **แดงจริง 2 ตัว** (`Header [X-Content-Type-Options] not present on response.` ทั้ง HTTP และ HTTPS) แล้วคืนกลับ

### 5. รอบหลังงานที่ 4 และตรวจส่งงานทั้งหมด (Final Verification)
- `php artisan test`: **1,189 tests**, **1,186 passed**, **0 failed**, 3 skipped, 6 incomplete, 10,688 assertions, 148.40 s
- `node --test tests/js/`: **5/5 passed** (121.2 ms)
- `composer audit --locked`: **0 advisories** (exit code `0`)
- `vendor/bin/pint --test` บนไฟล์ PHP ที่แก้และสร้างใหม่ทั้งหมด 9 ไฟล์: **passed** (exit code `0`)
- ตรวจ PHP 8.3 grammar ด้วย `nikic/php-parser` (`PhpVersion::fromComponents(8, 3)`) ทุกไฟล์ใน `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/`: **987 ไฟล์, 0 errors** และไม่พบการเรียกฟังก์ชันเฉพาะ PHP 8.4+
