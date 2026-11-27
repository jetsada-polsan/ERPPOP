# รายงานงานลด query ที่ layout ยิงทุกหน้า (`antigravity/fewer-queries-per-page`)

วันที่: 2026-11-27
Branch: `antigravity/fewer-queries-per-page` (แตกต่อจาก `antigravity/system-hardening` ที่ `99a5f9ce` / PR #148 — **ให้ merge PR #148 เข้า `main` ก่อน**)

## สภาพแวดล้อมและวิธีวัด

- **ระบบปฏิบัติการ**: Windows (`Microsoft Windows NT 10.0.26300.0`)
- **PHP**: `8.4.27` (`C:\php84\php.exe`)
- **ฐานข้อมูลและ Cache ตอนวัด**: SQLite in-memory + `CACHE_STORE=database` (ให้ตรงกับ `.env.example` ที่ production ใช้), `SESSION_DRIVER=array`
- **วิธีวัด**: สร้างผู้ใช้ admin ด้วยบทบาท `GM` (มีสิทธิ์ครบโดยตรง) และ `IT_MGR` (ใช้สิทธิ์ IT bypass ผ่าน `hasGlobalPermission`) รัน warm-up หน้าละ 1 ครั้ง จากนั้นดึง `User::findOrFail()` ใหม่ทุกรอบเพื่อให้เหมือน request จริงที่โหลด user จาก session แล้ววัดด้วย `DB::listen` ทั้ง 6 หน้า

## ตารางเปรียบเทียบจำนวน Query ก่อน/หลัง (database cache)

### บทบาท `GM` (admin ที่มีสิทธิ์ครบในตาราง permissions)

| หน้า | ก่อน: รวม | ก่อน: ไม่ซ้ำ | ก่อน: `cache(app_settings)` | ก่อน: `role/perm exists` | หลัง: รวม | หลัง: ไม่ซ้ำ | หลัง: `cache(app_settings)` | หลัง: `role/perm exists` | ลดลง |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `/dashboard` | 36 | 15 | 13 | 7 | **17** | 14 | **1** | **0** | -52.8% (-19) |
| `/bookings` | 34 | 14 | 12 | 6 | **17** | 13 | **1** | **0** | -50.0% (-17) |
| `/products` | 32 | 12 | 12 | 6 | **15** | 11 | **1** | **0** | -53.1% (-17) |
| `/reports` | 36 | 11 | 18 | 7 | **12** | 10 | **1** | **0** | -66.7% (-24) |
| `/settings` | 39 | 9 | 24 | 6 | **10** | 8 | **1** | **0** | -74.4% (-29) |
| `/customers` | 31 | 11 | 12 | 6 | **14** | 10 | **1** | **0** | -54.8% (-17) |

### บทบาท `IT_MGR` (admin ที่พึ่ง global IT bypass ใน `User::hasPermission()`)

| หน้า | ก่อน: รวม | ก่อน: ไม่ซ้ำ | ก่อน: `cache(app_settings)` | ก่อน: `role/perm exists` | หลัง: รวม | หลัง: ไม่ซ้ำ | หลัง: `cache(app_settings)` | หลัง: `role/perm exists` | ลดลง |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `/dashboard` | 106 | 15 | 13 | 77 | **17** | 14 | **1** | **0** | -84.0% (-89) |
| `/bookings` | 106 | 14 | 12 | 78 | **17** | 13 | **1** | **0** | -84.0% (-89) |
| `/products` | 102 | 12 | 12 | 76 | **15** | 11 | **1** | **0** | -85.3% (-87) |
| `/reports` | 106 | 11 | 18 | 77 | **12** | 10 | **1** | **0** | -88.7% (-94) |
| `/settings` | 109 | 9 | 24 | 76 | **10** | 8 | **1** | **0** | -90.8% (-99) |
| `/customers` | 105 | 11 | 12 | 80 | **14** | 10 | **1** | **0** | -86.7% (-91) |

*(หมายเหตุ: ค่าหลังแก้ของ `GM` และ `IT_MGR` เท่ากันทุกหน้า และนอกจากลด query ต่อหน้าแล้ว ชุดเทสต์เต็ม `php artisan test` ยังรันเร็วขึ้นจาก 196.6 วินาที เหลือ 113.7 วินาที)*

---

## สรุป Commit แยกตามงาน

| งาน | Commit | รายละเอียด |
|---|---|---|
| B1 | `d5c1ce99` | Memo `AppSetting` ต่อ request ใน `request()->attributes` (`app_settings.all`), เพิ่ม `AppSetting::clearCache()` และ model event `saved`/`deleted`, และล้าง cache/memo ใน `PurgeLegacyMasters` และ migration `2026_11_01_000004_activate_meaw_foods_brand` ที่เขียนตาราง `app_settings` ตรง |
| B2 | `0ef7c43d` | เพิ่ม `User::globalPermissionCodes()` memo ต่อ instance ด้วย `??=` ใน `app/Models/User.php` สำหรับ `hasGlobalPermission()` (เฉพาะ global roles ไม่รวม branch roles) และให้ `permissionCodes()` ใช้ต่อเพื่อไม่ต้องโหลด global roles ซ้ำ |
| B3 | `528780ed` | เพิ่ม `tests/Feature/LayoutQueryBudgetTest.php` ตรวจเพดาน query ใต้ database cache (`/dashboard` ≤ 20, `/settings` ≤ 13, `app_settings.all` = 1, `roles/permissions exists` ≤ 1), ตรวจการล้าง memo เมื่อแก้ค่า setting และตรวจว่า `hasGlobalPermission()` ไม่รวม branch role |

## ผลทดสอบจริง

- `php artisan test` (Windows, PHP 8.4.27, SQLite): **1,193 tests, 1,190 passed, 10,778 assertions, 0 failed, 3 skipped, 6 incomplete** (113.7 s)
  - ไม่พบเทสต์เดิมที่พังจากการ memo สิทธิ์ใน `User` (ทุกเทสต์เดิมที่เปลี่ยน role กลางเทสต์ใช้ `$user->fresh()` อยู่แล้ว)
- `node --test tests/js/`: **5/5 passed** (110.5 ms)
- `composer audit --locked`: **0 advisories** (exit code `0`)
- `vendor/bin/pint --test` บนไฟล์ PHP ที่แก้และเพิ่มใหม่ในส่วน B ทั้ง 5 ไฟล์: **passed** (exit code `0`)
- ตรวจ PHP 8.3 grammar ด้วย `nikic/php-parser` (`PhpVersion::fromComponents(8, 3)`) ทั้งโปรเจกต์: **988 ไฟล์, 0 errors**
- **Mutation check (B3)**: ถอดการอ่าน memo ใน `AppSetting::allCached()` ออกชั่วคราว → `LayoutQueryBudgetTest::test_dashboard_and_settings_stay_within_query_budget_under_database_cache` **แดงจริง** (`/dashboard อ่าน cache key app_settings.all ซ้ำ 13 ครั้ง (ต้องไม่เกิน 1 ครั้งต่อ request)`) แล้วคืนโค้ดกลับและรันผ่านครบ
