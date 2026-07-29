# รายงานตรวจระบบและ UAT

วันที่ตรวจ: 29 กรกฎาคม 2569  
Commit ที่ตรวจ: `0f6cd1f`

## สรุปผู้บริหาร

ระบบฝั่ง Laravel และ Web POS ผ่านการตรวจอัตโนมัติและ production health check
แล้ว ระบบที่ deploy อยู่ตอบสนองได้ตามปกติ ฐานข้อมูล migration, storage, backup
และ queue อยู่ในสถานะผ่าน

การรับรองทั้งระบบยังไม่ควรปิดสมบูรณ์ เพราะ POS Desktop ยังไม่มี Vitest test case
และยังไม่ได้ compile Rust/Tauri บน Windows ที่มี toolchain จริง รวมถึงอุปกรณ์
Printer, cash drawer, scanner และเครื่องชั่งยังต้องทดสอบกับรุ่นใช้งานจริง

## ผลตรวจที่รันจริง

| รายการ | ผล | หลักฐาน |
|---|---|---|
| Laravel Feature/Unit tests | ผ่าน | 110 tests, 1,834 assertions |
| Laravel route integrity | ผ่าน | 349 routes แสดงได้ |
| Blade cache | ผ่าน | `php artisan view:cache` |
| Laravel config cache | ผ่าน | `php artisan config:cache` |
| Web POS / Vue production build | ผ่าน | Vite build สร้าง `pos-web` asset |
| POS Desktop typecheck | ผ่าน | `vue-tsc --noEmit` ผ่านใน `pnpm run build` |
| POS Desktop web build | ผ่าน | Vite build, 1,569 modules transformed |
| POS Desktop Vitest | ยังไม่ผ่านการรับรอง | ไม่พบไฟล์ `*.test.*` หรือ `*.spec.*` |
| Rust/Tauri compile | ยังไม่ได้ตรวจ | เครื่องตรวจไม่มี `cargo` และ `rustc` |
| Production database/migration | ผ่าน | `php artisan erp:health` |
| Production backup | ผ่าน | health report พบ backup ล่าสุดประมาณ 10.7 ชั่วโมง |
| Production queue | ผ่าน | งานล้มเหลว 0 รายการ |
| Production login | ผ่าน | HTTP 200 |
| Production `/pos` | ผ่านการ redirect auth | HTTP 302 เมื่อยังไม่ login |
| Production `/dashboard` | ผ่านการ redirect auth | HTTP 302 เมื่อยังไม่ login |

## ขอบเขตธุรกรรมที่มี automated coverage

ชุด PHP tests ครอบคลุมการขาย POS, pricing guard, promotion, payment validation,
เงินสด/กะ, การคืนสินค้า, inventory cost flow, FIFO/FEFO, รับสินค้า, PO บางส่วน,
stock transfer/count/approval, accounting period, monthly accounting, finance
security, MFA/PIN, route integrity และการ sync สาขา/แคชเชียร์

ระบบมีคู่มือ UAT ในหน้า `core-modules` จำนวน 75 กรณีทดสอบ โดยมีกรณีสำคัญ เช่น
POS-01 ถึง POS-11, PUR-01 ถึง PUR-07, STK-01 ถึง STK-07, SAL, ACC, HR, SEC,
INT และ OPS-01 ถึง OPS-04

## รายการที่ต้องทำก่อนรับรอง Production เต็มรูปแบบ

1. เพิ่ม Vitest สำหรับ SQLite queue, sync retry, backup/restore และ pricing contract
2. รัน `cargo check` และ Tauri build บน Windows หรือ GitHub Actions
3. ทำ Hardware UAT กับเครื่องพิมพ์, ลิ้นชักเงินสด, barcode scanner, scale และ customer display
4. ทำ UAT แบบ login จริงตามคู่มือ 75 กรณี และแนบเลขบิล/ภาพ/รายงานเป็นหลักฐาน
5. ทดสอบไฟดับ/เปิดเครื่องใหม่และ restore SQLite บนเครื่อง POS Windows จริง
6. ตั้ง backup offsite และทดสอบ restore drill ตามรอบที่กำหนด

## ข้อสรุป

สถานะปัจจุบัน: **ผ่านสำหรับ backend และ Web POS production, ผ่านสำหรับ Desktop
frontend build, ยังไม่ผ่านการรับรองเต็มระบบสำหรับ native/hardware UAT**

ไม่พบ regression จากชุด automated tests ที่มีอยู่ใน repository ณ วันที่ตรวจ
แต่การไม่มี Desktop Vitest และยังไม่ได้ compile Rust เป็นความเสี่ยงที่ต้องปิดก่อน
แจกจ่ายไฟล์ติดตั้ง POS รุ่น production ให้ทุกสาขา
